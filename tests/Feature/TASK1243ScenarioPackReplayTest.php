<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ScenarioPacks\Packs\ArtSciLabRogerPack;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Database\Seeders\ArtSciLabScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1243 — rejeu multi-cycles (load -> reset -> reset -> ...) du pack
 * ArtSciLab/Roger, contrat T1239 S10 (idempotence) + S11 (reset) + S13
 * (preuves : aucun orphelin/duplicata, coherence des compteurs
 * economiques) rejoue plusieurs fois de suite plutot qu'une seule.
 *
 * Perimetre strict : uniquement ScenarioPackLoader/ScenarioPackResetter.
 * ScenarioPackRemover n'est jamais appele ici — sa semantique de
 * suppression (limite connue sur les modeles SoftDeletes, cf. Review Notes
 * TASK-1242) est en attente d'une decision produit de Cyril et reste hors
 * scope de cette suite.
 */
class TASK1243ScenarioPackReplayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        $this->organization = Organization::factory()->create(['slug' => ArtSciLabScenarioSeeder::SLUG]);

        config(['scenario_packs.allowed_organizations' => [ArtSciLabScenarioSeeder::SLUG]]);
        config(['scenario_packs.definitions' => ['artscilab-roger-demo' => ArtSciLabRogerPack::class]]);
    }

    public function test_repeated_resets_never_drift_the_entity_registry_or_row_counts(): void
    {
        $first = app(ScenarioPackLoader::class)->load(new ArtSciLabRogerPack, $this->organization);

        $baselineCounts = $first->entityCountsByType;
        $baselineRegistry = $this->registryMap($first->load->id);
        $baselineTableCounts = $this->tableCounts();

        for ($cycle = 1; $cycle <= 4; $cycle++) {
            $result = app(ScenarioPackResetter::class)->reset(new ArtSciLabRogerPack, $this->organization);

            $this->assertFalse($result->wasFirstLoad, "cycle {$cycle}: reset reported as a first load");
            $this->assertSame($baselineCounts, $result->entityCountsByType, "cycle {$cycle}: entity counts by type drifted");
            $this->assertSame([], $result->removedOrphans, "cycle {$cycle}: spurious orphan on an unchanged pack version");
            $this->assertSame(1, ScenarioPackLoad::query()->count(), "cycle {$cycle}: more than one load row for the same pack/organization");
            $this->assertSame($baselineRegistry, $this->registryMap($result->load->id), "cycle {$cycle}: registry re-created entities instead of reusing them");
            $this->assertSame($baselineTableCounts, $this->tableCounts(), "cycle {$cycle}: row counts drifted across a replay");
        }
    }

    public function test_repeated_resets_never_drift_points_balances_or_the_point_ledger(): void
    {
        app(ScenarioPackLoader::class)->load(new ArtSciLabRogerPack, $this->organization);

        $baselineBalances = User::query()
            ->where('organization_id', $this->organization->id)
            ->orderBy('id')
            ->pluck('points_balance', 'id')
            ->all();
        $baselineLedger = PointLedger::query()
            ->where('organization_id', $this->organization->id)
            ->orderBy('id')
            ->get(['user_id', 'transaction_id', 'delta', 'reason'])
            ->toArray();
        $baselineTransactionCount = Transaction::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->count();

        for ($cycle = 1; $cycle <= 3; $cycle++) {
            app(ScenarioPackResetter::class)->reset(new ArtSciLabRogerPack, $this->organization);

            $this->assertSame(
                $baselineBalances,
                User::query()->where('organization_id', $this->organization->id)->orderBy('id')->pluck('points_balance', 'id')->all(),
                "cycle {$cycle}: points_balance drifted"
            );
            $this->assertSame(
                $baselineLedger,
                PointLedger::query()->where('organization_id', $this->organization->id)->orderBy('id')->get(['user_id', 'transaction_id', 'delta', 'reason'])->toArray(),
                "cycle {$cycle}: point ledger entries drifted"
            );
            $this->assertSame(
                $baselineTransactionCount,
                Transaction::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count(),
                "cycle {$cycle}: transaction count drifted"
            );
        }
    }

    public function test_alternating_load_and_reset_calls_stay_idempotent_across_many_cycles(): void
    {
        $loader = app(ScenarioPackLoader::class);
        $resetter = app(ScenarioPackResetter::class);

        $first = $loader->load(new ArtSciLabRogerPack, $this->organization);
        $baselineRegistry = $this->registryMap($first->load->id);

        // Un admin qui rejoue la demo hesite entre "recharger" et
        // "reinitialiser" : le contrat S10/S11 garantit que les deux restent
        // sans effet au-dela du premier chargement, dans n'importe quel
        // ordre et enchaines plusieurs fois.
        $sequence = [
            fn () => $loader->load(new ArtSciLabRogerPack, $this->organization),
            fn () => $resetter->reset(new ArtSciLabRogerPack, $this->organization),
            fn () => $loader->load(new ArtSciLabRogerPack, $this->organization),
            fn () => $resetter->reset(new ArtSciLabRogerPack, $this->organization),
            fn () => $resetter->reset(new ArtSciLabRogerPack, $this->organization),
        ];

        foreach ($sequence as $step => $call) {
            $result = $call();

            $this->assertFalse($result->wasFirstLoad, "step {$step}: unexpectedly reported as a first load");
            $this->assertSame(1, ScenarioPackLoad::query()->count(), "step {$step}: more than one load row");
            $this->assertSame($baselineRegistry, $this->registryMap($result->load->id), "step {$step}: registry drifted");
        }
    }

    /** @return array<string, string> "type|internal_key" -> "model#id", preuve d'identite stable des entites a travers les rejeux */
    private function registryMap(string $loadId): array
    {
        return ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $loadId)
            ->orderBy('entity_type')
            ->orderBy('internal_key')
            ->get(['entity_type', 'internal_key', 'entity_model', 'entity_id'])
            ->mapWithKeys(fn (ScenarioPackEntity $e) => ["{$e->entity_type}|{$e->internal_key}" => "{$e->entity_model}#{$e->entity_id}"])
            ->all();
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $organizationId = $this->organization->id;

        return [
            'scenario_pack_entities' => ScenarioPackEntity::query()->count(),
            'users' => User::query()->where('organization_id', $organizationId)->count(),
            'loops' => Loop::query()->where('organization_id', $organizationId)->count(),
            'loop_messages' => LoopMessage::query()->where('organization_id', $organizationId)->count(),
            'transactions' => Transaction::withoutGlobalScopes()->where('organization_id', $organizationId)->count(),
            'point_ledger' => PointLedger::query()->where('organization_id', $organizationId)->count(),
        ];
    }
}
