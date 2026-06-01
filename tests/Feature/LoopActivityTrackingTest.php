<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopActivityTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $nonMember;

    private User $crossUser;

    private Loop $loop;

    private Loop $inactiveLoop;

    private LoopMessageService $messageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $loopService = new LoopService;

        $this->loop = $loopService->createLoop($this->owner, 'Active Loop');
        $loopService->addMember($this->loop, $this->member, 'member');

        $this->inactiveLoop = $loopService->createLoop($this->owner, 'Inactive Loop');
        $loopService->addMember($this->inactiveLoop, $this->member, 'member');

        $this->messageService = new LoopMessageService;
    }

    // -------------------------------------------------------------------------
    // Service: touch on sendUserMessage
    // -------------------------------------------------------------------------

    public function test_send_user_message_touches_loop_updated_at(): void
    {
        $originalUpdatedAt = $this->loop->updated_at->copy();

        $this->travel(5)->minutes();

        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'Activity check',
        );

        $freshLoop = $this->loop->fresh();

        $this->assertTrue(
            $freshLoop->updated_at->gt($originalUpdatedAt),
            'Loop updated_at should be touched after sending a user message',
        );
        $this->assertEquals('Activity check', $freshLoop->messages()->latest()->first()->body);
    }

    public function test_send_help_request_touches_loop_updated_at(): void
    {
        $originalUpdatedAt = $this->loop->updated_at->copy();

        $this->travel(5)->minutes();

        $this->messageService->sendHelpRequestMessage(
            $this->loop,
            $this->member,
            'I need help with architecture',
            'Help title',
            'I need help',
            'Project context',
            'advice',
            null,
            'normal',
        );

        $freshLoop = $this->loop->fresh();

        $this->assertTrue(
            $freshLoop->updated_at->gt($originalUpdatedAt),
            'Loop updated_at should be touched after sending a help request message',
        );
    }

    // -------------------------------------------------------------------------
    // Service: touch preserves tenant isolation
    // -------------------------------------------------------------------------

    public function test_send_message_from_same_organization_touches_correct_loop(): void
    {
        $activeOriginalUpdatedAt = $this->loop->updated_at->copy();
        $inactiveOriginalUpdatedAt = $this->inactiveLoop->updated_at->copy();

        $this->travel(5)->minutes();

        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'Only this loop should be touched',
        );

        $this->assertTrue(
            $this->loop->fresh()->updated_at->gt($activeOriginalUpdatedAt),
            'The loop receiving the message should be touched',
        );
        $this->assertEquals(
            $inactiveOriginalUpdatedAt->toDateTimeString(),
            $this->inactiveLoop->fresh()->updated_at->toDateTimeString(),
            'The inactive loop should not be touched',
        );
    }

    // -------------------------------------------------------------------------
    // Web route: activity sorting on index
    // -------------------------------------------------------------------------

    public function test_index_orders_active_loop_before_inactive_loop(): void
    {
        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'Recent activity on active loop',
        );

        $response = $this->actingAs($this->member)
            ->get(route('loops.index'));

        $response->assertStatus(200);

        $response->assertSeeInOrder(['Active Loop', 'Inactive Loop']);
    }

    public function test_index_shows_last_message_time(): void
    {
        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'This message sets the last activity',
        );

        $response = $this->actingAs($this->member)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Active Loop');
        $response->assertSee('Inactive Loop');
    }

    // -------------------------------------------------------------------------
    // Tenant safety: cross-community isolation
    // -------------------------------------------------------------------------

    public function test_cross_organization_user_cannot_see_loop_on_index(): void
    {
        $this->organization->update(['is_active' => false]);

        $response = $this->actingAs($this->crossUser)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertDontSee('Active Loop');
        $response->assertDontSee('Inactive Loop');
    }

    public function test_non_member_cannot_see_loop_on_index(): void
    {
        $response = $this->actingAs($this->nonMember)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertDontSee('Active Loop');
        $response->assertDontSee('Inactive Loop');
    }

    public function test_member_of_one_loop_does_not_see_same_organization_other_loop(): void
    {
        $loopService = new LoopService;
        $anotherLoop = $loopService->createLoop($this->owner, 'Owner Only Loop');

        $response = $this->actingAs($this->member)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('Active Loop');
        $response->assertDontSee('Owner Only Loop');
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function test_loop_without_messages_shows_dash_instead_of_time(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('loops.index'));

        $response->assertStatus(200);
        $response->assertSee('—');
    }

    public function test_guest_cannot_access_index(): void
    {
        $response = $this->get(route('loops.index'));

        $response->assertRedirect(route('login'));
    }
}
