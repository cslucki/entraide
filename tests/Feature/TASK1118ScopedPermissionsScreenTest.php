<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La matrice des permissions passe de « Plateforme » a
 * « Plateforme -> Organization », **sans changer qui administre**.
 *
 * Un ecran administre *par* une Organization avait existe et avait ete retire en
 * revue. Cette decision tient : ce qui revient ici est le **SuperAdmin** reglant
 * une Organization depuis la Plateforme. La question « qui administre » recoit
 * la meme reponse qu'a l'epoque.
 *
 * **Rien de la couche d'override n'a ete reecrit** : `organizationOverride()`,
 * `setOrganization()` et `clearOrganization()` existaient deja et sont testes
 * ailleurs. Ces tests portent sur l'ecran, sur la portee qu'il ecrit, et sur ce
 * qu'il ne doit jamais deborder.
 */
class TASK1118ScopedPermissionsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $simple;

    private Organization $orgA;

    private Organization $orgB;

    /** Une permission non verrouillee, choisie dans la configuration reelle. */
    private string $permission;

    private string $role = 'facilitator';

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->simple = User::factory()->create(['is_admin' => false, 'organization_id' => $this->orgA->id]);

        $reglages = app(LoopPermissionSettingsService::class);

        $this->permission = collect(array_keys($reglages->permissions()))
            ->first(fn (string $cle) => ! $reglages->isLocked($cle) && $reglages->isWritable('general', $this->role, $cle))
            ?? 'loop.rename';
    }

    private function reglages(): LoopPermissionSettingsService
    {
        return app(LoopPermissionSettingsService::class);
    }

    /**
     * Les deux Organizations, relues depuis la base.
     *
     * Le controleur charge **sa propre** instance et ecrit dessus : celle que
     * le test garde en memoire depuis `setUp()` porte encore l'ancien arbre.
     * Lire l'instance perimee ferait croire qu'aucune ecriture n'a eu lieu.
     */
    private function relu(Organization $organization): Organization
    {
        return $organization->fresh();
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    /** Poste une cellule dans une portee donnee. */
    private function poste(?Organization $scope, string $etat): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'scope' => $scope?->id ?? 'platform',
            'cells' => [$this->permission => [$this->role => $etat]],
        ]);
    }

    // ── Acces ───────────────────────────────────────────────────────────────

    public function test_only_the_super_admin_reaches_the_matrix(): void
    {
        $this->actingAs($this->simple)->get(route('admin.loop-permissions'))->assertForbidden();
        $this->actingAs($this->superAdmin)->get(route('admin.loop-permissions'))->assertOk();
    }

    public function test_a_simple_member_cannot_write_a_permission(): void
    {
        $this->actingAs($this->simple)
            ->put(route('admin.loop-permissions.update'), [
                'type' => 'general',
                'scope' => $this->orgA->id,
                'cells' => [$this->permission => [$this->role => 'allowed']],
            ])
            ->assertForbidden();

        $this->assertNull($this->reglages()->organizationOverride($this->relu($this->orgA), 'general', $this->role, $this->permission));
    }

    public function test_a_forged_scope_is_a_404_and_writes_nothing(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-permissions.update'), [
                'type' => 'general',
                'scope' => '00000000-0000-0000-0000-000000000000',
                'cells' => [$this->permission => [$this->role => 'allowed']],
            ])
            ->assertNotFound();
    }

    public function test_a_scope_that_is_not_an_identifier_is_refused(): void
    {
        // Colonne `uuid` : PostgreSQL leve `22P02` sur une chaine qui n'en est
        // pas une. Sans garde de forme, c'est une 500 au lieu d'une 404 — et
        // SQLite ne le montre pas.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => 'pas-un-identifiant']))
            ->assertNotFound();
    }

    // ── La portee decide de la couche ecrite ────────────────────────────────

    public function test_writing_in_an_organization_leaves_the_platform_alone(): void
    {
        $this->poste($this->orgA, 'allowed')->assertRedirect();

        $this->assertTrue($this->reglages()->organizationOverride($this->relu($this->orgA), 'general', $this->role, $this->permission));
        $this->assertNull($this->reglages()->globalOverride('general', $this->role, $this->permission));
    }

    public function test_writing_in_an_organization_is_invisible_to_its_neighbour(): void
    {
        $this->poste($this->orgA, 'denied')->assertRedirect();

        $this->assertNull($this->reglages()->organizationOverride($this->relu($this->orgB), 'general', $this->role, $this->permission));
        $this->assertSame(
            $this->resolver()->resolveForRole(null, 'general', $this->role, $this->permission),
            $this->resolver()->resolveForRole($this->relu($this->orgB), 'general', $this->role, $this->permission),
        );
    }

    public function test_writing_on_the_platform_still_writes_the_global_layer(): void
    {
        $this->poste(null, 'allowed')->assertRedirect();

        $this->assertTrue($this->reglages()->globalOverride('general', $this->role, $this->permission));
    }

    public function test_an_organization_override_wins_over_the_platform(): void
    {
        $this->poste(null, 'denied');
        $this->poste($this->orgA, 'allowed');

        $this->assertTrue($this->resolver()->resolveForRole($this->relu($this->orgA), 'general', $this->role, $this->permission));
        $this->assertFalse($this->resolver()->resolveForRole($this->relu($this->orgB), 'general', $this->role, $this->permission));
    }

    public function test_inherited_writes_nothing_at_all(): void
    {
        // « Herite » est l'absence de valeur. Le stocker ferait cesser de suivre
        // le niveau au-dessus sans que personne ne l'ait demande.
        $this->poste($this->orgA, 'inherited')->assertRedirect();

        $this->assertNull($this->reglages()->organizationOverride($this->relu($this->orgA), 'general', $this->role, $this->permission));
    }

    public function test_returning_to_inherited_removes_the_override(): void
    {
        $this->poste($this->orgA, 'allowed');
        $this->poste($this->orgA, 'inherited');

        $this->assertNull($this->reglages()->organizationOverride($this->relu($this->orgA), 'general', $this->role, $this->permission));
    }

    // ── Le reset ne deborde pas ─────────────────────────────────────────────

    public function test_resetting_an_organization_leaves_the_platform_and_the_neighbour_alone(): void
    {
        $this->poste(null, 'denied');
        $this->poste($this->orgA, 'allowed');
        $this->poste($this->orgB, 'allowed');

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loop-permissions.reset'), ['type' => 'general', 'scope' => $this->orgA->id])
            ->assertRedirect();

        $this->assertNull($this->reglages()->organizationOverride($this->relu($this->orgA), 'general', $this->role, $this->permission));
        $this->assertTrue($this->reglages()->organizationOverride($this->relu($this->orgB), 'general', $this->role, $this->permission));
        $this->assertFalse($this->reglages()->globalOverride('general', $this->role, $this->permission));
    }

    public function test_resetting_an_organization_hands_the_platform_back_the_decision(): void
    {
        $this->poste(null, 'denied');
        $this->poste($this->orgA, 'allowed');

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loop-permissions.reset'), ['type' => 'general', 'scope' => $this->orgA->id]);

        $this->assertFalse($this->resolver()->resolveForRole($this->relu($this->orgA), 'general', $this->role, $this->permission));
    }

    public function test_a_reset_never_touches_another_type(): void
    {
        $this->poste($this->orgA, 'allowed');

        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'project',
            'scope' => $this->orgA->id,
            'cells' => [$this->permission => [$this->role => 'allowed']],
        ]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loop-permissions.reset'), ['type' => 'general', 'scope' => $this->orgA->id]);

        $this->assertTrue($this->reglages()->organizationOverride($this->relu($this->orgA), 'project', $this->role, $this->permission));
    }

    public function test_a_locked_permission_is_never_written_by_a_reset(): void
    {
        $verrouillee = collect(array_keys($this->reglages()->permissions()))
            ->first(fn (string $cle) => $this->reglages()->isLocked($cle));

        if ($verrouillee === null) {
            $this->markTestSkipped('aucune permission verrouillee dans la configuration');
        }

        $avant = $this->resolver()->resolveForRole($this->relu($this->orgA), 'general', $this->role, $verrouillee);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loop-permissions.reset'), ['type' => 'general', 'scope' => $this->orgA->id]);

        $this->assertSame($avant, $this->resolver()->resolveForRole($this->relu($this->orgA), 'general', $this->role, $verrouillee));
    }

    // ── Ce que l'ecran montre ───────────────────────────────────────────────

    public function test_the_screen_shows_the_three_states_of_the_displayed_scope(): void
    {
        $this->poste($this->orgA, 'allowed');

        $modules = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgA->id, 'type' => 'general']))
            ->assertOk()
            ->viewData('modules');

        $cellule = collect($modules)->flatMap(fn ($m) => $m)->firstWhere('key', $this->permission);

        $this->assertSame('allowed', $cellule['cells'][$this->role]['state']);
        $this->assertSame('organization', $cellule['cells'][$this->role]['source']);
    }

    public function test_the_screen_says_when_a_cell_is_inherited_and_from_where(): void
    {
        $this->poste(null, 'denied');

        $modules = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgA->id, 'type' => 'general']))
            ->viewData('modules');

        $cellule = collect($modules)->flatMap(fn ($m) => $m)->firstWhere('key', $this->permission);

        $this->assertSame('inherited', $cellule['cells'][$this->role]['state']);
        $this->assertSame('global', $cellule['cells'][$this->role]['source'], 'l’ecran n’indique pas d’ou vient l’heritage');
        $this->assertFalse($cellule['cells'][$this->role]['inherited']);
    }

    public function test_the_loop_count_shown_is_the_one_of_the_scope(): void
    {
        $auteurB = User::factory()->create(['organization_id' => $this->orgB->id]);
        app()->instance('current_organization', $this->orgB);
        (new \App\Services\LoopService)->createLoop($auteurB, 'Chez B');

        $chezA = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgA->id, 'type' => 'general']))
            ->viewData('affectedLoops');

        $chezB = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgB->id, 'type' => 'general']))
            ->viewData('affectedLoops');

        $this->assertSame(0, $chezA);
        $this->assertSame(1, $chezB);
    }

    // ── Les types crees sont administrables ici aussi ───────────────────────

    public function test_a_type_created_for_an_organization_is_offered_in_its_scope(): void
    {
        // Le critere d'arret du mandat : un type cree doit etre immediatement
        // disponible dans l'ecran des permissions.
        $type = app(\App\Services\Loops\LoopTypeCreationService::class)
            ->create($this->orgA, 'Parcours', null, 'training');

        $chezA = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgA->id]))
            ->viewData('types');

        $chezB = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgB->id]))
            ->viewData('types');

        $this->assertArrayHasKey($type->key, $chezA);
        $this->assertArrayNotHasKey($type->key, $chezB);
    }

    public function test_a_type_of_another_organization_cannot_be_configured_here(): void
    {
        // `exists()` connait tous les types crees, portee comprise : sans garde,
        // le SuperAdmin reglerait depuis orgB un type qui n'appartient qu'a orgA.
        $type = app(\App\Services\Loops\LoopTypeCreationService::class)
            ->create($this->orgA, 'Parcours', null, 'training');

        $vu = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgB->id, 'type' => $type->key]))
            ->assertOk()
            ->viewData('type');

        $this->assertNotSame($type->key, $vu, 'un type d’une autre Organization a ete accepte');
    }

    public function test_the_screen_renders_with_a_created_type(): void
    {
        // Un type cree n'a pas de cle de traduction : le selecteur lisait
        // `label_key` en direct et cassait des qu'un tel type existait.
        $type = app(\App\Services\Loops\LoopTypeCreationService::class)
            ->create($this->orgA, 'Parcours', null, 'training');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['scope' => $this->orgA->id, 'type' => $type->key]))
            ->assertOk()
            ->assertSee('Parcours');
    }
}
