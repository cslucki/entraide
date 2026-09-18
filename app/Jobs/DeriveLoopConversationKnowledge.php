<?php

namespace App\Jobs;

use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Services\Knowledge\DerivedKnowledgeNoteIndexer;
use App\Services\Knowledge\LoopClaimCompiler;
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

    public function handle(LoopClaimCompiler $compiler, DerivedKnowledgeNoteIndexer $indexer): void
    {
        AiCorrelation::bind($this->correlationId);

        $loop = Loop::query()->with('organization')->find($this->loopId);

        if ($loop === null) {
            return;
        }

        // TASK-1541 — le chemin automatique compile desormais des ENONCES.
        //
        // Un seul appel, comme avant : le patch remplace le paragraphe, il ne
        // s'y ajoute pas. Le cout par compilation ne bouge donc pas, et
        // `knowledge:derive-loop-conversations` reste disponible pour produire
        // un digest a la demande (compatibilite, CDC §9).
        $bilan = $compiler->compile($loop);

        if (! ($bilan['applique'] ?? false)) {
            return;
        }

        // Le digest de cette Boucle n'est plus servi des lors qu'elle a des
        // enonces : ses vecteurs partent, sa ligne reste. Sans ce nettoyage, la
        // bascule laisserait derriere elle un doublon de chaque fait —
        // l'indexeur refuse deja d'en produire de nouveaux, mais il faut encore
        // retirer ceux qui existent.
        //
        // `synchronize()` plutot que `forget()` : c'est l'indexeur qui sait si
        // une ligne merite ses vecteurs. Lui passer la decision evite d'ecrire
        // ici une seconde regle d'eclipse, qui divergerait de la premiere au
        // premier changement.
        $digest = DerivedKnowledgeNote::query()
            ->where('organization_id', $loop->organization_id)
            ->where('source_loop_id', $loop->id)
            ->where('kind', DerivedKnowledgeNote::KIND_DIGEST)
            ->active()
            ->first();

        if ($digest !== null) {
            $indexer->synchronize($digest);
        }
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
