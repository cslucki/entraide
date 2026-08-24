<?php

namespace App\Services\Ai\DTO;

use App\Support\Ai\AiUserCreditStatus;

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
        /**
         * TASK-1229 : etat du credit IA du demandeur APRES cette reponse (ce
         * qu'il lui reste, alerte de seuil) — jamais un chiffre d'Organization.
         */
        public readonly ?AiUserCreditStatus $credit = null,
    ) {}

    /**
     * Forme publique d'une source : jamais de chunk_id ni d'identifiant
     * interne — la meme pour la reponse JSON et la metadata du LoopMessage
     * persiste (TASK-1297).
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function publicSource(array $source): array
    {
        return [
            'ref' => $source['ref'] ?? null,
            'title' => $source['title'] ?? null,
            'dossier_name' => $source['dossier_name'] ?? null,
            'excerpt' => $source['extrait'] ?? null,
            'url' => $source['url'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'grounded' => $this->grounded,
            'sources' => array_map(self::publicSource(...), $this->sources),
            'consulted' => array_map(self::publicSource(...), $this->consulted),
            'credit' => $this->credit?->toArray(),
        ];
    }
}
