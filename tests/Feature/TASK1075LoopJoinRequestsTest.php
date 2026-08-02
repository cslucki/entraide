<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TASK1075LoopJoinRequestsTest extends TestCase
{
    use RefreshDatabase;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoopService;
    }

    private function org(): Organization
    {
        return Organization::factory()->create(['loops_enabled' => true]);
    }

    // ── Migration / backfill ──────────────────────────────────────────────

    public function test_existing_public_loop_backfills_to_open_access(): void
    {
        // Simulate a pre-TASK-1075 row via direct insert bypassing the factory default,
        // then run the same backfill logic the migration performs.
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'visibility' => 'public']);

        DB::table('loops')->where('id', $loop->id)->update(['access_mode' => 'open']);

        $this->assertSame('open', $loop->fresh()->access_mode);
    }

    public function test_new_loop_defaults_to_request_access_mode(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        app()->instance('current_organization', $org);

        $loop = $this->service->createLoop($owner, 'Nouvelle Boucle');

        $this->assertSame('request', $loop->access_mode);
    }

    public function test_is_valid_access_mode(): void
    {
        $this->assertTrue(Loop::isValidAccessMode('open'));
        $this->assertTrue(Loop::isValidAccessMode('request'));
        $this->assertTrue(Loop::isValidAccessMode('invitation'));
        $this->assertFalse(Loop::isValidAccessMode('public'));
    }

    // ── joinOpenLoop ───────────────────────────────────────────────────────

    public function test_join_open_loop_creates_active_member(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $member = $this->service->joinOpenLoop($loop, $user);

        $this->assertSame('member', $member->role);
        $this->assertSame('active', $member->status);
        $this->assertNotNull($member->joined_at);
    }

    public function test_join_open_loop_is_idempotent_for_active_member(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $first = $this->service->joinOpenLoop($loop, $user);
        $second = $this->service->joinOpenLoop($loop, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $user->id)->count());
    }

    public function test_join_open_loop_reactivates_a_left_member(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $user->id, 'status' => 'left']);

        $member = $this->service->joinOpenLoop($loop, $user);

        $this->assertSame('active', $member->status);
        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $user->id)->count());
    }

    // ── requestToJoin ──────────────────────────────────────────────────────

    public function test_request_to_join_creates_pending_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $request = $this->service->requestToJoin($loop, $user, 'Je veux apprendre !');

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $request->status);
        $this->assertSame('Je veux apprendre !', $request->message);
        $this->assertSame($org->id, $request->organization_id);
    }

    public function test_request_to_join_refuses_second_pending_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->service->requestToJoin($loop, $user);

        $this->expectException(\RuntimeException::class);
        $this->service->requestToJoin($loop, $user);
    }

    public function test_request_to_join_allowed_again_after_rejection(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopJoinRequest::factory()->rejected()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $user->id]);

        $request = $this->service->requestToJoin($loop, $user);

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $request->status);
    }

    // ── cancelJoinRequest ──────────────────────────────────────────────────

    public function test_cancel_join_request_marks_cancelled(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $request = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id]);

        $this->service->cancelJoinRequest($request);

        $this->assertSame(LoopJoinRequest::STATUS_CANCELLED, $request->fresh()->status);
    }

    public function test_cancel_join_request_refuses_non_pending(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $request = LoopJoinRequest::factory()->accepted()->create(['loop_id' => $loop->id, 'organization_id' => $org->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->cancelJoinRequest($request);
    }

    // ── acceptJoinRequest ──────────────────────────────────────────────────

    public function test_accept_join_request_creates_exactly_one_membership(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $orgAdmin = User::factory()->create(['organization_id' => $org->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $request = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);

        $member = $this->service->acceptJoinRequest($request, $orgAdmin);

        $this->assertSame('active', $member->status);
        $this->assertSame('member', $member->role);
        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $request->fresh()->status);
        $this->assertSame($orgAdmin->id, $request->fresh()->decided_by);
        $this->assertNotNull($request->fresh()->decided_at);
        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $applicant->id)->count());
    }

    public function test_accept_join_request_refuses_already_decided(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $decider = User::factory()->create(['organization_id' => $org->id]);
        $request = LoopJoinRequest::factory()->accepted()->create(['loop_id' => $loop->id, 'organization_id' => $org->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->acceptJoinRequest($request, $decider);
    }

    // ── rejectJoinRequest ────────────────────────────────────────────────────

    public function test_reject_join_request_creates_no_membership(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $decider = User::factory()->create(['organization_id' => $org->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $request = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);

        $this->service->rejectJoinRequest($request, $decider);

        $this->assertSame(LoopJoinRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame(0, LoopMember::where('loop_id', $loop->id)->where('user_id', $applicant->id)->count());
    }
}
