<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 F2 — la section « Competences » s'affichait sans aucun champ.
 *
 * Recette OFSH du 25/10, formulaire « Proposer de l'aide » : le titre
 * « Competences » apparaissait, suivi de rien.
 *
 * Meme forme de defaut que F1 : le titre etait pose AU-DESSUS de la boucle des
 * categories et montre des qu'une categorie etait choisie, sans demander si
 * CETTE categorie avait des competences. Verifie en base au moment de la
 * recette : la categorie « Principal » d'OFSH en comptait **0**, quand chaque
 * categorie des autres Organizations en a 5 a 7.
 *
 * ## Une difference avec F1, et elle compte
 *
 * Les competences SONT gerables depuis l'administration de l'Organization
 * (`organization.admin.categories.skills.store`). Le bloc n'est donc pas une
 * UI morte comme celle des fourchettes : il apparaitra des que l'administrateur
 * d'OFSH en aura ajoute. C'est exactement ce que mesure le second test.
 *
 * La mesure porte sur **de quoi depend le titre** et non sur le comptage du
 * balisage : c'est la lecon du faux vert de F1, ou une garde calee sur le
 * marqueur que je venais d'introduire est restee verte sur le code defectueux.
 */
class TASK1707SkillsBlockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Category $sansCompetence;

    private Category $avecCompetences;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1707-skills',
            'is_active' => true,
            'is_default' => true,
        ]);

        // La situation d'OFSH : une categorie neuve, aucune competence.
        $this->sansCompetence = Category::factory()->create([
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Principal',
            'slug' => 'principal-1707-skills',
        ]);

        $this->avecCompetences = Category::factory()->create([
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Accompagnement',
            'slug' => 'accompagnement-1707-skills',
        ]);

        Skill::create([
            'category_id' => $this->avecCompetences->id,
            'organization_id' => $this->organization->id,
            'name' => 'Audit Qualiopi',
            'slug' => 'audit-qualiopi-1707',
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

    private function formulaire(): string
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->membre)
            ->get(route('services.create'))
            ->assertOk()
            ->getContent();
    }

    public function test_le_titre_depend_de_la_categorie_et_non_du_seul_choix(): void
    {
        $html = $this->formulaire();
        $attendu = preg_quote(e(__('marketplace.skills')), '/');

        $this->assertSame(
            1,
            preg_match('/data-category-skills="'.$this->avecCompetences->id.'"[^>]*>\s*<label[^>]*>\s*'.$attendu.'/', $html),
            'Le titre n\'est pas porte par le bloc de la categorie qui a des competences.'
        );

        $this->assertSame(
            0,
            preg_match('/x-show="selectedCategory"[^>]*>\s*<label[^>]*>\s*'.$attendu.'/', $html),
            'Le titre depend encore du seul fait qu\'une categorie soit choisie : il s\'affichera suivi de rien.'
        );

        $this->assertSame(
            0,
            preg_match('/data-category-skills="'.$this->sansCompetence->id.'"/', $html),
            'Une categorie sans competence produit encore son bloc.'
        );

        $this->assertSame(
            1,
            preg_match_all('/'.$attendu.'/', $html),
            'Le titre apparait plus de fois qu\'il n\'y a de blocs de competences.'
        );
    }

    /**
     * La categorie qui en a montre ses cases a cocher.
     *
     * Sans ce volet, supprimer le bloc entierement passerait le test precedent.
     */
    public function test_une_categorie_avec_competences_montre_ses_cases(): void
    {
        $html = $this->formulaire();

        $this->assertSame(
            1,
            preg_match('/<div[^>]*data-category-skills="'.$this->avecCompetences->id.'"[^>]*>(.*?)\n\s*<\/div>\s*\n\s*<\/div>/s', $html, $bloc),
            'Le bloc de la categorie qui a des competences n\'est pas servi.'
        );

        $this->assertStringContainsString('Audit Qualiopi', $bloc[1]);
        $this->assertStringContainsString('name="skills[]"', $bloc[1], 'La case a cocher n\'est pas servie.');
        $this->assertStringContainsString('value="'.Skill::first()->id.'"', $bloc[1]);
    }

    /**
     * Et le bloc reste reserve a la categorie choisie.
     *
     * Le titre ayant ete duplique dans chaque bloc, c'est la seule chose qui
     * empeche deux jeux de competences de s'afficher ensemble.
     */
    public function test_le_bloc_reste_conditionne_a_la_categorie_choisie(): void
    {
        $this->assertSame(
            1,
            preg_match(
                '/data-category-skills="'.$this->avecCompetences->id.'"\s+x-show="selectedCategory === \''.$this->avecCompetences->id.'\'"/',
                $this->formulaire()
            ),
            'Le bloc n\'est plus conditionne a la categorie choisie : il s\'afficherait pour toutes.'
        );
    }
}
