<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1685 / MICRO-02 — le catalogue et l'agenda d'une autre Organization
 * refusent en EXPLIQUANT, au lieu du 404 generique.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * Recette : un membre de `main` authentifie sur `/org/ofsh/agenda` recevait le
 * 404 generique de la plateforme, dont le message — « Hmm, cette boucle semble
 * partie en vacances… » — parle d'une Boucle. Ni Boucle, ni page cassee : un
 * refus de tenant, qu'il lisait comme une panne.
 *
 * Le refus ne venait d'aucun middleware : `ResolveOrganization` resout le slug
 * sans regarder l'appartenance, et `CheckLoopsEnabled` ne declenchait pas
 * (`ofsh.loops_enabled = true`, verifie en base). Il venait du controleur —
 * `LoopEventAgendaController::index()` et
 * `LoopController::assertUserCanInspectOrganization()`, poses par T1658.
 *
 * Matrice relevee sur un membre ordinaire, surfaces d'une AUTRE Organization :
 *
 * | Surface | publique | privee | inexistante |
 * |---|---|---|---|
 * | `/dashboard` | 403 explique | 403 neutre | 404 |
 * | `/agenda`, `/loops` | **404 generique** | **404 generique** | 404 |
 * | `/membres`, `/explorer` | 404 generique | 404 generique | — |
 *
 * ## Ce qui n'a PAS ete ecrit
 *
 * Aucune regle d'appartenance. TASK-1483 avait deja construit la page et son
 * mode `organization.member:explain` ; MICRO-02 ne fait que l'appliquer a deux
 * routes de plus. `canAccessOrganization()`, les policies et les gardes des
 * controleurs sont INTOUCHEES — elles restent la ceinture, et ce test l'affirme.
 *
 * Les 404 de TASK-1479 sur l'annuaire sont DELIBEREES (« un 403 y confirmerait
 * a un tiers que cette Organization existe ») : elles ne sont pas converties,
 * et ce test l'affirme aussi.
 */
class TASK1685CrossTenantRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $mine;

    private Organization $publicOther;

    private Organization $privateOther;

    private User $member;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Organization::factory()->create([
            'slug' => 't1685-mine',
            'name' => 'Mon Organization',
            'loops_enabled' => true,
            'is_public' => true,
            'is_active' => true,
        ]);

        $this->publicOther = Organization::factory()->create([
            'slug' => 't1685-public-other',
            'name' => 'Organization Publique Etrangere',
            'loops_enabled' => true,
            'is_public' => true,
            'is_active' => true,
        ]);

        $this->privateOther = Organization::factory()->create([
            'slug' => 't1685-private-other',
            'name' => 'Organization Privee Etrangere',
            'loops_enabled' => true,
            'is_public' => false,
            'is_active' => true,
        ]);

        $this->member = User::factory()->create([
            'organization_id' => $this->mine->id,
            'is_admin' => false,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->mine->id,
            'is_admin' => true,
        ]);
    }

    /** @return list<string> */
    private function targetedPaths(Organization $organization): array
    {
        return [
            "/org/{$organization->slug}/agenda",
            "/org/{$organization->slug}/loops",
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le defaut constate : un refus qui s'explique
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_public_organization_without_membership_is_refused_with_an_explanation(): void
    {
        foreach ($this->targetedPaths($this->publicOther) as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertStatus(403);
            $response->assertSee('data-org-member-required', false);
            $response->assertSee(__('errors.org_member_required_heading', [
                'organization' => $this->publicOther->name,
            ]), false);
        }
    }

    public function test_the_refusal_is_never_the_generic_404_anymore(): void
    {
        foreach ($this->targetedPaths($this->publicOther) as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertDontSee(__('errors.404_message'), false);
            $response->assertDontSee(__('errors.404_title'), false);
        }
    }

    public function test_the_refusal_offers_a_way_back_to_the_members_own_space(): void
    {
        foreach ($this->targetedPaths($this->publicOther) as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertSee(__('errors.org_member_required_own_space'), false);
            $response->assertSee(
                route('organization.dashboard', ['organization' => $this->mine->slug]),
                false
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Aucune divulgation
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_private_organization_is_never_named_to_a_stranger(): void
    {
        foreach ($this->targetedPaths($this->privateOther) as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertStatus(403);
            $response->assertSee(__('errors.org_member_required_heading_neutral'), false);
            $response->assertDontSee($this->privateOther->name, false);
        }
    }

    /**
     * Le refus ne doit porter NI le theme, NI le logo, NI le
     * `header_javascript` du tenant refuse — c'est la raison pour laquelle la
     * page de T1483 n'emprunte aucun layout. On le reverifie sur CES routes :
     * un middleware pose au bon endroit ne suffit pas a le garantir, encore
     * faut-il que ce soit bien sa page qui sorte.
     */
    public function test_the_refusal_carries_nothing_of_the_targeted_tenant(): void
    {
        $this->publicOther->update(['header_javascript' => '<script>window.__LEAK__=1;</script>']);

        foreach ($this->targetedPaths($this->publicOther) as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertDontSee('__LEAK__', false);
            $response->assertSee('noindex, nofollow', false);
        }
    }

    public function test_no_private_resource_of_the_targeted_tenant_appears_in_the_refusal(): void
    {
        $stranger = User::factory()->create([
            'organization_id' => $this->publicOther->id,
            'name' => 'Membre Etranger Confidentiel',
        ]);

        foreach ($this->targetedPaths($this->publicOther) as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertDontSee($stranger->name, false);
            $response->assertDontSee((string) $stranger->id, false);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Les trois cas restent distincts
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_nonexistent_organization_still_returns_404(): void
    {
        foreach (['/org/t1685-nope/agenda', '/org/t1685-nope/loops'] as $path) {
            $this->actingAs($this->member)->get($path)->assertStatus(404);
        }
    }

    public function test_a_member_of_the_organization_still_reaches_both_surfaces(): void
    {
        foreach ($this->targetedPaths($this->mine) as $path) {
            $this->actingAs($this->member)->get($path)->assertSuccessful();
        }
    }

    public function test_the_super_admin_keeps_the_already_authorized_cross_tenant_access(): void
    {
        foreach ($this->targetedPaths($this->publicOther) as $path) {
            $this->actingAs($this->superAdmin)->get($path)->assertSuccessful();
        }

        foreach ($this->targetedPaths($this->privateOther) as $path) {
            $this->actingAs($this->superAdmin)->get($path)->assertSuccessful();
        }
    }

    public function test_an_anonymous_visitor_is_still_sent_to_authentication(): void
    {
        foreach ($this->targetedPaths($this->publicOther) as $path) {
            $this->get($path)->assertRedirect();
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Rien d'autre n'a bouge
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Les 404 de TASK-1479 sur l'annuaire sont DELIBEREES. Les convertir
     * confirmerait a un tiers l'existence de l'Organization sur la surface
     * meme que ce correctif de fuite avait fermee.
     */
    public function test_the_directory_keeps_its_deliberate_generic_404(): void
    {
        foreach ([
            "/org/{$this->publicOther->slug}/membres",
            "/org/{$this->publicOther->slug}/explorer",
            "/org/{$this->publicOther->slug}/echanges",
        ] as $path) {
            $this->actingAs($this->member)->get($path)->assertStatus(404);
        }
    }

    /** Les formes NON prefixees ne sont pas touchees. */
    public function test_the_unprefixed_forms_are_untouched(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['events.agenda', 'loops.index'] as $name) {
            $middleware = $routes->getByName($name)->gatherMiddleware();

            $this->assertNotContains(
                'organization.member:explain',
                $middleware,
                "La route non prefixee [{$name}] ne doit pas porter le mode explain."
            );
        }
    }

    /** Le mode explain ne doit etre pose que la ou MASTER l'a arbitre. */
    public function test_the_explain_mode_is_applied_to_exactly_the_arbitrated_routes(): void
    {
        $withExplain = [];

        foreach (app('router')->getRoutes() as $route) {
            if (in_array('organization.member:explain', $route->gatherMiddleware(), true)) {
                $withExplain[] = $route->getName();
            }
        }

        sort($withExplain);

        $this->assertSame([
            // TASK-1483 — la famille du tableau de bord.
            'organization.dashboard',
            'organization.dashboard.requests',
            'organization.dashboard.requests.detail',
            'organization.dashboard.services',
            'organization.dashboard.services.detail',
            // TASK-1685 / MICRO-02 — les deux surfaces arbitrees.
            'organization.events.agenda',
            'organization.loops.index',
        ], $withExplain);
    }

    /**
     * La ceinture reste en place : le controleur garde SA garde. Un middleware
     * retire par erreur ne doit pas ouvrir la surface.
     */
    public function test_the_controllers_keep_their_own_membership_guard(): void
    {
        $this->assertStringContainsString(
            'canAccessOrganization($organization), 404)',
            file_get_contents(app_path('Http/Controllers/LoopEventAgendaController.php')),
            'LoopEventAgendaController doit conserver sa garde de tenant.'
        );

        $this->assertStringContainsString(
            'canAccessOrganization($organization), 404)',
            file_get_contents(app_path('Http/Controllers/LoopController.php')),
            'LoopController doit conserver sa garde de tenant.'
        );
    }

    /**
     * Et le predicat d'appartenance lui-meme n'a pas bouge : aucune permission
     * n'est elargie par MICRO-02.
     */
    public function test_the_membership_predicate_is_unchanged(): void
    {
        $this->assertTrue($this->member->canAccessOrganization($this->mine));
        $this->assertFalse($this->member->canAccessOrganization($this->publicOther));
        $this->assertFalse($this->member->canAccessOrganization($this->privateOther));
        $this->assertTrue($this->superAdmin->canAccessOrganization($this->publicOther));
    }
}
