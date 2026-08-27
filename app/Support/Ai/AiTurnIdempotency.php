<?php

namespace App\Support\Ai;

use App\Models\LoopMessage;
use RuntimeException;

/**
 * TASK-1311 : un tour deja repondu ne se rejoue pas.
 *
 * ## Pourquoi le verrou ne suffit pas
 *
 * `AiTurnLock` arbitre la COURSE : deux requetes qui se chevauchent. Il ne voit
 * rien du REJEU — un tour relance a trois secondes d'intervalle, un retry apres
 * re-render Livewire, un retour arriere. Le verrou est deja libere : la seconde
 * generation partirait, et facturerait.
 *
 * Cette garde-ci lit la TABLE plutot que le cache. Elle ne depend d'aucun TTL,
 * d'aucun store, d'aucune fenetre de temps : tant que la reponse existe dans le
 * fil, le tour est clos.
 *
 * > Le verrou traite la course, l'idempotence traite le rejeu.
 * > Les deux sont necessaires ; ils ne se remplacent pas.
 *
 * ## L'identite d'un tour
 *
 * Le message HUMAIN declencheur, et lui seul. C'est le seul identifiant stable
 * qui distingue « le meme tour rejoue » de « deux tours differents ».
 *
 * Ce que cette garde ne peut PAS faire, et pourquoi : un double clic cree DEUX
 * messages humains distincts (`sendUserMessage()` ne deduplique pas), donc deux
 * declencheurs, donc deux tours a ses yeux. Le double clic releve du verrou,
 * pas d'ici. Confondre les deux mecanismes, c'est en avoir zero qui tienne.
 *
 * De meme, le TEXTE de la question n'est jamais une cle : deux questions
 * identiques peuvent etre parfaitement intentionnelles.
 */
final class AiTurnIdempotency
{
    /**
     * Ce message declencheur a-t-il DEJA une reponse IA dans le fil ?
     *
     * Volontairement pur : la condition est la meme que celle qui a servi a
     * ecrire la reponse (`reply_to_id` du declencheur, `type = 'ai'`), pas une
     * seconde regle qui pourrait diverger.
     */
    public static function alreadyAnswered(LoopMessage $trigger): bool
    {
        return LoopMessage::query()
            ->where('loop_id', $trigger->loop_id)
            ->where('reply_to_id', $trigger->id)
            ->where('type', 'ai')
            ->exists();
    }

    /**
     * Refuse un tour deja repondu.
     *
     * Le refus porte son propre message : « une generation est en cours » et
     * « ce tour a deja sa reponse » sont deux etats differents, et un
     * utilisateur qui lit le mauvais des deux cherchera au mauvais endroit.
     *
     * @throws RuntimeException
     */
    public static function assertNotAnswered(?LoopMessage $trigger): void
    {
        if ($trigger !== null && self::alreadyAnswered($trigger)) {
            throw new RuntimeException(__('loops.ai_turn_already_answered'));
        }
    }
}
