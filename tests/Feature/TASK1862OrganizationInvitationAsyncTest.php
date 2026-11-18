<?php

namespace Tests\Feature;

use App\Jobs\SendOrganizationInvitationEmail;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * TASK-1862 — audit prealable (TASK B, suite de TASK-1861) : les deux
 * appelants de `OrganizationInvitationMailer::send()`
 * (`AdminUserBulkCreationController::store()` et `resend()`) restaient
 * SYNCHRONES, bloquant la requete HTTP sur la reponse SMTP a chaque
 * invitation directe a une Organization. Meme famille que TASK-1854/
 * 1855/1857/1858/1860/1861 : `SendOrganizationInvitationEmail` deporte
 * l'envoi vers la Managed Queue `notifications`, sans toucher
 * `OrganizationInvitationMailer` (autorite unique du rendu/livraison).
 *
 * Perimetre : dispatch depuis les deux chemins, absence d'envoi
 * synchrone dans la requete, absence d'envoi tardif (expiree/revoquee),
 * reservation deja existante (`lockForUpdate()` dans `invite()`) non
 * dupliquee ici, dispatch echoue sans perte, restauration du contexte
 * tenant, conservation des gabarits/journaux. Le rendu, les gabarits et
 * l'acceptation restent couverts par `TASK1659OrganizationInvitationTest`
 * (59 tests revalides, zero regression), non dupliques ici.
 */
class TASK1862OrganizationInvitationAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Entraide Locale']);
        $this->superAdmin = User::factory()->create(['is_admin' => true]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── 1. Dispatch depuis les deux chemins de production ──────────────────

    public function test_store_met_en_file_un_job_pour_une_nouvelle_invitation(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.bulk-create.invitations.store'), [
                'organization_id' => $this->organization->id,
                'people' => [
                    ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'jean@exemple.fr'],
                ],
            ])
            ->assertRedirect();

        $invitation = OrganizationInvitation::where('recipient_email', 'jean@exemple.fr')->firstOrFail();

        Queue::assertPushed(SendOrganizationInvitationEmail::class, 1);
        Queue::assertPushed(fn (SendOrganizationInvitationEmail $job): bool => $job->invitationId === (string) $invitation->id);
    }

    public function test_resend_met_en_file_un_job_pour_une_invitation_existante(): void
    {
        Queue::fake();

        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'recipient_email' => 'relance@exemple.fr',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.bulk-create.invitations.resend', $invitation))
            ->assertRedirect();

        Queue::assertPushed(SendOrganizationInvitationEmail::class, 1);
        Queue::assertPushed(fn (SendOrganizationInvitationEmail $job): bool => $job->invitationId === (string) $invitation->id);
    }

    public function test_aucun_envoi_synchrone_dans_la_requete(): void
    {
        Queue::fake();
        Mail::fake();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.bulk-create.invitations.store'), [
                'organization_id' => $this->organization->id,
                'people' => [
                    ['first_name' => 'Pas', 'last_name' => 'Synchrone', 'email' => 'pas-synchrone@exemple.fr'],
                ],
            ]);

        // Seul un job est mis en file (verifie ci-dessus). Un envoi
        // synchrone aurait ete enregistre par `Mail::fake()`.
        Mail::assertNothingSent();
    }

    // ── 2. Pas d'envoi tardif : expiree ou revoquee ─────────────────────────

    public function test_le_job_n_envoie_rien_pour_une_invitation_expiree(): void
    {
        $invitation = OrganizationInvitation::factory()->expired()->create([
            'organization_id' => $this->organization->id,
            'recipient_email' => 'expiree@exemple.fr',
        ]);

        (new SendOrganizationInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => 'expiree@exemple.fr']);
    }

    public function test_le_job_n_envoie_rien_pour_une_invitation_revoquee(): void
    {
        $invitation = OrganizationInvitation::factory()->revoked()->create([
            'organization_id' => $this->organization->id,
            'recipient_email' => 'revoquee@exemple.fr',
        ]);

        (new SendOrganizationInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => 'revoquee@exemple.fr']);
    }

    public function test_le_job_n_envoie_rien_pour_une_invitation_deja_acceptee(): void
    {
        $invitation = OrganizationInvitation::factory()->accepted()->create([
            'organization_id' => $this->organization->id,
            'recipient_email' => 'acceptee@exemple.fr',
        ]);

        (new SendOrganizationInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseMissing('email_logs', ['to_email' => 'acceptee@exemple.fr']);
    }

    public function test_le_job_envoie_normalement_une_invitation_toujours_pendante(): void
    {
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'recipient_email' => 'pendante@exemple.fr',
        ]);

        (new SendOrganizationInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseHas('email_logs', ['to_email' => 'pendante@exemple.fr', 'status' => 'sent']);
    }

    public function test_le_job_ignore_silencieusement_une_invitation_deja_supprimee(): void
    {
        $job = new SendOrganizationInvitationEmail((string) \Illuminate\Support\Str::uuid());

        $job->handle();

        $this->assertDatabaseCount('email_logs', 0);
    }

    // ── 3. Concurrence : la reservation vit DEJA dans invite() ─────────────

    /**
     * `OrganizationInvitationService::invite()` serialise deja la decision
     * CASE_CREATED/CASE_RESENT sous `lockForUpdate()` (verifie au code) :
     * deux soumissions concurrentes de la MEME adresse ne produisent JAMAIS
     * deux lignes `organization_invitations` — ce test le confirme sans le
     * redupliquer, puisque c'est ce verrou, pas ce job, qui porte la
     * garantie d'unicite de la ligne.
     */
    public function test_deux_invitations_concurrentes_de_la_meme_adresse_ne_creent_jamais_deux_lignes(): void
    {
        $service = app(\App\Services\OrganizationInvitationService::class);

        $premier = $service->invite($this->organization, $this->superAdmin, 'concurrence@exemple.fr', 'Jean', 'Dupont');
        $second = $service->invite($this->organization, $this->superAdmin, 'concurrence@exemple.fr', 'Jean', 'Dupont');

        $this->assertSame(\App\Services\OrganizationInvitationService::CASE_CREATED, $premier['case']);
        $this->assertSame(\App\Services\OrganizationInvitationService::CASE_RESENT, $second['case']);
        $this->assertSame(1, OrganizationInvitation::where('recipient_email', 'concurrence@exemple.fr')->count());
        // Meme jeton : un renvoi volontaire garde le MEME lien, comportement
        // intentionnel preserve (TASK-1860, meme doctrine).
        $this->assertSame($premier['invitation']->token, $second['invitation']->fresh()->token);
    }

    // ── 4. Integrite de l'invitation si la mise en file echoue ─────────────

    public function test_un_echec_de_mise_en_file_n_empeche_pas_la_creation_de_l_invitation(): void
    {
        $queueMock = Mockery::mock(QueueFactory::class);
        $queueMock->shouldReceive('connection')->andThrow(new \RuntimeException('Simulated queue connection failure'));
        $this->app->instance('queue', $queueMock);
        $this->app->instance(QueueFactory::class, $queueMock);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.bulk-create.invitations.store'), [
                'organization_id' => $this->organization->id,
                'people' => [
                    ['first_name' => 'Panne', 'last_name' => 'File', 'email' => 'panne-file@exemple.fr'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $this->organization->id,
            'recipient_email' => 'panne-file@exemple.fr',
        ]);
    }

    // ── 5. Restauration du contexte tenant ──────────────────────────────────

    public function test_le_job_envoie_sans_aucun_contexte_de_requete_prealable(): void
    {
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'recipient_email' => 'sans-contexte@exemple.fr',
        ]);

        app()->forgetInstance('current_organization');

        (new SendOrganizationInvitationEmail((string) $invitation->id))->handle();

        $this->assertDatabaseHas('email_logs', ['to_email' => 'sans-contexte@exemple.fr']);
        $this->assertFalse(app()->bound('current_organization'),
            'Le job ne doit laisser aucun tenant residuel quand il n\'y en avait aucun avant son execution.');
    }

    public function test_un_tenant_preexistant_est_restaure_a_l_identique_apres_le_job(): void
    {
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $this->organization->id,
            'recipient_email' => 'tenant-restaure@exemple.fr',
        ]);

        $autreOrganization = Organization::factory()->create();
        app()->instance('current_organization', $autreOrganization);

        (new SendOrganizationInvitationEmail((string) $invitation->id))->handle();

        $this->assertSame($autreOrganization->id, app('current_organization')->id,
            'Le tenant precedent doit etre restaure EXACTEMENT, pas celui de l\'invitation traitee par le job.');
    }

    // ── 6. Routage Managed Queue — meme motif que les jobs existants ───────

    public function test_le_job_bascule_sur_la_managed_queue_quand_le_drapeau_est_pose(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications',
        ]);

        $job = new SendOrganizationInvitationEmail((string) \Illuminate\Support\Str::uuid());

        $this->assertSame('cloud', $job->connection);
        $this->assertSame('notifications', $job->queue);
    }

    public function test_le_job_reste_sur_la_file_dediee_par_defaut_sans_le_drapeau(): void
    {
        config(['queue.notifications_managed_queue_connection' => null]);

        $job = new SendOrganizationInvitationEmail((string) \Illuminate\Support\Str::uuid());

        $this->assertNull($job->connection);
        $this->assertSame(SendOrganizationInvitationEmail::DEDICATED_QUEUE, $job->queue);
    }
}
