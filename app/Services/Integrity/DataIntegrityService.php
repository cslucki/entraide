<?php

namespace App\Services\Integrity;

use App\Models\Dossier;
use App\Support\AssignData\DatasetClassification;
use App\Support\AssignData\DatasetRegistry;
use App\Support\Integrity\IntegrityCheck;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\SchemaReferenceInspector;
use App\Support\Integrity\UnprotectedReferenceRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1632 — le moteur du cockpit « Integrite des donnees ».
 *
 * ## Lecture seule, et pas seulement par politesse
 *
 * Aucune methode de ce service n'ecrit. Aucune route de la TASK n'est en POST.
 * C'est ce qui permet a un SuperAdmin d'ouvrir l'ecran sans se demander ce
 * qu'il risque — et c'est teste, pas seulement affirme.
 *
 * ## Ce que le cockpit ne fait PAS
 *
 * Il ne rattache rien (TASK-1631), ne purge rien (TASK-1630), et ne
 * reimplemente ni leur registre ni leur purge. Il lit `DatasetRegistry` pour
 * resumer les donnees sans Organization, compte les sous-dossiers legacy, et
 * renvoie vers les deux outils qui savent agir.
 *
 * ## Pourquoi si peu de controles
 *
 * Parce que le schema fait deja le travail. 107 FK protegent
 * `organization_id`, 24 protegent `loop_id`, 6 protegent `dossier_id` : sur
 * ces colonnes, une reference cassee est impossible, et le verifier ligne a
 * ligne reviendrait a demander a PostgreSQL de se contredire. Le cockpit dit
 * cela — « garanti par le schema, N tables » — au lieu d'afficher un zero
 * obtenu en balayant des tables que la base protege deja. Un zero mesure et
 * un zero structurel ne valent pas la meme chose, et les confondre rendrait
 * l'ecran rassurant pour de mauvaises raisons.
 */
class DataIntegrityService
{
    public const GROUP_ORGANIZATION = 'organizations';

    public const GROUP_LOOP = 'loops';

    public const GROUP_DOSSIER = 'dossiers';

    public const GROUP_SCOPING = 'scoping';

    public const GROUP_HISTORY = 'history';

    public function __construct(
        private readonly SchemaReferenceInspector $inspector,
        private readonly UnprotectedReferenceRegistry $registry,
        private readonly DatasetRegistry $datasets,
    ) {}

    /**
     * Tous les controles, dans l'ordre d'affichage.
     *
     * @return Collection<int, IntegrityCheck>
     */
    public function all(): Collection
    {
        return collect([
            $this->organizationReferences(),
            $this->organizationForeignKeyCoverage(),
            $this->providerLedgerHistory(),
            $this->loopReferences(),
            $this->dossierReferences(),
            $this->dossierSoftDeletedRagResidues(),
            $this->legacySubfolders(),
            $this->unscopedData(),
        ]);
    }

    public function worstStatus(): IntegrityStatus
    {
        return IntegrityStatus::worst($this->all()->map(fn (IntegrityCheck $c) => $c->status));
    }

    /**
     * @return array<string, int> statut => nombre de controles
     */
    public function summary(): array
    {
        $counts = array_fill_keys(array_map(fn ($s) => $s->value, IntegrityStatus::cases()), 0);

        foreach ($this->all() as $check) {
            $counts[$check->status->value]++;
        }

        return $counts;
    }

    // ── Organizations ──────────────────────────────────────────────────────

    /**
     * Les references vers une Organization disparue, la ou aucune FK ne les
     * empeche — et uniquement la ou cela constitue un defaut.
     *
     * `ai_provider_invocations` est exclu ici par construction : sa trace est
     * volontaire, elle a son propre controle en Information.
     */
    public function organizationReferences(): IntegrityCheck
    {
        $total = 0;

        foreach ($this->registry->unguardedTables('organization_id') as $table) {
            $total += $this->brokenReferences($table, 'organization_id', 'organizations');
        }

        return new IntegrityCheck(
            key: 'organization_broken_references',
            group: self::GROUP_ORGANIZATION,
            status: $total > 0 ? IntegrityStatus::ActionRequired : IntegrityStatus::Ok,
            count: $total,
            replacements: ['tables' => implode(', ', $this->registry->unguardedTables('organization_id'))],
            detailKey: 'organization_broken_references',
        );
    }

    /**
     * Ce que le SCHEMA garantit — un zero structurel, pas un zero mesure.
     */
    public function organizationForeignKeyCoverage(): IntegrityCheck
    {
        $all = count($this->inspector->tablesWithColumn('organization_id'));
        $without = count($this->inspector->tablesWithoutForeignKey('organization_id'));

        return new IntegrityCheck(
            key: 'organization_foreign_key_coverage',
            group: self::GROUP_ORGANIZATION,
            status: IntegrityStatus::Information,
            count: $all - $without,
            replacements: ['total' => $all, 'without' => $without],
        );
    }

    /**
     * Le ledger economique : combien de lignes portent l'UUID d'une
     * Organization qui n'existe plus.
     *
     * **Information, jamais Action requise.** La FK a ete retiree par une
     * migration dediee pour que la facturation survive a la disparition d'une
     * Organization : ces lignes sont la preuve que le dispositif fonctionne,
     * pas un defaut. Aucun bouton, aucune purge, aucune reassignation.
     */
    public function providerLedgerHistory(): IntegrityCheck
    {
        $tables = $this->registry->protectedHistoryTables('organization_id');
        $invocations = 0;
        $organizations = 0;

        foreach ($tables as $table) {
            $invocations += $this->brokenReferences($table, 'organization_id', 'organizations');
            $organizations += $this->distinctBrokenReferences($table, 'organization_id', 'organizations');
        }

        return new IntegrityCheck(
            key: 'provider_ledger_history',
            group: self::GROUP_HISTORY,
            status: IntegrityStatus::Information,
            count: $invocations,
            replacements: ['organizations' => $organizations],
            detailKey: 'provider_ledger_history',
        );
    }

    // ── Boucles ────────────────────────────────────────────────────────────

    /**
     * `loop_id` : 24 tables, 24 cles etrangeres, aucune exception. Le controle
     * rapporte ce que le schema garantit, et rougirait si une table sans
     * contrainte apparaissait — c'est la garde de couverture qui le dit.
     */
    public function loopReferences(): IntegrityCheck
    {
        $all = count($this->inspector->tablesWithColumn('loop_id'));
        $without = $this->inspector->tablesWithoutForeignKey('loop_id');
        $broken = 0;

        foreach ($without as $table) {
            $broken += $this->brokenReferences($table, 'loop_id', 'loops');
        }

        return new IntegrityCheck(
            key: 'loop_references',
            group: self::GROUP_LOOP,
            status: $broken > 0 ? IntegrityStatus::ActionRequired : IntegrityStatus::Ok,
            count: $broken,
            replacements: ['tables' => $all, 'unprotected' => count($without)],
        );
    }

    // ── Dossiers ───────────────────────────────────────────────────────────

    public function dossierReferences(): IntegrityCheck
    {
        $all = count($this->inspector->tablesWithColumn('dossier_id'));
        $without = $this->inspector->tablesWithoutForeignKey('dossier_id');
        $broken = 0;

        foreach ($without as $table) {
            $broken += $this->brokenReferences($table, 'dossier_id', 'dossiers');
        }

        // `dossiers.parent_id` est en SET NULL : un enfant ne peut pas
        // pointer un parent detruit, il devient racine. Le cas reellement
        // interessant est le parent en CORBEILLE, traite juste en dessous.
        return new IntegrityCheck(
            key: 'dossier_references',
            group: self::GROUP_DOSSIER,
            status: $broken > 0 ? IntegrityStatus::ActionRequired : IntegrityStatus::Ok,
            count: $broken,
            replacements: ['tables' => $all, 'unprotected' => count($without)],
        );
    }

    /**
     * Les extraits RAG et les notes derivees encore attaches a un Dossier mis
     * a la corbeille.
     *
     * Mesure, pas supposition : `DossierController::destroy()` — le chemin
     * MEMBRE, en soft delete — nettoie les Series, les liaisons d'Articles,
     * les fichiers et les membres, mais ni `dossier_chunks` ni
     * `derived_knowledge_notes`. La ligne `dossiers` restant en base, les
     * CASCADE ne se declenchent pas. Le chemin SuperAdmin, lui, les supprime
     * (`DossierTreePurger::purgeKnowledge()`).
     *
     * **A surveiller, pas a corriger.** Aucune decision produit ne dit
     * aujourd'hui combien de temps un extrait doit survivre a la mise en
     * corbeille de son Dossier. Le cockpit le montre ; l'arbitrage appartient
     * a MASTER.
     */
    public function dossierSoftDeletedRagResidues(): IntegrityCheck
    {
        $trashed = Dossier::onlyTrashed()->pluck('id');

        $chunks = $trashed->isEmpty() ? 0 : DB::table('dossier_chunks')->whereIn('dossier_id', $trashed)->count();
        $notes = $trashed->isEmpty() ? 0 : DB::table('derived_knowledge_notes')->whereIn('dossier_id', $trashed)->count();

        $total = $chunks + $notes;

        return new IntegrityCheck(
            key: 'dossier_soft_deleted_rag_residues',
            group: self::GROUP_DOSSIER,
            status: $total > 0 ? IntegrityStatus::Watch : IntegrityStatus::Ok,
            count: $total,
            replacements: ['chunks' => $chunks, 'notes' => $notes, 'dossiers' => $trashed->count()],
            detailKey: 'dossier_soft_deleted_rag_residues',
        );
    }

    /**
     * Les sous-dossiers legacy — comptes ici, traites par TASK-1630.
     */
    public function legacySubfolders(): IntegrityCheck
    {
        $count = Dossier::withTrashed()->whereNotNull('parent_id')->count();

        return new IntegrityCheck(
            key: 'legacy_subfolders',
            group: self::GROUP_DOSSIER,
            status: $count > 0 ? IntegrityStatus::Watch : IntegrityStatus::Ok,
            count: $count,
        );
    }

    // ── Donnees sans Organization ─────────────────────────────────────────

    /**
     * Le resume des donnees sans Organization, lu sur le registre de
     * TASK-1631 — pas sur un second registre concurrent.
     *
     * Seules les lignes d'un dataset **affectable** comptent comme « a
     * traiter » : un NULL dans `categories` ou `translation_overrides`
     * signifie « Plateforme », et le presenter comme un probleme serait
     * exactement l'erreur que TASK-1631 a corrigee.
     */
    public function unscopedData(): IntegrityCheck
    {
        $assignable = 0;
        $legitimate = 0;

        foreach ($this->datasets->all() as $dataset) {
            $without = $dataset->query()->whereNull('organization_id')->count();

            if ($dataset->classification === DatasetClassification::AssignableTenant) {
                $assignable += $without;
            } else {
                $legitimate += $without;
            }
        }

        return new IntegrityCheck(
            key: 'unscoped_data',
            group: self::GROUP_SCOPING,
            status: $assignable > 0 ? IntegrityStatus::Watch : IntegrityStatus::Ok,
            count: $assignable,
            replacements: ['legitimate' => $legitimate],
        );
    }

    // ── Les lignes derriere un controle ───────────────────────────────────

    /**
     * Les lignes d'un controle, pour la vue detail. Lecture seule, paginee.
     */
    public function detailQuery(string $detailKey): ?Builder
    {
        return match ($detailKey) {
            'organization_broken_references' => $this->brokenOrganizationRows(),
            'provider_ledger_history' => $this->ledgerHistoryRows(),
            'dossier_soft_deleted_rag_residues' => $this->softDeletedRagRows(),
            default => null,
        };
    }

    private function brokenOrganizationRows(): ?Builder
    {
        $tables = $this->registry->unguardedTables('organization_id');

        if ($tables === []) {
            return null;
        }

        $query = null;

        foreach ($tables as $table) {
            $part = DB::table($table)
                ->selectRaw('? as source_table', [$table])
                ->addSelect('id', 'organization_id', 'created_at')
                ->whereNotNull('organization_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('organizations')
                    ->whereColumn('organizations.id', $table.'.organization_id'));

            $query = $query === null ? $part : $query->unionAll($part);
        }

        return $query;
    }

    private function ledgerHistoryRows(): ?Builder
    {
        $tables = $this->registry->protectedHistoryTables('organization_id');

        if ($tables === []) {
            return null;
        }

        $table = $tables[0];

        return DB::table($table)
            ->select('organization_id')
            ->selectRaw('count(*) as invocations')
            ->selectRaw('min(created_at) as first_seen')
            ->selectRaw('max(created_at) as last_seen')
            ->whereNotNull('organization_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('organizations')
                ->whereColumn('organizations.id', $table.'.organization_id'))
            ->groupBy('organization_id');
    }

    private function softDeletedRagRows(): ?Builder
    {
        return DB::table('dossiers')
            ->select('dossiers.id', 'dossiers.name', 'dossiers.organization_id', 'dossiers.loop_id', 'dossiers.owner_id', 'dossiers.deleted_at')
            ->selectSub(
                DB::table('dossier_chunks')->selectRaw('count(*)')->whereColumn('dossier_chunks.dossier_id', 'dossiers.id'),
                'chunks'
            )
            ->selectSub(
                DB::table('derived_knowledge_notes')->selectRaw('count(*)')->whereColumn('derived_knowledge_notes.dossier_id', 'dossiers.id'),
                'notes'
            )
            ->whereNotNull('dossiers.deleted_at')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('dossier_chunks')->whereColumn('dossier_chunks.dossier_id', 'dossiers.id'))
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('derived_knowledge_notes')->whereColumn('derived_knowledge_notes.dossier_id', 'dossiers.id'))
            );
    }

    /**
     * Les lignes de `$table` dont `$column` pointe une ligne absente de
     * `$parentTable`.
     *
     * `NOT EXISTS` plutot que `LEFT JOIN ... IS NULL` : c'est la forme que
     * PostgreSQL optimise le mieux, et elle dit litteralement ce qu'on
     * cherche. Le nom de table ne vient jamais d'une entree utilisateur — il
     * sort du registre, ecrit a la main — mais il est verifie quand meme.
     */
    private function brokenReferences(string $table, string $column, string $parentTable): int
    {
        if (! $this->isSafeIdentifier($table)) {
            return 0;
        }

        return DB::table($table)
            ->whereNotNull($column)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($parentTable)
                ->whereColumn($parentTable.'.id', $table.'.'.$column))
            ->count();
    }

    private function distinctBrokenReferences(string $table, string $column, string $parentTable): int
    {
        if (! $this->isSafeIdentifier($table)) {
            return 0;
        }

        return DB::table($table)
            ->whereNotNull($column)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($parentTable)
                ->whereColumn($parentTable.'.id', $table.'.'.$column))
            ->distinct()
            ->count($column);
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }
}
