<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who can open a Dossier, across the three modalities.
 *
 * Before this, `visibility` looked like a setting and behaved like a computed
 * field: the controller forced `private` on every write, and syncVisibility()
 * then overwrote it to `shared` as soon as a member existed. Nobody could
 * choose anything.
 *
 * Two kinds of Dossier are deliberately kept apart. A **personal** one belongs
 * to a user and carries its own audience. A **root** one belongs to a Loop, has
 * no audience of its own, and takes its Loop's — evaluated on read, never
 * copied into a column.
 */
class TASK1082DossierVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function personalDossier(string $visibility = Dossier::VISIBILITY_PRIVATE, ?Loop $loop = null): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier '.uniqid(),
            'visibility' => $visibility,
            'shared_with_loop_id' => $loop?->id,
        ]);
    }

    private function loop(string $visibility = 'private'): Loop
    {
        $loop = app(LoopService::class)->createLoop($this->owner, 'Boucle '.uniqid(), null, $visibility);

        return $loop->fresh();
    }

    private function member(?Loop $loop = null): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        if ($loop) {
            LoopMember::factory()->create([
                'loop_id' => $loop->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active',
            ]);
        }

        return $user;
    }

    // ── Matrice d'accès ─────────────────────────────────────────────────────

    public function test_a_private_dossier_is_seen_by_its_owner_only(): void
    {
        $dossier = $this->personalDossier();

        $this->assertTrue($this->owner->can('view', $dossier));
        $this->assertFalse($this->member()->can('view', $dossier));
    }

    public function test_a_private_dossier_is_seen_by_the_people_explicitly_added(): void
    {
        $dossier = $this->personalDossier();
        $guest = $this->member();

        DossierMember::create([
            'organization_id' => $this->org->id, 'dossier_id' => $dossier->id,
            'user_id' => $guest->id, 'role' => DossierMember::ROLE_READER,
        ]);

        $this->assertTrue($guest->can('view', $dossier));
    }

    public function test_an_organization_dossier_is_seen_by_every_active_member(): void
    {
        $dossier = $this->personalDossier(Dossier::VISIBILITY_ORGANIZATION);

        $this->assertTrue($this->member()->can('view', $dossier));
    }

    public function test_an_organization_dossier_is_never_seen_from_another_organization(): void
    {
        $dossier = $this->personalDossier(Dossier::VISIBILITY_ORGANIZATION);
        $stranger = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->assertFalse($stranger->can('view', $dossier));
    }

    public function test_a_dossier_shared_in_a_private_loop_follows_that_loop(): void
    {
        $loop = $this->loop('private');
        $dossier = $this->personalDossier(Dossier::VISIBILITY_LOOP, $loop);

        $this->assertTrue($this->member($loop)->can('view', $dossier));
        $this->assertFalse($this->member()->can('view', $dossier), 'A non-member of the Loop must not get in.');
    }

    public function test_the_owner_of_a_shared_dossier_stays_its_owner(): void
    {
        $loop = $this->loop('private');
        $dossier = $this->personalDossier(Dossier::VISIBILITY_LOOP, $loop);

        // Sharing is not a transfer: the Dossier does not become the Loop's
        // root Dossier, and its owner keeps every right.
        $this->assertSame($this->owner->id, $dossier->owner_id);
        $this->assertNull($dossier->loop_id);
        $this->assertTrue($this->owner->can('update', $dossier));
    }

    // ── Dossier racine ──────────────────────────────────────────────────────

    public function test_a_root_dossier_has_no_audience_of_its_own(): void
    {
        $loop = $this->loop('private');
        $root = Dossier::where('loop_id', $loop->id)->firstOrFail();

        $this->assertTrue($root->visibilityIsInherited());
        $this->assertNull($root->owner_id);
        $this->assertSame(0, $root->dossierMembers()->count());
    }

    public function test_a_root_dossier_follows_its_loop_on_every_read(): void
    {
        $loop = $this->loop('private');
        $root = Dossier::where('loop_id', $loop->id)->firstOrFail();

        $inside = $this->member($loop);
        $outside = $this->member();

        $this->assertTrue($inside->can('view', $root));
        $this->assertFalse($outside->can('view', $root));
    }

    public function test_the_visibility_of_a_root_dossier_cannot_be_chosen(): void
    {
        $loop = $this->loop('private');
        $root = Dossier::where('loop_id', $loop->id)->firstOrFail();

        $this->assertFalse($this->owner->can('updateVisibility', $root));
    }

    public function test_the_server_refuses_to_change_a_root_dossiers_visibility(): void
    {
        $loop = $this->loop('private');
        $root = Dossier::where('loop_id', $loop->id)->firstOrFail();

        // Not merely a hidden field: the endpoint itself says no.
        $this->actingAs($this->owner)
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $root->id]), [
                'name' => $root->name,
                'visibility' => Dossier::VISIBILITY_ORGANIZATION,
            ])
            ->assertForbidden();

        $this->assertNull($root->fresh()->visibility === Dossier::VISIBILITY_ORGANIZATION ? true : null);
    }

    // ── Édition ─────────────────────────────────────────────────────────────

    public function test_the_owner_chooses_the_organization_modality(): void
    {
        $dossier = $this->personalDossier();

        $this->actingAs($this->owner)
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $dossier->id]), [
                'name' => 'Renomme',
                'visibility' => Dossier::VISIBILITY_ORGANIZATION,
            ])->assertRedirect();

        $this->assertSame(Dossier::VISIBILITY_ORGANIZATION, $dossier->fresh()->visibility);
        $this->assertSame('Renomme', $dossier->fresh()->name);
    }

    public function test_sharing_in_a_loop_requires_a_loop(): void
    {
        $dossier = $this->personalDossier();

        $this->actingAs($this->owner)
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $dossier->id]), [
                'name' => $dossier->name,
                'visibility' => Dossier::VISIBILITY_LOOP,
            ])->assertSessionHasErrors('shared_with_loop_id');

        $this->assertSame(Dossier::VISIBILITY_PRIVATE, $dossier->fresh()->visibility);
    }

    public function test_sharing_with_a_loop_of_another_organization_is_refused(): void
    {
        $dossier = $this->personalDossier();
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'status' => 'active']);

        $this->actingAs($this->owner)
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $dossier->id]), [
                'name' => $dossier->name,
                'visibility' => Dossier::VISIBILITY_LOOP,
                'shared_with_loop_id' => $foreignLoop->id,
            ])->assertSessionHasErrors('shared_with_loop_id');

        $this->assertNull($dossier->fresh()->shared_with_loop_id);
    }

    public function test_leaving_the_loop_modality_clears_the_sharing(): void
    {
        $loop = $this->loop('private');
        $dossier = $this->personalDossier(Dossier::VISIBILITY_LOOP, $loop);

        $this->actingAs($this->owner)
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $dossier->id]), [
                'name' => $dossier->name,
                'visibility' => Dossier::VISIBILITY_PRIVATE,
            ])->assertRedirect();

        // A stale reference must never keep granting access.
        $this->assertNull($dossier->fresh()->shared_with_loop_id);
    }

    public function test_another_user_cannot_edit_someone_elses_dossier(): void
    {
        $dossier = $this->personalDossier();

        $this->actingAs($this->member())
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $dossier->id]), [
                'name' => 'Vol', 'visibility' => Dossier::VISIBILITY_ORGANIZATION,
            ])->assertForbidden();
    }

    public function test_an_invalid_visibility_is_refused(): void
    {
        $dossier = $this->personalDossier();

        $this->actingAs($this->owner)
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $dossier->id]), [
                'name' => $dossier->name, 'visibility' => 'public',
            ])->assertSessionHasErrors('visibility');
    }

    // ── Régression ──────────────────────────────────────────────────────────

    public function test_adding_a_member_no_longer_rewrites_the_chosen_audience(): void
    {
        // The old syncVisibility() flipped this to `shared` on the spot, which
        // is why a chosen value never survived.
        $dossier = $this->personalDossier(Dossier::VISIBILITY_ORGANIZATION);

        DossierMember::create([
            'organization_id' => $this->org->id, 'dossier_id' => $dossier->id,
            'user_id' => $this->member()->id, 'role' => DossierMember::ROLE_READER,
        ]);
        $dossier->syncVisibility();

        $this->assertSame(Dossier::VISIBILITY_ORGANIZATION, $dossier->fresh()->visibility);
    }
}
