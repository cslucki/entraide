<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\FakeAIProvider;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopHelpRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $member;

    private User $nonMember;

    private Loop $loop;

    private LoopMessageService $messageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);

        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->owner, 'Test Loop');

        $loopService->addMember($this->loop, $this->member, 'member');

        $this->messageService = new LoopMessageService;
    }

    // -------------------------------------------------------------------------
    // Service: sendHelpRequestMessage
    // -------------------------------------------------------------------------

    public function test_active_member_can_send_help_request(): void
    {
        $message = $this->messageService->sendHelpRequestMessage(
            $this->loop,
            $this->member,
            body: 'Je cherche des conseils pour trouver mes premiers clients.',
            title: 'Trouver mes premiers clients',
            need: 'Identifier des pistes concrètes pour obtenir mes premiers clients.',
            context: 'Je lance mon activité.',
            expectedHelpType: 'conseils, retours d\'expérience',
            deadline: null,
        );

        $this->assertNotNull($message);
        $this->assertEquals($this->loop->id, $message->loop_id);
        $this->assertEquals($this->member->id, $message->sender_id);
        $this->assertEquals('help_request', $message->type);
        $this->assertEquals('Je cherche des conseils pour trouver mes premiers clients.', $message->body);

        $meta = $message->metadata;
        $this->assertEquals('Trouver mes premiers clients', $meta['title']);
        $this->assertEquals('Identifier des pistes concrètes pour obtenir mes premiers clients.', $meta['need']);
        $this->assertEquals('conseils, retours d\'expérience', $meta['expected_help_type']);

        $this->assertDatabaseHas('loop_messages', [
            'id' => $message->id,
            'type' => 'help_request',
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
        ]);
    }

    public function test_non_member_cannot_send_help_request(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User is not an active member of this loop.');

        $this->messageService->sendHelpRequestMessage(
            $this->loop,
            $this->nonMember,
            body: 'Help',
            title: 'Help title',
            need: 'Help needed',
            context: '',
            expectedHelpType: 'advice',
        );
    }

    public function test_help_request_stores_deadline_in_metadata(): void
    {
        $message = $this->messageService->sendHelpRequestMessage(
            $this->loop,
            $this->member,
            body: 'Relecture avant vendredi.',
            title: 'Relecture d\'offre',
            need: 'Relecture rapide.',
            context: 'Offre à finaliser.',
            expectedHelpType: 'relecture',
            deadline: ['label' => 'avant vendredi', 'has_deadline' => true, 'date' => '2026-05-22'],
        );

        $this->assertNotNull($message);
        $this->assertEquals('help_request', $message->type);
        $this->assertEquals(['label' => 'avant vendredi', 'has_deadline' => true, 'date' => '2026-05-22'], $message->metadata['deadline']);
    }

    // -------------------------------------------------------------------------
    // Analyze route
    // -------------------------------------------------------------------------

    public function test_authenticated_member_can_analyze_intention(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => 'Je cherche des conseils pour trouver mes premiers clients',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('help_request_analysis');
        $response->assertSessionMissing('help_request_error');

        $analysis = session('help_request_analysis');
        $this->assertArrayHasKey('title', $analysis);
        $this->assertArrayHasKey('need', $analysis);
        $this->assertArrayHasKey('message_draft', $analysis);
    }

    public function test_analyze_requires_minimum_length(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => 'ab',
            ]);

        $response->assertSessionHasErrors('intention');
    }

    public function test_analyze_blocks_sensitive_data(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => 'Mon numéro perso est 0612345678',
            ]);

        $response->assertRedirect();
        $response->assertSessionMissing('help_request_analysis');
        $response->assertSessionHas('help_request_error');
    }

    public function test_analyze_blocks_legal_scope(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => 'J\'ai besoin d\'un conseil juridique pour mon contrat',
            ]);

        $response->assertRedirect();
        $response->assertSessionMissing('help_request_analysis');
        $response->assertSessionHas('help_request_error');
    }

    public function test_non_member_cannot_analyze_intention(): void
    {
        $response = $this->actingAs($this->nonMember)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => 'Je cherche des conseils',
            ]);

        $response->assertNotFound();
    }

    public function test_guest_cannot_analyze_intention(): void
    {
        $response = $this->post(route('loops.help-request.analyze', $this->loop), [
            'intention' => 'Je cherche des conseils',
        ]);

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Publish route
    // -------------------------------------------------------------------------

    public function test_authenticated_member_can_publish_help_request(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => 'Trouver mes premiers clients',
                'need' => 'Je cherche des conseils pour trouver mes premiers clients.',
                'context' => 'Je lance mon activité dans le consulting.',
                'expected_help_type' => 'conseils, retours d\'expérience',
                'deadline' => '',
                'urgency' => 'normal',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'type' => 'help_request',
            'body' => 'Je cherche des conseils pour trouver mes premiers clients.',
        ]);
    }

    public function test_publish_requires_title(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => '',
                'need' => 'Some need description.',
            ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_publish_requires_need(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => 'Some title',
                'need' => '',
            ]);

        $response->assertSessionHasErrors('need');
    }

    public function test_publish_enforces_title_max_length(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => str_repeat('a', 121),
                'need' => 'Valid need.',
            ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_non_member_cannot_publish_help_request(): void
    {
        $response = $this->actingAs($this->nonMember)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => 'Help title',
                'need' => 'Need description.',
            ]);

        $response->assertNotFound();
    }

    public function test_guest_cannot_publish_help_request(): void
    {
        $response = $this->post(route('loops.help-request.publish', $this->loop), [
            'title' => 'Help title',
            'need' => 'Need description.',
        ]);

        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Loop show displays help requests
    // -------------------------------------------------------------------------

    public function test_loop_show_displays_help_request_card(): void
    {
        $this->messageService->sendHelpRequestMessage(
            $this->loop,
            $this->member,
            body: 'Je cherche des conseils pour trouver mes premiers clients.',
            title: 'Trouver mes premiers clients',
            need: 'Identifier des pistes concrètes.',
            context: 'Je lance mon activité.',
            expectedHelpType: 'conseils, retours d\'expérience',
        );

        $response = $this->actingAs($this->member)
            ->get(route('loops.show', $this->loop));

        $response->assertStatus(200);
        $response->assertSee('Demande');
        $response->assertSee('aide');
        $response->assertSee('Trouver mes premiers clients');
        $response->assertSee('Je cherche des conseils');
    }

    public function test_loop_show_shows_help_requests_and_regular_messages_mixed(): void
    {
        $this->messageService->sendHelpRequestMessage(
            $this->loop,
            $this->member,
            body: 'Help request body.',
            title: 'Help Request Title',
            need: 'Need description.',
            context: '',
            expectedHelpType: 'advice',
        );

        $this->messageService->sendUserMessage(
            $this->loop,
            $this->member,
            'Regular message.',
        );

        $response = $this->actingAs($this->member)
            ->get(route('loops.show', $this->loop));

        $response->assertStatus(200);
        $response->assertSee('Demande');
        $response->assertSee('aide');
        $response->assertSee('Help Request Title');
        $response->assertSee('Help request body.');
        $response->assertSee('Regular message.');
    }

    // -------------------------------------------------------------------------
    // FakeAIProvider scenarios integration
    // -------------------------------------------------------------------------

    public function test_fake_ai_provider_generates_help_request_from_clear_intention(): void
    {
        $provider = new FakeAIProvider;
        $result = $provider->analyze('Je cherche des conseils pour trouver mes premiers clients');

        $this->assertEquals('help_request', $result->intent);
        $this->assertGreaterThanOrEqual(0.65, $result->confidence);
        $this->assertNotNull($result->messageDraft);
        $this->assertFalse($result->needsFallback());
        $this->assertFalse($result->isBlocked());
    }

    public function test_fake_ai_provider_returns_fallback_for_vague_intention(): void
    {
        $provider = new FakeAIProvider;
        $result = $provider->analyze('Je suis bloqué');

        $this->assertTrue($result->needsFallback());
        $this->assertLessThan(0.65, $result->confidence);
        $this->assertNull($result->messageDraft);
    }
}
