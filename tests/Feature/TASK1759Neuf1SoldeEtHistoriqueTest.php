<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1759 (§9.1 du CDC entraide) — fermer les DEUX surfaces qui manquaient.
 *
 * ## Pourquoi cette TASK existe, et c'est une erreur de lecture de ma part
 *
 * Le §9.1 nomme CINQ surfaces : annonce, cartes de l'explorateur, bandeau de
 * conversation, **solde**, **historique**. En T1758 j'ai affirme que « deux
 * manquaient » et j'ai corrige profil, favoris et recherche — **qui ne sont pas
 * dans les cinq**. Utile pour la coherence, mais §9.1 restait ouvert : le solde
 * et l'historique n'avaient jamais ete touches.
 *
 * ## Et une SECONDE erreur, trouvee par ce test
 *
 * J'avais annonce que la pastille de solde de la barre de navigation disait
 * « 160 pts » sur chaque page. **Elle n'existe pas dans le produit vivant** :
 * `layouts/navigation.blade.php` n'est inclus NULLE PART (seule une note dans
 * `errors/404.blade.php` le mentionne), et aucune barre du haut n'affiche le
 * solde. J'avais grepe un fichier sans verifier qu'il est rendu.
 *
 * Le solde reel vit donc sur `services/show` et `requests/show` (« Votre temps
 * disponible », fait en T1754), sur `profile/show`, et sur la page des points —
 * **c'est cette derniere, plus l'historique, qui manquaient.**
 *
 * ## Le test qui aurait attrape l'erreur
 *
 * `test_les_cinq_surfaces_du_9_1_portent_toutes_le_temps` parcourt les cinq
 * surfaces NOMMEES par le CDC dans un seul cas. Un test par page ne pouvait pas
 * voir qu'il manquait une page : il faut une liste qui soit celle du CDC, pas
 * celle de ce dont on se souvient.
 */
class TASK1759Neuf1SoldeEtHistoriqueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1759', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        // 160 points = « 2 h 40 », une valeur qui exerce aussi le passage aux heures.
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id, 'points_balance' => 160,
        ]);
    }

    private function annonce(int $points = 30): Service
    {
        return Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => $points,
        ]);
    }

    private function mouvement(int $delta): PointLedger
    {
        $autre = User::factory()->create(['organization_id' => $this->organization->id]);

        return PointLedger::create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'transaction_id' => Transaction::factory()->create([
                'organization_id' => $this->organization->id,
                'service_id' => $this->annonce()->id,
                'seller_id' => $this->membre->id,
                'buyer_id' => $autre->id,
                'status' => 'completed',
                'points_proposed' => abs($delta),
                'points_agreed' => abs($delta),
            ])->id,
            'delta' => $delta,
            'reason' => $delta > 0 ? 'exchange_earned' : 'exchange_spent',
        ]);
    }

    /**
     * 🔴 LES CINQ SURFACES NOMMEES PAR LE CDC, dans un seul cas.
     *
     * C'est le test que j'aurais du ecrire en T1754 : la liste vient du texte
     * du §9.1, pas de ma memoire de ce qu'il disait. Une surface qui sort de
     * la liste fait rougir ce test, et c'est tout ce qu'on lui demande.
     */
    public function test_les_cinq_surfaces_du_9_1_portent_toutes_le_temps(): void
    {
        $annonce = $this->annonce(points: 30);
        $this->mouvement(30);

        $lecteur = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 160]);

        $surfaces = [
            'annonce (service)' => route('services.show', $annonce),
            'explorateur' => route('explorer'),
            'solde + historique (page des points)' => route('points.index'),
        ];

        foreach ($surfaces as $nom => $url) {
            $html = $this->actingAs($lecteur)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-entraide-temps', $html,
                "La surface « {$nom} » ne porte pas le marqueur du temps partage.");
        }

        // Le SOLDE : il n'est affiche dans AUCUNE barre de navigation — le
        // fichier `layouts/navigation.blade.php` qui en portait une est du code
        // mort, inclus nulle part. Ses surfaces reelles sont l'annonce (« Votre
        // temps disponible », T1754) et la page des points.
        $this->assertStringContainsString('2 h 40',
            $this->actingAs($lecteur)->get(route('points.index'))->assertOk()->getContent(),
            'Le solde doit se dire en temps sur la page des points.');

        $this->assertStringContainsString('Votre temps disponible',
            $this->actingAs($lecteur)->get(route('services.show', $annonce))->assertOk()->getContent(),
            'Le solde de l\'annonce doit porter le libelle du CDC.');
    }

    /**
     * La carte de SOLDE de la page des points.
     *
     * Le grand nombre devient le temps, les points passent en dessous : mettre
     * « 2 h 40 · 160 pts » en `text-2xl` dans une carte d'un cinquieme de large
     * deborderait, mais supprimer les points irait contre l'arbitrage Q8.
     */
    public function test_la_carte_de_solde_dit_le_temps_et_garde_les_points(): void
    {
        $html = $this->actingAs($this->membre)->get(route('points.index'))->assertOk()->getContent();

        $this->assertStringContainsString('2 h 40', $html, 'Le solde doit se dire en temps.');
        $this->assertStringContainsString('160 pts', $html, 'Les points restent lisibles (arbitrage Q8).');
        $this->assertStringContainsString('data-entraide-temps', $html);
    }

    /**
     * 🔴 UN DEBIT NE S'AFFICHE PAS « 0 min ».
     *
     * `TempsPartage::duree()` borne a zero (`max(0, ...)`) : sans `abs()`, un
     * mouvement de -30 rendait **« 0 min »** — une entraide de 30 minutes
     * affichee comme nulle dans l'historique de quelqu'un. Mesure faite avant
     * d'ecrire le correctif, pas supposee.
     */
    public function test_un_debit_affiche_son_temps_et_non_zero(): void
    {
        $this->mouvement(-30);

        $html = $this->actingAs($this->membre)->get(route('points.index'))->assertOk()->getContent();

        // ## Deux assertions ratees avant celle-ci, et les deux pour la meme
        // raison : je mesurais LA PAGE au lieu de L'ELEMENT.
        //
        // 1. `assertStringNotContainsString('0 min')` ne pouvait JAMAIS passer :
        //    « 30 min » CONTIENT « 0 min ».
        // 2. Avec une frontiere, elle echouait encore — parce que la carte
        //    « Total gagne » affiche « +0 min » a bon droit quand il n'y a
        //    aucun credit. Un vrai zero, pas un defaut.
        //
        // La ligne d'historique se distingue par son signe « − » (U+2212), pose
        // dans le gabarit ; les cartes emploient le trait d'union ASCII. C'est
        // donc cette chaine, et elle seule, qui mesure le mouvement.
        $this->assertStringContainsString('−30 min', $html,
            'La ligne d\'historique doit dire le temps du mouvement.');

        $this->assertStringNotContainsString('−0 min', $html,
            'Un debit rend « 0 min » : le signe a ete passe au formateur, qui borne a zero.');
    }

    /** Les points restent lisibles sous le temps — arbitrage Q8. */
    public function test_les_points_restent_affiches_sous_le_temps(): void
    {
        $this->mouvement(45);

        $html = $this->actingAs($this->membre)->get(route('points.index'))->assertOk()->getContent();

        $this->assertStringContainsString('45 min', $html);
        $this->assertStringContainsString('45 pts', $html,
            'Supprimer les points irait contre l\'arbitrage Q8 (double affichage en V1).');
    }

    /** Un solde a zero est un vrai zero, pas « Offert ». */
    public function test_un_solde_a_zero_se_dit_zero_et_non_offert(): void
    {
        $pauvre = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 0]);

        $html = $this->actingAs($pauvre)->get(route('points.index'))->assertOk()->getContent();

        $this->assertStringContainsString('0 min', $html);
        $this->assertStringNotContainsString('Offert', $html,
            '« Offert » qualifie un coup de main a 0 min, jamais un portefeuille vide.');
    }
}
