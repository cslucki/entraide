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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1527 — la reponse generale du Shell aide vraiment les taches
 * conversationnelles ordinaires, sans devenir un RAG ni une Interaction.
 */
class TASK1527ShellGeneralConversationTasksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-shell-general-tasks',
            'name' => 'Org Shell General Tasks',
        ]);
        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1527-'.$this->organization->id,
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

    public function test_missing_text_for_reformulation_asks_for_the_missing_input_instead_of_refusing(): void
    {
        $this->fakeGeneral('Bien sur. Collez le texte a reformuler, et je vous proposerai une version plus claire.');
        HelpRequestClarifierAgent::fake([]);

        $this->send("Peux-tu m'aider à reformuler ce texte ?");

        $this->assertLastGeneralAnswerContains('Collez le texte');
        $this->assertGeneralCapabilityWasUsed();
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_general_capability_performs_reformulation_summary_comparison_and_brainstorming(): void
    {
        $this->fakeGeneral(
            'Organisons une reunion pour discuter ensemble des prochaines etapes du projet.',
            'La note explique comment prioriser les actions avec une validation humaine.',
            "Approche A: plus rapide mais moins robuste.\nApproche B: plus lente mais plus stable.",
            "1. Clarifier le message central.\n2. Raccourcir les diapositives.\n3. Ajouter une conclusion actionnable.",
        );
        HelpRequestClarifierAgent::fake([]);

        $questions = [
            "Reformule ceci de manière plus concise :\nNous souhaitons organiser une réunion afin de pouvoir discuter ensemble des prochaines étapes du projet.",
            'Résume en une phrase : Cette note présente une méthode pour prioriser les prochaines actions du projet tout en gardant une validation humaine.',
            'Aide-moi à comparer ces deux approches : A va vite avec peu de contrôle. B prend plus de temps mais vérifie chaque étape.',
            'Donne-moi trois idées pour améliorer cette présentation.',
        ];

        foreach ($questions as $question) {
            $this->send($question);
        }

        $answers = AiShellMessage::query()
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('content')
            ->all();

        $this->assertSame([
            'Organisons une reunion pour discuter ensemble des prochaines etapes du projet.',
            'La note explique comment prioriser les actions avec une validation humaine.',
            "Approche A: plus rapide mais moins robuste.\nApproche B: plus lente mais plus stable.",
            "1. Clarifier le message central.\n2. Raccourcir les diapositives.\n3. Ajouter une conclusion actionnable.",
        ], $answers);
        $this->assertSame(
            array_fill(0, count($questions), CapabilityRegistry::SHELL_GENERAL_ANSWER),
            AiInteraction::query()->orderBy('created_at')->orderBy('id')->pluck('feature')->all(),
        );
        ShellGeneralAnswerAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Donne-moi trois idées pour améliorer cette présentation.'));
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_boucle_organization_doctrine_reaches_the_general_answer_contract(): void
    {
        $this->fakeGeneral("Une Organization est le Tenant. Une Boucle est un espace collaboratif interne a l'Organization.");
        HelpRequestClarifierAgent::fake([]);

        $this->send('Quelle est la différence entre une Boucle et une Organisation ?');

        $answer = $this->lastAnswer();

        $this->assertStringContainsString('Organization', $answer->content);
        $this->assertStringContainsString('Tenant', $answer->content);
        $this->assertStringContainsString('Boucle', $answer->content);
        ShellGeneralAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'Organization = Tenant')
                && str_contains($instructions, 'Loop')
                && str_contains($instructions, 'jamais un Tenant')
                && str_contains($instructions, 'ne se limitent pas à des activités pédagogiques');
        });
    }

    public function test_human_help_routing_still_uses_clarify_help_request(): void
    {
        ShellGeneralAnswerAgent::fake([]);
        $this->fakeClarifier();

        $questions = [
            "Quelqu'un peut m'aider à trouver un expert Horizon Europe ?",
            'Can someone help me find a partner?',
            'Qui peut relire mon dossier ?',
        ];

        foreach ($questions as $question) {
            $this->send($question);
        }

        $this->assertSame(
            array_fill(0, count($questions), CapabilityRegistry::CLARIFY_HELP_REQUEST),
            AiInteraction::query()->orderBy('created_at')->orderBy('id')->pluck('feature')->all(),
        );
        ShellGeneralAnswerAgent::assertNeverPrompted();
        HelpRequestClarifierAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Qui peut relire mon dossier ?'));
    }

    public function test_understanding_aria_outside_document_context_remains_general_not_clarify(): void
    {
        $this->fakeGeneral('ARIA peut etre explique de facon generale, sans consulter un Dossier.');
        HelpRequestClarifierAgent::fake([]);

        $this->send("Peux-tu m'aider à comprendre ARIA ?");

        $this->assertLastGeneralAnswerContains('sans consulter un Dossier');
        $this->assertGeneralCapabilityWasUsed();
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_general_answer_contract_stays_non_documentary_and_strips_fake_citations(): void
    {
        $this->fakeGeneral('Voici une reponse utile [S1](secret.pdf) avec une fausse source [M3].');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Résume en une phrase : un texte court a résumer.');

        $answer = $this->lastAnswer();
        $interaction = AiInteraction::query()->sole();
        $invocation = AiProviderInvocation::query()->sole();

        $this->assertSame('Voici une reponse utile avec une fausse source.', $answer->content);
        $this->assertArrayNotHasKey('grounded', $answer->metadata);
        $this->assertArrayNotHasKey('sources', $answer->metadata);
        $this->assertSame(CapabilityRegistry::SHELL_GENERAL_ANSWER, $interaction->feature);
        $this->assertSame(CapabilityRegistry::SHELL_GENERAL_ANSWER, $invocation->capability);
        $this->assertStringNotContainsString('[S1]', (string) $interaction->response);
        $this->assertSame([CapabilityRegistry::SOURCE_PRODUCT_SURFACES], app(CapabilityRegistry::class)->get(CapabilityRegistry::SHELL_GENERAL_ANSWER)->allowedSources);
        ShellGeneralAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'non documentaire')
                && str_contains($instructions, 'aucun Dossier ou Article')
                && str_contains($instructions, 'aucune citation [S1]/[M1]');
        });
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function assertLastGeneralAnswerContains(string $expected): void
    {
        $answer = $this->lastAnswer();

        $this->assertStringContainsString($expected, $answer->content);
        $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $answer->metadata['status']);
        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $answer->metadata['producer']);
    }

    private function assertGeneralCapabilityWasUsed(): void
    {
        $this->assertSame(CapabilityRegistry::SHELL_GENERAL_ANSWER, AiInteraction::query()->sole()->feature);
        $this->assertSame(CapabilityRegistry::SHELL_GENERAL_ANSWER, AiProviderInvocation::query()->sole()->capability);
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
            'title' => 'Relecture Horizon Europe',
            'clarified_request' => 'Je cherche un relecteur pour mon dossier.',
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
