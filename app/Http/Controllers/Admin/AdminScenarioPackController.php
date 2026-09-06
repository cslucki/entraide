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
use App\Support\ScenarioPacks\ScenarioPackTarget;
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
    public function index(Request $request, ScenarioPackTarget $targets): View
    {
        $packIds = array_keys(config('scenario_packs.definitions', []));

        $selectedPackId = $request->query('pack');
        if (! in_array($selectedPackId, $packIds, true)) {
            $selectedPackId = $packIds[0] ?? null;
        }

        $pack = null;
        $target = null;

        if ($selectedPackId !== null) {
            $pack = app(ScenarioPackCatalog::class)->get($selectedPackId);
            $target = $this->targetOf($pack, $targets);
        }

        return view('admin.scenario-packs.index', [
            'packIds' => $packIds,
            'selectedPackId' => $selectedPackId,
            'pack' => $pack,
            'target' => $target,
        ]);
    }

    /**
     * Tout ce que l'ecran doit savoir de la cible d'un pack, en une lecture.
     *
     * L'Organization n'est plus un choix de l'utilisateur : elle est DEDUITE du
     * pack. Une combinaison incoherente ne peut donc plus etre proposee, ni
     * soumise.
     *
     * @return array{slug: ?string, organization: ?Organization, exists: bool, can_provision: bool, state: string, status: ?array<string, mixed>, created_by_pack: bool}
     */
    private function targetOf(ScenarioPackDefinition $pack, ScenarioPackTarget $targets): array
    {
        $slug = $targets->slugFor($pack);
        $organization = $targets->organizationFor($pack);
        $canProvision = $targets->provisionsItsOrganization($pack);

        $status = $organization !== null
            ? $this->currentStatus($pack->packId(), $organization)
            : null;

        // Trois etats, et un seul a la fois : l'organisation manque, elle est
        // la mais le scenario n'y est pas encore, ou le scenario est charge.
        $state = match (true) {
            $organization === null => 'missing',
            ($status['loaded'] ?? false) === true => 'loaded',
            default => 'ready',
        };

        return [
            'slug' => $slug,
            'organization' => $organization,
            'exists' => $organization !== null,
            'can_provision' => $canProvision,
            'state' => $state,
            'status' => $status,
            // Decide le texte d'avertissement du retrait : une Organization creee
            // par ce chargement disparaitra avec lui.
            'created_by_pack' => $status['created_by_pack'] ?? false,
        ];
    }

    public function load(Request $request, ScenarioPackLoader $loader): RedirectResponse
    {
        [$pack, $organization] = $this->resolveSelection($request);

        try {
            $result = $loader->load($pack, $organization);
        } catch (RuntimeException $e) {
            return $this->back($pack->packId())->with('error', $e->getMessage());
        }

        $label = $result->wasFirstLoad ? 'Premier chargement' : 'Rechargement (idempotent)';

        return $this->back($pack->packId())
            ->with('success', "{$label} : {$result->totalEntities()} entite(s) pour « {$pack->packName()} » dans « {$organization->name} ».");
    }

    public function reset(Request $request, ScenarioPackResetter $resetter): RedirectResponse
    {
        [$pack, $organization] = $this->resolveSelection($request);

        try {
            $result = $resetter->reset($pack, $organization);
        } catch (RuntimeException $e) {
            return $this->back($pack->packId())->with('error', $e->getMessage());
        }

        $orphans = count($result->removedOrphans);

        return $this->back($pack->packId())
            ->with('success', "Reset effectue pour « {$pack->packName()} » dans « {$organization->name} » : {$result->totalEntities()} entite(s) restante(s), {$orphans} orpheline(s) retiree(s).");
    }

    public function delete(Request $request, ScenarioPackRemover $remover): RedirectResponse
    {
        [$pack, $organization] = $this->resolveSelection($request);

        try {
            $remover->remove($pack->packId(), $organization);
        } catch (RuntimeException $e) {
            return $this->back($pack->packId())->with('error', $e->getMessage());
        }

        return $this->back($pack->packId())
            ->with('success', "« {$pack->packName()} » retire de « {$organization->name} » (ou n'etait deja pas charge).");
    }

    /**
     * @return array{0: ScenarioPackDefinition, 1: Organization}
     */
    private function resolveSelection(Request $request): array
    {
        $registeredPackIds = array_keys(config('scenario_packs.definitions', []));

        $data = $request->validate([
            'pack' => ['required', 'string', Rule::in($registeredPackIds)],
        ]);

        $pack = app(ScenarioPackCatalog::class)->get($data['pack']);

        // L'Organization n'est PLUS lue depuis la requete : elle est deduite du
        // pack. Un formulaire forge ne peut donc plus viser une autre cible,
        // meme allowlistee — la garde du moteur refusait deja, l'interface ne
        // le propose plus du tout.
        $organization = app(ScenarioPackTarget::class)->organizationFor($pack);

        if ($organization === null) {
            abort(404);
        }

        return [$pack, $organization];
    }

    private function back(string $packId): RedirectResponse
    {
        return redirect()->route('admin.scenario-packs', ['pack' => $packId]);
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
            return ['loaded' => false, 'pack_version' => null, 'loaded_at' => null, 'reset_at' => null, 'counts' => [], 'total' => 0, 'created_by_pack' => false];
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
            // TASK-1351 : ce chargement a-t-il cree l'Organization ? C'est ce
            // qui decide si le retrait l'emportera avec lui.
            'created_by_pack' => (bool) $load->organization_created_by_pack,
        ];
    }
}
