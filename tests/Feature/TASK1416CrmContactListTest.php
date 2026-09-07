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
 * TASK-1416 — CRM-4, « Relations » : la liste des Contacts de l'Organization.
 *
 * Ce qui est prouve ici :
 * - l'acces : OrgAdmin de SON Organization ou admin plateforme ; un membre
 *   ordinaire est refuse ;
 * - le tenant : un Contact ou un statut d'une autre Organization donne 404,
 *   jamais 403, et rien n'est ecrit ;
 * - les filtres (recherche, statut, sans contact depuis 30 j) ;
 * - les trois actions rapides (creer, changer le statut, ajouter une note)
 *   passent par les services et laissent leur trace ;
 * - « Ajouter au suivi » : explicite, meme Organization, idempotent.
 */
class TASK1416CrmContactListTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $memberA;

    private User $superAdmin;

    private CrmContact $contactA;

    private CrmContact $contactB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1416', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1416', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => true]);

        $contacts = app(CrmContactService::class);
        $this->contactA = $contacts->findOrCreate($this->orgA, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe', 'email' => 'zorglub@example.com']);
        $this->contactB = $contacts->findOrCreate($this->orgB, ['first_name' => 'Quixotic', 'last_name' => 'Bellwether', 'email' => 'quixotic@example.com']);
    }

    private function url(Organization $organization, array $query = []): string
    {
        return route('organization.admin.crm.contacts', ['organization' => $organization->slug] + $query);
    }

    // ── 1. Acces ────────────────────────────────────────────────────────────

    public function test_only_the_organization_admin_or_a_platform_admin_can_open_the_list(): void
    {
        $this->get($this->url($this->orgA))->assertRedirect();
        $this->actingAs($this->memberA)->get($this->url($this->orgA))->assertForbidden();
        $this->actingAs($this->adminB)->get($this->url($this->orgA))->assertForbidden();
        $this->actingAs($this->adminA)->get($this->url($this->orgA))->assertOk();
        $this->actingAs($this->superAdmin)->get($this->url($this->orgA))->assertOk();
        $this->actingAs($this->superAdmin)->get($this->url($this->orgB))->assertOk();
    }

    public function test_the_list_shows_only_the_contacts_of_the_organization(): void
    {
        $response = $this->actingAs($this->adminA)->get($this->url($this->orgA));

        $response->assertOk()
            ->assertSee('Zorglub Amaranthe')
            ->assertSee('data-crm-contact="'.$this->contactA->id.'"', false)
            ->assertDontSee('Quixotic')
            ->assertDontSee($this->contactB->id);
    }

    public function test_the_org_admin_rail_links_to_the_organization_prefixed_list(): void
    {
        $this->actingAs($this->adminA)
            ->get(route('organization.admin.dashboard', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee('href="'.$this->url($this->orgA).'"', false)
            ->assertSee('/org/'.$this->orgA->slug.'/admin/relations');
    }

    // ── 2. Tenant : 404, jamais 403, rien d'ecrit ───────────────────────────

    public function test_acting_on_a_contact_of_another_organization_is_a_404_and_writes_nothing(): void
    {
        $statusA = app(CrmStatusService::class)->ensureDefaultPipeline($this->orgA)->last();

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.contacts.status', ['organization' => $this->orgA->slug, 'contact' => $this->contactB->id]), ['status_id' => $statusA->id])
            ->assertNotFound();
        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.contacts.notes.store', ['organization' => $this->orgA->slug, 'contact' => $this->contactB->id]), ['body' => 'intrusion'])
            ->assertNotFound();

        $this->assertSame(0, $this->contactB->events()->count());
        $this->assertSame($this->contactB->status_id, $this->contactB->fresh()->status_id);
    }

    public function test_a_status_of_another_organization_is_a_404_and_the_contact_is_unchanged(): void
    {
        $statusB = app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB)->last();
        $before = $this->contactA->status_id;

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.contacts.status', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]), ['status_id' => $statusB->id])
            ->assertNotFound();

        $this->assertSame($before, $this->contactA->fresh()->status_id);
        $this->assertSame(0, $this->contactA->events()->count());
    }

    // ── 3. Filtres ──────────────────────────────────────────────────────────

    public function test_search_status_and_idle_filters(): void
    {
        $statuses = app(CrmStatusService::class);
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();
        $nouveau = $statuses->defaultStatus($this->orgA);
        $other = app(CrmContactService::class)->findOrCreate($this->orgA, ['first_name' => 'Wilhelmina', 'last_name' => 'Ostrogoth', 'phone' => '+33611223344']);
        $statuses->changeStatus($other, $client, $this->adminA);
        app(CrmTimelineService::class)->addNote($other, 'Appel', $this->adminA, 'call');

        $this->actingAs($this->adminA)->get($this->url($this->orgA, ['search' => 'zorg']))
            ->assertSee('Zorglub')->assertDontSee('Wilhelmina');
        // Les chiffres se cherchent en chiffres, espaces ignores : « 611 22 33 »
        // trouve « +33611223344 ». (« 06… » vs « +33 6… » attend CRM-10.)
        $this->actingAs($this->adminA)->get($this->url($this->orgA, ['search' => '611 22 33']))
            ->assertSee('Wilhelmina')->assertDontSee('Zorglub');
        $this->actingAs($this->adminA)->get($this->url($this->orgA, ['search' => 'introuvable-xyz']))
            ->assertSee('data-crm-empty', false);

        $this->actingAs($this->adminA)->get($this->url($this->orgA, ['status' => $client->id]))
            ->assertSee('Wilhelmina')->assertDontSee('Zorglub');
        $this->actingAs($this->adminA)->get($this->url($this->orgA, ['status' => $nouveau->id]))
            ->assertSee('Zorglub')->assertDontSee('Wilhelmina');

        // « Sans contact depuis 30 jours » : jamais contacte = a relancer ;
        // contacte a l'instant = non.
        $this->actingAs($this->adminA)->get($this->url($this->orgA, ['idle' => 1]))
            ->assertSee('Zorglub')->assertDontSee('Wilhelmina');
    }

    // ── 4. Actions rapides ──────────────────────────────────────────────────

    public function test_manual_creation_deduplicates_and_requires_an_email_or_a_phone(): void
    {
        $store = route('organization.admin.crm.contacts.store', ['organization' => $this->orgA->slug]);

        $this->actingAs($this->adminA)->post($store, ['first_name' => 'Nouvelle', 'last_name' => 'Piste', 'email' => 'Nouvelle@Example.com', 'company' => 'ACME'])
            ->assertSessionHasNoErrors()->assertRedirect($this->url($this->orgA))
            ->assertSessionHas('success', __('crm.flash.contact_created'));

        $created = CrmContact::forOrganization($this->orgA)->where('email', 'nouvelle@example.com')->firstOrFail();
        $this->assertSame(CrmContact::SOURCE_MANUAL, $created->source);
        $this->assertSame($this->adminA->id, $created->created_by_user_id);
        $this->assertNotNull($created->status_id, 'un Contact nait avec le statut par defaut');

        $this->actingAs($this->adminA)->post($store, ['email' => 'nouvelle@example.com'])
            ->assertSessionHas('success', __('crm.flash.contact_found'));
        $this->assertSame(1, CrmContact::forOrganization($this->orgA)->where('email', 'nouvelle@example.com')->count());

        $this->actingAs($this->adminA)->post($store, ['first_name' => 'Sans', 'last_name' => 'Coordonnees'])
            ->assertSessionHasErrors(['email', 'phone']);
    }

    public function test_changing_the_status_from_the_list_leaves_a_trace(): void
    {
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.contacts.status', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]), ['status_id' => $client->id])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($client->id, $this->contactA->fresh()->status_id);
        $event = $this->contactA->events()->firstOrFail();
        $this->assertSame(CrmContactEvent::TYPE_STATUS_CHANGED, $event->type);
        $this->assertSame($this->adminA->id, $event->author_user_id);
        $this->assertSame('Client', $event->payload['to_label']);
    }

    public function test_adding_a_note_from_the_list_with_a_channel_updates_the_last_contact(): void
    {
        $notes = route('organization.admin.crm.contacts.notes.store', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]);

        $this->actingAs($this->adminA)->post($notes, ['body' => 'Appel de 5 min', 'channel' => 'call'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $event = $this->contactA->events()->firstOrFail();
        $this->assertSame(CrmContactEvent::TYPE_NOTE, $event->type);
        $this->assertSame('call', $event->payload['channel']);
        $this->assertNotNull($this->contactA->fresh()->last_interaction_at);

        $this->actingAs($this->adminA)->post($notes, ['body' => 'x', 'channel' => 'pigeon'])->assertSessionHasErrors('channel');
        $this->actingAs($this->adminA)->post($notes, ['body' => ''])->assertSessionHasErrors('body');
        $this->assertSame(1, $this->contactA->events()->count());
    }

    // ── 5. « Ajouter au suivi » ─────────────────────────────────────────────

    public function test_follow_member_creates_a_linked_contact_once_and_only_for_the_same_organization(): void
    {
        $follow = fn (Organization $org, User $user) => route('organization.admin.crm.members.follow', ['organization' => $org->slug, 'user' => $user->id]);

        $this->actingAs($this->adminA)->post($follow($this->orgA, $this->memberA))->assertRedirect()
            ->assertSessionHas('success', __('crm.flash.member_followed'));

        $contact = CrmContact::forOrganization($this->orgA)->where('user_id', $this->memberA->id)->firstOrFail();
        $this->assertSame($this->memberA->email, $contact->email);
        $this->assertSame(CrmContact::SOURCE_MANUAL, $contact->source);

        $this->actingAs($this->adminA)->post($follow($this->orgA, $this->memberA))->assertRedirect()
            ->assertSessionHas('success', __('crm.flash.member_already_followed'));
        $this->assertSame(1, CrmContact::forOrganization($this->orgA)->where('user_id', $this->memberA->id)->count());

        // Un membre d'une autre Organization : 404, rien n'est ecrit nulle part.
        $this->actingAs($this->adminA)->post($follow($this->orgA, $this->adminB))->assertNotFound();
        $this->assertSame(0, CrmContact::withTrashed()->where('user_id', $this->adminB->id)->count());

        $this->actingAs($this->adminA)
            ->get(route('organization.admin.users', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee('data-crm-follow="'.$this->memberA->id.'"', false);
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_sees_a_contact_when_it_belongs_to_the_organization(): void
    {
        $this->actingAs($this->adminB)->get($this->url($this->orgB))
            ->assertOk()->assertSee('Quixotic Bellwether')->assertDontSee('Zorglub');
    }
}
