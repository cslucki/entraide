<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1603 — une page 404 ne propose pas de moteur de recherche.
 *
 * ## Pourquoi, et d'ou vient la decision
 *
 * Le parcours reel de Roger : `/mycelium` -> `/loops` -> login -> 404 tenant.
 * La page 404 affichait encore un champ « Que cherchez-vous ? » ; l'utilisateur
 * a naturellement tente une recherche, et s'est heurte a une **seconde**
 * frustration. Une page d'erreur qui invite a chercher promet une issue qu'elle
 * n'a pas.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * Sessions HTTP reelles sur `https://test.laravel`, base `bouclepro` :
 *
 * | cas | statut | `role="search"` | `action=".../search"` | `name="q"` |
 * |---|---|---|---|---|
 * | URL inexistante, invite | 404 | 1 | 1 | 1 |
 * | 404 cross-tenant (membre `launchpals` -> `/org/main/loops`) | 404 | 1 | 1 | 1 |
 *
 * ## La source
 *
 * Le formulaire est **code en dur dans `resources/views/errors/404.blade.php`**
 * (lignes 51-65 avant correctif). Ce n'est pas un composant partage : la vue est
 * un document HTML autonome, sans `@extends` ni `@include`. Le moteur de
 * recherche NORMAL du produit vit dans `resources/views/layouts/navigation.blade.php`,
 * que cette vue n'inclut pas. Le retirer du 404 ne peut donc pas le retirer
 * ailleurs — et la section C en fait une garde.
 *
 * ## Ce que la TASK ne touche pas
 *
 * Le statut reste 404. Le branding, la navigation restante et la resolution
 * d'Organization du lien de retour sont inchanges.
 */
class TASK1603NoSearchEngineOn404Test extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrg;

    private Organization $otherOrg;

    private User $otherMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultOrg = Organization::factory()->create([
            'slug' => 'org-1603-defaut',
            'name' => 'Org 1603 Defaut',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now()->subYear(),
        ]);

        $this->otherOrg = Organization::factory()->create([
            'slug' => 'org-1603-autre',
            'name' => 'Org 1603 Autre',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now(),
        ]);

        $this->otherMember = User::factory()->complete()->create([
            'organization_id' => $this->otherOrg->id,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Le rendu 404 ne doit porter aucune interface de recherche, sous aucune de
     * ses formes : ni le role ARIA, ni un formulaire vise sur la recherche, ni
     * le champ de requete, ni son libelle.
     */
    private function assertAucunMoteurDeRecherche(string $html, string $contexte): void
    {
        $this->assertStringNotContainsString('role="search"', $html,
            "{$contexte} : la page 404 porte encore un role ARIA de recherche");

        $this->assertDoesNotMatchRegularExpression('#action="[^"]*/search"#', $html,
            "{$contexte} : la page 404 porte encore un formulaire vise sur la recherche");

        $this->assertStringNotContainsString('name="q"', $html,
            "{$contexte} : la page 404 porte encore un champ de requete");

        // Les libelles sont ECRITS EN DUR, dans les deux langues, et non lus
        // depuis `errors.404_search` : cette cle a ete retiree avec le moteur.
        // La lire ici rendrait l'assertion degeneree — une cle absente renvoie
        // son propre nom, que la page ne contiendra jamais. Ecrire le texte
        // garde la mesure vraie et attrape une reintroduction dans l'une ou
        // l'autre langue.
        foreach (['Que cherchez-vous ?', 'What are you looking for?'] as $libelle) {
            $this->assertStringNotContainsString($libelle, $html,
                "{$contexte} : le libelle du moteur de recherche est encore rendu [{$libelle}]");
        }

        $this->assertStringNotContainsString('errors.404_search', $html,
            "{$contexte} : une cle de traduction non resolue fuit dans la page");
    }

    // =====================================================================
    // A. Le 404 public classique
    // =====================================================================

    public function test_a_public_404_carries_no_search_engine(): void
    {
        $reponse = $this->get('/cette-page-nexiste-pas-1603');

        $reponse->assertNotFound();

        $this->assertAucunMoteurDeRecherche($reponse->getContent(), '404 public');
    }

    /** Le statut ne change pas : c'est bien un 404, pas une redirection deguisee. */
    public function test_a_public_404_keeps_its_status(): void
    {
        $this->get('/cette-page-nexiste-pas-1603')->assertStatus(404);
    }

    // =====================================================================
    // B. Le 404 tenant — celui du parcours de Roger
    // =====================================================================

    public function test_b_cross_tenant_404_carries_no_search_engine(): void
    {
        $reponse = $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]));

        $reponse->assertNotFound();

        $this->assertAucunMoteurDeRecherche($reponse->getContent(), '404 cross-tenant');
    }

    // =====================================================================
    // C. Le moteur de recherche NORMAL du produit n'est pas touche
    // =====================================================================

    /**
     * La garde qui empeche la correction de deborder. Si l'on retirait le
     * formulaire du gabarit de navigation au lieu de la seule vue 404, ce test
     * rougirait.
     */
    public function test_c_the_product_search_engine_is_untouched(): void
    {
        // On lit la SOURCE Blade, donc on l'ancre sur ce qu'elle ecrit
        // reellement — `action="{{ route('search') }}"` — et non sur la forme
        // rendue. Premiere version de ce test : une regex `action=".../search"`
        // taillee pour du HTML, qui ne pouvait pas correspondre.
        $gabarit = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

        $this->assertStringContainsString('name="q"', $gabarit,
            'le moteur de recherche normal du produit a perdu son champ de requete');

        $this->assertStringContainsString("route('search')", $gabarit,
            'le moteur de recherche normal du produit ne vise plus la recherche');
    }

    /** Et la page 404 garde ce qui doit rester : son lien de retour. */
    public function test_c_the_404_keeps_its_way_out(): void
    {
        $reponse = $this->get('/cette-page-nexiste-pas-1603');

        // `assertSee` echappe comme Blade : « Retour a l'accueil » est rendu
        // `l&#039;accueil`, qu'une comparaison brute manque.
        $reponse->assertSee(__('errors.404_back_home'));
        $reponse->assertSee('404');
    }
}
