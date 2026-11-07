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
            ->assertSee('Proposition d\'entraide publiée !')
            ->assertSee('Merci de contribuer à l\'entraide sur BouclePro.')
            ->assertSee('Mettez en avant vos compétences')
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
                : strpos($html, 'Mettez en avant vos'),
            strpos($html, 'data-service-created-again'),
            'le bouton doit venir AVANT le bloc d\'explication',
        );
    }

    /**
     * Le bloc d'explication est lui-meme une porte vers le formulaire.
     *
     * Recette Cyril : « si on clique sur le bloc d'explication, ca va aussi
     * sur le formulaire ». Le texte DIT d'en publier une autre — le lire et
     * devoir remonter au bouton etait une marche inutile.
     *
     * Un `<a>`, pas un `<div>` avec un gestionnaire de clic : c'est ce qui
     * rend la destination visible, ouvrable dans un onglet, et atteignable
     * au clavier.
     */
    public function test_le_bloc_d_explication_mene_lui_aussi_au_formulaire(): void
    {
        $service = Service::factory()->forUser($this->membre)->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id, 'status' => 'active']);

        $html = $this->actingAs($this->membre)
            ->get(route('services.created', ['service' => $service->id]))
            ->assertOk()
            ->assertSee('data-service-created-block', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(route('services.create'), '/').'"[^>]*data-service-created-block/',
            $html,
            'le bloc doit etre un lien vers le formulaire, pas un div cliquable',
        );
    }

    /**
     * LA FETE, et surtout : ce qu'elle ne doit pas casser.
     *
     * « Faire un effet avec plein de coeurs qui traversent la page. » Le
     * melange avec des likes a ete essaye, puis ecarte. La garde ne mesure
     * pas la joie — elle mesure les trois choses qu'une animation posee sur
     * un ecran d'action peut abimer :
     *   - avaler un clic (`pointer-events`) ;
     *   - faire defiler la page (`fixed`, et non `absolute`) ;
     *   - parler aux lecteurs d'ecran, qui n'ont que faire de confettis.
     */
    public function test_la_pluie_de_coeurs_ne_gene_ni_le_clic_ni_la_lecture(): void
    {
        $service = Service::factory()->forUser($this->membre)->forCategory($this->categorie)
            ->create(['organization_id' => $this->organization->id, 'status' => 'active']);

        $html = $this->actingAs($this->membre)
            ->get(route('services.created', ['service' => $service->id]))
            ->assertOk()
            ->assertSee('data-coeurs-confirmation', false)
            ->assertSee('aria-hidden="true"', false)
            ->getContent();

        // QUE des coeurs : le melange avec des pouces et des
        // applaudissements a ete essaye, puis ecarte par Cyril.
        foreach (['❤️', '💜', '💚', '🧡', '💛'] as $coeur) {
            $this->assertStringContainsString($coeur, $html);
        }

        foreach (['👍', '🎉', '👏', '🙌', '⭐'] as $autreReaction) {
            $this->assertStringNotContainsString($autreReaction, $html,
                'un seul symbole : le melange ressemblait a une barre de reactions');
        }

        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.bp-coeurs', $css);
        $this->assertMatchesRegularExpression('/\.bp-coeurs\s*\{[^}]*position:\s*fixed/s', $css,
            'en `absolute`, les reactions allongeraient le document et l\'ecran se remettrait a defiler');
        $this->assertMatchesRegularExpression('/\.bp-coeurs\s*\{[^}]*pointer-events:\s*none/s', $css,
            'un confetti qui avale un clic est un defaut, pas une fete');
        $this->assertStringContainsString('prefers-reduced-motion', $css,
            'qui a demande moins de mouvement a demande moins de mouvement');
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
            ->assertDontSee('Proposition d\'entraide publiée !')
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
