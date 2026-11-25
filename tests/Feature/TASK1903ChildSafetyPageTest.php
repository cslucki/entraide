<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1903 — centre institutionnel unique « Informations legales & securite »
 * (/mentions-legales) : mentions legales, confidentialite, suppression de
 * compte et normes Securite des enfants (CSAE, Google Play).
 *
 * Les trois URL declarees a Google Play (/confidentialite,
 * /suppression-de-compte, /securite-enfants) repondent 200 DIRECTEMENT, sans
 * redirection ni JavaScript, en « mode cible » : la rubrique visee est le <h1>,
 * le <title>, et la premiere rubrique de la page. Le pied de page n'a plus
 * qu'un lien institutionnel, et le lien des bugs est distinct.
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

    public function test_le_centre_regroupe_les_quatre_rubriques_et_mene_aux_url_reelles(): void
    {
        $html = $this->withHeader('Accept-Language', 'fr')->get('/mentions-legales')->assertOk()->getContent();

        // Le sommaire mene aux URL REELLES (celles de Google Play), jamais a une ancre.
        foreach (['mentions' => 'mentions-legales', 'confidentialite' => 'confidentialite', 'suppression-compte' => 'suppression-de-compte', 'securite-enfants' => 'securite-enfants'] as $rubrique => $route) {
            $this->assertStringContainsString('data-legal-rubric="'.$rubrique.'"', $html, "rubrique {$rubrique}");
            $this->assertStringContainsString('href="'.route($route).'" data-legal-toc="'.$rubrique.'"', $html, "sommaire {$rubrique}");
        }
        $this->assertDoesNotMatchRegularExpression('#href="\#[a-z]#', $html, 'aucun lien par ancre');

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

            // La rubrique visee est la PREMIERE rubrique rendue (lisible sans script).
            preg_match('#data-legal-rubric="([^"]+)"#', $html, $premiere);
            $this->assertSame($ancre, $premiere[1] ?? null, "[{$route} {$langue}] premiere rubrique");

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

    public function test_la_suppression_de_compte_montre_ses_etapes_en_premier(): void
    {
        app()->setLocale('fr');
        $html = $this->get('/suppression-de-compte')->assertOk()->getContent();

        $etape = e(__('account_deletion.sections.0.steps.0'));
        $confidentialite = 'id="confidentialite"';

        $this->assertStringContainsString($etape, $html);
        $this->assertLessThan(strpos($html, $confidentialite), strpos($html, $etape), 'les etapes doivent preceder les autres rubriques');
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
