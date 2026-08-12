<?php

namespace App\Jobs;

use App\Services\Dossiers\DossierArticleIndexer;
use App\Support\Ai\AiCorrelation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class IndexDossierArticleChunks implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * TASK-1200 — propagation asynchrone de la corrélation, meme patron que
     * `GenerateAiAgentResponse` (TASK-1131) : figee au DISPATCH, serialisee
     * avec le job, jamais recreee a l'execution.
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $dossierId,
        public readonly string $blogPostId,
        public ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? AiCorrelation::id();
    }

    public function handle(DossierArticleIndexer $indexer): void
    {
        AiCorrelation::bind($this->correlationId);

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
