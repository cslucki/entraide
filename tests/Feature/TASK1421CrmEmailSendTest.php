<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmEmailSendService;
use App\Services\Crm\CrmEmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1421 — CRM-7b, envoyer un modele d'email a un Contact.
 *
 * - l'humain declenche : preview (rien d'ecrit) → confirmation avec jeton
 *   one-shot → envoi ; un second clic n'envoie rien ;
 * - fail-closed avant preview ET avant envoi : meme Organization (modele et
 *   Contact), contactable, email present ;
 * - un envoi accepte par le transport = EmailLog sent + fait email_sent +
 *   last_interaction_at ; un echec = failed + email_failed, sans interaction,
 *   sans retry ;
 * - la preuve porte crm_contact_id (et user_id seulement si le Contact est
 *   relie), jamais de faux User.
 */
class TASK1421CrmEmailSendTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private CrmContact $contactA;

    private EmailTemplate $templateA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1421', 'name' => 'Alpha Conseil', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1421', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'aline@alpha.test', 'first_name' => 'Aline', 'name' => 'Admin']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);

        $this->contactA = app(CrmContactService::class)->findOrCreate($this->orgA, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe', 'email' => 'zorglub@example.com', 'company' => 'ACME']);
        $this->templateA = app(CrmEmailTemplateService::class)->create($this->orgA, 'Suite entretien', 'Suite à notre échange, {{ first_name }}', '<p>Bonjour {{ full_name }} ({{ company }}) — {{ organization }}</p>');
    }

    private function preview(CrmContact $contact, EmailTemplate $template, ?Organization $org = null): string
    {
        return route('organization.admin.crm.contacts.email.preview', ['organization' => ($org ?? $this->orgA)->slug, 'contact' => $contact->id, 'template' => $template->id]);
    }

    private function send(CrmContact $contact, EmailTemplate $template, ?Organization $org = null): string
    {
        return route('organization.admin.crm.contacts.email.send', ['organization' => ($org ?? $this->orgA)->slug, 'contact' => $contact->id, 'template' => $template->id]);
    }

    private function show(CrmContact $contact): string
    {
        return route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id]);
    }

    /** @var array<int, array{html: string, message: \Illuminate\Mail\Message}> */
    private array $captured = [];

    /**
     * `Mail::fake()` ne connait pas `Mail::html()` (il le RETRANSMET au vrai
     * mailer) : on intercepte l'appel et on conserve le Message que le
     * callback a construit — destinataire, objet, reply-to sont ainsi
     * MESURES, pas supposes. Aucun transport n'est touche.
     */
    private function captureMail(): void
    {
        $this->captured = [];
        Mail::shouldReceive('html')->andReturnUsing(function (string $html, callable $callback) {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email);
            $callback($message);
            $this->captured[] = ['html' => $html, 'message' => $message];

            return null;
        });
    }

    private function sentCount(): int
    {
        return count($this->captured);
    }

    /** Preview puis envoi avec le jeton pose en session par la preview. */
    private function previewThenSend(CrmContact $contact, EmailTemplate $template)
    {
        $this->actingAs($this->adminA)->get($this->preview($contact, $template))->assertOk();
        $token = session(CrmEmailSendService::TOKEN_SESSION_PREFIX.$this->orgA->id.':'.$contact->id.':'.$template->id)['token'];

        return $this->actingAs($this->adminA)->post($this->send($contact, $template), ['token' => $token]);
    }

    // ── 1. Le chemin heureux, de bout en bout ───────────────────────────────

    public function test_preview_renders_for_this_contact_and_writes_nothing(): void
    {
        $this->captureMail();

        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $this->templateA))
            ->assertOk()
            ->assertSee('zorglub@example.com')
            ->assertSee('Suite à notre échange, Zorglub')
            ->assertSee('Bonjour Zorglub Amaranthe (ACME) — Alpha Conseil')
            ->assertSee('data-crm-email-send', false);

        $this->assertIsString(session(CrmEmailSendService::TOKEN_SESSION_PREFIX.$this->orgA->id.':'.$this->contactA->id.':'.$this->templateA->id)['token'] ?? null);
        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, EmailLog::count());
        $this->assertSame(0, $this->contactA->events()->count());
        $this->assertNull($this->contactA->fresh()->last_interaction_at);
    }

    public function test_a_confirmed_send_reaches_the_contact_logs_the_proof_and_counts_as_an_interaction(): void
    {
        $this->captureMail();

        $this->previewThenSend($this->contactA, $this->templateA)
            ->assertRedirect($this->show($this->contactA))
            ->assertSessionHas('success', __('crm.email.flash_sent', ['to' => 'zorglub@example.com']));

        $this->assertSame(1, $this->sentCount());
        $symfony = $this->captured[0]['message']->getSymfonyMessage();
        $this->assertSame('zorglub@example.com', $symfony->getTo()[0]->getAddress());
        $this->assertSame('Zorglub Amaranthe', $symfony->getTo()[0]->getName());
        $this->assertSame('Suite à notre échange, Zorglub', $symfony->getSubject());
        $this->assertSame('aline@alpha.test', $symfony->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('Zorglub Amaranthe (ACME) — Alpha Conseil', $this->captured[0]['html']);

        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame($this->contactA->id, $log->crm_contact_id);
        $this->assertNull($log->user_id, 'aucun faux User');
        $this->assertSame($this->orgA->id, $log->organization_id);
        $this->assertSame($this->templateA->id, $log->template_id);
        $this->assertSame('zorglub@example.com', $log->to_email);
        $this->assertSame('crm', $log->data['source']);
        $this->assertSame($this->adminA->id, $log->data['sender_id']);
        $this->assertStringContainsString('Zorglub Amaranthe (ACME)', (string) $log->body_html);
        $this->assertSame(hash('sha256', $log->body_html), $log->body_hash);

        $event = $this->contactA->events()->firstOrFail();
        $this->assertSame(CrmContactEvent::TYPE_EMAIL_SENT, $event->type);
        $this->assertSame($this->adminA->id, $event->author_user_id);
        $this->assertSame($log->id, $event->payload['log_id']);
        $this->assertSame('Suite à notre échange, Zorglub', $event->payload['subject']);
        $this->assertNotNull($this->contactA->fresh()->last_interaction_at, 'un email envoye est une interaction');

        $this->actingAs($this->adminA)->get($this->show($this->contactA))->assertOk()
            ->assertSee('data-crm-event="email_sent"', false)->assertSee(__('crm.event_email_sent'));
    }

    public function test_a_linked_contact_keeps_its_user_on_the_proof(): void
    {
        $this->captureMail();
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'email' => 'zorglub@example.com']);
        app(CrmContactService::class)->linkToUser($this->contactA, $member);

        $this->previewThenSend($this->contactA->fresh(), $this->templateA)->assertRedirect();

        $this->assertSame($member->id, EmailLog::firstOrFail()->user_id);
    }

    // ── 2. Pas de doublon ───────────────────────────────────────────────────

    public function test_a_second_submit_with_the_same_token_sends_nothing(): void
    {
        $this->captureMail();
        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $this->templateA))->assertOk();
        $token = session(CrmEmailSendService::TOKEN_SESSION_PREFIX.$this->orgA->id.':'.$this->contactA->id.':'.$this->templateA->id)['token'];

        $this->actingAs($this->adminA)->post($this->send($this->contactA, $this->templateA), ['token' => $token])->assertSessionHas('success');
        $this->actingAs($this->adminA)->post($this->send($this->contactA, $this->templateA), ['token' => $token])
            ->assertRedirect($this->show($this->contactA))->assertSessionHas('error', __('crm.email.flash_token'));
        $this->actingAs($this->adminA)->post($this->send($this->contactA, $this->templateA), ['token' => 'invente'])
            ->assertSessionHas('error', __('crm.email.flash_token'));
        $this->actingAs($this->adminA)->post($this->send($this->contactA, $this->templateA), [])
            ->assertSessionHas('error', __('crm.email.flash_token'));

        $this->assertSame(1, $this->sentCount());
        $this->assertSame(1, EmailLog::count());
        $this->assertSame(1, $this->contactA->events()->count());
    }

    public function test_a_token_expires_after_fifteen_minutes_and_a_new_preview_replaces_the_old_one(): void
    {
        $this->captureMail();
        $key = CrmEmailSendService::TOKEN_SESSION_PREFIX.$this->orgA->id.':'.$this->contactA->id.':'.$this->templateA->id;

        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $this->templateA))->assertOk();
        $old = session($key)['token'];
        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $this->templateA))->assertOk();
        $new = session($key)['token'];
        $this->assertNotSame($old, $new);

        $this->actingAs($this->adminA)->post($this->send($this->contactA, $this->templateA), ['token' => $old])
            ->assertSessionHas('error', __('crm.email.flash_token'));
        $this->assertSame(0, $this->sentCount());

        // L'ancien jeton a ete consomme par l'echec : nouvelle preview, puis on laisse passer 16 minutes.
        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $this->templateA))->assertOk();
        $fresh = session($key)['token'];
        $this->travel(16)->minutes();
        $this->actingAs($this->adminA)->post($this->send($this->contactA, $this->templateA), ['token' => $fresh])
            ->assertSessionHas('error', __('crm.email.flash_token'));
        $this->travelBack();

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, EmailLog::count());
    }

    // ── 3. Fail-closed ──────────────────────────────────────────────────────

    public function test_do_not_contact_blocks_before_preview_and_before_send(): void
    {
        $this->captureMail();
        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $this->templateA))->assertOk();
        $token = session(CrmEmailSendService::TOKEN_SESSION_PREFIX.$this->orgA->id.':'.$this->contactA->id.':'.$this->templateA->id)['token'];

        $this->contactA->update(['do_not_contact_at' => now()]);

        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $this->templateA))
            ->assertRedirect($this->show($this->contactA))
            ->assertSessionHas('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.do_not_contact')]));
        $this->actingAs($this->adminA)->post($this->send($this->contactA, $this->templateA), ['token' => $token])
            ->assertSessionHas('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.do_not_contact')]));

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, EmailLog::count());
        $this->assertSame(0, $this->contactA->events()->count());
        $this->actingAs($this->adminA)->get($this->show($this->contactA))->assertOk()->assertSee('data-crm-email-blocked', false)->assertDontSee('data-crm-email-pick');
    }

    public function test_a_contact_without_email_is_refused(): void
    {
        $this->captureMail();
        $phoneOnly = app(CrmContactService::class)->findOrCreate($this->orgA, ['first_name' => 'Sans', 'last_name' => 'Email', 'phone' => '+33600000099']);

        $this->actingAs($this->adminA)->get($this->preview($phoneOnly, $this->templateA))
            ->assertRedirect()->assertSessionHas('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.no_email')]));

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_a_foreign_template_or_a_foreign_contact_is_a_404_and_nothing_leaves(): void
    {
        $this->captureMail();
        $templateB = app(CrmEmailTemplateService::class)->create($this->orgB, 'Modele B', 'x', '<p>x</p>');
        $contactB = app(CrmContactService::class)->findOrCreate($this->orgB, ['email' => 'b@example.com']);
        $global = EmailTemplate::create(['slug' => 'global-1421', 'name' => 'Global', 'subject' => 'x', 'content_html' => '<p>x</p>', 'organization_id' => null]);

        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $templateB))->assertNotFound();
        $this->actingAs($this->adminA)->get($this->preview($this->contactA, $global))->assertNotFound();
        $this->actingAs($this->adminA)->get($this->preview($contactB, $this->templateA))->assertNotFound();
        $this->actingAs($this->adminA)->post($this->send($this->contactA, $templateB), ['token' => 'x'])->assertNotFound();
        $this->actingAs($this->adminA)->post($this->send($contactB, $this->templateA), ['token' => 'x'])->assertNotFound();
        $this->actingAs($this->adminB)->get($this->preview($this->contactA, $this->templateA))->assertForbidden();

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_the_service_refuses_a_sender_from_another_organization_and_a_mismatched_template(): void
    {
        $this->captureMail();
        $service = app(CrmEmailSendService::class);
        $templateB = app(CrmEmailTemplateService::class)->create($this->orgB, 'Modele B', 'x', '<p>x</p>');

        try {
            $service->send($this->contactA, $this->templateA, $this->adminB);
            $this->fail('expediteur d une autre Organization refuse');
        } catch (LogicException $e) {
            $this->assertSame('sender_organization', $e->getMessage());
        }

        // Un admin plateforme d'une AUTRE Organization voit la preview mais ne
        // signe pas l'email du tenant (MASTER Q29).
        $superAdmin = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => true]);
        $this->actingAs($superAdmin)->get($this->preview($this->contactA, $this->templateA))->assertOk();
        $token = session(CrmEmailSendService::TOKEN_SESSION_PREFIX.$this->orgA->id.':'.$this->contactA->id.':'.$this->templateA->id)['token'];
        $this->actingAs($superAdmin)->post($this->send($this->contactA, $this->templateA), ['token' => $token])
            ->assertSessionHas('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.sender_organization')]));
        try {
            $service->send($this->contactA, $templateB, $this->adminA);
            $this->fail('modele d une autre Organization refuse');
        } catch (LogicException $e) {
            $this->assertSame('template_organization', $e->getMessage());
        }

        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, EmailLog::count());
    }

    // ── 4. Un echec de transport est une preuve, pas une interaction ────────

    public function test_a_transport_failure_is_logged_as_failed_traced_and_never_retried(): void
    {
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('SMTP down'));

        $this->previewThenSend($this->contactA, $this->templateA)
            ->assertRedirect($this->show($this->contactA))
            ->assertSessionHas('error');

        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertSame('SMTP down', $log->error_message);
        $this->assertSame($this->contactA->id, $log->crm_contact_id);

        $event = $this->contactA->events()->firstOrFail();
        $this->assertSame(CrmContactEvent::TYPE_EMAIL_FAILED, $event->type);
        $this->assertSame('SMTP down', $event->payload['error']);
        $this->assertNull($this->contactA->fresh()->last_interaction_at, 'un echec n est pas une interaction');
    }

    // ── 5. La fiche propose l'envoi ─────────────────────────────────────────

    public function test_the_page_offers_the_organization_templates_only(): void
    {
        app(CrmEmailTemplateService::class)->create($this->orgB, 'Seulement chez B', 'x', '<p>x</p>');

        $this->actingAs($this->adminA)->get($this->show($this->contactA))->assertOk()
            ->assertSee('data-crm-email-pick', false)
            ->assertSee('Suite entretien')
            ->assertDontSee('Seulement chez B');

        $this->actingAs($this->adminA)
            ->get(route('organization.admin.crm.contacts.email.pick', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id, 'template' => $this->templateA->id]))
            ->assertRedirect($this->preview($this->contactA, $this->templateA));
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_sees_a_sent_mail_when_one_is_sent(): void
    {
        $this->captureMail();
        $this->assertSame(0, $this->sentCount());
        $this->previewThenSend($this->contactA, $this->templateA);
        $this->assertSame(1, $this->sentCount());
    }
}
