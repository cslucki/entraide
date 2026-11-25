<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1903 — pages legales (decision Cyril : plusieurs pages, une vue).
 *
 * /mentions-legales devient « Informations legales & securite » (mentions
 * legales). /confidentialite, /suppression-de-compte et /securite-enfants — les
 * URL declarees a Google Play — repondent 200 DIRECTEMENT, chacune avec SON seul
 * contenu, son titre en <h1> et en <title>. Chaque page porte en tete le MEME
 * sommaire des quatre pages, par URL reelles (jamais d'ancre). Le pied de page
 * n'a plus qu'un lien institutionnel, et le lien des bugs est distinct.
 */
class TASK1903ChildSafetyPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1903', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);
    }

    /** @return array<string, array{string, string, string}> route, ancre, fichier de langue */
    public static function cibles(): array
    {
        return [
            'confidentialite' => ['confidentialite', 'confidentialite', 'privacy'],
            'suppression' => ['suppression-de-compte', 'suppression-compte', 'account_deletion'],
            'securite enfants' => ['securite-enfants', 'securite-enfants', 'child_safety'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function langues(): array
    {
        return ['fr' => ['fr'], 'en' => ['en']];
    }

    /** Extrait le texte du premier <h1> de la page. */
    private function premierH1(string $html): string
    {
        preg_match_all('#<h1[^>]*>(.*?)</h1>#s', $html, $m);

        // Le layout porte deja un <h1> de marque ; celui de la page est le dernier.
        return trim(html_entity_decode(strip_tags(end($m[1]) ?: ''), ENT_QUOTES));
    }

    /** @return array<string, array{string, string}> URL, rubrique */
    public static function pagesLegales(): array
    {
        return [
            'mentions' => ['/mentions-legales', 'mentions'],
            'confidentialite' => ['/confidentialite', 'confidentialite'],
            'suppression' => ['/suppression-de-compte', 'suppression-compte'],
            'securite enfants' => ['/securite-enfants', 'securite-enfants'],
        ];
    }

    #[DataProvider('pagesLegales')]
    public function test_chaque_page_porte_le_meme_sommaire_par_url_reelles(string $url, string $rubrique): void
    {
        $html = $this->withHeader('Accept-Language', 'fr')->get($url)->assertOk()->getContent();

        app()->setLocale('fr');
        $this->assertStringContainsString(e(__('legal.toc_title')), $html, "[{$url}] titre du sommaire");
        foreach (['mentions' => 'mentions-legales', 'confidentialite' => 'confidentialite', 'suppression-compte' => 'suppression-de-compte', 'securite-enfants' => 'securite-enfants'] as $cible => $route) {
            $this->assertStringContainsString('href="'.route($route).'" data-legal-toc="'.$cible.'"', $html, "[{$url}] sommaire {$cible}");
        }
        $this->assertSame(1, substr_count($html, 'aria-current="page"'), "[{$url}] une seule page courante");
        $this->assertMatchesRegularExpression('#data-legal-toc="'.$rubrique.'"\s+aria-current="page"#', $html, "[{$url}] page courante");
        $this->assertDoesNotMatchRegularExpression('#href="\#[a-z]#', $html, "[{$url}] aucun lien par ancre");

        // Une page = son SEUL contenu.
        preg_match_all('#data-legal-rubric="([^"]+)"#', $html, $rubriques);
        $this->assertSame([$rubrique], $rubriques[1], "[{$url}] contenu seul");
    }

    public function test_la_page_mentions_legales_s_appelle_informations_legales_et_securite(): void
    {
        $html = $this->withHeader('Accept-Language', 'fr')->get('/mentions-legales')->assertOk()->getContent();

        app()->setLocale('fr');
        $this->assertSame(__('legal.center_title'), $this->premierH1($html));
        $this->assertStringContainsString('data-legal-page="center"', $html);
        // Le titre contient « & » : echappe UNE seule fois (pas de « &amp;amp; »).
        $this->assertMatchesRegularExpression('#<title>\s*'.preg_quote(e(__('legal.center_title')), '#').'#', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    #[DataProvider('cibles')]
    public function test_chaque_url_google_play_repond_200_directement_en_mode_cible(string $route, string $ancre, string $fichier): void
    {
        foreach (['fr', 'en'] as $langue) {
            $reponse = $this->withHeader('Accept-Language', $langue)->get('/'.$route);
            $reponse->assertOk(); // jamais de redirection
            $html = $reponse->getContent();

            app()->setLocale($langue);
            $titre = __($fichier.'.title');

            $this->assertSame($titre, $this->premierH1($html), "[{$route} {$langue}] <h1>");
            $this->assertMatchesRegularExpression('#<title>\s*'.preg_quote(e($titre), '#').'#', $html, "[{$route} {$langue}] <title>");
            $this->assertStringContainsString('data-legal-page="'.$ancre.'"', $html);

            // Une page = son SEUL contenu ; retour vers « Informations legales & securite ».
            preg_match_all('#data-legal-rubric="([^"]+)"#', $html, $rubriques);
            $this->assertSame([$ancre], $rubriques[1], "[{$route} {$langue}] contenu seul");
            $this->assertStringContainsString('href="'.route('mentions-legales').'"', $html);

            // Son contenu complet est present : toutes ses sections.
            foreach (__($fichier.'.sections') as $section) {
                $this->assertStringContainsString(e($section['title']), $html);
            }
        }
    }

    #[DataProvider('cibles')]
    public function test_le_mode_cible_suit_la_langue_de_l_organization(string $route, string $ancre, string $fichier): void
    {
        foreach (['fr', 'en'] as $langue) {
            $this->organization->update(['locale' => $langue]);

            $html = $this->get(route('organization.'.$route, $this->organization))->assertOk()->getContent();

            app()->setLocale($langue);
            $this->assertSame(__($fichier.'.title'), $this->premierH1($html), "[org {$route} {$langue}]");
        }
    }

    #[DataProvider('langues')]
    public function test_les_normes_csae_identifient_bouclepro_et_amt_et_le_contact(string $langue): void
    {
        app()->setLocale($langue);
        $interdiction = $langue === 'fr' ? 'exploitation et d’abus sexuels sur mineurs' : 'child sexual abuse and exploitation (CSAE)';

        $this->withHeader('Accept-Language', $langue)
            ->get('/securite-enfants')
            ->assertOk()
            ->assertSee('BouclePro')
            ->assertSee('Association AMT')
            ->assertSee($interdiction)
            ->assertSee($langue === 'fr' ? '18 ans' : 'aged 18')
            ->assertSee('PHAROS')
            ->assertSee('href="mailto:cslucki@gmail.com"', false)
            ->assertSee('id="signaler"', false);
    }

    public function test_la_suppression_de_compte_montre_ses_etapes(): void
    {
        app()->setLocale('fr');

        $this->withHeader('Accept-Language', 'fr')->get('/suppression-de-compte')->assertOk()
            ->assertSee(__('account_deletion.sections.0.steps.0'))
            ->assertDontSee('id="confidentialite"', false);
    }

    public function test_les_normes_n_affirment_aucun_mecanisme_inexistant(): void
    {
        foreach (['fr', 'en'] as $langue) {
            $texte = mb_strtolower(json_encode(require lang_path("{$langue}/child_safety.php"), JSON_UNESCAPED_UNICODE));

            foreach (['automatiquement', 'automatique', 'automatic', 'détection', 'detection', '24 heures', '24 hours', '48', 'garanti', 'guarantee'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $texte, "[{$langue}] affirmation interdite : {$interdit}");
            }
        }
    }

    /** @return array<string, array{string, string}> */
    public static function vitrines(): array
    {
        return [
            'hero-v2' => ['bouclepro_hero_v2', ''],
            'ofsh-hero' => ['ofsh_hero', ''],
            'about-ofsh' => ['ofsh_hero', '/about'],
            'artscilab-hero' => ['artscilab_hero', ''],
        ];
    }

    #[DataProvider('vitrines')]
    public function test_le_pied_de_chaque_vitrine_n_a_plus_qu_un_lien_institutionnel(string $gabarit, string $suffixe): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'vitrine-1903', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
            'homepage_template' => $gabarit,
        ]);

        $html = $this->get('/org/vitrine-1903'.$suffixe)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-footer-legal-center'), "[{$gabarit}{$suffixe}] un seul lien institutionnel");
        $this->assertStringContainsString('href="'.route('organization.mentions-legales', $organization).'"', $html);
        foreach (['data-footer-privacy', 'data-footer-account-deletion', 'data-footer-child-safety'] as $ancien) {
            $this->assertStringNotContainsString($ancien, $html, "[{$gabarit}{$suffixe}] {$ancien} doit disparaitre");
        }
    }

    public function test_le_pied_global_a_un_lien_institutionnel_et_un_lien_bugs_distinct(): void
    {
        app()->setLocale('fr');
        $html = $this->get(route('organization.home', $this->organization))->assertOk()->getContent();

        $this->assertStringContainsString('data-footer-legal-center', $html);
        $this->assertStringContainsString(e(__('legal.center_title')), $html);
        $this->assertStringContainsString(__('footer.bug_title'), $html);
        $this->assertStringNotContainsString('data-footer-privacy', $html);
    }

    public function test_les_textes_francais_et_anglais_ont_la_meme_structure_et_les_memes_ancres(): void
    {
        $fr = require lang_path('fr/child_safety.php');
        $en = require lang_path('en/child_safety.php');

        $this->assertSame(array_keys($fr), array_keys($en));
        $this->assertSame($fr['contact_email'], $en['contact_email']);
        $this->assertSame(array_column($fr['sections'], 'id'), array_column($en['sections'], 'id'));

        foreach ($fr['sections'] as $i => $section) {
            $this->assertSame(array_keys($section), array_keys($en['sections'][$i]), "section {$i}");
            foreach (['paragraphs', 'items', 'steps', 'after'] as $bloc) {
                $this->assertCount(count($section[$bloc] ?? []), $en['sections'][$i][$bloc] ?? [], "section {$i} {$bloc}");
            }
        }

        $this->assertSame(array_keys(require lang_path('fr/legal.php')), array_keys(require lang_path('en/legal.php')));
    }
}
