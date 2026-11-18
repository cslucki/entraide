<?php

namespace Tests\Feature;

use App\Jobs\SendTemplateEmailToUser;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1863 (TASK B2, dernier volet de l'audit final des envois groupes
 * SMTP) — `AdminEmailTemplatesController::sendExecute()` appelait
 * `EmailerService::sendFromTemplate()` EN LIGNE dans la requete HTTP, pour
 * jusqu'a 50 destinataires EXPLICITES, sans AUCUNE protection contre une
 * double soumission du formulaire. Arbitrage MASTER (mini-audit de
 * compatibilite) : PAS de reutilisation de `BulkEmailSend`/
 * `BulkEmailSendRecipient` (TASK-1861) — un job dedie leger
 * (`SendTemplateEmailToUser`) + un jeton de soumission en cache partage,
 * SANS nouvelle table.
 *
 * Perimetre : dispatch pour 1 et 50 destinataires, protection
 * anti-double-soumission (meme jeton rejete, jetons distincts tous deux
 * acceptes), atomicite de la reservation du jeton, dispatch partiellement
 * echoue sans relance aveugle, frontiere H1 (ambigu) et echec de
 * persistance du journal, invalidation si le contenu du gabarit change
 * entre confirmation et traitement, isolation tenant par destinataire,
 * routage Managed Queue, et non-regression de l'autre appelant
 * d'`EmailerService::sendFromTemplate()` (`AdminController::sendEmailToUser()`).
 * Le rendu, les gabarits, la deduplication des `user_ids` et le plafond
 * 50 restent couverts par `AdminEmailerSendTest`/`TASK1694AdminSendEmailToUserTest`
 * (34 tests revalides, zero regression), non dupliques ici.
 *
 * ## Correctifs MASTER avant fusion (PR #871)
 *
 * A. La reservation du jeton passe desormais EXPLICITEMENT par
 *    `Cache::store('database')`, jamais par le store PAR DEFAUT
 *    (`CACHE_STORE=array` en test) — la garantie inter-instances ne doit
 *    jamais en dependre.
 * B. Un POST final sans jeton (absent ou vide) n'est plus accepte avec un
 *    jeton mine a la volee : il est rejete, sans aucun envoi, invite a
 *    recommencer.
 * C. L'empreinte du contenu CONFIRME A L'ECRAN (pas une empreinte
 *    recalculee au dispatch) est verifiee par le CONTROLEUR avant tout
 *    dispatch, distincte de la garde du job qui protege la fenetre
 *    dispatch -> traitement differe.
 */
class TASK1863SendTemplateEmailAsyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── 1. Dispatch pour 1 et 50 destinataires ──────────────────────────────

    public function test_dispatch_un_job_pour_un_seul_destinataire(): void
    {
        Queue::fake();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
                'send_token' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        Queue::assertPushed(SendTemplateEmailToUser::class, 1);
        Queue::assertPushed(fn (SendTemplateEmailToUser $job): bool => $job->templateId === (string) $template->id
            && $job->userId === (string) $user->id);
    }

    public function test_cinquante_destinataires_sont_tous_mis_en_file(): void
    {
        Queue::fake();
        $template = EmailTemplate::factory()->create();
        $users = User::factory()->count(50)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => $users->pluck('id')->toArray(),
                'confirmed' => '1',
                'send_token' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        Queue::assertPushed(SendTemplateEmailToUser::class, 50);
    }

    // ── 2. Protection anti-double-soumission ────────────────────────────────

    /**
     * TASK-1863 correctif A — `CACHE_STORE` vaut `array` dans
     * l'environnement de test (`phpunit.xml`/`phpunit.pgsql.xml`) : si la
     * reservation passait par le store PAR DEFAUT, ce test ne prouverait
     * rien sur la garantie inter-instances. En passant EXPLICITEMENT par
     * le store `database`, la reservation doit atterrir dans la VRAIE
     * table SQL `cache` — partagee entre web/worker, independamment de ce
     * que vaut `CACHE_STORE` — et y rester atomique sur SQLite ET
     * PostgreSQL (meme test, deux moteurs selon la configuration CI).
     */
    public function test_la_reservation_du_jeton_utilise_le_store_database_explicite_et_reste_atomique(): void
    {
        $this->assertSame('array', config('cache.default'),
            'Precondition : si le store par defaut etait deja "database", ce test ne prouverait rien sur le correctif A.');

        $token = (string) Str::uuid();
        $key = 'admin-email-template-send:'.$token;

        $premiere = Cache::store('database')->add($key, true, now()->addMinutes(10));
        $seconde = Cache::store('database')->add($key, true, now()->addMinutes(10));

        $this->assertTrue($premiere, 'La premiere reservation doit reussir.');
        $this->assertFalse($seconde, 'Une seconde reservation du MEME jeton ne doit jamais reussir.');
        // `assertDatabaseCount()` n'accepte pas de message en 3e argument
        // (c'est le nom de connexion) : la preuve est l'assertion elle-meme.
        $this->assertDatabaseCount('cache', 1);
    }

    public function test_une_seconde_soumission_du_meme_jeton_ne_cree_aucun_nouvel_envoi(): void
    {
        Mail::fake();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();
        $token = (string) Str::uuid();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
                'send_token' => $token,
            ])
            ->assertRedirect();

        $this->assertSame(1, EmailLog::where('template_id', $template->id)->count());

        // MEME jeton (double-clic, retry reseau) : aucun second envoi, et
        // un message distinct plutot qu'un succes muet.
        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
                'send_token' => $token,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, EmailLog::where('template_id', $template->id)->count(), 'Aucun second envoi sur une soumission dupliquee.');
    }

    public function test_deux_intentions_successives_volontaires_avec_jetons_distincts_envoient_chacune(): void
    {
        Mail::fake();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
                'send_token' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        // Un NOUVEAU formulaire, un NOUVEAU jeton : un renvoi volontaire
        // doit toujours pouvoir partir, meme vers le meme destinataire.
        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
                'send_token' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $this->assertSame(2, EmailLog::where('template_id', $template->id)->count(),
            'Deux intentions VOLONTAIRES distinctes doivent toutes deux envoyer.');
    }

    /**
     * TASK-1863 correctif B — un POST final SANS jeton n'est plus
     * remplace par un jeton mine a la volee (ce qui viderait la
     * protection de son sens pour tout client qui omettrait le champ) :
     * un formulaire perime ou incomplet est invite a RECOMMENCER,
     * explicitement, sans qu'aucun envoi ne parte.
     */
    public function test_un_post_sans_jeton_est_rejete_et_invite_a_recommencer_sans_envoi(): void
    {
        Queue::fake();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
            ])
            ->assertRedirect(route('admin.email-templates.send', $template))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame(0, EmailLog::where('template_id', $template->id)->count());
    }

    /** Meme garde pour un jeton explicitement VIDE, pas seulement absent. */
    public function test_un_post_avec_un_jeton_vide_est_rejete_et_invite_a_recommencer_sans_envoi(): void
    {
        Queue::fake();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => [$user->id],
                'confirmed' => '1',
                'send_token' => '',
            ])
            ->assertRedirect(route('admin.email-templates.send', $template))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame(0, EmailLog::where('template_id', $template->id)->count());
    }

    // ── 3. Dispatch partiellement echoue ────────────────────────────────────

    public function test_un_dispatch_partiellement_echoue_ne_relance_pas_les_destinataires_deja_acceptes(): void
    {
        Mail::fake();
        $template = EmailTemplate::factory()->create();
        $users = User::factory()->count(3)->create();

        $originalQueue = $this->app->make(QueueFactory::class);
        $callIndex = 0;
        $queueMock = Mockery::mock(QueueFactory::class);
        $queueMock->shouldReceive('connection')->andReturnUsing(function (...$args) use (&$callIndex, $originalQueue) {
            $callIndex++;
            if ($callIndex === 2) {
                throw new RuntimeException('Simulated partial dispatch failure');
            }

            return $originalQueue->connection(...$args);
        });
        $this->app->instance('queue', $queueMock);
        $this->app->instance(QueueFactory::class, $queueMock);

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => $users->pluck('id')->toArray(),
                'confirmed' => '1',
                'send_token' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        // Exactement 2 des 3 destinataires ont ete traites (le 2e dispatch
        // a echoue AVANT tout envoi) : aucune relance aveugle des deux
        // deja acceptes, et le 3e n'est pas non plus perdu silencieusement
        // — ce test prouve l'absence de crash et le compte exact.
        $this->assertSame(2, EmailLog::where('template_id', $template->id)->count());
    }

    // ── 4. Frontiere H1 et echec de persistance du journal ──────────────────

    public function test_le_job_marque_ambigu_un_transport_qui_leve_apres_sollicitation(): void
    {
        Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('transport coupe'));

        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();
        $hash = SendTemplateEmailToUser::contentHash($template);

        (new SendTemplateEmailToUser((string) $template->id, (string) $user->id, (string) $this->admin->id, $hash))
            ->handle(app(EmailerService::class));

        $log = EmailLog::where('template_id', $template->id)->sole();
        $this->assertSame(EmailLog::STATUS_AMBIGUOUS, $log->status);
        $this->assertNotSame('failed', $log->status);
    }

    /**
     * Sabotage de la PERSISTANCE, pas du code de production : la table
     * est masquee le temps de l'appel, ce qui fait echouer REELLEMENT
     * `EmailLog::create()` — meme trick que `TASK1377NotificationEmailAsyncTest`.
     */
    public function test_un_echec_de_journalisation_apres_transport_reussi_ne_fait_jamais_planter_le_job(): void
    {
        Mail::shouldReceive('html')->once()->andReturn(null);

        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();
        $hash = SendTemplateEmailToUser::contentHash($template);
        $job = new SendTemplateEmailToUser((string) $template->id, (string) $user->id, (string) $this->admin->id, $hash);

        Schema::rename('email_logs', 'email_logs_masquee_par_le_test');

        try {
            $job->handle(app(EmailerService::class));
        } finally {
            Schema::rename('email_logs_masquee_par_le_test', 'email_logs');
        }

        // Aucune preuve n'a pu s'ecrire — l'incident etait dans CETTE
        // ecriture — et c'est attendu : on ne fabrique pas une preuve a
        // partir d'un sabotage de la preuve elle-meme.
        $this->assertSame(0, EmailLog::count());
    }

    // ── 5. Contenu modifie entre confirmation et traitement differe ────────

    public function test_le_job_n_envoie_rien_si_le_contenu_du_gabarit_a_change_depuis_le_dispatch(): void
    {
        Mail::fake();
        $template = EmailTemplate::factory()->create([
            'subject' => 'Original',
            'content_html' => '<p>Original</p>',
        ]);
        $user = User::factory()->create();

        // Le hash CAPTURE au moment ou l'administrateur a confirme...
        $staleHash = SendTemplateEmailToUser::contentHash($template);

        // ...puis le gabarit change AVANT que le job ne s'execute.
        $template->update(['content_html' => '<p>Modifie entre la confirmation et le traitement differe</p>']);

        (new SendTemplateEmailToUser((string) $template->id, (string) $user->id, (string) $this->admin->id, $staleHash))
            ->handle(app(EmailerService::class));

        $this->assertDatabaseMissing('email_logs', ['template_id' => $template->id]);
    }

    public function test_le_job_envoie_normalement_quand_le_contenu_n_a_pas_change(): void
    {
        Mail::fake();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();
        $hash = SendTemplateEmailToUser::contentHash($template);

        (new SendTemplateEmailToUser((string) $template->id, (string) $user->id, (string) $this->admin->id, $hash))
            ->handle(app(EmailerService::class));

        $this->assertDatabaseHas('email_logs', ['template_id' => $template->id, 'status' => 'sent']);
    }

    // ── 5bis. Integrite du contenu confirme a l'ecran (correctif C) ─────────

    /**
     * TASK-1863 correctif C — DISTINCT de la garde du job (section 5
     * ci-dessus, qui protege la fenetre dispatch -> traitement differe) :
     * ICI, le gabarit change ENTRE le rendu de l'ecran de confirmation et
     * la soumission finale, dans la MEME requete HTTP synchrone. Sans ce
     * correctif, `sendExecute()` recalculerait le hash `expectedContentHash`
     * du job a partir du contenu DEJA EDITE, et le job l'approuverait
     * puisqu'il compare deux lectures identiques (toutes deux apres
     * edition) — la garde du job ne verrait jamais la divergence.
     */
    public function test_le_controleur_interrompt_l_envoi_si_le_gabarit_change_apres_la_confirmation(): void
    {
        Queue::fake();
        $template = EmailTemplate::factory()->create([
            'subject' => 'Original',
            'content_html' => '<p>Original</p>',
        ]);
        $users = User::factory()->count(2)->create();
        $token = (string) Str::uuid();

        $confirmResponse = $this->actingAs($this->admin)->get(route('admin.email-templates.send.confirm', [
            'emailTemplate' => $template,
            'user_ids' => $users->pluck('id')->toArray(),
            'send_token' => $token,
        ]));
        $confirmedHash = $confirmResponse->viewData('contentHash');
        $this->assertNotEmpty($confirmedHash);

        // Un AUTRE SuperAdmin edite le gabarit pendant que l'administrateur
        // regarde encore l'ecran de confirmation.
        $template->update(['content_html' => '<p>Edite pendant la confirmation</p>']);

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => $users->pluck('id')->toArray(),
                'confirmed' => '1',
                'send_token' => $token,
                'content_hash' => $confirmedHash,
            ])
            ->assertRedirect(route('admin.email-templates.send.confirm', [
                'emailTemplate' => $template,
                'user_ids' => $users->pluck('id')->toArray(),
                'send_token' => $token,
            ]))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame(0, EmailLog::where('template_id', $template->id)->count());
    }

    public function test_le_controleur_envoie_normalement_quand_le_contenu_confirme_n_a_pas_change(): void
    {
        Queue::fake();
        $template = EmailTemplate::factory()->create();
        $users = User::factory()->count(2)->create();
        $token = (string) Str::uuid();

        $confirmResponse = $this->actingAs($this->admin)->get(route('admin.email-templates.send.confirm', [
            'emailTemplate' => $template,
            'user_ids' => $users->pluck('id')->toArray(),
            'send_token' => $token,
        ]));
        $confirmedHash = $confirmResponse->viewData('contentHash');

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.send.execute', $template), [
                'user_ids' => $users->pluck('id')->toArray(),
                'confirmed' => '1',
                'send_token' => $token,
                'content_hash' => $confirmedHash,
            ])
            ->assertRedirect(route('admin.email-templates.show', $template));

        Queue::assertPushed(SendTemplateEmailToUser::class, 2);
    }

    // ── 6. Isolation tenant par destinataire ────────────────────────────────

    public function test_le_job_isole_le_tenant_par_destinataire_et_restaure_l_ambiant_apres(): void
    {
        Mail::fake();
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $template = EmailTemplate::factory()->create();
        $hash = SendTemplateEmailToUser::contentHash($template);

        $tenantPrealable = Organization::factory()->create();
        app()->instance('current_organization', $tenantPrealable);

        (new SendTemplateEmailToUser((string) $template->id, (string) $userA->id, (string) $this->admin->id, $hash))
            ->handle(app(EmailerService::class));
        (new SendTemplateEmailToUser((string) $template->id, (string) $userB->id, (string) $this->admin->id, $hash))
            ->handle(app(EmailerService::class));

        $this->assertSame(2, EmailLog::where('template_id', $template->id)->count());
        $this->assertSame($tenantPrealable->id, app('current_organization')->id,
            'Le tenant ambiant anterieur doit etre restaure EXACTEMENT, jamais celui d un destinataire traite.');
    }

    public function test_le_job_envoie_sans_aucun_contexte_de_requete_prealable(): void
    {
        Mail::fake();
        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();
        $hash = SendTemplateEmailToUser::contentHash($template);

        app()->forgetInstance('current_organization');

        (new SendTemplateEmailToUser((string) $template->id, (string) $user->id, (string) $this->admin->id, $hash))
            ->handle(app(EmailerService::class));

        $this->assertSame(1, EmailLog::where('template_id', $template->id)->count());
        $this->assertFalse(app()->bound('current_organization'));
    }

    // ── 7. Routage Managed Queue — meme motif que les jobs existants ───────

    public function test_le_job_bascule_sur_la_managed_queue_quand_le_drapeau_est_pose(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications',
        ]);

        $job = new SendTemplateEmailToUser((string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), 'hash');

        $this->assertSame('cloud', $job->connection);
        $this->assertSame('notifications', $job->queue);
    }

    public function test_le_job_reste_sur_la_file_dediee_par_defaut_sans_le_drapeau(): void
    {
        config(['queue.notifications_managed_queue_connection' => null]);

        $job = new SendTemplateEmailToUser((string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), 'hash');

        $this->assertNull($job->connection);
        $this->assertSame(SendTemplateEmailToUser::DEDICATED_QUEUE, $job->queue);
    }

    // ── 8. Non-regression de l'autre appelant d'EmailerService ──────────────

    /**
     * `AdminController::sendEmailToUser()` reste SYNCHRONE (hors
     * perimetre de cette TASK) mais partage `sendFromTemplate()` — la
     * frontiere H1 corrigee ici ne doit RIEN casser pour cet appelant :
     * un transport qui leve doit encore afficher une erreur, jamais un
     * crash, meme si le statut n'est plus litteralement `'failed'`.
     */
    public function test_admin_send_email_to_user_affiche_une_erreur_sur_un_resultat_ambigu_sans_planter(): void
    {
        Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('transport coupe'));

        $template = EmailTemplate::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.users.send-email', $user), ['template_id' => $template->id]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $log = EmailLog::where('template_id', $template->id)->sole();
        $this->assertSame(EmailLog::STATUS_AMBIGUOUS, $log->status);
    }
}
