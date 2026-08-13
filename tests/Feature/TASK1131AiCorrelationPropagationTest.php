<?php

namespace Tests\Feature;

use App\Livewire\InlineMemberAgent;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use App\Services\Ai\Providers\LoggingSupervisionProvider;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use App\Support\Ai\AiCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1131 / IA P1-1 — propagation synchrone de la corrélation.
 *
 * Couvre :
 * - B : une opération produit une correlation_id non vide ;
 * - C : deux opérations distinctes → deux correlation_id distinctes ;
 * - D : les écritures d'une même opération partagent la même correlation_id ;
 * - H : aucune collision entre deux Organizations.
 */
class TASK1131AiCorrelationPropagationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = (new LoopService)->createLoop($this->owner, 'Boucle TASK-1131');

        config(['ai.openai.api_key' => 'test-key']);
        config(['ai.chatloop.min_summary_words' => 0]);

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => "Reponse de l'IA."]]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 18],
            ]),
        ]);
    }

    /**
     * Frontière d'opération, telle que la production la produit :
     * - en FPM, chaque requête HTTP démarre sur une application neuve ;
     * - sous Octane, la requête réinitialise les instances de façade et les
     *   liaisons `scoped` du conteneur ;
     * - dans un worker de queue, `Illuminate\Queue\Worker` réinitialise les
     *   liaisons `scoped` avant chaque job.
     *
     * Le dépôt de contexte qui porte la corrélation est une liaison `scoped` :
     * après cette frontière, l'opération suivante repart donc de zéro.
     */
    private function startNewOperation(): void
    {
        Facade::clearResolvedInstance(ContextRepository::class);
        app()->forgetScopedInstances();
    }

    /**
     * B — l'opération produit une corrélation non vide, et un `process`
     * technique cohérent avec le scénario.
     */
    public function test_an_operation_writes_a_non_empty_correlation_id(): void
    {
        app(ChatLoopAiService::class)->answer($this->loop, $this->owner);

        $interaction = AiInteraction::query()->latest('created_at')->firstOrFail();

        $this->assertNotNull($interaction->correlation_id);
        $this->assertNotSame('', $interaction->correlation_id);
        $this->assertTrue(Str::isUuid($interaction->correlation_id));
        $this->assertSame('chatloop.answer', $interaction->process);
    }

    /**
     * C — deux opérations distinctes produisent deux corrélations distinctes.
     */
    public function test_two_distinct_operations_produce_two_distinct_correlation_ids(): void
    {
        $this->startNewOperation();
        app(ChatLoopAiService::class)->answer($this->loop, $this->owner);

        $this->startNewOperation();
        app(ChatLoopAiService::class)->answer($this->loop, $this->owner);

        $correlationIds = AiInteraction::query()->pluck('correlation_id');

        $this->assertCount(2, $correlationIds);
        $this->assertCount(2, $correlationIds->unique(), 'Two operations must not share a correlation.');
        $this->assertNotContains(null, $correlationIds->all());
    }

    /**
     * D — deux écritures de la MÊME opération, dans deux tables différentes,
     * partagent la même corrélation. L'agent inline écrit à la fois dans
     * `admin_ai_interactions` et `member_ai_profile_interactions`.
     */
    public function test_writes_of_the_same_operation_share_one_correlation_id(): void
    {
        $visitor = User::factory()->create(['organization_id' => $this->organization->id]);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'skills' => ['SEO', 'Redaction'],
        ]);

        app()->instance('current_organization', $this->organization);

        $this->startNewOperation();

        Livewire::actingAs($visitor)
            ->test(InlineMemberAgent::class, ['user' => $this->owner])
            ->set('question', 'Quelles competences ?')
            ->call('askQuestion');

        $adminRow = AdminAiInteraction::query()->firstOrFail();
        $memberRow = MemberAiProfileInteraction::query()->firstOrFail();

        $this->assertNotNull($adminRow->correlation_id);
        $this->assertSame(
            $adminRow->correlation_id,
            $memberRow->correlation_id,
            'Both writes belong to a single BouclePro operation.'
        );

        $this->assertSame('member_profile.inline_presentation', $adminRow->process);
        $this->assertSame('member_profile.inline_presentation', $memberRow->process);
    }

    /**
     * D bis — cœur du contrat : `correlation_id` désigne UNE OPÉRATION, pas un
     * appel LLM. Deux appels IA successifs d'une même opération, persistés par
     * le point d'écriture partagé, portent donc la même corrélation — chacun
     * avec son propre `process`.
     */
    public function test_several_ai_calls_of_one_operation_share_the_correlation(): void
    {
        $this->actingAs($this->owner);

        $persistence = app(AdminAiInteractionPersistence::class);

        $this->startNewOperation();

        $persistence->persist([
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'status' => 'success',
            'content' => 'premier appel',
        ]);

        $persistence->persist([
            'scenario_id' => 'clarify_help_request',
            'provider' => 'openai',
            'status' => 'success',
            'content' => 'second appel',
        ]);

        $rows = AdminAiInteraction::query()->orderBy('scenario_id')->get();

        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->correlation_id);
        $this->assertTrue(Str::isUuid($rows[0]->correlation_id));
        $this->assertSame(
            $rows[0]->correlation_id,
            $rows[1]->correlation_id,
            'Several LLM calls of one BouclePro operation share one correlation.'
        );

        $this->assertSame('help_request.clarify', $rows->firstWhere('scenario_id', 'clarify_help_request')->process);
        $this->assertSame('supervision.content', $rows->firstWhere('scenario_id', 'supervision_content')->process);
    }

    /**
     * D ter — le décorateur de supervision réellement câblé dans
     * `AppServiceProvider` propage bien la corrélation, et deux opérations
     * distinctes restent séparées.
     */
    public function test_supervision_decorator_traces_the_current_operation(): void
    {
        $this->actingAs($this->owner);

        $provider = new LoggingSupervisionProvider(
            $this->fakeSupervisionProvider(),
            app(AiBenchmarkLogger::class),
            app(AdminAiInteractionPersistence::class),
            'openai',
        );

        $this->startNewOperation();
        $provider->supervise('contenu a superviser');
        $firstCorrelation = AdminAiInteraction::query()->firstOrFail()->correlation_id;

        $this->startNewOperation();
        $provider->supervise('autre contenu a superviser');

        $correlations = AdminAiInteraction::query()->pluck('correlation_id');

        $this->assertCount(2, $correlations);
        $this->assertNotNull($firstCorrelation);
        $this->assertCount(2, $correlations->unique(), 'Two operations must not share a correlation.');
        $this->assertSame(
            ['supervision.content'],
            AdminAiInteraction::query()->pluck('process')->unique()->values()->all()
        );
    }

    private function fakeSupervisionProvider(): SupervisionProvider
    {
        return new class implements SupervisionProvider
        {
            public function supervise(string $content, ?string $model = null): AiSupervisionResult
            {
                return new AiSupervisionResult(
                    summary: 'resume',
                    riskLevel: 'low',
                    category: ['slug' => 'divers', 'label' => 'Divers'],
                    skills: [],
                    unmatchedTerms: [],
                    needsHumanCategoryReview: false,
                    categoryReviewReason: '',
                    recommendations: [],
                    moderationFlag: false,
                    notes: '',
                    inputTokens: 10,
                    outputTokens: 20,
                    model: 'gpt-4o-mini',
                    estimatedCostUsd: 0.000015,
                    latencyMs: 5,
                );
            }

            public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array
            {
                return ['summary' => 'resume'];
            }
        };
    }

    /**
     * C bis — la corrélation change bien d'une opération à l'autre au niveau
     * du support lui-même, pas seulement en base.
     */
    public function test_a_new_operation_scope_yields_a_new_correlation(): void
    {
        $first = AiCorrelation::id();
        $this->assertSame($first, AiCorrelation::id(), 'Within one operation the correlation is stable.');

        $this->startNewOperation();

        $second = AiCorrelation::id();

        $this->assertNotSame($first, $second);
    }

    /**
     * H — deux Organizations, deux opérations : aucune corrélation partagée,
     * et la corrélation ne franchit aucune frontière de tenant.
     *
     * `correlation_id` sert à TRACER : elle n'autorise rien, elle ne remplace
     * aucun scope. Le test vérifie donc aussi que chaque trace reste rattachée
     * à son Organization.
     */
    public function test_two_organizations_never_share_a_correlation_id(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherOwner = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Boucle autre tenant');

        $this->startNewOperation();
        app(ChatLoopAiService::class)->answer($this->loop, $this->owner);

        $this->startNewOperation();
        app(ChatLoopAiService::class)->answer($otherLoop, $otherOwner);

        $ours = AiInteraction::query()
            ->where('organization_id', $this->organization->id)
            ->pluck('correlation_id');

        $theirs = AiInteraction::query()
            ->where('organization_id', $otherOrganization->id)
            ->pluck('correlation_id');

        $this->assertCount(1, $ours);
        $this->assertCount(1, $theirs);
        $this->assertNotNull($ours->first());
        $this->assertNotNull($theirs->first());
        $this->assertEmpty(array_intersect($ours->all(), $theirs->all()));
    }
}
