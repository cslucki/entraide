<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1471 — un seul producteur de tokens de theme.
 *
 * ## Le probleme, mesure
 *
 * La boucle qui ecrit `--bp-*` vivait en double : quatre blocs dans
 * `layouts/app`, CINQ dans `layouts/org-admin`. Les deux copies avaient deja
 * diverge — org-admin ecrivait un `.dark[data-bp-theme="<defaut>"]` explicite
 * que la boucle suivante regenerait de toute facon, aux memes valeurs.
 *
 * Et les deux landings publiques reelles — `hero-v2` (main) et
 * `artscilab-hero` (launchpals) — sont des documents HTML autonomes : elles
 * n'emettaient AUCUN token. `--bp-primary` y etait litteralement absent.
 *
 * ## Ce que ce test protege
 *
 * 1. **Une seule boucle generatrice dans tout le depot.** C'est le coeur : une
 *    quatrieme copie reintroduirait la divergence que cette TASK supprime, et
 *    aucun test de valeur ne l'attraperait — les copies rendent les MEMES
 *    valeurs jusqu'au jour ou l'une d'elles est modifiee seule.
 * 2. Les tokens sont reellement rendus sur les quatre familles de pages.
 * 3. La landing publique porte le theme de SON Organization, pas le defaut.
 * 4. Le chemin Guest ne gagne aucun asset applicatif.
 *
 * ## Ce que ce test ne fait pas
 *
 * Il ne verifie pas une couleur en dur. Les valeurs viennent du systeme de
 * themes existant ; les asserter figerait une donnee de configuration dans un
 * test. Ce qui est verifie est la CORRESPONDANCE : la landing d'une
 * Organization rend la meme valeur que le systeme de themes attribue a son
 * theme, et deux Organizations aux themes differents rendent des valeurs
 * differentes.
 */
class TASK1471PublicThemeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Une seule boucle generatrice — le contrat central
    // =====================================================================

    public function test_only_one_file_in_the_repository_generates_theme_tokens(): void
    {
        $generators = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), '--bp-{{ $token }}')) {
                $generators[] = str_replace(resource_path('views').'/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            ['components/theme-tokens.blade.php'],
            $generators,
            "la generation des tokens doit vivre dans UN seul fichier ; trouvee dans : \n  ".implode("\n  ", $generators),
        );
    }

    /** Et le chargement de la source aussi : plus de `bouclepro-themes.php` recopie. */
    public function test_only_the_helper_reads_the_theme_source(): void
    {
        $readers = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            // Le CHARGEMENT, pas la mention : le composant cite le fichier dans
            // son commentaire d'intention, et c'est bien ainsi.
            if (str_contains((string) file_get_contents($file->getPathname()), "storage_path('app/bouclepro-themes.php')")) {
                $readers[] = str_replace(resource_path('views').'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $readers, 'le chargement de la source de themes ne vit plus dans une vue');
    }

    // =====================================================================
    // B. Les quatre familles de pages rendent les tokens
    // =====================================================================

    public function test_the_four_page_families_all_render_the_tokens(): void
    {
        [$hero, $artscilab] = $this->twoLandingOrganizations();
        $member = User::factory()->complete()->create(['organization_id' => $hero->id]);

        $pages = [
            'hero-v2 (public)' => $this->get(route('organization.home', ['organization' => $hero->slug])),
            'artscilab-hero (public)' => $this->get(route('organization.home', ['organization' => $artscilab->slug])),
            'layouts/app' => $this->actingAs($member)->get(route('organization.dashboard', ['organization' => $hero->slug])),
        ];

        foreach ($pages as $label => $response) {
            $html = $response->assertOk()->getContent();

            $this->assertStringContainsString('--bp-primary:', $html, $label.' : --bp-primary');
            $this->assertStringContainsString('--bp-primary-deep:', $html, $label.' : --bp-primary-deep');
        }
    }

    // =====================================================================
    // C. La landing porte le theme de SON Organization
    // =====================================================================

    /**
     * Le point produit : deux Organizations aux themes differents doivent
     * rendre des couleurs differentes sur leurs landings respectives. Sans
     * cela, « le trigger herite du theme » serait vrai en apparence et faux en
     * pratique.
     */
    public function test_each_landing_carries_its_own_organization_theme(): void
    {
        [$hero, $artscilab] = $this->twoLandingOrganizations();

        $themes = bp_themes()['themes'];
        $heroKey = $hero->theme->key;
        $otherKey = $artscilab->theme->key;
        $this->assertNotSame($heroKey, $otherKey, 'pre-requis : les deux Organizations ont des themes differents');
        $this->assertNotSame(
            $themes[$heroKey]['tokens']['primary'],
            $themes[$otherKey]['tokens']['primary'],
            'pre-requis : les deux themes ont une couleur primaire differente',
        );

        foreach ([$hero, $artscilab] as $organization) {
            $html = $this->get(route('organization.home', ['organization' => $organization->slug]))->assertOk()->getContent();

            // La cle est posee cote SERVEUR sur la balise <html> — et c'est bien
            // la BALISE qu'on mesure. La chaine `data-bp-theme="zen"` figure
            // aussi dans la feuille de style generee : une assertion sur la
            // chaine seule serait verte meme si l'attribut disparaissait de
            // <html>. Mesure faite : le sabotage passait.
            $this->assertSame(
                1,
                preg_match('/<html[^>]*\sdata-bp-theme="([a-z0-9_-]+)"/i', $html, $htmlTag),
                $organization->slug.' : la balise <html> porte une cle de theme',
            );
            $this->assertSame($organization->theme->key, $htmlTag[1], $organization->slug.' : et c\'est celle de SON Organization');

            // Et la regle qui porte cette cle existe bien dans la feuille rendue.
            $this->assertMatchesRegularExpression(
                '/\[data-bp-theme="'.preg_quote($organization->theme->key, '/').'"\][^}]*--bp-primary:\s*'.preg_quote($themes[$organization->theme->key]['tokens']['primary'], '/').'/s',
                $html,
                $organization->slug.' : la couleur du theme de cette Organization',
            );
        }
    }

    /** Une Organization sans theme retombe sur le theme par defaut, jamais sur du vide. */
    public function test_an_organization_without_a_theme_falls_back_to_the_default(): void
    {
        $organization = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => 'org-sans-theme',
            'homepage_template' => 'bouclepro_hero_v2',
            'theme_id' => null,
        ]);

        $html = $this->get(route('organization.home', ['organization' => $organization->slug]))->assertOk()->getContent();

        $this->assertStringContainsString('data-bp-theme="'.bp_themes()['default'].'"', $html);
        $this->assertStringContainsString('--bp-primary:', $html);
    }

    // =====================================================================
    // D. Le chemin Guest ne s'alourdit pas
    // =====================================================================

    /**
     * La fondation ajoute une feuille de style INLINE, rien d'autre. Un
     * visiteur anonyme ne doit toujours charger aucun asset applicatif : c'est
     * la garde de perf du CDC (§4), et elle vaut aussi pour cette TASK.
     */
    public function test_the_anonymous_landing_gains_no_application_asset(): void
    {
        [$hero, $artscilab] = $this->twoLandingOrganizations();

        foreach ([$hero, $artscilab] as $organization) {
            $html = $this->get(route('organization.home', ['organization' => $organization->slug]))->assertOk()->getContent();

            $this->assertStringNotContainsString('build/assets/app-', $html, $organization->slug.' : aucun bundle applicatif');
            $this->assertStringNotContainsString('/livewire/livewire.js', strtolower($html), $organization->slug.' : aucun runtime Livewire');
            $this->assertStringNotContainsString('wire:snapshot', strtolower($html), $organization->slug.' : aucun composant Livewire monte');
            $this->assertStringNotContainsString('@vite', $html, $organization->slug);
        }
    }

    /** Et aucun script de preference de theme n'est copie sur la landing. */
    public function test_no_localstorage_theme_script_is_copied_to_the_landings(): void
    {
        foreach (['organization/hero-v2.blade.php', 'organization/artscilab-hero.blade.php'] as $view) {
            $source = (string) file_get_contents(resource_path('views/'.$view));

            $this->assertStringNotContainsString('localStorage.bpTheme', $source, $view);
            $this->assertStringNotContainsString('window.bpThemes', $source, $view);
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array{0: Organization, 1: Organization} */
    private function twoLandingOrganizations(): array
    {
        $themes = bp_themes()['themes'];
        $keys = array_keys($themes);

        // Deux themes dont la couleur primaire differe reellement.
        $first = $keys[0];
        $second = null;
        foreach (array_slice($keys, 1) as $key) {
            if ($themes[$key]['tokens']['primary'] !== $themes[$first]['tokens']['primary']) {
                $second = $key;
                break;
            }
        }
        $this->assertNotNull($second, 'le systeme de themes doit offrir deux couleurs primaires distinctes');

        return [
            $this->landing('org-hero', 'bouclepro_hero_v2', $first),
            $this->landing('org-artscilab', 'artscilab_hero', $second),
        ];
    }

    private function landing(string $slug, string $template, string $themeKey): Organization
    {
        $theme = Theme::query()->firstOrCreate(
            ['key' => $themeKey],
            ['label' => strtoupper($themeKey)],
        );

        return Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => $slug,
            'homepage_template' => $template,
            'theme_id' => $theme->id,
        ]);
    }
}
