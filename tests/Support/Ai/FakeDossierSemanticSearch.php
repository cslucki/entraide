<?php

namespace Tests\Support\Ai;

use App\Services\Dossiers\DossierSemanticSearchService;

/**
 * Double du moteur pgvector, partageable entre suites.
 *
 * `TASK1213KnowledgeAnswerTest` en declare un equivalent en bas de son propre
 * fichier. Le reutiliser depuis une autre suite marcherait tant que PHPUnit
 * charge les deux fichiers, et casserait des qu'on joue la nouvelle suite
 * seule — un couplage invisible jusqu'au jour ou il mord. Celui-ci vit donc
 * dans `tests/Support`, comme les fixtures de scenario pack.
 */
class FakeDossierSemanticSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null, ?array $onlyDossierFileIds = null): array
    {
        $this->lastCall = compact('organizationId', 'dossierIds', 'query', 'embeddingInstance', 'limit', 'traceMetadata', 'candidateLimit', 'onlyDossierFileIds');

        $rows = $this->rows;

        // TASK-1516 : ce double est le SEUL partage entre suites, donc le seul
        // qu'une TASK future reutilisera sans le relire. Un double qui accepte
        // une restriction de perimetre et l'ignore rendrait un test vert alors
        // que la restriction ne s'applique pas — le pire des faux verts. Il
        // l'applique donc reellement.
        if ($onlyDossierFileIds !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array(($row['dossier_file_id'] ?? null), $onlyDossierFileIds, true),
            ));
        }

        return array_slice($rows, 0, $candidateLimit ?? $limit);
    }
}
