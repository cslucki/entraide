<?php

namespace App\Services\Knowledge;

use App\Jobs\DeriveLoopConversationKnowledge;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;

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
 * ## TASK-1542 — ce que cette regle, seule, ne pouvait pas apprendre
 *
 * La fenetre d'inactivite suppose que les conversations finissent par se
 * taire. Toutes ne se taisent pas. Une Boucle ou quelqu'un parle toutes les
 * cinq minutes ne franchit jamais `dernier <= calme` : elle n'etait donc
 * JAMAIS compilee — pas « en retard », jamais. Sans limite de temps, sans
 * alerte, et precisement sur les Boucles les plus actives, c'est-a-dire celles
 * qui ont le plus a apprendre.
 *
 * Le remede n'est pas de raccourcir la fenetre de calme : cela compilerait des
 * echanges en cours partout, pour reparer un cas particulier.
 *
 * C'est une SECONDE question, que la premiere ne pose pas : **depuis combien
 * de temps y a-t-il de la matiere non apprise ?** Au-dela d'un plafond, on
 * compile pendant que les gens parlent encore — parce qu'attendre indefiniment
 * coute plus que compiler un echange inacheve.
 *
 * Le plafond ne remplace pas le debounce, il le BORNE. En deca, N messages
 * rapproches convergent toujours vers une seule compilation ; le cout ajoute
 * vaut au pire un appel par Boucle et par plafond — et zero quand l'empreinte
 * de source n'a pas bouge.
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
     * Les Boucles porteuses de nouveaute, calmees OU en attente depuis trop
     * longtemps.
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
        $limite = now()->subMinutes($this->maxLearningDelayMinutes());

        // La derniere activite HUMAINE de chaque Boucle. `type = 'user'` et
        // `deleted_at` nul : exactement la meme population que celle que le
        // deriver lira, sinon le balayeur promettrait du travail qui n'existe
        // pas.
        $derniere = LoopMessage::query()
            // `premier` sert au plafond de retard quand la memoire est VIDE :
            // il n'y a alors aucun `connu` a partir duquel mesurer l'attente,
            // et c'est le plus ancien message qui dit depuis quand on attend.
            ->selectRaw('loop_id, max(created_at) as dernier, min(created_at) as premier')
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
            // LA condition qui ne se negocie pas : il faut de la matiere NON
            // APPRISE. Elle vaut pour les deux chemins.
            //
            // Sans elle, le plafond redeviendrait une horloge : chaque Boucle
            // compilee serait redispatchee toutes les deux heures, pour rien —
            // le court-circuit d'empreinte eviterait la depense, mais la file
            // tournerait en boucle et la metrique de retard ne voudrait plus
            // rien dire.
            ->where(function ($q): void {
                $q->whereNull('memoire.connu')
                    ->orWhereColumn('activite.dernier', '>', 'memoire.connu');
            })
            ->where(function ($q) use ($calme, $limite): void {
                // Chemin 1 — la conversation s'est posee. C'est le cas normal,
                // et celui qui fait converger N messages vers UNE compilation.
                $q->where('activite.dernier', '<=', $calme)
                    // Chemin 2 — elle ne se pose pas, et cela dure.
                    //
                    // On mesure l'attente depuis la DERNIERE compilation, ou
                    // depuis le premier message si rien n'a jamais ete
                    // compile. Jamais depuis le dernier message : celui-la est
                    // recent par definition dans une conversation continue, et
                    // le plafond ne se declencherait jamais.
                    ->orWhere(fn ($cap) => $cap->whereNotNull('memoire.connu')
                        ->where('memoire.connu', '<=', $limite))
                    ->orWhere(fn ($cap) => $cap->whereNull('memoire.connu')
                        ->where('activite.premier', '<=', $limite));
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

    /**
     * Le plafond de retard, jamais plus court que la fenetre de calme.
     *
     * Un plafond inferieur prendrait la main sur elle : toute Boucle ayant de
     * la matiere non apprise depuis plus longtemps que le plafond deviendrait
     * due, y compris en pleine conversation, et le debounce ne servirait plus
     * a rien. La borne n'est pas une precaution de style — c'est ce qui
     * garantit que le plafond reste l'exception.
     */
    private function maxLearningDelayMinutes(): int
    {
        return max(
            $this->quietMinutes(),
            (int) config('ai.knowledge.conversation.max_learning_delay_minutes', 120),
        );
    }
}
