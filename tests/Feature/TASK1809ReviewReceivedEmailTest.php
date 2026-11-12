<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\ReviewModeration;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * TASK-1809 §4 — l'email a l'aidant (D2) QUAND son evaluation arrive.
 *
 * Proprietes exigees par MASTER :
 *
 *   1. envoye apres le depot REEL d'un avis, jamais avant, jamais a l'aide ;
 *   2. porte la note en etoiles ET en chiffre, et le commentaire SEULEMENT
 *      s'il existe ;
 *   3. jamais declenche par une action de moderation SuperAdmin
 *      (`ReviewModeration`) ;
 *   4. jamais de HTML non echappe issu d'un commentaire membre ;
 *   5. idempotent, journalise, non bloquant si le transport echoue.
 */
class TASK1809ReviewReceivedEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aide;    // D1 — buyer, reviewer

    private User $aidant;  // D2 — seller, reviewed

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Dominique', 'points_balance' => 100]);
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

    private function mockMailTransportFailure(int $times = 1): void
    {
        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('html')
            ->times($times)
            ->andThrow(new TransportException('Simulated transport failure'));

        $this->app->instance('mail.manager', $factoryMock);
    }

    // ── 1. Envoye apres un depot reel, jamais a l'aide ─────────────────────

    public function test_le_depot_d_un_avis_envoie_l_email_a_l_aidant_jamais_a_l_aide(): void
    {
        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), [
            'rating' => 4,
            'comment' => 'Super entraide !',
        ]);

        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aidant->email,
            'status' => EmailLog::STATUS_SENT,
        ]);
        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aide->email]);
    }

    public function test_un_renoncement_explicite_n_envoie_aucun_email(): void
    {
        $entraide = $this->entraideCompletee();

        // Domaine : `enregistrer(null)` reste disponible en interne (aucune
        // route web ne l'atteint plus depuis T1809 §2) mais ne cree jamais
        // de ligne `Review` — rien ne doit donc jamais partir.
        \App\Support\Entraide\EvaluationEntraide::enregistrer($entraide, null);

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aidant->email]);
    }

    // ── 2. Etoiles + chiffre, commentaire seulement si present ─────────────

    public function test_le_courriel_porte_les_etoiles_et_le_chiffre_et_le_commentaire(): void
    {
        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();

        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), [
            'rating' => 4,
            'comment' => 'Vraiment au top, merci !',
        ]);

        $messages = array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
        $this->assertCount(1, $messages);

        $corps = (string) $messages[0]->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString('★★★★☆', $corps);
        $this->assertStringContainsString('4/5', $corps);
        $this->assertStringContainsString('Vraiment au top, merci !', $corps);
        $this->assertStringContainsString('Dominique', $corps);
    }

    public function test_une_note_sans_commentaire_envoie_quand_meme_l_email(): void
    {
        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 5]);

        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aidant->email,
            'status' => EmailLog::STATUS_SENT,
        ]);
    }

    /**
     * Securite : un commentaire contenant du HTML ne doit JAMAIS s'executer
     * ni s'afficher comme balise — `GabaritEmail`/`EmailerService::
     * interpolate()` applique `e()` a chaque variable substituee.
     */
    public function test_un_commentaire_avec_du_html_est_toujours_echappe(): void
    {
        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();

        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), [
            'rating' => 3,
            'comment' => '<script>alert(1)</script>',
        ]);

        $messages = array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
        $corps = (string) $messages[0]->getOriginalMessage()->getHtmlBody();

        $this->assertStringNotContainsString('<script>', $corps);
        $this->assertStringContainsString('&lt;script&gt;', $corps);
    }

    // ── 3. Jamais declenche par la moderation SuperAdmin ────────────────────

    public function test_une_modification_de_moderation_n_envoie_pas_un_second_email(): void
    {
        $entraide = $this->entraideCompletee();
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 2, 'comment' => 'moyen']);

        $this->assertSame(1, EmailLog::where('to_email', $this->aidant->email)->count());

        $avis = Review::where('transaction_id', $entraide->id)->first();
        $admin = User::factory()->create(['organization_id' => $this->organization->id]);

        ReviewModeration::masquer($avis, $admin, 'test moderation');
        ReviewModeration::modifier($avis->fresh(), $admin, 1, null, 'correction');

        $this->assertSame(1, EmailLog::where('to_email', $this->aidant->email)->count());
    }

    // ── 4. Idempotence, echec non bloquant ──────────────────────────────────

    public function test_un_echec_de_transport_n_empeche_pas_le_depot_de_l_avis(): void
    {
        $entraide = $this->entraideCompletee();
        $this->mockMailTransportFailure();

        $this->actingAs($this->aide)
            ->post(route('reviews.store', $entraide), ['rating' => 4])
            ->assertRedirect(route('messages.show', $entraide));

        $this->assertDatabaseHas('reviews', ['transaction_id' => $entraide->id, 'rating' => 4]);
        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aidant->email,
            'status' => EmailLog::STATUS_FAILED,
        ]);
    }

    public function test_un_second_depot_est_impossible_donc_un_seul_email_part(): void
    {
        $entraide = $this->entraideCompletee();

        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 4]);
        // `unique(transaction_id)` + la garde `enregistrer()` : un second
        // depot ne cree jamais de seconde ligne, donc jamais un second email.
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 1]);

        $this->assertSame(1, EmailLog::where('to_email', $this->aidant->email)->count());
        $this->assertSame(4, Review::where('transaction_id', $entraide->id)->first()->rating);
    }
}
