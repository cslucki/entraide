<?php

namespace Tests\Feature;

use App\Jobs\SendChildSafetyReportAlert;
use App\Models\BugReport;
use App\Models\BugReportAction;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\Reports\ChildSafetyAlertMailer;
use App\Support\Reports\ChildSafetyReportAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * TASK-1905 — alerte email et suivi des signalements « Securite des enfants ».
 *
 * Decisions MASTER : adresse `CHILD_SAFETY_ALERT_EMAIL` (D1), gabarit global
 * FR/EN (D2), superadmin strict sans comptes de demonstration en production
 * (D3), suppression interdite (D4), historique avec note interne (D5), email
 * sans description / signaleur / URL (D6), job apres commit sans rejeu (D7).
 */
class TASK1905ChildSafetyAlertTest extends TestCase
{
    use RefreshDatabase;

    private const ALERTE = 'alerte-1905@example.org';

    private Organization $org;

    private User $membre;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['child_safety.alert_email' => self::ALERTE, 'child_safety.alert_locale' => 'fr']);

        $this->org = Organization::factory()->create(['slug' => 't1905', 'name' => 'Organisation Alerte 1905', 'is_active' => true, 'is_public' => true, 'is_default' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => false, 'name' => 'Signaleur Secret 1905', 'email' => 'signaleur-secret-1905@example.org']);
        $this->superadmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true, 'name' => 'Super Admin 1905', 'email' => 'super-1905@example.org']);
    }

    private function signalementEnfant(): BugReport
    {
        return BugReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->membre->id,
            'category' => BugReport::CHILD_SAFETY,
            'reason' => BugReport::CHILD_SAFETY,
            'details' => 'DESCRIPTION SENSIBLE 1905',
            'page_url' => 'http://localhost/org/t1905/profil-concerne-1905',
            'status' => 'pending',
        ]);
    }

    private function transportArray(): void
    {
        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    /** @return list<SentMessage> */
    private function messages(): array
    {
        return array_values(Mail::mailer()->getSymfonyTransport()->messages()->all());
    }

    // ── Declenchement ──────────────────────────────────────────────────────

    public function test_un_signalement_enfant_met_en_file_un_seul_job_apres_commit_sans_rejeu(): void
    {
        Queue::fake();

        $this->actingAs($this->membre)
            ->post(route('organization.bug-reports.store', $this->org), ['category' => 'child_safety', 'details' => 'Detail 1905'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('bug_reports', ['category' => 'child_safety', 'details' => 'Detail 1905']);
        Queue::assertPushed(SendChildSafetyReportAlert::class, 1);
        Queue::assertPushed(SendChildSafetyReportAlert::class, function (SendChildSafetyReportAlert $job) {
            return $job->tries === 1 && $job->afterCommit === true;
        });
    }

    public function test_une_autre_categorie_ne_declenche_aucune_alerte(): void
    {
        Queue::fake();

        foreach (array_diff(BugReport::CATEGORIES, [BugReport::CHILD_SAFETY]) as $category) {
            $this->actingAs($this->membre)->post(route('organization.bug-reports.store', $this->org), ['category' => $category, 'details' => "x {$category}"]);
        }

        Queue::assertNotPushed(SendChildSafetyReportAlert::class);
    }

    public function test_une_file_indisponible_n_empeche_jamais_l_enregistrement(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('file indisponible'));

        $this->actingAs($this->membre)
            ->post(route('organization.bug-reports.store', $this->org), ['category' => 'child_safety', 'details' => 'Detail file KO 1905'])
            ->assertRedirect()->assertSessionHas('success');

        $report = BugReport::where('details', 'Detail file KO 1905')->firstOrFail();
        $this->assertSame([BugReportAction::ALERT_FAILED], $report->actions()->pluck('action')->all());
    }

    // ── Contenu, idempotence, echec ────────────────────────────────────────

    public function test_l_email_part_au_contact_avec_l_organisation_et_jamais_les_donnees_sensibles(): void
    {
        $this->transportArray();
        $report = $this->signalementEnfant();

        (new SendChildSafetyReportAlert($report->id))->handle(app(ChildSafetyAlertMailer::class));

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame(self::ALERTE, $email->getTo()[0]->getAddress());

        $tout = $email->getSubject().' '.$email->getHtmlBody();
        $this->assertStringContainsString('Organisation Alerte 1905', $tout);
        $this->assertStringContainsString('/admin/bugs-reports?category=child_safety', html_entity_decode($tout));
        foreach (['DESCRIPTION SENSIBLE 1905', 'Signaleur Secret 1905', 'signaleur-secret-1905@example.org', 'profil-concerne-1905'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $tout, "l'email expose : {$interdit}");
        }
        $this->assertStringNotContainsString('Sécurité des enfants', $email->getSubject(), 'objet neutre');

        $log = EmailLog::where('to_email', self::ALERTE)->sole();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->organization_id, 'la trace ne doit apparaitre dans aucun ecran d organisation');
        $this->assertNull($log->user_id);
        $this->assertSame('child-safety-alert', $log->data['source']);
        $this->assertSame('system_email_template', $log->data['template_used'], 'le gabarit global de la migration est utilise');

        $this->assertNotNull($report->fresh()->child_safety_alerted_at);
        $this->assertSame([BugReportAction::ALERT_SENT], $report->actions()->pluck('action')->all());
    }

    public function test_un_second_passage_n_envoie_rien(): void
    {
        $this->transportArray();
        $report = $this->signalementEnfant();
        $mailer = app(ChildSafetyAlertMailer::class);

        $this->assertSame(ChildSafetyAlertMailer::SENT, $mailer->envoyer($report));
        $this->assertSame(ChildSafetyAlertMailer::DUPLICATE, $mailer->envoyer($report->fresh()));
        (new SendChildSafetyReportAlert($report->id))->handle($mailer);

        $this->assertCount(1, $this->messages());
        $this->assertSame(1, EmailLog::where('to_email', self::ALERTE)->count());
    }

    /**
     * Course simulee : deux executions ont lu le signalement AVANT toute
     * reservation (instance en memoire sans `child_safety_alerted_at`). La
     * premiere reserve en base ; la seconde, avec son instance perimee, doit
     * echouer sur la reservation ATOMIQUE et ne rien envoyer.
     */
    public function test_en_dispatch_concurrent_seule_la_reservation_atomique_envoie(): void
    {
        $this->transportArray();
        $report = $this->signalementEnfant();
        $instancePerimee = BugReport::find($report->id);
        $mailer = app(ChildSafetyAlertMailer::class);

        $this->assertSame(ChildSafetyAlertMailer::SENT, $mailer->envoyer(BugReport::find($report->id)));
        $this->assertNull($instancePerimee->child_safety_alerted_at);
        $this->assertSame(ChildSafetyAlertMailer::DUPLICATE, $mailer->envoyer($instancePerimee));

        $this->assertCount(1, $this->messages());
    }

    public function test_un_echec_smtp_est_trace_sans_exception_ni_rejeu_et_le_signalement_reste(): void
    {
        $report = $this->signalementEnfant();
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('smtp indisponible'));

        $resultat = app(ChildSafetyAlertMailer::class)->envoyer($report);

        $this->assertSame(ChildSafetyAlertMailer::FAILED, $resultat);
        $this->assertDatabaseHas('bug_reports', ['id' => $report->id, 'status' => 'pending']);
        $this->assertSame(EmailLog::STATUS_FAILED, EmailLog::where('to_email', self::ALERTE)->sole()->status);
        $this->assertSame([BugReportAction::ALERT_FAILED], $report->actions()->pluck('action')->all());
        $this->assertSame(1, (new SendChildSafetyReportAlert($report->id))->tries);
    }

    public function test_une_adresse_absente_produit_une_alerte_technique_explicite(): void
    {
        config(['child_safety.alert_email' => null]);
        $this->transportArray();
        Log::spy();
        $report = $this->signalementEnfant();

        $this->assertSame(ChildSafetyAlertMailer::NOT_CONFIGURED, app(ChildSafetyAlertMailer::class)->envoyer($report));

        $this->assertCount(0, $this->messages());
        Log::shouldHaveReceived('critical')->once()->withArgs(fn ($message) => str_contains($message, 'CHILD_SAFETY_ALERT_EMAIL'));
        $this->assertSame([BugReportAction::ALERT_NOT_CONFIGURED], $report->actions()->pluck('action')->all());

        $this->actingAs($this->superadmin)->get(route('admin.bug-reports'))
            ->assertOk()->assertSee('data-child-safety-alert-missing', false);
    }

    public function test_une_autre_categorie_n_est_jamais_alertee_par_le_mailer(): void
    {
        $this->transportArray();
        $report = BugReport::create(['organization_id' => $this->org->id, 'reporter_id' => $this->membre->id, 'category' => 'spam_fraud', 'reason' => 'spam_fraud', 'details' => 'x']);

        $this->assertSame(ChildSafetyAlertMailer::SKIPPED, app(ChildSafetyAlertMailer::class)->envoyer($report));
        $this->assertCount(0, $this->messages());
    }

    // ── Historique et notes internes ───────────────────────────────────────

    public function test_traiter_et_classer_tracent_auteur_date_et_note_interne_jamais_visible_du_membre(): void
    {
        $a = $this->signalementEnfant();
        $b = $this->signalementEnfant();

        $this->actingAs($this->superadmin)->patch(route('admin.bug-reports.fix', $a), [
            'resolution_notes' => 'REPONSE A L AUTEUR 1905',
            'internal_note' => 'NOTE INTERNE SECRETE 1905',
        ])->assertRedirect();
        $this->actingAs($this->superadmin)->patch(route('admin.bug-reports.dismiss', $b), [
            'internal_note' => 'NOTE CLASSEMENT 1905',
        ])->assertRedirect();

        $actionA = $a->actions()->sole();
        $this->assertSame([BugReportAction::FIXED, $this->superadmin->id, 'NOTE INTERNE SECRETE 1905'], [$actionA->action, $actionA->actor_id, $actionA->note]);
        $this->assertSame('REPONSE A L AUTEUR 1905', $a->fresh()->resolution_notes, 'la reponse a l auteur reste distincte');
        $this->assertSame(BugReportAction::DISMISSED, $b->actions()->sole()->action);
        $this->assertNotNull($b->fresh()->fixed_at, 'Classer date desormais le traitement');

        // Le back-office montre l'historique et la note interne...
        $this->actingAs($this->superadmin)->get(route('admin.bug-reports'))
            ->assertSee('NOTE INTERNE SECRETE 1905')->assertSee('data-report-history', false);

        // ...le membre voit la reponse, JAMAIS la note interne.
        $this->actingAs($this->membre)->get(route('organization.bug-reports.index', $this->org))
            ->assertOk()->assertSee('REPONSE A L AUTEUR 1905')
            ->assertDontSee('NOTE INTERNE SECRETE 1905')->assertDontSee('NOTE CLASSEMENT 1905');
    }

    public function test_la_resolution_par_l_admin_d_organisation_est_tracee(): void
    {
        $adminOrg = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => false]);
        $this->org->update(['admin_id' => $adminOrg->id]);
        $report = BugReport::create(['organization_id' => $this->org->id, 'reporter_id' => $this->membre->id, 'category' => 'spam_fraud', 'reason' => 'spam_fraud', 'details' => 'x']);

        $this->actingAs($adminOrg)->patch(route('organization.admin.reports.resolve', [$this->org, $report]))->assertRedirect();

        $action = $report->actions()->sole();
        $this->assertSame([BugReportAction::RESOLVED, $adminOrg->id], [$action->action, $action->actor_id]);
    }

    // ── Suppression interdite ──────────────────────────────────────────────

    public function test_un_signalement_enfant_ne_peut_jamais_etre_supprime(): void
    {
        $report = $this->signalementEnfant();

        $html = $this->actingAs($this->superadmin)->get(route('admin.bug-reports'))->getContent();
        $this->assertStringNotContainsString('action="'.route('admin.bug-reports.destroy', $report).'"', $html, 'bouton masque');
        $this->assertStringContainsString('data-delete-forbidden', $html);

        $this->actingAs($this->superadmin)->delete(route('admin.bug-reports.destroy', $report))->assertForbidden();
        $this->assertDatabaseHas('bug_reports', ['id' => $report->id]);

        // Garde modele : aucun chemin Eloquent.
        $this->expectException(\LogicException::class);
        $report->delete();
    }

    public function test_une_autre_categorie_reste_supprimable(): void
    {
        $report = BugReport::create(['organization_id' => $this->org->id, 'reporter_id' => $this->membre->id, 'category' => 'technical_bug', 'reason' => 'technical_bug', 'details' => 'x']);

        $this->actingAs($this->superadmin)->delete(route('admin.bug-reports.destroy', $report))->assertRedirect();
        $this->assertDatabaseMissing('bug_reports', ['id' => $report->id]);
    }

    // ── Acces superadmin strict ────────────────────────────────────────────

    /** @return array<string, array{string, bool}> */
    public static function adressesEnProduction(): array
    {
        return [
            'superadmin reel' => ['cyril-1905@example-asso.fr', true],
            'compte .test' => ['admin@bouclepro.test', false],
            'compte de demonstration .invalid' => ['julien@demo-bouclepro.invalid', false],
            'compte .example' => ['demo@site.example', false],
        ];
    }

    #[DataProvider('adressesEnProduction')]
    public function test_en_production_les_comptes_de_demonstration_sont_exclus(string $email, bool $autorise): void
    {
        $admin = User::factory()->make(['is_admin' => true, 'email' => $email]);
        $this->app['env'] = 'production';

        try {
            $this->assertSame($autorise, app(ChildSafetyReportAccessPolicy::class)->estAutorise($admin));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_un_superadmin_exclu_ne_voit_ni_ne_traite_aucun_signalement_enfant(): void
    {
        $exclu = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true, 'email' => 'demo-1905@bouclepro.test']);
        $enfant = $this->signalementEnfant();
        BugReport::create(['organization_id' => $this->org->id, 'reporter_id' => $this->membre->id, 'category' => 'spam_fraud', 'reason' => 'spam_fraud', 'details' => 'SPAM VISIBLE 1905']);

        $this->app['env'] = 'production';

        try {
            $html = $this->actingAs($exclu)->get(route('admin.bug-reports'))->assertOk()->getContent();
            $this->assertStringContainsString('SPAM VISIBLE 1905', $html);
            $this->assertStringNotContainsString('DESCRIPTION SENSIBLE 1905', $html);
            $this->assertStringNotContainsString('data-child-safety-pending', $html);
            $this->assertStringNotContainsString('value="child_safety"', $html);

            $this->actingAs($exclu)->get(route('admin.bug-reports', ['category' => 'child_safety']))->assertDontSee('DESCRIPTION SENSIBLE 1905');
            $this->actingAs($exclu)->patch(route('admin.bug-reports.fix', $enfant), ['internal_note' => 'x'])->assertNotFound();
            $this->actingAs($exclu)->patch(route('admin.bug-reports.dismiss', $enfant))->assertNotFound();
            $this->actingAs($exclu)->delete(route('admin.bug-reports.destroy', $enfant))->assertNotFound();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame('pending', $enfant->fresh()->status);
        $this->assertSame(0, $enfant->actions()->count());
    }

    public function test_l_admin_d_organisation_n_a_toujours_aucun_acces(): void
    {
        $adminOrg = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => false]);
        $this->org->update(['admin_id' => $adminOrg->id]);
        $enfant = $this->signalementEnfant();

        $this->actingAs($adminOrg)->get(route('admin.bug-reports'))->assertForbidden();
        $this->actingAs($adminOrg)->get(route('organization.admin.reports', $this->org))->assertOk()->assertDontSee('DESCRIPTION SENSIBLE 1905');
        $this->actingAs($adminOrg)->patch(route('organization.admin.reports.resolve', [$this->org, $enfant]))->assertNotFound();
    }

    // ── Gabarit et migration ───────────────────────────────────────────────

    public function test_le_gabarit_global_existe_en_francais_et_en_anglais_sans_variable_sensible(): void
    {
        foreach (['fr', 'en'] as $langue) {
            $gabarit = SystemEmailTemplate::where('slug', ChildSafetyAlertMailer::SLUG)->where('locale', $langue)->whereNull('organization_id')->sole();
            $this->assertEqualsCanonicalizing(['organization_name', 'report_date', 'admin_url', 'app_name'], $gabarit->variables);
            foreach (['details', 'reporter', 'page_url', 'email'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $gabarit->content_html.$gabarit->subject);
            }
        }
    }
}
