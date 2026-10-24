<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1694, bug A — « Échanges » devient « Entraide ».
 *
 * Décision de Cyril. Le terme est celui de la NAVIGATION et des TITRES : le
 * rail gauche, la page Explorer, la card du tableau de bord, l'accueil public.
 *
 * ## Ce que ce renommage ne touche pas, et c'est délibéré
 *
 * Les phrases où « échange » est un nom commun — « Échanges complétés »,
 * « Échange — gain » dans le grand livre des points, « Échange #:id » comme
 * sujet de conversation. Les réécrire demande un choix de langue que je ne
 * prends pas seul : « Entraides complétées » n'est pas du bon français.
 * La liste est dans le TASK file, pour arbitrage.
 *
 * Et rien du MODÈLE ne bouge : `Transaction`, la table, les routes d'action.
 * Un renommage de vocabulaire n'est pas une migration.
 */
class TASK1694EntraideWordingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694a',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        Http::preventStrayRequests();
        Http::fake();
    }

    private function page(string $url): string
    {
        return $this->actingAs($this->membre)->get($url)->assertOk()->getContent();
    }

    /** Les quatre libellés nommés par Cyril portent le nouveau terme. */
    public function test_the_four_named_labels_say_entraide(): void
    {
        $this->assertSame('Entraide', __('navigation.exchanges'));
        $this->assertSame('Entraide', __('explorer.title'));
        $this->assertSame('Entraide', __('dashboard.exchanges'));
        $this->assertSame('Entraide', __('home.feature_exchanges'));
    }

    /** Le rail gauche le dit, et ne dit plus l'ancien. */
    public function test_the_left_rail_says_entraide(): void
    {
        $html = $this->page(route('organization.dashboard', ['organization' => $this->organization->slug]));

        $this->assertStringContainsString('Entraide', $html);
        $this->assertStringNotContainsString('>Échanges<', $html);
    }

    /** La page Explorer porte le nouveau titre. */
    public function test_the_explorer_page_says_entraide(): void
    {
        $this->assertStringContainsString(
            'Entraide',
            $this->page(route('organization.explorer', ['organization' => $this->organization->slug])),
        );
    }

    /**
     * L'anglais suit, et ne reste pas sur l'ancien terme.
     *
     * « Entraide » est un mot français ; l'équivalent usuel est « Mutual aid ».
     * Garder « Exchanges » ferait nommer deux choses différentes aux deux
     * locales.
     */
    public function test_english_follows_with_its_own_word(): void
    {
        app()->setLocale('en');

        $this->assertSame('Mutual aid', __('navigation.exchanges'));
        $this->assertSame('Mutual aid', __('explorer.title'));
        $this->assertSame('Mutual aid', __('dashboard.exchanges'));
    }

    /**
     * Ce que le renommage NE touche PAS : le modèle.
     *
     * Les routes d'action gardent leur nom et leur adresse. Un renommage de
     * vocabulaire qui déplacerait des routes casserait des liens enregistrés
     * sans rien apporter au produit.
     */
    public function test_the_model_and_its_routes_are_untouched(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('organization.transactions.approve'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('organization.transactions.complete'));
        $this->assertTrue(class_exists(\App\Models\Transaction::class));
    }
}
