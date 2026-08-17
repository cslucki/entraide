<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\Ai\AiProviderInvocationConsole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cockpit IA/RAG plateforme (TASK-1223) — supervision par METADONNEES.
 *
 * Le SuperAdmin voit, par Organization : la configuration IA (prete ou non —
 * la cle n'est JAMAIS affichee ni transmise), les invocations du mois depuis
 * le ledger canonique (generation / embeddings ingestion-query / echecs /
 * cout connu / inconnus), et la sante de l'index RAG (chunks, sources,
 * derniere indexation). Il ne voit RIEN du contenu tenant : ni message, ni
 * prompt, ni reponse, ni document, ni chunk. Supervision != lecture.
 *
 * Ces chiffres viennent des registres surs (ledger 1220 via le read model
 * 1223, semantique economique 1222) — jamais de l'ancienne lecture
 * `ia-usage-by-user` dont les agregats etaient economiquement faux.
 */
class AdminAiOrganizationsController extends Controller
{
    public function index(AiProviderInvocationConsole $console): View
    {
        $from = CarbonImmutable::now()->startOfMonth();
        $to = $from->addMonth();

        $organizations = Organization::query()->orderBy('name')->get(['id', 'name', 'slug']);

        $settings = OrganizationAiSetting::query()
            ->get(['organization_id', 'provider', 'model', 'monthly_budget_usd', 'is_enabled', 'api_key'])
            ->keyBy('organization_id')
            ->map(static fn (OrganizationAiSetting $setting): array => [
                'provider' => $setting->provider,
                'model' => $setting->model,
                'monthly_budget_usd' => $setting->monthly_budget_usd,
                // La seule information transmise sur le credential : il est
                // defini ou non. Jamais sa valeur, meme chiffree.
                'ready' => $setting->isUsable(),
            ])
            ->all();

        $ledger = $console->platformPerOrganization($from, $to);
        $rag = $this->ragPerOrganization();

        $configuredCount = count(array_filter($settings, static fn (array $s): bool => $s['ready']));
        $knownParts = array_filter(
            array_map(static fn (array $row): ?float => $row['known_cost_usd'], $ledger),
            static fn (?float $v): bool => $v !== null,
        );

        return view('admin.ai-organizations.index', [
            'from' => $from,
            'organizations' => $organizations,
            'settings' => $settings,
            'ledger' => $ledger,
            'rag' => $rag,
            'totals' => [
                'organizations' => $organizations->count(),
                'configured' => $configuredCount,
                'invocations' => array_sum(array_map(
                    static fn (array $row): int => $row['generation_count']
                        + $row['embedding_ingestion_count']
                        + $row['embedding_query_count']
                        + $row['embedding_undeclared_count'],
                    $ledger,
                )),
                'generation' => array_sum(array_column($ledger, 'generation_count')),
                'embeddings' => array_sum(array_map(
                    static fn (array $row): int => $row['embedding_ingestion_count']
                        + $row['embedding_query_count']
                        + $row['embedding_undeclared_count'],
                    $ledger,
                )),
                'failed' => array_sum(array_column($ledger, 'failed_count')),
                // NULL tant qu'aucune mesure reelle n'existe nulle part.
                'known_cost_usd' => $knownParts === [] ? null : array_sum($knownParts),
                'unknown_cost_count' => array_sum(array_column($ledger, 'unknown_cost_count')),
            ],
        ]);
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
