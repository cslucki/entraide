<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1749 (demande Cyril, 04/11) — une demande POURVUE reste visible dans sa
 * card, avec sa pastille.
 *
 * ## Ce que cela renverse, et pourquoi le motif d'origine ne tenait pas
 *
 * TASK-1693 tenait `closed` hors de la card au motif que « son auteur l'a ferme
 * deliberement ». C'est vrai d'une fermeture manuelle
 * (`RequestController::destroy`), mais pas du cas principal :
 * `ServiceRequestLifecycle::close()` ferme la demande quand la prestation est
 * TERMINEE. Son auteur ne l'a alors pas fermee — elle a abouti, et elle
 * disparaissait au moment ou elle meritait le plus d'etre vue.
 *
 * ## Les deux pieges que ces tests gardent
 *
 * Ils n'etaient pas theoriques : les deux etaient dans le code avant ce
 * correctif, et se seraient declares des le premier `closed` affiche.
 *
 *  1. la pastille etait BINAIRE — tout ce qui n'etait pas `in_progress`
 *     s'affichait « Ouverte ». Une demande pourvue aurait donc annonce
 *     « Ouverte » ;
 *  2. « Fermer » etait offert des que la demande n'etait pas `in_progress`.
 *     Une demande DEJA close l'aurait donc propose — un geste sans effet, au
 *     seul endroit ou il ne veut plus rien dire.
 */
class TASK1749DashboardFulfilledRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Category $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-pourvue-1749',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->categorie = Category::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    private function demande(string $statut, string $titre): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie->id,
            'status' => $statut,
            'title' => $titre,
        ]);
    }

    private function tableauDeBord(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.dashboard', $this->organization))
            ->assertOk()
            ->getContent();
    }

    /** Le fragment de ligne d'UNE demande, borne a elle seule. */
    private function ligne(string $html, ServiceRequest $demande): string
    {
        $debut = strpos($html, 'data-dashboard-request="'.$demande->id.'"');

        $this->assertNotFalse($debut, "La demande « {$demande->title} » n'est pas rendue dans la card.");

        // Jusqu'a la ligne suivante, ou la fin de la liste. Une assertion posee
        // sur la PAGE entiere passerait des qu'une AUTRE demande porte le
        // libelle cherche — c'est precisement ce que ce test doit distinguer.
        $suivante = strpos($html, 'data-dashboard-request="', $debut + 10);

        return $suivante === false
            ? substr($html, $debut)
            : substr($html, $debut, $suivante - $debut);
    }

    public function test_une_demande_pourvue_est_visible_dans_la_card(): void
    {
        $pourvue = $this->demande('closed', 'Demande qui a abouti');

        $html = $this->tableauDeBord();

        $this->assertStringContainsString('data-dashboard-request="'.$pourvue->id.'"', $html);
        $this->assertStringContainsString('Demande qui a abouti', $html);
    }

    /** Le piege n°1. */
    public function test_une_demande_pourvue_porte_la_pastille_pourvue_et_non_ouverte(): void
    {
        $pourvue = $this->demande('closed', 'Pourvue');

        $ligne = $this->ligne($this->tableauDeBord(), $pourvue);

        $this->assertStringContainsString('data-dashboard-request-state="closed"', $ligne);
        $this->assertStringContainsString(__('dashboard.request_fulfilled'), $ligne);
        $this->assertStringNotContainsString(__('dashboard.request_open'), $ligne);
    }

    /** Le piege n°2. */
    public function test_une_demande_pourvue_ne_propose_plus_de_la_fermer(): void
    {
        $pourvue = $this->demande('closed', 'Pourvue');

        $ligne = $this->ligne($this->tableauDeBord(), $pourvue);

        $this->assertStringNotContainsString('data-dashboard-request-close="'.$pourvue->id.'"', $ligne);
    }

    /** Les deux autres etats ne doivent rien perdre au passage. */
    public function test_les_etats_ouverte_et_prise_en_charge_sont_intacts(): void
    {
        $ouverte = $this->demande('open', 'Encore ouverte');
        $enCours = $this->demande('in_progress', 'Prise en charge');

        $html = $this->tableauDeBord();

        $ligneOuverte = $this->ligne($html, $ouverte);
        $this->assertStringContainsString(__('dashboard.request_open'), $ligneOuverte);
        $this->assertStringContainsString('data-dashboard-request-close="'.$ouverte->id.'"', $ligneOuverte);

        $ligneEnCours = $this->ligne($html, $enCours);
        $this->assertStringContainsString(__('dashboard.request_in_progress'), $ligneEnCours);
        $this->assertStringNotContainsString('data-dashboard-request-close="'.$enCours->id.'"', $ligneEnCours);
    }

    /**
     * L'ORDRE : les etats vivants d'abord.
     *
     * La card se dote d'un ascenseur au-dela de 4 elements. Melangees par date,
     * de vieilles demandes pourvues passeraient devant des demandes ouvertes
     * recentes et les sortiraient du champ.
     */
    public function test_les_demandes_vivantes_passent_devant_les_pourvues(): void
    {
        // La pourvue est creee EN DERNIER : par date seule, elle serait en tete.
        $ouverte = $this->demande('open', 'Encore ouverte');
        $pourvue = $this->demande('closed', 'Deja pourvue');

        $html = $this->tableauDeBord();

        $this->assertLessThan(
            strpos($html, 'data-dashboard-request="'.$pourvue->id.'"'),
            strpos($html, 'data-dashboard-request="'.$ouverte->id.'"'),
            'Une demande pourvue passe devant une demande encore ouverte.'
        );
    }
}
