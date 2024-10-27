<?php
declare(strict_types=1);

namespace Plank\Mediable\UrlGenerators;

use Illuminate\Contracts\Config\Repository as Config;
use Plank\Mediable\Media;

abstract class BaseUrlGenerator implements UrlGeneratorInterface
{
    protected Config $config;

    /**
     * Media instance being linked.
     */
    protected ?Media $media = null;

    /**
     * Constructor.
     * @param \Illuminate\Contracts\Config\Repository $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Set the media being operated on.
     * @param \Plank\Mediable\Media $media
     */
    public function setMedia(Media $media): void
    {
        $this->media = $media;
    }

    /**
     * {@inheritdoc}
     */
    public function isPubliclyAccessible(): bool
    {
        return $this->getDiskConfig('visibility', 'private') == 'public' && $this->media->isVisible();
    }

    /**
     * Get a config value for the current disk.
     * @param  string $key
     * @param  mixed $default
     * @return mixed
     */
    protected function getDiskConfig(string $key, $default = null): mixed
    {
        return $this->config->get("filesystems.disks.{$this->media->disk}.{$key}", $default);
    }
}
