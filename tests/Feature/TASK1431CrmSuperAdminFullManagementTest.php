<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmEmailSendService;
use App\Services\Crm\CrmEmailTemplateService;
use App\Services\Crm\CrmStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\CapturesMailHtml;
use Tests\TestCase;

/**
 * TASK-1431 — CRM CORE FIX B : le SuperAdmin ADMINISTRE les Relations de
 * toutes les Organizations depuis /admin/relations/{organization}/…
 *
 * Ce qui est mesure :
 * 1. les routes plateforme sont reservees a `is_admin` et portent l'Organization ;
 * 2. la fiche plateforme montre le Contact de n'importe quelle Organization avec
 *    des actions qui ciblent /admin/relations/{organization}/… ;
 * 3. le Contact est TOUJOURS resolu dans l'Organization de l'URL (404 sinon),
 *    et un statut d'ailleurs ne s'applique jamais ;
 * 4. chaque mutation reutilise les services et est SIGNEE par le SuperAdmin ;
 * 5. la suppression est un SoftDelete trace, qui sort le Contact des listes et
 *    bloque toute mutation jusqu'a une restauration explicite ;
 * 6. supprimer/restaurer est reserve a la plateforme (route ET service) ;
 * 7. l'email passe par les memes gardes (dont MASTER Q29 : pas de signature d'un
 *    autre tenant) et n'envoie rien sans clic.
 */
class TASK1431CrmSuperAdminFullManagementTest extends TestCase
{
    use CapturesMailHtml, RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $memberA;

    private User $superAdmin;

    private CrmContact $contactA;

    private CrmContact $contactB;

    private CrmContactService $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1431', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1431', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Aline', 'name' => 'Admin']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id, 'first_name' => 'Boris', 'name' => 'Admin']);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);
        // Le SuperAdmin est membre de A : il agit sur B en tant que PLATEFORME.
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true, 'first_name' => 'Sacha', 'name' => 'Plateforme']);

        $this->contacts = app(CrmContactService::class);
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgA);
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB);
        $this->contactA = $this->contacts->findOrCreate($this->orgA, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe', 'email' => 'zorglub@example.com'], $this->adminA);
        $this->contactB = $this->contacts->findOrCreate($this->orgB, ['first_name' => 'Quixotic', 'last_name' => 'Bellwether', 'email' => 'quixotic@example.com'], $this->adminB);
    }

    private function admin(string $name, Organization $organization, array $params = []): string
    {
        return route('admin.crm.'.$name, ['organization' => $organization->slug] + $params);
    }

    private function facts(CrmContact $contact, string $type): Collection
    {
        return CrmContactEvent::where('crm_contact_id', $contact->id)->where('type', $type)->get();
    }

    // ── 1. Reserve a la plateforme, Organization explicite ─────────────────

    public function test_platform_routes_are_admin_only_and_carry_the_organization(): void
    {
        $show = $this->admin('contacts.show', $this->orgB, ['contact' => $this->contactB->id]);
        $this->assertSame('/admin/relations/org-b-1431/contacts/'.$this->contactB->id, parse_url($show, PHP_URL_PATH));

        $this->get($show)->assertRedirect();
        $this->actingAs($this->memberA)->get($show)->assertForbidden();
        $this->actingAs($this->adminB)->get($show)->assertForbidden();
        $this->actingAs($this->adminA)->get($show)->assertForbidden();
        $this->actingAs($this->superAdmin)->get($show)->assertOk();

        // Un OrgAdmin (meme de cette Organization) ne peut pas muter par la plateforme.
        $this->actingAs($this->adminB)->post($this->admin('contacts.notes.store', $this->orgB, ['contact' => $this->contactB->id]), ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->adminB)->delete($this->admin('contacts.destroy', $this->orgB, ['contact' => $this->contactB->id]))->assertForbidden();
        $this->assertSame(0, $this->contactB->events()->count());
        $this->assertFalse($this->contactB->fresh()->trashed());
    }

    // ── 2. La fiche plateforme, actions plateforme ─────────────────────────

    public function test_the_platform_fiche_shows_any_organization_s_contact_with_platform_action_urls(): void
    {
        $html = $this->actingAs($this->superAdmin)->get($this->admin('contacts.show', $this->orgB, ['contact' => $this->contactB->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Quixotic Bellwether', $html);
        $this->assertStringContainsString('data-crm-admin-tabs', $html);
        $this->assertStringContainsString('data-crm-admin-org="org-b-1431"', $html);
        $this->assertStringContainsString('data-crm-admin-delete', $html);
        foreach (['status', 'notes', 'next-action', 'policy'] as $action) {
            $this->assertStringContainsString('/admin/relations/org-b-1431/contacts/'.$this->contactB->id.'/'.$action, $html, $action);
        }
        $this->assertStringContainsString(route('admin.crm.overview', ['organization' => $this->orgB->id]), $html);
        preg_match_all('/<form[^>]*\saction="([^"]+)"/', $html, $m);
        foreach ($m[1] as $action) {
            $this->assertStringNotContainsString('/org/org-b-1431/admin/relations', $action, 'une action org-admin fuit dans la fiche plateforme');
        }
    }

    // ── 3. Jamais hors de l'Organization de l'URL ──────────────────────────

    public function test_the_contact_is_always_resolved_inside_the_organization_of_the_url(): void
    {
        $wrong = fn (string $name) => $this->admin($name, $this->orgA, ['contact' => $this->contactB->id]);

        $this->actingAs($this->superAdmin)->get($wrong('contacts.show'))->assertNotFound();
        $this->actingAs($this->superAdmin)->post($wrong('contacts.notes.store'), ['body' => 'intrus'])->assertNotFound();
        $this->actingAs($this->superAdmin)->put($wrong('contacts.update'), ['first_name' => 'X', 'email' => 'quixotic@example.com'])->assertNotFound();
        $this->actingAs($this->superAdmin)->delete($wrong('contacts.destroy'))->assertNotFound();
        $this->actingAs($this->superAdmin)->post($wrong('contacts.restore'))->assertNotFound();

        // Un statut de A ne s'applique jamais a un Contact de B, meme par l'URL de B.
        $statusA = CrmStatus::forOrganization($this->orgA)->active()->first();
        $this->actingAs($this->superAdmin)
            ->post($this->admin('contacts.status', $this->orgB, ['contact' => $this->contactB->id]), ['status_id' => $statusA->id])
            ->assertNotFound();

        $this->assertSame(0, $this->contactB->events()->count());
        $this->assertSame('Quixotic', $this->contactB->fresh()->first_name);
        $this->assertFalse($this->contactB->fresh()->trashed());
    }

    // ── 4. Memes services, signes par la plateforme ────────────────────────

    public function test_every_mutation_reuses_the_services_and_is_signed_by_the_platform_admin(): void
    {
        $sa = $this->superAdmin;

        // Creer DANS B (l'URL porte l'Organization ; le corps ne decide de rien).
        $this->actingAs($sa)->post($this->admin('contacts.store', $this->orgB), ['first_name' => 'Nouvelle', 'last_name' => 'Recrue', 'email' => 'recrue@example.com', 'organization_slug' => 'org-a-1431'])
            ->assertRedirect(route('admin.crm.overview', ['organization' => $this->orgB->id]))
            ->assertSessionHas('success', __('crm.flash.contact_created'));
        $created = CrmContact::forOrganization($this->orgB)->where('email', 'recrue@example.com')->firstOrFail();
        $this->assertSame($sa->id, $created->created_by_user_id);
        $this->assertSame(app(CrmStatusService::class)->defaultStatus($this->orgB)->id, $created->status_id);
        $this->assertSame(0, CrmContact::withTrashed()->forOrganization($this->orgA)->where('email', 'recrue@example.com')->count());

        $c = $this->contactB;
        $url = fn (string $name) => $this->admin($name, $this->orgB, ['contact' => $c->id]);
        $show = $url('contacts.show');

        $this->actingAs($sa)->from($show)->put($url('contacts.update'), ['first_name' => 'Quixotic', 'last_name' => 'Bellwether', 'email' => 'quixotic@example.com', 'company' => 'Moulins & Cie'])
            ->assertRedirect($show);
        $statusB = CrmStatus::forOrganization($this->orgB)->active()->ordered()->get()->last();
        $this->actingAs($sa)->from($show)->post($url('contacts.status'), ['status_id' => $statusB->id])->assertRedirect($show);
        $this->actingAs($sa)->from($show)->post($url('contacts.notes.store'), ['body' => 'Appel de la plateforme', 'channel' => 'call'])->assertRedirect($show);
        $this->actingAs($sa)->from($show)->post($url('contacts.next-action.plan'), ['next_action_type' => 'call', 'next_action_date' => now()->addDay()->toDateString()])->assertRedirect($show);
        $this->actingAs($sa)->from($show)->post($url('contacts.next-action.complete'))->assertRedirect($show);
        $this->actingAs($sa)->from($show)->post($url('contacts.policy'), ['action' => 'block', 'reason' => 'contact_request'])->assertRedirect($show);

        $c->refresh();
        $this->assertSame('Moulins & Cie', $c->company);
        $this->assertSame($statusB->id, $c->status_id);
        $this->assertFalse($c->isContactable());
        $this->assertSame($this->orgB->id, $c->organization_id);

        $types = [
            CrmContactEvent::TYPE_CONTACT_UPDATED, CrmContactEvent::TYPE_STATUS_CHANGED, CrmContactEvent::TYPE_NOTE,
            CrmContactEvent::TYPE_NEXT_ACTION_PLANNED, CrmContactEvent::TYPE_NEXT_ACTION_DONE, CrmContactEvent::TYPE_CONTACT_POLICY_CHANGED,
        ];
        foreach ($types as $type) {
            $facts = $this->facts($c, $type);
            $this->assertCount(1, $facts, $type);
            $this->assertSame($sa->id, $facts->first()->author_user_id, $type.' doit etre signe par le SuperAdmin');
        }

        $html = $this->actingAs($sa)->get($show)->assertOk()->getContent();
        $this->assertStringContainsString('data-crm-event-platform', $html);
        $this->assertStringContainsString('Sacha Plateforme', $html);
    }

    // ── 5. SoftDelete trace, listes, blocage, restauration ─────────────────

    public function test_delete_is_a_soft_delete_traced_hidden_from_lists_blocking_mutations_until_restore(): void
    {
        $sa = $this->superAdmin;
        $c = $this->contactB;
        $url = fn (string $name) => $this->admin($name, $this->orgB, ['contact' => $c->id]);
        $orgList = route('organization.admin.crm.contacts', ['organization' => $this->orgB->slug]);

        $this->actingAs($sa)->delete($url('contacts.destroy'))
            ->assertRedirect($url('contacts.show'))
            ->assertSessionHas('success', __('crm.trashed.flash_deleted'));

        $c->refresh();
        $this->assertTrue($c->trashed());
        $this->assertSame(1, CrmContact::withTrashed()->forOrganization($this->orgB)->whereKey($c->id)->count(), 'jamais de hard delete');
        $deleted = $this->facts($c, CrmContactEvent::TYPE_CONTACT_DELETED);
        $this->assertCount(1, $deleted);
        $this->assertSame($sa->id, $deleted->first()->author_user_id);

        // Absent des listes normales (org-admin et plateforme), present dans « supprimes ».
        $this->actingAs($this->adminB)->get($orgList)->assertOk()->assertDontSee('data-crm-contact="'.$c->id.'"', false);
        $this->actingAs($sa)->get(route('admin.crm.overview'))->assertOk()->assertDontSee('data-crm-admin-contact="'.$c->id.'"', false);
        $this->actingAs($sa)->get(route('admin.crm.overview', ['state' => 'trashed']))->assertOk()
            ->assertSee('data-crm-admin-contact="'.$c->id.'"', false)
            ->assertSee('data-crm-admin-restore="'.$c->id.'"', false)
            ->assertDontSee('data-crm-admin-contact="'.$this->contactA->id.'"', false);

        // Aucune mutation tant qu'il est supprime : 404 partout, fiche org-admin 404, fiche plateforme = banniere + restaurer.
        $this->actingAs($sa)->post($url('contacts.notes.store'), ['body' => 'trop tard'])->assertNotFound();
        $this->actingAs($sa)->post($url('contacts.status'), ['status_id' => CrmStatus::forOrganization($this->orgB)->active()->first()->id])->assertNotFound();
        $this->actingAs($sa)->delete($url('contacts.destroy'))->assertNotFound();
        $this->actingAs($this->adminB)->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgB->slug, 'contact' => $c->id]))->assertNotFound();
        $this->actingAs($sa)->get($url('contacts.show'))->assertOk()
            ->assertSee('data-crm-trashed-banner', false)
            ->assertSee('data-crm-admin-restore', false)
            ->assertDontSee('data-crm-note-form', false)
            ->assertDontSee('data-crm-admin-delete"', false);
        $this->assertSame(1, $c->events()->count());

        $this->actingAs($sa)->post($url('contacts.restore'))
            ->assertRedirect($url('contacts.show'))
            ->assertSessionHas('success', __('crm.trashed.flash_restored'));
        $c->refresh();
        $this->assertFalse($c->trashed());
        $restored = $this->facts($c, CrmContactEvent::TYPE_CONTACT_RESTORED);
        $this->assertCount(1, $restored);
        $this->assertSame($sa->id, $restored->first()->author_user_id);
        $this->actingAs($this->adminB)->get($orgList)->assertOk()->assertSee('data-crm-contact="'.$c->id.'"', false);
        $this->actingAs($sa)->post($url('contacts.restore'))->assertNotFound();
        $this->actingAs($sa)->post($url('contacts.notes.store'), ['body' => 'de retour'])->assertRedirect();
    }

    // ── 6. Supprimer/restaurer : plateforme seulement, route ET service ────

    public function test_delete_and_restore_are_platform_only_at_the_route_and_at_the_service(): void
    {
        $this->actingAs($this->adminB)->delete($this->admin('contacts.destroy', $this->orgB, ['contact' => $this->contactB->id]))->assertForbidden();

        try {
            $this->contacts->delete($this->contactB, $this->adminB);
            $this->fail('An OrgAdmin must not delete a CRM contact in V1.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('platform admin', $e->getMessage());
        }
        $this->assertFalse($this->contactB->fresh()->trashed());
        $this->assertSame(0, $this->contactB->events()->count());

        // L'OrgAdmin ne voit aucun bouton « Supprimer » sur SA fiche.
        $this->actingAs($this->adminB)
            ->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgB->slug, 'contact' => $this->contactB->id]))
            ->assertOk()->assertDontSee('data-crm-admin-delete', false);
    }

    // ── 7. Email : memes gardes ; la plateforme envoie pour un tenant sans le SIGNER (MASTER Q53) ─

    private function capturedReplyTo(int $index): array
    {
        return array_map(fn ($a) => $a->getAddress(), $this->capturedMailHtml[$index]['message']->getSymfonyMessage()->getReplyTo());
    }

    private function tokenFor(Organization $org, CrmContact $contact, EmailTemplate $template): string
    {
        return session(CrmEmailSendService::TOKEN_SESSION_PREFIX.$org->id.':'.$contact->id.':'.$template->id)['token'];
    }

    public function test_email_from_the_platform_reuses_the_guards_and_replies_to_the_tenant_admin_never_to_the_platform(): void
    {
        $this->captureMailHtml();
        $sa = $this->superAdmin;
        $templateA = app(CrmEmailTemplateService::class)->create($this->orgA, 'Relance', 'Bonjour {{ first_name }}', '<p>Suite {{ full_name }}</p>');
        $templateB = app(CrmEmailTemplateService::class)->create($this->orgB, 'Relance B', 'Bonjour {{ first_name }}', '<p>Suite {{ full_name }}</p>');
        $previewB = $this->admin('contacts.email.preview', $this->orgB, ['contact' => $this->contactB->id, 'template' => $templateB->id]);
        $sendB = $this->admin('contacts.email.send', $this->orgB, ['contact' => $this->contactB->id, 'template' => $templateB->id]);
        $showB = $this->admin('contacts.show', $this->orgB, ['contact' => $this->contactB->id]);

        // Dans SON Organization (A) : il repond lui-meme.
        $this->actingAs($sa)->get($this->admin('contacts.email.preview', $this->orgA, ['contact' => $this->contactA->id, 'template' => $templateA->id]))->assertOk();
        $this->assertSame(0, $this->capturedMailCount());
        $this->actingAs($sa)->post($this->admin('contacts.email.send', $this->orgA, ['contact' => $this->contactA->id, 'template' => $templateA->id]), ['token' => $this->tokenFor($this->orgA, $this->contactA, $templateA)])
            ->assertRedirect($this->admin('contacts.show', $this->orgA, ['contact' => $this->contactA->id]));
        $this->assertSame(1, $this->capturedMailCount());
        $this->assertSame([$sa->email], $this->capturedReplyTo(0));

        // Dans une AUTRE Organization (B) : preview possible ; envoi avec Reply-To = admin de B, acteur = plateforme.
        $html = $this->actingAs($sa)->get($previewB)->assertOk()->getContent();
        $this->assertStringContainsString('/admin/relations/org-b-1431/contacts/'.$this->contactB->id.'/email/'.$templateB->id, $html);
        $this->assertSame(1, $this->capturedMailCount());
        $token = $this->tokenFor($this->orgB, $this->contactB, $templateB);
        $this->actingAs($sa)->post($sendB, ['token' => $token])->assertRedirect($showB)
            ->assertSessionHas('success', __('crm.email.flash_sent', ['to' => 'quixotic@example.com']));
        $this->assertSame(2, $this->capturedMailCount());
        $this->assertSame(['quixotic@example.com'], $this->capturedMailTo(1));
        $this->assertSame([$this->adminB->email], $this->capturedReplyTo(1), 'le prospect repond au tenant, jamais a la plateforme');
        $this->assertNotContains($sa->email, $this->capturedReplyTo(1));
        $sent = $this->facts($this->contactB, CrmContactEvent::TYPE_EMAIL_SENT);
        $this->assertCount(1, $sent);
        $this->assertSame($sa->id, $sent->first()->author_user_id);
        $log = EmailLog::where('crm_contact_id', $this->contactB->id)->firstOrFail();
        $this->assertSame($sa->id, $log->data['sender_id']);
        $this->assertSame($this->adminB->id, $log->data['reply_to_id']);
        // L'historique de la fiche nomme l'expediteur plateforme.
        $this->assertStringContainsString('Sacha Plateforme', $this->actingAs($sa)->get($showB)->assertOk()->getContent());

        // Double-submit : le jeton est consomme, rien ne repart.
        $this->actingAs($sa)->post($sendB, ['token' => $token])->assertRedirect($showB)->assertSessionHas('error', __('crm.email.flash_token'));
        $this->assertSame(2, $this->capturedMailCount());

        // Un modele de A ne s'applique jamais a un Contact de B (404 : resolu dans l'Organization de l'URL).
        $this->actingAs($sa)->get($this->admin('contacts.email.preview', $this->orgB, ['contact' => $this->contactB->id, 'template' => $templateA->id]))->assertNotFound();

        // Organization sans admin exploitable : preview possible, envoi refuse, 0 mail, 0 fait.
        $this->orgB->update(['admin_id' => null]);
        $this->actingAs($sa)->get($previewB)->assertOk();
        $this->actingAs($sa)->post($sendB, ['token' => $this->tokenFor($this->orgB, $this->contactB, $templateB)])->assertRedirect($showB)
            ->assertSessionHas('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.no_organization_admin')]));
        $this->assertSame(2, $this->capturedMailCount());
        $this->assertCount(1, $this->facts($this->contactB, CrmContactEvent::TYPE_EMAIL_SENT));
        // Un admin banni n'est pas exploitable non plus.
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->adminB->forceFill(['banned_at' => now()])->save();
        $this->actingAs($sa)->get($previewB)->assertOk();
        $this->actingAs($sa)->post($sendB, ['token' => $this->tokenFor($this->orgB, $this->contactB, $templateB)])->assertRedirect($showB)
            ->assertSessionHas('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.no_organization_admin')]));
        $this->assertSame(2, $this->capturedMailCount());
        $this->adminB->forceFill(['banned_at' => null])->save();

        // « Ne pas contacter » reste prioritaire : la preview elle-meme est refusee.
        $this->actingAs($sa)->post($this->admin('contacts.policy', $this->orgB, ['contact' => $this->contactB->id]), ['action' => 'block', 'reason' => 'contact_request'])->assertRedirect();
        $this->actingAs($sa)->get($previewB)->assertRedirect($showB)
            ->assertSessionHas('error', __('crm.email.flash_blocked', ['reason' => __('crm.email.reason.do_not_contact')]));
        $this->assertSame(2, $this->capturedMailCount());

        // Un simple OrgAdmin d'ailleurs reste refuse par la route (403) — et par le service.
        $this->actingAs($this->adminA)->get($previewB)->assertForbidden();
        try {
            app(CrmEmailSendService::class)->guard($this->contactB->fresh()->forceFill(['do_not_contact_at' => null]), $templateB, $this->adminA, sending: true);
            $this->fail('A member of another Organization must not send.');
        } catch (\LogicException $e) {
            $this->assertSame('sender_organization', $e->getMessage());
        }
    }
}
