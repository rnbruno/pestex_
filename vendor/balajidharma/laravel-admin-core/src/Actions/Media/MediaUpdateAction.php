<?php

namespace BalajiDharma\LaravelAdminCore\Actions\Media;

use BalajiDharma\LaravelAdminCore\Data\Media\MediaUpdateData;
use BalajiDharma\LaravelMediaManager\MediaManager;
use Plank\Mediable\Media;

class MediaUpdateAction
{
    protected MediaManager $mediaManager;

    public function __construct(MediaManager $mediaManager)
    {
        $this->mediaManager = $mediaManager;
    }

    public function handle(MediaUpdateData $data, Media $media)
    {
        return $this->mediaManager->createFromSource($data->getFile(), $data->getType(), $data->getName(), $data->getAlt(), $media);
    }
}
