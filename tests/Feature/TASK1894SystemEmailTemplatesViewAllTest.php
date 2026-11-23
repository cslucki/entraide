<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-1894 correctif 1 — `/admin/system-email-templates/view-all` : tous les
 * gabarits globaux d'une langue sur une page (FR par defaut), rendus comme
 * l'apercu, et un bouton d'acces depuis la liste.
 */
class TASK1894SystemEmailTemplatesViewAllTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
    }

    private function gabarit(string $slug, string $locale, array $surcharge = []): SystemEmailTemplate
    {
        return SystemEmailTemplate::query()->create(array_merge([
            'slug' => $slug, 'locale' => $locale, 'organization_id' => null,
            'name' => "Gabarit {$slug} {$locale}",
            'subject' => 'Bonjour {{ recipient_name }}',
            'content_html' => "<p>CORPS_{$slug}_{$locale} pour {{ recipient_name }}</p>",
            'variables' => ['recipient_name'], 'enabled' => true,
        ], $surcharge));
    }

    public function test_fr_par_defaut_tous_les_globaux_rendus_sans_envoi(): void
    {
        Mail::fake();
        $this->gabarit('t1894_a', 'fr');
        $this->gabarit('t1894_b', 'fr', ['enabled' => false]);
        $this->gabarit('t1894_a', 'en');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates.view-all'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-locale="fr"', $html);
        // Les migrations de donnees sement deja des gabarits globaux : le compte
        // attendu est celui de la base, pas un litteral.
        $attendus = SystemEmailTemplate::query()->whereNull('organization_id')->where('locale', 'fr')->count();
        $this->assertGreaterThanOrEqual(2, $attendus);
        $this->assertStringContainsString('data-system-email-view-all-count="'.$attendus.'"', $html);
        // Rendu reel : variables de demonstration interpolees, corps present (dans srcdoc, echappe).
        $this->assertStringContainsString('CORPS_t1894_a_fr', $html);
        $this->assertStringContainsString('CORPS_t1894_b_fr', $html);
        $this->assertStringContainsString('Bonjour Camille Durand', $html);
        $this->assertStringNotContainsString('CORPS_t1894_a_en', $html);
        Mail::assertNothingSent();
    }

    public function test_le_selecteur_en_montre_les_gabarits_anglais(): void
    {
        $this->gabarit('t1894_a', 'fr');
        $this->gabarit('t1894_a', 'en');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates.view-all', ['locale' => 'en']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('CORPS_t1894_a_en', $html);
        $this->assertStringNotContainsString('CORPS_t1894_a_fr', $html);
    }

    public function test_une_langue_forgee_retombe_sur_le_francais(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates.view-all', ['locale' => 'xx']))
            ->assertOk()->assertSee('data-locale="fr"', false);
    }

    public function test_les_surcharges_d_organisation_n_y_figurent_pas(): void
    {
        $org = Organization::factory()->create();
        $this->gabarit('t1894_a', 'fr');
        $this->gabarit('t1894_a', 'fr', ['organization_id' => $org->id, 'content_html' => '<p>SURCHARGE_ORG</p>']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates.view-all'))
            ->assertOk()->assertDontSee('SURCHARGE_ORG');
    }

    public function test_reserve_au_super_admin(): void
    {
        $membre = User::factory()->create(['is_admin' => false]);

        $this->actingAs($membre)->get(route('admin.system-email-templates.view-all'))->assertForbidden();
    }

    public function test_la_liste_porte_le_bouton_tout_voir(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates'))
            ->assertOk()
            ->assertSee('data-system-email-view-all-link', false)
            ->assertSee(route('admin.system-email-templates.view-all', ['locale' => 'fr']), false);
    }
}
