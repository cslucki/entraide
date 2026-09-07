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
use App\Services\Crm\CrmTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * TASK-1427 — Panneau CRM du SuperAdmin : TOUT voir (decision Cyril, 07/09
 * 20h30). Contacts, echeances et derniers faits de TOUTES les Organizations,
 * avec leur contenu ; reserve a `is_admin` ; lecture seule (on agit depuis
 * la fiche du cockpit de l'Organization, ou le SuperAdmin passe deja).
 */
class TASK1427CrmSuperAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $superAdmin;

    private CrmContactService $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-09 10:00:00'));

        $this->orgA = Organization::factory()->create(['name' => 'Alpha Corp', 'slug' => 'org-a-1427', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['name' => 'Beta SAS', 'slug' => 'org-b-1427', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ada', 'name' => 'Lovelace']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);

        $this->contacts = app(CrmContactService::class);
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgA);
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    private function contact(Organization $org, string $first, string $last, array $columns = []): CrmContact
    {
        $contact = $this->contacts->findOrCreate($org, ['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first.'.'.$last).'@example.com']);
        if ($columns !== []) {
            $contact->forceFill($columns)->save();
        }

        return $contact->fresh();
    }

    private function contactIds(string $html): array
    {
        preg_match_all('/data-crm-admin-contact="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_only_a_platform_admin_can_open_the_three_pages(): void
    {
        foreach (['admin.crm.overview', 'admin.crm.overview.today', 'admin.crm.overview.facts'] as $route) {
            $this->actingAs($this->adminA)->get(route($route))->assertForbidden();
            $this->actingAs($this->adminB)->get(route($route))->assertForbidden();
            $this->actingAs($this->superAdmin)->get(route($route))->assertOk();
        }
        $this->assertSame('/admin/relations/aujourdhui', parse_url(route('admin.crm.overview.today'), PHP_URL_PATH));
        $this->assertSame('/admin/relations/faits', parse_url(route('admin.crm.overview.facts'), PHP_URL_PATH));
    }

    public function test_the_overview_lists_every_organization_s_contacts_under_the_aggregates_with_names_and_links_to_the_right_cockpit(): void
    {
        $a = $this->contact($this->orgA, 'Zorglub', 'Amaranthe', ['last_interaction_at' => Carbon::parse('2026-09-08 09:00:00')]);
        $b = $this->contact($this->orgB, 'Quixotic', 'Bellwether');

        $html = $this->actingAs($this->superAdmin)->get(route('admin.crm.overview'))->assertOk()->getContent();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->contactIds($html));
        $this->assertStringContainsString('Zorglub Amaranthe', $html);
        $this->assertStringContainsString('Quixotic Bellwether', $html);
        $this->assertStringContainsString('Alpha Corp', $html);
        $this->assertStringContainsString('Beta SAS', $html);
        $this->assertStringContainsString(route('organization.admin.crm.contacts.show', ['organization' => 'org-a-1427', 'contact' => $a->id]), $html);
        $this->assertStringContainsString(route('organization.admin.crm.contacts.show', ['organization' => 'org-b-1427', 'contact' => $b->id]), $html);
        $this->assertStringContainsString('data-crm-admin-total="2"', $html);
        // Le plus recemment contacte d'abord ; jamais contacte en dernier.
        $this->assertMatchesRegularExpression('/Zorglub Amaranthe.*Quixotic Bellwether/s', $html);
    }

    public function test_the_contacts_list_filters_by_search_organization_status_due_idle_and_reachability(): void
    {
        $actions = app(CrmNextActionService::class);
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();
        $a1 = $this->contact($this->orgA, 'Zorglub', 'Amaranthe', ['status_id' => $client->id, 'last_interaction_at' => Carbon::parse('2026-09-08 09:00:00')]);
        $a2 = $this->contact($this->orgA, 'Yolande', 'Brimborion', ['phone' => '+33 6 11 22 33 44', 'phone_normalized' => '+33611223344']);
        $b1 = $this->contact($this->orgB, 'Quixotic', 'Bellwether');
        $actions->plan($a2, 'call', Carbon::parse('2026-09-09'), null, null, $this->adminA);
        $actions->plan($b1, 'quote', Carbon::parse('2026-09-01'), null, null, $this->adminB);
        app(CrmContactPolicyService::class)->block($b1, 'contact_request', null, $this->adminB);
        // Un Contact du samedi 12 : dans « cette semaine », jamais dans « aujourd'hui ».
        $b2 = $this->contact($this->orgB, 'Wilfrid', 'Dandinet', ['last_interaction_at' => Carbon::parse('2026-09-08 09:00:00')]);
        $actions->plan($b2, 'meeting', Carbon::parse('2026-09-12'), null, null, $this->adminB);

        $ids = fn (array $q) => $this->contactIds($this->actingAs($this->superAdmin)->get(route('admin.crm.overview', $q))->assertOk()->getContent());

        $this->assertSame([$a1->id], $ids(['search' => 'zorglub']));
        // Recherche par CHIFFRES sur phone_normalized (+33611223344) : « 6 11 22 33 » trouve, sans deviner de pays.
        $this->assertSame([$a2->id], $ids(['search' => '6 11 22 33']));
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $ids(['organization' => $this->orgA->id]));
        $this->assertEqualsCanonicalizing([$b1->id, $b2->id], $ids(['organization' => $this->orgB->id]));
        $this->assertSame([$a1->id], $ids(['status' => 'Client']));
        $this->assertSame([$a2->id], $ids(['due' => 'today']));
        $this->assertSame([$b1->id], $ids(['due' => 'overdue']));
        $this->assertEqualsCanonicalizing([$a2->id, $b2->id], $ids(['due' => 'week']));
        $this->assertEqualsCanonicalizing([$a2->id, $b1->id], $ids(['idle' => 1]));
        $this->assertSame([$b1->id], $ids(['contactable' => 'no']));
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id, $b2->id], $ids(['contactable' => 'yes']));
        $this->assertSame([], $ids(['search' => 'introuvable']));
    }

    public function test_the_today_page_shows_the_due_contacts_of_every_organization_with_their_names(): void
    {
        $actions = app(CrmNextActionService::class);
        $a = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $b = $this->contact($this->orgB, 'Quixotic', 'Bellwether');
        $c = $this->contact($this->orgB, 'Wilfrid', 'Dandinet');
        $actions->plan($a, 'call', Carbon::parse('2026-09-09'), '14:00', 'Rappel', $this->adminA);
        $actions->plan($b, 'quote', Carbon::parse('2026-09-01'), null, null, $this->adminB);
        $actions->plan($c, 'meeting', Carbon::parse('2026-09-12'), null, null, $this->adminB);
        // Le lundi 14 n'est pas « cette semaine » : nulle part sur la page.
        $actions->plan($this->contact($this->orgA, 'Ulysse', 'Farfadet'), 'sms', Carbon::parse('2026-09-14'), null, null, $this->adminA);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.crm.overview.today'))->assertOk()->getContent();

        $section = fn (string $k) => (preg_match('/<section data-crm-admin-section="'.$k.'".*?<\/section>/s', $html, $m) ? $m[0] : '');
        $this->assertStringContainsString('Zorglub Amaranthe', $section('today'));
        $this->assertStringContainsString('Alpha Corp', $section('today'));
        $this->assertStringContainsString('Rappel', $section('today'));
        $this->assertStringContainsString('Quixotic Bellwether', $section('overdue'));
        $this->assertStringContainsString('Beta SAS', $section('overdue'));
        $this->assertStringContainsString('Wilfrid Dandinet', $section('week'));
        $this->assertStringNotContainsString('Wilfrid Dandinet', $section('today'));
        $this->assertStringNotContainsString('Zorglub Amaranthe', $section('week'));
        $this->assertStringNotContainsString('Ulysse Farfadet', $html);
    }

    public function test_the_facts_page_shows_the_content_of_every_organization_newest_first_and_capped(): void
    {
        $a = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $b = $this->contact($this->orgB, 'Quixotic', 'Bellwether');
        app(CrmTimelineService::class)->addNote($a, 'Secret commercial : remise de 40 % si signature avant vendredi', $this->adminA, 'call');
        for ($i = 1; $i <= 52; $i++) {
            CrmContactEvent::create([
                'organization_id' => $this->orgB->id,
                'crm_contact_id' => $b->id,
                'type' => 'note',
                'author_user_id' => $this->adminB->id,
                'payload' => ['body' => 'Fait B numero '.$i],
                'occurred_at' => now()->subMinutes($i),
            ]);
        }

        $html = $this->actingAs($this->superAdmin)->get(route('admin.crm.overview.facts'))->assertOk()->getContent();

        // Tout voir : le contenu des notes des deux Organizations, avec le contact et l'auteur.
        $this->assertStringContainsString('Secret commercial : remise de 40 % si signature avant vendredi', $html);
        $this->assertStringContainsString('Zorglub Amaranthe', $html);
        $this->assertStringContainsString('Ada Lovelace', $html);
        $this->assertStringContainsString('Alpha Corp', $html);
        $this->assertStringContainsString('Beta SAS', $html);
        $this->assertStringContainsString('data-crm-count="50"', $html);
        // Le plus recent (la note A, maintenant) d'abord, puis B 1, B 2 … ; B 50+ hors de la fenetre.
        $this->assertMatchesRegularExpression('/Secret commercial.*Fait B numero 1\b.*Fait B numero 2\b/s', $html);
        $this->assertStringNotContainsString('Fait B numero 50', $html);
        $this->assertStringNotContainsString('Fait B numero 52', $html);
    }

    public function test_the_panel_is_read_only(): void
    {
        $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        foreach (['admin.crm.overview', 'admin.crm.overview.today', 'admin.crm.overview.facts'] as $route) {
            $html = $this->actingAs($this->superAdmin)->get(route($route))->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/<form[^>]*method="POST"[^>]*action="[^"]*relations[^"]*"/i', $html, $route);
            $this->assertStringContainsString('data-crm-admin-tabs', $html);
        }
    }
}
