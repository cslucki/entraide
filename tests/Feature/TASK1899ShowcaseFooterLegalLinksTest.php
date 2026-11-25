<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1899 — les vitrines qui portent leur PROPRE pied de page (hero-v2,
 * ofsh-hero, about-ofsh, artscilab-hero) n'offraient que les mentions
 * legales : la politique de confidentialite et la page de suppression de
 * compte (T1898, exigees par Google Play) y sont desormais liees, bornees a
 * l'Organization courante.
 */
class TASK1899ShowcaseFooterLegalLinksTest extends TestCase
{
    use RefreshDatabase;

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
    public function test_le_pied_de_la_vitrine_mene_a_la_confidentialite_et_a_la_suppression(string $gabarit, string $suffixe): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'vitrine-1899', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
            'homepage_template' => $gabarit,
        ]);

        $html = $this->get('/org/vitrine-1899'.$suffixe)->assertOk()->getContent();

        // TASK-1903 — un seul lien, vers le centre institutionnel qui contient
        // les rubriques confidentialite et suppression de compte.
        $this->assertStringContainsString('data-footer-legal-center', $html, "[{$gabarit}{$suffixe}] lien institutionnel absent.");
        $this->assertStringContainsString('href="'.route('organization.mentions-legales', $organization).'"', $html);

        // Les liens ne sortent pas de l'Organization pour un visiteur anonyme.
        $this->get(route('organization.confidentialite', $organization))->assertOk();
        $this->get(route('organization.suppression-de-compte', $organization))->assertOk();
    }
}
