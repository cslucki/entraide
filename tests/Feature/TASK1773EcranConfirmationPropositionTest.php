<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

/**
 * TASK-1773 — L'ECRAN QUI SUIT LA PUBLICATION D'UNE PROPOSITION.
 *
 * Le parcours complet : creation -> confirmation -> nouvelle proposition.
 *
 * Ce que ces gardes defendent, et qui ne se voit pas a la lecture du code :
 *   - un RAFRAICHISSEMENT de l'ecran ne recree rien (c'est une page en GET,
 *     atteinte par redirection — le PRG, pas une garde ajoutee apres coup) ;
 *   - l'ecran n'est lisible que par l'AUTEUR : sinon on lirait « votre
 *     proposition est publiee » devant celle d'autrui ;
 *   - le texte correspond au STATUT REEL ;
 *   - les liens respectent la surface (prefixee par l'Organization ou non),
 *     donc aucune route en dur vers `main`.
 */
class TASK1773EcranConfirmationPropositionTest extends TestCase
{
    private Organization $organization;

    private User $membre;

    private Category $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('fr');

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'is_default' => true,
            'locale' => 'fr',
            'service_points_min' => 10,
            'service_points_max' => 240,
        ]);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->categorie = Category::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Relecture de dossier Qualiopi',
            'description' => str_repeat('Je relis votre dossier et je vous dis ce qui manque, point par point. ', 3),
            'category_id' => $this->categorie->id,
            'delivery_mode' => 'remote',
            'points_cost' => 45,
        ], $overrides);
    }

    /** Publier mene a l'ecran, et non plus au tableau de bord. */
    public function test_publier_une_proposition_mene_a_l_ecran_de_confirmation(): void
    {
        $reponse = $this->actingAs($this->membre)->post(route('services.store'), $this->payload());

        $service = Service::where('user_id', $this->membre->id)->firstOrFail();

        // La redirection prefere la surface prefixee par l'Organization quand
        // elle existe — c'est EXACTEMENT ce que faisait l'ancienne
        // redirection vers le tableau de bord. On ne change pas cette regle
        // en passant : on s'y conforme.
        $reponse->assertRedirect(route('organization.services.created', [
            'organization' => $this->organization->slug,
            'service' => $service->id,
        ]));

        $this->actingAs($this->membre)
            ->get(route('services.created', ['service' => $service->id]))
            ->assertOk()
            ->assertSee('Votre proposition d\'entraide est publiée !')
            ->assertSee('Merci de contribuer à l\'entraide sur BouclePro.')
            ->assertSee('Et si vous partagiez d\'autres savoir-faire ?')
            ->assertSee('Proposer une autre proposition d\'entraide')
            // TASK-1773 (recette Cyril) — le titre de la proposition est
            // RETIRE : il coutait une ligne et poussait le bouton hors du
            // champ sur un ecran de telephone.
            ->assertDontSee($service->title)
            ->assertSee('Voir mes propositions');
    }

    /**
     * L'INVITATION PASSE AVANT L'EXPLICATION.
     *
     * Recette Cyril : « on met le bouton juste avant le bloc d'explication
     * pour que la personne soit poussee a cliquer ». Un ecran ou il faut
     * defiler pour voir le bouton n'invite personne — on mesure donc l'ORDRE
     * dans le document, pas la simple presence des deux.
     */
    public function test_le_bouton_precede_le_bloc_d_explication(): void
    {
        $service = Service::factory()->forUser($this->membre)->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id, 'status' => 'active']);

        $html = $this->actingAs($this->membre)
            ->get(route('services.created', ['service' => $service->id]))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'service_created_more_title') !== false
                ? strpos($html, 'service_created_more_title')
                : strpos($html, 'Et si vous partagiez'),
            strpos($html, 'data-service-created-again'),
            'le bouton doit venir AVANT le bloc d\'explication',
        );
    }

    /** Le bouton principal ramene au formulaire VIERGE. */
    public function test_l_ecran_ramene_a_un_formulaire_de_creation_vierge(): void
    {
        $service = Service::factory()->forUser($this->membre)->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id, 'status' => 'active']);

        $this->actingAs($this->membre)
            ->get(route('services.created', ['service' => $service->id]))
            ->assertOk()
            ->assertSee(route('services.create'), false);

        // Et ce formulaire ne porte rien de la proposition precedente.
        $this->actingAs($this->membre)
            ->get(route('services.create'))
            ->assertOk()
            ->assertDontSee($service->title);
    }

    /**
     * Le rafraichissement ne duplique RIEN.
     *
     * La demande en faisait une regle. La forme la tient : l'ecran est une
     * page en GET. On le prouve en la rechargeant trois fois et en comptant
     * les propositions.
     */
    public function test_rafraichir_l_ecran_ne_cree_aucune_proposition(): void
    {
        $this->actingAs($this->membre)->post(route('services.store'), $this->payload());

        $service = Service::where('user_id', $this->membre->id)->firstOrFail();

        foreach (range(1, 3) as $ignore) {
            $this->actingAs($this->membre)
                ->get(route('services.created', ['service' => $service->id]))
                ->assertOk();
        }

        $this->assertSame(1, Service::where('user_id', $this->membre->id)->count());
    }

    /** L'ecran d'autrui n'est pas lisible. */
    public function test_l_ecran_d_une_autre_personne_est_introuvable(): void
    {
        $autre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $service = Service::factory()->forUser($autre)->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id, 'status' => 'active']);

        $this->actingAs($this->membre)
            ->get(route('services.created', ['service' => $service->id]))
            ->assertNotFound();
    }

    /** Le texte suit le statut REEL, il ne le suppose pas. */
    public function test_une_proposition_en_pause_ne_s_annonce_pas_comme_publiee(): void
    {
        $service = Service::factory()->forUser($this->membre)->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id, 'status' => 'paused']);

        $this->actingAs($this->membre)
            ->get(route('services.created', ['service' => $service->id]))
            ->assertOk()
            ->assertDontSee('Votre proposition d\'entraide est publiée !')
            ->assertSee('Elle n\'est pas encore visible par les autres membres.');
    }

    /** Sur la surface prefixee, tous les liens restent prefixes. */
    public function test_les_liens_respectent_la_surface_de_l_organization(): void
    {
        $service = Service::factory()->forUser($this->membre)->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id, 'status' => 'active']);

        $slug = $this->organization->slug;

        $this->actingAs($this->membre)
            ->get(route('organization.services.created', ['organization' => $slug, 'service' => $service->id]))
            ->assertOk()
            ->assertSee(route('organization.services.create', ['organization' => $slug]), false)
            ->assertSee(route('organization.dashboard.services', ['organization' => $slug]), false);
    }
}
