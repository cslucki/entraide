<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1608, addendum MASTER — le lien « Logigramme » / « Flowchart » du pied.
 *
 * ## Ce qui est mesure, et ou
 *
 * Trois pieds portent des liens d'Organization, et ils sont distincts :
 *
 * | gabarit | Mycelium ? | logigramme pose |
 * |---|---|---|
 * | `partials/footer` (layout applicatif) | oui, en bas a gauche | juste a sa droite |
 * | `organization/hero-v2` (landing `main`) | oui, `.foot-credit` | juste a sa droite |
 * | `organization/artscilab-hero` (landing `launchpals`) | **non** | 1re place de `.foot-right` |
 *
 * L'ecart du 3e gabarit est rapporte a MASTER : lui ajouter un lien Mycelium
 * pour satisfaire « immediatement a droite de Mycelium » reviendrait a defaire
 * un choix de TASK-1604 sous couvert d'addendum.
 *
 * ## La borne heritee de TASK-1602 / 1604
 *
 * MASTER interdit toute route `/flowchart` globale. Le lien ne peut donc pas
 * avoir de repli hors Organization : il n'apparait que quand l'URL EXPRIME un
 * prefixe `/org/{slug}`. Le declencheur est ce que l'URL dit, jamais un tenant
 * devine par defaut — sinon on n'isole pas un contexte, on en invente un.
 */
class TASK1608FooterFlowchartLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $main;

    private Organization $launchpals;

    protected function setUp(): void
    {
        parent::setUp();

        // `hero-v2` : le gabarit de `main`, en francais.
        $this->main = Organization::factory()->create([
            'slug' => 'main-1608',
            'name' => 'BouclePro 1608',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'locale' => 'fr',
            'homepage_template' => 'bouclepro_hero_v2',
        ]);

        // `artscilab-hero` : le gabarit de `launchpals`, en anglais.
        $this->launchpals = Organization::factory()->create([
            'slug' => 'launchpals-1608',
            'name' => 'LaunchPals 1608',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'locale' => 'en',
            'homepage_template' => 'artscilab_hero',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    // =====================================================================
    // 18. `/org/main` en FR — Mycelium, PUIS Logigramme
    // =====================================================================

    /**
     * L'ordre compte : « immediatement a sa droite » se mesure par la position
     * dans le HTML, pas par la simple presence des deux liens.
     */
    public function test_18_the_french_landing_shows_mycelium_then_logigramme(): void
    {
        $this->oublierOrganisation();
        app()->setLocale('fr');

        $response = $this->get('/org/'.$this->main->slug);
        $response->assertOk();

        $html = $response->getContent();

        $mycelium = strpos($html, 'data-footer-mycelium');
        $flowchart = strpos($html, 'data-footer-flowchart');

        $this->assertNotFalse($mycelium, 'Le lien Mycelium doit rester present.');
        $this->assertNotFalse($flowchart, 'Le lien Logigramme doit etre pose.');
        $this->assertLessThan($flowchart, $mycelium, 'Le logigramme vient APRES Mycelium, pas avant.');

        // `assertSee` SANS `false` : Blade echappe `&` en `&amp;`, et chercher
        // la chaine brute mesurerait l'echappement, pas le libelle.
        $response->assertSee('Mycélium & organisations');
        $response->assertSee('Logigramme', false);
        $response->assertSee('/org/'.$this->main->slug.'/flowchart', false);
    }

    // =====================================================================
    // 19. `/org/launchpals` en EN — Flowchart, borne a SA propre Organization
    // =====================================================================

    public function test_19_the_english_landing_shows_flowchart_scoped_to_its_own_organization(): void
    {
        $this->oublierOrganisation();
        app()->setLocale('en');

        $response = $this->get('/org/'.$this->launchpals->slug);
        $response->assertOk();

        $response->assertSee('Flowchart', false);
        $response->assertSee('/org/'.$this->launchpals->slug.'/flowchart', false);

        // Le pied d'une Organization ne renvoie jamais chez une autre.
        $response->assertDontSee('/org/'.$this->main->slug.'/flowchart', false);
    }

    /**
     * L'etiquette EN est bien « Flowchart », et la FR « Logigramme » : deux
     * libelles distincts, donc une vraie traduction et non une chaine unique.
     */
    public function test_19b_the_two_locales_render_two_different_labels(): void
    {
        $this->assertSame('Logigramme', __('footer.flowchart', [], 'fr'));
        $this->assertSame('Flowchart', __('footer.flowchart', [], 'en'));
    }

    // =====================================================================
    // 20. La bascule FR/EN traduit le libelle ET conserve l'URL scoped
    // =====================================================================

    /**
     * La bascule traduit le libelle ET conserve l'URL Organization-scoped.
     *
     * Les deux moities se mesurent sur les deux surfaces concernees :
     *
     * - l'URL conservee, depuis le flowchart lui-meme.
     *   `LocaleController::destination()` preserve l'URL exacte par le
     *   `Referer` (TASK-1602). Rien n'est ajoute ici : ce test MESURE ce
     *   comportement, dans les DEUX sens.
     *
     * - le libelle traduit, sur la landing, seule surface des deux qui porte
     *   `partials/footer` — `x-app-layout` n'inclut aucun pied, ses liens
     *   legaux vivant dans la nav laterale. Ecart rapporte a MASTER :
     *   ajouter un pied a `x-app-layout` changerait TOUTES les pages
     *   applicatives, ce que l'addendum ne demande pas.
     */
    public function test_20_switching_locale_translates_the_label_and_keeps_the_scoped_url(): void
    {
        $depart = '/org/'.$this->launchpals->slug.'/flowchart';
        $landing = '/org/'.$this->launchpals->slug;

        // A — l'URL exacte du flowchart survit a la bascule, dans les 2 sens.
        $this->oublierOrganisation();
        $this->from($depart)->post('/locale/fr')->assertRedirect(url($depart));

        $this->oublierOrganisation();
        $this->from($depart)->post('/locale/en')->assertRedirect(url($depart));

        // B — le libelle suit la langue, sur l'URL de CETTE Organization.
        //
        // La bascule passe par la VRAIE route : c'est elle qui depose la
        // locale en session. `app()->setLocale()` serait ecrase au prochain
        // passage du middleware, et le test mesurerait alors un reglage que
        // l'utilisateur ne fait jamais.
        $this->oublierOrganisation();
        $this->from($landing)->post('/locale/fr')->assertRedirect(url($landing));

        $this->oublierOrganisation();
        $enFrancais = $this->get($landing);
        $enFrancais->assertOk();
        $enFrancais->assertSee('Logigramme', false);
        $enFrancais->assertSee($depart, false);
        $enFrancais->assertDontSee('/org/'.$this->main->slug.'/flowchart', false);

        $this->oublierOrganisation();
        $this->from($landing)->post('/locale/en')->assertRedirect(url($landing));

        $this->oublierOrganisation();
        $enAnglais = $this->get($landing);
        $enAnglais->assertOk();
        $enAnglais->assertSee('Flowchart', false);
        $enAnglais->assertSee($depart, false);
    }

    // =====================================================================
    // A (addendum recette) — la page flowchart porte le pied d'Organization
    // =====================================================================

    /**
     * La carte elle-meme sert le pied partage, Mycelium et logigramme compris.
     *
     * Mesure AVANT correctif : `/org/{slug}/flowchart` n'affichait AUCUN pied.
     * La cause n'etait pas la page mais le layout — `layouts/app.blade.php`
     * n'inclut pas `partials/footer`, ses liens legaux vivant dans la nav
     * laterale (`components/app-side-nav:461`).
     *
     * Le pied est donc pose sur cette page par `@include('partials.footer')` :
     * le MEME fichier que les surfaces publiques, jamais une copie de son HTML.
     * L'ajouter au layout aurait donne un pied a TOUTES les pages
     * applicatives, ce que l'addendum ne demande pas.
     */
    public function test_a_the_flowchart_page_serves_the_organization_footer(): void
    {
        $this->oublierOrganisation();
        $this->from('/org/'.$this->main->slug)->post('/locale/fr');

        $this->oublierOrganisation();
        $response = $this->get('/org/'.$this->main->slug.'/flowchart');
        $response->assertOk();

        $html = $response->getContent();

        $mycelium = strpos($html, 'data-footer-mycelium');
        $flowchart = strpos($html, 'data-footer-flowchart');

        $this->assertNotFalse($mycelium, 'La page flowchart doit porter le pied d\'Organization.');
        $this->assertNotFalse($flowchart, 'Le logigramme doit y figurer aussi.');
        $this->assertLessThan($flowchart, $mycelium, 'Le logigramme vient APRES Mycelium.');

        $response->assertSee('Mycélium & organisations');
        $response->assertSee('Logigramme', false);

        // Les liens du pied restent bornes a CETTE Organization.
        $response->assertSee('/org/'.$this->main->slug.'/mycelium', false);
        $response->assertSee('/org/'.$this->main->slug.'/flowchart', false);
        $response->assertDontSee('/org/'.$this->launchpals->slug.'/', false);
    }

    // =====================================================================
    // La borne : hors `/org/{slug}`, aucun lien invente
    // =====================================================================

    /**
     * Sur une route GLOBALE, le lien ne s'affiche pas.
     *
     * MASTER interdit une route `/flowchart` globale, et la doctrine
     * TASK-1602/1604 interdit de deviner un tenant. Un lien construit sur
     * l'Organization par defaut enverrait un membre de `launchpals` sur la
     * carte de `main` : exactement le defaut que TASK-1602 a corrige.
     */
    public function test_no_flowchart_link_is_invented_outside_an_organization_url(): void
    {
        $this->oublierOrganisation();

        $membre = User::factory()->complete()->create(['organization_id' => $this->main->id]);

        $response = $this->actingAs($membre)->get('/dashboard');
        $response->assertOk();

        $response->assertDontSee('data-footer-flowchart', false);
    }
}
