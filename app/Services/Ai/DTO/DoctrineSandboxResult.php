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
     * @param  list<array{source: string, id: string, type: string, extrait: string}>  $provenance
     */
    public function __construct(
        public readonly string $status,
        public readonly string $organizationId,
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
        /**
         * Nombre de lignes du ledger canonique ecrites pour CE test (generation
         * ET requete d'embedding de la recherche documentaire) : la verite
         * economique, meme quand aucune generation n'a ete emise.
         */
        public readonly int $ledgerEntries,
        public readonly ?string $interactionId,
        /**
         * TASK-1533 — la cle de correlation du tour.
         *
         * Le DTO portait `interactionId` seul, ce qui suffit a relire UNE ligne
         * `ai_interactions`. L'Inspector a besoin du LEDGER canonique
         * (`ai_provider_invocations`), qui peut porter DEUX lignes pour un meme
         * tour — la generation et la requete d'embedding — et qui, lui, dit
         * NULL quand un compteur n'a pas ete observe.
         *
         * Rendre la correlation, c'est donc rendre lisible ce que le ledger a
         * reellement ecrit, sans deuxieme mecanisme de mesure. Presente meme
         * sur un refus AVANT appel : rien n'y est ledger, et le dire est une
         * information.
         */
        public readonly ?string $correlationId = null,
        /**
         * TASK-1533 — la provenance des sources REELLEMENT utilisees.
         *
         * `ContexteBorne` la porte deja (source, type, id, extrait borne a 240
         * caracteres, collectee APRES les gardes d'acces de chaque source) et le
         * bac a sable la jetait. La rendre, c'est repondre a « sur quoi cette
         * reponse s'appuie-t-elle ? » sans deuxieme lecture documentaire, donc
         * sans deuxieme chemin d'acces a revalider.
         *
         * Invariant : elle ne decrit QUE des sources utilisees. Une source
         * refusee n'en produit aucune entree — le builder l'ecarte avant
         * collecte — et rien ici ne doit jamais en fabriquer une, sous peine de
         * transformer un refus en oracle.
         */
        public readonly array $provenance = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'organization_id' => $this->organizationId,
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
            'ledger_entries' => $this->ledgerEntries,
            'interaction_id' => $this->interactionId,
            'correlation_id' => $this->correlationId,
            'provenance' => $this->provenance,
        ];
    }
}
