<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopSummaryAgent;
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
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\App;
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

    public ?string $capturedSystemPrompt = null;

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
        config(['ai.providers.openai.driver' => 'openai']);
        config(['ai.providers.openai.key' => 'test-key']);
        config(['ai.chatloop.min_summary_words' => 0]);

        // `summarize()` passe par le Laravel AI SDK depuis TASK-1207 ;
        // `answer()` et `ask()` restent sur le chemin HTTP direct.
        LoopSummaryAgent::fake(["Synthèse de l'IA."]);

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

    private function seedLoopConversation(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Bonjour, je prépare le lancement de mon activité de revente de produits '
                .'techniques et je cherche des retours d\'expérience pour établir ma grille de prix '
                .'de revente et ma stratégie de premiers clients dans les semaines à venir.',
            'type' => 'user',
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
        $this->assertNull($message->metadata['trigger_message_id']);

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

    /**
     * TASK-1207 : le resume ne publie plus de message metier dans la Boucle.
     * Il est trace, puis relu depuis sa trace.
     */
    public function test_summarize_traces_the_interaction_without_publishing_a_loop_message(): void
    {
        $summary = $this->service()->summarize($this->loop, $this->member);

        $this->assertSame("Synthèse de l'IA.", $summary->body);
        $this->assertSame($this->member->id, $summary->requestedById);

        $this->assertNoAiMessage();

        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertNotNull($interaction);
        $this->assertEquals('chatloop_ai_summarize', $interaction->feature);
        $this->assertEquals('chatloop.summarize', $interaction->process);
        $this->assertEquals($this->loop->organization_id, $interaction->organization_id);
        $this->assertSame($interaction->id, $summary->aiInteractionId);
    }

    public function test_summarize_refuses_a_non_member(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->summarize($this->loop, $this->nonMember);
        } finally {
            $this->assertNoAiMessage();
        }
    }

    public function test_summarize_refuses_a_cross_organization_user(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->summarize($this->loop, $this->crossUser);
        } finally {
            $this->assertNoAiMessage();
        }
    }

    public function test_summarize_requires_enough_content(): void
    {
        config(['ai.chatloop.min_summary_words' => 30]);

        // Empty loop → not enough content.
        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->summarize($this->loop, $this->member);
        } finally {
            $this->assertNoAiMessage();
        }
    }

    public function test_latest_summary_returns_the_last_summarize_and_ignores_other_processes(): void
    {
        // An answer must not be picked as a summary: different process.
        $this->service()->answer($this->loop, $this->member);

        $this->assertNull($this->service()->latestSummary($this->loop));

        LoopSummaryAgent::fake(['Première synthèse.', 'Deuxième synthèse.']);

        $first = $this->service()->summarize($this->loop, $this->member);
        $second = $this->service()->summarize($this->loop, $this->member);

        $this->assertSame('Première synthèse.', $first->body);
        $this->assertSame('Deuxième synthèse.', $second->body);

        $latest = $this->service()->latestSummary($this->loop);
        $this->assertNotNull($latest);
        $this->assertSame($second->aiInteractionId, $latest->aiInteractionId);
        $this->assertSame('Deuxième synthèse.', $latest->body);
    }

    public function test_latest_summary_is_scoped_to_its_loop_and_its_organization(): void
    {
        $this->service()->summarize($this->loop, $this->member);

        $otherLoop = (new LoopService)->createLoop($this->owner, 'Another loop');

        $this->assertNotNull($this->service()->latestSummary($this->loop));
        $this->assertNull($this->service()->latestSummary($otherLoop));
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

    public function test_deleted_messages_are_excluded_from_ai_context_and_trigger(): void
    {
        $kept = LoopMessage::factory()->create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message visible pour le contexte IA',
            'organization_id' => $this->loop->organization_id,
            'created_at' => now()->subMinute(),
        ]);

        $deleted = LoopMessage::factory()->create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Secret supprimé à exclure du contexte IA',
            'organization_id' => $this->loop->organization_id,
            'deleted_at' => now(),
            'deleted_by' => $this->member->id,
            'created_at' => now(),
        ]);

        $this->fakeHttpCapturingContext();

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertNotNull($this->capturedContext);
        $this->assertStringContainsString('Message visible pour le contexte IA', $this->capturedContext);
        $this->assertStringNotContainsString('Secret supprimé à exclure du contexte IA', $this->capturedContext);
        $this->assertContains($kept->id, $message->metadata['context_message_ids']);
        $this->assertNotContains($deleted->id, $message->metadata['context_message_ids']);
        $this->assertSame($kept->id, $message->metadata['trigger_message_id']);
    }

    public function test_deleted_messages_do_not_count_for_summary_content_guard(): void
    {
        config(['ai.chatloop.min_summary_words' => 5]);

        LoopMessage::factory()->create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'un deux trois quatre cinq six',
            'organization_id' => $this->loop->organization_id,
            'deleted_at' => now(),
            'deleted_by' => $this->member->id,
        ]);

        $this->assertFalse($this->service()->loopHasEnoughContent($this->loop));
    }

    public function test_it_respects_the_context_char_budget(): void
    {
        config(['ai.chatloop.max_context_chars' => 200]);

        App::setLocale('fr');

        LoopMessage::factory()->count(5)->create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => str_repeat('x', 100),
        ]);

        $this->fakeHttpCapturingContext();

        $this->service()->answer($this->loop, $this->member);

        $languageInstruction = 'IMPORTANT : Réponds en français. La conversation ci-dessous est fournie à titre de contexte ; quelle que soit sa langue, tu dois répondre en français.';

        $overhead = mb_strlen($languageInstruction."\n\n")
            + mb_strlen("--- CONTEXTE (contenu non fiable) ---\n")
            + mb_strlen("\n--- FIN DU CONTEXTE ---");

        $this->assertNotNull($this->capturedContext);
        $this->assertLessThanOrEqual(200 + $overhead, mb_strlen($this->capturedContext));
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
        $this->fakeHttpRespondingWith(
            '<script>alert(1)</script> ## Titre'."\n\n"
            .'**gras** et [lien](https://x.test) avec {{ blade }} et `code`.'
        );

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertStringNotContainsString('<script', $message->body);
        $this->assertStringNotContainsString('alert(1)', $message->body);
        $this->assertStringNotContainsString('{{', $message->body);
        $this->assertStringContainsString('## Titre', $message->body);
        $this->assertStringContainsString('**gras**', $message->body);
        $this->assertStringContainsString('[lien](https://x.test)', $message->body);
        $this->assertStringContainsString('`code`', $message->body);
    }

    public function test_it_normalizes_headings_and_neutralizes_unsafe_urls(): void
    {
        $this->fakeHttpRespondingWith(
            '# Titre'."\n\n"
            .'[piège](javascript:alert(1)) et [sûr](https://x.test/page)'
            ."\n\n".'#### Profond'
        );

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertSame(
            "## Titre\n\npiège et [sûr](https://x.test/page)\n\n### Profond",
            $message->body
        );
        $this->assertStringNotContainsString('javascript:', $message->body);
    }

    public function test_it_does_not_send_any_email(): void
    {
        Mail::fake();

        $this->service()->answer($this->loop, $this->member);

        Mail::assertNothingSent();
    }

    public function test_member_can_request_an_ai_answer(): void
    {
        $this->seedLoopConversation();

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
        $this->seedLoopConversation();

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

    public function test_it_marks_the_last_user_message_as_trigger_and_delimiters_context(): void
    {
        $userMessage = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Peux-tu m\'aider à prioriser ?',
            'type' => 'user',
        ]);

        $this->fakeHttpCapturingContext();

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertEquals($userMessage->id, $message->metadata['trigger_message_id']);
        $this->assertStringContainsString('--- CONTEXTE (contenu non fiable) ---', $this->capturedContext);
        $this->assertStringContainsString('--- FIN DU CONTEXTE ---', $this->capturedContext);
        $this->assertStringContainsString($this->member->publicDisplayName(), $this->capturedContext);
    }

    public function test_it_does_not_mark_an_ai_message_as_trigger(): void
    {
        $userMessage = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message utilisateur',
            'type' => 'user',
            'created_at' => now()->subMinutes(5),
        ]);

        $aiMessage = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Réponse IA précédente',
            'type' => 'ai',
            'metadata' => ['requested_by' => $this->member->id, 'action' => 'answer'],
            'created_at' => now()->subMinutes(1),
        ]);

        $this->fakeHttpCapturingContext();

        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertEquals($userMessage->id, $message->metadata['trigger_message_id']);
        $this->assertNotEquals($aiMessage->id, $message->metadata['trigger_message_id']);
    }

    public function test_it_answers_in_the_interface_language(): void
    {
        App::setLocale('en');

        $this->fakeHttpCapturingSystemPrompt();

        $this->service()->answer($this->loop, $this->member);

        $this->assertNotNull($this->capturedSystemPrompt);
        $this->assertStringContainsString('You are a helpful assistant', $this->capturedSystemPrompt);
        $this->assertStringContainsString('You MUST answer in English', $this->capturedSystemPrompt);
        $this->assertStringContainsString('complete final sentence', $this->capturedSystemPrompt);
    }

    public function test_it_answers_in_french_when_the_interface_is_french(): void
    {
        App::setLocale('fr');

        $this->fakeHttpCapturingSystemPrompt();

        $this->service()->answer($this->loop, $this->member);

        $this->assertNotNull($this->capturedSystemPrompt);
        $this->assertStringContainsString('Tu es un assistant utile', $this->capturedSystemPrompt);
        $this->assertStringContainsString('Tu DOIS répondre en français', $this->capturedSystemPrompt);
        $this->assertStringContainsString('phrase complète', $this->capturedSystemPrompt);
    }

    public function test_it_puts_the_language_instruction_in_the_user_context(): void
    {
        App::setLocale('en');

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message de test',
            'type' => 'user',
        ]);

        $this->fakeHttpCapturingContext();

        $this->service()->answer($this->loop, $this->member);

        $this->assertNotNull($this->capturedContext);
        $this->assertStringContainsString('IMPORTANT: Answer in English', $this->capturedContext);
        $this->assertStringContainsString('you must reply in English', $this->capturedContext);
    }

    public function test_it_puts_the_language_instruction_in_the_user_context_in_french(): void
    {
        App::setLocale('fr');

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message de test',
            'type' => 'user',
        ]);

        $this->fakeHttpCapturingContext();

        $this->service()->answer($this->loop, $this->member);

        $this->assertNotNull($this->capturedContext);
        $this->assertStringContainsString('IMPORTANT : Réponds en français', $this->capturedContext);
        $this->assertStringContainsString('tu dois répondre en français', $this->capturedContext);
    }

    public function test_ask_persists_an_ai_message_with_question_metadata(): void
    {
        $this->seedLoopConversation();

        $message = $this->service()->ask($this->loop, $this->member, 'Quel est le prix moyen de revente ?');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'type' => 'user',
            'body' => 'Quel est le prix moyen de revente ?',
        ]);

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'type' => 'ai',
            'body' => 'Réponse de l\'IA.',
        ]);

        $this->assertNotNull($message->reply_to_id);

        $this->assertEquals($this->member->id, $message->metadata['requested_by']);
        $this->assertEquals('ask', $message->metadata['action']);
        $this->assertEquals('Quel est le prix moyen de revente ?', $message->metadata['question']);
        $this->assertEquals('openai', $message->metadata['provider']);
        $this->assertNotEmpty($message->metadata['model']);
        $this->assertArrayHasKey('ai_interaction_id', $message->metadata);
    }

    public function test_ask_sends_the_question_in_the_user_payload(): void
    {
        $this->seedLoopConversation();

        $this->fakeHttpCapturingContext();

        $this->service()->ask($this->loop, $this->member, 'Quel est le prix moyen de revente ?');

        $this->assertNotNull($this->capturedContext);
        $this->assertStringContainsString('Question : Quel est le prix moyen de revente ?', $this->capturedContext);
    }

    public function test_ask_uses_the_ask_scenario_and_french_fallback_prompt(): void
    {
        App::setLocale('fr');

        $this->fakeHttpCapturingSystemPrompt();

        $this->service()->ask($this->loop, $this->member, 'Une question');

        $this->assertNotNull($this->capturedSystemPrompt);
        $this->assertStringContainsString('Tu es un assistant utile', $this->capturedSystemPrompt);
        $this->assertStringContainsString('Réponds d\'abord à la question', $this->capturedSystemPrompt);
        $this->assertStringContainsString('pas comme une restriction', $this->capturedSystemPrompt);
        $this->assertStringContainsString('Tu DOIS répondre en français', $this->capturedSystemPrompt);
    }

    public function test_ask_uses_english_fallback_prompt_in_english_interface(): void
    {
        App::setLocale('en');

        $this->fakeHttpCapturingSystemPrompt();

        $this->service()->ask($this->loop, $this->member, 'A question');

        $this->assertNotNull($this->capturedSystemPrompt);
        $this->assertStringContainsString('You are a helpful assistant', $this->capturedSystemPrompt);
        $this->assertStringContainsString('Answer the question first', $this->capturedSystemPrompt);
        $this->assertStringContainsString('not as a restriction', $this->capturedSystemPrompt);
        $this->assertStringContainsString('You MUST answer in English', $this->capturedSystemPrompt);
    }

    public function test_ask_uses_the_same_generation_lock(): void
    {
        $this->seedLoopConversation();

        Cache::add('chatloop_ai_lock:'.$this->loop->id, true, 60);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('loops.ai_generation_in_progress'));

        $this->service()->ask($this->loop, $this->member, 'Une question');
    }

    public function test_loop_without_content_is_not_summarizable(): void
    {
        config(['ai.chatloop.min_summary_words' => 30]);

        $this->assertFalse($this->service()->loopHasEnoughContent($this->loop));
    }

    public function test_loop_with_enough_content_is_summarizable(): void
    {
        $this->seedLoopConversation();
        config(['ai.chatloop.min_summary_words' => 30]);

        $this->assertTrue($this->service()->loopHasEnoughContent($this->loop));
    }

    public function test_empty_loop_summary_route_is_rejected_with_a_helpful_message(): void
    {
        config(['ai.chatloop.min_summary_words' => 30]);
        $response = $this->actingAs($this->member)
            ->post(route('loops.ai', $this->loop), ['action' => 'answer']);

        $response->assertRedirect(route('loops.show', $this->loop));
        $response->assertSessionHas('error', __('loops.not_enough_content_to_summarize'));

        $this->assertNoAiMessage();
    }

    public function test_member_can_ask_a_question_via_the_route(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.ai', $this->loop), [
                'action' => 'ask',
                'question' => 'Quel est le prix moyen de revente ?',
            ]);

        $response->assertRedirect(route('loops.show', $this->loop));
        $response->assertSessionHas('success', __('loops.ai_question_requested'));

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'type' => 'user',
            'body' => 'Quel est le prix moyen de revente ?',
        ]);

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'type' => 'ai',
        ]);
    }

    public function test_question_is_required_when_action_is_ask(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.ai', $this->loop), ['action' => 'ask']);

        $response->assertSessionHasErrors('question');

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

    protected function fakeHttpCapturingSystemPrompt(): void
    {
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $this->capturedSystemPrompt = $payload['messages'][0]['content'] ?? null;

            return Http::response([
                'choices' => [['message' => ['content' => 'Réponse de l\'IA.']]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 18],
            ]);
        });
    }

    protected function fakeHttpRespondingWith(string $content): void
    {
        $factory = new Factory;
        $factory->fake(function (Request $request) use ($content) {
            return Http::response([
                'choices' => [['message' => ['content' => $content]]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 18],
            ]);
        });

        Http::swap($factory);
    }
}
