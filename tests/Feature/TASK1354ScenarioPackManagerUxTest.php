<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishPack;
use App\Support\ScenarioPacks\Packs\ArtSciLabDemoPack;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingPack;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1354 — l'ecran d'administration dit la verite sur ce qu'il va faire.
 *
 * ## Ce qui n'allait pas
 *
 * Deux menus deroulants independants, « Pack » et « Organization », laissaient
 * croire qu'un pack pouvait etre charge dans n'importe quelle Organization
 * qualifiee. C'etait faux : les trois packs sont hard-bound et `apply()` refuse
 * toute autre cible. L'interface proposait donc des actions que le moteur
 * rejette — par exemple `artscilab-demo-test` dans `artscilab-en`.
 *
 * ## Ce que cette suite verrouille
 *
 * La cible vient du PACK, jamais d'un choix : elle est lue sur la definition
 * elle-meme, pas dans une table ni un mapping d'interface. Aucune combinaison
 * arbitraire ne peut plus etre ni proposee, ni soumise. Et l'avertissement de
 * retrait dit ce qui va REELLEMENT disparaitre — y compris l'Organization,
 * quand c'est le chargement qui l'a creee.
 */
class TASK1354ScenarioPackManagerUxTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
    }

    private function screen(string $packId)
    {
        return $this->actingAs($this->superAdmin)->get(route('admin.scenario-packs', ['pack' => $packId]));
    }

    // =====================================================================
    // A. Chaque pack nomme sa cible, et c'est la sienne
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function packTargets(): array
    {
        return [
            'dogfooding FR' => [Test20260822DogfoodingPack::PACK_ID, 'test20260822'],
            'demo ArtSciLab legacy' => ['artscilab-demo-test', 'artscilab-demo'],
            'dogfooding EN' => [ArtSciLabEnglishPack::PACK_ID, 'artscilab-en'],
        ];
    }

    #[DataProvider('packTargets')]
    public function test_the_resolver_reads_the_target_from_the_pack_itself(string $packId, string $expectedSlug): void
    {
        $pack = app(ScenarioPackCatalog::class)->get($packId);

        $this->assertSame($expectedSlug, app(ScenarioPackTarget::class)->slugFor($pack));
    }

    #[DataProvider('packTargets')]
    public function test_the_screen_shows_that_target_and_offers_no_organization_choice(string $packId, string $expectedSlug): void
    {
        $response = $this->screen($packId);

        $response->assertOk();
        $response->assertSee('data-scenario-pack-target-slug="'.$expectedSlug.'"', false);
        // Le second menu deroulant a disparu : plus aucune combinaison libre.
        $response->assertDontSee('name="organization"', false);
    }

    public function test_a_pack_never_offers_another_packs_organization(): void
    {
        // La cible du pack EN existe...
        Organization::factory()->create(['slug' => 'artscilab-en', 'name' => 'ArtSciLab EN']);

        // ...mais l'ecran du pack legacy ne doit jamais la proposer.
        $response = $this->screen('artscilab-demo-test');

        $response->assertOk();
        $response->assertSee('data-scenario-pack-target-slug="artscilab-demo"', false);
        $response->assertDontSee('data-scenario-pack-target-slug="artscilab-en"', false);
    }

    // =====================================================================
    // B. Les trois etats de la cible
    // =====================================================================

    public function test_a_missing_target_on_a_legacy_pack_asks_for_it_to_exist_first(): void
    {
        $response = $this->screen('artscilab-demo-test');

        $response->assertOk();
        $response->assertSee('data-scenario-pack-state="missing"', false);
        $response->assertSee('data-scenario-pack-missing-hint="legacy"', false);
        $response->assertSee(__('admin.scenario_packs_missing_legacy'));
        $response->assertDontSee('data-scenario-pack-action="load"', false);
    }

    public function test_a_missing_target_on_a_provisioning_pack_says_it_can_be_created(): void
    {
        $response = $this->screen(ArtSciLabEnglishPack::PACK_ID);

        $response->assertOk();
        $response->assertSee('data-scenario-pack-state="missing"', false);
        $response->assertSee('data-scenario-pack-missing-hint="provisionable"', false);
        $response->assertSee(__('admin.scenario_packs_missing_provisionable'));
    }

    public function test_an_existing_but_unloaded_target_reads_ready(): void
    {
        Organization::factory()->create(['slug' => 'artscilab-en', 'name' => 'ArtSciLab EN']);

        $response = $this->screen(ArtSciLabEnglishPack::PACK_ID);

        $response->assertOk();
        $response->assertSee('data-scenario-pack-state="ready"', false);
        $response->assertSee('data-scenario-pack-action="load"', false);
        $response->assertSee('data-scenario-pack-action="open-organization"', false);
    }

    public function test_a_loaded_target_reads_loaded_and_shows_its_counts(): void
    {
        $organization = Organization::factory()->create(['slug' => 'artscilab-en', 'name' => 'ArtSciLab EN']);
        ScenarioPackLoad::create([
            'pack_id' => ArtSciLabEnglishPack::PACK_ID,
            'pack_version' => '1.0.0',
            'organization_id' => $organization->id,
            'loaded_at' => now(),
        ]);

        $response = $this->screen(ArtSciLabEnglishPack::PACK_ID);

        $response->assertOk();
        $response->assertSee('data-scenario-pack-state="loaded"', false);
        $response->assertSee('data-scenario-pack-status="loaded"', false);
    }

    // =====================================================================
    // C. L'avertissement de retrait dit ce qui disparait vraiment
    // =====================================================================

    public function test_the_removal_warning_says_the_organization_is_kept_when_the_pack_did_not_create_it(): void
    {
        $organization = Organization::factory()->create(['slug' => 'artscilab-en', 'name' => 'ArtSciLab EN']);
        ScenarioPackLoad::create([
            'pack_id' => ArtSciLabEnglishPack::PACK_ID,
            'pack_version' => '1.0.0',
            'organization_id' => $organization->id,
            'loaded_at' => now(),
            'organization_created_by_pack' => false,
        ]);

        $response = $this->screen(ArtSciLabEnglishPack::PACK_ID);

        $response->assertSee('data-scenario-pack-delete-scope="organization-kept"', false);
    }

    public function test_the_removal_warning_announces_the_organization_deletion_when_the_pack_created_it(): void
    {
        $organization = Organization::factory()->create(['slug' => 'artscilab-en', 'name' => 'ArtSciLab EN']);
        ScenarioPackLoad::create([
            'pack_id' => ArtSciLabEnglishPack::PACK_ID,
            'pack_version' => '1.0.0',
            'organization_id' => $organization->id,
            'loaded_at' => now(),
            'organization_created_by_pack' => true,
        ]);

        $response = $this->screen(ArtSciLabEnglishPack::PACK_ID);

        // Le follow-up de la revue T1351 : l'ancien texte affirmait que « seules
        // les entites creees par ce pack » disparaissaient, alors que
        // l'Organization elle-meme part avec — et avec elle tout ce qu'un humain
        // y a ajoute depuis le chargement.
        $response->assertSee('data-scenario-pack-delete-scope="organization-removed"', false);
    }

    // =====================================================================
    // D. Terminologie
    // =====================================================================

    public function test_the_french_screen_never_says_organization_in_english(): void
    {
        Organization::factory()->create(['slug' => 'artscilab-en', 'name' => 'ArtSciLab EN']);

        // La locale d'une requete vient de `preferred_locale` de l'utilisateur
        // (middleware SetLocale), jamais d'un `setLocale()` pose dans le test.
        $this->superAdmin->forceFill(['preferred_locale' => 'fr'])->saveQuietly();
        $response = $this->screen(ArtSciLabEnglishPack::PACK_ID);

        $response->assertOk();
        $response->assertSee('ORGANISATION CIBLE');
        $response->assertSee(__('admin.scenario_packs_open_organization'));

        // Le mot anglais est le nom du MODELE, pas celui que lit un
        // administrateur francophone. On le verifie sur la copie que cette
        // surface possede reellement — ses cles `scenario_packs_*` — et non sur
        // toute la page : la navigation admin partagee dit encore
        // « Organizations » ailleurs, et sa correction globale est hors scope.
        $fr = require base_path('lang/fr/admin.php');

        foreach ($fr as $key => $value) {
            if (! str_starts_with($key, 'scenario_packs_')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'Organization',
                (string) $value,
                "La cle française {$key} dit « Organization » au lieu d'« organisation »."
            );
        }
    }

    public function test_the_english_screen_has_the_same_keys(): void
    {
        Organization::factory()->create(['slug' => 'artscilab-en', 'name' => 'ArtSciLab EN']);

        $this->superAdmin->forceFill(['preferred_locale' => 'en'])->saveQuietly();
        $response = $this->screen(ArtSciLabEnglishPack::PACK_ID);

        $response->assertOk();
        $response->assertSee('TARGET ORGANISATION');
        $response->assertSee('Load the scenario');
    }

    public function test_every_new_key_exists_in_both_languages(): void
    {
        $fr = require base_path('lang/fr/admin.php');
        $en = require base_path('lang/en/admin.php');

        $keys = array_filter(array_keys($fr), static fn (string $k): bool => str_starts_with($k, 'scenario_packs_'));

        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "La cle {$key} manque en anglais.");
            $this->assertNotSame('', trim((string) $en[$key]));
        }
    }

    // =====================================================================
    // E. Le pack legacy garde sa cible historique
    // =====================================================================

    public function test_the_legacy_pack_target_is_the_seeder_slug_not_a_duplicated_string(): void
    {
        $this->assertSame(
            \Database\Seeders\ArtSciLabScenarioSeeder::SLUG,
            ArtSciLabDemoPack::ORGANIZATION_SLUG,
            'La cible du pack legacy doit rester la MEME constante que celle que sa garde verifie.'
        );
    }
}
