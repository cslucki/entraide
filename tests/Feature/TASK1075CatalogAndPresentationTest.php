<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TASK1075CatalogAndPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        return Organization::factory()->create(['loops_enabled' => true, 'members_can_create_loops' => true]);
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    // ── Catalog ────────────────────────────────────────────────────────────

    public function test_catalog_shows_active_loops_the_user_is_not_a_member_of(): void
    {
        $org = $this->org();
        $member = User::factory()->create(['organization_id' => $org->id]);
        $discoverable = Loop::factory()->requestAccess()->create(['organization_id' => $org->id, 'name' => 'Boucle à découvrir']);
        $this->withCurrentOrganization($org);

        // Two Loops exist so the "redirect to single loop" shortcut doesn't kick in.
        Loop::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => Loop::factory()->create(['organization_id' => $org->id])->id, 'user_id' => $member->id, 'status' => 'active']);

        $this->actingAs($member)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertSee('Boucle à découvrir');
    }

    public function test_catalog_hides_archived_loops(): void
    {
        $org = $this->org();
        $member = User::factory()->create(['organization_id' => $org->id]);
        Loop::factory()->archived()->create(['organization_id' => $org->id, 'name' => 'Boucle archivée']);
        Loop::factory()->create(['organization_id' => $org->id]);
        Loop::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertDontSee('Boucle archivée');
    }

    public function test_catalog_never_shows_another_organizations_loops(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $member = User::factory()->create(['organization_id' => $org->id]);
        Loop::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Boucle étrangère']);
        Loop::factory()->create(['organization_id' => $org->id]);
        Loop::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertDontSee('Boucle étrangère');
    }

    // ── Presentation (non-member) ────────────────────────────────────────────

    public function test_non_member_sees_open_loop_cta_to_join(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee(__('loops.cta_join'));
    }

    public function test_non_member_sees_request_loop_cta_to_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee(__('loops.cta_request'));
    }

    public function test_non_member_with_pending_request_sees_pending_notice_and_cancel(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $user->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee(__('loops.join_request_pending_notice'))
            ->assertSee(__('loops.cta_cancel_request'))
            ->assertDontSee(__('loops.cta_request'));
    }

    public function test_non_member_sees_invitation_only_notice(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->invitationAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee(__('loops.cta_invitation'))
            ->assertDontSee(__('loops.cta_join'))
            ->assertDontSee(__('loops.cta_request'));
    }

    public function test_archived_loop_404s_for_non_member(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->archived()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.show', $loop))
            ->assertNotFound();
    }

    // ── Create with new fields ────────────────────────────────────────────

    public function test_create_saves_tagline_access_mode_and_cover_image(): void
    {
        Storage::fake('public');
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Cours IA générative',
            'tagline' => 'Pour les curieux et les pros',
            'description' => 'Un cours pratique.',
            'access_mode' => 'open',
            'cover_image' => UploadedFile::fake()->image('cover.jpg'),
        ])->assertRedirect();

        $loop = Loop::where('name', 'Cours IA générative')->firstOrFail();
        $this->assertSame('Pour les curieux et les pros', $loop->tagline);
        $this->assertSame('open', $loop->access_mode);
        $this->assertNotNull($loop->cover_image_path);
        Storage::disk('public')->assertExists($loop->cover_image_path);
        // TASK-1079: new Loops are born with a real business type instead of the
        // historical `custom` placeholder. Existing rows keep `custom`, read as
        // `general` by the registry — no backfill.
        $this->assertSame('general', $loop->type);
        $this->assertSame('private', $loop->visibility);
    }

    public function test_create_defaults_access_mode_to_request_when_omitted(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Boucle sans mode choisi',
        ])->assertRedirect();

        $loop = Loop::where('name', 'Boucle sans mode choisi')->firstOrFail();
        $this->assertSame('request', $loop->access_mode);
    }

    public function test_create_rejects_invalid_access_mode(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Boucle invalide',
            'access_mode' => 'public',
        ])->assertSessionHasErrors('access_mode');
    }

    // ── Update with new fields ─────────────────────────────────────────────

    public function test_owner_can_update_tagline_and_access_mode(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->put(route('loops.update', $loop), [
            'name' => $loop->name,
            'tagline' => 'Nouvelle accroche',
            'access_mode' => 'open',
        ])->assertRedirect();

        $loop->refresh();
        $this->assertSame('Nouvelle accroche', $loop->tagline);
        $this->assertSame('open', $loop->access_mode);
    }

    public function test_owner_can_replace_and_remove_cover_image(): void
    {
        Storage::fake('public');
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => 'loop-covers/x/old.jpg']);
        Storage::disk('public')->put('loop-covers/x/old.jpg', 'fake');
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->put(route('loops.update', $loop), [
            'name' => $loop->name,
            'remove_cover_image' => '1',
        ])->assertRedirect();

        $this->assertNull($loop->fresh()->cover_image_path);
        Storage::disk('public')->assertMissing('loop-covers/x/old.jpg');
    }

    // ── Join requests: store ─────────────────────────────────────────────────

    public function test_member_can_send_a_join_request_with_message(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.join-requests.store', $loop), [
            'message' => 'Je suis motivé !',
        ])->assertRedirect();

        $this->assertDatabaseHas('loop_join_requests', [
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'message' => 'Je suis motivé !',
            'status' => 'pending',
        ]);
    }

    public function test_cannot_store_join_request_on_open_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->post(route('loops.join-requests.store', $loop))
            ->assertForbidden();
    }

    public function test_join_request_store_is_throttled(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        for ($i = 1; $i <= 5; $i++) {
            $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
            $this->actingAs($user)
                ->post(route('loops.join-requests.store', $loop))
                ->assertRedirect();
        }

        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $this->actingAs($user)
            ->post(route('loops.join-requests.store', $loop))
            ->assertStatus(429);
    }

    // ── Join requests: cancel ────────────────────────────────────────────────

    public function test_requester_can_cancel_their_own_pending_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $user->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->delete(route('loop-join-requests.cancel', $joinRequest))
            ->assertRedirect();

        $this->assertSame('cancelled', $joinRequest->fresh()->status);
    }

    public function test_cannot_cancel_someone_elses_join_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $outsider = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($outsider)
            ->delete(route('loop-join-requests.cancel', $joinRequest))
            ->assertNotFound();

        $this->assertSame('pending', $joinRequest->fresh()->status);
    }

    public function test_cannot_cancel_join_request_from_another_organization(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $otherOrg->id]);
        $applicant = User::factory()->create(['organization_id' => $otherOrg->id]);
        $outsider = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $otherOrg->id, 'user_id' => $applicant->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($outsider)
            ->delete(route('loop-join-requests.cancel', $joinRequest))
            ->assertNotFound();
    }
}
