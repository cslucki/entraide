<?php

namespace Tests\Feature;

use App\Events\LoopMessageCreated;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatLoopAiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Organization $otherOrganization;

    protected User $owner;

    protected User $member;

    protected User $nonMember;

    protected User $crossUser;

    protected Loop $loop;

    public ?string $capturedContext = null;

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
        $this->loop = $loopService->createLoop($this->owner, 'Test Loop');
        $loopService->addMember($this->loop, $this->member, 'member');

        config(['ai.openai.api_key' => 'test-key']);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Réponse de l\'IA.']]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 18],
            ]),
        ]);
    }

    private function service(): ChatLoopAiService
    {
        return app(ChatLoopAiService::class);
    }

    private function assertNoAiMessage(): void
    {
        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $this->loop->id,
            'type' => 'ai',
        ]);
    }

    public function test_it_persists_an_ai_message_with_metadata_and_logs_interaction(): void
    {
        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'type' => 'ai',
            'body' => 'Réponse de l\'IA.',
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->assertNull($message->sender_id);
        $this->assertEquals('ai', $message->type);
        $this->assertEquals($this->member->id, $message->metadata['requested_by']);
        $this->assertEquals('answer', $message->metadata['action']);
        $this->assertEquals('openai', $message->metadata['provider']);
        $this->assertNotEmpty($message->metadata['model']);
        $this->assertIsArray($message->metadata['context_message_ids']);

        $interaction = AiInteraction::query()->latest('id')->first();

        $this->assertNotNull($interaction);
        $this->assertEquals($this->member->id, $interaction->user_id);
        $this->assertEquals($this->loop->organization_id, $interaction->organization_id);
        $this->assertEquals('chatloop_ai_answer', $interaction->feature);
        $this->assertEquals(12, $interaction->input_tokens);
        $this->assertEquals(18, $interaction->output_tokens);
        $this->assertStringContainsString('/', $interaction->model);
        $this->assertEquals($interaction->id, $message->metadata['ai_interaction_id']);
    }

    public function test_it_limits_context_to_the_last_thirty_messages(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            LoopMessage::factory()->create([
                'loop_id' => $this->loop->id,
                'sender_id' => $this->member->id,
                'body' => 'Message '.$i,
                'created_at' => now()->addMinutes($i),
            ]);
        }

        $expectedIds = LoopMessage::query()
            ->where('loop_id', $this->loop->id)
            ->orderBy('created_at')
            ->pluck('id')
            ->slice(-30)
            ->values()
            ->all();

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertCount(30, $message->metadata['context_message_ids']);
        $this->assertEquals($expectedIds, array_values($message->metadata['context_message_ids']));
    }

    public function test_it_respects_the_context_char_budget(): void
    {
        config(['ai.chatloop.max_context_chars' => 200]);

        LoopMessage::factory()->count(5)->create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => str_repeat('x', 100),
        ]);

        $this->fakeHttpCapturingContext();

        $this->service()->answer($this->loop, $this->member);

        $this->assertNotNull($this->capturedContext);
        $this->assertLessThanOrEqual(200, mb_strlen($this->capturedContext));
    }

    public function test_it_rejects_a_non_member(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('loops.not_an_active_member'));

        $this->service()->answer($this->loop, $this->nonMember);

        $this->assertNoAiMessage();
    }

    public function test_it_rejects_a_cross_organization_user(): void
    {
        LoopMember::create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->crossUser->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('loops.cross_organization'));

        $this->service()->answer($this->loop, $this->crossUser);

        $this->assertNoAiMessage();
    }

    public function test_it_blocks_a_second_concurrent_generation(): void
    {
        Cache::add('chatloop_ai_lock:'.$this->loop->id, true, 60);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('loops.ai_generation_in_progress'));

        $this->service()->answer($this->loop, $this->member);

        $this->assertNoAiMessage();
    }

    public function test_it_releases_the_lock_after_a_generation(): void
    {
        $this->service()->answer($this->loop, $this->member);

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertDatabaseCount('loop_messages', 2);
        $this->assertNotNull($message);
    }

    public function test_lock_ttl_is_never_below_timeout_plus_thirty_seconds(): void
    {
        config(['ai.chatloop.lock_ttl' => 5]);
        config(['ai.chatloop.timeout' => 30]);

        Cache::shouldReceive('add')
            ->with('chatloop_ai_lock:'.$this->loop->id, true, \Mockery::on(
                fn (int $ttl): bool => $ttl >= 60
            ))
            ->once()
            ->andReturn(true);

        Cache::shouldReceive('forget')
            ->with('chatloop_ai_lock:'.$this->loop->id)
            ->once();

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertNotNull($message);
    }

    public function test_it_dispatches_loop_message_created_event(): void
    {
        Event::fake([LoopMessageCreated::class]);

        $this->service()->answer($this->loop, $this->member);

        Event::assertDispatched(LoopMessageCreated::class, function (LoopMessageCreated $event) {
            return $event->loopId === $this->loop->id
                && $event->senderId === null
                && $event->type === 'ai';
        });
    }

    public function test_it_sanitizes_the_ai_output(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '<script>alert(1)</script> ## Titre'."\n\n"
                            .'**gras** et [lien](https://x.test) avec {{ blade }} et `code`.',
                    ],
                ]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 18],
            ]),
        ]);

        $message = $this->service()->answer($this->loop, $this->member);

        foreach (['<script', '##', '**', '{{', '](', '`'] as $needle) {
            $this->assertStringNotContainsString($needle, $message->body);
        }
    }

    public function test_it_does_not_send_any_email(): void
    {
        Mail::fake();

        $this->service()->answer($this->loop, $this->member);

        Mail::assertNothingSent();
    }

    public function test_member_can_request_an_ai_answer(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.ai', $this->loop), ['action' => 'answer']);

        $response->assertRedirect(route('loops.show', $this->loop));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'type' => 'ai',
        ]);
    }

    public function test_non_member_route_request_is_rejected(): void
    {
        $response = $this->actingAs($this->nonMember)
            ->post(route('loops.ai', $this->loop), ['action' => 'answer']);

        $response->assertRedirect(route('loops.show', $this->loop));
        $response->assertSessionHas('error', __('loops.not_an_active_member'));

        $this->assertNoAiMessage();
    }

    public function test_guest_route_request_redirects_to_login(): void
    {
        $response = $this->post(route('loops.ai', $this->loop), ['action' => 'answer']);

        $response->assertRedirect(route('login'));
    }

    public function test_invalid_action_is_rejected(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.ai', $this->loop), ['action' => 'bogus']);

        $response->assertSessionHasErrors('action');

        $this->assertNoAiMessage();
    }

    public function test_route_is_throttled(): void
    {
        $this->actingAs($this->member);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('loops.ai', $this->loop), ['action' => 'answer'])->assertRedirect();
        }

        $this->post(route('loops.ai', $this->loop), ['action' => 'answer'])
            ->assertStatus(429);
    }

    public function test_org_scoped_route_accepts_member_of_the_same_organization(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('organization.loops.ai', [$this->organization, $this->loop]), ['action' => 'answer']);

        $response->assertRedirect(route('organization.loops.show', [$this->organization, $this->loop]));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'type' => 'ai',
        ]);
    }

    public function test_org_scoped_route_refuses_a_cross_organization_user(): void
    {
        LoopMember::create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->crossUser->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->crossUser)
            ->post(route('organization.loops.ai', [$this->otherOrganization, $this->loop]), ['action' => 'answer']);

        $response->assertNotFound();

        $this->assertNoAiMessage();
    }

    protected function fakeHttpCapturingContext(): void
    {
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $this->capturedContext = $payload['messages'][1]['content'] ?? null;

            return Http::response([
                'choices' => [['message' => ['content' => 'Réponse de l\'IA.']]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 18],
            ]);
        });
    }
}
