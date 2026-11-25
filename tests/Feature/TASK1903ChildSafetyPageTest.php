<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1903 — la page « Securite des enfants » (normes CSAE exigees par Google
 * Play) est publique, en francais et en anglais, servie en global ET dans le
 * contexte d'une Organization, reliee depuis les pieds de page et les mentions
 * legales, et n'affirme aucun mecanisme qui n'existe pas encore (S2a / S2b).
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

    /** @return array<string, array{string, string, string}> */
    public static function langues(): array
    {
        return [
            'fr' => ['fr', 'exploitation et d’abus sexuels sur mineurs', '18 ans'],
            'en' => ['en', 'child sexual abuse and exploitation (CSAE)', 'aged 18'],
        ];
    }

    #[DataProvider('langues')]
    public function test_la_page_globale_est_publique_et_porte_les_mentions_exigees(string $langue, string $interdiction, string $age): void
    {
        $page = $this->withHeader('Accept-Language', $langue)
            ->get('/securite-enfants')
            ->assertOk();

        app()->setLocale($langue);
        $page->assertSee(__('child_safety.heading'))
            ->assertSee('BouclePro')
            ->assertSee('AMT')
            ->assertSee($interdiction)
            ->assertSee($age)
            ->assertSee('PHAROS')
            ->assertSee('href="mailto:cslucki@gmail.com"', false)
            ->assertSee('id="signaler"', false)
            ->assertSee('href="#signaler"', false);
    }

    #[DataProvider('langues')]
    public function test_la_page_est_aussi_publique_dans_le_contexte_de_l_organization(string $langue, string $interdiction, string $age): void
    {
        // Dans le contexte d'une Organization, SetLocale fait primer la langue
        // de l'Organization sur celle du navigateur.
        $this->organization->update(['locale' => $langue]);

        $this->get(route('organization.securite-enfants', $this->organization))
            ->assertOk()
            ->assertSee('data-legal-page="child-safety"', false)
            ->assertSee($interdiction)
            ->assertSee('cslucki@gmail.com');
    }

    public function test_la_page_n_affirme_aucun_mecanisme_inexistant(): void
    {
        foreach (['fr', 'en'] as $langue) {
            $texte = mb_strtolower(json_encode(require lang_path("{$langue}/child_safety.php"), JSON_UNESCAPED_UNICODE));

            foreach (['automatiquement', 'automatique', 'automatic', 'détection', 'detection', '24 heures', '24 hours', '48', 'garanti', 'guarantee'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $texte, "[{$langue}] affirmation interdite : {$interdit}");
            }
        }
    }

    public function test_les_pieds_de_page_et_les_mentions_legales_menent_a_la_page(): void
    {
        $mentions = $this->get(route('mentions-legales'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('securite-enfants').'"', $mentions);

        $scoped = $this->get(route('organization.mentions-legales', $this->organization))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('organization.securite-enfants', $this->organization).'"', $scoped);

        $accueil = $this->get(route('organization.home', $this->organization))->assertOk()->getContent();
        $this->assertStringContainsString('data-footer-child-safety', $accueil);
        $this->assertStringContainsString('href="'.route('organization.securite-enfants', $this->organization).'"', $accueil);
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
    public function test_le_pied_de_chaque_vitrine_mene_a_la_page(string $gabarit, string $suffixe): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'vitrine-1903', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
            'homepage_template' => $gabarit,
        ]);

        $html = $this->get('/org/vitrine-1903'.$suffixe)->assertOk()->getContent();

        $this->assertStringContainsString('data-footer-child-safety', $html, "[{$gabarit}{$suffixe}] lien absent.");
        $this->assertStringContainsString('href="'.route('organization.securite-enfants', $organization).'"', $html);
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
    }
}
