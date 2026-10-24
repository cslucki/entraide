<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_can_view_email_templates_list()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-templates'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.email-templates.index');
    }

    public function test_non_admin_cannot_view_email_templates_list()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('admin.email-templates'));

        $response->assertStatus(403);
    }

    public function test_admin_can_create_email_template()
    {
        // TASK-1694 bug AA — une creation DESIGNE desormais ses cibles.
        // Un gabarit sans destinataire n'existe plus : la demande 8 de Cyril
        // fait de l'Organization (ou « Global ») une donnee du formulaire.
        $data = [
            'slug' => 'welcome_email',
            'name' => 'Email de bienvenue',
            'organization_ids' => [\App\Http\Controllers\Admin\AdminEmailTemplatesController::CIBLE_GLOBALE],
            'subject' => 'Bienvenue sur Entraide !',
            'content_html' => '<p>Bonjour {{user_name}}, bienvenue !</p>',
            'variables' => ['{{user_name}}', '{{app_name}}'],
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('admin.email-templates.store'), $data);

        $response->assertRedirect(route('admin.email-templates'));
        $this->assertDatabaseHas('email_templates', [
            'slug' => 'welcome_email',
            'name' => 'Email de bienvenue',
        ]);
    }

    /**
     * TASK-1694 bug AA — LA REGLE A CHANGE, par decision produit.
     *
     * Ce test exigeait un slug unique GLOBALEMENT. Cyril : « oui je veux un
     * veritable appariement » — `relance` doit exister en FR et en EN sous le
     * meme slug, et pour plusieurs Organizations. L'unicite porte donc
     * desormais sur le TRIPLET (slug, organisation, langue).
     *
     * Le test garde sa question — « peut-on creer deux fois la meme chose ? » —
     * avec la bonne definition de « la meme chose » : meme slug, meme cible,
     * meme langue. Et il garde l'autre moitie : la MEME cible dans une AUTRE
     * langue reste permise, sinon la regle interdirait ce que la demande
     * reclame.
     */
    public function test_a_template_cannot_be_created_twice_for_the_same_target_and_locale()
    {
        $cible = \App\Http\Controllers\Admin\AdminEmailTemplatesController::CIBLE_GLOBALE;

        $data = [
            'slug' => 'existing_slug',
            'name' => 'Test Template',
            'locale' => 'fr',
            'organization_ids' => [$cible],
            'subject' => 'Test Subject',
            'content_html' => '<p>Test</p>',
        ];

        $this->actingAs($this->admin)->post(route('admin.email-templates.store'), $data);

        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.store'), $data)
            ->assertSessionHasErrors('organization_ids');

        $this->assertSame(1, EmailTemplate::where('slug', 'existing_slug')->count());

        // L'autre moitie : la meme cible, dans une AUTRE langue.
        $this->actingAs($this->admin)
            ->post(route('admin.email-templates.store'), array_merge($data, ['locale' => 'en']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, EmailTemplate::where('slug', 'existing_slug')->count());
    }

    public function test_admin_can_view_email_template()
    {
        $template = EmailTemplate::factory()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-templates.show', $template));

        $response->assertStatus(200);
        $response->assertViewHas('emailTemplate', $template);
    }

    public function test_admin_can_edit_email_template()
    {
        $template = EmailTemplate::factory()->create([
            'name' => 'Old Name',
        ]);

        $data = [
            'slug' => $template->slug,
            'name' => 'Updated Name',
            'subject' => $template->subject,
            'content_html' => $template->content_html,
        ];

        $response = $this->actingAs($this->admin)
            ->put(route('admin.email-templates.update', $template), $data);

        $response->assertRedirect(route('admin.email-templates'));
        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_admin_can_delete_email_template()
    {
        $template = EmailTemplate::factory()->create();

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.email-templates.destroy', $template));

        $response->assertRedirect(route('admin.email-templates'));
        $this->assertDatabaseMissing('email_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_admin_can_search_email_templates()
    {
        EmailTemplate::factory()->create(['name' => 'Welcome Email']);
        EmailTemplate::factory()->create(['name' => 'Password Reset']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-templates', ['search' => 'Welcome']));

        $response->assertStatus(200);
        $response->assertViewHas('templates');
        $templates = $response->viewData('templates');
        $this->assertCount(1, $templates);
        $this->assertEquals('Welcome Email', $templates->first()->name);
    }

    public function test_required_fields_validation()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.email-templates.store'), []);

        $response->assertSessionHasErrors(['slug', 'name', 'subject', 'content_html']);
    }
}
