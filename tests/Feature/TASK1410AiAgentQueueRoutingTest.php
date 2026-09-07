<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiAgentResponse;
use App\Jobs\IndexDossierArticleChunks;
use App\Jobs\IndexDossierFileChunks;
use App\Jobs\SendNotificationEmail;
use App\Models\Loop;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationEmailDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1410 — la reponse de l'agent de profil part sur une file DEDIEE.
 *
 * Le defaut : `GenerateAiAgentResponse` n'appelait jamais `onQueue()`. Il
 * partait donc sur `default` — la file qui porte, sur la surface produit
 * `main`, des jobs historiques en quarantaine que personne n'a decide
 * d'executer.
 *
 * POURQUOI C'ETAIT BLOQUANT, et ce n'est pas une question d'hygiene. PROD
 * tourne en `QUEUE_CONNECTION=sync` : ce job s'execute aujourd'hui DANS la
 * requete du message poste, et l'utilisateur voit la reponse. Le pilote RAG
 * exigera `database`. Tant que ce job partait sur `default`, basculer imposait
 * de choisir entre deux maux :
 *   - ne pas ecouter `default` -> **plus AUCUNE reponse d'agent, en silence**,
 *     sans erreur ni log ;
 *   - l'ecouter -> **drainer les 207 jobs de la quarantaine**.
 * Cette file supprime le dilemme.
 *
 * Aggravant, et c'est la raison d'etre de ce fichier : `phpunit.xml` fixe
 * `QUEUE_CONNECTION=sync`. **Aucun test Feature ne peut detecter l'absence
 * d'un worker.** La regression ne serait donc attrapee par aucune CI — seule
 * une assertion explicite sur la DESTINATION du job la previent.
 *
 * `Queue::fake()` et non `Bus::fake()` : seul le premier intercepte au niveau
 * du QueueManager et expose la file de destination au callback.
 */
class TASK1410AiAgentQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private User $owner;

    private User $visitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Organization::factory()->create([
            'is_active' => true,
            'ai_profiles_enabled' => true,
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->tenant->id, 'first_name' => 'Maya']);
        $this->visitor = User::factory()->create(['organization_id' => $this->tenant->id, 'first_name' => 'Theo']);

        app()->instance('current_organization', $this->tenant);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'skills' => ['SEO'],
            'service_scope' => 'Audit SEO local',
            'member_profile_summary' => 'Consultante SEO',
        ]);

        Http::preventStrayRequests();
    }

    // ── 1 et 2. La file dediee, et plus jamais `default` ────────────────────

    public function test_a_visitor_message_pushes_the_agent_reply_on_the_dedicated_queue(): void
    {
        $loop = $this->aiAgentLoop();
        Queue::fake();

        app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Bonjour, que proposez-vous ?');

        Queue::assertPushed(GenerateAiAgentResponse::class, 1);
        Queue::assertPushedOn(GenerateAiAgentResponse::class::QUEUE, GenerateAiAgentResponse::class);
        $this->assertNoAgentJobOnDefault();
    }

    /**
     * La file est une propriete du JOB, pas du site de dispatch : un appelant
     * qui construit le job directement l'obtient sans avoir a y penser. C'est
     * ce qui protege un futur second appelant.
     */
    public function test_the_queue_is_carried_by_the_job_itself_not_by_the_dispatch_site(): void
    {
        [$loop, $message] = $this->aiAgentLoopWithMessage();

        $job = new GenerateAiAgentResponse($loop, $message);

        $this->assertSame('member-agent-replies', GenerateAiAgentResponse::QUEUE);
        $this->assertSame(GenerateAiAgentResponse::QUEUE, $job->queue);
        $this->assertNotSame('default', $job->queue);
    }

    // ── 3. Le comportement du job lui-meme est inchange ─────────────────────

    /**
     * Le routage ne doit rien changer d'autre : la correlation reste figee au
     * DISPATCH (TASK-1131), et le job porte toujours la Boucle et le message
     * d'origine.
     */
    public function test_the_job_payload_and_correlation_are_unchanged(): void
    {
        [$loop, $message] = $this->aiAgentLoopWithMessage();

        $avecCorrelation = new GenerateAiAgentResponse($loop, $message, 'correlation-figee-1410');
        $this->assertSame('correlation-figee-1410', $avecCorrelation->correlationId);
        $this->assertSame($loop->id, $avecCorrelation->loop->id);
        $this->assertSame($message->id, $avecCorrelation->message->id);

        // Sans correlation explicite, elle est derivee — jamais nulle.
        $sansCorrelation = new GenerateAiAgentResponse($loop, $message);
        $this->assertNotNull($sansCorrelation->correlationId);
        $this->assertNotSame('', $sansCorrelation->correlationId);
    }

    // ── 4. Aucun autre job n'est reroute ────────────────────────────────────

    /**
     * Les quatre autres files du projet sont inchangees. Cette TASK ne cree
     * aucun systeme generique de queues : elle ajoute UNE destination a UN job.
     */
    public function test_no_other_job_is_rerouted(): void
    {
        $this->assertSame('dossier-files-indexing', DossierFileIndexingDispatcher::DEDICATED_QUEUE);
        $this->assertSame('dossier-articles-indexing', DossierArticleIndexingDispatcher::DEDICATED_QUEUE);
        $this->assertSame('notifications-email', NotificationEmailDeliverer::QUEUE);

        // Les cinq files du projet sont distinctes deux a deux : c'est ce qui
        // permet d'operer un consommateur par flux, sans jamais toucher
        // `default`.
        $files = [
            GenerateAiAgentResponse::QUEUE,
            DossierFileIndexingDispatcher::DEDICATED_QUEUE,
            DossierArticleIndexingDispatcher::DEDICATED_QUEUE,
            NotificationEmailDeliverer::QUEUE,
        ];

        $this->assertSame($files, array_values(array_unique($files)));
        $this->assertNotContains('default', $files);
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    /**
     * Sans lui, un listener debranche ferait passer la garde « aucun job sur
     * default » : zero job pousse, zero job sur default. Ce temoin exige qu'un
     * job existe REELLEMENT, et que l'assertion de queue sache distinguer deux
     * files.
     */
    public function test_the_probe_really_pushes_a_job_and_can_tell_two_queues_apart(): void
    {
        $loop = $this->aiAgentLoop();
        Queue::fake();

        app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Une question.');

        Queue::assertPushed(GenerateAiAgentResponse::class, 1);
        Queue::assertNotPushed(
            GenerateAiAgentResponse::class,
            fn (GenerateAiAgentResponse $job, ?string $queue): bool => $queue === 'une-file-qui-n-existe-pas',
        );
        Queue::assertPushedOn(GenerateAiAgentResponse::QUEUE, GenerateAiAgentResponse::class);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * `Queue::fake()` presente au callback la queue `null` pour un job pousse
     * SANS `onQueue()`, et jamais la chaine `'default'` — mesure faite en
     * sabotant le routage en TASK-1407, ou une closure typee `string $queue`
     * levait un TypeError au lieu de rendre un verdict. D'ou `?string`, et la
     * couverture des trois formes.
     */
    private function assertNoAgentJobOnDefault(): void
    {
        Queue::assertNotPushed(
            GenerateAiAgentResponse::class,
            fn (GenerateAiAgentResponse $job, ?string $queue): bool => $queue === 'default'
                || $queue === null
                || $queue === '',
        );
    }

    /**
     * Une Boucle agent ET un message de visiteur dedans. Le `Queue::fake()`
     * est pose AVANT l'envoi : on veut le message en base, pas le job execute.
     *
     * @return array{0: Loop, 1: \App\Models\LoopMessage}
     */
    private function aiAgentLoopWithMessage(): array
    {
        $loop = $this->aiAgentLoop();
        Queue::fake();
        $message = app(LoopMessageService::class)
            ->sendUserMessage($loop, $this->visitor, 'Message de fixture TASK-1410.');

        return [$loop, $message instanceof \App\Models\LoopMessage
            ? $message
            : $loop->messages()->latest('id')->firstOrFail()];
    }

    private function aiAgentLoop(): Loop
    {
        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->owner))
            ->assertRedirect();

        return Loop::query()->where('type', 'ai_agent')->firstOrFail();
    }
}
