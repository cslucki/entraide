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

    // =====================================================================
    // D. La page 404 parle la langue de l'utilisateur
    // =====================================================================

    /**
     * ## Le mecanisme, et le defaut exact
     *
     * BouclePro resout la locale dans `SetLocale`, par une CASCADE :
     * session (choix explicite) -> `preferred_locale` de l'utilisateur ->
     * locale de l'Organization -> `Accept-Language` -> `config('app.locale')`.
     * `Accept-Language` en fait partie, mais en QUATRIEME position : ce n'est
     * pas le mecanisme principal, et on ne le teste pas comme tel.
     *
     * Mesure avant correctif, sur `https://test.laravel` :
     *
     * | 404 | langue rendue |
     * |---|---|
     * | URI NON ROUTEE, `Accept-Language: en` | **fr** |
     * | URI NON ROUTEE, session `locale=en` (prouvee active ailleurs) | **fr** |
     * | cross-tenant (`abort()` depuis un controleur) | en — deja correct |
     *
     * La cause n'etait pas dans la vue : sur une URI non routee, le routeur
     * leve la 404 AVANT tout middleware de groupe. `StartSession` n'avait
     * jamais tourne — `$request->session()->isStarted()` valait `false` —,
     * donc `SetLocale` non plus, et aucun choix ne pouvait etre lu.
     *
     * Le correctif n'ajoute AUCUNE detection de langue : il ramene simplement
     * cette 404 dans le groupe `web`, ou le mecanisme existant s'applique.
     */
    public function test_d_an_unrouted_404_speaks_french_to_a_french_browser(): void
    {
        $reponse = $this->get('/cette-page-nexiste-pas-1603', ['Accept-Language' => 'fr-FR,fr;q=0.9']);

        $reponse->assertNotFound();
        $reponse->assertSee('lang="fr"', false);
        $reponse->assertSee(__('errors.404_message', [], 'fr'));
        $reponse->assertDontSee(__('errors.404_message', [], 'en'));
    }

    /**
     * Le cas « AUCUN en-tete `Accept-Language` » n'est pas testable ici, et le
     * dire vaut mieux que de croire le mesurer.
     *
     * `Symfony\Component\HttpFoundation\Request::create()` — qu'emploie le
     * client de test de Laravel — injecte d'office `Accept-Language: en-us`.
     * Un `$this->get(...)` sans en-tete n'est donc PAS une requete sans
     * en-tete : le harnais en pose un. Une premiere version de ce test asserait
     * « par defaut, francais » et rougissait pour cette seule raison — le
     * produit allait bien.
     *
     * Ce cas a ete mesure la ou il existe vraiment, en HTTP reel sur
     * `https://test.laravel` : `curl` sans `Accept-Language` sur une URI non
     * routee rend `lang="fr"`, la valeur de `config('app.locale')`.
     *
     * Ce test fige donc ce qui EST verifiable ici : la cascade retombe sur le
     * defaut de l'application quand rien d'autre ne s'applique.
     */
    public function test_d_the_application_default_is_french(): void
    {
        $this->assertSame('fr', config('app.locale'),
            'la premisse a change : le defaut applicatif n\'est plus le francais');
    }

    /** Le navigateur est entendu — c'est le dernier maillon avant le defaut. */
    public function test_d_an_unrouted_404_follows_the_browser_language(): void
    {
        $reponse = $this->get('/cette-page-nexiste-pas-1603', ['Accept-Language' => 'en-US,en;q=0.9']);

        $reponse->assertNotFound();
        $reponse->assertSee('lang="en"', false);
        $reponse->assertSee(__('errors.404_message', [], 'en'));
        $reponse->assertDontSee(__('errors.404_message', [], 'fr'));
    }

    /**
     * Un choix EXPLICITE prime sur le navigateur — le `Accept-Language`
     * contraire est pose exprès.
     *
     * La preference est portee ici par `preferred_locale` de l'utilisateur,
     * deuxieme maillon de la cascade, et non par la session, premier maillon :
     * le harnais ne peut pas representer le cas de la session sur une URI NON
     * ROUTEE. `withSession()` pose la donnee dans le store de l'application,
     * mais pas de COOKIE sur la requete ; or sans `StartSession` — qui ne
     * tourne justement pas ici — `SetLocale` n'a aucune session a lire. Un
     * navigateur reel, lui, envoie bien son cookie.
     *
     * Ce cas a donc ete mesure la ou il existe, en HTTP reel sur
     * `https://test.laravel` : apres `POST /locale/en`, une URI non routee rend
     * `lang="en"`, y compris avec `Accept-Language: fr-FR` — le choix prime.
     * Et le 404 cross-tenant, lui, est bien teste avec session ci-dessous.
     *
     * Ce que ce test fige est donc le point essentiel et verifiable ici : sur
     * une URI non routee, une preference EXPLICITE l'emporte sur le navigateur.
     */
    public function test_d_an_explicit_preference_wins_over_the_browser(): void
    {
        $this->otherMember->forceFill(['preferred_locale' => 'en'])->save();

        $reponse = $this->actingAs($this->otherMember)
            ->get('/cette-page-nexiste-pas-1603', ['Accept-Language' => 'fr-FR,fr;q=0.9']);

        $reponse->assertNotFound();
        $reponse->assertSee('lang="en"', false);
        $reponse->assertSee(__('errors.404_message', [], 'en'));
        $reponse->assertDontSee(__('errors.404_message', [], 'fr'));
    }

    /** Le 404 tenant, lui, etait deja correct — on le fige. */
    public function test_d_a_cross_tenant_404_also_speaks_the_chosen_language(): void
    {
        $reponse = $this->withSession(['locale' => 'en'])
            ->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]));

        $reponse->assertNotFound();
        $reponse->assertSee('lang="en"', false);
        $reponse->assertSee(__('errors.404_message', [], 'en'));
    }

    /** Dans les deux langues, toujours aucun moteur de recherche. */
    public function test_d_neither_language_brings_the_search_engine_back(): void
    {
        foreach ([null, 'en'] as $choix) {
            $requete = $choix === null ? $this : $this->withSession(['locale' => $choix]);
            $html = $requete->get('/cette-page-nexiste-pas-1603')->getContent();

            $this->assertAucunMoteurDeRecherche($html, 'langue '.($choix ?? 'par defaut'));
        }
    }

    /**
     * Aucune phrase utilisateur codee en dur dans la vue.
     *
     * Les DEUX seules chaines litterales qui subsistent sont nommees ici plutot
     * que passees sous silence :
     * - `404`, un nombre, qui ne se traduit pas ;
     * - `alt="BouclePro"`, un NOM DE MARQUE, qui ne se traduit pas davantage.
     *
     * Tout le reste — titre, message, lien de retour — passe par `__()`.
     */
    public function test_d_no_user_facing_sentence_is_hardcoded_in_the_view(): void
    {
        $vue = file_get_contents(resource_path('views/errors/404.blade.php'));

        // On retire les commentaires Blade : ils ne sont jamais rendus.
        $vue = preg_replace('/\{\{--.*?--\}\}/s', '', $vue);

        foreach (['fr', 'en'] as $langue) {
            foreach (['404_title', '404_message', '404_back_home'] as $cle) {
                $this->assertStringNotContainsString(
                    __('errors.'.$cle, [], $langue),
                    $vue,
                    "la chaine [{$cle}] en [{$langue}] est codee en dur dans la vue au lieu de passer par __()"
                );
            }
        }

        foreach (['errors.404_title', 'errors.404_message', 'errors.404_back_home'] as $cle) {
            $this->assertStringContainsString("__('".$cle."')", $vue,
                "la vue n'utilise pas __() pour [{$cle}]");
        }
    }

    // =====================================================================
    // E. Le repli n'a change que la langue — rien d'autre
    // =====================================================================

    /**
     * La route de repli n'accepte pas que GET.
     *
     * `Route::fallback()` du framework n'enregistre que GET, et
     * `checkForAlternateVerbs` prend les routes de repli en compte : un POST
     * vers une URI inexistante serait alors passe de **404 a 405**, parce qu'un
     * pendant GET existerait desormais. Mesure faite, regression evitee.
     */
    public function test_e_every_verb_still_gets_a_404_on_an_unknown_uri(): void
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verbe) {
            $this->{$verbe}('/uri-inexistante-1603')->assertNotFound();
        }
    }

    /** Un client qui demande du JSON en recoit toujours. */
    public function test_e_a_json_client_still_gets_json(): void
    {
        $this->getJson('/uri-inexistante-1603')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json');
    }
}
