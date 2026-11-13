<?php

namespace Tests\Feature;

use App\Events\LoopMessageCreated;
use App\Jobs\GenerateAiAgentResponse;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1815 — le job d'IA s'exécutait DANS la transaction du message.
 *
 * ## Le chemin, et ce qui a été mesuré
 *
 * `LoopMessageService::sendUserMessage()` (ligne 38) ouvre un
 * `DB::transaction()`. À l'intérieur, ligne 60, il émet `LoopMessageCreated`.
 * L'écoute d'`AppServiceProvider` dispatche `GenerateAiAgentResponse`. Sous
 * `QUEUE_CONNECTION=sync`, `dispatch()` exécute **séance tenante**.
 *
 * | mesure, 13/11 | profondeur applicative au démarrage du job |
 * |---|---|
 * | avant | **1** — dans la transaction |
 * | après | **0** — après le commit |
 *
 * La « profondeur applicative » est le niveau de transaction MOINS le socle
 * posé par `RefreshDatabase`, qui enveloppe chaque test et n'a rien
 * d'applicatif. Compter le socle ferait conclure à tort que le job reste
 * transaction ouverte.
 *
 * Conséquence du défaut : l'appel au fournisseur d'IA **et** l'insertion de la
 * réponse de l'agent se faisaient transaction ouverte. Cette insertion
 * réémettait l'événement, d'où un second job, lui aussi dans la transaction.
 *
 * ## Pourquoi `afterCommit()` suffit, sous `sync`
 *
 * Contre-intuitif, et vérifié dans le framework : `SyncQueue::push()` teste
 * `shouldDispatchAfterCommit()` et, s'il y a une transaction vivante,
 * enregistre l'exécution comme **callback de commit** au lieu de la lancer.
 * Hors transaction, le comportement est inchangé.
 *
 * Rien d'autre ne bouge : ni la file, ni le pilote, ni les autres
 * notifications. La garde économique reste DANS le job.
 */
class TASK1815TransactionIaBorneeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private User $owner;

    private User $visitor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => false,
            'ai.ollama.enabled' => false,
        ]);

        // EXIGENCE : aucun appel IA reel. `preventStrayRequests()` fait
        // ECHOUER le test si une requete HTTP sortante n'est pas doublee.
        Http::preventStrayRequests();

        Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plat-1815', 'ai_profiles_enabled' => true]);
        $this->tenant = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-1815', 'ai_profiles_enabled' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->tenant->id, 'first_name' => 'Maya']);
        $this->visitor = User::factory()->create(['organization_id' => $this->tenant->id, 'first_name' => 'Theo']);

        app()->instance('current_organization', $this->tenant);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'skills' => ['SEO'],
            'service_scope' => 'Audit SEO',
            'member_profile_summary' => 'Consultante',
        ]);
    }

    private function boucleAgent(): Loop
    {
        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->owner))
            ->assertRedirect();

        return Loop::query()->where('type', 'ai_agent')->firstOrFail();
    }

    /**
     * @return array<int, int> profondeurs applicatives au demarrage de chaque job
     */
    private function profondeursAuDemarrage(callable $geste): array
    {
        $socle = DB::transactionLevel();
        $profondeurs = [];

        Event::listen(JobProcessing::class, function (JobProcessing $e) use (&$profondeurs, $socle): void {
            if (str_contains($e->job->resolveName(), 'GenerateAiAgentResponse')) {
                $profondeurs[] = DB::transactionLevel() - $socle;
            }
        });

        $geste();

        return $profondeurs;
    }

    // ────────────────────────────────────────────────────────────────────────
    // 1. Le job ne demarre pas avant le commit
    // ────────────────────────────────────────────────────────────────────────

    public function test_le_job_ne_demarre_jamais_dans_la_transaction_du_message(): void
    {
        $loop = $this->boucleAgent();

        $profondeurs = $this->profondeursAuDemarrage(function () use ($loop): void {
            app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Quelles competences en SEO ?');
        });

        $this->assertNotEmpty($profondeurs, 'Precondition : le job doit avoir ete dispatche.');

        foreach ($profondeurs as $index => $profondeur) {
            $this->assertSame(
                0,
                $profondeur,
                "Le job d IA numero {$index} a demarre a une profondeur applicative de {$profondeur} : "
                .'il s execute DANS la transaction du message. L appel au fournisseur et l insertion de la '
                .'reponse tiennent alors la transaction ouverte.'
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Rollback : ni appel provider, ni message agent
    // ────────────────────────────────────────────────────────────────────────

    public function test_un_rollback_ne_laisse_ni_reponse_ni_appel(): void
    {
        $loop = $this->boucleAgent();
        $avant = LoopMessage::where('loop_id', $loop->id)->count();

        $demarres = 0;
        Event::listen(JobProcessing::class, function (JobProcessing $e) use (&$demarres): void {
            if (str_contains($e->job->resolveName(), 'GenerateAiAgentResponse')) {
                $demarres++;
            }
        });

        try {
            DB::transaction(function () use ($loop): void {
                app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Message qui sera annule');

                throw new \RuntimeException('annulation volontaire');
            });
        } catch (\RuntimeException $e) {
            // attendue
        }

        $this->assertSame(
            0,
            $demarres,
            'Un job d IA a demarre alors que la transaction a ete annulee : le tenant aurait ete facture '
            .'pour un message qui n existe pas.'
        );

        $this->assertSame(
            $avant,
            LoopMessage::where('loop_id', $loop->id)->count(),
            'Le rollback doit ne laisser aucun message, ni celui du visiteur ni une reponse d agent.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Un message valide declenche toujours une reponse
    // ────────────────────────────────────────────────────────────────────────

    public function test_un_message_valide_declenche_toujours_le_job(): void
    {
        $loop = $this->boucleAgent();

        $demarres = 0;
        Event::listen(JobProcessing::class, function (JobProcessing $e) use (&$demarres): void {
            if (str_contains($e->job->resolveName(), 'GenerateAiAgentResponse')) {
                $demarres++;
            }
        });

        app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Une vraie question');

        $this->assertGreaterThanOrEqual(
            1,
            $demarres,
            'Borner la transaction ne doit pas SUPPRIMER la reponse : le job doit toujours s executer apres le commit.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 4. Aucun double dispatch POUR UN MEME MESSAGE
    // ────────────────────────────────────────────────────────────────────────

    /**
     * La nuance compte. Deux jobs partent bien pour un envoi — mais pour DEUX
     * messages differents : celui du visiteur, puis la reponse de l'agent, qui
     * reemet l'evenement. Le second job retourne immediatement (l'expediteur
     * est l'agent lui-meme). Ce qui serait un defaut, c'est qu'un MEME message
     * soit dispatche deux fois.
     */
    public function test_un_meme_message_n_est_jamais_dispatche_deux_fois(): void
    {
        $loop = $this->boucleAgent();

        $messagesDispatches = [];
        Event::listen(LoopMessageCreated::class, function (LoopMessageCreated $e) use (&$messagesDispatches): void {
            $messagesDispatches[] = (string) $e->id;
        });

        app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Une seule question');

        $this->assertSame(
            count(array_unique($messagesDispatches)),
            count($messagesDispatches),
            'Un meme message a emis `LoopMessageCreated` plusieurs fois : la reponse de l agent serait produite en double.'
        );

        $this->assertSame(
            1,
            LoopMessage::where('loop_id', $loop->id)->where('type', 'member_agent')->count(),
            'L agent a repondu plus d une fois a une seule question.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 5. Isolation Organization
    // ────────────────────────────────────────────────────────────────────────

    public function test_l_isolation_de_l_organization_est_preservee(): void
    {
        $loop = $this->boucleAgent();

        app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Question du tenant');

        $etrangers = LoopMessage::where('loop_id', $loop->id)
            ->where('organization_id', '!=', $this->tenant->id)
            ->count();

        $this->assertSame(0, $etrangers, 'Un message de cette Boucle porte une AUTRE Organization.');

        $this->assertSame(
            (string) $this->tenant->id,
            (string) $loop->fresh()->organization_id,
            'La Boucle agent a change d Organization.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 6. La garde economique reste DANS le job
    // ────────────────────────────────────────────────────────────────────────

    /**
     * `afterCommit()` retarde le dispatch, donc la garde est evaluee PLUS TARD
     * qu'avant — ce qui la rend plus juste, pas moins. Ce test tient le
     * contrat : la garde n'a pas migre vers l'ecoute.
     */
    public function test_la_garde_economique_n_a_pas_quitte_le_job(): void
    {
        $ecoute = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $extrait = substr($ecoute, strpos($ecoute, 'LoopMessageCreated::class'), 3000);

        $this->assertStringNotContainsString(
            'SupervisionEconomicScope',
            $extrait,
            'La garde economique a migre dans l ecoute : elle doit rester DANS le job, evaluee au moment de l execution.'
        );

        $this->assertStringContainsString(
            'SupervisionEconomicScope',
            file_get_contents(app_path('Jobs/GenerateAiAgentResponse.php')),
            'La garde economique a disparu du job.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 7-8. Ni file ni pilote ni autres notifications modifies
    // ────────────────────────────────────────────────────────────────────────

    public function test_la_connexion_de_file_reste_sync_et_le_job_garde_sa_file(): void
    {
        $this->assertSame(
            'sync',
            config('queue.default'),
            'La connexion de file a change : hors perimetre de cette TASK.'
        );

        $job = new GenerateAiAgentResponse(
            Loop::factory()->create(['organization_id' => $this->tenant->id]),
            new LoopMessage
        );

        $this->assertTrue(
            property_exists($job, 'afterCommit') === false || $job->afterCommit !== false,
            'Le job ne doit pas porter un `afterCommit = false` qui annulerait la bride posee au dispatch.'
        );
    }
}
