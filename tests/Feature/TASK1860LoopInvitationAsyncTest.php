<?php

namespace Tests\Feature;

use App\Jobs\SendLoopInvitationEmail;
use App\Livewire\LoopMembersCard;
use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * TASK-1860 — audit prealable : les deux appelants de
 * `LoopInvitationMailer::send()` (`LoopInvitationController::store()` et
 * `LoopMembersCard::sendInvitation()`) restaient SYNCHRONES, bloquant la
 * requete HTTP sur la reponse SMTP a CHAQUE invitation EXTERNE. Meme
 * famille que TASK-1854/1855/1857/1858 : `SendLoopInvitationEmail`
 * deporte l'envoi vers la Managed Queue `notifications`, sans toucher
 * `LoopInvitationMailer` (autorite unique du rendu/livraison, qui
 * recalcule elle-meme `emailHandledByNotifications()` — TASK-1378).
 *
 * Perimetre : le dispatch depuis les deux chemins, l'absence de second
 * email pour un membre DEJA de l'Organization, la coherence
 * Organization/Loop portee par l'invitation elle-meme, l'absence
 * d'envoi tardif (expiree/revoquee/acceptee), la restauration du
 * contexte tenant, l'integrite de l'invitation si la mise en file
 * echoue, et la conservation des gabarits/journalisation. Le rendu, les
 * gabarits et le cutover TASK-1378 restent couverts par
 * `LoopInvitationMailerTest` / `TASK1378LoopInvitationEmailCutoverTest`
 * (101 tests revalides, zero regression), non dupliques ici.
 */
class TASK1860LoopInvitationAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Entraide Locale']);
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Owner Person']);
        $this->loop = (new LoopService)->createLoop($this->owner, 'Boucle TASK-1860');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── 1. Dispatch depuis les deux chemins de production ──────────────────

    public function test_la_route_historique_met_en_file_un_job_pour_l_invitation_externe(): void
    {
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('loops.invitations.store', $this->loop), ['email' => 'externe@exemple.fr'])
            ->assertRedirect();

        $invitation = LoopInvitation::where('recipient_email', 'externe@exemple.fr')->firstOrFail();

        Queue::assertPushed(SendLoopInvitationEmail::class, 1);
        Queue::assertPushed(fn (SendLoopInvitationEmail $job): bool => $job->invitationId === (string) $invitation->id);
    }

    public function test_la_card_membres_met_en_file_un_job_pour_l_invitation_externe(): void
    {
        Queue::fake();

        Livewire::actingAs($this->owner)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('inviteEmail', 'externe-card@exemple.fr')
            ->call('sendInvitation')
            ->assertSet('showAddModal', false);

        $invitation = LoopInvitation::where('recipient_email', 'externe-card@exemple.fr')->firstOrFail();

        Queue::assertPushed(SendLoopInvitationEmail::class, 1);
        Queue::assertPushed(fn (SendLoopInvitationEmail $job): bool => $job->invitationId === (string) $invitation->id);
    }

    public function test_aucun_envoi_synchrone_dans_la_requete(): void
    {
        Queue::fake();
        Mail::fake();

        $this->actingAs($this->owner)
            ->post(route('loops.invitations.store', $this->loop), ['email' => 'pas-synchrone@exemple.fr']);

        // Seul un job est mis en file (verifie ci-dessus). Un envoi
        // synchrone aurait ete enregistre par `Mail::fake()`.
        Mail::assertNothingSent();
    }

    // ── 2. Invitation interne : aucun second e-mail ─────────────────────────

    public function test_le_job_n_envoie_rien_pour_un_membre_deja_de_l_organization(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id, 'email' => 'membre@exemple.fr']);

        $invitation = LoopInvitation::factory()->forExistingMember()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => $membre->email,
        ]);

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        // `LoopInvitationMailer::send()` recalcule `emailHandledByNotifications()`
        // EN VIF et s'abstient — ce job ne doit pas contourner cette autorite.
        $this->assertDatabaseMissing('email_logs', ['to_email' => $membre->email]);
    }

    // ── 3. Organisation et Loop cohérentes, portées par l'invitation elle-même ──

    public function test_le_job_envoie_sous_l_organization_et_la_loop_de_l_invitation_malgre_un_tenant_different_au_depart(): void
    {
        $autreOrganization = Organization::factory()->create(['name' => 'Autre Organization']);
        app()->instance('current_organization', $autreOrganization);

        $invitation = LoopInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'coherence@exemple.fr',
        ]);

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        $log = EmailLog::where('to_email', 'coherence@exemple.fr')->firstOrFail();
        $this->assertSame((string) $this->organization->id, (string) $log->organization_id,
            'Le courriel doit etre journalise sous l\'Organization DE L\'INVITATION, jamais celle liee au tenant courant au moment du dispatch.');
    }

    // ── 4. Pas d'envoi tardif : expiree, revoquee ou acceptee ──────────────

    public function test_le_job_n_envoie_rien_pour_une_invitation_expiree(): void
    {
        $invitation = LoopInvitation::factory()->expired()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'expiree@exemple.fr',
        ]);

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => 'expiree@exemple.fr']);
    }

    public function test_le_job_n_envoie_rien_pour_une_invitation_revoquee(): void
    {
        $invitation = LoopInvitation::factory()->revoked()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'revoquee@exemple.fr',
        ]);

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => 'revoquee@exemple.fr']);
    }

    public function test_le_job_n_envoie_rien_pour_une_invitation_deja_acceptee(): void
    {
        $invitation = LoopInvitation::factory()->accepted()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'acceptee@exemple.fr',
        ]);

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => 'acceptee@exemple.fr']);
    }

    public function test_le_job_envoie_normalement_une_invitation_externe_toujours_pendante(): void
    {
        $invitation = LoopInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'pendante@exemple.fr',
        ]);

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseHas('email_logs', ['to_email' => 'pendante@exemple.fr', 'status' => 'sent']);
    }

    public function test_le_job_ignore_silencieusement_une_invitation_deja_supprimee(): void
    {
        $job = new SendLoopInvitationEmail((string) \Illuminate\Support\Str::uuid());

        $job->handle();

        $this->assertDatabaseCount('email_logs', 0);
    }

    // ── 5. Restauration du contexte tenant ──────────────────────────────────

    public function test_le_job_envoie_sans_aucun_contexte_de_requete_prealable(): void
    {
        $invitation = LoopInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'sans-contexte@exemple.fr',
        ]);

        app()->forgetInstance('current_organization');

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseHas('email_logs', ['to_email' => 'sans-contexte@exemple.fr']);
        $this->assertFalse(app()->bound('current_organization'),
            'Le job ne doit laisser aucun tenant residuel quand il n\'y en avait aucun avant son execution.');
    }

    public function test_un_tenant_preexistant_est_restaure_a_l_identique_apres_le_job(): void
    {
        $invitation = LoopInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'recipient_email' => 'tenant-restaure@exemple.fr',
        ]);

        $autreOrganization = Organization::factory()->create();
        app()->instance('current_organization', $autreOrganization);

        (new SendLoopInvitationEmail((string) $invitation->id))->handle();

        $this->assertSame($autreOrganization->id, app('current_organization')->id,
            'Le tenant precedent doit etre restaure EXACTEMENT, pas celui de l\'invitation traitee par le job.');
    }

    // ── 6. Integrite de l'invitation si la mise en file echoue ─────────────

    public function test_un_echec_de_mise_en_file_n_empeche_pas_la_creation_de_l_invitation(): void
    {
        $queueMock = Mockery::mock(QueueFactory::class);
        $queueMock->shouldReceive('connection')->andThrow(new \RuntimeException('Simulated queue connection failure'));
        $this->app->instance('queue', $queueMock);
        $this->app->instance(QueueFactory::class, $queueMock);

        $this->actingAs($this->owner)
            ->post(route('loops.invitations.store', $this->loop), ['email' => 'panne-file@exemple.fr'])
            ->assertRedirect();

        $this->assertDatabaseHas('loop_invitations', [
            'loop_id' => $this->loop->id,
            'recipient_email' => 'panne-file@exemple.fr',
        ]);
    }

    // ── 7. Routage Managed Queue — meme motif que les jobs existants ───────

    public function test_le_job_bascule_sur_la_managed_queue_quand_le_drapeau_est_pose(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications',
        ]);

        $job = new SendLoopInvitationEmail((string) \Illuminate\Support\Str::uuid());

        $this->assertSame('cloud', $job->connection);
        $this->assertSame('notifications', $job->queue);
    }

    public function test_le_job_reste_sur_la_file_dediee_par_defaut_sans_le_drapeau(): void
    {
        config(['queue.notifications_managed_queue_connection' => null]);

        $job = new SendLoopInvitationEmail((string) \Illuminate\Support\Str::uuid());

        $this->assertNull($job->connection);
        $this->assertSame(SendLoopInvitationEmail::DEDICATED_QUEUE, $job->queue);
    }
}
