<?php

namespace App\Services\Ai\DTO;

/**
 * Resultat d'un test « tester sans publier » de la doctrine (TASK-1227).
 *
 * Porte ce qui a REELLEMENT guide la reponse (Constitution, doctrine
 * candidate, capability, portee, sources) et l'issue : reponse obtenue,
 * refus AVANT l'appel (aucun ledger), ou aucune source. Aucun secret, aucun
 * prompt complet : de quoi expliquer, jamais de quoi rejouer.
 */
final class DoctrineSandboxResult
{
    public const STATUS_ANSWERED = 'answered';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_NO_SOURCES = 'no_sources';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  list<string>  $sourcesUsed
     * @param  array<string, string>  $sourcesDenied
     */
    public function __construct(
        public readonly string $status,
        public readonly string $capability,
        public readonly string $scope,
        public readonly string $constitutionVersion,
        public readonly ?string $doctrineLabel,
        public readonly array $sourcesUsed,
        public readonly array $sourcesDenied,
        public readonly int $sourcesCount,
        public readonly ?string $answer,
        public readonly ?string $refusalReason,
        public readonly bool $ledgered,
        public readonly ?string $interactionId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'capability' => $this->capability,
            'scope' => $this->scope,
            'constitution_version' => $this->constitutionVersion,
            'doctrine_label' => $this->doctrineLabel,
            'sources_used' => $this->sourcesUsed,
            'sources_denied' => $this->sourcesDenied,
            'sources_count' => $this->sourcesCount,
            'answer' => $this->answer,
            'refusal_reason' => $this->refusalReason,
            'ledgered' => $this->ledgered,
            'interaction_id' => $this->interactionId,
        ];
    }
}
