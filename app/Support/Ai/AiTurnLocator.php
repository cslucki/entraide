<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\LoopMessage;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * TASK-1584 / CDC-02 TRACE-1C — retrouver un tour persiste a partir d'UNE cle,
 * dans UNE Organization.
 *
 * La cle est un uuid qui peut designer, dans l'ordre : une AiInteraction, un
 * LoopMessage (→ son `metadata.ai_interaction_id`), une ligne assistant du
 * Shell (→ son interaction si elle en a une, sinon la ligne elle-meme —
 * zero-provider). Toutes les requetes sont bornees a l'Organization : une cle
 * d'ailleurs rend `null`, sans dire qu'elle existe (J7).
 *
 * Query-full par nature — c'est le sibling qui REQUETE ; `AiTurnInspection`
 * reste query-free et `AiTurnComparison` pure.
 */
final class AiTurnLocator
{
    /**
     * @return array{kind: string, interaction: ?AiInteraction, shell_message: ?AiShellMessage, inspection: array<string, mixed>}|null
     */
    public static function locate(Organization $organization, string $cle): ?array
    {
        $cle = trim($cle);

        if (! Str::isUuid($cle)) {
            return null;
        }

        $orgId = (string) $organization->id;

        $interaction = AiInteraction::query()->where('organization_id', $orgId)->whereKey($cle)->first();
        if ($interaction instanceof AiInteraction) {
            return ['kind' => 'interaction', 'interaction' => $interaction, 'shell_message' => null, 'inspection' => AiTurnInspection::fromPersistedTurn($interaction)];
        }

        $message = LoopMessage::query()->where('organization_id', $orgId)->whereKey($cle)->first();
        if ($message instanceof LoopMessage) {
            $interaction = self::interactionLiee($orgId, is_array($message->metadata) ? ($message->metadata['ai_interaction_id'] ?? null) : null);

            return $interaction instanceof AiInteraction
                ? ['kind' => 'loop_message', 'interaction' => $interaction, 'shell_message' => null, 'inspection' => AiTurnInspection::fromPersistedTurn($interaction)]
                : null;
        }

        $ligne = AiShellMessage::query()->where('organization_id', $orgId)->whereKey($cle)->where('role', AiShellMessage::ROLE_ASSISTANT)->first();
        if ($ligne instanceof AiShellMessage) {
            $interaction = self::interactionLiee($orgId, is_array($ligne->metadata) ? ($ligne->metadata['ai_interaction_id'] ?? null) : null);

            return $interaction instanceof AiInteraction
                ? ['kind' => 'shell_message', 'interaction' => $interaction, 'shell_message' => $ligne, 'inspection' => AiTurnInspection::fromPersistedTurn($interaction, $ligne)]
                : ['kind' => 'shell_message', 'interaction' => null, 'shell_message' => $ligne, 'inspection' => AiTurnInspection::fromPersistedTurn($ligne)];
        }

        return null;
    }

    private static function interactionLiee(string $orgId, mixed $interactionId): ?AiInteraction
    {
        if (! is_string($interactionId) || ! Str::isUuid($interactionId)) {
            return null;
        }

        return AiInteraction::query()->where('organization_id', $orgId)->whereKey($interactionId)->first();
    }
}
