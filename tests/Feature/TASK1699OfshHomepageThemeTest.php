<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Theme;
use App\Models\User;
use App\Support\Homepage\RootDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1699 — le gabarit d'accueil OFSH, et le theme `ofsh` clair/sombre.
 *
 * Cyril : « je voudrais qu'on cree un nouveau theme pour OFSH, et qu'on puisse
 * le choisir via /admin/organizations/ofsh/homepage. De la meme facon, je
 * voudrais un theme dark/light mode pour OFSH. »
 *
 * ## Deux choses distinctes, et il vaut de le dire
 *
 * « Theme » designe DEUX objets differents dans ce produit, et la demande les
 * nomme tous les deux :
 *
 *   1. le GABARIT d'accueil (`organizations.homepage_template`) — la structure
 *      de la vitrine, choisie dans `/admin/homepages` ;
 *   2. le THEME de couleurs (`organizations.theme_id` -> `themes.key`) — les
 *      vingt jetons `--bp-*`, declines en clair et en sombre, que TOUT le
 *      produit lit, pas seulement la vitrine.
 *
 * Les deux sont independants : une Organization peut prendre le gabarit OFSH
 * sans le theme OFSH, et l'inverse. Ce fichier mesure les deux, et la
 * frontiere entre eux.
 *
 * ## Ce qui n'est PAS mesure ici
 *
 * La fidelite a la maquette. Elle se recette a l'oeil, captures a l'appui
 * (4 PNG de reference dans `design/HomePage/OFSH-Homepage-Couleurs`). Un test
 * qui asserterait des valeurs hexadecimales de CSS ne prouverait que sa propre
 * recopie — et rougirait au premier ajustement visuel, qui est precisement ce
 * qu'on veut pouvoir faire librement.
 */
class TASK1699OfshHomepageThemeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ofsh;

    private Organization $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ofsh = Organization::factory()->create([
            'slug' => 'ofsh-1699',
            'name' => 'OFSH 1699',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'locale' => 'fr',
            'homepage_template' => 'ofsh_hero',
        ]);

        $this->autre = Organization::factory()->create([
            'slug' => 'autre-1699',
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    private function landing(?User $qui = null): string
    {
        $this->oublierOrganisation();

        $requete = $qui ? $this->actingAs($qui) : $this;

        return $requete->get('/org/'.$this->ofsh->slug)->assertOk()->getContent();
    }

    /**
     * Le lien porte par un marqueur, jamais la page entiere.
     *
     * Lecon de TASK-1697 : `assertSee('/org/x/blog')` est vrai des qu'une URL
     * apparait N'IMPORTE OU dans le document — un autre lien, un attribut, un
     * fragment de script. Quatre faux verts ont ete trouves comme ca.
     */
    private function hrefDeLaCarte(string $html, string $carte): string
    {
        $trouve = preg_match('/<a[^>]*data-ofsh-card="'.$carte.'"[^>]*>/', $html, $m);

        $this->assertSame(1, $trouve, "La carte « {$carte} » n'est pas dans le document.");
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $m[0], $h), "La carte « {$carte} » n'a pas de href.");

        return $this->chemin(html_entity_decode($h[1]));
    }

    /**
     * Le CHEMIN d'une URL, hote compris ou non.
     *
     * `route()` rend des URL ABSOLUES. Comparer une destination a
     * `'/org/x/blog'` rougit donc toujours, et — plus grave — une garde qui
     * ne chercherait que les `href` commencant par `/` ne verrait AUCUN lien
     * de cette page et serait verte sans rien mesurer. Les deux defauts ont
     * ete trouves au premier passage de ce fichier.
     */
    private function chemin(string $url): string
    {
        $chemin = parse_url($url, PHP_URL_PATH) ?: '/';
        $requete = parse_url($url, PHP_URL_QUERY);

        return $requete ? $chemin.'?'.$requete : $chemin;
    }

    // ── Le gabarit ──────────────────────────────────────────────────────────

    public function test_le_gabarit_ofsh_est_servi_quand_l_organization_le_choisit(): void
    {
        $html = $this->landing();

        // Le gabarit OFSH, et pas un autre : ses quatre cartes d'orbite et sa
        // feuille de style lui sont propres.
        $this->assertStringContainsString('css/ofsh-hero.css', $html);
        $this->assertStringContainsString('js/ofsh-hero.js', $html);
        foreach (['help', 'offer', 'share', 'together'] as $carte) {
            $this->assertStringContainsString('data-ofsh-card="'.$carte.'"', $html);
        }

        // Et pas les deux autres landings autonomes.
        $this->assertStringNotContainsString('css/bouclepro-hero.css', $html);
        $this->assertStringNotContainsString('css/artscilab-hero.css', $html);
    }

    public function test_un_autre_gabarit_ne_sert_pas_la_landing_ofsh(): void
    {
        // La contre-epreuve du test precedent : sans ce volet, une branche de
        // controleur qui rendrait TOUJOURS `ofsh-hero` serait verte.
        $this->ofsh->update(['homepage_template' => 'bouclepro_hero_v2']);

        $html = $this->landing();

        $this->assertStringNotContainsString('css/ofsh-hero.css', $html);
        $this->assertStringContainsString('css/bouclepro-hero.css', $html);
    }

    /**
     * Les quatre cartes : le libelle et la destination disent la MEME chose.
     *
     * C'est la lecon de TASK-1608, qui avait trouve l'inverse sur `hero-v2` —
     * « J'explore une piste » menait a l'Annuaire. Les quatre destinations sont
     * donc epinglees une par une, jamais deduites l'une de l'autre.
     */
    public function test_les_quatre_cartes_menent_aux_surfaces_annoncees_pour_un_membre(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => now(),
        ]);

        $html = $this->landing($membre);
        $prefixe = '/org/'.$this->ofsh->slug;

        // « J'ai besoin d'entraide » -> les PROPOSITIONS des autres.
        $this->assertSame($prefixe.'/explorer', $this->hrefDeLaCarte($html, 'help'));

        // « Je peux apporter mon aide » -> les DEMANDES. Le symetrique.
        $this->assertSame($prefixe.'/explorer?tab=requests', $this->hrefDeLaCarte($html, 'offer'));

        // « J'ai quelque chose a partager » -> les contenus.
        $this->assertSame($prefixe.'/blog', $this->hrefDeLaCarte($html, 'share'));

        // « Et si on faisait ensemble ? » -> les Boucles.
        $this->assertSame($prefixe.'/loops', $this->hrefDeLaCarte($html, 'together'));
    }

    /**
     * Pour un visiteur, les quatre cartes menent a la connexion BORNEE.
     *
     * Ce n'est pas un lien qui ne mene nulle part — c'est l'invitation a
     * entrer, et la page de connexion est la destination annoncee. La doctrine
     * TASK-1692 vise les liens qui PROMETTENT une page et ne rendent qu'une
     * redirection ; ce n'est pas le cas ici.
     */
    public function test_les_quatre_cartes_menent_a_la_connexion_bornee_pour_un_visiteur(): void
    {
        $html = $this->landing();
        $connexion = '/org/'.$this->ofsh->slug.'/login';

        foreach (['help', 'offer', 'share', 'together'] as $carte) {
            $this->assertSame($connexion, $this->hrefDeLaCarte($html, $carte), "La carte « {$carte} » n'invite pas a entrer.");
        }
    }

    /**
     * Un membre d'une AUTRE Organization est traite comme un visiteur.
     *
     * C'est `$showInternalHomepageContent` (TASK-1692) qui le dit, et ce
     * gabarit s'y conforme : appartenir a un tenant n'ouvre pas les surfaces
     * d'un autre.
     */
    public function test_un_membre_d_une_autre_organization_est_traite_comme_un_visiteur(): void
    {
        $etranger = User::factory()->complete()->create([
            'organization_id' => $this->autre->id,
            'email_verified_at' => now(),
        ]);

        $html = $this->landing($etranger);

        $this->assertSame(
            '/org/'.$this->ofsh->slug.'/login',
            $this->hrefDeLaCarte($html, 'help')
        );
        $this->assertStringNotContainsString('/org/'.$this->autre->slug.'/', $html);
    }

    /**
     * AUCUNE URL globale.
     *
     * Ce gabarit est proposable a toute Organization : une URL globale y
     * enverrait les visiteurs d'un tenant sur les surfaces d'un autre. C'est
     * la doctrine TASK-1602 / 1604, et le defaut reel que TASK-1608 a trouve
     * sur `hero-v2`.
     *
     * La mesure porte sur les `href` REELS du document, pas sur la page :
     * chercher la chaine « /explorer » dans tout le HTML trouverait aussi
     * `/org/ofsh-1699/explorer`.
     */
    public function test_aucun_lien_du_gabarit_ne_sort_du_prefixe_de_l_organization(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => now(),
        ]);

        foreach ([null, $membre] as $viseur) {
            $html = $this->landing($viseur);

            preg_match_all('/href="([^"]+)"/', $html, $m);

            // Les liens EXTERNES ne sont pas l'objet de cette garde : elle
            // mesure si une URL du produit sort du prefixe de l'Organization.
            $hote = parse_url(config('app.url'), PHP_URL_HOST);
            $internes = array_unique(array_map(
                fn (string $url): string => $this->chemin(html_entity_decode($url)),
                array_filter($m[1], function (string $url) use ($hote): bool {
                    $h = parse_url(html_entity_decode($url), PHP_URL_HOST);

                    return $h === null || $h === $hote || $h === 'localhost';
                })
            ));

            // Non-vacuite : sans ce controle, un filtre trop etroit rendrait la
            // garde verte en ne regardant rien. C'est exactement ce qui s'est
            // passe au premier jet — `href="/...` ne voyait aucun lien, parce
            // que `route()` rend des URL absolues.
            $this->assertGreaterThanOrEqual(10, count($internes), 'La garde ne mesure presque rien : le filtre est trop etroit.');

            $globales = array_filter($internes, function (string $url): bool {
                // Les seules URL de premier niveau tolerees : les assets, et le
                // tableau de bord, qui est PERSONNEL et non tenant (c'est la
                // borne ecrite de `ResolveUrlOrganization`, TASK-1601).
                foreach (['/org/', '/css/', '/js/', '/img/', '/build/', '/storage/', '/dashboard', '/locale/', '/favicon'] as $tolere) {
                    if (str_starts_with($url, $tolere)) {
                        return false;
                    }
                }

                return true;
            });

            $this->assertSame([], array_values($globales), 'URL globales servies par le gabarit : '.implode(', ', $globales));
        }
    }

    // ── Le theme ────────────────────────────────────────────────────────────

    public function test_la_palette_ofsh_porte_les_memes_jetons_que_les_autres_themes(): void
    {
        $themes = config('bouclepro_themes.themes');

        $this->assertArrayHasKey('ofsh', $themes, 'Le theme `ofsh` a disparu de la configuration.');

        // La reference est `zen`, le theme par defaut : un jeton qui lui
        // manquerait rendrait `var(--bp-x)` vide sur toutes les surfaces qui le
        // lisent — l'echec SILENCIEUX contre lequel `theme-tokens` met en garde.
        $this->assertSame(
            array_keys($themes['zen']['tokens']),
            array_keys($themes['ofsh']['tokens']),
            'Les jetons CLAIRS de `ofsh` ne couvrent pas ceux de `zen`.'
        );
        $this->assertSame(
            array_keys($themes['zen']['dark']),
            array_keys($themes['ofsh']['dark']),
            'Les jetons SOMBRES de `ofsh` ne couvrent pas ceux de `zen`.'
        );

        // Clair et sombre doivent REELLEMENT differer : une palette sombre
        // recopiee de la claire passerait les deux assertions ci-dessus.
        $this->assertNotSame($themes['ofsh']['tokens']['page'], $themes['ofsh']['dark']['page']);
        $this->assertNotSame($themes['ofsh']['tokens']['text'], $themes['ofsh']['dark']['text']);
    }

    public function test_la_migration_attache_le_theme_a_son_organization(): void
    {
        // La migration a tourne sur cette base de test. L'Organization `ofsh`
        // n'y existait pas : la ligne de theme doit exister quand meme, et
        // n'avoir rattache personne — c'est le cas documente dans la migration.
        $theme = Theme::where('key', 'ofsh')->first();

        $this->assertNotNull($theme, 'La ligne de theme `ofsh` n\'a pas ete creee.');

        // Et quand une Organization la prend, la cle se resout.
        $this->ofsh->forceFill(['theme_id' => $theme->id])->save();

        $this->assertSame('ofsh', bp_organization_theme_key($this->ofsh->fresh()));
    }

    public function test_la_cle_de_theme_est_posee_cote_serveur_sur_la_landing(): void
    {
        $theme = Theme::where('key', 'ofsh')->firstOrFail();
        $this->ofsh->forceFill(['theme_id' => $theme->id])->save();
        Theme::regenerateCache();

        $html = $this->landing();

        $this->assertSame(
            1,
            preg_match('/<html[^>]*data-bp-theme="ofsh"/', $html),
            'La cle de theme n\'est pas posee sur <html> : un visiteur anonyme ne verrait pas les couleurs de l\'Organization.'
        );
    }

    /**
     * Le volet sombre existe, et il suit la preference SYSTEME.
     *
     * `.dark` est pose par le JavaScript des layouts applicatifs, qui lit un
     * `localStorage` partage entre Organizations. Une landing publique ne doit
     * pas le lire — `theme-tokens` le documente depuis TASK-1471.
     */
    public function test_le_volet_sombre_de_la_landing_suit_la_preference_systeme(): void
    {
        $html = $this->landing();

        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);

        // Et la palette sombre y est REELLEMENT servie, pas seulement le bloc.
        $sombre = config('bouclepro_themes.themes.ofsh.dark.page');
        $this->assertStringContainsString($sombre, $html, 'Le bloc sombre ne porte pas la palette sombre du theme.');

        // Aucun script de theme : la landing ne lit aucune preference rangee.
        $this->assertStringNotContainsString('localStorage', $html);
    }

    /**
     * `system-dark` est OPT-IN, et les deux autres landings n'y touchent pas.
     *
     * Sans cette garde, l'attribut aurait pu devenir le defaut d'un composant
     * appele par TOUTES les pages du produit : un rendu sombre impose a des
     * gabarits clairs qui n'ont jamais ete dessines pour lui.
     */
    public function test_les_deux_autres_landings_n_heritent_d_aucun_volet_sombre(): void
    {
        foreach (['bouclepro_hero_v2', 'artscilab_hero'] as $gabarit) {
            $this->ofsh->update(['homepage_template' => $gabarit]);

            $html = $this->landing();

            $this->assertStringNotContainsString(
                '@media (prefers-color-scheme: dark)',
                $html,
                "Le gabarit `{$gabarit}` a herite d'un volet sombre qu'il n'a pas demande."
            );
        }
    }

    // ── L'administration ────────────────────────────────────────────────────

    public function test_un_administrateur_peut_choisir_le_gabarit_ofsh(): void
    {
        $this->autre->update(['homepage_template' => null]);

        $admin = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->oublierOrganisation();
        $this->actingAs($admin)
            ->put(route('admin.organizations.homepage.update', $this->autre), ['homepage_template' => 'ofsh_hero'])
            ->assertRedirect();

        $this->assertSame('ofsh_hero', $this->autre->fresh()->homepage_template);
    }

    public function test_un_gabarit_inconnu_est_refuse(): void
    {
        // La contre-epreuve : si la validation acceptait n'importe quoi, le
        // test precedent serait vert pour la mauvaise raison.
        $admin = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->oublierOrganisation();
        $this->actingAs($admin)
            ->put(route('admin.organizations.homepage.update', $this->autre), ['homepage_template' => 'ofsh_hero_v9'])
            ->assertSessionHasErrors('homepage_template');
    }

    public function test_le_catalogue_des_gabarits_nomme_ofsh(): void
    {
        $admin = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->oublierOrganisation();
        $this->actingAs($admin)
            ->get(route('admin.homepages'))
            ->assertOk()
            ->assertSee('OFSH_Hero');

        $this->oublierOrganisation();
        $this->actingAs($admin)
            ->get(route('admin.organizations.homepage', $this->ofsh))
            ->assertOk()
            ->assertSee('value="ofsh_hero"', false);
    }

    /**
     * `ofsh_hero` est un gabarit AUTONOME, et la racine doit le savoir.
     *
     * L'oublier aurait servi la landing OFSH avec la destination de racine du
     * gabarit par defaut — un ecart invisible aux tests du gabarit lui-meme,
     * qui ne passent jamais par `GET /`.
     */
    public function test_la_racine_traite_ofsh_hero_comme_un_gabarit_autonome(): void
    {
        $this->assertSame(
            RootDestination::effective(null, 'bouclepro_hero_v2'),
            RootDestination::effective(null, 'ofsh_hero'),
            '`ofsh_hero` n\'est pas traite comme les autres gabarits autonomes.'
        );

        // Et le repli du gabarit par defaut n'a pas bouge pour autant.
        $this->assertNotSame(
            RootDestination::effective(null, null),
            RootDestination::effective(null, 'ofsh_hero')
        );
    }
}
