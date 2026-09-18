<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactPolicyService;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmNextActionService;
use App\Services\Crm\CrmStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TASK-1424 — CRM-14 : « Aujourd'hui », la page d'entree de Relations.
 *
 * - /relations EST « Aujourd'hui » ; la liste vit sous /relations/contacts,
 *   son nom de route est inchange ;
 * - les trois echeances se mesurent AUX BORNES sur un mercredi fige, et un
 *   Contact n'apparait que dans UNE section ;
 * - « sans contact » ne compte que les joignables ; les volumes par statut
 *   ignorent un Contact supprime ; les derniers faits sont les N plus
 *   recents de l'Organization, et d'elle seule ;
 * - un Contact d'ailleurs n'apparait NULLE PART, ni dans un compte.
 */
class TASK1424CrmTodayDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $memberA;

    private User $superAdmin;

    private CrmContactService $contacts;

    private CrmNextActionService $actions;

    protected function setUp(): void
    {
        parent::setUp();

        // Mercredi 9 septembre 2026, 10h : la semaine ISO finit dimanche 13.
        $this->travelTo(Carbon::parse('2026-09-09 10:00:00'));

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1424', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1424', 'is_active' => true, 'locale' => 'fr']);
        // Nom FIXE : un nom Faker avec apostrophe est rendu `&#039;` et rougit une assertion sur une chance (CI develop f2cbffc0).
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ada', 'name' => 'Lovelace']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => true]);

        $this->contacts = app(CrmContactService::class);
        $this->actions = app(CrmNextActionService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    private function today(Organization $organization): string
    {
        return route('organization.admin.crm.today', ['organization' => $organization->slug]);
    }

    private function contact(Organization $org, string $first, string $last, array $columns = []): CrmContact
    {
        $contact = $this->contacts->findOrCreate($org, ['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first.'.'.$last).'@example.com']);
        if ($columns !== []) {
            $contact->forceFill($columns)->save();
        }

        return $contact->fresh();
    }

    /** Le HTML d'une section du cockpit, pour mesurer OU un nom apparait. */
    private function section(string $html, string $name): string
    {
        $this->assertMatchesRegularExpression('/<section data-crm-section="'.$name.'".*?<\/section>/s', $html, "section {$name} absente");
        preg_match('/<section data-crm-section="'.$name.'".*?<\/section>/s', $html, $m);

        return $m[0];
    }

    // ── 1. Entree de Relations et acces ─────────────────────────────────────

    public function test_today_is_the_entry_page_of_relations_and_the_list_moved_without_changing_its_name(): void
    {
        $this->assertSame('/org/org-a-1424/admin/relations', parse_url($this->today($this->orgA), PHP_URL_PATH));
        $this->assertSame('/org/org-a-1424/admin/relations/contacts', parse_url(route('organization.admin.crm.contacts', ['organization' => 'org-a-1424']), PHP_URL_PATH));

        $response = $this->actingAs($this->adminA)->get($this->today($this->orgA));
        $response->assertOk()
            ->assertSee(__('crm.today.title'))
            ->assertSee(__('crm.today.subtitle'))
            ->assertSee('mercredi 9 septembre 2026')
            // Le rail : Aujourd'hui puis Contacts, tous deux prefixes par l'Organization.
            ->assertSeeInOrder(['/org/org-a-1424/admin/relations"', '/org/org-a-1424/admin/relations/contacts"'])
            ->assertSee(__('navigation.org_admin_crm_today'));

        $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts', ['organization' => 'org-a-1424']))->assertOk();
    }

    public function test_only_the_organization_admin_or_a_platform_admin_can_open_it(): void
    {
        $this->actingAs($this->memberA)->get($this->today($this->orgA))->assertForbidden();
        $this->actingAs($this->adminB)->get($this->today($this->orgA))->assertForbidden();
        $this->actingAs($this->adminA)->get($this->today($this->orgA))->assertOk();
        $this->actingAs($this->superAdmin)->get($this->today($this->orgA))->assertOk();
    }

    // ── 2. Les trois echeances, aux bornes ──────────────────────────────────

    public function test_today_overdue_and_week_are_measured_at_the_bounds_and_never_overlap(): void
    {
        $late = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $early = $this->contact($this->orgA, 'Yolande', 'Brimborion');
        $noTime = $this->contact($this->orgA, 'Xavier', 'Cassoulet');
        $overdue = $this->contact($this->orgA, 'Wilfrid', 'Dandinet');
        $sunday = $this->contact($this->orgA, 'Valentine', 'Escarmouche');
        $monday = $this->contact($this->orgA, 'Ulysse', 'Farfadet');
        $nothing = $this->contact($this->orgA, 'Tancrede', 'Girouette');

        $this->actions->plan($late, 'call', Carbon::parse('2026-09-09'), '14:00', 'Rappel devis', $this->adminA);
        $this->actions->plan($early, 'meeting', Carbon::parse('2026-09-09'), '09:30', null, $this->adminA);
        $this->actions->plan($noTime, 'email', Carbon::parse('2026-09-09'), null, null, $this->adminA);
        $this->actions->plan($overdue, 'quote', Carbon::parse('2026-09-07'), null, null, $this->adminA);
        $this->actions->plan($sunday, 'whatsapp', Carbon::parse('2026-09-13'), null, null, $this->adminA);
        $this->actions->plan($monday, 'sms', Carbon::parse('2026-09-14'), null, null, $this->adminA);

        $html = $this->actingAs($this->adminA)->get($this->today($this->orgA))->assertOk()->getContent();

        $today = $this->section($html, 'today');
        // Par heure, les sans-heure en dernier ; l'objet et le type sont visibles.
        $this->assertStringContainsString('data-crm-count="3"', $today);
        $this->assertMatchesRegularExpression('/Yolande Brimborion.*Zorglub Amaranthe.*Xavier Cassoulet/s', $today);
        $this->assertStringContainsString('Rappel devis', $today);
        $this->assertStringContainsString('14:00', $today);
        foreach (['Wilfrid Dandinet', 'Valentine Escarmouche', 'Ulysse Farfadet', 'Tancrede Girouette'] as $absent) {
            $this->assertStringNotContainsString($absent, $today);
        }

        $overdueSection = $this->section($html, 'overdue');
        $this->assertStringContainsString('data-crm-count="1"', $overdueSection);
        $this->assertStringContainsString('Wilfrid Dandinet', $overdueSection);
        $this->assertStringContainsString('07/09', $overdueSection);
        $this->assertStringNotContainsString('Zorglub Amaranthe', $overdueSection);

        $week = $this->section($html, 'week');
        $this->assertStringContainsString('data-crm-count="1"', $week);
        $this->assertStringContainsString('Valentine Escarmouche', $week);
        foreach (['Ulysse Farfadet', 'Zorglub Amaranthe', 'Wilfrid Dandinet'] as $absent) {
            $this->assertStringNotContainsString($absent, $week);
        }

        // Le lundi suivant n'est dans AUCUNE echeance ; il ne subsiste que comme
        // fait « action planifiee » dans les derniers faits — c'est le contrat.
        // (Il figure aussi dans « sans contact » : jamais contacte, joignable — c'est juste.)
        $this->assertStringContainsString('Ulysse Farfadet', $this->section($html, 'facts'));
        $this->assertStringContainsString('Ulysse Farfadet', $this->section($html, 'idle'));
    }

    /** MASTER Q36 : le cockpit est strictement en lecture — on agit depuis la fiche. */
    public function test_today_is_read_only_no_form_targets_relations(): void
    {
        $contact = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $this->actions->plan($contact, 'call', Carbon::parse('2026-09-09'), '11:00', null, $this->adminA);
        $this->actions->plan($this->contact($this->orgA, 'Wilfrid', 'Dandinet'), 'quote', Carbon::parse('2026-09-01'), null, null, $this->adminA);

        $html = $this->actingAs($this->adminA)->get($this->today($this->orgA))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<form[^>]*action="[^"]*\/relations\/[^"]*"/', $html, 'aucun formulaire ne vise Relations');
        $this->assertStringNotContainsString('next-action/complete', $html);
        // Le geste existe toujours, sur la fiche : le lien y mene.
        $this->assertStringContainsString(route('organization.admin.crm.contacts.show', ['organization' => 'org-a-1424', 'contact' => $contact->id]), $html);
        $this->assertStringContainsString(__('crm.today.open_contact'), $html);
    }

    // ── 3. Sans contact, volumes, derniers faits ────────────────────────────

    public function test_idle_lists_only_reachable_contacts_without_a_recent_interaction_never_contacted_first(): void
    {
        $never = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $old = $this->contact($this->orgA, 'Yolande', 'Brimborion', ['last_interaction_at' => Carbon::parse('2026-07-01 09:00:00')]);
        $edge = $this->contact($this->orgA, 'Xavier', 'Cassoulet', ['last_interaction_at' => Carbon::parse('2026-08-10 10:00:00')]); // exactement 30 j : pas encore « sans contact »
        $recent = $this->contact($this->orgA, 'Wilfrid', 'Dandinet', ['last_interaction_at' => Carbon::parse('2026-09-08 18:00:00')]);
        $blocked = $this->contact($this->orgA, 'Valentine', 'Escarmouche');
        app(CrmContactPolicyService::class)->block($blocked, 'contact_request', null, $this->adminA);

        $html = $this->actingAs($this->adminA)->get($this->today($this->orgA))->getContent();
        $idle = $this->section($html, 'idle');

        $this->assertStringContainsString('data-crm-idle-count="2"', $idle);
        $this->assertMatchesRegularExpression('/Zorglub Amaranthe.*Jamais.*Yolande Brimborion.*01\/07\/2026/s', $idle);
        foreach (['Xavier Cassoulet', 'Wilfrid Dandinet', 'Valentine Escarmouche'] as $absent) {
            $this->assertStringNotContainsString($absent, $idle);
        }
        $this->assertStringContainsString('idle=1', $idle);
    }

    public function test_volumes_per_status_ignore_a_deleted_contact_and_count_the_unassigned(): void
    {
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgA);
        $new = CrmStatus::forOrganization($this->orgA)->where('label', 'Nouveau')->firstOrFail();
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();
        $lost = CrmStatus::forOrganization($this->orgA)->where('label', 'Perdu')->firstOrFail();

        $a = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');            // Nouveau (statut par defaut)
        $b = $this->contact($this->orgA, 'Yolande', 'Brimborion');           // Nouveau
        $c = $this->contact($this->orgA, 'Xavier', 'Cassoulet', ['status_id' => $client->id]);
        $d = $this->contact($this->orgA, 'Wilfrid', 'Dandinet', ['status_id' => null]);
        $e = $this->contact($this->orgA, 'Valentine', 'Escarmouche', ['status_id' => $client->id]);
        $e->delete();

        $html = $this->actingAs($this->adminA)->get($this->today($this->orgA))->getContent();
        $statuses = $this->section($html, 'statuses');

        $this->assertStringContainsString('data-crm-total="4"', $statuses);
        $this->assertStringContainsString('data-crm-unassigned="1"', $statuses);
        $this->assertStringContainsString('data-crm-status-count="2" data-crm-status="'.$new->id.'"', $statuses);
        $this->assertStringContainsString('data-crm-status-count="1" data-crm-status="'.$client->id.'"', $statuses);
        $this->assertStringContainsString('data-crm-status-count="0" data-crm-status="'.$lost->id.'"', $statuses);
        $this->assertStringContainsString('status='.$client->id, $statuses);
        $this->assertStringContainsString(__('crm.today.unassigned'), $statuses);
    }

    public function test_last_facts_are_the_ten_newest_of_the_organization_newest_first(): void
    {
        $contact = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $other = $this->contact($this->orgB, 'Quixotic', 'Bellwether');

        for ($i = 1; $i <= 12; $i++) {
            CrmContactEvent::create([
                'organization_id' => $this->orgA->id,
                'crm_contact_id' => $contact->id,
                'type' => 'note',
                'author_user_id' => $this->adminA->id,
                'payload' => ['body' => 'Fait numero '.$i],
                'occurred_at' => now()->subMinutes($i),
            ]);
        }
        CrmContactEvent::create([
            'organization_id' => $this->orgB->id,
            'crm_contact_id' => $other->id,
            'type' => 'note',
            'author_user_id' => $this->adminB->id,
            'payload' => ['body' => 'Fait ailleurs'],
            'occurred_at' => now(),
        ]);

        $html = $this->actingAs($this->adminA)->get($this->today($this->orgA))->getContent();
        $facts = $this->section($html, 'facts');

        $this->assertStringContainsString('data-crm-count="10"', $facts);
        $this->assertMatchesRegularExpression('/Fait numero 1\s*<.*Fait numero 2\s*<.*Fait numero 10\s*</s', $facts);
        $this->assertStringNotContainsString('Fait numero 11', $facts);
        $this->assertStringNotContainsString('Fait numero 12', $facts);
        $this->assertStringNotContainsString('Fait ailleurs', $html);
        $this->assertStringContainsString('Ada Lovelace', $facts);
    }

    // ── 4. Tenant et etats vides ────────────────────────────────────────────

    public function test_a_contact_of_another_organization_appears_nowhere_not_even_in_a_count(): void
    {
        $other = $this->contact($this->orgB, 'Quixotic', 'Bellwether');
        $this->actions->plan($other, 'call', Carbon::parse('2026-09-09'), null, null, $this->adminB);
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB);

        $html = $this->actingAs($this->adminA)->get($this->today($this->orgA))->getContent();

        $this->assertStringNotContainsString('Quixotic', $html);
        $this->assertStringContainsString('data-crm-count="0"', $this->section($html, 'today'));
        $this->assertStringContainsString('data-crm-idle-count="0"', $this->section($html, 'idle'));
        $this->assertStringContainsString('data-crm-total="0"', $this->section($html, 'statuses'));
        $this->assertStringContainsString('data-crm-count="0"', $this->section($html, 'facts'));
    }

    public function test_an_organization_without_any_contact_sees_every_empty_state(): void
    {
        $response = $this->actingAs($this->adminA)->get($this->today($this->orgA));

        $response->assertOk();
        foreach (['today', 'overdue', 'week', 'idle', 'facts'] as $key) {
            $response->assertSee('data-crm-empty="'.$key.'"', false);
        }
        $response->assertSee(__('crm.today.empty_today'))
            ->assertSee(__('crm.today.empty_facts'))
            ->assertSee('data-crm-total="0"', false);
        // Le pipeline par defaut est seme a l'ouverture : les statuts sont la, a zero.
        $this->assertSame(6, CrmStatus::forOrganization($this->orgA)->count());
    }
}
