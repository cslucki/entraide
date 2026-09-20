<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1610 — l'identite de la page : titre, description, apercu social.
 *
 * ## Le defaut mesure
 *
 * L'apercu WhatsApp de `/org/launchpals/flowchart` affichait l'ancien
 * positionnement : « Plateforme de troc de services entre professionnels — …».
 *
 * La cause n'etait pas une faute de frappe, mais une CONDITION : le bloc
 * OpenGraph de `layouts/app.blade.php` vivait sous `@isset($ogTitle)`. Une page
 * qui ne definit pas cette variable — le logigramme, par exemple — n'emettait
 * donc AUCUNE balise `og:*`, et les reseaux se rabattaient sur la
 * `meta description`, seul endroit ou l'ancien slogan subsistait.
 *
 * Deux gardes distinctes en decoulent, et c'est volontaire :
 *
 * 1. L'apercu social est EMIS, toujours. Une page sans `$ogTitle` doit quand
 *    meme porter `og:description` — sans quoi le defaut reviendrait sans
 *    qu'aucune chaine ne change.
 * 2. L'ancien slogan a DISPARU des sources servies. Une garde qui ne
 *    verifierait que la page du logigramme laisserait passer sa reapparition
 *    ailleurs.
 */
class TASK1610SocialMetadataTest extends TestCase
{
    use RefreshDatabase;

    /** La description canonique, au caractere pres. */
    private const DESCRIPTION = 'Intelligence augmented by your peers.';

    /** Ce que le produit ne doit plus jamais dire de lui-meme. */
    private const ANCIEN_SLOGAN = [
        'Plateforme de troc',
        'échangez vos compétences',
        'troc de services',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    private function organisation(string $nom, string $slug, bool $defaut = false): Organization
    {
        return Organization::factory()->create([
            'name' => $nom,
            'slug' => $slug.'-'.bin2hex(random_bytes(3)),
            'is_active' => true,
            'is_public' => true,
            'is_default' => $defaut,
            'loops_enabled' => true,
        ]);
    }

    /** La valeur d'une balise, lue sur le HTML rendu. */
    private function balise(string $html, string $attribut, string $valeur): ?string
    {
        return preg_match(
            '~<meta '.$attribut.'="'.preg_quote($valeur, '~').'" content="([^"]*)"~',
            $html,
            $m,
        ) ? html_entity_decode($m[1]) : null;
    }

    private function titre(string $html): ?string
    {
        return preg_match('~<title>([^<]*)</title>~', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    private function flowchart(Organization $organisation): string
    {
        app()->forgetInstance('current_organization');

        return $this->get('/org/'.$organisation->slug.'/flowchart')->assertOk()->getContent();
    }

    // =====================================================================
    // A. La description canonique, sur les trois canaux
    // =====================================================================

    /**
     * `/org/{org}/flowchart` porte la description canonique partout.
     *
     * Les trois canaux sont verifies ENSEMBLE : c'est leur divergence qui a
     * produit le defaut. La `meta description` etait la seule presente, et
     * c'est elle que WhatsApp a lue.
     */
    public function test_the_flowchart_carries_the_canonical_description_everywhere(): void
    {
        $html = $this->flowchart($this->organisation('LaunchPals', 'launchpals'));

        $this->assertSame(self::DESCRIPTION, $this->balise($html, 'name', 'description'));
        $this->assertSame(self::DESCRIPTION, $this->balise($html, 'property', 'og:description'));
        $this->assertSame(self::DESCRIPTION, $this->balise($html, 'name', 'twitter:description'));
    }

    /**
     * L'apercu social est emis MEME SANS `$ogTitle`.
     *
     * C'est la garde du defaut lui-meme : le logigramme ne definit pas cette
     * variable, et c'est exactement pour cela qu'il n'avait aucun `og:*`.
     */
    public function test_the_social_preview_is_emitted_even_without_a_page_og_title(): void
    {
        $html = $this->flowchart($this->organisation('LaunchPals', 'launchpals'));

        foreach (['og:title', 'og:description', 'og:type', 'og:site_name', 'og:image'] as $balise) {
            $this->assertNotNull(
                $this->balise($html, 'property', $balise),
                "`{$balise}` est absent : l'apercu social redevient devinable par le reseau.",
            );
        }

        foreach (['twitter:card', 'twitter:title', 'twitter:description', 'twitter:image'] as $balise) {
            $this->assertNotNull($this->balise($html, 'name', $balise), "`{$balise}` est absent.");
        }
    }

    // =====================================================================
    // B. Le titre porte l'Organization — sans se repeter
    // =====================================================================

    /** Une Organization distincte de la plateforme s'inscrit dans le titre. */
    public function test_a_distinct_organization_appears_in_the_title(): void
    {
        $html = $this->flowchart($this->organisation('LaunchPals', 'launchpals'));

        $this->assertSame(
            __('flowchart.title').' · LaunchPals | '.config('app.name'),
            $this->titre($html),
        );
    }

    /**
     * Une Organization NOMMEE comme la plateforme ne se repete pas.
     *
     * `brandOrganizationName` retombe sur l'Organization par defaut hors
     * contexte scope, et celle-ci se nomme « BouclePro » : sans garde, le
     * titre rendait « … | BouclePro | BouclePro ».
     */
    public function test_an_organization_named_like_the_platform_is_not_repeated(): void
    {
        $html = $this->flowchart($this->organisation(config('app.name'), 'principale', true));

        $titre = $this->titre($html);

        $this->assertSame(__('flowchart.title').' | '.config('app.name'), $titre);
        $this->assertStringNotContainsString(
            config('app.name').' | '.config('app.name'),
            (string) $titre,
            'Le nom de la plateforme est ecrit deux fois.',
        );
    }

    // =====================================================================
    // C. L'ancien slogan a disparu des sources SERVIES
    // =====================================================================

    /**
     * Plus aucune occurrence dans ce que l'application rend.
     *
     * On balaye les sources SERVIES — gabarits, code, configuration,
     * traductions — et jamais la documentation ni les archives : `@DOCS/`,
     * `_bash_cyril/` et `_local/captures/` en contiennent legitimement, ils
     * racontent l'histoire du produit et ne sont servis a personne.
     */
    public function test_the_former_positioning_is_gone_from_served_sources(): void
    {
        $racines = ['resources/views', 'resources/js', 'app', 'config', 'lang'];
        $trouves = [];

        foreach ($racines as $racine) {
            $chemin = base_path($racine);

            if (! is_dir($chemin)) {
                continue;
            }

            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($chemin));

            foreach ($fichiers as $fichier) {
                if (! $fichier->isFile()) {
                    continue;
                }

                $contenu = (string) file_get_contents($fichier->getPathname());

                foreach (self::ANCIEN_SLOGAN as $chaine) {
                    if (mb_stripos($contenu, $chaine) !== false) {
                        $trouves[] = str_replace(base_path().'/', '', $fichier->getPathname()).' — « '.$chaine.' »';
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($trouves)), implode("\n", $trouves));
    }

    /** Et rien n'en subsiste dans le HTML rendu. */
    public function test_no_page_still_renders_the_former_positioning(): void
    {
        $html = $this->flowchart($this->organisation('LaunchPals', 'launchpals'));

        foreach (self::ANCIEN_SLOGAN as $chaine) {
            $this->assertStringNotContainsString($chaine, $html, "« {$chaine} » est encore rendu.");
        }
    }

    /**
     * `og:url` n'est JAMAIS deduit de la requete.
     *
     * Une premiere version de ce patch emettait `url()->current()` par defaut.
     * `TASK1145DossierAccessDeniedTest` l'a refusee, et elle avait raison : sur
     * le refus d'acces a un Dossier, l'URL courante PORTE l'identifiant refuse,
     * et le layout le reinjectait dans le HTML.
     *
     * `dossiers/acces-refuse` tient sa promesse par l'ABSENCE DE DONNEE — la
     * vue ne recoit jamais le Dossier. Aller chercher une source ambiante
     * qu'elle n'a pas choisie contournait cette garantie par le bas.
     *
     * Cette garde fige la lecon pour que le raccourci ne revienne pas.
     */
    public function test_the_social_url_is_never_inferred_from_the_request(): void
    {
        $html = $this->flowchart($this->organisation('LaunchPals', 'launchpals'));

        $this->assertNull(
            $this->balise($html, 'property', 'og:url'),
            '`og:url` est emis sans avoir ete declare : le layout lit la requete.',
        );

        // Declaree, elle est rendue — la balise n'est pas supprimee, elle est
        // rendue a la page, qui seule sait si son URL est partageable.
        $rendu = view('layouts.app', ['slot' => '', 'title' => 'Page', 'ogUrl' => 'https://exemple.test/page'])->render();

        $this->assertSame('https://exemple.test/page', $this->balise($rendu, 'property', 'og:url'));
    }

    // =====================================================================
    // D. Une page qui se decrit elle-meme garde sa description
    // =====================================================================

    /**
     * Le repli n'ecrase jamais une valeur posee par la page.
     *
     * Sans cette garde, « unifier » les metadonnees reviendrait a effacer les
     * descriptions specifiques — un article de blog dirait la meme chose que
     * l'accueil.
     */
    public function test_a_page_specific_description_wins_over_the_fallback(): void
    {
        $rendu = view('layouts.app', [
            'slot' => '',
            'title' => 'Page',
            'description' => 'Une description propre a cette page.',
            'ogTitle' => 'Titre social propre',
            'ogDescription' => 'Description sociale propre',
        ])->render();

        $this->assertSame('Une description propre a cette page.', $this->balise($rendu, 'name', 'description'));
        $this->assertSame('Titre social propre', $this->balise($rendu, 'property', 'og:title'));
        $this->assertSame('Description sociale propre', $this->balise($rendu, 'property', 'og:description'));
        $this->assertSame('Description sociale propre', $this->balise($rendu, 'name', 'twitter:description'));
    }
}
