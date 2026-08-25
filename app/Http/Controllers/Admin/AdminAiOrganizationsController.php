<?php

namespace App\Http\Controllers\Admin;

use App\Ai\ProviderResolver;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\Ai\AiProviderInvocationConsole;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * « Economie IA BouclePro » — cockpit plateforme (TASK-1223, TASK-1228).
 *
 * Le SuperAdmin voit, par Organization : la configuration IA (prete ou non —
 * la cle n'est JAMAIS affichee ni transmise), l'economie du mois et la sante
 * de l'index RAG (comptes, dates). Il ne voit RIEN du contenu tenant : ni
 * message, ni prompt, ni reponse, ni document, ni chunk, ni question posee.
 * Supervision != lecture.
 *
 * TASK-1228 — AUTORITE : les chiffres economiques (cout connu, generations,
 * recherches, indexations, inconnus, budget consomme) viennent de
 * `OrganizationAiEconomicUsage::perOrganization()` — la MEME autorite 1222
 * (generation `ai_interactions` = ce que la garde applique, embeddings =
 * ledger) que le releve de chaque Organization, groupee par Organization.
 * La ligne d'une Organization ici EST son `summary()` ; le total EST la
 * somme des lignes plus les traces sans Organization (rendues a part,
 * jamais reparties). Le ledger canonique n'alimente plus que les
 * METADONNEES (echecs, derniere activite) — jamais une somme parallele.
 *
 * Periode : `AiConsumptionFilters::currentMonth()` (UTC, fenetre de la
 * garde), identique aux trois niveaux.
 */
class AdminAiOrganizationsController extends Controller
{
    public function index(AiProviderInvocationConsole $console, OrganizationAiEconomicUsage $usage): View
    {
        $period = AiConsumptionFilters::currentMonth();
        $from = $period->from;
        $to = $period->to;

        $organizations = Organization::query()->orderBy('name')->get(['id', 'name', 'slug']);

        // TASK-1306 : instances completes (jamais serialisees telles quelles —
        // `$hidden` protege `api_key` de tout toArray()/toJson(), et cette vue
        // ne l'affiche jamais) pour le formulaire inline reutilisant EXACTEMENT
        // OrgAdminController::updateAi() (aucune deuxieme autorite de credential).
        $aiSettingModels = OrganizationAiSetting::query()->get()->keyBy('organization_id');

        $settings = $aiSettingModels
            ->map(static fn (OrganizationAiSetting $setting): array => [
                'provider' => $setting->provider,
                'model' => $setting->model,
                'monthly_budget_usd' => $setting->monthly_budget_usd,
                // La seule information transmise sur le credential : il est
                // defini ou non, quand il a change, et qui le gere. Jamais sa
                // valeur, meme chiffree.
                'ready' => $setting->isUsable(),
                'has_credential' => $setting->hasCredential(),
                'api_key_updated_at' => $setting->api_key_updated_at,
                'credential_management_mode' => $setting->credential_management_mode,
            ])
            ->all();

        $economics = $usage->perOrganization($from, $to);

        // Traces d'Organizations SUPPRIMEES (soft delete) : hors de la table
        // par Organization, mais dans le total plateforme — rendues sur une
        // ligne dediee pour que somme(lignes) + non attribuable + supprimees
        // == cartes, a l'ecran comme dans l'autorite (revue PASS B).
        $liveIds = $organizations->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $deletedRows = array_diff_key($economics['organizations'], array_flip($liveIds));
        $deleted = $this->sumRows(array_values($deletedRows));
        $activeOrganizations = count(array_filter(
            array_intersect_key($economics['organizations'], array_flip($liveIds)),
            static fn (array $row): bool => $row['total_count'] > 0,
        ));
        // Metadonnees ledger uniquement (echecs, derniere activite) : aucun
        // chiffre economique n'en est tire ici.
        $ledger = $console->platformPerOrganization($from, $to);
        $rag = $this->ragPerOrganization();

        $configuredCount = count(array_filter($settings, static fn (array $s): bool => $s['ready']));
        $budgetParts = array_filter(
            array_map(static fn (array $s): ?float => $s['monthly_budget_usd'] !== null ? (float) $s['monthly_budget_usd'] : null, $settings),
            static fn (?float $v): bool => $v !== null,
        );

        return view('admin.ai-organizations.index', [
            'from' => $from,
            'to' => $to,
            'organizations' => $organizations,
            'settings' => $settings,
            // TASK-1306 : formulaire inline « Configurer » — memes props que
            // /org/{slug}/admin/ai, une instance par Organization (jamais l'api_key).
            'aiSettingModels' => $aiSettingModels,
            'providers' => ProviderResolver::ALLOWED_PROVIDERS,
            'defaultModel' => (string) (config('ai.default_model') ?: config('ai.openrouter.model', 'openai/gpt-4o-mini')),
            'economics' => $economics['organizations'],
            'unattributed' => $economics['unattributed'],
            'deleted' => $deleted,
            'deletedCount' => count($deletedRows),
            'ledger' => $ledger,
            'rag' => $rag,
            'totals' => [
                'organizations' => $organizations->count(),
                'configured' => $configuredCount,
                'active_organizations' => $activeOrganizations,
                'ai_users' => $economics['totals']['ai_users_count'],
                'generation' => $economics['totals']['generation_count'],
                'generation_sandbox' => $economics['totals']['generation_sandbox_count'],
                'embedding_query' => $economics['totals']['embedding_query_count'],
                'embedding_ingestion' => $economics['totals']['embedding_ingestion_count'],
                'embedding_undeclared' => $economics['totals']['embedding_undeclared_count'],
                'failed' => array_sum(array_column($ledger, 'failed_count')),
                // NULL tant qu'aucune mesure reelle n'existe nulle part.
                'known_cost_usd' => $economics['totals']['known_cost_usd'],
                'unknown_count' => $economics['totals']['unknown_count'],
                'unevaluated_count' => $economics['totals']['unevaluated_count'],
                // Somme des budgets DECLARES : un budget absent n'est pas 0.
                'declared_budget_usd' => $budgetParts === [] ? null : array_sum($budgetParts),
                'declared_budget_count' => count($budgetParts),
            ],
        ]);
    }

    /**
     * Somme de lignes de l'autorite (couts connus null-aware, comptes) — pour
     * la ligne « Organizations supprimees ».
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function sumRows(array $rows): array
    {
        $known = array_filter(
            array_map(static fn (array $r): ?float => $r['total_known_cost_usd'], $rows),
            static fn (?float $v): bool => $v !== null,
        );

        return [
            'total_known_cost_usd' => $known === [] ? null : array_sum($known),
            'total_unknown_count' => array_sum(array_column($rows, 'total_unknown_count')),
            'total_count' => array_sum(array_column($rows, 'total_count')),
            'generation_count' => array_sum(array_map(static fn (array $r): int => $r['generation']['trace_count'], $rows)),
            'embedding_query_count' => array_sum(array_map(static fn (array $r): int => $r['embedding_query']['invocation_count'], $rows)),
            'embedding_ingestion_count' => array_sum(array_map(static fn (array $r): int => $r['embedding_ingestion']['invocation_count'], $rows)),
            'embedding_undeclared_count' => array_sum(array_map(static fn (array $r): int => $r['embedding_undeclared']['invocation_count'], $rows)),
        ];
    }

    /**
     * Sante RAG par Organization : UNE requete agregee — des comptes et des
     * dates, jamais un contenu de chunk.
     *
     * @return array<string, array{chunks: int, article_sources: int, file_sources: int, last_indexed_at: ?string}>
     */
    private function ragPerOrganization(): array
    {
        return DB::table('dossier_chunks')
            ->selectRaw('organization_id')
            ->selectRaw('COUNT(*) as chunks')
            ->selectRaw('COUNT(DISTINCT blog_post_id) as article_sources')
            ->selectRaw('COUNT(DISTINCT dossier_file_id) as file_sources')
            ->selectRaw('MAX(indexed_at) as last_indexed_at')
            ->groupBy('organization_id')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->organization_id => [
                    'chunks' => (int) $row->chunks,
                    'article_sources' => (int) $row->article_sources,
                    'file_sources' => (int) $row->file_sources,
                    'last_indexed_at' => $row->last_indexed_at !== null ? (string) $row->last_indexed_at : null,
                ],
            ])
            ->all();
    }
}
