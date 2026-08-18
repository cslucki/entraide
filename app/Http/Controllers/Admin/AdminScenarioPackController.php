<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * UI Admin (SuperAdmin plateforme) des scenario packs (TASK-1240/TASK-1241).
 *
 * Volontairement mince : toutes les garanties tenant (allowlist,
 * idempotence, suppression bornee) vivent dans le moteur
 * `App\Support\ScenarioPacks\*` ; ce controleur ne fait que resoudre les
 * entrees, appeler le moteur et afficher son resultat. Jamais d'action non
 * bornee a un seul couple (pack, Organization).
 */
class AdminScenarioPackController extends Controller
{
    public function index(Request $request): View
    {
        $packIds = array_keys(config('scenario_packs.definitions', []));
        $organizations = Organization::query()
            ->whereIn('slug', config('scenario_packs.allowed_organizations', []))
            ->orderBy('name')
            ->get();

        $selectedPackId = $request->query('pack');
        if (! in_array($selectedPackId, $packIds, true)) {
            $selectedPackId = $packIds[0] ?? null;
        }

        $selectedOrganization = $organizations->firstWhere('slug', $request->query('organization'))
            ?? $organizations->first();

        $pack = null;
        $status = null;

        if ($selectedPackId !== null && $selectedOrganization !== null) {
            $pack = app(ScenarioPackCatalog::class)->get($selectedPackId);
            $status = $this->currentStatus($selectedPackId, $selectedOrganization);
        }

        return view('admin.scenario-packs.index', [
            'packIds' => $packIds,
            'organizations' => $organizations,
            'selectedPackId' => $selectedPackId,
            'selectedOrganization' => $selectedOrganization,
            'pack' => $pack,
            'status' => $status,
        ]);
    }

    public function load(Request $request, ScenarioPackLoader $loader): RedirectResponse
    {
        [$pack, $organization] = $this->resolveSelection($request);

        try {
            $result = $loader->load($pack, $organization);
        } catch (RuntimeException $e) {
            return $this->back($pack->packId(), $organization->slug)->with('error', $e->getMessage());
        }

        $label = $result->wasFirstLoad ? 'Premier chargement' : 'Rechargement (idempotent)';

        return $this->back($pack->packId(), $organization->slug)
            ->with('success', "{$label} : {$result->totalEntities()} entite(s) pour « {$pack->packName()} » dans « {$organization->name} ».");
    }

    public function reset(Request $request, ScenarioPackResetter $resetter): RedirectResponse
    {
        [$pack, $organization] = $this->resolveSelection($request);

        try {
            $result = $resetter->reset($pack, $organization);
        } catch (RuntimeException $e) {
            return $this->back($pack->packId(), $organization->slug)->with('error', $e->getMessage());
        }

        $orphans = count($result->removedOrphans);

        return $this->back($pack->packId(), $organization->slug)
            ->with('success', "Reset effectue pour « {$pack->packName()} » dans « {$organization->name} » : {$result->totalEntities()} entite(s) restante(s), {$orphans} orpheline(s) retiree(s).");
    }

    public function delete(Request $request, ScenarioPackRemover $remover): RedirectResponse
    {
        [$pack, $organization] = $this->resolveSelection($request);

        try {
            $remover->remove($pack->packId(), $organization);
        } catch (RuntimeException $e) {
            return $this->back($pack->packId(), $organization->slug)->with('error', $e->getMessage());
        }

        return $this->back($pack->packId(), $organization->slug)
            ->with('success', "« {$pack->packName()} » retire de « {$organization->name} » (ou n'etait deja pas charge).");
    }

    /**
     * @return array{0: ScenarioPackDefinition, 1: Organization}
     */
    private function resolveSelection(Request $request): array
    {
        $allowedOrganizations = config('scenario_packs.allowed_organizations', []);
        $registeredPackIds = array_keys(config('scenario_packs.definitions', []));

        $data = $request->validate([
            'pack' => ['required', 'string', Rule::in($registeredPackIds)],
            'organization' => ['required', 'string', Rule::in($allowedOrganizations)],
        ]);

        $pack = app(ScenarioPackCatalog::class)->get($data['pack']);
        $organization = Organization::query()->where('slug', $data['organization'])->firstOrFail();

        return [$pack, $organization];
    }

    private function back(string $packId, string $organizationSlug): RedirectResponse
    {
        return redirect()->route('admin.scenario-packs', ['pack' => $packId, 'organization' => $organizationSlug]);
    }

    /**
     * @return array{loaded: bool, pack_version: ?string, loaded_at: ?string, reset_at: ?string, counts: array<string, int>, total: int}
     */
    private function currentStatus(string $packId, Organization $organization): array
    {
        $load = ScenarioPackLoad::query()
            ->where('organization_id', $organization->id)
            ->where('pack_id', $packId)
            ->first();

        if ($load === null) {
            return ['loaded' => false, 'pack_version' => null, 'loaded_at' => null, 'reset_at' => null, 'counts' => [], 'total' => 0];
        }

        $counts = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $load->id)
            ->selectRaw('entity_type, count(*) as aggregate')
            ->groupBy('entity_type')
            ->pluck('aggregate', 'entity_type')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'loaded' => true,
            'pack_version' => $load->pack_version,
            'loaded_at' => $load->loaded_at?->toDateTimeString(),
            'reset_at' => $load->reset_at?->toDateTimeString(),
            'counts' => $counts,
            'total' => array_sum($counts),
        ];
    }
}
