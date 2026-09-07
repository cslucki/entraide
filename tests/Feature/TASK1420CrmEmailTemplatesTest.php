<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmEmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-1420 — CRM-7a, les modeles d'email de l'Organization.
 *
 * - l'OrgAdmin ne voit et ne touche que les modeles de SON Organization :
 *   ni les globaux (organization_id NULL), ni ceux d'ailleurs (404) ;
 * - la creation force organization_id depuis la route et pose un slug
 *   technique namespace, stable ensuite ;
 * - la preview interpole avec un Contact d'exemple et n'ecrit RIEN.
 */
class TASK1420CrmEmailTemplatesTest extends TestCase
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

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1420', 'name' => 'Alpha Conseil', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1420', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function index(Organization $organization): string
    {
        return route('organization.admin.crm.templates', ['organization' => $organization->slug]);
    }

    private function template(Organization $organization, string $name = 'Suite entretien'): EmailTemplate
    {
        return app(CrmEmailTemplateService::class)->create($organization, $name, 'Suite à notre échange, {{ first_name }}', '<p>Bonjour {{ full_name }} ({{ company }}), merci pour votre temps. — {{ organization }}</p>');
    }

    // ── 1. Acces + visibilite tenant ────────────────────────────────────────

    public function test_only_the_organization_templates_are_listed_never_global_nor_foreign_ones(): void
    {
        $mine = $this->template($this->orgA, 'Relance devis');
        $foreign = $this->template($this->orgB, 'Modele de B');
        $global = EmailTemplate::create(['slug' => 'global-plateforme', 'name' => 'Global plateforme', 'subject' => 'x', 'content_html' => '<p>x</p>', 'organization_id' => null]);

        $this->actingAs($this->memberA)->get($this->index($this->orgA))->assertForbidden();
        $this->actingAs($this->adminB)->get($this->index($this->orgA))->assertForbidden();

        $this->actingAs($this->adminA)->get($this->index($this->orgA))->assertOk()
            ->assertSee('Relance devis')
            ->assertSee('data-crm-template="'.$mine->id.'"', false)
            ->assertDontSee('Modele de B')
            ->assertDontSee('Global plateforme')
            ->assertDontSee($foreign->id)
            ->assertDontSee($global->id);

        // « modeles » n'est jamais pris pour un id de Contact.
        $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => 'modeles']))->assertOk();
    }

    public function test_editing_or_previewing_a_foreign_or_global_template_is_a_404(): void
    {
        $foreign = $this->template($this->orgB);
        $global = EmailTemplate::create(['slug' => 'global-2', 'name' => 'Global', 'subject' => 'x', 'content_html' => '<p>x</p>', 'organization_id' => null]);

        foreach ([$foreign, $global] as $t) {
            $this->actingAs($this->adminA)->get(route('organization.admin.crm.templates.edit', ['organization' => $this->orgA->slug, 'template' => $t->id]))->assertNotFound();
            $this->actingAs($this->adminA)->get(route('organization.admin.crm.templates.preview', ['organization' => $this->orgA->slug, 'template' => $t->id]))->assertNotFound();
            $this->actingAs($this->adminA)->put(route('organization.admin.crm.templates.update', ['organization' => $this->orgA->slug, 'template' => $t->id]), ['name' => 'Pirate', 'subject' => 'x', 'content_html' => '<p>x</p>'])->assertNotFound();
        }

        $this->assertSame('Suite entretien', $foreign->fresh()->name);
        $this->assertSame('Global', $global->fresh()->name);
    }

    public function test_the_rail_links_to_the_templates_page(): void
    {
        $this->actingAs($this->adminA)
            ->get(route('organization.admin.crm.contacts', ['organization' => $this->orgA->slug]))
            ->assertOk()->assertSee('href="'.$this->index($this->orgA).'"', false);
    }

    // ── 2. Creation : organization forcee, slug namespace et stable ─────────

    public function test_creation_forces_the_organization_and_namespaces_a_stable_slug(): void
    {
        // Un contexte tenant ETRANGER est lie : la creation ne doit pas s'en servir.
        app()->instance('current_organization', $this->orgB);

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.templates.store', ['organization' => $this->orgA->slug]), ['name' => 'Suite à notre entretien téléphonique', 'subject' => 'Suite à notre échange', 'content_html' => '<p>Bonjour {{ first_name }}</p>'])
            ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('success', __('crm.templates.flash_created'));

        $created = EmailTemplate::where('name', 'Suite à notre entretien téléphonique')->firstOrFail();
        $this->assertSame($this->orgA->id, $created->organization_id, 'l Organization vient de la route, pas du contexte');
        $this->assertSame('org-a-1420-suite-a-notre-entretien-telephonique', $created->slug);
        $this->assertSame(CrmEmailTemplateService::CONTACT_VARIABLES, $created->variables);

        // Meme nom : slug suffixe, jamais de collision globale.
        $again = app(CrmEmailTemplateService::class)->create($this->orgA, 'Suite à notre entretien téléphonique', 'x', '<p>x</p>');
        $this->assertSame('org-a-1420-suite-a-notre-entretien-telephonique-2', $again->slug);

        // Renommer ne recalcule pas le slug.
        $this->actingAs($this->adminA)
            ->put(route('organization.admin.crm.templates.update', ['organization' => $this->orgA->slug, 'template' => $created->id]), ['name' => 'Nouveau nom', 'subject' => 'Nouvel objet', 'content_html' => '<p>Nouveau corps</p>'])
            ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('success', __('crm.templates.flash_updated'));
        $fresh = $created->fresh();
        $this->assertSame('Nouveau nom', $fresh->name);
        $this->assertSame('org-a-1420-suite-a-notre-entretien-telephonique', $fresh->slug);
        $this->assertSame($this->orgA->id, $fresh->organization_id);

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.templates.store', ['organization' => $this->orgA->slug]), ['name' => '', 'subject' => '', 'content_html' => ''])
            ->assertSessionHasErrors(['name', 'subject', 'content_html']);
    }

    // ── 3. Preview : interpolation, rien d'ecrit ────────────────────────────

    public function test_preview_interpolates_a_sample_contact_and_writes_nothing(): void
    {
        Mail::fake();
        $template = $this->template($this->orgA);

        $this->actingAs($this->adminA)
            ->get(route('organization.admin.crm.templates.preview', ['organization' => $this->orgA->slug, 'template' => $template->id]))
            ->assertOk()
            ->assertSee('Suite à notre échange, Camille')
            ->assertSee('Bonjour Camille Dupont (ACME), merci pour votre temps. — Alpha Conseil')
            ->assertSee('data-crm-template-preview-notice', false);

        $this->assertSame(0, EmailLog::count());
        Mail::assertNothingSent();
    }

    public function test_the_form_lists_the_contact_variables(): void
    {
        $this->actingAs($this->adminA)->get(route('organization.admin.crm.templates.create', ['organization' => $this->orgA->slug]))
            ->assertOk()->assertSee('data-crm-template-variables', false)->assertSee('{{ company }}')->assertSee('{{ full_name }}')->assertDontSee('{{ city }}');
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_sees_the_other_organization_templates_for_its_own_admin(): void
    {
        $this->template($this->orgB, 'Seulement chez B');

        $this->actingAs($this->adminB)->get($this->index($this->orgB))->assertOk()->assertSee('Seulement chez B');
        $this->actingAs($this->adminA)->get($this->index($this->orgA))->assertOk()->assertDontSee('Seulement chez B')->assertSee('data-crm-templates-empty', false);
    }
}
