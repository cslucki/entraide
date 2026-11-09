<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1788, correctif 01 — l'option « Proposer cette action sous forme
 * d'atelier » est MASQUEE sur les formulaires de proposition d'aide.
 *
 * Deux choses a prouver, et la seconde est celle qui pouvait couter cher :
 *
 * 1. l'option et sa zone ne sont plus rendues (creation ET modification) ;
 * 2. **un atelier DEJA cree ne perd pas sa capacite** quand on enregistre le
 *    formulaire de modification. Le formulaire n'envoie plus `capacity` ni
 *    `min_participants` ; avec l'ancien `?? null` du controleur, modifier le
 *    titre d'un atelier l'aurait silencieusement rendu individuel.
 *
 * Toute assertion d'ABSENCE porte ici un TEMOIN DE RENDU : sans lui, une page
 * vide, une 500 ou une redirection rendraient le test vert en ne contenant
 * simplement rien (lecon T1786 — `assertSee()` ne dit jamais ce qui est en
 * trop, et une absence ne dit jamais que la page a ete rendue).
 */
class TASK1788Correctif01AtelierMasqueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aidant;

    private Category $categorie;

    /** Les marqueurs du composant retire : aucun n'a d'apostrophe, donc aucun
     *  ne depend de l'echappement HTML. */
    private const MARQUEURS = [
        'data-entraide-champs-atelier',
        'data-entraide-atelier-bascule',
        'data-entraide-atelier-capacite',
        'data-entraide-atelier-plancher',
        'data-entraide-atelier-apercu',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // `locale` explicite : `SetLocale` passe par l'Organization (lecon T1755).
        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->categorie = Category::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function annonce(?int $capacite, ?int $plancher): Service
    {
        return Service::factory()->create([
            'user_id' => $this->aidant->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie->id,
            'status' => 'active',
            'points_cost' => 60,
            'capacity' => $capacite,
            'min_participants' => $plancher,
        ]);
    }

    /**
     * Le libelle, dans ses DEUX formes.
     *
     * « Proposer cette action sous forme d'atelier » porte une apostrophe :
     * Blade la rend `&#039;`. Chercher la seule forme brute ferait passer le
     * test meme si la case etait encore la (lecon T1787/T1786).
     */
    private function assertOptionAbsente(string $page, string $ou): void
    {
        $libelle = __('entraide.atelier.champ_label');

        $this->assertStringNotContainsString($libelle, $page, "Le libelle brut de l'option est encore rendu sur {$ou}.");
        $this->assertStringNotContainsString(e($libelle), $page, "Le libelle echappe de l'option est encore rendu sur {$ou}.");
        $this->assertStringNotContainsString(__('entraide.atelier.champ_aide'), $page, "L'aide de la zone atelier est encore rendue sur {$ou}.");
        $this->assertStringNotContainsString(e(__('entraide.atelier.champ_aide')), $page, "L'aide echappee de la zone atelier est encore rendue sur {$ou}.");

        foreach (self::MARQUEURS as $marqueur) {
            $this->assertStringNotContainsString($marqueur, $page, "Le marqueur `{$marqueur}` est encore rendu sur {$ou}.");
        }
    }

    public function test_l_option_atelier_n_apparait_plus_sur_le_formulaire_de_proposition(): void
    {
        $page = $this->actingAs($this->aidant)
            ->get(route('services.create'))
            ->assertOk()
            ->getContent();

        // TEMOIN : le champ de duree est le voisin IMMEDIAT de la zone retiree.
        // S'il est la, c'est bien cette region du formulaire qui a ete rendue.
        $this->assertStringContainsString('data-duration-equivalent', $page,
            'Temoin absent : la region du formulaire ou vivait l\'option n\'a pas ete rendue, l\'absence ne prouve donc rien.');

        $this->assertOptionAbsente($page, 'le formulaire de proposition');
    }

    public function test_l_option_atelier_n_apparait_plus_sur_le_formulaire_de_modification(): void
    {
        $atelier = $this->annonce(6, 3);

        $page = $this->actingAs($this->aidant)
            ->get(route('services.edit', $atelier))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-duration-equivalent', $page,
            'Temoin absent : la region du formulaire ou vivait l\'option n\'a pas ete rendue.');

        $this->assertOptionAbsente($page, 'le formulaire de modification');
    }

    /**
     * ⚠️ LE test du correctif.
     *
     * Le formulaire n'envoie plus la capacite. Si le controleur retombait sur
     * `null`, modifier le seul titre d'un atelier le transformerait en aide
     * individuelle — sans message, sans trace, et le prix facture aux inscrits
     * changerait avec.
     */
    public function test_un_atelier_existant_garde_sa_capacite_quand_on_modifie_son_titre(): void
    {
        $atelier = $this->annonce(6, 3);

        $this->actingAs($this->aidant)
            ->put(route('services.update', $atelier), [
                'title' => 'Un titre tout neuf et suffisamment long',
                'description' => str_repeat('Une description qui depasse la centaine de caracteres exigee. ', 3),
                'category_id' => $this->categorie->id,
                'delivery_mode' => 'remote',
                'points_cost' => 60,
                'status' => 'active',
                // ni `capacity`, ni `min_participants` : c'est exactement ce que
                // poste desormais le formulaire.
            ])
            ->assertSessionHasNoErrors();

        $atelier->refresh();

        $this->assertSame('Un titre tout neuf et suffisamment long', $atelier->title, 'La modification n\'a pas ete enregistree : le reste du test ne prouverait rien.');
        $this->assertSame(6, $atelier->capacity, 'L\'atelier a perdu sa capacite en enregistrant une simple modification de titre.');
        $this->assertSame(3, $atelier->min_participants, 'L\'atelier a perdu son plancher en enregistrant une simple modification de titre.');
    }

    /**
     * Le pendant du precedent : conserver ne doit pas INVENTER.
     *
     * Une aide individuelle n'a pas de capacite, et n'en gagne pas en passant
     * par le formulaire.
     */
    public function test_une_aide_individuelle_reste_individuelle_apres_modification(): void
    {
        $individuelle = $this->annonce(null, null);

        $this->actingAs($this->aidant)
            ->put(route('services.update', $individuelle), [
                'title' => 'Un autre titre tout neuf et assez long',
                'description' => str_repeat('Une description qui depasse la centaine de caracteres exigee. ', 3),
                'category_id' => $this->categorie->id,
                'delivery_mode' => 'remote',
                'points_cost' => 60,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $individuelle->refresh();

        $this->assertNull($individuelle->capacity, 'Une aide individuelle a gagne une capacite qu\'on ne lui a jamais donnee.');
        $this->assertNull($individuelle->min_participants, 'Une aide individuelle a gagne un plancher qu\'on ne lui a jamais donne.');
    }

    /**
     * Le commentaire Blade qui explique le retrait ne doit pas S'AFFICHER.
     *
     * Defaut deja vu en T1786 : un `--}` au lieu de `--}}` avait fait rendre un
     * commentaire entier a l'ecran, et 37 tests verts ne l'avaient pas vu parce
     * qu'aucun ne mesurait ce qui etait EN TROP.
     */
    public function test_aucun_commentaire_de_code_ne_fuit_dans_les_deux_formulaires(): void
    {
        $atelier = $this->annonce(6, 3);

        foreach ([
            'proposition' => route('services.create'),
            'modification' => route('services.edit', $atelier),
        ] as $ou => $url) {
            $page = $this->actingAs($this->aidant)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-duration-equivalent', $page, "Temoin absent sur le formulaire de {$ou}.");
            $this->assertStringNotContainsString('TASK-1788', $page, "Le commentaire de code s'affiche sur le formulaire de {$ou}.");
            $this->assertStringNotContainsString('{{--', $page, "Un commentaire Blade non ferme fuit sur le formulaire de {$ou}.");
        }
    }
}
