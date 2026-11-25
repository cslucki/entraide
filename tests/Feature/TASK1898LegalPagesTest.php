<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1898 — les deux pages exigees par Google Play (politique de
 * confidentialite, suppression de compte) sont publiques, en francais et en
 * anglais, servies en global ET dans le contexte d'une Organization, et
 * reliees depuis le pied de page et les mentions legales.
 */
class TASK1898LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1898', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function pages(): array
    {
        return [
            'confidentialite fr' => ['confidentialite', 'fr'],
            'confidentialite en' => ['confidentialite', 'en'],
            'suppression fr' => ['suppression-de-compte', 'fr'],
            'suppression en' => ['suppression-de-compte', 'en'],
        ];
    }

    private static function fichier(string $route): string
    {
        return $route === 'confidentialite' ? 'privacy' : 'account_deletion';
    }

    #[DataProvider('pages')]
    public function test_la_page_globale_est_publique_et_traduite(string $route, string $langue): void
    {
        app()->setLocale($langue);
        $titre = __(self::fichier($route).'.title');

        $this->withHeader('Accept-Language', $langue)
            ->get(route($route))
            ->assertOk()
            ->assertSee($titre)
            ->assertSee(__(self::fichier($route).'.sections.0.title'));
    }

    #[DataProvider('pages')]
    public function test_la_page_est_aussi_publique_dans_le_contexte_de_l_organization(string $route, string $langue): void
    {
        $this->get(route('organization.'.$route, $this->organization))
            ->assertOk()
            ->assertSee('data-legal-page', false);
    }

    public function test_la_page_de_suppression_decrit_le_parcours_reel_sans_promettre_d_effacement_automatique(): void
    {
        app()->setLocale('fr');

        $page = $this->get(route('suppression-de-compte'))->assertOk();

        // Les libelles du VRAI formulaire de fermeture.
        $page->assertSee(__('profile.security_title'));
        $page->assertSee(__('profile.close_account'));
        $page->assertSee(__('profile.close_account_erasure'));
        $page->assertDontSee('automatiquement supprim');
    }

    public function test_le_pied_de_page_et_les_mentions_legales_menent_aux_deux_pages(): void
    {
        // TASK-1903 — les deux contenus sont desormais des RUBRIQUES du centre
        // institutionnel (/mentions-legales), reliees par ancres ; le pied de
        // page n'a plus qu'un lien, vers ce centre.
        foreach ([route('mentions-legales'), route('organization.mentions-legales', $this->organization)] as $url) {
            $centre = $this->get($url)->assertOk()->getContent();
            foreach (['confidentialite', 'suppression-compte'] as $ancre) {
                $this->assertStringContainsString('id="'.$ancre.'"', $centre);
                $this->assertStringContainsString('href="#'.$ancre.'"', $centre);
            }
        }

        // Le pied de page vit sur l'accueil public de l'Organization (et les
        // pages invitees), pas dans `x-app-layout` : on le lit la ou il est.
        $accueil = $this->get(route('organization.home', $this->organization))->assertOk()->getContent();
        $this->assertStringContainsString('data-footer-legal-center', $accueil);
        $this->assertStringContainsString('href="'.route('organization.mentions-legales', $this->organization).'"', $accueil);
    }

    public function test_les_textes_francais_et_anglais_ont_la_meme_structure(): void
    {
        foreach (['privacy', 'account_deletion'] as $fichier) {
            $fr = require lang_path("fr/{$fichier}.php");
            $en = require lang_path("en/{$fichier}.php");

            $this->assertSame(array_keys($fr), array_keys($en), $fichier);
            $this->assertCount(count($fr['sections']), $en['sections'], $fichier);

            foreach ($fr['sections'] as $i => $section) {
                $this->assertSame(array_keys($section), array_keys($en['sections'][$i]), "{$fichier} section {$i}");
                foreach (['paragraphs', 'items', 'steps', 'after'] as $bloc) {
                    $this->assertCount(count($section[$bloc] ?? []), $en['sections'][$i][$bloc] ?? [], "{$fichier} section {$i} {$bloc}");
                }
            }
        }
    }
}
