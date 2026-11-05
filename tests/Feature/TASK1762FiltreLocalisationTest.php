<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 10 — le filtre « Localisation » de l'annuaire.
 *
 * Un MENU et non un second champ libre : arbitrage de Cyril, « j'ai peur que
 * les gens ne trouvent pas le fait qu'on puisse chercher par ville ».
 */
class TASK1762FiltreLocalisationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-ville', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id, 'city' => null]);
    }

    private function membre(string $prenom, string $nom, ?string $ville): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => $prenom, 'name' => $nom, 'city' => $ville,
            'approved_at' => now(),
        ]);
    }

    private function page(array $parametres = []): string
    {
        $url = route('organization.members.index', $this->org);

        if ($parametres !== []) {
            $url .= '?'.http_build_query($parametres);
        }

        return $this->actingAs($this->visiteur)->get($url)->assertOk()->getContent();
    }

    // ── Le menu ──────────────────────────────────────────────────────────────

    /** Le menu liste les villes du parc, avec leur nombre. */
    public function test_le_menu_liste_les_villes_avec_leur_nombre(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Jean', 'DUPONT', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $html = $this->page();

        $this->assertStringContainsString('data-directory-city', $html);
        $this->assertStringContainsString('Marseille (2)', $html);
        $this->assertStringContainsString('Menton (1)', $html);
        $this->assertStringContainsString(__('directory.city_all'), $html);
    }

    /**
     * 🔴 « Marseille » et « MARSEILLE » ne font qu'UNE entree.
     *
     * Le jumeau porte les deux graphies, saisies a la main par des gens
     * differents. Un `GROUP BY city` brut les listerait toutes les deux, et
     * chacune ne montrerait qu'une partie des membres de la ville.
     */
    public function test_les_graphies_d_une_meme_ville_ne_font_qu_une_entree(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Jean', 'DUPONT', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'MARSEILLE');

        // Une SECONDE ville, sans quoi le menu ne s'afficherait pas du tout —
        // la regle « pas de menu pour une seule ville » s'appliquerait, et ce
        // test mesurerait son absence au lieu du regroupement.
        $this->membre('Luc', 'BERNARD', 'Menton');

        $html = $this->page();

        // Une seule entree pour Marseille, et elle porte le total des TROIS.
        $this->assertStringContainsString('Marseille (3)', $html);
        $this->assertStringNotContainsString('MARSEILLE (', $html);
        $this->assertSame(3, substr_count($html, '<option'), 'Il ne doit y avoir que « toutes », « Marseille » et « Menton ».');
    }

    /** Un parc d'une seule ville n'a pas besoin de menu. */
    public function test_pas_de_menu_quand_il_n_y_a_qu_une_ville(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Jean', 'DUPONT', 'Marseille');

        $this->assertStringNotContainsString('data-directory-city', $this->page());
    }

    // ── Le filtre ────────────────────────────────────────────────────────────

    /** 🔴 Choisir une ville borne la liste. */
    public function test_choisir_une_ville_borne_la_liste(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $html = $this->page(['city' => 'Marseille']);

        $this->assertStringContainsString('Camille MOREAU', $html);
        $this->assertStringNotContainsString('Zoe LEROY', $html);
    }

    /** 🔴 Et il rend AUSSI les autres graphies de la meme ville. */
    public function test_le_filtre_rend_les_autres_graphies(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Jean', 'DUPONT', 'MARSEILLE');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $html = $this->page(['city' => 'Marseille']);

        $this->assertStringContainsString('Camille MOREAU', $html);
        $this->assertStringContainsString('Jean DUPONT', $html);
        $this->assertStringNotContainsString('Zoe LEROY', $html);
    }

    /** 🔴 Les deux filtres se COMBINENT au lieu de s'annuler. */
    public function test_la_ville_et_la_recherche_se_combinent(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Jean', 'DUPONT', 'Marseille');
        $this->membre('Camille', 'BERNARD', 'Menton');

        $html = $this->page(['city' => 'Marseille', 'q' => 'Camille']);

        $this->assertStringContainsString('Camille MOREAU', $html);
        $this->assertStringNotContainsString('Jean DUPONT', $html);
        $this->assertStringNotContainsString('Camille BERNARD', $html);
    }

    /**
     * 🔴 Une ville FORGEE dans l'URL ne filtre rien.
     *
     * Elle doit se lire « aucun filtre » et non « aucun membre » : une liste
     * vide sans explication se lit comme une panne.
     */
    public function test_une_ville_forgee_ne_vide_pas_la_liste(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $html = $this->page(['city' => 'Atlantide']);

        $this->assertStringContainsString('Camille MOREAU', $html);
        $this->assertStringContainsString('Zoe LEROY', $html);
    }

    /** « Effacer » apparait aussi quand SEULE la ville est choisie. */
    public function test_effacer_apparait_avec_le_seul_filtre_de_ville(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $this->assertStringContainsString(__('directory.search_clear'), $this->page(['city' => 'Marseille']));
        $this->assertStringNotContainsString(__('directory.search_clear'), $this->page());
    }

    /** La ville choisie reste selectionnee apres le rechargement. */
    public function test_la_ville_choisie_reste_selectionnee(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $html = $this->page(['city' => 'Menton']);

        $this->assertMatchesRegularExpression('/<option value="Menton"[^>]*selected/', $html);
    }

    /**
     * 🔴 TASK-1762 point 11.3 — « Effacer » est un BOUTON, en « Alerte douce ».
     *
     * La couleur vient du jeton `warning` du theme (`#FFB24D` sur Zen).
     * L'ecrire en dur aurait fige la couleur d'un theme sur tous les autres —
     * la meme lecon qu'au point 4 pour `--bp-accent`.
     */
    public function test_effacer_est_un_bouton_en_alerte_douce(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $html = $this->page(['city' => 'Marseille']);

        // La balise ENTIERE, et non « class puis le marqueur » : l'ordre des
        // attributs dans le HTML n'est pas une propriete du produit, et une
        // assertion qui en depend rougit a la premiere reecriture de la vue.
        preg_match('/<a[^>]*data-directory-clear[^>]*>/', $html, $m);

        $this->assertNotEmpty($m, 'Le bouton « Effacer » est introuvable.');

        $balise = $m[0];

        $this->assertStringContainsString('bg-[var(--bp-warning)]', $balise);
        $this->assertStringNotContainsString('#', $balise, 'La couleur ne doit pas etre ecrite en dur.');

        // Un bouton, pas un lien en texte nu.
        $this->assertStringContainsString('rounded-lg', $balise);
        $this->assertStringContainsString('px-4', $balise);
    }

    /**
     * 🔴 TASK-1762 point 11.1 — TROIS colonnes au maximum sur grand ecran.
     *
     * Demande de Cyril : « ainsi cela sera plus aere ». Le palier `xl` a ete
     * retire ; le laisser revenir est exactement le genre de regression qu'une
     * retouche de grille reintroduit sans qu'on la voie.
     */
    public function test_la_grille_ne_depasse_pas_trois_colonnes(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');

        $html = $this->page();

        $this->assertStringContainsString('lg:grid-cols-3', $html);
        $this->assertStringNotContainsString('xl:grid-cols-4', $html);
    }

    /** 🔴 Le menu ne traverse pas les Organizations. */
    public function test_le_menu_ne_traverse_pas_les_organizations(): void
    {
        $this->membre('Camille', 'MOREAU', 'Marseille');
        $this->membre('Zoe', 'LEROY', 'Menton');

        $autre = Organization::factory()->create(['slug' => 'org-1762-voisine-ville', 'is_active' => true]);
        User::factory()->create([
            'organization_id' => $autre->id, 'approved_at' => now(),
            'first_name' => 'Luc', 'name' => 'AILLEURS', 'city' => 'Bruxelles',
        ]);

        $html = $this->page();

        $this->assertStringNotContainsString('Bruxelles', $html);
    }
}
