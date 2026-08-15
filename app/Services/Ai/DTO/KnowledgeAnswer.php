<?php

namespace App\Services\Ai\DTO;

/**
 * Reponse documentaire sourcee (TASK-1213 / RAG V1).
 *
 * `sources` ne contient QUE des entrees de provenance du Context Builder :
 * aucune citation ne peut designer un document que le retrieval n'a pas
 * fourni — donc que l'utilisateur ne peut pas ouvrir.
 */
final class KnowledgeAnswer
{
    /**
     * @param  list<array<string, mixed>>  $sources  provenance citee (ou consultee si rien n'est cite)
     * @param  list<array<string, mixed>>  $consulted  toute la provenance fournie au modele
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
        public readonly array $consulted,
        public readonly bool $grounded,
        public readonly ?string $interactionId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $public = static fn (array $source): array => [
            'ref' => $source['ref'] ?? null,
            'title' => $source['title'] ?? null,
            'dossier_name' => $source['dossier_name'] ?? null,
            'excerpt' => $source['extrait'] ?? null,
            'url' => $source['url'] ?? null,
        ];

        return [
            'answer' => $this->answer,
            'grounded' => $this->grounded,
            'sources' => array_map($public, $this->sources),
            'consulted' => array_map($public, $this->consulted),
        ];
    }
}
