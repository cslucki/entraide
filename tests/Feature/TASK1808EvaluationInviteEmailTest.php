<?php

namespace Tests\Feature;

use App\Console\Commands\EntraideInviteToEvaluate;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * TASK-1808 §6 — l'invitation a evaluer, envoyee par le planificateur.
 *
 * Trois proprietes exigees par MASTER, et un test par propriete :
 *
 *   1. idempotence — une invitation MAXIMUM par entraide, meme sur un
 *      second passage, meme en concurrence (garde `whereNull`) ;
 *   2. echec — un transport qui leve laisse la porte ouverte a un reessai
 *      (statut `failed`), un transport qui leve APRES acceptation ne doit
 *      JAMAIS reessayer (statut `ambiguous`, marque invitee quand meme) ;
 *   3. non-envoi quand une evaluation existe deja — « eviter un email devenu
 *      inutile ».
 */
class TASK1808EvaluationInviteEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aide;

    private User $aidant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function entraideClose(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->aide)
            ->forSeller($this->aidant)
            ->completed()
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'completed_at' => now()->subMinutes(EntraideInviteToEvaluate::DELAI_MINUTES + 5),
            ], $overrides));
    }

    /**
     * TASK-1808 — `Mail::html()` (contrairement a une Mailable envoyee via
     * `Mail::send()`) resout `mail.manager` puis appelle `html()`
     * DIRECTEMENT sur lui : c'est `MailManager::__call()` qui, cote reel,
     * relaie ensuite vers `$this->mailer()->html()`. Un mock de la seule
     * interface `Factory` n'a pas cette magie — il faut donc poser
     * `shouldReceive('html')` sur le mock du manager lui-meme, pas sur un
     * mailer intermediaire.
     */
    private function mockMailTransportFailure(int $times = 1): void
    {
        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('html')
            ->times($times)
            ->andThrow(new TransportException('Simulated transport failure'));

        $this->app->instance('mail.manager', $factoryMock);
    }

    /**
     * `instance()` pose un partage qui survit a la fin du mock — sans ce
     * retrait, un second appel dans le MEME test continuerait de heurter un
     * mock dont l'unique attente (`->times(n)`) est deja consommee.
     * `forgetInstance()` fait retomber la resolution sur le binding normal.
     */
    private function restaurerLeVraiTransportMail(): void
    {
        $this->app->forgetInstance('mail.manager');
        // `forgetInstance()` nettoie le CONTENEUR, mais la facade `Mail` garde
        // sa propre resolution en cache statique (`resolveFacadeInstance()`),
        // independamment du conteneur, et ne la relirait pas sans ceci.
        Mail::clearResolvedInstance('mail.manager');
    }

    // ── Envoi normal ────────────────────────────────────────────────────────

    public function test_une_entraide_eligible_recoit_son_invitation(): void
    {
        $entraide = $this->entraideClose();

        $this->artisan('entraide:invite-to-evaluate')->assertSuccessful();

        $entraide->refresh();
        $this->assertNotNull($entraide->evaluation_invited_at);

        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aide->email,
            'status' => EmailLog::STATUS_SENT,
        ]);

        // Jamais a l'aidant.
        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aidant->email]);
    }

    public function test_le_lien_de_l_email_pointe_vers_la_conversation_de_l_entraide(): void
    {
        $entraide = $this->entraideClose();

        $this->artisan('entraide:invite-to-evaluate');

        $log = EmailLog::where('to_email', $this->aide->email)->first();
        $this->assertSame('sent', $log->status);
        $this->assertSame($entraide->id, $log->data['transaction_id']);
    }

    public function test_une_entraide_trop_recente_n_est_pas_encore_invitee(): void
    {
        $entraide = $this->entraideClose(['completed_at' => now()->subMinutes(2)]);

        $this->artisan('entraide:invite-to-evaluate');

        $this->assertNull($entraide->fresh()->evaluation_invited_at);
        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aide->email]);
    }

    public function test_une_discussion_directe_n_est_jamais_invitee(): void
    {
        $discussion = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->aide->id,
            'seller_id' => $this->aidant->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'status' => 'completed',
            'completed_at' => now()->subMinutes(EntraideInviteToEvaluate::DELAI_MINUTES + 5),
        ]);

        $this->artisan('entraide:invite-to-evaluate');

        $this->assertNull($discussion->fresh()->evaluation_invited_at);
    }

    // ── 1. Idempotence : une invitation MAXIMUM ─────────────────────────────

    public function test_un_second_passage_n_envoie_rien_de_plus(): void
    {
        $entraide = $this->entraideClose();

        $this->artisan('entraide:invite-to-evaluate');
        $this->assertSame(1, EmailLog::where('to_email', $this->aide->email)->count());

        $this->artisan('entraide:invite-to-evaluate');
        $this->assertSame(1, EmailLog::where('to_email', $this->aide->email)->count());
    }

    public function test_une_entraide_deja_marquee_invitee_est_ignoree_meme_sans_journal(): void
    {
        // Simule une marque posee par un AUTRE processus (course deja gagnee) :
        // la garde `whereNull('evaluation_invited_at')` de la commande doit
        // suffire seule, sans relire `email_logs`.
        $entraide = $this->entraideClose(['evaluation_invited_at' => now()->subMinute()]);

        $this->artisan('entraide:invite-to-evaluate');

        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aide->email]);
    }

    // ── 2. Echec ─────────────────────────────────────────────────────────────

    public function test_un_echec_de_transport_reste_rejouable_au_prochain_passage(): void
    {
        $entraide = $this->entraideClose();
        $this->mockMailTransportFailure();

        $this->artisan('entraide:invite-to-evaluate')->assertSuccessful();

        // Rien n'a ete marque : le prochain passage peut retenter.
        $this->assertNull($entraide->fresh()->evaluation_invited_at);
        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aide->email,
            'status' => EmailLog::STATUS_FAILED,
        ]);

        // Et il retente bien, reussissant cette fois (plus de mock de panne).
        $this->restaurerLeVraiTransportMail();
        $this->artisan('entraide:invite-to-evaluate')->assertSuccessful();
        $this->assertNotNull($entraide->fresh()->evaluation_invited_at);
        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aide->email,
            'status' => EmailLog::STATUS_SENT,
        ]);
    }

    public function test_un_echec_de_transport_ne_fait_pas_tomber_les_autres_entraides_du_balayage(): void
    {
        $enPanne = $this->entraideClose();
        $autreAide = User::factory()->create(['organization_id' => $this->organization->id]);
        $autreAidant = User::factory()->create(['organization_id' => $this->organization->id]);
        $saine = Transaction::factory()
            ->forBuyer($autreAide)->forSeller($autreAidant)->completed()
            ->create([
                'organization_id' => $this->organization->id,
                'completed_at' => now()->subMinutes(EntraideInviteToEvaluate::DELAI_MINUTES + 5),
            ]);

        // Un seul des deux envois echoue : la PREMIERE invocation leve, les
        // suivantes reussissent normalement (`andReturnNull()` : la valeur de
        // retour de `html()` n'est jamais lue par le mailer).
        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('html')->once()->andThrow(new TransportException('Simulated transport failure'));
        $factoryMock->shouldReceive('html')->andReturnNull();
        $this->app->instance('mail.manager', $factoryMock);

        $this->artisan('entraide:invite-to-evaluate')->assertSuccessful();

        $this->assertNull($enPanne->fresh()->evaluation_invited_at);
        $this->assertNotNull($saine->fresh()->evaluation_invited_at);
        $this->assertDatabaseHas('email_logs', ['to_email' => $autreAide->email, 'status' => EmailLog::STATUS_SENT]);
    }

    // ── 3. Non-envoi quand une evaluation existe deja ──────────────────────

    public function test_aucune_invitation_si_un_avis_est_deja_depose(): void
    {
        $entraide = $this->entraideClose();
        Review::factory()->create([
            'transaction_id' => $entraide->id,
            'organization_id' => $this->organization->id,
            'reviewer_id' => $this->aide->id,
            'reviewed_id' => $this->aidant->id,
            'rating' => 5,
        ]);

        $this->artisan('entraide:invite-to-evaluate');

        $this->assertNull($entraide->fresh()->evaluation_invited_at);
        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aide->email]);
    }

    public function test_aucune_invitation_si_l_aide_a_explicitement_renonce(): void
    {
        $entraide = $this->entraideClose(['reviews_skipped_at' => now()]);

        $this->artisan('entraide:invite-to-evaluate');

        $this->assertNull($entraide->fresh()->evaluation_invited_at);
        $this->assertDatabaseMissing('email_logs', ['to_email' => $this->aide->email]);
    }

    public function test_un_simple_plus_tard_n_empeche_pas_l_invitation(): void
    {
        // « Plus tard » (report) est distinct du renoncement — la relance
        // unique par email reste due.
        $entraide = $this->entraideClose(['evaluation_deferred_at' => now()]);

        $this->artisan('entraide:invite-to-evaluate');

        $this->assertNotNull($entraide->fresh()->evaluation_invited_at);
        $this->assertDatabaseHas('email_logs', ['to_email' => $this->aide->email, 'status' => EmailLog::STATUS_SENT]);
    }

    /**
     * TASK-1809 — demande Cyril (12/11) : l'email nomme l'aidant par son
     * PRENOM dans la phrase d'invitation, et dit explicitement que
     * l'echange est termine — les deux faits dans le MEME courriel.
     */
    public function test_le_courriel_nomme_le_prenom_de_l_aidant_et_dit_que_l_echange_est_termine(): void
    {
        $this->aidant->update(['first_name' => 'Alex', 'name' => 'Alex Martin']);

        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();

        $this->entraideClose();

        $this->artisan('entraide:invite-to-evaluate');

        $messages = array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
        $this->assertCount(1, $messages);

        $corps = (string) $messages[0]->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString('terminée', $corps, 'Le courriel dit explicitement que l\'echange est termine.');
        $this->assertStringContainsString('Alex', $corps, 'Le prenom de l\'aidant doit apparaitre dans la phrase d\'invitation.');
    }
}
