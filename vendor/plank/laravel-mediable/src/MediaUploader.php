<?php
declare(strict_types=1);

namespace Plank\Mediable;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\UnableToRetrieveMetadata;
use Plank\Mediable\Exceptions\MediaUpload\ConfigurationException;
use Plank\Mediable\Exceptions\MediaUpload\FileExistsException;
use Plank\Mediable\Exceptions\MediaUpload\FileNotFoundException;
use Plank\Mediable\Exceptions\MediaUpload\FileNotSupportedException;
use Plank\Mediable\Exceptions\MediaUpload\FileSizeException;
use Plank\Mediable\Exceptions\MediaUpload\ForbiddenException;
use Plank\Mediable\Exceptions\MediaUpload\InvalidHashException;
use Plank\Mediable\Helpers\File;
use Plank\Mediable\SourceAdapters\RawContentAdapter;
use Plank\Mediable\SourceAdapters\SourceAdapterFactory;
use Plank\Mediable\SourceAdapters\SourceAdapterInterface;

/**
 * Media Uploader.
 *
 * Validates files, uploads them to disk and generates Media
 */
class MediaUploader
{
    const ON_DUPLICATE_UPDATE = 'update';
    const ON_DUPLICATE_INCREMENT = 'increment';
    const ON_DUPLICATE_ERROR = 'error';
    const ON_DUPLICATE_REPLACE = 'replace';
    const ON_DUPLICATE_REPLACE_WITH_VARIANTS = 'replace_with_variants';

    private FileSystemManager $filesystem;

    private SourceAdapterFactory $factory;

    private ImageManipulator $imageManipulator;

    private array $config;

    private SourceAdapterInterface $source;

    private ?string $disk = null;

    /**
     * Path relative to the filesystem disk root.
     */
    private ?string $directory = null;

    /**
     * Name of the new file.
     */
    private ?string $filename = null;

    /**
     * If true the contents hash of the source will be used as the filename.
     */
    private ?string $hashFilenameAlgo = null;

    /**
     * Visibility for the new file
     */
    private ?string $visibility = null;

    /**
     * Callable allowing to alter the model before save.
     * @var callable
     */
    private $before_save;

    /**
     * Additional options to pass to the filesystem while uploading
     */
    private array $options = [];

    private ?string $alt = null;

    private array $expectedHashes = [];

    /**
     * Constructor.
     * @param FilesystemManager $filesystem
     * @param SourceAdapterFactory $factory
     * @param array|null $config
     */
    public function __construct(
        FileSystemManager $filesystem,
        SourceAdapterFactory $factory,
        ImageManipulator $imageManipulator,
        array $config = null
    ) {
        $this->filesystem = $filesystem;
        $this->factory = $factory;
        $this->imageManipulator = $imageManipulator;
        $this->config = $config ?: config('mediable', []);
    }

    /**
     * Set the source for the file.
     *
     * @param  mixed $source
     *
     * @return $this
     * @throws ConfigurationException
     */
    public function fromSource(mixed $source): self
    {
        $this->source = $this->factory->create($source);

        return $this;
    }

    /**
     * Set the source for the string data.
     * @param  string $source
     * @return $this
     */
    public function fromString(string $source): self
    {
        $this->source = new RawContentAdapter($source);

        return $this;
    }

    /**
     * Set the filesystem disk and relative directory where the file will be saved.
     *
     * @param  string $disk
     * @param  string $directory
     *
     * @return $this
     * @throws ConfigurationException
     * @throws ForbiddenException
     */
    public function toDestination(string $disk, string $directory): self
    {
        return $this->toDisk($disk)->toDirectory($directory);
    }

    /**
     * Set the filesystem disk on which the file will be saved.
     *
     * @param string $disk
     *
     * @return $this
     * @throws ConfigurationException
     * @throws ForbiddenException
     */
    public function toDisk(string $disk): self
    {
        $this->disk = $this->verifyDisk($disk);

        return $this;
    }

    /**
     * Set the directory relative to the filesystem disk at which the file will be saved.
     * @param string $directory
     * @return $this
     */
    public function toDirectory(string $directory): self
    {
        $this->directory = File::sanitizePath($directory);

        return $this;
    }

    /**
     * Specify the filename to copy to the file to.
     * @param string $filename
     * @return $this
     */
    public function useFilename(string $filename): self
    {
        $this->filename = File::sanitizeFilename($filename);
        $this->hashFilenameAlgo = null;

        return $this;
    }

    public function withAltAttribute(string $alt): self
    {
        $this->alt = $alt;
        return $this;
    }

    /**
     * Indicates to the uploader to generate a filename using the file's MD5 hash.
     * @param string $algo any hashing algorithm supported by PHP's hash() function
     * @return $this
     */
    public function useHashForFilename(string $algo = 'md5'): self
    {
        $this->hashFilenameAlgo = $algo;
        $this->filename = null;

        return $this;
    }

    /**
     * Restore the default behaviour of using the source file's filename.
     * @return $this
     */
    public function useOriginalFilename(): self
    {
        $this->filename = null;
        $this->hashFilenameAlgo = null;

        return $this;
    }

    /**
     * Change the class to use for generated Media.
     * @param string $class
     * @return $this
     * @throws ConfigurationException if $class does not extend Plank\Mediable\Media
     */
    public function setModelClass(string $class): self
    {
        if (!is_subclass_of($class, Media::class)) {
            throw ConfigurationException::cannotSetModel($class);
        }
        $this->config['model'] = $class;

        return $this;
    }

    /**
     * Change the maximum allowed file size.
     * @param int $size
     * @return $this
     */
    public function setMaximumSize(int $size): self
    {
        $this->config['max_size'] = $size;

        return $this;
    }

    /**
     * Change the behaviour for when a file already exists at the destination.
     * @param string $behavior
     * @return $this
     */
    public function setOnDuplicateBehavior(string $behavior): self
    {
        $this->config['on_duplicate'] = $behavior;

        return $this;
    }

    /**
     * Get current behavior when duplicate file is uploaded.
     *
     * @return string
     */
    public function getOnDuplicateBehavior(): string
    {
        return $this->config['on_duplicate'];
    }

    /**
     * Throw an exception when file already exists at the destination.
     *
     * @return $this
     */
    public function onDuplicateError(): self
    {
        return $this->setOnDuplicateBehavior(self::ON_DUPLICATE_ERROR);
    }

    /**
     * Append incremented counter to file name when file already exists at destination.
     *
     * @return $this
     */
    public function onDuplicateIncrement(): self
    {
        return $this->setOnDuplicateBehavior(self::ON_DUPLICATE_INCREMENT);
    }

    /**
     * Overwrite existing Media when file already exists at destination.
     *
     * This will delete the old media record and create a new one, detaching any existing associations.
     *
     * @return $this
     */
    public function onDuplicateReplace(): self
    {
        return $this->setOnDuplicateBehavior(self::ON_DUPLICATE_REPLACE);
    }

    /**
     * Overwrite existing Media when file already exists at destination and delete any variants of the original record.
     *
     * This will delete the old media record and create a new one, detaching any existing associations.
     *
     * This will also delete any existing
     *
     * @return $this
     */
    public function onDuplicateReplaceWithVariants(): self
    {
        return $this->setOnDuplicateBehavior(self::ON_DUPLICATE_REPLACE_WITH_VARIANTS);
    }

    /**
     * Overwrite existing files and update the existing media record.
     *
     * This will retain any existing associations.
     *
     * @return $this
     */
    public function onDuplicateUpdate(): self
    {
        return $this->setOnDuplicateBehavior(self::ON_DUPLICATE_UPDATE);
    }

    /**
     * Change whether both the MIME type and extensions must match the same aggregate type.
     * @param bool $strict
     * @return $this
     */
    public function setStrictTypeChecking(bool $strict): self
    {
        $this->config['strict_type_checking'] = $strict;

        return $this;
    }

    /**
     * Change whether files not matching any aggregate types are allowed.
     * @param bool $allow
     * @return $this
     */
    public function setAllowUnrecognizedTypes(bool $allow): self
    {
        $this->config['allow_unrecognized_types'] = $allow;

        return $this;
    }

    /**
     * Add or update the definition of a aggregate type.
     * @param string $type the name of the type
     * @param string[] $mimeTypes list of MIME types recognized
     * @param string[] $extensions list of file extensions recognized
     * @return $this
     */
    public function setTypeDefinition(string $type, array $mimeTypes, array $extensions): self
    {
        $this->config['aggregate_types'][$type] = [
            'mime_types' => array_map('strtolower', $mimeTypes),
            'extensions' => array_map('strtolower', $extensions),
        ];

        return $this;
    }

    /**
     * Set a list of MIME types that the source file must be restricted to.
     * @param string[] $allowedMimes
     * @return $this
     */
    public function setAllowedMimeTypes(array $allowedMimes): self
    {
        $this->config['allowed_mime_types'] = array_map('strtolower', $allowedMimes);

        return $this;
    }

    /**
     * Prefer the MIME type provided by the client, if any, over the inferred MIME type.
     * Depending on the source, this may not be accurate.
     * @return $this
     */
    public function preferClientMimeType(): self
    {
        $this->config['prefer_client_mime_type'] = true;

        return $this;
    }

    /**
     * Prefer the MIME type inferred by the contents of the file, if available,
     * over the MIME type provided by the client.
     * @return $this
     */
    public function preferInferredMimeType(): self
    {
        $this->config['prefer_client_mime_type'] = false;

        return $this;
    }

    /**
     * Set a list of file extensions that the source file must be restricted to.
     * @param string[] $allowedExtensions
     * @return $this
     */
    public function setAllowedExtensions(array $allowedExtensions): self
    {
        $this->config['allowed_extensions'] = array_map('strtolower', $allowedExtensions);

        return $this;
    }

    /**
     * Set a list of aggregate types that the source file must be restricted to.
     * @param string[] $allowedTypes
     * @return $this
     */
    public function setAllowedAggregateTypes(array $allowedTypes): self
    {
        $this->config['allowed_aggregate_types'] = $allowedTypes;

        return $this;
    }

    /**
     * Verify the MD5 hash of the file contents matches an expected value.
     * The upload process will throw an InvalidHashException if the hash of the
     * uploaded file does not match the provided value.
     * @param string|null $expectedHash set to null to disable hash validation
     * @param string $algo any hashing algorithm supported by PHP's hash() function
     * @return $this
     */
    public function validateHash(?string $expectedHash, string $algo = 'md5'): self
    {
        $this->expectedHashes[$algo] = $expectedHash;
        return $this;
    }

    /**
     * Make the resulting file public (default behaviour)
     * @return $this
     */
    public function makePublic(): self
    {
        $this->visibility = Filesystem::VISIBILITY_PUBLIC;
        return $this;
    }

    /**
     * Make the resulting file private
     * @return $this
     */
    public function makePrivate(): self
    {
        $this->visibility = Filesystem::VISIBILITY_PRIVATE;
        return $this;
    }

    public function getVisibility(): string
    {
        if ($this->visibility) {
            return $this->visibility;
        }

        return config(
            'filesystems.disks.'.$this->disk.'.visibility',
            Filesystem::VISIBILITY_PUBLIC
        );
    }

    /**
     * Apply an image manipulation to the uploaded image.
     *
     * This will modify the image before saving it to disk.
     * The original image will not be preserved.
     *
     * Note this will manipulate the image as part of the upload process, which may be slow.
     * @param string|ImageManipulation $imageManipulation Either a defined ImageManipulation variant name
     *   or an ImageManipulation instance
     * @return $this
     */
    public function applyImageManipulation($imageManipulation): self
    {
        if (is_string($imageManipulation)) {
            $imageManipulation = $this->imageManipulator->getVariantDefinition($imageManipulation);
        }
        $this->config['image_manipulation'] = $imageManipulation;
        return $this;
    }

    /**
     * Additional options to pass to the filesystem when uploading
     * @param array $options
     * @return $this
     */
    public function withOptions(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Determine the aggregate type of the file based on the MIME type and the extension.
     * @param  string $mimeType
     * @param  string $extension
     * @return string
     * @throws FileNotSupportedException If the file type is not recognized
     * @throws FileNotSupportedException If the file type is restricted
     * @throws FileNotSupportedException If the aggregate type is restricted
     */
    public function inferAggregateType(string $mimeType, string $extension): string
    {
        $mimeType = strtolower($mimeType);
        $extension = strtolower($extension);
        $allowedTypes = $this->config['allowed_aggregate_types'] ?? [];
        $typesForMime = $this->possibleAggregateTypesForMimeType($mimeType);
        $typesForExtension = $this->possibleAggregateTypesForExtension($extension);

        if (count($allowedTypes)) {
            $intersection = array_intersect($typesForMime, $typesForExtension, $allowedTypes);
        } else {
            $intersection = array_intersect($typesForMime, $typesForExtension);
        }

        if (count($intersection)) {
            $type = $intersection[0];
        } elseif (empty($typesForMime) && empty($typesForExtension)) {
            if (!$this->config['allow_unrecognized_types'] ?? false) {
                throw FileNotSupportedException::unrecognizedFileType($mimeType, $extension);
            }
            $type = Media::TYPE_OTHER;
        } else {
            if ($this->config['strict_type_checking'] ?? false) {
                throw FileNotSupportedException::strictTypeMismatch($mimeType, $extension);
            }
            $merged = array_merge($typesForMime, $typesForExtension);
            $type = reset($merged);
        }

        if (count($allowedTypes) && !in_array($type, $allowedTypes)) {
            throw FileNotSupportedException::aggregateTypeRestricted($type, $allowedTypes);
        }

        return $type;
    }

    /**
     * Determine the aggregate type of the file based on the MIME type.
     * @param  string $mime
     * @return string[]
     */
    public function possibleAggregateTypesForMimeType(string $mime): array
    {
        $types = [];
        foreach ($this->config['aggregate_types'] ?? [] as $type => $attributes) {
            if (in_array($mime, $attributes['mime_types'])) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Determine the aggregate type of the file based on the extension.
     * @param  string $extension
     * @return string[]
     */
    public function possibleAggregateTypesForExtension(string $extension): array
    {
        $types = [];
        foreach ($this->config['aggregate_types'] ?? [] as $type => $attributes) {
            if (in_array($extension, $attributes['extensions'])) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Process the file upload.
     *
     * Validates the source, then stores the file onto the disk and creates and stores a new Media instance.
     *
     * @return Media
     * @throws ConfigurationException
     * @throws FileExistsException
     * @throws FileNotFoundException
     * @throws FileNotSupportedException
     * @throws FileSizeException
     * @throws InvalidHashException
     */
    public function upload(): Media
    {
        $this->verifyFile();

        $model = $this->populateModel($this->makeModel());

        $this->manipulateImage($model);

        if (is_callable($this->before_save)) {
            call_user_func($this->before_save, $model, $this->source);
        }

        $this->verifyDestination($model);
        $this->writeToDisk($model);
        $model->save();

        return $model;
    }

    /**
     * Process the file upload, overwriting an existing media's file
     *
     * Uploader will automatically place the file on the same disk as the original media.
     *
     * @param  Media $media
     * @return Media
     *
     * @throws ConfigurationException
     * @throws FileNotFoundException
     * @throws FileNotSupportedException
     * @throws FileSizeException
     * @throws ForbiddenException
     * @throws FileExistsException
     */
    public function replace(Media $media): Media
    {
        if (!$this->disk) {
            $this->toDisk($media->disk);
        }

        if (!$this->directory) {
            $this->toDirectory($media->directory);
        }

        if (!$this->filename) {
            $this->useFilename($media->filename);
        }

        // Remember original file location.
        // We will only delete it if validation passes
        $disk = $media->disk;
        $path = $media->getDiskPath();

        $model = $this->populateModel($media);

        if (is_callable($this->before_save)) {
            call_user_func($this->before_save, $model, $this->source);
        }

        $this->verifyDestination($model);
        // Delete original file, if necessary
        $this->filesystem->disk($disk)->delete($path);
        $this->writeToDisk($model);

        $model->save();

        return $model;
    }

    /**
     * Validate input and convert to Media attributes
     * @param  Media $model
     * @return Media
     *
     * @throws ConfigurationException
     * @throws FileNotFoundException
     * @throws FileNotSupportedException
     * @throws FileSizeException
     */
    private function populateModel(Media $model): Media
    {
        $model->size = $this->verifyFileSize($this->source->size() ?? 0);
        $model->mime_type = $this->verifyMimeType($this->selectMimeType());
        $model->extension = $this->verifyExtension(
            $this->source->extension()
                ?? File::guessExtension($model->mime_type)
        );
        $model->aggregate_type = $this->inferAggregateType($model->mime_type, $model->extension);

        $model->disk = $this->disk ?: $this->config['default_disk'];
        $model->directory = $this->directory;
        $model->filename = $this->generateFilename();

        if ($this->alt) {
            $model->alt = $this->alt;
        }

        return $model;
    }

    /**
     * Set the before save callback
     * @param callable $callable
     * @return $this
     */
    public function beforeSave(callable $callable): self
    {
        $this->before_save = $callable;
        return $this;
    }

    /**
     * Create a `Media` record for a file already on a disk.
     *
     * @param  string $disk
     * @param  string $path Path to file, relative to disk root
     *
     * @return Media
     * @throws ConfigurationException
     * @throws FileNotFoundException
     * @throws FileNotSupportedException
     * @throws FileSizeException
     * @throws ForbiddenException
     */
    public function importPath(string $disk, string $path): Media
    {
        $directory = File::cleanDirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $this->import($disk, $directory, $filename, $extension);
    }

    /**
     * Create a `Media` record for a file already on a disk.
     *
     * @param  string $disk
     * @param  string $directory
     * @param  string $filename
     * @param  string $extension
     *
     * @return Media
     * @throws ConfigurationException
     * @throws FileNotFoundException If the file does not exist
     * @throws FileNotSupportedException
     * @throws FileSizeException
     * @throws ForbiddenException
     */
    public function import(string $disk, string $directory, string $filename, string $extension): Media
    {
        $disk = $this->verifyDisk($disk);
        $storage = $this->filesystem->disk($disk);

        $model = $this->makeModel();
        $model->disk = $disk;
        $model->directory = $directory;
        $model->filename = $filename;
        $model->extension = $this->verifyExtension($extension, false);

        if (!$storage->exists($model->getDiskPath())) {
            throw FileNotFoundException::fileNotFound($model->getDiskPath());
        }

        $model->mime_type = $this->verifyMimeType(
            $this->inferMimeType($storage, $model->getDiskPath())
        );
        $model->aggregate_type = $this->inferAggregateType($model->mime_type, $model->extension);
        $model->size = $this->verifyFileSize($storage->size($model->getDiskPath()));

        if ($this->visibility) {
            $storage->setVisibility($model->getDiskPath(), $this->visibility);
        }

        if ($this->alt) {
            $model->alt = $this->alt;
        }

        if (is_callable($this->before_save)) {
            call_user_func($this->before_save, $model, $this->source);
        }

        $model->save();

        return $model;
    }

    /**
     * Reanalyze a media record's file and adjust the aggregate type and size, if necessary.
     *
     * @param  Media $media
     *
     * @return bool Whether the model was modified
     * @throws FileNotSupportedException
     * @throws FileSizeException
     */
    public function update(Media $media):  bool
    {
        $storage = $this->filesystem->disk($media->disk);

        $media->size = $this->verifyFileSize($storage->size($media->getDiskPath()));
        $media->mime_type = $this->verifyMimeType(
            $this->inferMimeType($storage, $media->getDiskPath())
        );
        $media->aggregate_type = $this->inferAggregateType($media->mime_type, $media->extension);

        if ($this->alt) {
            $media->alt = $this->alt;
        }

        if ($dirty = $media->isDirty()) {
            $media->save();
        }

        return $dirty;
    }

    /**
     * Verify if file is valid
     * @throws ConfigurationException If no source is provided
     * @throws FileNotFoundException If the source is invalid
     * @throws FileSizeException If the file is too large
     * @throws FileNotSupportedException If the mime type is not allowed
     * @throws FileNotSupportedException If the file extension is not allowed
     * @return void
     */
    public function verifyFile(): void
    {
        $this->verifySource();
        $this->verifyFileSize($this->source->size() ?? 0);
        $mimeType = $this->verifyMimeType(
            $this->selectMimeType()
        );
        $this->verifyExtension(
            $this->source->extension() ?? File::guessExtension($mimeType)
        );

        $this->verifyHashes();
    }

    /**
     * Generate an instance of the `Media` class.
     * @return Media
     */
    private function makeModel(): Media
    {
        $class = $this->config['model'] ?? Media::class;

        return new $class;
    }

    /**
     * Ensure that the provided filesystem disk name exists and is allowed.
     * @param  string $disk
     * @return string
     * @throws ConfigurationException If the disk does not exist
     * @throws ForbiddenException If the disk is not included in the `allowed_disks` config.
     */
    private function verifyDisk(string $disk): string
    {
        if (!array_key_exists($disk, config('filesystems.disks', []))) {
            throw ConfigurationException::diskNotFound($disk);
        }

        if (!in_array($disk, $this->config['allowed_disks'] ?? [])) {
            throw ForbiddenException::diskNotAllowed($disk);
        }

        return $disk;
    }

    /**
     * Ensure that a valid source has been provided.
     * @return void
     * @throws ConfigurationException If no source is provided
     * @throws FileNotFoundException If the source is invalid
     */
    private function verifySource(): void
    {
        if (empty($this->source)) {
            throw ConfigurationException::noSourceProvided();
        }
    }

    private function inferMimeType(Filesystem $filesystem, string $path): string
    {
        $mimeType = null;
        try {
            if (method_exists($filesystem, 'mimeType')) {
                $mimeType = $filesystem->mimeType($path);
            }
        } catch (UnableToRetrieveMetadata $e) {
            // previous versions of flysystem would default to octet-stream when
            // the file was unrecognized. Maintain the behaviour for now
            return 'application/octet-stream';
        }
        return $mimeType ?: 'application/octet-stream';
    }

    private function selectMimeType(): string
    {
        if ($this->config['prefer_client_mime_type'] ?? false) {
            return $this->source->clientMimeType() ?? $this->source->mimeType();
        }
        return $this->source->mimeType();
    }

    /**
     * Ensure that the file's mime type is allowed.
     * @param  string $mimeType
     * @return string
     * @throws FileNotSupportedException If the mime type is not allowed
     */
    private function verifyMimeType(string $mimeType): string
    {
        $mimeType = strtolower($mimeType);
        $allowed = $this->config['allowed_mime_types'] ?? [];
        if (!empty($allowed) && !in_array($mimeType, $allowed)) {
            throw FileNotSupportedException::mimeRestricted($mimeType, $allowed);
        }

        return $mimeType;
    }

    /**
     * Ensure that the file's extension is allowed.
     * @param  string $extension
     * @param  bool $toLower
     * @return string
     * @throws FileNotSupportedException If the file extension is not allowed
     */
    private function verifyExtension(string $extension, bool $toLower = true): string
    {
        $extensionLower = strtolower($extension);
        $allowed = $this->config['allowed_extensions'] ?? [];
        if (!empty($allowed) && !in_array($extensionLower, $allowed)) {
            throw FileNotSupportedException::extensionRestricted($extensionLower, $allowed);
        }

        return $toLower ? $extensionLower : $extension;
    }

    /**
     * Verify that the file being uploaded is not larger than the maximum.
     * @param  int $size
     * @return int
     * @throws FileSizeException If the file is too large
     */
    private function verifyFileSize(int $size): int
    {
        $max = $this->config['max_size'] ?? 0;
        if ($max > 0 && $size > $max) {
            throw FileSizeException::fileIsTooBig($size, $max);
        }

        return $size;
    }

    private function verifyHashes(): void
    {
        foreach ($this->expectedHashes as $algo => $expectedHash) {
            if ($expectedHash === null) {
                return;
            }

            $actualHash = $this->source->hash($algo);
            if ($actualHash !== $expectedHash) {
                throw InvalidHashException::hashMismatch(
                    $algo,
                    $expectedHash,
                    $actualHash
                );
            }
        }
    }

    /**
     * Verify that the intended destination is available and handle any duplications.
     * @param  Media $model
     * @return void
     *
     * @throws FileExistsException
     */
    private function verifyDestination(Media $model): void
    {
        $storage = $this->filesystem->disk($model->disk);

        if ($storage->exists($model->getDiskPath())) {
            $this->handleDuplicate($model);
        }
    }

    /**
     * Decide what to do about duplicated files.
     *
     * @param  Media $model
     * @return Media
     * @throws FileExistsException If directory is not writable or file already exists at the destination and on_duplicate is set to 'error'
     */
    private function handleDuplicate(Media $model): Media
    {
        switch ($this->config['on_duplicate'] ?? MediaUploader::ON_DUPLICATE_INCREMENT) {
            case static::ON_DUPLICATE_ERROR:
                throw FileExistsException::fileExists($model->getDiskPath());
            case static::ON_DUPLICATE_REPLACE:
                $this->deleteExistingMedia($model);
                break;
            case static::ON_DUPLICATE_REPLACE_WITH_VARIANTS:
                $this->deleteExistingMedia($model, true);
                break;
            case static::ON_DUPLICATE_UPDATE:
                $original = $model->newQuery()
                   ->where('disk', $model->disk)
                   ->where('directory', $model->directory)
                   ->where('filename', $model->filename)
                   ->where('extension', $model->extension)
                   ->first();

                if ($original) {
                    $model->{$model->getKeyName()} = $original->getKey();
                    $model->exists = true;
                }
                break;
            case static::ON_DUPLICATE_INCREMENT:
            default:
                $model->filename = $this->generateUniqueFilename($model);
        }
        return $model;
    }

    /**
     * Delete the media that previously existed at a destination.
     * @param  Media $model
     * @param  bool $withVariants
     * @return void
     */
    private function deleteExistingMedia(Media $model, bool $withVariants = false): void
    {
        $original = $model->newQuery()
            ->where('disk', $model->disk)
            ->where('directory', $model->directory)
            ->where('filename', $model->filename)
            ->where('extension', $model->extension)
            ->first();
        if ($original) {
            $models = $withVariants ? $original->getAllVariantsAndSelf() : collect([$original]);
            $models->each(
                function (Media $variant) {
                    $variant->delete();
                    $this->deleteExistingFile($variant);
                }
            );
        }
    }

    /**
     * Delete the file on disk.
     * @param  Media $model
     * @return void
     */
    private function deleteExistingFile(Media $model): void
    {
        $this->filesystem->disk($model->disk)->delete($model->getDiskPath());
    }

    /**
     * Increment model's filename until one is found that doesn't already exist.
     * @param  Media $model
     * @return string
     */
    private function generateUniqueFilename(Media $model): string
    {
        $storage = $this->filesystem->disk($model->disk);
        $counter = 0;
        do {
            $filename = "{$model->filename}";
            if ($counter > 0) {
                $filename .= '-' . $counter;
            }
            $path = "{$model->directory}/{$filename}.{$model->extension}";
            ++$counter;
        } while ($storage->exists($path));

        return $filename;
    }

    /**
     * Generate the model's filename.
     * @return string
     */
    private function generateFilename(): string
    {
        if ($this->filename) {
            return $this->filename;
        }

        if ($this->hashFilenameAlgo) {
            return $this->source->hash($this->hashFilenameAlgo);
        }

        $filename = $this->source->filename();

        if ($filename === null) {
            ConfigurationException::cannotInferFilename();
        }

        return File::sanitizeFileName($filename);
    }

    private function writeToDisk(Media $model): void
    {
        $this->filesystem->disk($model->disk)
            ->put(
                $model->getDiskPath(),
                $this->source->getStream(),
                $this->getOptions()
            );
    }

    public function getOptions(): array
    {
        $options = $this->options;
        if (!isset($options['visibility'])) {
            $options['visibility'] = $this->getVisibility();
        }
        return $options;
    }

    /**
     * @param Media $model
     * @return void
     * @throws Exceptions\ImageManipulationException
     */
    public function manipulateImage(Media $model): void
    {
        if (empty($this->config['image_manipulation'])
            || $model->aggregate_type !== Media::TYPE_IMAGE
        ) {
            return;
        }
        $manipulation = $this->config['image_manipulation'];
        $this->source = $this->imageManipulator->manipulateUpload(
            $model,
            $this->source,
            $manipulation
        );
    }
}
