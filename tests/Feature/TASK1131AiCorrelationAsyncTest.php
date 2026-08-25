<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\PromptRepository;
use App\Jobs\GenerateAiAgentResponse;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use App\Services\Ai\SupervisionProviderResolver;
use App\Services\LoopMessageService;
use App\Support\Ai\AiCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1131 / IA P1-1 — propagation asynchrone de la corrélation.
 *
 * Couvre :
 * - F : propagation dans `GenerateAiAgentResponse` ;
 * - G : retry du job — pas de nouvelle corrélation arbitraire ;
 * - non-mélange entre deux jobs.
 *
 * Contrat vérifié : la corrélation est créée au DISPATCH (opération d'origine),
 * sérialisée avec le job, puis réadoptée à l'exécution. Elle n'est jamais
 * recréée par le worker.
 */
class TASK1131AiCorrelationAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private User $visitor;

    private MemberAiProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->visitor = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'skills' => ['SEO', 'Redaction'],
        ]);

        Http::preventStrayRequests();

        // Réponse déterministe : cette TASK ne change ni provider, ni modèle,
        // ni prompt. Seule la corrélation est sous test.
        $this->instance(MemberProfileAgentResponder::class, new class(app(SupervisionProviderResolver::class), app(AdminAiInteractionPersistence::class), app(CapabilityRegistry::class), app(PromptRepository::class), app(ContextBuilder::class)) extends MemberProfileAgentResponder
        {
            public function answerWithDefaultProvider(
                MemberAiProfile $profile,
                string $question,
                string $scenarioId = 'profile_agent_master',
            ): array {
                return [
                    'response' => 'Reponse deterministe de test.',
                    'fields' => ['skills'],
                    'provider' => 'fake',
                    'model' => 'fake-model',
                    'latency_ms' => 7,
                ];
            }
        });
    }

    private function aiAgentLoop(): Loop
    {
        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->member))
            ->assertRedirect();

        return Loop::query()->where('type', 'ai_agent')->firstOrFail();
    }

    private function visitorMessage(Loop $loop, string $body = 'Quelles competences ?'): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $this->visitor->id,
            'body' => $body,
            'type' => 'user',
            'organization_id' => $loop->organization_id,
        ]);
    }

    /**
     * Frontière d'exécution du worker : `Illuminate\Queue\Worker` réinitialise
     * les liaisons `scoped` du conteneur avant chaque job, et le contexte est
     * purgé puis réhydraté depuis le payload (`Repository::hydrate()` appelle
     * `flush()`). Sans réadoption explicite de la corrélation du dispatch, le
     * job repartirait donc sur une corrélation neuve.
     */
    private function enterWorkerScope(): void
    {
        Facade::clearResolvedInstance(ContextRepository::class);
        app()->forgetScopedInstances();
    }

    /**
     * F.1 — la corrélation est figée au dispatch, dans l'opération d'origine
     * (le message posté par le visiteur), pas à l'exécution du job.
     */
    public function test_dispatch_captures_the_correlation_of_the_origin_operation(): void
    {
        $loop = $this->aiAgentLoop();

        Queue::fake();

        $originCorrelation = AiCorrelation::id();

        app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Quelles competences ?');

        Queue::assertPushed(
            GenerateAiAgentResponse::class,
            fn (GenerateAiAgentResponse $job) => $job->correlationId === $originCorrelation
        );
    }

    /**
     * F.2 — cœur du critère : le job écrit la corrélation reçue au dispatch,
     * alors même qu'il s'exécute dans un scope neuf.
     */
    public function test_job_writes_the_dispatch_correlation_not_a_fresh_one(): void
    {
        $loop = $this->aiAgentLoop();
        $message = $this->visitorMessage($loop);

        $originCorrelation = AiCorrelation::id();
        $job = new GenerateAiAgentResponse($loop, $message);

        $this->assertSame($originCorrelation, $job->correlationId);

        $this->enterWorkerScope();
        $this->assertNull(AiCorrelation::peek(), 'The worker scope starts without any correlation.');

        $job->handle(app(MemberProfileAgentResponder::class));

        $this->assertDatabaseHas('member_ai_profile_interactions', [
            'member_ai_profile_id' => $this->profile->id,
            'correlation_id' => $originCorrelation,
            'process' => 'member_profile.loop_agent_reply',
        ]);
    }

    /**
     * F.3 — sérialisation : la corrélation survit au passage par la queue.
     */
    public function test_correlation_survives_job_serialization(): void
    {
        $loop = $this->aiAgentLoop();
        $message = $this->visitorMessage($loop);

        $originCorrelation = AiCorrelation::id();
        $job = new GenerateAiAgentResponse($loop, $message);

        /** @var GenerateAiAgentResponse $restored */
        $restored = unserialize(serialize($job));

        $this->assertSame($originCorrelation, $restored->correlationId);
        $this->assertTrue(Str::isUuid($restored->correlationId));
    }

    /**
     * G — un retry rejoue le même payload sérialisé : la corrélation de
     * l'opération est conservée, aucune nouvelle corrélation n'est inventée.
     */
    public function test_job_retry_keeps_the_same_correlation(): void
    {
        $loop = $this->aiAgentLoop();
        $message = $this->visitorMessage($loop);

        $originCorrelation = AiCorrelation::id();
        $job = new GenerateAiAgentResponse($loop, $message);

        // 1re tentative
        $this->enterWorkerScope();
        $job->handle(app(MemberProfileAgentResponder::class));

        // Retry : Laravel repousse le même payload sérialisé.
        /** @var GenerateAiAgentResponse $retried */
        $retried = unserialize(serialize($job));

        $this->enterWorkerScope();
        $retried->handle(app(MemberProfileAgentResponder::class));

        $correlations = MemberAiProfileInteraction::query()->pluck('correlation_id');

        $this->assertCount(2, $correlations, 'Both attempts wrote a trace.');
        $this->assertCount(1, $correlations->unique(), 'A retry must not create a new correlation.');
        $this->assertSame($originCorrelation, $correlations->first());
    }

    /**
     * Non-mélange — deux opérations distinctes produisent deux jobs et deux
     * corrélations, y compris lorsqu'ils s'exécutent l'un après l'autre dans
     * le même worker.
     */
    public function test_two_jobs_never_mix_their_correlations(): void
    {
        $loop = $this->aiAgentLoop();

        $firstCorrelation = AiCorrelation::start();
        $firstJob = new GenerateAiAgentResponse($loop, $this->visitorMessage($loop, 'Premiere question ?'));

        // Nouvelle opération utilisateur → nouvelle corrélation.
        $secondCorrelation = AiCorrelation::start();
        $secondJob = new GenerateAiAgentResponse($loop, $this->visitorMessage($loop, 'Deuxieme question ?'));

        $this->assertNotSame($firstCorrelation, $secondCorrelation);

        $this->enterWorkerScope();
        $firstJob->handle(app(MemberProfileAgentResponder::class));

        $this->enterWorkerScope();
        $secondJob->handle(app(MemberProfileAgentResponder::class));

        $correlations = MemberAiProfileInteraction::query()
            ->orderBy('created_at')
            ->pluck('correlation_id', 'question');

        $this->assertSame($firstCorrelation, $correlations['Premiere question ?']);
        $this->assertSame($secondCorrelation, $correlations['Deuxieme question ?']);
    }

    /**
     * Échec — un job qui sort tôt (l'auteur du message est le propriétaire du
     * profil) n'écrit aucune trace et ne pollue pas la corrélation suivante.
     */
    public function test_early_returning_job_writes_nothing(): void
    {
        $loop = $this->aiAgentLoop();

        $ownMessage = LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message du proprietaire du profil.',
            'type' => 'user',
            'organization_id' => $loop->organization_id,
        ]);

        $job = new GenerateAiAgentResponse($loop, $ownMessage);

        $this->enterWorkerScope();
        $job->handle(app(MemberProfileAgentResponder::class));

        $this->assertSame(0, MemberAiProfileInteraction::query()->count());
    }
}
