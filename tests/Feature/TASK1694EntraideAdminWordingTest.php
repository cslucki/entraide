<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1694, bug B — dans l'administration, « Transactions » devient
 * « Entraide ».
 *
 * Demande de Cyril : « Sur /admin/transactions, on remplace Transactions par
 * Entraide. Idem pour l'admin org. »
 *
 * ## Deux administrations, deux endroits
 *
 * Le SuperAdmin plateforme nomme son item EN DUR dans son gabarit ; l'admin
 * d'Organization passe par `navigation.org_admin_transactions`. Les deux
 * portaient le mot : n'en changer qu'un aurait laissé les deux écrans se
 * contredire.
 *
 * Le GROUPE qui contient l'item s'appelait « Échanges » : il prend le même
 * terme que ce qu'il contient.
 */
class TASK1694EntraideAdminWordingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694b',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        app()->setLocale('fr');

        Http::preventStrayRequests();
        Http::fake();
    }

    /** L'écran plateforme porte le nouveau titre. */
    public function test_the_platform_screen_says_entraide(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Entraide', $html);
        $this->assertStringNotContainsString('<title>Transactions', $html);
    }

    /** Le menu du SuperAdmin aussi — item ET groupe. */
    public function test_the_platform_menu_says_entraide(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        // Viser le lien LUI-MEME : le libelle est rendu entre l'icone et la
        // fermeture de la balise `<a>`, jamais colle entre deux chevrons. Ma
        // premiere assertion cherchait `>Transactions<` — un balisage qui
        // n'existe pas ici, donc elle restait verte avec l'ancien libelle.
        $lien = route('admin.transactions');

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote($lien, '#').'".{0,600}?Entraide\s*</a>#s',
            $html,
            'l\'item de menu doit porter le nouveau terme',
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<a href="'.preg_quote($lien, '#').'".{0,600}?Transactions\s*</a>#s',
            $html,
        );
        $this->assertStringNotContainsString('tracking-wider">Échanges<', $html,
            'le groupe qui contient l\'item porte le meme terme');
    }

    /** Et l'administration de l'Organization dit la même chose. */
    public function test_the_organization_admin_says_entraide(): void
    {
        $this->assertSame('Entraide', __('navigation.org_admin_transactions'));
        $this->assertSame('Entraide', __('navigation.org_admin_section_exchanges'));

        $html = $this->actingAs($this->superAdmin)
            ->get(route('organization.admin.transactions', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Entraide', $html);
    }

    /** L'anglais suit avec son propre mot. */
    public function test_english_follows(): void
    {
        app()->setLocale('en');

        $this->assertSame('Mutual aid', __('navigation.org_admin_transactions'));
        $this->assertSame('Mutual aid', __('navigation.org_admin_section_exchanges'));
    }
}
