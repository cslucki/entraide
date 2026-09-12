<?php

namespace App\Jobs;

use App\Models\DerivedKnowledgeNote;
use App\Services\Knowledge\DerivedKnowledgeNoteIndexer;
use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use App\Support\Ai\AiCorrelation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * TASK-1548 — l'indexation d'un enonce CORRIGE A LA MAIN, hors chemin critique.
 *
 * `ClaimMemory::appliquer()` indexait en ligne, juste avant de rendre la main.
 * Hors de la transaction, certes — mais pas hors du chemin critique : l'accuse
 * de reception de la personne attendait un aller-retour d'embedding, et un
 * fournisseur injoignable faisait remonter une exception sur une correction
 * pourtant deja commitee. La personne aurait lu « echec » sur un fait acquis.
 *
 * La mutation fait foi ; le vecteur suit. Ce job porte ce « suit ».
 *
 * Il vise la queue que le planificateur consomme DEJA
 * ({@see LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE}, inscrite dans
 * l'allowlist de `routes/console.php`). Une queue neuve serait dispatchee et
 * jamais consommee — le defaut silencieux que T1511 avait corrige, et que
 * `default` illustre encore avec ses jobs en quarantaine.
 */
class IndexDerivedKnowledgeNote implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $noteId,
        public ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? AiCorrelation::id();
        $this->onQueue(LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE);
    }

    public function handle(DerivedKnowledgeNoteIndexer $indexer): void
    {
        AiCorrelation::bind($this->correlationId);

        $note = DerivedKnowledgeNote::find($this->noteId);

        if ($note === null) {
            // La ligne a pu etre re-corrigee entre-temps : il n'y a plus rien
            // a indexer, et ce n'est pas une erreur.
            return;
        }

        $indexer->synchronize($note);
    }
}
