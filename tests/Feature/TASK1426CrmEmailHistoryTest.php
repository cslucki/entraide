<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1426 — CRM-8 : les emails du Contact, ce qui a ete tente et avec
 * quel resultat.
 *
 * - la fiche liste les EmailLog du Contact (tenant + crm_contact_id), du plus
 *   recent au plus ancien, avec les TROIS statuts reels : accepte par le
 *   transport / echec / resultat inconnu — jamais « delivre » ni « lu » ;
 * - « Relire » montre le snapshot STOCKE, isole (iframe sandbox + CSP
 *   default-src 'none'), jamais injecte dans le DOM parent ; l'integrite
 *   sha256 est verifiee contre body_hash ;
 * - un log d'un autre tenant ou d'un autre Contact = 404 ; le lien de la
 *   timeline n'existe que si log_id resout dans CE Contact et CE tenant.
 */
class TASK1426CrmEmailHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $memberA;

    private CrmContact $contactA;

    private CrmContact $otherA;

    private CrmContact $contactB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1426', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1426', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ada', 'name' => 'Lovelace']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);

        $contacts = app(CrmContactService::class);
        $this->contactA = $contacts->findOrCreate($this->orgA, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe', 'email' => 'zorglub@example.com']);
        $this->otherA = $contacts->findOrCreate($this->orgA, ['first_name' => 'Yolande', 'last_name' => 'Brimborion', 'email' => 'yolande@example.com']);
        // Meme adresse email que contactA, dans l'autre Organization : le tenant seul separe.
        $this->contactB = $contacts->findOrCreate($this->orgB, ['first_name' => 'Quixotic', 'last_name' => 'Bellwether', 'email' => 'zorglub@example.com']);
    }

    private function fiche(CrmContact $contact): string
    {
        return route('organization.admin.crm.contacts.show', ['organization' => $contact->organization_id === $this->orgA->id ? 'org-a-1426' : 'org-b-1426', 'contact' => $contact->id]);
    }

    private function reread(CrmContact $contact, string $logId, ?string $orgSlug = null): string
    {
        return route('organization.admin.crm.contacts.emails.show', ['organization' => $orgSlug ?? 'org-a-1426', 'contact' => $contact->id, 'log' => $logId]);
    }

    private function log(CrmContact $contact, string $status, string $subject, array $overrides = []): EmailLog
    {
        $html = $overrides['body_html'] ?? '<p>Bonjour '.$contact->first_name.', <img src="https://tracker.example.com/pixel.gif"> voici <a href="https://x.test/offre">l\'offre</a>.</p>';

        return EmailLog::create(array_merge([
            'organization_id' => $contact->organization_id,
            'crm_contact_id' => $contact->id,
            'user_id' => null,
            'template_id' => null,
            'to_email' => $contact->email,
            'subject' => $subject,
            'status' => $status,
            'error_message' => $status === EmailLog::STATUS_FAILED ? 'SMTP 550 mailbox unavailable' : null,
            'body_html' => $html,
            'body_hash' => hash('sha256', $html),
            'data' => ['source' => 'crm', 'sender_id' => $this->adminA->id],
        ], $overrides));
    }

    /** Le HTML de la section « Emails » de la fiche. */
    private function history(string $html): string
    {
        preg_match('/<div[^>]*data-crm-email-history>.*?<div[^>]*data-crm-timeline>/s', $html, $m);
        $this->assertNotEmpty($m, 'section Emails absente');

        return $m[0];
    }

    // ── 1. La fiche : les trois statuts, ce Contact seulement, du plus recent au plus ancien ──

    public function test_the_contact_page_lists_its_own_email_logs_with_the_three_real_statuses(): void
    {
        $template = EmailTemplate::factory()->create(['name' => 'Relance atelier']);
        $this->travelTo(now()->subHours(3));
        $old = $this->log($this->contactA, EmailLog::STATUS_SENT, 'Premier contact', ['template_id' => $template->id]);
        $this->travelTo(now()->addHour());
        $failed = $this->log($this->contactA, EmailLog::STATUS_FAILED, 'Relance qui echoue');
        $this->travelTo(now()->addHour());
        $unknown = $this->log($this->contactA, EmailLog::STATUS_AMBIGUOUS, 'Transport muet');
        $this->travelBack();
        // Un autre Contact de la meme Organization, et le Contact homonyme de l'autre Organization.
        $this->log($this->otherA, EmailLog::STATUS_SENT, 'Pas pour Zorglub');
        $this->log($this->contactB, EmailLog::STATUS_SENT, 'Envoye ailleurs a la meme adresse');

        // Un log dont l'expediteur est membre d'une AUTRE Organization : le nom n'est pas resolu.
        $this->travelTo(now()->subHours(4));
        $this->log($this->contactA, EmailLog::STATUS_SENT, 'Expediteur d ailleurs', ['data' => ['source' => 'crm', 'sender_id' => $this->adminB->id]]);
        $this->travelBack();

        $html = $this->actingAs($this->adminA)->get($this->fiche($this->contactA))->assertOk()->getContent();
        $history = $this->history($html);

        $this->assertMatchesRegularExpression('/Transport muet.*Relance qui echoue.*Premier contact.*Expediteur d ailleurs/s', $history, 'du plus recent au plus ancien');
        $this->assertStringContainsString('data-crm-email-log="'.$unknown->id.'" data-crm-email-status="ambiguous"', $history);
        $this->assertStringContainsString('data-crm-email-log="'.$failed->id.'" data-crm-email-status="failed"', $history);
        $this->assertStringContainsString('data-crm-email-log="'.$old->id.'" data-crm-email-status="sent"', $history);
        $this->assertStringContainsString(__('crm.email_history.status_sent'), $history);
        $this->assertStringContainsString(__('crm.email_history.status_failed'), $history);
        $this->assertStringContainsString(__('crm.email_history.status_ambiguous'), $history);
        $this->assertStringContainsString('SMTP 550 mailbox unavailable', $history);
        $this->assertStringContainsString('Relance atelier', $history);
        $this->assertStringContainsString('Ada Lovelace', $history, 'expediteur resolu dans l Organization');
        $this->assertStringNotContainsString($this->adminB->fullName, $history, 'un membre d une autre Organization n est jamais resolu');
        $this->assertStringContainsString('data-crm-email-reread="'.$old->id.'"', $history);
        $this->assertStringNotContainsString('Pas pour Zorglub', $history);
        $this->assertStringNotContainsString('Envoye ailleurs', $html);
        // Jamais de fausse certitude : ni « delivre » ni « lu ».
        foreach (['livré', 'Livré', 'delivered', 'Lu par', 'ouvert'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $history);
        }
    }

    public function test_a_contact_without_email_log_shows_the_empty_state(): void
    {
        $html = $this->actingAs($this->adminA)->get($this->fiche($this->contactA))->assertOk()->getContent();

        $this->assertStringContainsString('data-crm-email-history-empty', $this->history($html));
    }

    // ── 2. Relire : snapshot stocke, isole, integrite ──────────────────────

    public function test_reread_shows_the_stored_snapshot_isolated_and_never_in_the_parent_dom(): void
    {
        $log = $this->log($this->contactA, EmailLog::STATUS_SENT, 'Premier contact');

        $response = $this->actingAs($this->adminA)->get($this->reread($this->contactA, $log->id))->assertOk();
        $html = $response->getContent();

        $response->assertSee('Premier contact')
            ->assertSee('zorglub@example.com')
            ->assertSee(__('crm.email_history.status_sent'))
            ->assertSee('Ada Lovelace')
            ->assertSee('data-crm-email-integrity="ok"', false)
            ->assertSee(__('crm.email_history.integrity_ok'));

        // Le snapshot est dans l'attribut srcdoc (echappe), avec la CSP, dans un iframe sandbox sans capacite.
        $this->assertMatchesRegularExpression('/<iframe[^>]*\ssandbox=""[^>]*\ssrcdoc="/', $html);
        $this->assertStringContainsString(e("default-src 'none'; style-src 'unsafe-inline'"), $html);
        $this->assertStringContainsString(e('<img src="https://tracker.example.com/pixel.gif">'), $html, 'le snapshot est la, tel quel, echappe');
        // Les liens sont DESARMES a l'affichage (le sandbox n'empeche pas un cadre de se naviguer lui-meme) :
        // href → data-href, plus <base target="_blank"> que le sandbox sans allow-popups refuse.
        $this->assertStringContainsString(e('<a data-href="https://x.test/offre">'), $html, 'lien desarme');
        $this->assertStringNotContainsString(e('<a href="https://x.test/offre">'), $html, 'aucun lien arme dans le document isole');
        $this->assertStringContainsString(e('<base target="_blank">'), $html);
        // Jamais rendu brut dans le DOM parent : ni le pixel ni le lien ne sont des elements actifs de la page.
        $this->assertStringNotContainsString('<img src="https://tracker.example.com/pixel.gif">', $html);
        $this->assertStringNotContainsString('<a href="https://x.test/offre">', $html);
        $this->assertStringNotContainsString('allow-scripts', $html);
    }

    public function test_reread_disarms_a_meta_refresh_and_an_area_link_inside_the_snapshot(): void
    {
        $log = $this->log($this->contactA, EmailLog::STATUS_SENT, 'Piege', ['body_html' => '<meta http-equiv="refresh" content="0;url=https://evil.test"><map><area href="https://evil.test/a"></map><p>Corps</p>']);

        $html = $this->actingAs($this->adminA)->get($this->reread($this->contactA, $log->id))->assertOk()->getContent();

        $this->assertStringContainsString(e('<meta data-http-equiv="refresh" content="0;url=https://evil.test">'), $html);
        $this->assertStringContainsString(e('<area data-href="https://evil.test/a">'), $html);
        $this->assertStringNotContainsString(e('<meta http-equiv="refresh"'), $html);
        $this->assertStringNotContainsString(e('<area href="'), $html);
        // La CSP du document isole, elle, garde son http-equiv.
        $this->assertStringContainsString(e('<meta http-equiv="Content-Security-Policy"'), $html);
        // L'integrite se verifie sur le snapshot STOCKE, pas sur la forme desarmee.
        $this->assertStringContainsString('data-crm-email-integrity="ok"', $html);
    }

    public function test_reread_uses_the_stored_body_never_the_current_template_and_detects_a_divergent_copy(): void
    {
        $template = EmailTemplate::factory()->create(['name' => 'Relance', 'content_html' => '<p>MODELE COURANT MODIFIE</p>']);
        $log = $this->log($this->contactA, EmailLog::STATUS_FAILED, 'Relance', ['template_id' => $template->id, 'body_html' => '<p>Version envoyee</p>']);

        $html = $this->actingAs($this->adminA)->get($this->reread($this->contactA, $log->id))->assertOk()->getContent();
        $this->assertStringContainsString(e('<p>Version envoyee</p>'), $html);
        $this->assertStringNotContainsString('MODELE COURANT MODIFIE', $html);
        $this->assertStringContainsString('data-crm-email-integrity="ok"', $html);

        // La copie stockee ne correspond plus a l'empreinte : on le dit, sobrement.
        EmailLog::whereKey($log->id)->update(['body_html' => '<p>Copie alteree</p>']);
        $html = $this->actingAs($this->adminA)->get($this->reread($this->contactA, $log->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-crm-email-integrity="divergent"', $html);
        // Le libelle porte une apostrophe : mesurer la forme ECHAPPEE, celle que le navigateur recoit.
        $this->assertStringContainsString(e(__('crm.email_history.integrity_divergent')), $html);

        // Sans copie conservee : rien a relire, pas d'iframe.
        EmailLog::whereKey($log->id)->update(['body_html' => null]);
        $html = $this->actingAs($this->adminA)->get($this->reread($this->contactA, $log->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-crm-email-no-snapshot', $html);
        $this->assertStringContainsString('data-crm-email-integrity="none"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    // ── 3. Tenant et Contact : 404, jamais 403 ; acces ─────────────────────

    public function test_a_log_of_another_tenant_or_another_contact_is_a_404(): void
    {
        $mine = $this->log($this->contactA, EmailLog::STATUS_SENT, 'Le mien');
        $ofOtherContact = $this->log($this->otherA, EmailLog::STATUS_SENT, 'Celui de Yolande');
        $ofOtherTenant = $this->log($this->contactB, EmailLog::STATUS_SENT, 'Celui de Quixotic');

        $this->actingAs($this->adminA)->get($this->reread($this->contactA, $mine->id))->assertOk();
        // Un log d'un autre Contact de la meme Organization, via l'URL de ce Contact.
        $this->actingAs($this->adminA)->get($this->reread($this->contactA, $ofOtherContact->id))->assertNotFound();
        // Un log de l'autre tenant, meme adresse email.
        $this->actingAs($this->adminA)->get($this->reread($this->contactA, $ofOtherTenant->id))->assertNotFound();
        // Le Contact de l'autre tenant, depuis l'Organization A.
        $this->actingAs($this->adminA)->get($this->reread($this->contactB, $ofOtherTenant->id))->assertNotFound();
        // Une ligne incoherente (log rattache a ce Contact mais a l'autre tenant) : le tenant fait autorite.
        $inconsistent = $this->log($this->contactA, EmailLog::STATUS_SENT, 'Ligne incoherente', ['organization_id' => $this->orgB->id]);
        $this->actingAs($this->adminA)->get($this->reread($this->contactA, $inconsistent->id))->assertNotFound();
        $this->assertStringNotContainsString('Ligne incoherente', $this->actingAs($this->adminA)->get($this->fiche($this->contactA))->getContent());
        // Un id inconnu.
        $this->actingAs($this->adminA)->get($this->reread($this->contactA, '00000000-0000-7000-8000-000000000000'))->assertNotFound();
    }

    public function test_only_the_organization_admin_or_a_platform_admin_can_reread(): void
    {
        $log = $this->log($this->contactA, EmailLog::STATUS_SENT, 'Le mien');

        $this->actingAs($this->memberA)->get($this->reread($this->contactA, $log->id))->assertForbidden();
        $this->actingAs($this->adminB)->get($this->reread($this->contactA, $log->id))->assertForbidden();
        $superAdmin = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => true]);
        $this->actingAs($superAdmin)->get($this->reread($this->contactA, $log->id))->assertOk();
    }

    // ── 4. Timeline ↔ EmailLog ─────────────────────────────────────────────

    public function test_the_timeline_fact_links_to_the_log_only_when_it_resolves_in_this_contact_and_tenant(): void
    {
        $mine = $this->log($this->contactA, EmailLog::STATUS_SENT, 'Le mien');
        $ofOtherTenant = $this->log($this->contactB, EmailLog::STATUS_SENT, 'Celui de Quixotic');
        $ofOtherContact = $this->log($this->otherA, EmailLog::STATUS_FAILED, 'Celui de Yolande');

        $fact = fn (string $logId, string $subject) => CrmContactEvent::create([
            'organization_id' => $this->orgA->id,
            'crm_contact_id' => $this->contactA->id,
            'type' => 'email_sent',
            'author_user_id' => $this->adminA->id,
            'payload' => ['log_id' => $logId, 'subject' => $subject, 'to' => 'zorglub@example.com', 'template_name' => 'Relance'],
            'occurred_at' => now(),
        ]);
        $fact($mine->id, 'Le mien');
        // Un fait qui pointe (par erreur, ou par malice) vers un log d'ailleurs : pas de lien.
        $fact($ofOtherTenant->id, 'Fait douteux tenant');
        $fact($ofOtherContact->id, 'Fait douteux contact');

        $html = $this->actingAs($this->adminA)->get($this->fiche($this->contactA))->assertOk()->getContent();

        $this->assertStringContainsString('data-crm-event-reread="'.$mine->id.'"', $html);
        $this->assertStringNotContainsString('data-crm-event-reread="'.$ofOtherTenant->id.'"', $html);
        $this->assertStringNotContainsString('data-crm-event-reread="'.$ofOtherContact->id.'"', $html);
        $this->assertSame(1, substr_count($html, 'data-crm-event-reread='));
    }
}
