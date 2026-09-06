<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioPacks\FixtureScenarioPack;
use Tests\TestCase;

/**
 * TASK-1241 — UI Admin de gestion des scenario packs (SuperAdmin plateforme,
 * moteur TASK-1240 reutilise tel quel).
 *
 * Preuves attendues (roadmap T1241 + doctrine) :
 *  A. PERMISSIONS — SuperAdmin uniquement ; membre et Admin d'Organization
 *     (non plateforme) refuses.
 *  B. SELECTION BORNEE — seules les Organizations allowlistees et les packs
 *     enregistres apparaissent ; jamais un picker ouvert.
 *  C. ETATS VIDES — aucun pack enregistre / aucune Organization qualifiee.
 *  D. ACTIONS — charger (idempotent), reset, supprimer, via HTTP, avec les
 *     memes garanties que le moteur (cross-tenant, borne).
 *  E. GARDE-FOU DE FORMULAIRE — un pack ou une Organization hors liste est
 *     refuse avant tout appel moteur.
 *  F. NAVIGATION — l'ecran est atteignable depuis le menu admin.
 */
class TASK1241ScenarioPackAdminUiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $allowedOrganization;

    private Organization $otherAllowedOrganization;

    private Organization $notAllowedOrganization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowedOrganization = Organization::factory()->create(['slug' => FixtureScenarioPack::ORGANIZATION_SLUG, 'name' => 'Allowed A']);
        $this->otherAllowedOrganization = Organization::factory()->create(['slug' => 'task1241-allowed-b', 'name' => 'Allowed B']);
        $this->notAllowedOrganization = Organization::factory()->create(['slug' => 'task1241-not-allowed', 'name' => 'Not Allowed']);

        config([
            'scenario_packs.allowed_organizations' => [
                $this->allowedOrganization->slug,
                $this->otherAllowedOrganization->slug,
            ],
            'scenario_packs.definitions' => [
                'task1240-fixture' => FixtureScenarioPack::class,
            ],
        ]);

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
    }

    // =====================================================================
    // A. Permissions
    // =====================================================================

    public function test_a_regular_member_cannot_reach_the_screen(): void
    {
        $member = User::factory()->create(['organization_id' => $this->allowedOrganization->id, 'is_admin' => false]);

        $this->actingAs($member)->get(route('admin.scenario-packs'))->assertForbidden();
    }

    public function test_an_organization_admin_who_is_not_a_platform_admin_cannot_reach_the_screen(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->allowedOrganization->id, 'is_admin' => false]);
        $this->allowedOrganization->update(['admin_id' => $orgAdmin->id]);

        $this->actingAs($orgAdmin)->get(route('admin.scenario-packs'))->assertForbidden();
        $this->actingAs($orgAdmin)->post(route('admin.scenario-packs.load'), [
            'pack' => 'task1240-fixture',
            'organization' => $this->allowedOrganization->slug,
        ])->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.scenario-packs'))->assertRedirect(route('login'));
    }

    public function test_a_platform_admin_can_reach_the_screen(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.scenario-packs'))->assertOk();
    }

    // =====================================================================
    // B. Selection bornee
    // =====================================================================

    public function test_the_target_organization_comes_from_the_pack_and_is_never_offered_as_a_choice(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.scenario-packs'));

        $response->assertOk();

        // TASK-1354 : la cible est AFFICHEE, jamais choisie. L'ancien second
        // menu deroulant laissait croire qu'un pack pouvait aller dans
        // n'importe quelle Organization qualifiee — combinaison que le moteur
        // refuse depuis toujours.
        $response->assertSee('data-scenario-pack-target-slug="'.FixtureScenarioPack::ORGANIZATION_SLUG.'"', false);
        $response->assertDontSee('name="organization"', false);

        // Et surtout : l'autre Organization allowlistee n'est pas proposee.
        $response->assertDontSee($this->otherAllowedOrganization->slug);
        $response->assertDontSee($this->notAllowedOrganization->slug);
    }

    public function test_only_registered_packs_appear(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.scenario-packs'));

        $response->assertOk();
        $response->assertSee('task1240-fixture');
    }

    // =====================================================================
    // C. Etats vides
    // =====================================================================

    public function test_it_states_clearly_when_no_pack_is_registered(): void
    {
        config(['scenario_packs.definitions' => []]);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.scenario-packs'));

        $response->assertOk();
        $response->assertSee('data-scenario-packs-empty', false);
        $response->assertDontSee('data-scenario-pack-preview', false);
    }

    public function test_it_states_clearly_when_the_target_organization_does_not_exist(): void
    {
        // L'Organization cible du pack n'existe pas encore.
        Organization::query()->where('slug', FixtureScenarioPack::ORGANIZATION_SLUG)->forceDelete();

        $response = $this->actingAs($this->superAdmin)->get(route('admin.scenario-packs'));

        $response->assertOk();
        $response->assertSee('data-scenario-pack-state="missing"', false);
        // Pack sans auto-provisionnement : l'ecran dit qu'il faut la creer avant.
        $response->assertSee('data-scenario-pack-missing-hint="legacy"', false);
        // Rien a charger, rien a retirer, rien a ouvrir tant qu'elle manque.
        $response->assertDontSee('data-scenario-pack-action="load"', false);
        $response->assertDontSee('data-scenario-pack-action="delete"', false);
        $response->assertDontSee('data-scenario-pack-action="open-organization"', false);
    }

    // =====================================================================
    // D. Actions
    // =====================================================================

    public function test_loading_through_the_ui_creates_entities_and_shows_the_status(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), [
            'pack' => 'task1240-fixture',
            'organization' => $this->allowedOrganization->slug,
        ])->assertRedirect(route('admin.scenario-packs', ['pack' => 'task1240-fixture']))
            ->assertSessionHas('success');

        $this->assertSame(1, ScenarioPackLoad::query()->where('organization_id', $this->allowedOrganization->id)->count());
        $this->assertSame(4, ScenarioPackEntity::query()->count());

        $page = $this->actingAs($this->superAdmin)->get(route('admin.scenario-packs', ['pack' => 'task1240-fixture', 'organization' => $this->allowedOrganization->slug]));
        $page->assertOk();
        $page->assertSee('data-scenario-pack-status="loaded"', false);
        $page->assertSee('data-scenario-pack-entity-total', false);
    }

    public function test_loading_twice_through_the_ui_is_idempotent(): void
    {
        $payload = ['pack' => 'task1240-fixture', 'organization' => $this->allowedOrganization->slug];

        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), $payload)->assertSessionHas('success');
        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), $payload)->assertSessionHas('success');

        $this->assertSame(1, ScenarioPackLoad::query()->count());
        $this->assertSame(4, ScenarioPackEntity::query()->count());
    }

    public function test_reset_without_a_prior_load_flashes_an_error_instead_of_crashing(): void
    {
        $response = $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.reset'), [
            'pack' => 'task1240-fixture',
            'organization' => $this->allowedOrganization->slug,
        ]);

        $response->assertRedirect(route('admin.scenario-packs', ['pack' => 'task1240-fixture']));
        $response->assertSessionHas('error');
        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }

    public function test_reset_through_the_ui_removes_orphans_from_a_previous_version(): void
    {
        $payload = ['pack' => 'task1240-fixture', 'organization' => $this->allowedOrganization->slug];
        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), $payload);
        $this->assertSame(4, ScenarioPackEntity::query()->count());

        // Simule une nouvelle version qui abandonne l'interaction.
        config(['scenario_packs.definitions' => [
            'task1240-fixture' => Task1241FixtureV2ScenarioPack::class,
        ]]);

        $response = $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.reset'), $payload);
        $response->assertSessionHas('success');

        $this->assertSame(3, ScenarioPackEntity::query()->count());
    }

    public function test_delete_through_the_ui_removes_only_this_packs_entities(): void
    {
        $payload = ['pack' => 'task1240-fixture', 'organization' => $this->allowedOrganization->slug];
        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), $payload);

        $outsider = Loop::factory()->create(['organization_id' => $this->allowedOrganization->id, 'name' => 'Preexistante']);

        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.delete'), $payload)->assertSessionHas('success');

        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
        $this->assertTrue(Loop::query()->whereKey($outsider->id)->exists());
    }

    public function test_delete_through_the_ui_without_a_prior_load_is_a_safe_noop(): void
    {
        $response = $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.delete'), [
            'pack' => 'task1240-fixture',
            'organization' => $this->allowedOrganization->slug,
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }

    public function test_a_forged_organization_field_cannot_redirect_an_action_elsewhere(): void
    {
        // Le champ n'existe plus dans le formulaire ; on le FORGE quand meme.
        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), [
            'pack' => 'task1240-fixture',
            'organization' => $this->otherAllowedOrganization->slug,
        ]);

        // L'action a atterri sur la cible DU PACK, pas sur celle demandee.
        $this->assertSame(1, ScenarioPackLoad::query()->where('organization_id', $this->allowedOrganization->id)->count());
        $this->assertSame(0, ScenarioPackLoad::query()->where('organization_id', $this->otherAllowedOrganization->id)->count());
    }

    // =====================================================================
    // E. Garde-fou de formulaire
    // =====================================================================

    public function test_a_forged_non_allowed_organization_never_receives_anything(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), [
            'pack' => 'task1240-fixture',
            'organization' => $this->notAllowedOrganization->slug,
        ]);

        // Rien n'a ete ecrit dans l'Organization non allowlistee : le controleur
        // ne lit plus ce champ du tout.
        $this->assertSame(
            0,
            ScenarioPackLoad::query()->where('organization_id', $this->notAllowedOrganization->id)->count(),
        );
    }

    public function test_a_form_submission_for_an_unregistered_pack_is_rejected_before_any_engine_call(): void
    {
        $response = $this->actingAs($this->superAdmin)->post(route('admin.scenario-packs.load'), [
            'pack' => 'never-registered',
            'organization' => $this->allowedOrganization->slug,
        ]);

        $response->assertSessionHasErrors('pack');
        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }

    // =====================================================================
    // F. Navigation
    // =====================================================================

    public function test_the_screen_is_reachable_from_the_admin_navigation(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee(route('admin.scenario-packs'), false);
    }
}

/**
 * Meme fixture que TASK1240ScenarioPackEngineTest, sans l'interaction : sert
 * uniquement a exercer le reset (orphelins v1->v2) depuis l'UI.
 */
class Task1241FixtureV2ScenarioPack extends FixtureScenarioPack
{
    public function __construct()
    {
        parent::__construct(version: '2.0.0', includeInteraction: false);
    }
}
