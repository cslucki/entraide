<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\CapabilityRegistry;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\ShellGeneralAnswerService;
use App\Support\Ai\AiShellPageContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1526 — le Shell route les questions generales sans detourner la
 * capability de clarification d'entraide.
 */
class TASK1526ShellGeneralRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-shell-routing',
            'name' => 'Org Shell Routing',
        ]);
        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1526-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    public function test_a_general_question_uses_the_general_capability_and_never_the_clarifier(): void
    {
        $this->fakeGeneral('Paris est la capitale de la France.');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Quelle est la capitale de la France ?');

        $answer = $this->lastAnswer();
        $interaction = AiInteraction::query()->sole();
        $invocation = AiProviderInvocation::query()->sole();

        $this->assertSame('Paris est la capitale de la France.', $answer->content);
        $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $answer->metadata['status']);
        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $answer->metadata['producer']);
        $this->assertSame(CapabilityRegistry::SHELL_GENERAL_ANSWER, $interaction->feature);
        $this->assertSame('help_request.clarify', $interaction->process,
            'Le routage conserve le seau economique historique du Shell.');
        $this->assertSame(CapabilityRegistry::SHELL_GENERAL_ANSWER, $invocation->capability);

        ShellGeneralAnswerAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Quelle est la capitale de la France ?')
        );
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_an_explicit_interpersonal_help_question_stays_on_clarify(): void
    {
        ShellGeneralAnswerAgent::fake([]);
        $this->fakeClarifier();

        $questions = [
            "Qui peut m'aider à trouver un relecteur ?",
            "Quelqu'un peut m'aider à relire ce dossier ?",
            "Peux-tu m'aider à structurer ma demande ?",
            'Pouvez-vous me mettre en relation avec un expert ?',
            'Can someone help me review this file?',
            'Can you help me find a reviewer?',
        ];

        foreach ($questions as $question) {
            $this->send($question);
        }

        $answer = $this->lastAnswer();

        $this->assertSame('laravel_ai_sdk', $answer->metadata['producer']);
        $this->assertSame(
            array_fill(0, count($questions), 'clarify_help_request'),
            AiInteraction::query()->orderBy('created_at')->orderBy('id')->pluck('feature')->all(),
        );
        ShellGeneralAnswerAgent::assertNeverPrompted();
        HelpRequestClarifierAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Can someone help me review this file?')
        );
    }

    public function test_a_non_question_keeps_the_historical_clarification_path(): void
    {
        ShellGeneralAnswerAgent::fake([]);
        $this->fakeClarifier();

        $this->send('Je cherche un relecteur pour mon dossier Erasmus.');

        $this->assertSame('clarify_help_request', AiInteraction::query()->sole()->feature);
        ShellGeneralAnswerAgent::assertNeverPrompted();
    }

    public function test_the_general_capability_has_no_documentary_or_loop_source(): void
    {
        $definition = app(CapabilityRegistry::class)->get(CapabilityRegistry::SHELL_GENERAL_ANSWER);

        $this->assertSame([CapabilityRegistry::SOURCE_PRODUCT_SURFACES], $definition->allowedSources);
        $this->assertNotContains(CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL, $definition->allowedSources);
        $this->assertNotContains(CapabilityRegistry::SOURCE_DOSSIER_MANIFEST, $definition->allowedSources);
        $this->assertNotContains(CapabilityRegistry::SOURCE_BLOG_POST, $definition->allowedSources);
        $this->assertNotContains(CapabilityRegistry::SOURCE_LOOP_MESSAGES, $definition->allowedSources);
        $this->assertFalse($definition->canWrite);
        $this->assertFalse($definition->requiresHumanConfirmation);
    }

    public function test_a_general_answer_cannot_display_a_fabricated_document_citation(): void
    {
        $this->fakeGeneral('Paris est la capitale. [S1](secret.pdf) Autre assertion [M9].');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Quelle est la capitale de la France ?');

        $answer = $this->lastAnswer();

        $this->assertSame('Paris est la capitale. Autre assertion.', $answer->content);
        $this->assertArrayNotHasKey('grounded', $answer->metadata);
        $this->assertArrayNotHasKey('sources', $answer->metadata);
        $this->assertStringNotContainsString('[S1]', (string) AiInteraction::query()->sole()->response);
    }

    public function test_general_follow_up_reuses_the_same_shell_thread_and_memory(): void
    {
        $this->fakeGeneral('La premiere reponse.', 'Parce que la diffusion produit cet effet.');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Pourquoi le ciel est-il bleu ?');
        $this->send('Et pourquoi cet effet varie-t-il ?');

        $messages = AiShellMessage::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(4, $messages);
        $this->assertCount(1, $messages->pluck('conversation_id')->unique());

        $secondPrompt = (string) AiInteraction::query()
            ->where('feature', CapabilityRegistry::SHELL_GENERAL_ANSWER)
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail()->prompt;

        $this->assertStringContainsString('Pourquoi le ciel est-il bleu ?', $secondPrompt);
        $this->assertStringContainsString('La premiere reponse.', $secondPrompt);
        $this->assertStringContainsString('Et pourquoi cet effet varie-t-il ?', $secondPrompt);
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_an_unconfigured_general_question_never_falls_back_to_clarify(): void
    {
        OrganizationAiSetting::query()->delete();
        ShellGeneralAnswerAgent::fake([]);
        $this->fakeClarifier();

        $this->send('Quel temps fait-il à Marseille ?');

        $answer = $this->lastAnswer();
        $this->assertSame(AiShellResponder::STATUS_UNAVAILABLE, $answer->metadata['status']);
        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $answer->metadata['producer']);
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
        ShellGeneralAnswerAgent::assertNeverPrompted();
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_an_empty_provider_answer_is_unavailable_but_still_ledgered_once(): void
    {
        $this->fakeGeneral('   ');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Pourquoi cette réponse est-elle vide ?');

        $answer = $this->lastAnswer();
        $interaction = AiInteraction::query()->sole();
        $invocation = AiProviderInvocation::query()->sole();

        $this->assertSame(AiShellResponder::STATUS_UNAVAILABLE, $answer->metadata['status']);
        $this->assertSame('failed', $interaction->metadata['status']);
        $this->assertSame('failed', $invocation->status);
        $this->assertSame(CapabilityRegistry::SHELL_GENERAL_ANSWER, $invocation->capability);
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_cross_tenant_requester_is_refused_before_any_provider_or_trace(): void
    {
        $other = Organization::factory()->create();
        $outsider = User::factory()->create(['organization_id' => $other->id]);
        ShellGeneralAnswerAgent::fake([]);

        $this->expectException(DomainException::class);

        try {
            app(ShellGeneralAnswerService::class)->answer(
                $this->organization,
                $outsider,
                'Quelle est la capitale de la France ?',
            );
        } finally {
            $this->assertSame(0, AiInteraction::query()->count());
            $this->assertSame(0, AiProviderInvocation::query()->count());
            ShellGeneralAnswerAgent::assertNeverPrompted();
        }
    }

    public function test_english_interface_composes_english_general_instructions(): void
    {
        app()->setLocale('en');
        $this->fakeGeneral('The answer is bounded.');
        HelpRequestClarifierAgent::fake([]);

        $this->send('How does this work?');

        ShellGeneralAnswerAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->agent->instructions(), 'Answer the current question directly')
            && str_contains($prompt->prompt, 'Answer in English')
        );
    }

    private function send(string $question): void
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->member,
            $this->organization,
            AiShellPageContext::KIND_DASHBOARD,
            null,
            'organization.dashboard',
        );

        app(AiShellResponder::class)->respond($this->organization, $this->member, $question, $context);
    }

    private function lastAnswer(): AiShellMessage
    {
        return AiShellMessage::query()
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    }

    private function fakeGeneral(string ...$answers): void
    {
        ShellGeneralAnswerAgent::fake(array_map(
            static fn (string $answer): TextResponse => new TextResponse(
                $answer,
                new Usage(80, 30),
                new Meta('openai', 'gpt-4o-mini'),
            ),
            $answers,
        ));
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'interaction_fit' => true,
            'direct_reply' => '',
            'title' => 'Relecture Erasmus',
            'clarified_request' => 'Je cherche un relecteur pour mon dossier Erasmus.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openai', 'gpt-4o-mini'),
        ));
    }
}
