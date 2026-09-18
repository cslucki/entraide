<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmStatusService;
use App\Services\Crm\CrmTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1417 — CRM-5, la fiche Contact.
 *
 * - la fiche se lit dans le tenant (404 ailleurs), par l'OrgAdmin ou l'admin
 *   plateforme ;
 * - la timeline s'affiche du plus recent au plus ancien, avec auteur, canal,
 *   ancien → nouveau statut ;
 * - statut et note depuis la fiche laissent leur trace ;
 * - les coordonnees se corrigent, chaque changement est un fait
 *   `contact_updated` ; un email deja porte dans l'Organization est refuse,
 *   le meme email dans une autre Organization ne gene pas.
 */
class TASK1417CrmContactDetailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $memberA;

    private CrmContact $contactA;

    private CrmContact $contactB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1417', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1417', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Aline', 'name' => 'Admin']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);

        $contacts = app(CrmContactService::class);
        $this->contactA = $contacts->findOrCreate($this->orgA, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe', 'email' => 'zorglub@example.com', 'phone' => '+33600000001']);
        $this->contactB = $contacts->findOrCreate($this->orgB, ['first_name' => 'Quixotic', 'last_name' => 'Bellwether', 'email' => 'quixotic@example.com']);
    }

    private function show(Organization $organization, CrmContact $contact): string
    {
        return route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $contact->id]);
    }

    // ── 1. Acces + tenant ───────────────────────────────────────────────────

    public function test_the_page_is_tenant_scoped_and_admin_only(): void
    {
        $this->actingAs($this->memberA)->get($this->show($this->orgA, $this->contactA))->assertForbidden();
        $this->actingAs($this->adminA)->get($this->show($this->orgA, $this->contactB))->assertNotFound();
        $this->actingAs($this->adminB)->get($this->show($this->orgA, $this->contactA))->assertForbidden();

        $this->actingAs($this->adminA)->get($this->show($this->orgA, $this->contactA))
            ->assertOk()
            ->assertSee('Zorglub Amaranthe')
            ->assertSee('zorglub@example.com')
            ->assertSee('data-crm-timeline', false)
            ->assertSee('data-crm-timeline-empty', false)
            ->assertDontSee('Quixotic');

        $superAdmin = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => true]);
        $this->actingAs($superAdmin)->get($this->show($this->orgA, $this->contactA))->assertOk();
    }

    public function test_the_list_links_to_the_page(): void
    {
        $this->actingAs($this->adminA)
            ->get(route('organization.admin.crm.contacts', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee('data-crm-open="'.$this->contactA->id.'"', false)
            ->assertSee('href="'.$this->show($this->orgA, $this->contactA).'"', false);
    }

    // ── 2. Timeline ─────────────────────────────────────────────────────────

    public function test_the_timeline_reads_newest_first_with_author_channel_and_status_labels(): void
    {
        $statuses = app(CrmStatusService::class);
        $timeline = app(CrmTimelineService::class);
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();

        $this->travel(-10)->minutes();
        $timeline->addNote($this->contactA, "Premier appel.\nTres interesse.", $this->adminA, 'call');
        $this->travel(5)->minutes();
        $statuses->changeStatus($this->contactA, $client, $this->adminA);
        $this->travelBack();
        $timeline->addNote($this->contactA, 'Memo interne', $this->adminA);

        $response = $this->actingAs($this->adminA)->get($this->show($this->orgA, $this->contactA))->assertOk();
        $html = $response->getContent();

        preg_match_all('/data-crm-event="([a-z_]+)"/', $html, $m);
        $this->assertSame(['note', 'status_changed', 'note'], $m[1], 'du plus recent au plus ancien');
        $this->assertTrue(strpos($html, 'Memo interne') < strpos($html, 'Premier appel'), 'la derniere note vient en premier');

        $response->assertSee('Premier appel.<br />', false)
            ->assertSee('Aline Admin')
            ->assertSee(__('crm.channel.call'))
            ->assertSee('Nouveau')
            ->assertSee('<strong>Client</strong>', false)
            ->assertDontSee('data-crm-timeline-empty');
    }

    // ── 3. Actions depuis la fiche ──────────────────────────────────────────

    public function test_status_and_note_from_the_page_leave_their_trace(): void
    {
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();
        $page = $this->show($this->orgA, $this->contactA);

        $this->actingAs($this->adminA)->from($page)
            ->post(route('organization.admin.crm.contacts.status', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]), ['status_id' => $client->id])
            ->assertRedirect($page);
        $this->actingAs($this->adminA)->from($page)
            ->post(route('organization.admin.crm.contacts.notes.store', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]), ['body' => 'Depuis la fiche', 'channel' => 'meeting'])
            ->assertRedirect($page);

        $this->assertSame($client->id, $this->contactA->fresh()->status_id);
        $this->assertSame(['status_changed', 'note'], $this->contactA->events()->chronological()->pluck('type')->all());
        $this->assertNotNull($this->contactA->fresh()->last_interaction_at);
    }

    // ── 4. Coordonnees : correction tracee, dedup tenant ────────────────────

    public function test_editing_details_writes_a_contact_updated_fact_with_old_and_new_values(): void
    {
        $update = route('organization.admin.crm.contacts.update', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]);

        $this->actingAs($this->adminA)
            ->put($update, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe-Dupont', 'email' => 'Zorglub.Dupont@Example.com', 'phone' => '+33 6 00 00 00 02', 'company' => 'ACME'])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->show($this->orgA, $this->contactA))
            ->assertSessionHas('success', __('crm.flash_contact_updated'));

        $fresh = $this->contactA->fresh();
        $this->assertSame('zorglub.dupont@example.com', $fresh->email);
        $this->assertSame('+33 6 00 00 00 02', $fresh->phone);
        $this->assertSame('+33600000002', $fresh->phone_normalized);
        $this->assertSame('ACME', $fresh->company);
        $this->assertNull($fresh->last_interaction_at, 'corriger une coordonnee n est pas un contact');

        $event = $this->contactA->events()->firstOrFail();
        $this->assertSame(CrmContactEvent::TYPE_CONTACT_UPDATED, $event->type);
        $this->assertSame($this->adminA->id, $event->author_user_id);
        $this->assertSame(['last_name', 'email', 'phone', 'company'], array_keys($event->payload['changes']));
        $this->assertSame(['from' => 'zorglub@example.com', 'to' => 'zorglub.dupont@example.com'], $event->payload['changes']['email']);

        // Rejouer les memes valeurs n'ecrit rien.
        $this->actingAs($this->adminA)
            ->put($update, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe-Dupont', 'email' => 'zorglub.dupont@example.com', 'phone' => '+33 6 00 00 00 02', 'company' => 'ACME'])
            ->assertSessionHas('success', __('crm.flash_contact_unchanged'));
        $this->assertSame(1, $this->contactA->events()->count());
    }

    public function test_an_email_already_used_in_the_organization_is_refused_but_the_same_email_elsewhere_is_fine(): void
    {
        app(CrmContactService::class)->findOrCreate($this->orgA, ['email' => 'pris@example.com']);
        $update = route('organization.admin.crm.contacts.update', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]);

        $this->actingAs($this->adminA)->put($update, ['email' => 'pris@example.com'])
            ->assertRedirect()->assertSessionHas('error', __('crm.flash_update_conflict'));
        $this->assertSame('zorglub@example.com', $this->contactA->fresh()->email);
        $this->assertSame(0, $this->contactA->events()->count());

        // L'email du Contact de l'Organization B n'est pas « pris » pour A.
        $this->actingAs($this->adminA)->put($update, ['email' => 'quixotic@example.com'])
            ->assertSessionHas('success', __('crm.flash_contact_updated'));
        $this->assertSame('quixotic@example.com', $this->contactA->fresh()->email);
        $this->assertSame('quixotic@example.com', $this->contactB->fresh()->email, 'B n a pas bouge');

        // Ni email ni telephone : refus de validation.
        $this->actingAs($this->adminA)->put($update, ['first_name' => 'Sans', 'email' => '', 'phone' => ''])
            ->assertSessionHasErrors(['email', 'phone']);
    }

    public function test_editing_a_contact_of_another_organization_is_a_404_and_writes_nothing(): void
    {
        $this->actingAs($this->adminA)
            ->put(route('organization.admin.crm.contacts.update', ['organization' => $this->orgA->slug, 'contact' => $this->contactB->id]), ['email' => 'pirate@example.com'])
            ->assertNotFound();

        $this->assertSame('quixotic@example.com', $this->contactB->fresh()->email);
        $this->assertSame(0, $this->contactB->events()->count());
    }

    // ── 5. Header : compte relie, ne pas contacter ──────────────────────────

    public function test_the_header_shows_the_linked_account_and_the_do_not_contact_badge(): void
    {
        app(CrmContactService::class)->linkToUser($this->contactA, $this->memberA);
        $this->contactA->update(['do_not_contact_at' => now()]);

        $this->actingAs($this->adminA)->get($this->show($this->orgA, $this->contactA))
            ->assertOk()
            ->assertSee('data-crm-linked', false)
            ->assertSee('data-crm-do-not-contact', false)
            ->assertSee('search='.urlencode($this->memberA->email), false);
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_sees_the_other_organization_page_for_its_own_admin(): void
    {
        $this->actingAs($this->adminB)->get($this->show($this->orgB, $this->contactB))
            ->assertOk()->assertSee('Quixotic Bellwether')->assertDontSee('Zorglub');
    }
}
