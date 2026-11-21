<?php

namespace App\Support\Images;

/**
 * TASK-1873 — une image source a deriver : sa surface, son disque, son
 * chemin, et l'Organization qui la porte (NULL pour un avatar sans tenant).
 */
final readonly class ImageSource
{
    public function __construct(
        public string $surface,
        public string $path,
        public ?string $organizationId = null,
        public string $disk = 'public',
    ) {}

    public function key(): string
    {
        return $this->disk.'|'.$this->path;
    }
}
