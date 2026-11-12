<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * TASK-1809 §3 — « simplification finale du parcours d'evaluation » :
 * l'invitation a evaluer n'est plus une relance PLANIFIEE a dix minutes
 * (`entraide:invite-to-evaluate`, supprimee) ; c'est UN SEUL email, envoye
 * IMMEDIATEMENT par `TransactionController::complete()`, a la cloture.
 *
 * Trois proprietes exigees par MASTER, reprises de T1808 §6 sur ce nouveau
 * declencheur synchrone :
 *
 *   1. idempotence — une invitation MAXIMUM par entraide, meme sur un
 *      second appel de `complete()` (retour arriere, double envoi) ;
 *   2. echec — un transport qui leve ne fait jamais echouer la cloture
 *      (deja validee, irreversible) ;
 *   3. le texte dit explicitement le fait de la cloture, le prenom de
 *      l'aidant, et le nombre de points transferes.
 */
class TASK1809EvaluationInviteImmediateEmailTest extends TestCase
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

    private function entraideAcceptee(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->aide)
            ->forSeller($this->aidant)
            ->accepted()
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'points_proposed' => 20,
                'points_agreed' => 20,
            ], $overrides));
    }

    /** Meme necessite technique que l'ancien test T1808 §6, documentee la-bas. */
    private function mockMailTransportFailure(int $times = 1): void
    {
        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('html')
            ->times($times)
            ->andThrow(new TransportException('Simulated transport failure'));

        $this->app->instance('mail.manager', $factoryMock);
    }

    public function test_la_cloture_envoie_immediatement_l_invitation_a_evaluer(): void
    {
        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $this->assertNotNull($entraide->fresh()->evaluation_invited_at);
        $this->assertSame(1, $this->invitesPour($this->aide->email)->count());

        // Jamais a l'aidant : SEULE la notification de cloture (distincte,
        // deja existante, non `entraide-evaluation-invite`) peut lui ecrire
        // une ligne `email_logs` — jamais cette invitation.
        $this->assertSame(0, $this->invitesPour($this->aidant->email)->count());
    }

    /**
     * `TransactionStatusChanged` (la notification de cloture, DISTINCTE et
     * INCHANGEE) journalise elle aussi dans `email_logs`, pour les DEUX
     * participants — un `assertDatabaseMissing('email_logs', ['to_email' =>
     * ...])` brut matcherait donc cette ligne et donnerait un faux negatif.
     * On filtre ici sur la source propre a l'invitation.
     */
    private function invitesPour(string $email)
    {
        return EmailLog::where('to_email', $email)
            ->get()
            ->filter(fn (EmailLog $log) => ($log->data['source'] ?? null) === 'entraide-evaluation-invite');
    }

    public function test_une_discussion_directe_ne_recoit_jamais_l_invitation(): void
    {
        $discussion = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->aide->id,
            'seller_id' => $this->aidant->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'points_agreed' => 0,
            'status' => 'accepted',
        ]);

        $this->actingAs($this->aide)->patch(route('transactions.complete', $discussion));

        $this->assertNull($discussion->fresh()->evaluation_invited_at);
        $this->assertSame(0, $this->invitesPour($this->aide->email)->count());
    }

    // ── 1. Idempotence : une invitation MAXIMUM ─────────────────────────────

    public function test_un_second_appel_de_cloture_n_envoie_rien_de_plus(): void
    {
        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->assertSame(1, $this->invitesPour($this->aide->email)->count());

        // Retour arriere / double envoi : la cloture elle-meme ne
        // retransfere rien (invariant deja prouve ailleurs), et l'email ne
        // repart pas non plus.
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->assertSame(1, $this->invitesPour($this->aide->email)->count());
    }

    // ── 2. Echec — n'empeche jamais la cloture ──────────────────────────────

    public function test_un_echec_de_transport_n_empeche_pas_la_cloture(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->mockMailTransportFailure();

        $this->actingAs($this->aide)
            ->patch(route('transactions.complete', $entraide))
            ->assertRedirect(route('messages.show', $entraide));

        $entraide->refresh();
        $this->assertSame('completed', $entraide->status);
        $this->assertSame(80, $this->aide->fresh()->points_balance);

        // L'echec est journalise, mais rien n'est marque invite : un futur
        // appel pourrait retenter (aucun appelant ne le fait plus
        // aujourd'hui, mais la garde reste honnete).
        $this->assertNull($entraide->fresh()->evaluation_invited_at);
        $this->assertDatabaseHas('email_logs', [
            'to_email' => $this->aide->email,
            'status' => EmailLog::STATUS_FAILED,
        ]);
    }

    // ── 3. Le texte ──────────────────────────────────────────────────────────

    public function test_le_courriel_dit_la_cloture_le_prenom_de_l_aidant_et_les_points(): void
    {
        $this->aidant->update(['first_name' => 'Alex', 'name' => 'Alex Martin']);

        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();

        $entraide = $this->entraideAcceptee(['points_proposed' => 15, 'points_agreed' => 15]);

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        // TASK-1809 §3 — la cloture envoie AUSSI, via `TransactionStatusChanged`
        // (DISTINCTE, inchangee), la notification de fin a l'aide ET a
        // l'aidant : le transport 'array' voit donc 3 messages. On isole
        // celui de l'invitation par son sujet, seul a nommer l'aidant.
        $messages = array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
        $this->assertCount(3, $messages);

        $invitation = array_values(array_filter(
            $messages,
            fn ($m) => str_contains((string) $m->getOriginalMessage()->getSubject(), 'évaluer'),
        ));
        $this->assertCount(1, $invitation);

        $corps = (string) $invitation[0]->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString('terminé', $corps);
        $this->assertStringContainsString('Alex', $corps);
        $this->assertStringContainsString('15', $corps);
    }

    /**
     * TASK-1810 — BUG CONFIRME EN RECETTE (Cyril, 12/11) : meme defaut que
     * `ReviewReceivedMailer`, trouve sur le MEME compte de demonstration.
     * `first_name` NULL (mais pas desactive) laissait `{{ aidant_first_name }}`
     * litteral dans le sujet ET le corps de l'email reel.
     */
    public function test_un_aidant_sans_prenom_renseigne_ne_laisse_jamais_le_jeton_brut(): void
    {
        $this->aidant->update(['first_name' => null, 'name' => 'Demo Admin']);

        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();

        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $messages = array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
        $invitation = array_values(array_filter(
            $messages,
            fn ($m) => str_contains((string) $m->getOriginalMessage()->getSubject(), 'évaluer'),
        ));
        $this->assertCount(1, $invitation);

        $sujet = (string) $invitation[0]->getOriginalMessage()->getSubject();
        $corps = (string) $invitation[0]->getOriginalMessage()->getHtmlBody();

        foreach ([$sujet, $corps] as $rendu) {
            $this->assertStringNotContainsString('{{ aidant_first_name }}', $rendu,
                'Le jeton brut ne doit JAMAIS apparaitre dans un email reel.');
            $this->assertStringContainsString('Demo Admin', $rendu,
                'A defaut de prenom, le nom complet doit servir de repli.');
        }
    }
}
