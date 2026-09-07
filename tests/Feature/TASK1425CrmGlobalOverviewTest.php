<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
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
 * TASK-1425 — CRM-15 : la vision globale du SuperAdmin, en agregats.
 *
 * - reservee au SuperAdmin (`is_admin`) : un OrgAdmin est refuse ;
 * - une ligne par Organization, chaque compteur mesure sur SON tenant ;
 * - aucun contenu : ni corps de note, ni objet d'email, ni nom de Contact ;
 * - un lien explicite vers le cockpit « Aujourd'hui » de chaque Organization.
 */
class TASK1425CrmGlobalOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Organization $orgEmpty;

    private User $adminA;

    private User $adminB;

    private User $superAdmin;

    private CrmContactService $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-09 10:00:00'));

        $this->orgA = Organization::factory()->create(['name' => 'Alpha Corp', 'slug' => 'org-a-1425', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['name' => 'Beta SAS', 'slug' => 'org-b-1425', 'is_active' => true, 'locale' => 'fr']);
        $this->orgEmpty = Organization::factory()->create(['name' => 'Gamma Vide', 'slug' => 'org-c-1425', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);

        $this->contacts = app(CrmContactService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    private function url(): string
    {
        return route('admin.crm.overview');
    }

    private function contact(Organization $org, string $first, string $last, array $columns = []): CrmContact
    {
        $contact = $this->contacts->findOrCreate($org, ['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first.'.'.$last).'@example.com']);
        if ($columns !== []) {
            $contact->forceFill($columns)->save();
        }

        return $contact->fresh();
    }

    /** La ligne <tr> d'une Organization. */
    private function row(string $html, string $slug): string
    {
        preg_match('/<tr data-crm-org="'.$slug.'".*?<\/tr>/s', $html, $m);
        $this->assertNotEmpty($m, "ligne {$slug} absente");

        return $m[0];
    }

    public function test_only_a_platform_admin_can_open_the_global_overview(): void
    {
        $this->actingAs($this->adminA)->get($this->url())->assertForbidden();
        $this->actingAs($this->adminB)->get($this->url())->assertForbidden();
        $this->actingAs($this->superAdmin)->get($this->url())->assertOk()->assertSee(__('admin.crm_overview_title'));
        $this->assertSame('/admin/relations', parse_url($this->url(), PHP_URL_PATH));
    }

    public function test_each_organization_row_counts_its_own_tenant_only(): void
    {
        $actions = app(CrmNextActionService::class);
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgA);

        $a1 = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $a2 = $this->contact($this->orgA, 'Yolande', 'Brimborion', ['last_interaction_at' => Carbon::parse('2026-09-08 09:00:00')]);
        $a3 = $this->contact($this->orgA, 'Xavier', 'Cassoulet');
        $actions->plan($a1, 'call', Carbon::parse('2026-09-09'), null, null, $this->adminA);
        $actions->plan($a2, 'quote', Carbon::parse('2026-09-01'), null, null, $this->adminA);
        app(CrmContactPolicyService::class)->block($a3, 'contact_request', null, $this->adminA);

        $b1 = $this->contact($this->orgB, 'Quixotic', 'Bellwether');
        $actions->plan($b1, 'sms', Carbon::parse('2026-09-02'), null, null, $this->adminB);

        $html = $this->actingAs($this->superAdmin)->get($this->url())->assertOk()->getContent();

        $rowA = $this->row($html, 'org-a-1425');
        // 3 contacts, 2 joignables, 1 bloque, 1 aujourd'hui, 1 en retard ; idle = a1 seulement
        // (a2 contactee hier, a3 bloquee ne compte pas comme « a reactiver »).
        $this->assertStringContainsString('data-crm-contacts="3" data-crm-contactable="2" data-crm-blocked="1" data-crm-today="1" data-crm-overdue="1" data-crm-idle="1"', $rowA);
        // Repartition par statut : les trois sont « Nouveau » (statut par defaut).
        $this->assertStringContainsString('data-crm-status-label="Nouveau" data-crm-status-count="3"', $rowA);
        $this->assertStringContainsString('/org/org-a-1425/admin/relations', $rowA);

        $rowB = $this->row($html, 'org-b-1425');
        $this->assertStringContainsString('data-crm-contacts="1" data-crm-contactable="1" data-crm-blocked="0" data-crm-today="0" data-crm-overdue="1" data-crm-idle="1"', $rowB);

        $rowEmpty = $this->row($html, 'org-c-1425');
        $this->assertStringContainsString('data-crm-contacts="0" data-crm-contactable="0" data-crm-blocked="0" data-crm-today="0" data-crm-overdue="0" data-crm-idle="0"', $rowEmpty);

        // Totaux plateforme.
        $this->assertStringContainsString('data-crm-total="contacts" data-crm-value="4"', $html);
        $this->assertStringContainsString('data-crm-total="overdue" data-crm-value="2"', $html);
        $this->assertStringContainsString('data-crm-total="blocked" data-crm-value="1"', $html);
    }

    /**
     * TASK-1427 — decision Cyril (07/09 20h30) : « le SuperAdmin doit pouvoir
     * tout voir ». La vue d'ensemble montre donc les CONTACTS (noms, emails)
     * de toutes les Organizations sous les agregats ; le CONTENU des faits
     * (corps de note, objet d'email) vit dans l'onglet « Derniers faits »,
     * pas dans les agregats. Ce test remplace explicitement l'ancien
     * « jamais affiche » (option a de Q37, remplacee par la decision Cyril).
     */
    public function test_recent_facts_are_counted_and_dated_and_the_contacts_are_listed_below_the_aggregates(): void
    {
        $contact = $this->contact($this->orgA, 'Zorglub', 'Amaranthe');
        $timeline = app(CrmTimelineService::class);
        $timeline->addNote($contact, 'Secret commercial : remise de 40 % si signature avant vendredi', $this->adminA, 'call');
        CrmContactEvent::create([
            'organization_id' => $this->orgA->id,
            'crm_contact_id' => $contact->id,
            'type' => 'email_sent',
            'author_user_id' => $this->adminA->id,
            'payload' => ['subject' => 'Proposition confidentielle Amaranthe', 'to' => 'zorglub.amaranthe@example.com', 'template_name' => 'Relance'],
            'occurred_at' => now()->subDays(10),
        ]);

        $html = $this->actingAs($this->superAdmin)->get($this->url())->assertOk()->getContent();
        $rowA = $this->row($html, 'org-a-1425');

        // 1 fait recent (la note, aujourd'hui) ; l'email d'il y a 10 jours n'est pas « recent ».
        $this->assertStringContainsString('data-crm-recent="1"', $rowA);
        $this->assertStringContainsString(now()->format('d/m/Y H:i'), $rowA);
        // Les agregats ne portent aucun contenu ; le contenu des faits est dans l'onglet dedie.
        foreach (['Secret commercial', 'remise de 40', 'Proposition confidentielle', 'Relance'] as $content) {
            $this->assertStringNotContainsString($content, $html, "contenu de fait sur la vue d'ensemble : {$content}");
        }
        // Tout voir : le Contact est liste sous les agregats, avec son email et le lien vers sa fiche.
        $this->assertStringContainsString('data-crm-admin-contact="'.$contact->id.'"', $html);
        $this->assertStringContainsString('Zorglub Amaranthe', $html);
        $this->assertStringContainsString('zorglub.amaranthe@example.com', $html);
        $this->assertStringContainsString(route('organization.admin.crm.contacts.show', ['organization' => 'org-a-1425', 'contact' => $contact->id]), $html);
    }

    public function test_the_overview_is_read_only(): void
    {
        $html = $this->actingAs($this->superAdmin)->get($this->url())->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<form[^>]*action="[^"]*\/relations[^"]*"/', $html);
    }
}
