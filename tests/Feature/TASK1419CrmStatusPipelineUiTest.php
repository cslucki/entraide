<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1419 — CRM-4b, la gestion du pipeline par l'OrgAdmin.
 *
 * Tout passe par `CrmStatusService` (T1414) : cette tranche prouve la
 * surface — acces, tenant (404 pour un statut d'ailleurs), creation, edition,
 * ordre par boutons, desactivation (jamais le defaut), statut par defaut —
 * et la pastille de couleur dans Relations et sur la fiche.
 */
class TASK1419CrmStatusPipelineUiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private User $memberA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1419', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1419', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function page(Organization $organization): string
    {
        return route('organization.admin.crm.statuses', ['organization' => $organization->slug]);
    }

    private function statusA(string $label): CrmStatus
    {
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgA);

        return CrmStatus::forOrganization($this->orgA)->where('label', $label)->firstOrFail();
    }

    // ── 1. Acces + liste ────────────────────────────────────────────────────

    public function test_the_page_is_admin_only_and_lists_the_pipeline_in_order(): void
    {
        $this->actingAs($this->memberA)->get($this->page($this->orgA))->assertForbidden();
        $this->actingAs($this->adminB)->get($this->page($this->orgA))->assertForbidden();

        $html = $this->actingAs($this->adminA)->get($this->page($this->orgA))->assertOk()
            ->assertSee('data-crm-statuses-table', false)
            ->assertSee('data-crm-status-default-badge', false)
            ->getContent();

        preg_match_all('/data-crm-status-position="(\d+)"/', $html, $m);
        $this->assertSame(['1', '2', '3', '4', '5', '6'], $m[1]);
        $this->assertTrue(strpos($html, 'value="Nouveau"') < strpos($html, 'value="Perdu"'));

        // « statuts » n'est jamais pris pour un id de Contact (ordre des routes).
        $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => 'statuts']))->assertOk();
    }

    public function test_the_rail_links_to_the_statuses_page(): void
    {
        $this->actingAs($this->adminA)
            ->get(route('organization.admin.crm.contacts', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee('href="'.$this->page($this->orgA).'"', false);
    }

    // ── 2. Creer / editer ───────────────────────────────────────────────────

    public function test_creating_a_status_appends_it_and_refuses_a_duplicate_label_or_a_bad_color(): void
    {
        $store = route('organization.admin.crm.statuses.store', ['organization' => $this->orgA->slug]);

        $this->actingAs($this->adminA)->post($store, ['label' => '  Relance  ', 'color' => '#FF8800'])
            ->assertSessionHasNoErrors()->assertRedirect($this->page($this->orgA))->assertSessionHas('success', __('crm.flash_status_created'));

        $created = CrmStatus::forOrganization($this->orgA)->where('label', 'Relance')->firstOrFail();
        $this->assertSame(7, $created->sort_order);
        $this->assertSame('#ff8800', $created->color);
        $this->assertTrue($created->is_active);
        $this->assertFalse($created->is_default);

        $this->actingAs($this->adminA)->post($store, ['label' => 'Sans couleur', 'color' => '#123456', 'no_color' => 1]);
        $this->assertNull(CrmStatus::forOrganization($this->orgA)->where('label', 'Sans couleur')->firstOrFail()->color);

        $this->actingAs($this->adminA)->post($store, ['label' => 'Client'])
            ->assertRedirect()->assertSessionHas('error', __('crm.flash_status_label_taken'));
        $this->assertSame(1, CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->count());

        $this->actingAs($this->adminA)->post($store, ['label' => 'Mauvaise', 'color' => 'rouge'])
            ->assertSessionHasErrors('color');
        $this->assertSame(0, CrmStatus::forOrganization($this->orgB)->count(), 'rien n a fuite vers B');
    }

    public function test_editing_a_status_renames_and_recolors_without_touching_another_organization(): void
    {
        $client = $this->statusA('Client');
        $clientB = app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB)->firstWhere('label', 'Client');

        $this->actingAs($this->adminA)
            ->put(route('organization.admin.crm.statuses.update', ['organization' => $this->orgA->slug, 'status' => $client->id]), ['label' => 'Client signé', 'color' => '#00aa00'])
            ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('success', __('crm.flash_status_updated'));

        $this->assertSame('Client signé', $client->fresh()->label);
        $this->assertSame('#00aa00', $client->fresh()->color);
        $this->assertSame('Client', $clientB->fresh()->label);

        $this->actingAs($this->adminA)
            ->put(route('organization.admin.crm.statuses.update', ['organization' => $this->orgA->slug, 'status' => $clientB->id]), ['label' => 'Pirate'])
            ->assertNotFound();
        $this->assertSame('Client', $clientB->fresh()->label);

        $this->actingAs($this->adminA)
            ->put(route('organization.admin.crm.statuses.update', ['organization' => $this->orgA->slug, 'status' => $client->id]), ['label' => 'Perdu'])
            ->assertSessionHas('error', __('crm.flash_status_label_taken'));
        $this->assertSame('Client signé', $client->fresh()->label);
    }

    // ── 3. Ordre ────────────────────────────────────────────────────────────

    public function test_moving_a_status_swaps_it_with_its_neighbour_and_stops_at_the_edges(): void
    {
        $nouveau = $this->statusA('Nouveau');
        $move = fn (CrmStatus $s, string $dir) => $this->actingAs($this->adminA)->post(route('organization.admin.crm.statuses.move', ['organization' => $this->orgA->slug, 'status' => $s->id]), ['direction' => $dir]);

        $move($nouveau, 'up')->assertRedirect();
        $this->assertSame('Nouveau', CrmStatus::forOrganization($this->orgA)->ordered()->first()->label, 'deja en tete : rien ne bouge');

        $move($nouveau, 'down')->assertRedirect()->assertSessionHas('success', __('crm.flash_status_moved'));
        $labels = CrmStatus::forOrganization($this->orgA)->ordered()->pluck('label')->all();
        $this->assertSame(['À contacter', 'Nouveau', 'Contact en cours', 'Devis envoyé', 'Client', 'Perdu'], $labels);

        $statusB = app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB)->first();
        $this->actingAs($this->adminA)->post(route('organization.admin.crm.statuses.move', ['organization' => $this->orgA->slug, 'status' => $statusB->id]), ['direction' => 'down'])->assertNotFound();
        $this->assertSame(1, $statusB->fresh()->sort_order);
    }

    // ── 4. Desactiver / par defaut ──────────────────────────────────────────

    public function test_deactivation_keeps_contacts_never_touches_the_default_and_reactivation_works(): void
    {
        $perdu = $this->statusA('Perdu');
        $nouveau = $this->statusA('Nouveau');
        $contact = app(CrmContactService::class)->findOrCreate($this->orgA, ['email' => 'perdu@example.com']);
        app(CrmStatusService::class)->changeStatus($contact, $perdu, $this->adminA);
        $toggle = fn (CrmStatus $s) => $this->actingAs($this->adminA)->post(route('organization.admin.crm.statuses.toggle', ['organization' => $this->orgA->slug, 'status' => $s->id]));

        $toggle($perdu)->assertRedirect()->assertSessionHas('success', __('crm.flash_status_deactivated'));
        $this->assertFalse($perdu->fresh()->is_active);
        $this->assertSame($perdu->id, $contact->fresh()->status_id);
        $this->actingAs($this->adminA)->get($this->page($this->orgA))->assertSee('data-crm-status-inactive-badge', false);

        $toggle($nouveau)->assertSessionHas('error', __('crm.flash_status_default_cannot_deactivate'));
        $this->assertTrue($nouveau->fresh()->is_active);

        $this->actingAs($this->adminA)->post(route('organization.admin.crm.statuses.default', ['organization' => $this->orgA->slug, 'status' => $perdu->id]))
            ->assertSessionHas('error', __('crm.flash_status_inactive_cannot_default'));
        $this->assertTrue($nouveau->fresh()->is_default);

        $toggle($perdu)->assertSessionHas('success', __('crm.flash_status_activated'));
        $this->assertTrue($perdu->fresh()->is_active);
    }

    public function test_setting_the_default_keeps_exactly_one_and_the_other_organization_is_untouched(): void
    {
        $client = $this->statusA('Client');
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB);

        $this->actingAs($this->adminA)->post(route('organization.admin.crm.statuses.default', ['organization' => $this->orgA->slug, 'status' => $client->id]))
            ->assertRedirect()->assertSessionHas('success', __('crm.flash_status_default'));

        $this->assertSame([$client->id], CrmStatus::forOrganization($this->orgA)->where('is_default', true)->pluck('id')->all());
        $this->assertSame('Nouveau', CrmStatus::forOrganization($this->orgB)->where('is_default', true)->firstOrFail()->label);

        $contact = app(CrmContactService::class)->findOrCreate($this->orgA, ['email' => 'neuf@example.com']);
        $this->assertSame($client->id, $contact->status_id, 'un nouveau Contact recoit le nouveau defaut');
    }

    // ── 5. La couleur se voit ───────────────────────────────────────────────

    public function test_the_color_shows_as_a_dot_in_the_list_and_on_the_page(): void
    {
        $client = $this->statusA('Client');
        app(CrmStatusService::class)->edit($client, 'Client', '#00aa00');
        $contact = app(CrmContactService::class)->findOrCreate($this->orgA, ['first_name' => 'Colore', 'last_name' => 'Dupont', 'email' => 'colore@example.com']);
        app(CrmStatusService::class)->changeStatus($contact, $client, $this->adminA);
        $grey = app(CrmContactService::class)->findOrCreate($this->orgA, ['first_name' => 'Gris', 'last_name' => 'Dupont', 'email' => 'gris@example.com']);

        $html = $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts', ['organization' => $this->orgA->slug]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-crm-status-color'), 'une pastille pour le seul statut colore');
        $this->assertStringContainsString('background-color: #00aa00', $html);

        $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $contact->id]))
            ->assertOk()->assertSee('data-crm-status-color', false);
        $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $grey->id]))
            ->assertOk()->assertDontSee('data-crm-status-color');
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_sees_the_other_organization_pipeline_for_its_own_admin(): void
    {
        app(CrmStatusService::class)->ensureDefaultPipeline($this->orgB);
        app(CrmStatusService::class)->create($this->orgB, 'Seulement chez B');

        $this->actingAs($this->adminB)->get($this->page($this->orgB))->assertOk()->assertSee('Seulement chez B');
        $this->actingAs($this->adminA)->get($this->page($this->orgA))->assertOk()->assertDontSee('Seulement chez B');
    }
}
