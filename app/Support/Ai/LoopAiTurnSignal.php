<?php

namespace App\Support\Ai;

use App\Models\Loop;
use App\Models\LoopMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-1316 : « une reponse IA est en cours » — l'etat partage d'un tour, vu
 * par les AUTRES membres de la Boucle.
 *
 * ## Pourquoi ce n'est pas `AiTurnLock` qui repond
 *
 * L'audit d'autorite (`ai/scripts/audit-1316-signal-authority.php`, mesure
 * reelle sur deux processus) a etabli trois faits :
 *
 * 1. La valeur stockee par le verrou est le booleen `true`. Elle ne porte NI le
 *    mode demande, NI le message declencheur, NI l'instant de depart. Le
 *    demandeur n'y figure que parce qu'il est encode dans la CLE — donc
 *    seulement si on le connait deja.
 * 2. L'API Cache n'offre aucune enumeration par prefixe. Repondre « quelqu'un
 *    genere-t-il ici ? » impose de forger une cle par membre actif et de les
 *    sonder toutes : O(membres) requetes a CHAQUE battement de `wire:poll.3s`,
 *    par spectateur, pour une information vide la quasi-totalite du temps.
 * 3. Le `finally` de `AiTurnLock::run()` libere sur refus economique et sur
 *    panne provider — mais pas sur un fatal PHP ni un timeout. Un verrou
 *    orphelin survit alors jusqu'a son TTL. Le verrou dit « personne n'a rendu
 *    la cle », jamais « la generation tourne encore ».
 *
 * Detourner le verrou en source d'etat d'interface aurait donc coute cher pour
 * afficher peu, et menti jusqu'a 90 secondes dans le seul cas ou le signal
 * compte vraiment : celui ou quelque chose a mal tourne.
 *
 * ## L'autorite retenue : ce qui est deja ecrit
 *
 * `LoopChat::sendMessage()` persiste le message HUMAIN — et son
 * `metadata.requested_mode` — AVANT toute generation, dans la transaction
 * propre de `LoopMessageService::sendUserMessage()`, donc COMMITEE. Le meme
 * audit l'a lu depuis un second processus avant que l'IA n'ait repondu.
 *
 * Ce message porte les trois informations que le verrou n'a pas :
 * QUI (`sender_id`), QUOI (`requested_mode`), DEPUIS QUAND (`created_at`).
 *
 * Et la condition de fin existe elle aussi deja : c'est exactement celle de
 * `AiTurnIdempotency::alreadyAnswered()` — un message `type = 'ai'` dont le
 * `reply_to_id` est le declencheur. Les trois modes la respectent
 * (`respondInThread()` et `publishExchange()`). Ecrire une seconde regle de
 * fin, c'etait accepter qu'elles divergent un jour.
 *
 * ## Le verrou garde le role qui est le sien
 *
 * Les messages savent QUI et QUOI ; ils ne savent pas si le tour VIT encore.
 * Un refus provider laisse le declencheur sans reponse pour toujours : sur les
 * messages seuls, le signal resterait affiche jusqu'a la borne de temps.
 *
 * Le verrou, lui, sait precisement cela — et son `finally` couvre le refus
 * economique comme la panne provider. Il est donc consulte, mais UNIQUEMENT
 * pour confirmer la vivacite du ou des candidats deja identifies : une sonde
 * ciblee, jamais une enumeration. Zero candidat, zero lecture de cache.
 *
 * > Les messages repondent « qui, et quoi ». Le verrou repond « encore ? ».
 * > Chacun repond a la question qui est la sienne.
 *
 * ## La borne de temps
 *
 * Elle est `AiTurnLock::ttl()`, appelee et non recopiee : au-dela, le verrou
 * n'existe plus par construction, donc le signal ne peut plus etre vrai. Les
 * deux ne peuvent pas deriver l'un de l'autre.
 */
final class LoopAiTurnSignal
{
    /**
     * Les modes du composeur unifie (TASK-1308/1309) qui declenchent un tour
     * IA. `normal` n'en est pas un : aucun moteur, aucune depense, rien a
     * signaler.
     */
    public const REQUESTED_MODES = ['ia', 'dossiers', 'ia_dossiers'];

    /**
     * Le discriminant canonique d'affichage (`ai_mode`, TASK-1312) que PORTERA
     * la reponse. Le signal annonce ainsi exactement le badge qui va paraitre :
     * un mode annonce puis un autre affiche serait une promesse trahie.
     */
    private const MODE_TO_AI_MODE = [
        'ia' => 'llm',
        'dossiers' => 'rag',
        'ia_dossiers' => 'llm_rag',
    ];

    /**
     * Les tours IA en cours dans cette Boucle, derives des messages DEJA
     * charges par le composant — le cas courant ne coute donc aucune requete.
     *
     * @param  Collection<int, LoopMessage>  $messages  le fil deja rendu
     * @return array<int, array{message_id: string, requester_id: string, requester_name: string, ai_mode: string}>
     */
    public static function pendingTurns(Loop $loop, Collection $messages): array
    {
        $since = now()->subSeconds(AiTurnLock::ttl());

        $candidates = $messages->filter(function (LoopMessage $message) use ($since): bool {
            // `user` seulement : un message `member_agent` (TASK-1298) est la
            // parole d'un membre, jamais une demande faite a l'IA de la Boucle.
            return $message->type === 'user'
                && $message->sender_id !== null
                && in_array($message->metadata['requested_mode'] ?? null, self::REQUESTED_MODES, true)
                && $message->created_at !== null
                && $message->created_at->greaterThanOrEqualTo($since);
        });

        if ($candidates->isEmpty()) {
            return [];
        }

        // Une seule requete, et seulement s'il existe un candidat. La condition
        // est celle d'`AiTurnIdempotency::alreadyAnswered()`, pas une seconde
        // regle de fin qui pourrait diverger.
        $answered = LoopMessage::query()
            ->where('loop_id', $loop->id)
            ->where('type', 'ai')
            ->whereIn('reply_to_id', $candidates->pluck('id')->all())
            ->pluck('reply_to_id')
            ->all();

        $turns = [];

        foreach ($candidates as $message) {
            if (in_array($message->id, $answered, true)) {
                continue;
            }

            $requester = $message->sender;

            if ($requester === null) {
                continue;
            }

            // La sonde de vivacite — ciblee sur ce demandeur, dans cette
            // Boucle, dans ce tenant. C'est elle qui fait disparaitre le signal
            // des qu'un refus economique ou une panne provider a rendu la cle.
            if (! Cache::has(AiTurnLock::key($loop, $requester))) {
                continue;
            }

            $turns[] = [
                'message_id' => (string) $message->id,
                'requester_id' => (string) $requester->id,
                'requester_name' => $requester->publicDisplayName(),
                'ai_mode' => self::MODE_TO_AI_MODE[$message->metadata['requested_mode']],
            ];
        }

        return $turns;
    }
}
