<?php

namespace App\Jobs;

use App\Services\Dossiers\DossierFileIndexer;
use App\Support\Ai\AiCorrelation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Clone structurel de IndexDossierArticleChunks (TASK-1216).
 */
class IndexDossierFileChunks implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $dossierId,
        public readonly string $fileId,
        public ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? AiCorrelation::id();
    }

    public function handle(DossierFileIndexer $indexer): void
    {
        AiCorrelation::bind($this->correlationId);

        $indexer->synchronize($this->organizationId, $this->dossierId, $this->fileId);
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
        return 'dossier-file-index:'.$this->organizationId.':'.$this->dossierId.':'.$this->fileId;
    }
}
