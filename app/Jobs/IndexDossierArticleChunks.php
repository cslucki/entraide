<?php

namespace App\Jobs;

use App\Services\Dossiers\DossierArticleIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class IndexDossierArticleChunks implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $dossierId,
        public readonly string $blogPostId,
    ) {}

    public function handle(DossierArticleIndexer $indexer): void
    {
        $indexer->synchronize($this->organizationId, $this->dossierId, $this->blogPostId);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->overlapKey()))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function overlapKey(): string
    {
        return 'dossier-article-index:'.$this->organizationId.':'.$this->dossierId.':'.$this->blogPostId;
    }
}
