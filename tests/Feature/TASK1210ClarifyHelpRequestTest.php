<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\UserLoopsSource;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\DTO\AssistedInteractionLabResult;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Tests\TestCase;

/**
 * « Qui peut m'aider ? » — clarification IA et suggestion de Boucle
 * (TASK-1210 / IA P3).
 *
 * L'IA propose. L'humain publie. Rien n'est jamais publie sans un clic.
 */
class TASK1210ClarifyHelpRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private Loop $loop;

    private Loop $ethique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->member, 'Boucle principale');
        $this->ethique = $loops->createLoop($this->member, 'IA & Éthique');
        $this->ethique->update(['tagline' => 'Décider durablement de nos usages de l’IA.', 'type' => 'project']);

        app()->instance('current_organization', $this->organization);

        AiConfig::set('clarification_enabled', true);
        config([
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Clarification
    // =====================================================================

    public function test_a_vague_intention_becomes_a_structured_request(): void
    {
        $this->fakeClarifier();

        $result = $this->clarify('jai besoin daide sur lia et lethique jsais pas trop');

        $this->assertSame('Cadrer nos usages de l’IA', $result->title);
        $this->assertStringContainsString('éthique', $result->need);
        $this->assertTrue($result->humanValidation['required']);
    }

    public function test_a_malformed_sdk_answer_degrades_without_breaking(): void
    {
        // Charge REELLEMENT malformee : aucun des champs attendus.
        $this->fakeRawClarifier(['unexpected' => 'shape']);

        $result = $this->clarify('une intention quelconque');

        // Aucun champ inventé : titre de repli, pas de suggestion, relecture
        // humaine exigée.
        $this->assertSame('Nouvelle demande', $result->title);
        $this->assertNull($result->suggestedLoop);
        $this->assertTrue($result->needsFallback());
    }

    public function test_an_sdk_failure_falls_back_to_the_deterministic_clarifier(): void
    {
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('provider down');
        });

        $result = $this->clarify('Je cherche des conseils pour trouver mes premiers clients');

        $this->assertNotSame('', $result->title);
        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('failed', $interaction->metadata['status']);
        $this->assertNull($interaction->cost_usd);
        $this->assertNull($interaction->cost_unknown);
    }

    // =====================================================================
    // B. Suggestion de Boucle — validée côté serveur
    // =====================================================================

    public function test_a_suggestion_taken_from_the_offered_loops_is_accepted(): void
    {
        $this->fakeClarifier(['suggested_loop_id' => $this->ethique->id]);

        $result = $this->clarify('aide sur l’éthique de l’IA');

        $this->assertSame($this->ethique->id, $result->suggestedLoop['id']);
        $this->assertSame('IA & Éthique', $result->suggestedLoop['label']);
        $this->assertNotEmpty($result->suggestedLoop['reason']);
    }

    public function test_an_invented_loop_id_is_rejected(): void
    {
        $this->fakeClarifier(['suggested_loop_id' => '11111111-1111-4111-8111-111111111111']);

        $this->assertNull($this->clarify('une intention')->suggestedLoop);
    }

    public function test_a_loop_of_another_organization_is_rejected(): void
    {
        $autreProprio = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $ailleurs = (new LoopService)->createLoop($autreProprio, 'Boucle ailleurs');

        $this->fakeClarifier(['suggested_loop_id' => $ailleurs->id]);

        $this->assertNull($this->clarify('une intention')->suggestedLoop);
    }

    public function test_a_loop_the_member_does_not_belong_to_is_rejected(): void
    {
        $tiers = User::factory()->create(['organization_id' => $this->organization->id]);
        $nonMembre = (new LoopService)->createLoop($tiers, 'Boucle des autres');

        $this->fakeClarifier(['suggested_loop_id' => $nonMembre->id]);

        $this->assertNull($this->clarify('une intention')->suggestedLoop);
    }

    public function test_no_suggestion_is_a_valid_outcome(): void
    {
        $this->fakeClarifier(['suggested_loop_id' => '', 'suggestion_reason' => '']);

        $this->assertNull($this->clarify('une intention')->suggestedLoop);
    }

    // =====================================================================
    // C. Contexte
    // =====================================================================

    public function test_only_the_member_loops_are_offered_to_the_model(): void
    {
        $tiers = User::factory()->create(['organization_id' => $this->organization->id]);
        (new LoopService)->createLoop($tiers, 'Boucle du catalogue');

        $this->fakeClarifier();
        $this->clarify('une intention');

        HelpRequestClarifierAgent::assertPrompted(function ($prompt): bool {
            $this->assertStringContainsString('BOUCLES AUTORISÉES', $prompt->prompt);
            $this->assertStringContainsString('IA & Éthique', $prompt->prompt);
            $this->assertStringNotContainsString('Boucle du catalogue', $prompt->prompt);

            return true;
        });
    }

    public function test_the_capability_declares_user_loops_only(): void
    {
        $definition = app(CapabilityRegistry::class)->get(CapabilityRegistry::CLARIFY_HELP_REQUEST);

        $this->assertTrue($definition->allowsSource(UserLoopsSource::NAME));
        $this->assertFalse($definition->allowsSource(CapabilityRegistry::SOURCE_LOOP_MESSAGES));
        $this->assertFalse($definition->canWrite);
        $this->assertTrue($definition->requiresHumanConfirmation);
        $this->assertSame('help_request.clarify', $definition->process);
    }

    public function test_loop_messages_never_leak_into_the_clarification_context(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'SECRET-CONVERSATION-INTERNE',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->fakeClarifier();
        $this->clarify('une intention');

        HelpRequestClarifierAgent::assertPrompted(
            fn ($prompt): bool => ! str_contains($prompt->prompt, 'SECRET-CONVERSATION-INTERNE')
        );
    }

    // =====================================================================
    // D. Human-in-the-loop
    // =====================================================================

    public function test_clarifying_publishes_nothing(): void
    {
        $this->fakeClarifier(['suggested_loop_id' => $this->ethique->id]);

        $this->clarify('aide sur l’éthique');

        $this->assertDatabaseCount('loop_messages', 0);
    }

    public function test_publishing_requires_an_explicit_click_and_lands_in_the_chosen_loop(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => 'Cadrer nos usages de l’IA',
                'need' => 'Je cherche de l’aide sur l’éthique de l’IA.',
                'help_type' => 'request',
                // L'utilisateur a retenu une AUTRE Boucle que celle d'où il part.
                'loop_id' => $this->ethique->id,
            ]);

        $response->assertRedirect();

        $message = LoopMessage::where('type', 'help_request')->firstOrFail();
        $this->assertSame($this->ethique->id, $message->loop_id);
        $this->assertSame($this->member->id, $message->sender_id);
        $this->assertSame('Cadrer nos usages de l’IA', $message->metadata['title']);
    }

    public function test_publishing_into_a_loop_the_member_left_is_refused(): void
    {
        $tiers = User::factory()->create(['organization_id' => $this->organization->id]);
        $interdite = (new LoopService)->createLoop($tiers, 'Interdite');

        $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => 'Un titre',
                'need' => 'Un besoin.',
                'help_type' => 'request',
                'loop_id' => $interdite->id,
            ])
            ->assertSessionHas('help_request_error');

        $this->assertDatabaseCount('loop_messages', 0);
    }

    public function test_publishing_into_another_organization_is_refused(): void
    {
        $autreProprio = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $ailleurs = (new LoopService)->createLoop($autreProprio, 'Ailleurs');
        LoopMember::create([
            'loop_id' => $ailleurs->id,
            'user_id' => $this->member->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => 'Un titre',
                'need' => 'Un besoin.',
                'help_type' => 'request',
                'loop_id' => $ailleurs->id,
            ])
            ->assertSessionHas('help_request_error');

        $this->assertDatabaseCount('loop_messages', 0);
    }

    public function test_nothing_is_published_in_the_organization_marketplace(): void
    {
        $this->actingAs($this->member)
            ->post(route('loops.help-request.publish', $this->loop), [
                'title' => 'Un titre',
                'need' => 'Un besoin.',
                'help_type' => 'service',
                'loop_id' => $this->loop->id,
            ]);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('services', 0);
    }

    // =====================================================================
    // E. IA — SDK, trace, corrélation
    // =====================================================================

    public function test_the_clarification_goes_through_the_sdk_without_direct_http(): void
    {
        $this->fakeClarifier();

        $this->clarify('une intention');

        HelpRequestClarifierAgent::assertPrompted(function ($prompt): bool {
            $this->assertSame('openai', $prompt->provider->name());
            $this->assertNotSame('', $prompt->model);

            return true;
        });

        Http::assertNothingSent();
    }

    public function test_the_trace_is_written_once_with_distinct_correlation_and_invocation(): void
    {
        $this->fakeClarifier();

        $this->clarify('une intention');

        $this->assertSame(1, AiInteraction::count());
        $interaction = AiInteraction::firstOrFail();

        $this->assertSame('help_request.clarify', $interaction->process);
        $this->assertSame($this->organization->id, $interaction->organization_id);
        $this->assertSame('clarify_help_request', $interaction->metadata['capability']);
        $this->assertNotSame(
            $interaction->correlation_id,
            $interaction->metadata['sdk_invocation_id'] ?? null,
        );
    }

    public function test_the_constitution_opens_the_final_prompt(): void
    {
        $this->fakeClarifier();

        $this->clarify('une intention');

        HelpRequestClarifierAgent::assertPrompted(
            fn ($prompt): bool => str_starts_with(
                (string) $prompt->agent->instructions(),
                'Constitution BouclePro IA — v1',
            )
        );
    }

    public function test_the_kill_switch_prevents_any_provider_call(): void
    {
        config(['ai.clarify.enabled' => false]);
        $this->fakeClarifier();

        $result = $this->clarify('Je cherche des conseils pour trouver mes premiers clients');

        HelpRequestClarifierAgent::assertNeverPrompted();
        $this->assertSame(0, AiInteraction::count());
        $this->assertNotSame('', $result->title);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function clarify(string $phrase): AssistedInteractionLabResult
    {
        return app(ClarifyUserHelpRequestService::class)
            ->clarifyForLoop($this->loop, $this->member, $phrase);
    }

    /**
     * Reponse structuree arbitraire, sans les defauts : sert a verifier ce que
     * le service fait d'une charge qui ne respecte pas le schema.
     *
     * @param  array<string, mixed>  $structured
     */
    private function fakeRawClarifier(array $structured): void
    {
        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse(
                $structured,
                json_encode($structured, JSON_UNESCAPED_UNICODE),
                new Usage(10, 5),
                new Meta('openai', 'gpt-4o-mini'),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     */
    private function fakeClarifier(?array $overrides = null): void
    {
        $structured = array_merge([
            'title' => 'Cadrer nos usages de l’IA',
            'clarified_request' => 'Je cherche de l’aide pour cadrer nos usages de l’IA sur le plan éthique.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggestion_reason' => 'Cette Boucle traite précisément de l’éthique de l’IA.',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ], $overrides ?? []);

        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse(
                $structured,
                json_encode($structured, JSON_UNESCAPED_UNICODE),
                new Usage(120, 80),
                new Meta('openai', 'gpt-4o-mini'),
            ),
        ]);
    }
}
