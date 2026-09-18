<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactPolicyService;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmEmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1422 — CRM-13, la contactabilite decidee explicitement.
 *
 * - « ne plus contacter » / « autoriser a nouveau » portent une raison bornee,
 *   une note optionnelle, un auteur, et laissent un fait ; le meme etat
 *   redemande n'ecrit rien ;
 * - l'effet est fail-closed en aval : apres la bascule, l'email ne part plus
 *   (preview et envoi refuses) ; apres la reactivation, il repart ;
 * - tenant : un Contact d'ailleurs = 404 ; un acteur d'ailleurs est refuse.
 */
class TASK1422CrmContactPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private CrmContact $contactA;

    private CrmContactPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1422', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1422', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->contactA = app(CrmContactService::class)->findOrCreate($this->orgA, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe', 'email' => 'zorglub@example.com']);
        $this->policy = app(CrmContactPolicyService::class);
    }

    private function policyUrl(CrmContact $contact, ?Organization $org = null): string
    {
        return route('organization.admin.crm.contacts.policy', ['organization' => ($org ?? $this->orgA)->slug, 'contact' => $contact->id]);
    }

    private function show(CrmContact $contact): string
    {
        return route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id]);
    }

    // ── 1. Bascule explicite, tracee ────────────────────────────────────────

    public function test_blocking_records_a_reasoned_fact_and_the_same_state_again_writes_nothing(): void
    {
        $event = $this->policy->block($this->contactA, 'contact_request', '  Demande par telephone  ', $this->adminA);

        $fresh = $this->contactA->fresh();
        $this->assertFalse($fresh->isContactable());
        $this->assertNotNull($fresh->do_not_contact_at);
        $this->assertSame(CrmContactEvent::TYPE_CONTACT_POLICY_CHANGED, $event->type);
        $this->assertTrue($event->payload['from_contactable']);
        $this->assertFalse($event->payload['to_contactable']);
        $this->assertSame('contact_request', $event->payload['reason']);
        $this->assertSame('Demande par telephone', $event->payload['note']);
        $this->assertSame($this->adminA->id, $event->author_user_id);
        $this->assertNull($fresh->last_interaction_at, 'une decision de contactabilite n est pas une interaction');

        $this->assertNull($this->policy->block($this->contactA->fresh(), 'other', null, $this->adminA), 'deja bloque : rien n est ecrit');
        $this->assertSame(1, $this->contactA->events()->count());

        try {
            $this->policy->block($this->contactA->fresh(), 'pigeon', null, $this->adminA);
            $this->fail('raison inconnue refusee');
        } catch (LogicException) {
        }
    }

    public function test_allowing_again_clears_the_flag_records_a_fact_and_keeps_the_history(): void
    {
        $this->policy->block($this->contactA, 'contact_request', null, $this->adminA);

        $event = $this->policy->allow($this->contactA->fresh(), 'data_error', 'Mauvaise fiche', $this->adminA);

        $this->assertNotNull($event);
        $this->assertFalse($event->payload['from_contactable']);
        $this->assertTrue($event->payload['to_contactable']);
        $this->assertSame('data_error', $event->payload['reason']);
        $fresh = $this->contactA->fresh();
        $this->assertTrue($fresh->isContactable());
        $this->assertNull($fresh->do_not_contact_at);

        $this->assertNull($this->policy->allow($fresh, 'other', null, $this->adminA), 'deja contactable : rien n est ecrit');
        $this->assertSame([false, true], $this->contactA->events()->chronological()->get()->pluck('payload.to_contactable')->all());
        // Aucun effet retroactif : le premier fait garde son contenu.
        $this->assertSame('contact_request', $this->contactA->events()->chronological()->first()->payload['reason']);
    }

    // ── 2. L'effet est fail-closed en aval ──────────────────────────────────

    public function test_after_blocking_no_email_leaves_and_after_allowing_it_does_again(): void
    {
        $captured = 0;
        Mail::shouldReceive('html')->andReturnUsing(function () use (&$captured) { $captured++; return null; });
        $template = app(CrmEmailTemplateService::class)->create($this->orgA, 'Relance', 'Objet', '<p>Corps</p>');
        $preview = route('organization.admin.crm.contacts.email.preview', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id, 'template' => $template->id]);

        $this->actingAs($this->adminA)->post($this->policyUrl($this->contactA), ['action' => 'block', 'reason' => 'contact_request'])
            ->assertRedirect()->assertSessionHas('success', __('crm.policy.flash_blocked'));
        $this->actingAs($this->adminA)->get($preview)->assertRedirect($this->show($this->contactA))->assertSessionHas('error');
        $this->assertSame(0, $captured);
        $this->assertSame(0, EmailLog::count());

        $this->actingAs($this->adminA)->post($this->policyUrl($this->contactA), ['action' => 'allow', 'reason' => 'data_error', 'note' => 'Erreur'])
            ->assertRedirect()->assertSessionHas('success', __('crm.policy.flash_allowed'));
        $this->actingAs($this->adminA)->get($preview)->assertOk();
        $token = session(\App\Services\Crm\CrmEmailSendService::TOKEN_SESSION_PREFIX.$this->orgA->id.':'.$this->contactA->id.':'.$template->id)['token'];
        $this->actingAs($this->adminA)->post(route('organization.admin.crm.contacts.email.send', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id, 'template' => $template->id]), ['token' => $token])
            ->assertSessionHas('success');
        $this->assertSame(1, $captured);
    }

    // ── 3. Surface ──────────────────────────────────────────────────────────

    public function test_the_page_shows_the_state_the_form_and_the_facts(): void
    {
        $this->actingAs($this->adminA)->get($this->show($this->contactA))->assertOk()
            ->assertSee('data-crm-policy-state="contactable"', false)
            ->assertSee('data-crm-policy-form', false)
            ->assertSee(__('crm.policy.reason.contact_request'))
            ->assertDontSee('data-crm-do-not-contact');

        $this->actingAs($this->adminA)->post($this->policyUrl($this->contactA), ['action' => 'block', 'reason' => 'other', 'note' => 'Ne veut plus de nouvelles'])->assertRedirect();

        $this->actingAs($this->adminA)->get($this->show($this->contactA))->assertOk()
            ->assertSee('data-crm-policy-state="blocked"', false)
            ->assertSee('data-crm-do-not-contact', false)
            ->assertSee('data-crm-email-blocked', false)
            ->assertSee('data-crm-event="contact_policy_changed"', false)
            ->assertSee('Ne veut plus de nouvelles')
            ->assertSee(__('crm.policy_state.do_not_contact'));

        $this->actingAs($this->adminA)->post($this->policyUrl($this->contactA), ['action' => 'block', 'reason' => 'other'])
            ->assertSessionHas('success', __('crm.policy.flash_unchanged'));
        $this->assertSame(1, $this->contactA->events()->count());

        $this->actingAs($this->adminA)->post($this->policyUrl($this->contactA), ['action' => 'block', 'reason' => 'pigeon'])->assertSessionHasErrors('reason');
        $this->actingAs($this->adminA)->post($this->policyUrl($this->contactA), ['action' => 'nuke', 'reason' => 'other'])->assertSessionHasErrors('action');
    }

    // ── 4. Tenant ───────────────────────────────────────────────────────────

    public function test_a_contact_of_another_organization_is_a_404_and_an_actor_from_elsewhere_is_refused(): void
    {
        $contactB = app(CrmContactService::class)->findOrCreate($this->orgB, ['email' => 'b@example.com']);

        $this->actingAs($this->adminA)->post($this->policyUrl($contactB), ['action' => 'block', 'reason' => 'other'])->assertNotFound();
        $this->assertTrue($contactB->fresh()->isContactable());
        $this->assertSame(0, $contactB->events()->count());

        try {
            $this->policy->block($this->contactA, 'other', null, $this->adminB);
            $this->fail('acteur d une autre Organization refuse');
        } catch (LogicException) {
        }
        $this->assertTrue($this->contactA->fresh()->isContactable());
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_distinguishes_blocked_from_contactable(): void
    {
        $blocked = CrmContact::factory()->doNotContact()->create(['organization_id' => $this->orgA->id]);

        $this->assertFalse($blocked->isContactable());
        $this->assertTrue($this->contactA->isContactable());
        $this->actingAs($this->adminA)->get($this->show($blocked))->assertOk()->assertSee('data-crm-policy-state="blocked"', false);
    }
}
