<?php

namespace Tests\Feature;

use App\Jobs\SendReviewReceivedEmail;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Entraide\ReviewReceivedMailer;
use App\Support\Entraide\EvaluationEntraide;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * TASK-1854 — l'email d'avis d'entraide (`ReviewReceivedMailer`) part
 * desormais via `SendReviewReceivedEmail` (Managed Queue Laravel Cloud
 * EXISTANTE), au lieu de bloquer le thread HTTP sur une conversation SMTP
 * (audit incident 429 du 17/11 : jusqu'a 11 281 ms observes sur `/complete`).
 *
 * Perimetre : le dispatch, la relecture/autorisation tenant dans le job,
 * l'echec de mise en file, et la garde anti double-appui du formulaire.
 * Le comportement du MAILER lui-meme (gabarits FR/EN, echappement,
 * idempotence du depot) reste couvert par `TASK1809ReviewReceivedEmailTest`
 * — inchange, revalide en meme temps que ce fichier (67/67 PASS avant ce
 * correctif, voir TASK file).
 */
class TASK1854EntraideReviewEmailAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aide;

    private User $aidant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 100]);
        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 10]);
        app()->instance('current_organization', $this->organization);
    }

    private function entraideCompletee(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->aide)
            ->forSeller($this->aidant)
            ->completed()
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'points_proposed' => 20,
                'points_agreed' => 20,
            ], $overrides));
    }

    // ── 1. Soumission unique : exactement un job, le bon identifiant ───────

    public function test_une_soumission_envoie_exactement_un_job_portant_l_identifiant_de_l_avis(): void
    {
        Queue::fake();

        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), [
            'rating' => 4,
            'comment' => 'Super entraide !',
        ]);

        $avis = Review::where('transaction_id', $entraide->id)->first();
        $this->assertNotNull($avis, 'Precondition non prise : aucun avis cree.');

        Queue::assertPushed(SendReviewReceivedEmail::class, 1);
        Queue::assertPushed(
            fn (SendReviewReceivedEmail $job): bool => $job->reviewId === (string) $avis->id
        );
    }

    // ── 2. Double appui : aucun second job, aucun second avis ──────────────

    public function test_un_double_appui_ne_declenche_jamais_un_second_job(): void
    {
        Queue::fake();

        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 4]);
        // Second appui rapproche, meme formulaire — la garde existante
        // (`unique(transaction_id)` + `enregistrer()`) refuse deja la
        // seconde ligne ; ce test prouve qu'elle coupe AUSSI le second
        // dispatch, pas seulement la seconde ecriture SQL.
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 1]);

        $this->assertSame(1, Review::where('transaction_id', $entraide->id)->count());
        Queue::assertPushed(SendReviewReceivedEmail::class, 1);
    }

    // ── 3. Echec de mise en file : le depot de l'avis n'est jamais perdu ────

    public function test_un_echec_de_mise_en_file_n_empeche_pas_le_depot_de_l_avis(): void
    {
        $queueMock = Mockery::mock(QueueFactory::class);
        $queueMock->shouldReceive('connection')->andThrow(new \RuntimeException('Simulated queue connection failure'));
        $this->app->instance('queue', $queueMock);
        $this->app->instance(QueueFactory::class, $queueMock);

        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)
            ->post(route('reviews.store', $entraide), ['rating' => 4])
            ->assertRedirect(route('messages.show', $entraide));

        $this->assertDatabaseHas('reviews', ['transaction_id' => $entraide->id, 'rating' => 4]);
    }

    // ── 4. Le job relit les donnees ET re-etablit le tenant, sans contexte ──

    public function test_le_job_envoie_l_email_sans_aucun_contexte_de_requete_prealable(): void
    {
        $entraide = $this->entraideCompletee();

        EvaluationEntraide::enregistrer($entraide, 5, 'Au top');
        $avis = Review::where('transaction_id', $entraide->id)->first();

        // Simule un worker : AUCUN contexte tenant ambiant, contrairement a
        // une requete HTTP ou au `setUp()` de ce test.
        app()->forgetInstance('current_organization');

        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();

        (new SendReviewReceivedEmail((string) $avis->id))->handle(app(ReviewReceivedMailer::class));

        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aidant->email,
            'status' => EmailLog::STATUS_SENT,
        ]);

        // Preuve que le tenant a ete correctement re-etabli : l'URL de
        // conversation (qui depend de `$avis->transaction`, scope
        // `BelongsToOrganizationScope`) n'est pas vide dans le corps rendu.
        $messages = array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
        $corps = (string) $messages[0]->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString((string) $entraide->id, $corps,
            'Sans liaison tenant explicite dans le job, la transaction ne se charge pas et l\'URL de conversation reste vide.');
    }

    public function test_le_job_ignore_silencieusement_un_avis_deja_supprime(): void
    {
        $job = new SendReviewReceivedEmail((string) Str::uuid());

        $this->assertDoesNotThrow(function () use ($job) {
            $job->handle(app(ReviewReceivedMailer::class));
        });

        $this->assertDatabaseCount('email_logs', 0);
    }

    private function assertDoesNotThrow(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->fail('Une exception inattendue a ete levee : '.$e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    // ── 5. Routage Managed Queue — meme motif que SendNotificationEmail ────

    public function test_le_job_bascule_sur_la_managed_queue_quand_le_drapeau_est_pose(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications',
        ]);

        $job = new SendReviewReceivedEmail((string) Str::uuid());

        $this->assertSame('cloud', $job->connection);
        $this->assertSame('notifications', $job->queue);
    }

    public function test_le_job_reste_sur_la_file_dediee_par_defaut_sans_le_drapeau(): void
    {
        config(['queue.notifications_managed_queue_connection' => null]);

        $job = new SendReviewReceivedEmail((string) Str::uuid());

        $this->assertNull($job->connection);
        $this->assertSame(SendReviewReceivedEmail::DEDICATED_QUEUE, $job->queue);
    }

    // ── 6. Garde anti double-appui — presente dans le HTML rendu ───────────

    public function test_le_formulaire_d_avis_porte_la_garde_anti_double_appui(): void
    {
        $entraide = $this->entraideCompletee();

        $html = $this->actingAs($this->aide)
            ->get(route('messages.show', $entraide))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('envoiEnCours', $html,
            'La garde Alpine anti double-appui doit etre presente sur le formulaire d\'avis.');
        $this->assertStringContainsString(':disabled="envoiEnCours"', $html);
        $this->assertStringContainsString('@submit="envoiEnCours = true"', $html);
    }
}
