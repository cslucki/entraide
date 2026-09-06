<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * TASK-1403 — les libelles VISIBLES du FAB mobile suivent la langue de
 * l'interface et ne viennent plus de `$T`.
 *
 * Le fait mesure (MOBILE_FAB_PROBE, 06/09, 390 x 844, `artscilab-en`) : le
 * menu du bouton flottant rendait « Faire une demande d'aide » et « Proposer
 * un micro-service » dans une Organization ANGLAISE. Ce n'etaient pas les
 * replis `?? 'demande'` du Blade : c'etaient les valeurs de `$T`, donc de
 * `config/terms.php`, un dictionnaire francais GLOBAL.
 *
 * D'ou la garde n.3, la plus importante du lot : elle ne verifie pas que les
 * libelles sont « traduits », elle verifie que `$T` n'a plus AUCUNE prise sur
 * les textes visibles de ce composant. Une garde qui se contenterait de
 * comparer des chaines passerait le jour ou quelqu'un remettrait un
 * `{{ $T[...] }}` dont la valeur ressemble a la traduction attendue.
 */
class TASK1403MobileFabLocaleLabelsTest extends TestCase
{
    use RefreshDatabase;

    private const VUE = 'resources/views/components/mobile-fab.blade.php';

    public function test_an_english_interface_renders_the_four_labels_in_english(): void
    {
        $rendu = $this->renderFab('en');

        $this->assertStringContainsString('aria-label="Add"', $rendu);
        $this->assertStringContainsString('Create a help request', $rendu);
        $this->assertStringContainsString('Offer a micro-service', $rendu);
        $this->assertStringContainsString('Write an article', $rendu);

        // Et la promesse en negatif : plus aucun des libelles francais mesures
        // a 390px sur `artscilab-en`.
        //
        // L'apostrophe est ECHAPPEE par Blade (`{{ }}`) : la chaine servie est
        // `demande d&#039;aide`, jamais `demande d'aide`. Une assertion ecrite
        // avec l'apostrophe litterale passerait donc toujours — y compris si le
        // libelle francais etait bel et bien la. C'est la forme SERVIE qu'on
        // mesure, pas la forme source.
        $this->assertStringNotContainsString('aria-label="Ajouter"', $rendu);
        $this->assertStringNotContainsString('demande d&#039;aide', $rendu);
        $this->assertStringNotContainsString('Proposer un micro-service', $rendu);
        $this->assertStringNotContainsString('Écrire un article', $rendu);
    }

    public function test_a_french_interface_renders_the_four_labels_in_french(): void
    {
        $rendu = $this->renderFab('fr');

        $this->assertStringContainsString('aria-label="Ajouter"', $rendu);
        // Forme SERVIE, apostrophe echappee par Blade.
        $this->assertStringContainsString('Faire une demande d&#039;aide', $rendu);
        $this->assertStringContainsString('Proposer un micro-service', $rendu);
        $this->assertStringContainsString('Écrire un article', $rendu);
    }

    /**
     * La garde centrale. `$T` est un `View::share` : il reste disponible dans
     * toutes les vues, y compris celle-ci. Ce qu'on exige, c'est que ce
     * composant ne le CONSOMME plus — mesure sur la source, seul endroit ou
     * l'absence est verifiable de facon non ambigue.
     */
    public function test_the_fab_no_longer_consumes_t_for_any_visible_label(): void
    {
        $source = File::get(base_path(self::VUE));

        // Les commentaires Blade parlent de `$T` a dessein (ils expliquent la
        // dette) : on les retire avant de mesurer, sinon la garde se declenche
        // sur sa propre documentation.
        $sansCommentaires = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertIsString($sansCommentaires);
        $this->assertStringNotContainsString('$T', $sansCommentaires);

        // Temoin : le fichier mesure est bien celui qu'on croit, et il porte
        // les quatre cles. Sans lui, un fichier vide passerait la garde.
        foreach ([
            'navigation.mobile_fab_add',
            'navigation.mobile_fab_create_request',
            'navigation.mobile_fab_offer_service',
            'navigation.mobile_fab_write_article',
        ] as $cle) {
            $this->assertStringContainsString($cle, $sansCommentaires);
        }
    }

    /**
     * Le perimetre etait ferme aux libelles : ni les routes, ni les actions,
     * ni le comportement Alpine ne devaient bouger.
     */
    public function test_routes_and_actions_are_unchanged(): void
    {
        $rendu = $this->renderFab('en');

        // Les trois destinations REELLES, relevees dans `route:list` — la
        // troisieme n'est pas `/blog/create` mais `/blog/rediger/nouveau`.
        $this->assertStringContainsString('/requests/create', $rendu);
        $this->assertStringContainsString('/services/create', $rendu);
        $this->assertStringContainsString('/blog/rediger/nouveau', $rendu);

        $source = File::get(base_path(self::VUE));
        $this->assertStringContainsString('x-data="{ open: false }"', $source);
        $this->assertStringContainsString('@click.outside="open = false"', $source);
        $this->assertStringContainsString('@click="open = !open"', $source);
        $this->assertStringContainsString("routeIs('organization.dossiers.*')", $source);
    }

    /**
     * Couplage fragile, decouvert par le probe : la page Boucle masque ce FAB
     * en mobile en le selectionnant par `button[class*="bottom-20"]`. Si la
     * classe disparait du bouton, le FAB REAPPARAIT dans le fil, ou il
     * recouvre le composeur. Les deux moities doivent donc rester d'accord —
     * et rien dans le code ne les relie autrement que par cette chaine.
     */
    public function test_the_loop_page_still_hides_the_fab_on_mobile(): void
    {
        $fab = File::get(base_path(self::VUE));
        $loopShow = File::get(base_path('resources/views/loops/show.blade.php'));

        // La moitie « selecteur ».
        $this->assertStringContainsString('body:has(.loops-show-container) > [class*="md:hidden"]:has(button[class*="bottom-20"])', $loopShow);
        $this->assertStringContainsString('@media (max-width: 767px)', $loopShow);

        // La moitie « cible » : le bouton porte encore la classe que le
        // selecteur cherche, et son conteneur la classe `md:hidden`.
        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*\bbottom-20\b/', $fab);
        $this->assertStringContainsString('class="md:hidden"', $fab);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function renderFab(string $locale): string
    {
        $organization = Organization::factory()->create(['locale' => $locale]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);

        app()->setLocale($locale);

        // On rend le CENTRE DE NOTIFICATIONS, pas l'index des Boucles : avec
        // une seule Boucle, `organization.loops.index` repond 302 vers cette
        // Boucle — et la page Boucle est justement celle ou le FAB est masque
        // en mobile. Le centre de notifications est l'une des deux pages ou le
        // MOBILE_FAB_PROBE a mesure le FAB REELLEMENT visible a 390px.
        $reponse = $this->actingAs($user)
            ->get(route('organization.notifications.index', ['organization' => $organization->slug]));

        $reponse->assertOk();

        return $reponse->getContent();
    }
}
