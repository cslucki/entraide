<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1749 (demande Cyril, 04/11) — « Mes propositions d'aide » et « Mes
 * demandes d'aide » sont la QUOI QU'IL ARRIVE.
 *
 * ## Ce que cela renverse
 *
 * TASK-1725 conditionnait les quatre cards a `count(...) > 0` : une card vide
 * disparaissait, pour qu'un nouvel arrivant ne voie que son On-boarding.
 *
 * L'effet constate est l'inverse de l'intention. Les etapes d'On-boarding se
 * calculent sur l'EXISTENCE d'une demande ou d'un service, quel que soit leur
 * statut ; les cards, elles, filtrent sur `active` et sur `open`+`in_progress`.
 * Quelqu'un dont les propositions sont inactives et les demandes fermees est
 * donc a 4/4 — plus d'On-boarding — et perdait les deux portes qui lui servent
 * le plus.
 *
 * La regle de T1725 ne gouverne plus que les cards ECHANGES et MESSAGES, qui ne
 * portent aucun geste a declencher.
 */
class TASK1749DashboardAlwaysOnCardsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-dash-1749',
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    private function membre(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Amene le membre a 4/4 d'On-boarding SANS qu'aucune card ait de quoi se
     * remplir.
     *
     * Les trois etapes retenues ici — biographie, une demande, une proposition —
     * se calculent sur l'EXISTENCE, quel que soit le statut. La demande est
     * FERMEE et la proposition INACTIVE : ni l'une ni l'autre ne parait dans sa
     * card, qui ne retient que `open`/`in_progress` et `active`.
     *
     * L'etape « profil IA » ne compte pas : elle est retiree quand l'IA de
     * l'Organization est eteinte, ce qui est le defaut d'usine.
     *
     * PREMIERE VERSION DE CE HELPER : elle ne creait QUE la demande fermee.
     * L'On-boarding restait donc a 1/3, le bloc « rien pour l'instant » ne
     * paraissait pas, et la garde de non-doublon passait AU VERT SANS RIEN
     * MESURER.
     */
    private function onboardingTermineSansRienAMontrer(User $membre): void
    {
        $membre->forceFill(['bio' => 'Une presentation, pour valider l\'etape.'])->save();

        $categorie = Category::factory()->create(['organization_id' => $this->organization->id]);

        ServiceRequest::factory()->create([
            'user_id' => $membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => $categorie->id,
            'status' => 'closed',
        ]);

        Service::factory()->create([
            'user_id' => $membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => $categorie->id,
            'status' => 'inactive',
        ]);
    }

    private function tableauDeBord(User $membre): string
    {
        return $this->actingAs($membre)
            ->get(route('organization.dashboard', $this->organization))
            ->assertOk()
            ->getContent();
    }

    public function test_les_deux_cards_sont_la_pour_un_nouvel_arrivant(): void
    {
        $html = $this->tableauDeBord($this->membre());

        $this->assertStringContainsString('data-dashboard-card="services"', $html);
        $this->assertStringContainsString('data-dashboard-card="requests"', $html);
    }

    /**
     * LE cas qui motive le correctif : On-boarding termine, et pourtant rien a
     * afficher dans les cards.
     *
     * Une demande FERMEE suffit a valider l'etape d'On-boarding sans jamais
     * paraitre dans la card, qui ne retient que `open` et `in_progress`.
     */
    public function test_les_deux_cards_sont_la_quand_l_onboarding_est_termine_et_tout_est_vide(): void
    {
        $membre = $this->membre();

        $this->onboardingTermineSansRienAMontrer($membre);

        $html = $this->tableauDeBord($membre);

        $this->assertStringContainsString('data-dashboard-card="services"', $html);
        $this->assertStringContainsString('data-dashboard-card="requests"', $html);
    }

    /**
     * Les appels a l'action ne doivent pas apparaitre DEUX FOIS.
     *
     * Le bloc « rien pour l'instant » de T1725 existait parce que, les quatre
     * cards masquees, le tableau de bord aurait ete entierement BLANC. Avec deux
     * cards permanentes qui portent deja leur propre appel a l'action, ce bloc
     * repeterait les memes deux boutons juste en dessous.
     *
     * Cette garde dit laquelle des deux situations le produit sert.
     */
    public function test_les_appels_a_l_action_ne_sont_pas_rendus_deux_fois(): void
    {
        $membre = $this->membre();

        $this->onboardingTermineSansRienAMontrer($membre);

        $html = $this->tableauDeBord($membre);

        $this->assertStringNotContainsString('data-dashboard-nothing-yet', $html,
            "Le bloc « rien pour l'instant » doublonne avec les etats vides des deux cards permanentes.");
    }
}
