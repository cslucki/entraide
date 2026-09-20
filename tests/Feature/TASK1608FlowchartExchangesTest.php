<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Flowchart\FlowchartExchanges;
use App\Support\Flowchart\FlowchartGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1608, addendum MASTER — les debouches concrets.
 *
 * ## Ce que l'arbitrage a tranche
 *
 * Les NOEUDS « Propositions » et « Demandes » sont PUBLICS : ils disent ou mene
 * BouclePro. Les DONNEES, elles, sont MEMBER-ONLY.
 *
 * Ce partage n'est pas une precaution de confort. TASK-1479 puis TASK-1488
 * (P0 privacy) ont ferme `/explorer`, `services.show` et `requests.show`
 * derriere `auth` + `organization.member`, apres avoir mesure : « sans aucun
 * cookie, sur une Organization `is_public = false`, ces routes rendaient 200
 * avec le NOM REEL de la personne, le titre et le contenu metier »
 * (`routes/web.php:457`). Le logigramme etant public, y verser ces cards sans
 * garde rouvrirait exactement cette fuite.
 *
 * ## Les memes regles metier qu'Explorer
 *
 * `Service::active()` et `ServiceRequest::open()` ne filtrent pas que le
 * statut : ils excluent aussi les auteurs au compte inactif. Les reecrire a la
 * main aurait ressuscite sous le logigramme des annonces que l'Explorer cache.
 */
class TASK1608FlowchartExchangesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    private Organization $autre;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-echanges',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->autre = Organization::factory()->create([
            'slug' => 'org-echanges-autre',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organisation->id,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function categorie(Organization $organization): Category
    {
        return Category::factory()->create(['organization_id' => $organization->id]);
    }

    private function proposition(Organization $organization, string $titre, ?User $auteur = null): Service
    {
        return Service::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => ($auteur ?? User::factory()->complete()->create(['organization_id' => $organization->id]))->id,
            'category_id' => $this->categorie($organization)->id,
            'title' => $titre,
            'status' => 'active',
        ]);
    }

    private function demande(Organization $organization, string $titre): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => User::factory()->complete()->create(['organization_id' => $organization->id])->id,
            'category_id' => $this->categorie($organization)->id,
            'title' => $titre,
            'status' => 'open',
        ]);
    }

    private function url(?Organization $organization = null): string
    {
        return '/org/'.($organization ?? $this->organisation)->slug.'/flowchart';
    }

    // =====================================================================
    // A. Les NOEUDS sont publics
    // =====================================================================

    /**
     * Un invite voit « Propositions » et « Demandes » sur le graphe.
     *
     * C'est le sens de l'arbitrage : le logigramme explique ou mene BouclePro,
     * meme a qui n'y a pas encore de compte.
     */
    public function test_the_outlet_nodes_are_public(): void
    {
        $graph = app(FlowchartGraph::class)->build($this->organisation, null);

        $ids = array_column(array_column($graph['nodes'], 'data'), 'id');

        $this->assertContains('outlet:proposals', $ids);
        $this->assertContains('outlet:requests', $ids);

        $aretes = array_map(
            static fn (array $e): string => $e['data']['source'].'>'.$e['data']['target'],
            $graph['edges'],
        );

        $this->assertContains('intent:need_help>outlet:proposals', $aretes);
        $this->assertContains('intent:offer_help>outlet:requests', $aretes);
    }

    // =====================================================================
    // B. Les DONNEES ne le sont pas
    // =====================================================================

    /** Un invite ne recoit AUCUNE Proposition ni Demande reelle. */
    public function test_a_guest_receives_no_real_exchange(): void
    {
        $this->proposition($this->organisation, 'Proposition Confidentielle 1608');
        $this->demande($this->organisation, 'Demande Confidentielle 1608');

        $echanges = app(FlowchartExchanges::class);

        $this->assertTrue($echanges->proposals($this->organisation, null)->isEmpty());
        $this->assertTrue($echanges->requests($this->organisation, null)->isEmpty());

        app()->forgetInstance('current_organization');
        $response = $this->get($this->url());
        $response->assertOk();

        $response->assertDontSee('Proposition Confidentielle 1608', false);
        $response->assertDontSee('Demande Confidentielle 1608', false);
    }

    /**
     * Un visiteur CONNECTE venu d'une autre Organization non plus.
     *
     * Son identite ne lui ouvre pas le contenu d'un tenant dont il n'est pas
     * membre : il est traite exactement comme un anonyme.
     */
    public function test_a_logged_in_outsider_receives_no_real_exchange(): void
    {
        $this->proposition($this->organisation, 'Proposition Confidentielle 1608');

        $etranger = User::factory()->complete()->create(['organization_id' => $this->autre->id]);

        $this->assertTrue(
            app(FlowchartExchanges::class)->proposals($this->organisation, $etranger)->isEmpty(),
        );

        app()->forgetInstance('current_organization');
        $this->actingAs($etranger)->get($this->url())
            ->assertOk()
            ->assertDontSee('Proposition Confidentielle 1608', false);
    }

    /** Un MEMBRE de cette Organization, lui, les recoit. */
    public function test_a_member_receives_the_exchanges_of_their_organization(): void
    {
        $this->proposition($this->organisation, 'Proposition Visible 1608');
        $this->demande($this->organisation, 'Demande Visible 1608');

        $echanges = app(FlowchartExchanges::class);

        $this->assertCount(1, $echanges->proposals($this->organisation, $this->membre));
        $this->assertCount(1, $echanges->requests($this->organisation, $this->membre));

        app()->forgetInstance('current_organization');
        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $response->assertSee('Proposition Visible 1608', false);
        $response->assertSee('Demande Visible 1608', false);
    }

    // =====================================================================
    // C. Cross-tenant et regles metier
    // =====================================================================

    /** Jamais une annonce d'une AUTRE Organization. */
    public function test_no_exchange_from_another_organization(): void
    {
        $this->proposition($this->autre, 'Proposition Etrangere 1608');

        $cartes = app(FlowchartExchanges::class)->proposals($this->organisation, $this->membre);

        $this->assertTrue($cartes->isEmpty());

        app()->forgetInstance('current_organization');
        $this->actingAs($this->membre)->get($this->url())
            ->assertOk()
            ->assertDontSee('Proposition Etrangere 1608', false);
    }

    /**
     * Les scopes d'Explorer sont RESPECTES, pas reecrits.
     *
     * Une Proposition dont l'auteur n'a plus un compte actif est cachee par
     * `Service::active()` — dont la seconde moitie est
     * `whereHas('user', fn ($q) => $q->activeAccount())`. Un filtre de statut
     * ecrit a la main l'aurait laissee passer.
     */
    public function test_the_explorer_scopes_are_honoured_not_rewritten(): void
    {
        // Le compte desactive se mesure sur `banned_at`, seul champ que
        // `User::scopeActiveAccount()` regarde (`User.php:205`). Un `is_active`
        // devine n'existe pas en base — la colonne a d'ailleurs fait echouer
        // ce test au premier jet.
        $auteurDesactive = User::factory()->complete()->create([
            'organization_id' => $this->organisation->id,
            'banned_at' => now(),
        ]);

        $this->proposition($this->organisation, 'Proposition Auteur Inactif 1608', $auteurDesactive);
        $this->proposition($this->organisation, 'Proposition Auteur Actif 1608');

        $titres = app(FlowchartExchanges::class)
            ->proposals($this->organisation, $this->membre)
            ->pluck('title')
            ->all();

        $this->assertContains('Proposition Auteur Actif 1608', $titres);
        $this->assertNotContains(
            'Proposition Auteur Inactif 1608',
            $titres,
            '`Service::active()` exclut aussi les auteurs au compte inactif : le scope doit etre appele, pas recopie.',
        );
    }

    // =====================================================================
    // D. Le TYPE d'une Boucle vient du Registry
    // =====================================================================

    /**
     * Le noeud d'une Boucle porte son type ET son statut.
     *
     * Le libelle du type vient de `LoopTypeRegistry` : c'est la seule autorite
     * du catalogue, types de plateforme comme types crees par l'Organization.
     */
    public function test_a_loop_node_carries_its_type_and_access(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organisation->id,
            'created_by' => $this->membre->id,
            'status' => 'active',
            'visibility' => 'public',
            'access_mode' => Loop::ACCESS_OPEN,
            'name' => 'Boucle Typee 1608',
            'slug' => 'boucle-typee-1608',
            'type' => 'project',
        ]);

        $graph = app(FlowchartGraph::class)->build($this->organisation, null);
        $noeud = collect($graph['nodes'])->firstWhere('data.loop_id', $loop->id);

        $this->assertNotNull($noeud, 'La Boucle publique et ouverte doit etre sur la carte.');

        $typeAttendu = app(\App\Support\Loops\LoopTypeRegistry::class)
            ->label('project', $this->organisation);

        $this->assertSame($typeAttendu, $noeud['data']['type_label']);
        $this->assertSame(__('flowchart.access_open'), $noeud['data']['access_label']);

        // Le libelle du noeud porte les deux, sur deux lignes : « nom » puis
        // « type · statut ».
        $this->assertSame(
            'Boucle Typee 1608'."\n".$typeAttendu.' · '.__('flowchart.access_open'),
            $noeud['data']['label'],
        );

        // Et la description du type, pour le panneau — jamais inventee.
        $this->assertArrayHasKey('type_description', $noeud['data']);
    }

    // =====================================================================
    // E. La profondeur du parcours
    // =====================================================================

    /**
     * Trois interactions suffisent pour atteindre du concret.
     *
     * Le contrat produit est de 3 interactions, 4 au maximum. On le mesure sur
     * la TOPOLOGIE, qui est ce que le moteur parcourt :
     *
     * - racine -> intention (1) -> debouche (2) : les echanges reels ;
     * - racine -> « Explorer les Boucles » (1) -> une Boucle (2) ;
     * - racine -> intention (1) -> entree (2) -> [scene du moteur] ->
     *   Decision (3) -> [scene de convergence] : les resultats.
     *
     * Les deux scenes sont ce qui tient ce contrat : sans elles, les huit
     * etapes du tronc feraient huit paliers de plus.
     */
    public function test_three_interactions_reach_something_concrete(): void
    {
        $graph = app(FlowchartGraph::class)->build($this->organisation, null);

        $sortants = [];

        foreach ($graph['edges'] as $edge) {
            if ($edge['data']['view'] === 'overview') {
                continue;
            }

            $sortants[$edge['data']['source']][] = $edge['data']['target'];
        }

        // Profondeur, en sauts, depuis la racine.
        $profondeur = ['root' => 0];
        $file = ['root'];

        while ($file !== []) {
            $courant = array_shift($file);

            foreach ($sortants[$courant] ?? [] as $suivant) {
                if (! isset($profondeur[$suivant])) {
                    $profondeur[$suivant] = $profondeur[$courant] + 1;
                    $file[] = $suivant;
                }
            }
        }

        $this->assertSame(2, $profondeur['outlet:proposals'], 'Les Propositions : 2 clics.');
        $this->assertSame(2, $profondeur['outlet:requests'], 'Les Demandes : 2 clics.');

        // Decision est a 3 sauts de topologie depuis la racine, mais le moteur
        // ouvre les huit etapes en UNE scene : l'entree revele tout le tronc.
        $this->assertSame(1, $profondeur['intent:need_help']);
        $this->assertSame(2, $profondeur['entry:need_help']);
        $this->assertSame(3, $profondeur['step:clarify']);
    }
}
