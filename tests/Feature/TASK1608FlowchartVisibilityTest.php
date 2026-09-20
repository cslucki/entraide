<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1608 — la carte interactive ne montre que des POSSIBILITES REELLES.
 *
 * ## L'autorite n'a pas bouge
 *
 * `App\Support\Loops\VisibleLoops` (TASK-1364) reste l'autorite UNIQUE de
 * visibilite. Cette TASK n'ecrit AUCUNE seconde query, et ne recopie AUCUNE
 * Policy. Ce qu'elle ajoute est une **projection de presentation**, appliquee
 * APRES l'autorite, et qui ne peut que RETRANCHER.
 *
 * ## L'arbitrage MASTER que ces tests mesurent
 *
 * Le catalogue `/org/{org}/loops` nomme deja toute Boucle `active` du tenant a
 * ses membres, `access_mode = invitation` comprise — decision TASK-1075,
 * « privee » ne veut pas dire « cachee » mais « contenu reserve aux membres ».
 * Le Shell fait de meme (`AiSelfKnowledge`, cles
 * `ai.self_knowledge_visible_loops_access_*`).
 *
 * MASTER tranche que le flowchart, lui, est une **carte des possibilites
 * reelles** : une Boucle uniquement sur invitation dont on n'est pas membre
 * n'est pas une possibilite que l'on peut explorer ni demander a rejoindre.
 * Elle sort donc du payload — entierement.
 *
 * | access_mode | is_member | flowchart |
 * |---|---|---|
 * | open | — | visible |
 * | request | — | visible |
 * | request, demande en attente | — | visible |
 * | invitation | true | visible |
 * | invitation | false | ABSENTE |
 *
 * ## Le piege que ces tests verrouillent
 *
 * Le predicat d'exclusion lit `Loop::access_mode` + `is_member`, JAMAIS
 * `VisibleLoops::accessStateFor()`. Cet etat rend `invitation` PAR DEFAUT des
 * que ni `join` ni `requestToJoin` n'autorisent — or `LoopPolicy::join` refuse
 * aussi quand la personne est deja membre actif
 * (`app/Policies/LoopPolicy.php:130-134`). Filtrer sur l'etat masquerait donc
 * TOUTES les Boucles dont la personne est membre. Le test n°1 le mesure : une
 * Boucle `open` DONT ON EST MEMBRE doit rester presente.
 *
 * ## « Absente » se mesure sur le payload, pas sur le rendu
 *
 * Cacher en CSS ou en JavaScript ne serait pas cacher. Les tests 6 a 9 lisent
 * le tableau PHP rendu a la vue ET le HTML complet : nom, slug, ID et edges.
 */
class TASK1608FlowchartVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $autreOrganization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1608',
            'name' => 'Org 1608',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'loop_mode' => 'multi',
        ]);

        $this->autreOrganization = Organization::factory()->create([
            'slug' => 'org-1608-autre',
            'name' => 'Org 1608 Autre',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'loop_mode' => 'multi',
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // Outils — aucun ne connait la regle, ils la lisent
    // =====================================================================

    private function url(?Organization $organization = null): string
    {
        return '/org/'.($organization ?? $this->organization)->slug.'/flowchart';
    }

    private function loop(array $attributs = []): Loop
    {
        return Loop::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'created_by' => $this->membre->id,
            'status' => 'active',
        ], $attributs));
    }

    private function rendreMembre(Loop $loop, ?User $user = null): void
    {
        LoopMember::query()->create([
            'loop_id' => $loop->id,
            'user_id' => ($user ?? $this->membre)->id,
            'organization_id' => $loop->organization_id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    /** Les noeuds `kind = loop` du payload transmis a Cytoscape. */
    private function noeudsBoucle($response): array
    {
        $graph = $response->viewData('graph');

        $this->assertIsArray($graph, 'La vue doit recevoir un tableau `graph`.');
        $this->assertArrayHasKey('nodes', $graph, 'Le payload doit porter `nodes`.');
        $this->assertArrayHasKey('edges', $graph, 'Le payload doit porter `edges`.');

        return array_values(array_filter(
            $graph['nodes'],
            static fn (array $node): bool => ($node['data']['kind'] ?? null) === 'loop',
        ));
    }

    /** @return list<string> les identifiants de Boucle reellement transmis */
    private function idsBoucle($response): array
    {
        return array_map(
            static fn (array $node): string => (string) ($node['data']['loop_id'] ?? ''),
            $this->noeudsBoucle($response),
        );
    }

    // =====================================================================
    // A. MEMBRE DE L'ORGANIZATION — tests 1 a 5
    // =====================================================================

    /**
     * 1. `open` est visible — y compris quand on en est DEJA membre.
     *
     * La seconde moitie de ce test est le verrou du piege `accessStateFor()` :
     * pour un membre d'une Boucle `open`, cet etat rend `invitation`. Si le
     * filtre le lisait, cette Boucle disparaitrait.
     */
    public function test_1_an_open_loop_is_visible_to_a_member_of_the_organization(): void
    {
        $libre = $this->loop(['name' => 'Boucle Libre 1608', 'access_mode' => Loop::ACCESS_OPEN]);

        $sienne = $this->loop(['name' => 'Boucle Sienne 1608', 'access_mode' => Loop::ACCESS_OPEN]);
        $this->rendreMembre($sienne);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $ids = $this->idsBoucle($response);

        $this->assertContains($libre->id, $ids, 'Une Boucle a entree libre doit etre sur la carte.');
        $this->assertContains(
            $sienne->id,
            $ids,
            "Une Boucle `open` DONT ON EST MEMBRE a disparu : le filtre lit `accessStateFor()` au lieu de `access_mode`.",
        );
    }

    /** 2. `request` est visible : demander a rejoindre EST une possibilite reelle. */
    public function test_2_a_request_loop_is_visible_to_a_member_of_the_organization(): void
    {
        $loop = $this->loop(['name' => 'Boucle Sur Demande 1608', 'access_mode' => Loop::ACCESS_REQUEST]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertContains($loop->id, $this->idsBoucle($response));
    }

    /**
     * 3. Une demande DEJA en attente reste visible.
     *
     * Elle ne disparait pas parce qu'on a agi : la carte doit pouvoir rendre
     * l'etat « demande en attente », que `VisibleLoops::accessStateFor()` sait
     * deja nommer.
     */
    public function test_3_a_pending_request_loop_stays_visible(): void
    {
        $loop = $this->loop(['name' => 'Boucle En Attente 1608', 'access_mode' => Loop::ACCESS_REQUEST]);

        LoopJoinRequest::query()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $loop->id,
            'user_id' => $this->membre->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $noeuds = $this->noeudsBoucle($response);
        $sien = collect($noeuds)->firstWhere('data.loop_id', $loop->id);

        $this->assertNotNull($sien, 'Une Boucle dont la demande est en attente doit rester sur la carte.');
        $this->assertSame(
            'pending',
            $sien['data']['access'] ?? null,
            "L'etat d'acces doit venir de `VisibleLoops::accessStateFor()`, qui sait dire `pending`.",
        );
    }

    /** 4. `invitation` DONT ON EST MEMBRE est visible : elle fait partie de son espace. */
    public function test_4_an_invitation_loop_one_belongs_to_is_visible(): void
    {
        $loop = $this->loop(['name' => 'Boucle Invitation Sienne 1608', 'access_mode' => Loop::ACCESS_INVITATION]);
        $this->rendreMembre($loop);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertContains(
            $loop->id,
            $this->idsBoucle($response),
            'Une Boucle sur invitation dont on est membre fait partie de son espace.',
        );
    }

    /**
     * 5. `invitation` SANS appartenance est ABSENTE.
     *
     * C'est la decision produit de MASTER, et la seule soustraction que le
     * flowchart opere par rapport au catalogue.
     */
    public function test_5_an_invitation_loop_one_does_not_belong_to_is_absent(): void
    {
        $interdite = $this->loop(['name' => 'Boucle Invitation Fermee 1608', 'access_mode' => Loop::ACCESS_INVITATION]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertNotContains(
            $interdite->id,
            $this->idsBoucle($response),
            'Une Boucle sur invitation dont on n\'est pas membre n\'est pas une possibilite reelle.',
        );
    }

    // =====================================================================
    // B. SECURITE DU PAYLOAD — tests 6 a 9
    //
    // Une Boucle absente doit l'etre du tableau PHP, du JSON, du DOM et des
    // attributs. Le navigateur ne doit pas pouvoir apprendre qu'elle existe.
    // =====================================================================

    /** 6. Son NOM n'apparait nulle part dans la reponse. */
    public function test_6_the_name_of_a_hidden_invitation_loop_never_reaches_the_browser(): void
    {
        $interdite = $this->loop([
            'name' => 'Cartographie Confidentielle 1608',
            'access_mode' => Loop::ACCESS_INVITATION,
        ]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $response->assertDontSee($interdite->name, false);
    }

    /** 7. Son SLUG non plus. */
    public function test_7_the_slug_of_a_hidden_invitation_loop_never_reaches_the_browser(): void
    {
        $interdite = $this->loop([
            'name' => 'Boucle Slug Confidentiel 1608',
            'slug' => 'slug-confidentiel-1608',
            'access_mode' => Loop::ACCESS_INVITATION,
        ]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $response->assertDontSee($interdite->slug, false);
    }

    /** 8. Son IDENTIFIANT non plus — ni en node, ni en `data-*`, ni en URL. */
    public function test_8_the_id_of_a_hidden_invitation_loop_never_reaches_the_browser(): void
    {
        $interdite = $this->loop([
            'name' => 'Boucle Id Confidentiel 1608',
            'access_mode' => Loop::ACCESS_INVITATION,
        ]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $response->assertDontSee($interdite->id, false);
    }

    /**
     * 9. Aucune EDGE ne pointe vers elle.
     *
     * Une arete orpheline revelerait l'existence d'un noeud retire, et
     * casserait le graphe. Les deux se mesurent ici : aucune arete ne cite la
     * Boucle interdite, et toute arete rendue relie deux noeuds presents.
     */
    public function test_9_no_edge_points_at_a_hidden_invitation_loop(): void
    {
        $interdite = $this->loop([
            'name' => 'Boucle Arete Confidentielle 1608',
            'access_mode' => Loop::ACCESS_INVITATION,
        ]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $graph = $response->viewData('graph');

        $idsPresents = array_map(
            static fn (array $node): string => (string) $node['data']['id'],
            $graph['nodes'],
        );

        foreach ($graph['edges'] as $edge) {
            $source = (string) $edge['data']['source'];
            $target = (string) $edge['data']['target'];

            $this->assertNotContains($interdite->id, [$source, $target], 'Une arete cite la Boucle interdite.');

            $this->assertContains($source, $idsPresents, "Arete orpheline : source `{$source}` absente des noeuds.");
            $this->assertContains($target, $idsPresents, "Arete orpheline : cible `{$target}` absente des noeuds.");
        }
    }

    // =====================================================================
    // C. INVITE — tests 10 a 12
    //
    // Aucune surface du produit ne nomme une Boucle a un invite, et
    // `VisibleLoops::query()` exige un `User`. La page reste publique et
    // explicative ; elle n'ouvre aucune divulgation.
    // =====================================================================

    /** 10. La page repond 200 a un visiteur anonyme. */
    public function test_10_the_flowchart_is_public(): void
    {
        $this->get($this->url())->assertOk();
    }

    /** 11. Et elle porte bien le graphe structurel, pas une page vide. */
    public function test_11_a_guest_sees_the_structural_graph(): void
    {
        $response = $this->get($this->url());
        $response->assertOk();

        $graph = $response->viewData('graph');

        $racines = array_filter($graph['nodes'], static fn (array $n): bool => ($n['data']['kind'] ?? null) === 'root');
        $intentions = array_filter($graph['nodes'], static fn (array $n): bool => ($n['data']['kind'] ?? null) === 'intent');

        $this->assertCount(1, $racines, 'Le graphe doit avoir un point d\'entree unique.');
        $this->assertCount(4, $intentions, 'Les quatre portes du §4 doivent etre presentes.');
        $this->assertNotEmpty($graph['edges'], 'Un graphe sans arete n\'est pas un graphe.');
    }

    /** 12. Aucune Boucle REELLE n'atteint un invite — ni en payload, ni en HTML. */
    public function test_12_a_guest_receives_no_real_loop(): void
    {
        // Slugs explicites : celui de la factory vient de `faker`, et un slug
        // comme « et-qui » se retrouverait par hasard dans la page. Un rouge
        // doit mesurer une fuite, jamais une collision de vocabulaire.
        $ouverte = $this->loop([
            'name' => 'Boucle Ouverte Visible 1608',
            'slug' => 'boucle-ouverte-visible-1608',
            'access_mode' => Loop::ACCESS_OPEN,
        ]);
        $publique = $this->loop([
            'name' => 'Boucle Publique 1608',
            'slug' => 'boucle-publique-1608',
            'visibility' => 'public',
            'access_mode' => Loop::ACCESS_OPEN,
        ]);

        $response = $this->get($this->url());
        $response->assertOk();

        $this->assertSame([], $this->noeudsBoucle($response), 'Un invite ne recoit aucun noeud de Boucle.');

        foreach ([$ouverte, $publique] as $loop) {
            $response->assertDontSee($loop->name, false);
            $response->assertDontSee($loop->slug, false);
            $response->assertDontSee($loop->id, false);
        }
    }

    // =====================================================================
    // D. CROSS-TENANT — test 13
    // =====================================================================

    /**
     * 13. Aucune Boucle d'une AUTRE Organization, meme parfaitement ouverte.
     *
     * Mesure faite depuis la page de `org-1608`, par un membre de `org-1608`,
     * sur une Boucle `open` + `public` de `org-1608-autre` : le cas le plus
     * permissif possible reste hors du tenant.
     */
    public function test_13_no_loop_from_another_organization_ever_appears(): void
    {
        $etrangere = Loop::factory()->create([
            'organization_id' => $this->autreOrganization->id,
            'created_by' => User::factory()->complete()->create([
                'organization_id' => $this->autreOrganization->id,
            ])->id,
            'name' => 'Boucle Etrangere Ouverte 1608',
            'slug' => 'boucle-etrangere-ouverte-1608',
            'status' => 'active',
            'visibility' => 'public',
            'access_mode' => Loop::ACCESS_OPEN,
        ]);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertNotContains($etrangere->id, $this->idsBoucle($response));
        $response->assertDontSee($etrangere->name, false);
        $response->assertDontSee($etrangere->slug, false);
    }
}
