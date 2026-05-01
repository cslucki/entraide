<?php

namespace App\Jobs;

use App\Models\ServiceImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class GenerateServiceThumbnail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ServiceImage $serviceImage) {}

    public function handle(): void
    {
        $disk = Storage::disk('public');
        $path = $this->serviceImage->path;

        if (! $disk->exists($path)) {
            return;
        }

        $thumbnailPath = 'thumbnails/' . $path;

        $image = Image::read($disk->get($path));
        $image->scaleDown(width: 800, height: 600);

        $disk->put($thumbnailPath, (string) $image->encodeByExtension());
    }
}
