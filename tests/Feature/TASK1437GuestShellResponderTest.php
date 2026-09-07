<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Ai\CapabilityRegistry;
use App\Models\AdminAiPrompt;
use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellGate;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellPromptResolver;
use App\Services\GuestShell\GuestShellResponder;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestShellTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1437 — SW-7 : le premier appel provider du Shell Welcome et sa
 * comptabilite (Addendum V2 §9, cadre Cyril §5/§7, MASTER Q62/Q63). Tout est
 * prouve avec le FAKE du SDK : aucun appel reel (hard gate Cyril).
 *
 * Ce qui est mesure :
 * 1. succes : le provider est appele UNE fois apres les gardes, avec le
 *    modele resolu, max_tokens = la borne de la garde, le prompt EN BASE et
 *    le contexte PUBLIC dans les instructions ; ledger success (user_id
 *    NULL, capability, process, credential Organization, tokens, cout,
 *    correlation) ; message assistant relie a l'invocation ; aucun
 *    ai_interactions ;
 * 2. echec provider : ledger failed (usage non observe, cout inconnu explicite,
 *    erreur bornee), message visiteur CONSERVE, repli assistant LOCAL relie a
 *    l'invocation, compteur non remis a zero, aucun second appel ;
 * 3. garde refusee : aucun appel, aucun message, aucune ligne ledger ;
 * 4. prompt absent en base : fail-closed, aucun appel, rien d'ecrit ;
 * 5. l'historique de CETTE conversation (et d'aucune autre) est montre au modele.
 */
class TASK1437GuestShellResponderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private GuestVisitor $visitor;

    private GuestConversation $conversation;

    private GuestShellResponder $responder;

    private GuestConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.visitor_monthly_max_messages' => 30,
            'ai.guest_shell.rate_limit_per_minute' => 6,
            'ai.guest_shell.max_output_tokens' => 650,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
        ]);

        $this->org = Organization::factory()->create(['slug' => 'org-a-1437', 'name' => 'CyberWorkers', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => 'Entraide entre freelances']);
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true, 'max_messages' => 3]);
        OrganizationAiSetting::create(['organization_id' => $this->org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);

        $this->visitor = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->org, ['locale' => 'fr']);
        $this->conversations = app(GuestConversationService::class);
        $this->conversation = $this->conversations->start($this->visitor);
        $this->responder = app(GuestShellResponder::class);
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $this->visitor));
    }

    private function respond(string $message): GuestShellTurn
    {
        return $this->responder->respond($this->org->fresh(), $this->visitor->fresh(), $this->conversation->fresh(), $message);
    }

    // ── 1. Succes ───────────────────────────────────────────────────────────

    public function test_a_successful_turn_calls_the_provider_once_with_the_db_prompt_and_public_context_and_is_ledgered_exactly(): void
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue chez CyberWorkers ! Que cherchez-vous ?', new Usage(120, 40), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $prompt = app(GuestShellPromptResolver::class)->resolve();
        $this->assertNotNull($prompt, 'le seed SW-2 fournit le prompt');

        $turn = $this->respond('Bonjour, que faites-vous ?');

        $this->assertTrue($turn->isAnswered(), (string) $turn->reason);
        $this->assertSame('Bienvenue chez CyberWorkers ! Que cherchez-vous ?', $turn->assistantMessage->body);
        $this->assertSame(GuestMessage::ROLE_ASSISTANT, $turn->assistantMessage->role);
        $this->assertSame('Bonjour, que faites-vous ?', $turn->userMessage->body);
        $this->assertSame(1, $this->conversation->fresh()->message_count);

        GuestShellAgent::assertPrompted(function (AgentPrompt $p) use ($prompt) {
            $instructions = (string) $p->agent->instructions();

            return $p->model === 'openai/gpt-4o-mini'
                && $p->agent->maxTokens() === 650
                && str_contains($instructions, $prompt->text)
                && str_contains($instructions, 'CyberWorkers')
                && str_contains($instructions, 'Entraide entre freelances')
                && str_contains($p->prompt, 'Bonjour, que faites-vous ?');
        });

        $this->assertSame(1, AiProviderInvocation::count(), 'un tour = une invocation');
        $invocation = AiProviderInvocation::firstOrFail();
        $this->assertSame($this->org->id, $invocation->organization_id);
        $this->assertNull($invocation->user_id, 'jamais de faux User');
        $this->assertSame(CapabilityRegistry::GUEST_SHELL_WELCOME, $invocation->capability);
        $this->assertSame('guest_shell', $invocation->process);
        $this->assertSame(AiProviderInvocation::OPERATION_GENERATION, $invocation->operation);
        $this->assertSame('openrouter', $invocation->provider);
        $this->assertSame('openai/gpt-4o-mini', $invocation->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $invocation->credential_source, 'le credential est celui de l\'Organization');
        $this->assertSame(120, $invocation->input_tokens);
        $this->assertSame(40, $invocation->output_tokens);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $invocation->status);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $invocation->cost_status, 'le tarif de ce modele est connu : le cout est calcule');
        $this->assertGreaterThan(0, (float) $invocation->provider_cost);
        $this->assertTrue(Str::isUuid((string) $invocation->correlation_id));
        $this->assertSame($invocation->id, $turn->assistantMessage->ai_provider_invocation_id);
        $this->assertSame($invocation->id, $turn->invocation->id);
        $this->assertSame(0, DB::table('ai_interactions')->count(), 'le Guest n\'ecrit jamais ai_interactions');
    }

    // ── 2. Echec provider ──────────────────────────────────────────────────

    public function test_a_provider_failure_keeps_the_user_message_ledgers_the_truth_and_records_a_local_fallback_without_retry(): void
    {
        // Forme Closure du fake : chaque appel leve — l'echec survient APRES le demarrage reel de l'invocation.
        GuestShellAgent::fake(fn () => throw new RuntimeException('upstream 502 with sk-live-SECRET inside'));

        $turn = $this->respond('Bonjour ?');

        $this->assertSame(GuestShellTurn::FAILED, $turn->status);
        $this->assertSame('provider_failed', $turn->reason);
        // Le tour est consomme : le message visiteur reste, le compteur ne recule pas.
        $this->assertSame('Bonjour ?', $turn->userMessage->fresh()->body);
        $this->assertSame(1, $this->conversation->fresh()->message_count);
        // Le repli est un message ASSISTANT local (MASTER Q62), relie a l'invocation, jamais `system`.
        $this->assertSame(GuestMessage::ROLE_ASSISTANT, $turn->assistantMessage->role);
        $this->assertSame(__('guest_shell.fallback_unavailable', [], 'fr'), $turn->assistantMessage->body);
        $this->assertSame(['user', 'assistant'], $this->conversation->fresh()->messages->pluck('role')->all());

        $this->assertSame(1, AiProviderInvocation::count(), 'un appel tente = une ligne, meme en echec — jamais un second appel');
        $invocation = AiProviderInvocation::firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $invocation->status);
        $this->assertNull($invocation->user_id);
        $this->assertNull($invocation->input_tokens, 'usage non observe : null, pas 0 invente');
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $invocation->cost_status, 'cout inconnu EXPLICITE');
        $this->assertNull($invocation->provider_cost);
        $this->assertSame(RuntimeException::class, $invocation->failure_reason, 'erreur bornee : la classe, jamais le message (qui peut contenir une cle)');
        $this->assertStringNotContainsString('SECRET', json_encode($invocation->getAttributes()));
        $this->assertSame($invocation->id, $turn->assistantMessage->ai_provider_invocation_id);
        $this->assertSame(0, DB::table('ai_interactions')->count());
    }

    // ── 3. Garde refusee : rien ────────────────────────────────────────────

    public function test_a_refused_guard_calls_nothing_and_writes_nothing(): void
    {
        GuestShellAgent::fake([new TextResponse('jamais', new Usage(1, 1), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => false]);

        $turn = $this->respond('Bonjour');

        $this->assertTrue($turn->isRefused());
        $this->assertSame('shell_disabled', $turn->reason);
        $this->assertSame('policy', $turn->step);
        GuestShellAgent::assertNeverPrompted();
        $this->assertSame(0, AiProviderInvocation::count());
        $this->assertSame(0, GuestMessage::count());
        $this->assertSame(0, $this->conversation->fresh()->message_count);

        // A la limite de conversation aussi : refus AVANT append, rien d'ecrit.
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true, 'max_messages' => 1]);
        GuestShellAgent::fake([new TextResponse('Une reponse', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->assertTrue($this->respond('Premier')->isAnswered());
        $refused = $this->respond('Second');
        $this->assertTrue($refused->isRefused());
        $this->assertSame('max_messages_reached', $refused->reason);
        $this->assertSame(1, AiProviderInvocation::count());
        $this->assertSame(2, GuestMessage::count(), 'le second message n\'est pas enregistre');
    }

    // ── 4. Prompt absent : fail-closed ─────────────────────────────────────

    public function test_without_an_active_prompt_in_db_the_turn_is_refused_before_anything_happens(): void
    {
        GuestShellAgent::fake([new TextResponse('jamais', new Usage(1, 1), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->update(['is_active' => false]);

        $turn = $this->respond('Bonjour');

        $this->assertTrue($turn->isRefused());
        $this->assertSame('no_active_prompt', $turn->reason);
        GuestShellAgent::assertNeverPrompted();
        $this->assertSame(0, AiProviderInvocation::count());
        $this->assertSame(0, GuestMessage::count());
    }

    // ── 5. L'historique de CETTE conversation, et d'aucune autre ───────────

    public function test_the_model_sees_the_bounded_history_of_this_conversation_only(): void
    {
        GuestShellAgent::fake([
            new TextResponse('Nous aidons les freelances.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini')),
            new TextResponse('Oui, gratuitement pour commencer.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
        // Une autre conversation du meme visiteur : son contenu ne fuit pas dans celle-ci.
        $other = $this->conversations->start($this->visitor);
        $this->conversations->acceptUserMessage($other, 'SECRET-AUTRE-CONVERSATION');

        $this->assertTrue($this->respond('Que faites-vous ?')->isAnswered());
        $this->assertTrue($this->respond('Est-ce gratuit ?')->isAnswered());

        GuestShellAgent::assertPrompted(function (AgentPrompt $p) {
            return str_contains($p->prompt, 'Visiteur : Que faites-vous ?')
                && str_contains($p->prompt, 'Assistant : Nous aidons les freelances.')
                && str_contains($p->prompt, 'Visiteur : Est-ce gratuit ?')
                && ! str_contains($p->prompt, 'SECRET-AUTRE-CONVERSATION');
        });
        $this->assertSame(2, AiProviderInvocation::count());
        $this->assertSame(2, $this->conversation->fresh()->message_count);
    }
}
