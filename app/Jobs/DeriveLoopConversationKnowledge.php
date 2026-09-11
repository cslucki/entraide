<?php

namespace App\Jobs;

use App\Models\Loop;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Support\Ai\AiCorrelation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * TASK-1539 — compiler la conversation d'UNE Boucle, en tache de fond.
 *
 * Meme patron que `IndexDossierArticleChunks` : queue dediee, verrou par objet,
 * correlation figee au DISPATCH et jamais recreee a l'execution.
 *
 * Le job ne decide de rien. Il ne sait ni quand compiler, ni s'il y a lieu de
 * le faire : c'est le balayeur qui choisit, et le deriver qui refuse. Un job
 * lance sur une Boucle deja a jour se termine sans appeler le moindre modele —
 * le court-circuit d'empreinte s'en charge, avant toute resolution de provider.
 * C'est ce qui rend le rejeu sur (retry, double dispatch) sans consequence.
 */
class DeriveLoopConversationKnowledge implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly string $loopId,
        public ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? AiCorrelation::id();
    }

    public function handle(LoopConversationKnowledgeDeriver $deriver): void
    {
        AiCorrelation::bind($this->correlationId);

        $loop = Loop::query()->with('organization')->find($this->loopId);

        if ($loop === null) {
            return;
        }

        $deriver->derive($loop);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            // Deux compilations simultanees de la MEME Boucle se marcheraient
            // dessus : la seconde partirait du meme etat que la premiere et
            // perdrait de toute facon au compare-and-swap (T1538). Autant ne
            // pas depenser l'appel.
            (new WithoutOverlapping($this->overlapKey()))
                ->releaseAfter(60)
                ->expireAfter(300),
        ];
    }

    public function overlapKey(): string
    {
        return 'loop-conversation-knowledge:'.$this->loopId;
    }
}
