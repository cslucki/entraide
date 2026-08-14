<?php

namespace App\Services\ChatLoop;

use App\Models\AiInteraction;
use App\Support\Ai\AiMarkdownSanitizer;
use Illuminate\Support\Carbon;

/**
 * Dernier resume IA d'une Boucle, relu depuis sa trace technique
 * (TASK-1207 / IA P3).
 *
 * `loop_summary` est une capability READ-ONLY metier : le resume ne peut plus
 * etre persiste en `LoopMessage`, qui est une publication visible de la Boucle.
 * Il est relu depuis `ai_interactions`, la trace P1 que l'appel produit deja.
 *
 * `ai_interactions.response` conserve le texte BRUT du provider — c'est ce
 * qu'une trace doit contenir. L'assainissement Markdown est donc rejoue a la
 * lecture : `AiMarkdownSanitizer::sanitize()` est une fonction pure, le rendu
 * est identique a celui du chemin legacy, sans dupliquer le texte en base.
 */
final class LoopSummary
{
    public function __construct(
        public readonly string $body,
        public readonly ?Carbon $createdAt,
        public readonly ?string $requestedById,
        public readonly string $aiInteractionId,
        public readonly ?string $provider,
        /** Representation de trace `"{provider}/{model}"`, telle qu'en base. */
        public readonly ?string $traceModel,
        public readonly ?string $correlationId,
        public readonly ?string $sdkInvocationId,
    ) {}

    public static function fromInteraction(AiInteraction $interaction, ?int $limit = null): self
    {
        $metadata = is_array($interaction->metadata) ? $interaction->metadata : [];

        return new self(
            body: AiMarkdownSanitizer::sanitize((string) $interaction->response, $limit),
            createdAt: $interaction->created_at,
            requestedById: isset($metadata['requested_by']) ? (string) $metadata['requested_by'] : null,
            aiInteractionId: (string) $interaction->id,
            provider: isset($metadata['provider']) ? (string) $metadata['provider'] : null,
            traceModel: $interaction->model,
            correlationId: $interaction->correlation_id,
            sdkInvocationId: isset($metadata['sdk_invocation_id']) ? (string) $metadata['sdk_invocation_id'] : null,
        );
    }
}
