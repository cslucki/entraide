<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\PointGuideline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 F1 — un titre de fourchettes suivi de RIEN.
 *
 * Recette OFSH du 25/10, sur les deux formulaires :
 *
 * - « Demander de l'aide » : « Fourchettes indicatives par niveau : »
 * - « Proposer de l'aide » : « Fourchettes recommandees pour cette categorie : »
 *
 * Le titre etait pose AU-DESSUS de la boucle des categories et montre des
 * qu'une categorie etait choisie (`x-show="selectedCategory"`), sans jamais
 * demander si CETTE categorie avait des fourchettes.
 *
 * ## Ce que la base disait au moment de la recette
 *
 * `point_guidelines` comptait **0 ligne**, pour toutes les Organizations.
 * Le seul code qui en cree est `PointGuidelineSeeder`, cale sur huit slugs
 * (`tech-digital`, `design`, …) qui n'existent dans AUCUNE Organization de
 * cette base — et il n'y a **aucune route** pour en gerer. Le bloc etait donc
 * un titre vide partout, et pas seulement chez OFSH.
 *
 * ## Le correctif
 *
 * Le titre descend DANS le bloc de chaque categorie, et une categorie sans
 * fourchette n'en produit aucun. L'absence est decidee au SERVEUR : il n'y a
 * plus de balisage a cacher. C'est ce qui rend ce fichier mesurable — un
 * `x-show` laisse le balisage en place, et une garde qui se contenterait de
 * lire le DOM resterait verte (memoire « asserter le balisage ne protege pas la
 * garde qui le conditionne »).
 */
class TASK1707PointRangesBlockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Category $sansFourchette;

    private Category $avecFourchettes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1707-ranges',
            'is_active' => true,
            'is_default' => true,
        ]);

        // La situation d'OFSH : une categorie neuve, aucune fourchette.
        $this->sansFourchette = Category::factory()->create([
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Principal',
            'slug' => 'principal-1707',
        ]);

        $this->avecFourchettes = Category::factory()->create([
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Accompagnement',
            'slug' => 'accompagnement-1707',
        ]);

        PointGuideline::create([
            'category_id' => $this->avecFourchettes->id,
            'organization_id' => $this->organization->id,
            'level' => 'standard',
            'points_min' => 60,
            'points_max' => 80,
            'duration_label' => '30 a 45 min',
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    private function formulaire(string $route): string
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->membre)
            ->get(route($route))
            ->assertOk()
            ->getContent();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function formulaires(): array
    {
        return [
            'demander de l\'aide' => ['requests.create', 'marketplace.indicative_ranges_level'],
            'proposer de l\'aide' => ['services.create', 'marketplace.recommended_ranges_category'],
        ];
    }

    /**
     * Le titre vit DANS le bloc d'une categorie qui a des fourchettes.
     *
     * ## Pourquoi la mesure est posee comme ca, et pas autrement
     *
     * Ma premiere version de cette garde comptait les blocs `data-point-ranges`
     * et les occurrences du titre. Elle est restee VERTE quand j'ai remis la
     * structure defectueuse d'origine — un faux vert sur le defaut meme que ce
     * correctif adresse : sans marqueur dans l'ancien balisage, « 0 bloc pour
     * la categorie vide » etait vrai pour rien, et le titre y apparaissait deja
     * exactement une fois.
     *
     * Ce qui distingue reellement les deux etats, c'est **de quoi depend le
     * titre** : d'une categorie NOMMEE qui a des fourchettes, ou du seul fait
     * qu'une categorie soit choisie. Les deux assertions mesurent donc
     * l'adjacence du titre et de la condition qui le gouverne — ce qui ne
     * depend d'aucune imbrication HTML.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formulaires')]
    public function test_le_titre_depend_de_la_categorie_et_non_du_seul_choix(string $route, string $titre): void
    {
        $html = $this->formulaire($route);
        $attendu = preg_quote(e(__($titre)), '/');

        $this->assertSame(
            1,
            preg_match('/data-point-ranges="'.$this->avecFourchettes->id.'"[^>]*>\s*<p[^>]*>\s*'.$attendu.'/', $html),
            'Le titre n\'est pas porte par le bloc de la categorie qui a des fourchettes.'
        );

        $this->assertSame(
            0,
            preg_match('/x-show="selectedCategory"[^>]*>\s*<p[^>]*>\s*'.$attendu.'/', $html),
            'Le titre depend encore du seul fait qu\'une categorie soit choisie : il s\'affichera suivi de rien.'
        );

        $this->assertSame(
            0,
            preg_match('/data-point-ranges="'.$this->sansFourchette->id.'"/', $html),
            'Une categorie sans fourchette produit encore son bloc.'
        );

        // Un titre par bloc, et un bloc par categorie qui en a : ici une seule.
        $this->assertSame(
            1,
            preg_match_all('/'.$attendu.'/', $html),
            'Le titre apparait plus de fois qu\'il n\'y a de blocs de fourchettes.'
        );
    }

    /**
     * La categorie qui en a montre bien ses fourchettes.
     *
     * Sans ce volet, supprimer le bloc entierement passerait le test precedent.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formulaires')]
    public function test_une_categorie_avec_fourchettes_montre_les_siennes(string $route, string $titre): void
    {
        $html = $this->formulaire($route);

        $this->assertSame(
            1,
            preg_match('/<div[^>]*data-point-ranges="'.$this->avecFourchettes->id.'"[^>]*>(.*?)\n\s*<\/div>\s*\n\s*<\/div>/s', $html, $bloc),
            'Le bloc de la categorie qui a des fourchettes n\'est pas servi.'
        );

        $this->assertStringContainsString(e(__($titre)), $bloc[1]);
        $this->assertStringContainsString('Standard', $bloc[1], 'Le niveau n\'est pas affiche.');
        $this->assertStringContainsString('60', $bloc[1]);
        $this->assertStringContainsString('80', $bloc[1]);
        $this->assertStringContainsString('30 a 45 min', $bloc[1]);
    }

    /**
     * Le bloc reste reserve a la categorie choisie.
     *
     * Le titre ayant ete DUPLIQUE dans chaque bloc, c'est maintenant la seule
     * chose qui empeche deux jeux de fourchettes de s'afficher ensemble.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formulaires')]
    public function test_le_bloc_reste_conditionne_a_la_categorie_choisie(string $route, string $titre): void
    {
        $html = $this->formulaire($route);

        $this->assertSame(
            1,
            preg_match(
                '/data-point-ranges="'.$this->avecFourchettes->id.'"\s+x-show="selectedCategory === \''.$this->avecFourchettes->id.'\'"/',
                $html
            ),
            'Le bloc n\'est plus conditionne a la categorie choisie : il s\'afficherait pour toutes.'
        );
    }
}
