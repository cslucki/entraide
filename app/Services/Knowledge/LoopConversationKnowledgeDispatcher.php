<?php

namespace App\Services\Knowledge;

use App\Jobs\DeriveLoopConversationKnowledge;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1539 — QUAND BouclePro apprend.
 *
 * ## Pourquoi une fenetre d'inactivite, et pas un observer
 *
 * Un observer sur `LoopMessage` appellerait un modele a chaque phrase, « ok »
 * et « merci » compris. Et differer chaque message ne resout rien : dix
 * messages rapproches produiraient dix jobs differes que `WithoutOverlapping`
 * se contenterait de SERIALISER — dix compilations, pas une.
 *
 * Ce qui fait converger N messages vers UNE compilation, c'est d'attendre que
 * la conversation se soit POSEE. On ne compile pas un echange en cours : on
 * compile ce qui s'est dit, une fois qu'il a fini de se dire.
 *
 * Ce balayeur selectionne donc les Boucles qui remplissent DEUX conditions :
 *
 *  1. **calmees** — plus rien depuis `quiet_minutes` ;
 *  2. **porteuses de nouveaute** — de l'activite humaine posterieure a ce que
 *     la memoire connait deja.
 *
 * ## Ce qu'il ne fait PAS
 *
 * Il ne juge pas de la valeur de ce qui a ete dit, et ne cherche pas a deviner
 * si un message « merite » une compilation. Cette question a deja sa reponse,
 * en aval et sans cout : `LoopConversationKnowledgeDeriver` ecarte les messages
 * trop courts, puis compare l'empreinte de la source AVANT toute resolution de
 * provider. Un « ok » ne change pas l'empreinte, donc ne coute rien — meme si
 * un job est parti pour lui.
 *
 * Le budget economique, lui, n'entre pas ici : il REFUSE une depense, il ne
 * decide pas d'un rythme. Confondre les deux ferait dependre la frequence
 * d'apprentissage du solde du mois.
 */
final class LoopConversationKnowledgeDispatcher
{
    /**
     * Jamais `default` : cette file porte 207 jobs historiques mis en
     * quarantaine le 23/08/2026, qui se rapportent et ne se consomment pas.
     */
    public const DEDICATED_QUEUE = 'loop-conversation-knowledge';

    /** Borne dure par balayage : un incident ne vide pas le budget d'un coup. */
    private const MAX_PAR_BALAYAGE = 25;

    public function dispatchFor(Loop $loop, ?string $queue = null): void
    {
        DeriveLoopConversationKnowledge::dispatch((string) $loop->id)
            ->afterCommit()
            ->onQueue($queue !== null && $queue !== '' ? $queue : self::DEDICATED_QUEUE);
    }

    /**
     * Les Boucles calmees ET porteuses de nouveaute.
     *
     * @return int le nombre de jobs dispatches
     */
    public function dispatchDue(?string $queue = null): int
    {
        $n = 0;

        foreach ($this->due() as $loop) {
            $this->dispatchFor($loop, $queue);
            $n++;
        }

        return $n;
    }

    /**
     * @return list<Loop>
     */
    public function due(): array
    {
        $calme = now()->subMinutes($this->quietMinutes());

        // La derniere activite HUMAINE de chaque Boucle. `type = 'user'` et
        // `deleted_at` nul : exactement la meme population que celle que le
        // deriver lira, sinon le balayeur promettrait du travail qui n'existe
        // pas.
        $derniere = LoopMessage::query()
            ->selectRaw('loop_id, max(created_at) as dernier')
            ->where('type', 'user')
            ->whereNull('deleted_at')
            // Le seuil de longueur vient du deriver lui-meme : une Boucle ou
            // l'on n'a echange que des « ok » n'a rien a compiler, et la
            // declarer due la ferait redispatcher a chaque balayage, sans fin.
            ->whereRaw('length(trim(body)) >= ?', [LoopConversationKnowledgeDeriver::MIN_MESSAGE_CHARS])
            ->groupBy('loop_id');

        // Ce que la memoire connait deja de chaque Boucle.
        $memoire = DerivedKnowledgeNote::query()
            ->selectRaw('source_loop_id, max(observed_at) as connu')
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('status', DerivedKnowledgeNote::STATUS_ACTIVE)
            ->groupBy('source_loop_id');

        return Loop::query()
            ->with('organization')
            ->joinSub($derniere, 'activite', 'activite.loop_id', '=', 'loops.id')
            // Sans Dossier racine, le deriver refuse : il ne saurait pas ou
            // ranger la note ni de quel perimetre documentaire elle releve.
            // Mesure en environnement reel : trois Boucles sentinelles sans
            // Dossier racine restaient dues INDEFINIMENT, redispatchees a
            // chaque balayage. Aucun appel provider — le court-circuit tenait —
            // mais une file qui tourne a vide et une metrique de retard qui ne
            // veut plus rien dire.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('dossiers')
                ->whereColumn('dossiers.loop_id', 'loops.id')
                ->whereNull('dossiers.deleted_at'))
            ->leftJoinSub($memoire, 'memoire', 'memoire.source_loop_id', '=', 'loops.id')
            ->where('activite.dernier', '<=', $calme)
            ->where(function ($q): void {
                $q->whereNull('memoire.connu')
                    ->orWhereColumn('activite.dernier', '>', 'memoire.connu');
            })
            ->orderBy('activite.dernier')
            ->limit(self::MAX_PAR_BALAYAGE)
            ->select('loops.*')
            ->get()
            ->all();
    }

    private function quietMinutes(): int
    {
        return max(1, (int) config('ai.knowledge.conversation.quiet_minutes', 10));
    }
}
