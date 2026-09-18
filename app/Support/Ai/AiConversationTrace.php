<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\LoopMessage;

/**
 * TASK-1579 / CDC-02 TRACE-1A — les tours se RELIENT.
 *
 * Depuis un message LoopChat (n'importe quel maillon d'une chaine de reply) ou
 * une conversation Shell, reconstruire la suite de tours et dire, pour chaque
 * tour, ce qu'il a VU des precedents — d'apres son propre bloc `turn.history`
 * (V0-L), jamais d'apres une supposition.
 *
 * ## Ce que c'est
 *
 * Une LECTURE, sur le modele d'`AiTurnInspection` (V0-H) qu'elle appelle pour
 * chaque tour. Deux strategies de liaison, nommees et jamais fusionnees (J3) :
 *
 *   reply_chain   LoopChat — remonter `reply_to_id` depuis le message donne,
 *                 bornee a `depth` maillons (defaut 6 = `MAX_THREAD_DEPTH` du
 *                 produit, `AiConversationContextBuilder:40`) ;
 *   shell_thread  Shell — les lignes assistant d'un `conversation_id`, dans
 *                 l'ordre, les `depth` dernieres.
 *
 * ## Ce que ce n'est pas
 *
 * Aucune ecriture. Aucune relation qui ne soit portee par `reply_to_id` ou
 * `conversation_id` (pas de conversation « implicite » par proximite). Aucune
 * qualification (« aurait du voir ») : c'est CDC-04. Un tour sans bloc `turn`
 * (anterieur au V0) rend `UNAVAILABLE`, jamais `NO` (I12).
 *
 * ## J7 — ACL
 *
 * Toute lecture est scoped a l'Organization donnee ; une chaine qui change
 * d'Organization OU de Boucle s'arrete avec `CROSS_TENANT_LINK_REFUSED` et
 * ne rend aucun identifiant du maillon refuse.
 */
final class AiConversationTrace
{
    public const STRATEGY_REPLY_CHAIN = 'reply_chain';

    public const STRATEGY_SHELL_THREAD = 'shell_thread';

    /** Meme borne que le produit : la lecture ne « voit » jamais plus loin que lui. */
    public const DEFAULT_DEPTH = 6;

    public const YES = 'YES';

    public const NO = 'NO';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public const STOP_CROSS_TENANT = 'CROSS_TENANT_LINK_REFUSED';

    public const STOP_DEPTH = 'DEPTH_REACHED';

    public const STOP_DELETED_LINK = 'REPLY_TO_DELETED_OR_MISSING';

    /** Types de messages que le produit lit dans une chaine (`AiConversationContextBuilder:45`). */
    private const TYPES_LUS = ['user', 'ai'];

    /**
     * LoopChat : la chaine de reply qui CONTIENT ce message, du plus ancien au
     * plus recent, et un tour par bulle IA.
     *
     * @return array<string, mixed>
     */
    public static function fromLoopMessage(LoopMessage $message, string $organizationId, int $depth = self::DEFAULT_DEPTH): array
    {
        $depth = max(1, $depth);
        $chaine = [];
        $arret = null;
        $frontiere = null;
        $idRefuse = null;
        $courant = $message;

        // Remontee bornee : le message donne compte pour un maillon.
        for ($i = 0; $i < $depth; $i++) {
            if ((string) $courant->organization_id !== $organizationId || (string) $courant->loop_id !== (string) $message->loop_id) {
                // J7 : rien de ce maillon n'est rendu — ni id, ni type — et le
                // maillon accepte qui pointait vers lui ne rend pas non plus
                // son `reply_to_id` (ce serait l'id refuse).
                $arret = self::STOP_CROSS_TENANT;
                $frontiere = (string) end($chaine)->id;
                $idRefuse = (string) $courant->id;

                break;
            }

            $chaine[] = $courant;

            if ($courant->reply_to_id === null) {
                break;
            }

            $parent = LoopMessage::query()->whereKey($courant->reply_to_id)->first();

            if (! $parent instanceof LoopMessage) {
                // s4 : un `reply_to_id` vers un message disparu — la chaine est
                // intacte jusqu'ici, et l'arret est dit.
                $arret = self::STOP_DELETED_LINK;

                break;
            }

            if ($i === $depth - 1) {
                $arret = self::STOP_DEPTH;
            }

            $courant = $parent;
        }

        $chaine = array_reverse($chaine);
        $tours = [];
        $precedent = null;
        $position = 0;

        foreach ($chaine as $index => $maillon) {
            if ($maillon->type !== 'ai') {
                continue;
            }

            $inspection = self::inspectionDeBulle($maillon, $organizationId);
            $tour = self::resume(++$position, (string) $maillon->id, $inspection, $idRefuse);
            $tour['derived'] = self::derives(
                $inspection,
                $precedent['inspection'] ?? null,
                $precedent['message_id'] ?? null,
                self::dernierMessageUtilisateurAvant($chaine, $index, $maillon),
                self::STRATEGY_REPLY_CHAIN,
            );
            $tours[] = $tour;
            $precedent = ['inspection' => $inspection, 'message_id' => (string) $maillon->id];
        }

        return [
            'mode' => 'conversation',
            'strategy' => self::STRATEGY_REPLY_CHAIN,
            'anchor' => ['message_id' => (string) $message->id, 'loop_id' => (string) $message->loop_id],
            'depth' => $depth,
            'chain' => array_map(static fn (LoopMessage $m): array => [
                'message_id' => (string) $m->id,
                'type' => (string) $m->type,
                'reply_to_id' => $m->reply_to_id !== null && (string) $m->id !== $frontiere ? (string) $m->reply_to_id : null,
                'reply_to_refused' => (string) $m->id === $frontiere,
                'deleted' => $m->isDeleted(),
            ], $chaine),
            'turns' => $tours,
            'stopped' => $arret === null ? null : ['reason_code' => $arret, 'at_message_id' => $frontiere ?? (string) end($chaine)->id],
        ];
    }

    /**
     * Shell : les lignes assistant d'une conversation, dans l'ordre, les
     * `depth` dernieres — un tour par ligne.
     *
     * @return array<string, mixed>
     */
    public static function fromShellConversation(string $conversationId, string $organizationId, int $depth = self::DEFAULT_DEPTH): array
    {
        $depth = max(1, $depth);

        $lignes = AiShellMessage::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversationId)
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($depth + 1)
            ->get()
            ->reverse()
            ->values();

        $tronquee = $lignes->count() > $depth;
        $lignes = $lignes->slice($tronquee ? 1 : 0)->values();

        $tours = [];
        $precedent = null;

        foreach ($lignes as $position => $ligne) {
            $inspection = self::inspectionDeLigneShell($ligne, $organizationId);
            $tour = self::resume($position + 1, (string) $ligne->id, $inspection);
            $tour['derived'] = self::derives(
                $inspection,
                $precedent['inspection'] ?? null,
                $precedent['message_id'] ?? null,
                // Shell : le message humain declencheur est `reply_to_id` de la
                // ligne assistant PRECEDENTE ; le produit le lit dans le fil.
                $precedent !== null ? $precedent['trigger_id'] : null,
                self::STRATEGY_SHELL_THREAD,
            );
            $tours[] = $tour;
            $precedent = [
                'inspection' => $inspection,
                'message_id' => (string) $ligne->id,
                'trigger_id' => $ligne->reply_to_id !== null ? (string) $ligne->reply_to_id : null,
            ];
        }

        return [
            'mode' => 'conversation',
            'strategy' => self::STRATEGY_SHELL_THREAD,
            'anchor' => ['conversation_id' => $conversationId],
            'depth' => $depth,
            'chain' => $lignes->map(static fn (AiShellMessage $l): array => [
                'message_id' => (string) $l->id,
                'type' => 'assistant',
                'reply_to_id' => $l->reply_to_id !== null ? (string) $l->reply_to_id : null,
                'reply_to_refused' => false,
                'deleted' => false,
            ])->all(),
            'turns' => $tours,
            'stopped' => $tronquee ? ['reason_code' => self::STOP_DEPTH, 'at_message_id' => (string) $lignes->first()->id] : null,
        ];
    }

    // ────────────────────────────── lecture d'un tour

    /**
     * La bulle IA porte `metadata.ai_interaction_id` (FACT T1233) ; sans
     * interaction dans CE tenant, le tour est UNAVAILABLE.
     *
     * @return array<string, mixed>|null
     */
    private static function inspectionDeBulle(LoopMessage $bulle, string $organizationId): ?array
    {
        $interactionId = is_array($bulle->metadata) ? ($bulle->metadata['ai_interaction_id'] ?? null) : null;

        if (! is_string($interactionId) || $interactionId === '') {
            return null;
        }

        $interaction = AiInteraction::query()->where('organization_id', $organizationId)->whereKey($interactionId)->first();

        return $interaction instanceof AiInteraction ? AiTurnInspection::fromPersistedTurn($interaction) : null;
    }

    /** @return array<string, mixed> */
    private static function inspectionDeLigneShell(AiShellMessage $ligne, string $organizationId): array
    {
        $interactionId = is_array($ligne->metadata) ? ($ligne->metadata['ai_interaction_id'] ?? null) : null;
        $interaction = is_string($interactionId) && $interactionId !== ''
            ? AiInteraction::query()->where('organization_id', $organizationId)->whereKey($interactionId)->first()
            : null;

        return $interaction instanceof AiInteraction
            ? AiTurnInspection::fromPersistedTurn($interaction, $ligne)
            : AiTurnInspection::fromPersistedTurn($ligne);
    }

    /**
     * Les champs d'un tour que la conversation rend (CDC-02 §5.1) — lus dans
     * l'inspection, `null` = UNAVAILABLE.
     *
     * @param  array<string, mixed>|null  $inspection
     * @return array<string, mixed>
     */
    private static function resume(int $position, string $messageId, ?array $inspection, ?string $idRefuse = null): array
    {
        $identity = $inspection['identity'] ?? null;
        $history = $inspection['history'] ?? null;

        // J7 : le produit a pu ecrire dans `turn.history` l'id du message
        // auquel on repondait, meme hors Boucle (`trigger_id`, V0-L). Le
        // maillon a ete refuse par cette lecture : son id ne sort pas ici non
        // plus — le champ est masque et le dit.
        $triggerRefuse = $history !== null && $idRefuse !== null && (string) ($history['trigger_id'] ?? '') === $idRefuse;

        return [
            'position' => $position,
            'message_id' => $messageId,
            'turn_id' => $inspection['run']['turn_id'] ?? null,
            'ai_interaction_id' => $inspection['run']['ai_interaction_id'] ?? null,
            'execution_path' => $identity['execution_path'] ?? null,
            'mode' => $identity['mode'] ?? null,
            'status' => $inspection['decision']['status'] ?? null,
            'reason_code' => $inspection['decision']['reason_code'] ?? null,
            'history' => $history === null ? null : [
                'strategy' => $history['strategy'] ?? null,
                'count' => $history['count'] ?? null,
                'trigger_id' => $triggerRefuse ? null : ($history['trigger_id'] ?? null),
                'trigger_refused' => $triggerRefuse,
                'input_message_id' => $history['input_message_id'] ?? null,
            ],
            'turn_available' => is_array($inspection) && is_array($inspection['run'] ?? null) && ($inspection['run']['turn_id'] ?? null) !== null,
        ];
    }

    // ────────────────────────────── derives

    /**
     * Les six derives du §5.1 + `REFERENT_RESOLUTION`, chacun `YES|NO|UNAVAILABLE`,
     * et la raison de chaque UNAVAILABLE. Aucune inference : un `NO` n'est
     * rendu que quand les DEUX termes de la comparaison sont mesures.
     *
     * @param  array<string, mixed>|null  $tour
     * @param  array<string, mixed>|null  $precedent
     * @return array<string, mixed>
     */
    private static function derives(?array $tour, ?array $precedent, ?string $bullePrecedente, ?string $messageUtilisateurPrecedent, string $strategie): array
    {
        $raisons = [];
        $history = $tour['history'] ?? null;
        $vus = is_array($history['message_ids'] ?? null) ? array_map('strval', $history['message_ids']) : null;

        $visible = static function (?string $id, string $cle) use ($vus, $tour, &$raisons): string {
            if ($id === null) {
                $raisons[$cle] = 'no_previous_turn';

                return self::UNAVAILABLE;
            }
            if ($tour === null || ($tour['run']['turn_id'] ?? null) === null) {
                $raisons[$cle] = 'turn_unavailable';

                return self::UNAVAILABLE;
            }
            if ($vus === null) {
                $raisons[$cle] = 'history_unavailable';

                return self::UNAVAILABLE;
            }

            return in_array($id, $vus, true) ? self::YES : self::NO;
        };

        $changement = static function (string $cle, callable $lecture) use ($tour, $precedent, &$raisons): string {
            if ($precedent === null) {
                $raisons[$cle] = 'no_previous_turn';

                return self::UNAVAILABLE;
            }
            $a = $lecture($precedent);
            $b = $lecture($tour);
            if ($a === null || $b === null) {
                $raisons[$cle] = 'turn_unavailable';

                return self::UNAVAILABLE;
            }

            return $a === $b ? self::NO : self::YES;
        };

        $contextBuilder = static function (?array $t): ?string {
            foreach ($t['steps'] ?? [] as $etape) {
                if (($etape['name'] ?? null) === 'context_builder') {
                    // executed d'un cote, bypassed/not_applicable de l'autre = change.
                    return ($etape['status'] ?? null) === 'executed' ? 'executed' : 'not_executed';
                }
            }

            return null;
        };

        $derives = [
            'PREVIOUS_AI_ANSWER_VISIBLE' => $visible($bullePrecedente, 'PREVIOUS_AI_ANSWER_VISIBLE'),
            'PREVIOUS_USER_MESSAGE_VISIBLE' => $visible($messageUtilisateurPrecedent, 'PREVIOUS_USER_MESSAGE_VISIBLE'),
            'MODE_CHANGED' => $changement('MODE_CHANGED', static fn (?array $t) => $t['identity']['mode'] ?? null),
            'EXECUTION_PATH_CHANGED' => $changement('EXECUTION_PATH_CHANGED', static fn (?array $t) => $t['identity']['execution_path'] ?? null),
            'CONTEXT_BUILDER_CHANGED' => $changement('CONTEXT_BUILDER_CHANGED', $contextBuilder),
        ];

        // DOSSIER_CONTEXT_PRESERVED : sources.used ∩ precedent ≠ ∅.
        if ($precedent === null) {
            $raisons['DOSSIER_CONTEXT_PRESERVED'] = 'no_previous_turn';
            $derives['DOSSIER_CONTEXT_PRESERVED'] = self::UNAVAILABLE;
        } else {
            $a = $precedent['sources']['used'] ?? null;
            $b = $tour['sources']['used'] ?? null;
            if (! is_array($a) || ! is_array($b)) {
                $raisons['DOSSIER_CONTEXT_PRESERVED'] = 'sources_unavailable';
                $derives['DOSSIER_CONTEXT_PRESERVED'] = self::UNAVAILABLE;
            } else {
                $derives['DOSSIER_CONTEXT_PRESERVED'] = array_intersect($a, $b) !== [] ? self::YES : self::NO;
            }
        }

        // REFERENT_RESOLUTION : LoopChat n'a aucun resolver (DECLARED) ; le
        // Shell en a une branche, nommee par son chemin.
        if ($tour === null || ($tour['run']['turn_id'] ?? null) === null) {
            $raisons['REFERENT_RESOLUTION'] = 'turn_unavailable';
            $derives['REFERENT_RESOLUTION'] = self::UNAVAILABLE;
        } elseif ($strategie === self::STRATEGY_SHELL_THREAD) {
            $derives['REFERENT_RESOLUTION'] = ($tour['identity']['execution_path'] ?? null) === AiExecutionPath::AI_SHELL_REFERENCE ? 'shell_branch' : 'none_declared';
        } else {
            $derives['REFERENT_RESOLUTION'] = 'none_declared';
        }

        $derives['unavailable_reasons'] = $raisons;

        return $derives;
    }

    /**
     * Le message HUMAIN le plus recent de la chaine avant cette bulle, hors le
     * declencheur direct de la bulle (celui-ci est `input_message_id`, pas un
     * message « precedent »).
     *
     * @param  list<LoopMessage>  $chaine
     */
    private static function dernierMessageUtilisateurAvant(array $chaine, int $index, LoopMessage $bulle): ?string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $m = $chaine[$i];
            if ((string) $m->id === (string) $bulle->reply_to_id) {
                continue;
            }
            if ($m->type === 'user' && in_array($m->type, self::TYPES_LUS, true)) {
                return (string) $m->id;
            }
        }

        return null;
    }
}
