<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1700 — « À propos » de l'Organization OFSH.
 *
 * Cyril : « actuellement, cette page affiche à tort la page About de
 * LaunchPals, en anglais, avec des contenus liés à ArtSciLab. »
 *
 * ## La cause tenait en une ligne
 *
 * `OrganizationLandingController::about()` rendait `about-launchpals` a TOUTE
 * Organization, sans jamais regarder laquelle. Ce n'etait pas un defaut de
 * resolution de tenant — `$organization` etait juste — mais une vue codee en
 * dur. `main` recevait la meme page anglaise.
 *
 * ## Et un second constat, qui n'etait pas dans la demande
 *
 * `/org/{slug}/about` rendait **302 vers la connexion** a un visiteur anonyme.
 * Meme motif qu'en TASK-1698 : la ligne de route n'a jamais ete touchee que par
 * T371 et T1450, **jamais par T1692**, dont le groupe ferme par defaut l'a
 * refermee collateralement.
 *
 * Deux dispositifs du produit disaient pourtant deja que cette page s'adresse
 * a quelqu'un qui ne connait PAS encore le collectif : son CTA est
 * « Rejoindre », et `discovery-bottom-nav` l'affiche en premiere position « que
 * l'on soit connecte ou non ». Arbitrage MASTER du 25/10 : la route est
 * rouverte, et sa frontiere passe dans le controleur, ou elle est plus forte
 * qu'avant (voir `TASK1692StrictHttpIsolationTest::test_g_bis`).
 */
class TASK1700AboutOfshTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ofsh;

    private Organization $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ofsh = Organization::factory()->create([
            'slug' => 'ofsh-1700',
            'name' => 'OFSH 1700',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'locale' => 'fr',
            'homepage_template' => 'ofsh_hero',
        ]);

        // Le temoin : une Organization au gabarit par defaut. Sa page « A
        // propos » ne doit pas bouger d'un octet.
        $this->autre = Organization::factory()->create([
            'slug' => 'temoin-1700',
            'name' => 'Temoin 1700',
            'is_active' => true,
            'is_public' => true,
        ]);

        app()->setLocale('fr');
    }

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    private function about(Organization $organisation, ?User $qui = null): string
    {
        $this->oublierOrganisation();

        $requete = $qui ? $this->actingAs($qui) : $this;

        return $requete->get('/org/'.$organisation->slug.'/about')->assertOk()->getContent();
    }

    /** Le `href` porte par un marqueur, jamais cherche dans la page entiere. */
    private function href(string $html, string $marqueur): string
    {
        $this->assertSame(1, preg_match('/<a[^>]*'.preg_quote($marqueur, '/').'[^>]*>/', $html, $m), "Element « {$marqueur} » absent.");
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $m[0], $h), "Element « {$marqueur} » sans href.");

        $url = html_entity_decode($h[1]);
        $chemin = parse_url($url, PHP_URL_PATH) ?: '/';
        $requete = parse_url($url, PHP_URL_QUERY);

        return $requete ? $chemin.'?'.$requete : $chemin;
    }

    // ── L'aiguillage ────────────────────────────────────────────────────────

    public function test_ofsh_recoit_sa_propre_page_et_non_celle_de_launchpals(): void
    {
        $html = $this->about($this->ofsh);

        $this->assertStringContainsString('css/ofsh-about.css', $html);
        $this->assertStringContainsString('id="s-intro"', $html);
        $this->assertStringNotContainsString('lp-panel', $html, 'Le gabarit LaunchPals est encore rendu.');
    }

    /**
     * Aucune trace de LaunchPals ni d'ArtSciLab — ni dans le texte, ni dans les
     * classes, ni dans les liens.
     *
     * La mesure porte sur le document ENTIER et pas seulement sur le corps :
     * `about-launchpals` porte « LaunchPals » dans son `<title>`, dans son
     * `aria-label` de logo et dans ses libelles de bouton. Un aiguillage
     * incomplet laisserait l'un des trois.
     */
    public function test_la_page_ofsh_ne_contient_aucune_trace_de_launchpals_ni_d_artscilab(): void
    {
        $html = $this->about($this->ofsh);

        foreach (['LaunchPals', 'launchpals', 'ArtSciLab', 'artscilab', 'lp-panel', 'lp-nav', 'lp-title'] as $trace) {
            $this->assertStringNotContainsString($trace, $html, "Trace « {$trace} » dans la page OFSH.");
        }
    }

    /**
     * Aucun texte anglais dans les CONTENUS de la page.
     *
     * Les mots retenus sont ceux de `about-launchpals`, pris dans ses titres et
     * ses boutons. Ce test ne pretend pas detecter l'anglais en general : il
     * epingle le texte PRECIS qui s'affichait a tort, ce qui est exactement ce
     * que la demande nomme.
     */
    public function test_aucun_texte_anglais_ne_subsiste_dans_les_contenus(): void
    {
        $html = $this->about($this->ofsh);

        foreach ([
            'A living digital village',
            'The Mission',
            'Join LaunchPals',
            'Discover the circles',
            'Ask. Offer. Connect. Remember.',
            'Not another social network',
            'Village',
            'Memory',
        ] as $mot) {
            $this->assertStringNotContainsString($mot, $html, "Texte anglais « {$mot} » encore servi.");
        }

        // Et la page se declare en francais.
        $this->assertSame(1, preg_match('/<html[^>]*lang="fr"/', $html), 'La page ne se declare pas en francais.');
    }

    /**
     * Le temoin ne bouge pas. C'est la contre-epreuve de l'aiguillage : sans
     * elle, une branche qui rendrait TOUJOURS `about-ofsh` serait verte.
     */
    public function test_une_organization_au_gabarit_par_defaut_garde_sa_page_existante(): void
    {
        $html = $this->about($this->autre);

        $this->assertStringContainsString('lp-panel', $html, 'Le temoin a perdu sa page.');
        $this->assertStringNotContainsString('css/ofsh-about.css', $html);
        $this->assertStringNotContainsString('id="s-intro"', $html);
    }

    // ── Le contenu demande ──────────────────────────────────────────────────

    /** Les huit sections, et leurs huit ancres de navigation. */
    public function test_les_huit_sections_et_leur_navigation_par_ancres_sont_servies(): void
    {
        $html = $this->about($this->ofsh);

        $ancres = ['s-intro', 's-purpose', 's-values', 's-ways', 's-loops', 's-quiet', 's-ai', 's-join'];

        foreach ($ancres as $ancre) {
            $this->assertStringContainsString('id="'.$ancre.'"', $html, "Section « {$ancre} » absente.");
            $this->assertSame(
                1,
                preg_match('/<a[^>]*href="#'.$ancre.'"[^>]*data-of-nav/', $html),
                "La rubrique « {$ancre} » n'a pas de lien dans la navigation."
            );
        }

        // Autant de rubriques que de sections : ni lien orphelin, ni section
        // qu'on ne peut pas atteindre.
        //
        // Le comptage porte sur les BALISES, pas sur la chaine : le script de
        // la page contient `querySelectorAll('[data-of-nav]')`, et un
        // `substr_count` y voyait une neuvieme rubrique.
        $this->assertSame(
            count($ancres),
            preg_match_all('/<a[^>]*data-of-nav/', $html),
            'Le nombre de rubriques ne correspond pas au nombre de sections.'
        );
    }

    /** Les huit titres sont ceux que la demande dicte, mot pour mot. */
    public function test_les_huit_titres_sont_ceux_de_la_demande(): void
    {
        $html = $this->about($this->ofsh);

        foreach ([
            'Ensemble, donnons du sens à la solidarité professionnelle.',
            'Personne ne devrait rester seul face aux difficultés de son métier.',
            'Solidaires par conviction. Humanistes par engagement.',
            'Chacun peut avoir besoin d’aide. Chacun a quelque chose à apporter.',
            'Des échanges qui deviennent des actions.',
            'Moins de bruit. Plus d’entraide.',
            'L’IA facilite les échanges. L’entraide reste humaine.',
            'Et si nous avancions ensemble ?',
        ] as $titre) {
            $this->assertStringContainsString(e($titre), $html, "Titre manquant : « {$titre} »");
        }
    }

    /** Les quatre valeurs, et la regle fondamentale sur l'IA. */
    public function test_les_quatre_valeurs_et_la_regle_sur_l_ia_sont_posees(): void
    {
        $html = $this->about($this->ofsh);

        foreach (['Solidarité', 'Humanisme', 'Partage', 'Coopération'] as $valeur) {
            $this->assertStringContainsString(e($valeur), $html, "Valeur manquante : {$valeur}");
        }

        $this->assertSame(
            1,
            preg_match('/data-of-rule/', $html),
            'La regle fondamentale sur l\'IA n\'est pas mise en avant.'
        );
        $this->assertStringContainsString(
            e('Aucune contribution n’est publiée automatiquement sans validation humaine.'),
            $html
        );
    }

    /**
     * Les quatre intentions sont les MEMES qu'a l'accueil — libelles, ordre et
     * destinations.
     *
     * Ce n'est pas une coquetterie : les deux pages proposent le meme geste.
     * Si elles divergeaient, un visiteur qui clique « J'ai besoin d'entraide »
     * arriverait ailleurs selon la page d'ou il vient.
     */
    public function test_les_quatre_intentions_sont_celles_de_la_page_d_accueil(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => now(),
        ]);

        $html = $this->about($this->ofsh, $membre);
        $prefixe = '/org/'.$this->ofsh->slug;

        $this->assertSame($prefixe.'/explorer', $this->href($html, 'data-of-way="help"'));
        $this->assertSame($prefixe.'/explorer?tab=requests', $this->href($html, 'data-of-way="offer"'));
        $this->assertSame($prefixe.'/blog', $this->href($html, 'data-of-way="share"'));
        $this->assertSame($prefixe.'/loops', $this->href($html, 'data-of-way="together"'));

        // Et les memes libelles, lus aux memes clefs que l'accueil.
        $this->oublierOrganisation();
        $accueil = $this->actingAs($membre)->get('/org/'.$this->ofsh->slug)->assertOk()->getContent();

        foreach (['card_help_title', 'card_offer_title', 'card_share_title', 'card_together_title'] as $clef) {
            $libelle = e(__('ofsh.'.$clef));
            $this->assertStringContainsString($libelle, $html, "« {$libelle} » absent de la page About.");
            $this->assertStringContainsString($libelle, $accueil, "« {$libelle} » absent de l'accueil.");
        }
    }

    public function test_les_deux_cta_de_la_section_finale_pointent_ou_il_faut(): void
    {
        // Pour un visiteur : l'inscription bornee, et les Boucles derriere la
        // connexion bornee.
        $html = $this->about($this->ofsh);

        $this->assertSame('/org/'.$this->ofsh->slug.'/register', $this->href($html, 'data-of-cta-join'));
        $this->assertSame('/org/'.$this->ofsh->slug.'/login', $this->href($html, 'data-of-cta-loops'));

        // Pour un membre : « Rejoindre » n'a plus de sens, il cede la place au
        // tableau de bord, et les Boucles s'ouvrent vraiment.
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => now(),
        ]);

        $html = $this->about($this->ofsh, $membre);

        $this->assertSame('/dashboard', $this->href($html, 'data-of-cta-join'));
        $this->assertSame('/org/'.$this->ofsh->slug.'/loops', $this->href($html, 'data-of-cta-loops'));
    }

    /**
     * AUCUNE URL globale : ce gabarit suit l'Organization qui a choisi le
     * gabarit OFSH, quelle qu'elle soit (doctrine TASK-1602 / 1604).
     *
     * `route()` rend des URL ABSOLUES — une garde qui ne chercherait que les
     * `href="/…"` ne verrait aucun lien et serait verte sans rien mesurer.
     * C'est le defaut trouve en TASK-1699 ; le controle de non-vacuite est la
     * pour qu'il ne revienne pas.
     */
    public function test_aucun_lien_ne_sort_du_prefixe_de_l_organization(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => now(),
        ]);

        foreach ([null, $membre] as $viseur) {
            $html = $this->about($this->ofsh, $viseur);

            preg_match_all('/href="([^"]+)"/', $html, $m);

            $hote = parse_url(config('app.url'), PHP_URL_HOST);
            $chemins = array_unique(array_filter(array_map(function (string $url) use ($hote): ?string {
                $url = html_entity_decode($url);

                // Une ANCRE n'est pas une URL : `parse_url('#s-intro')` rend un
                // chemin vide, que le repli transformait en « / » — huit faux
                // positifs, un par rubrique de la navigation.
                if (str_starts_with($url, '#')) {
                    return null;
                }

                $h = parse_url($url, PHP_URL_HOST);

                if ($h !== null && $h !== $hote && $h !== 'localhost') {
                    return null; // lien externe : hors sujet ici
                }

                $chemin = parse_url($url, PHP_URL_PATH) ?: '/';

                return str_starts_with($chemin, '/') ? $chemin : null;
            }, $m[1])));

            $this->assertGreaterThanOrEqual(10, count($chemins), 'La garde ne mesure presque rien : le filtre est trop etroit.');

            $globales = array_filter($chemins, function (string $chemin): bool {
                // `/demo` est une exception DECLAREE a la doctrine
                // « aucune route globale depuis une surface Organization-scoped »,
                // et elle ne vient pas de ce gabarit : `discovery-bottom-nav`
                // la porte, et son docblock l'explique — `bouclepro.com/demo`
                // redirige vers un prototype heberge ailleurs, qui n'a aucune
                // variante par Organization. Ne pas la « corriger » en
                // inventant `organization.demo`.
                foreach (['/org/', '/css/', '/js/', '/img/', '/build/', '/storage/', '/dashboard', '/locale/', '/open-source/github', '/demo'] as $tolere) {
                    if (str_starts_with($chemin, $tolere)) {
                        return false;
                    }
                }

                return true;
            });

            $this->assertSame([], array_values($globales), 'URL globales servies : '.implode(', ', $globales));
        }
    }

    // ── L'acces ─────────────────────────────────────────────────────────────

    public function test_un_visiteur_anonyme_atteint_la_page(): void
    {
        // Le coeur de la demande : « en arrivant sur /org/ofsh/about, un
        // VISITEUR decouvre immediatement un collectif ».
        $this->oublierOrganisation();
        $this->get('/org/'.$this->ofsh->slug.'/about')->assertOk();

        // Et le temoin aussi : l'ouverture n'est pas reservee a OFSH.
        $this->oublierOrganisation();
        $this->get('/org/'.$this->autre->slug.'/about')->assertOk();
    }

    public function test_un_membre_d_une_autre_organization_voit_la_page_sans_rien_de_son_tenant(): void
    {
        $etranger = User::factory()->complete()->create([
            'organization_id' => $this->autre->id,
            'email_verified_at' => now(),
        ]);

        $html = $this->about($this->ofsh, $etranger);

        // Il est traite comme un visiteur : les quatre intentions l'invitent a
        // entrer, et aucune URL ne renvoie chez lui.
        $this->assertSame('/org/'.$this->ofsh->slug.'/login', $this->href($html, 'data-of-way="help"'));
        $this->assertStringNotContainsString('/org/'.$this->autre->slug.'/', $html);
    }

    // ── Le mobilier ─────────────────────────────────────────────────────────

    public function test_le_logo_ofsh_est_servi_dans_ses_deux_versions(): void
    {
        $html = $this->about($this->ofsh);

        $this->assertStringContainsString('data-ofsh-logo-light', $html);
        $this->assertStringContainsString('data-ofsh-logo-dark', $html);
        $this->assertStringContainsString('img/ofsh/ofsh-header-light.png', $html);
        $this->assertStringContainsString('img/ofsh/ofsh-header-dark.png', $html);

        // Le logo mene a l'accueil de SON Organization.
        $this->assertSame('/org/'.$this->ofsh->slug, $this->href($html, 'class="of-logo"'));
    }

    public function test_la_page_suit_le_theme_de_son_organization_et_son_volet_sombre(): void
    {
        $html = $this->about($this->ofsh);

        $this->assertSame(1, preg_match('/<html[^>]*data-bp-theme="/', $html), 'La cle de theme n\'est pas posee cote serveur.');
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertStringNotContainsString('localStorage', $html);
    }

    /**
     * La barre basse de decouverte reste montee.
     *
     * Elle est la raison la plus visible pour laquelle cette page devait etre
     * publique : son docblock dit « QUE L'ON SOIT CONNECTE OU NON : c'est une
     * aide a la decouverte, pas un etat de session », et elle mene a « A
     * propos » en premiere position.
     */
    public function test_la_barre_basse_de_decouverte_reste_montee(): void
    {
        $html = $this->about($this->ofsh);

        $this->assertStringContainsString('/org/'.$this->ofsh->slug.'/flowchart', $html);
        $this->assertStringContainsString('/org/'.$this->ofsh->slug.'/mycelium', $html);
    }
}
