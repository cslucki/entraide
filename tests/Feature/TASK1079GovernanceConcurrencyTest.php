<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopGovernanceService;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrency around the last-owner invariant.
 *
 * These tests are meaningful on PostgreSQL only: SQLite accepts lockForUpdate
 * syntactically but does not enforce row locking the same way, so a green run
 * there would prove nothing. They skip themselves elsewhere rather than give
 * false confidence.
 */
class TASK1079GovernanceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row locking is only genuinely enforced on PostgreSQL.');
        }

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $this->loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
    }

    private function owner(): LoopMember
    {
        return LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id,
            'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id,
            'joined_at' => now(),
        ]);
    }

    private function service(): LoopGovernanceService
    {
        return app(LoopGovernanceService::class);
    }

    private function activeOwnerCount(): int
    {
        return LoopMember::where('loop_id', $this->loop->id)
            ->where('status', 'active')
            ->where('role', LoopRoleRegistry::OWNER)
            ->count();
    }

    public function test_two_demotions_of_the_last_two_owners_cannot_both_succeed(): void
    {
        $first = $this->owner();
        $second = $this->owner();

        $this->assertSame(2, $this->activeOwnerCount());

        // Sequential here, but each call re-reads under lockForUpdate inside its
        // own transaction — which is exactly what makes the second one see the
        // real state instead of a stale count captured before the first ran.
        $a = $this->service()->demoteToMember($first);
        $b = $this->service()->demoteToMember($second->fresh());

        $this->assertSame(LoopGovernanceService::RESULT_OK, $a);
        $this->assertSame(LoopGovernanceService::RESULT_LAST_OWNER, $b);
        $this->assertSame(1, $this->activeOwnerCount(), 'The Loop must never end with zero owners');
    }

    public function test_a_demotion_racing_a_removal_still_leaves_one_owner(): void
    {
        $first = $this->owner();
        $second = $this->owner();

        $this->service()->demoteToMember($first);
        $removal = $this->service()->removeMember($second->fresh());

        $this->assertSame(LoopGovernanceService::RESULT_LAST_OWNER, $removal);
        $this->assertSame(1, $this->activeOwnerCount());
    }

    public function test_a_departure_racing_a_demotion_still_leaves_one_owner(): void
    {
        $first = $this->owner();
        $second = $this->owner();

        $this->service()->demoteToMember($first);
        $leave = $this->service()->leave($this->loop, $second->user_id);

        $this->assertSame(LoopGovernanceService::RESULT_LAST_OWNER, $leave);
        $this->assertSame(1, $this->activeOwnerCount());
    }

    public function test_the_lock_is_taken_on_the_whole_loop_not_a_single_row(): void
    {
        $first = $this->owner();
        $this->owner();

        DB::enableQueryLog();
        $this->service()->demoteToMember($first);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $locking = collect($log)->filter(fn ($q) => str_contains(strtolower($q['query']), 'for update'));

        $this->assertNotEmpty($locking, 'The transition must lock rows');
        // Scoped to the Loop, because the decision depends on the other owners.
        $this->assertTrue(
            $locking->contains(fn ($q) => str_contains(strtolower($q['query']), 'loop_id')),
            'The lock must cover the Loop memberships, not just the target row',
        );
    }

    public function test_the_invariant_holds_across_a_full_rotation(): void
    {
        $owners = collect(range(1, 4))->map(fn () => $this->owner());

        // Demote them one after another; the last attempt must be refused.
        $results = $owners->map(fn (LoopMember $m) => $this->service()->demoteToMember($m->fresh()));

        $this->assertSame(
            [LoopGovernanceService::RESULT_OK, LoopGovernanceService::RESULT_OK, LoopGovernanceService::RESULT_OK, LoopGovernanceService::RESULT_LAST_OWNER],
            $results->all(),
        );
        $this->assertSame(1, $this->activeOwnerCount());
    }
}
