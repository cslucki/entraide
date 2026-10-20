<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1608 — ce que la carte publique montre, et ce qu'elle ne montre jamais.
 *
 * ## Deux axes DISTINCTS, et tout le sujet est de ne pas les confondre
 *
 * - `visibility` : `public` | `private`
 * - `access_mode` : `open` | `request` | `invitation`
 *
 * Les six combinaisons existent reellement en base (mesure sur `bouclepro` :
 * `main` porte du `private+open`, du `private+request`, du `private+invitation`
 * et du `public+open`). Une regle qui n'en nommerait qu'un des deux axes
 * laisserait donc passer des cas reels.
 *
 * ## La regle tranchee par MASTER, et pourquoi ce n'est pas `VisibleLoops`
 *
 * `App\Support\Loops\VisibleLoops` repond a « quelles Boucles le produit
 * INTERNE peut nommer a ce membre ». Depuis TASK-1075, sa reponse est « toutes
 * les actives du tenant » : « privee » y veut dire « contenu reserve », pas
 * « cachee ». C'est juste pour un catalogue derriere `auth`, et inutilisable
 * sur une page PUBLIQUE.
 *
 * Le flowchart repond a « quelles possibilites cette personne peut reellement
 * explorer ici ». Sa projection — `App\Support\Flowchart\FlowchartLoops` — est
 * dediee a ce payload, n'accorde aucun droit, et ne modifie pas `VisibleLoops`.
 *
 * SOCLE PUBLIC — `status = active` ET `visibility = public` ET
 * `access_mode ∈ {open, request}`.
 *
 * TASK-1692 : « servi a tout le monde » voulait dire « y compris a un visiteur
 * sans session ». Ce n'est plus vrai — `auth` + `organization.member` sont
 * poses sur tout le groupe `/org/{organization}`, et cette carte est
 * desormais reservee aux MEMBRES de l'Organization visitee. Le nom « socle
 * public » est conserve parce qu'il designe la PROJECTION, pas l'audience :
 * c'est ce que la carte montre a quelqu'un qui n'appartient a aucune de ces
 * Boucles. Organization = Tenant, mais Loop ≠ Tenant : appartenir au tenant
 * n'ouvre aucune Boucle privee, et c'est precisement ce que mesurent les
 * tests 3, 4, 11 et 12.
 *
 * AJOUT MEMBRE, pour qui appartient a l'Organization visitee : ses Boucles
 * actives dont il est membre ACTIF, meme `private`, meme `invitation`.
 *
 * ## « Absente » se mesure sur le payload, jamais sur le rendu
 *
 * Cacher en CSS ou en JavaScript ne serait pas cacher. Les tests 13 a 17 lisent
 * le tableau PHP transmis a la vue ET le HTML complet : nom, slug, id, aretes.
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
    // Outils
    // =====================================================================

    private function url(?Organization $organization = null): string
    {
        return '/org/'.($organization ?? $this->organization)->slug.'/flowchart';
    }

    /**
     * Une Boucle sur les DEUX axes, avec un nom et un slug distinctifs.
     *
     * Le slug de la factory vient de `faker` : un slug comme « et-qui » se
     * retrouverait par hasard dans la page et rendrait un rouge qui ne mesure
     * rien. Ici, chaque Boucle porte une empreinte unique.
     */
    private function loop(string $visibility, string $accessMode, ?Organization $organization = null): Loop
    {
        $empreinte = $visibility.'-'.$accessMode.'-'.bin2hex(random_bytes(4));

        return Loop::factory()->create([
            'organization_id' => ($organization ?? $this->organization)->id,
            'created_by' => $this->membre->id,
            'status' => 'active',
            'visibility' => $visibility,
            'access_mode' => $accessMode,
            'name' => 'Boucle 1608 '.$empreinte,
            'slug' => 'boucle-1608-'.$empreinte,
        ]);
    }

    private function rendreMembre(Loop $loop, ?User $user = null): Loop
    {
        LoopMember::query()->create([
            'loop_id' => $loop->id,
            'user_id' => ($user ?? $this->membre)->id,
            'organization_id' => $loop->organization_id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $loop;
    }

    private function graph($response): array
    {
        $graph = $response->viewData('graph');

        $this->assertIsArray($graph, 'La vue doit recevoir un tableau `graph`.');
        $this->assertArrayHasKey('nodes', $graph);
        $this->assertArrayHasKey('edges', $graph);

        return $graph;
    }

    /** @return list<string> les identifiants de Boucle reellement transmis */
    private function idsBoucle($response): array
    {
        return collect($this->graph($response)['nodes'])
            ->filter(static fn (array $n): bool => ($n['data']['kind'] ?? null) === 'loop')
            ->map(static fn (array $n): string => (string) $n['data']['loop_id'])
            ->values()
            ->all();
    }

    /** La Boucle est-elle sur la carte, et nulle part ailleurs dans la reponse ? */
    private function assertAbsente($response, Loop $loop, string $pourquoi): void
    {
        $this->assertNotContains($loop->id, $this->idsBoucle($response), $pourquoi);
        $response->assertDontSee($loop->name, false);
        $response->assertDontSee($loop->slug, false);
        $response->assertDontSee($loop->id, false);
    }

    private function assertPresente($response, Loop $loop, string $pourquoi): void
    {
        $this->assertContains($loop->id, $this->idsBoucle($response), $pourquoi);
    }

    // =====================================================================
    // A. LE VISITEUR ANONYME — TASK-1692 : il n'atteint plus cette carte
    //
    // Les tests 1 a 6 mesuraient ici le « socle public » servi a un INVITE.
    // TASK-1692 a pose `auth` + `organization.member` sur tout le groupe
    // `/org/{organization}` : un visiteur sans session est redirige vers le
    // login borne, et la question « que voit un invite ? » n'a plus d'objet.
    //
    // AUCUNE garantie n'est perdue pour autant, et c'est le point a verifier
    // avant de lire la suite — une garantie qu'on ne peut plus mesurer ne se
    // supprime pas, elle se REFONDE :
    //
    //   * le refus anonyme devient une preuve EXPLICITE (test 1 ci-dessous),
    //     plus forte que l'ancienne : rien ne fuit parce que RIEN n'est servi ;
    //   * les quatre interdits (3 a 6) sont refondes sur le viseur qui existe
    //     encore : un membre de l'Organization qui n'appartient PAS a la
    //     Boucle. C'est la distinction Organization = Tenant mais Loop ≠
    //     Tenant — appartenir au tenant n'ouvre aucune Boucle privee ;
    //   * les deux autorises (1 et 2 : `public+open` et `public+request` sont
    //     montres) restent mesures A L'IDENTIQUE par les tests 7 et 8, qui
    //     interrogent deja `$this->membre`, lui non plus membre d'aucune de
    //     ces Boucles. Rien n'a ete retire, la preuve a change de section.
    //
    // Les six combinaisons des deux axes restent donc couvertes, une par test,
    // aucune deduite d'une autre : 7, 8, 3bis, 4bis, 11, 12 pour un non-membre
    // de la Boucle ; 9 et 10 pour un membre de la Boucle.
    // =====================================================================

    /**
     * 1. Le visiteur anonyme est refuse, et rien ne lui echappe au passage.
     *
     * Les deux faits sont mesures ensemble : la redirection, ET l'absence de
     * toute empreinte de Boucle dans ce qui lui est rendu. Un `assertRedirect()`
     * seul ne dirait pas que la reponse ne porte rien.
     */
    public function test_1_an_anonymous_visitor_cannot_reach_the_prefixed_flowchart(): void
    {
        $publique = $this->loop('public', Loop::ACCESS_OPEN);
        $privee = $this->loop('private', Loop::ACCESS_INVITATION);

        $response = $this->get($this->url());

        $response->assertRedirect(route('organization.login', [
            'organization' => $this->organization->slug,
        ]));

        foreach ([$publique, $privee] as $loop) {
            $response->assertDontSee($loop->name, false);
            $response->assertDontSee($loop->slug, false);
            $response->assertDontSee($loop->id, false);
        }
    }

    /**
     * 2. Et la carte n'est pas davantage joignable par un membre d'une AUTRE
     *    Organization : 404, jamais 403 — un 403 avouerait que la carte existe.
     */
    public function test_2_a_member_of_another_organization_gets_a_no_oracle_404(): void
    {
        $loop = $this->loop('public', Loop::ACCESS_OPEN);

        $etranger = User::factory()->complete()->create([
            'organization_id' => $this->autreOrganization->id,
        ]);

        $response = $this->actingAs($etranger)->get($this->url());

        $response->assertNotFound();
        $response->assertDontSee($loop->name, false);
        $response->assertDontSee($loop->slug, false);
        $response->assertDontSee($loop->id, false);
    }

    /**
     * 3. `public` + `invitation` : ABSENTE — publique n'est pas praticable.
     *
     * Refondu sur un membre de l'Organization NON membre de la Boucle : c'est
     * desormais le viseur le plus faible qui puisse atteindre cette page.
     * Ce cas n'est couvert par aucun autre test de la section B.
     */
    public function test_3_a_member_never_sees_a_public_invitation_loop(): void
    {
        $loop = $this->loop('public', Loop::ACCESS_INVITATION);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertAbsente($response, $loop, 'Sur invitation : on ne peut ni entrer ni demander.');
    }

    /**
     * 4. `private` + `open` : ABSENTE.
     *
     * Le cas qui prouve que l'axe `visibility` est bien lu. Une regle ecrite
     * sur le seul `access_mode` laisserait passer cette Boucle — et elle
     * existe reellement sur `main` (4 occurrences mesurees). Lui aussi n'est
     * couvert nulle part ailleurs.
     */
    public function test_4_a_member_never_sees_a_private_open_loop_they_do_not_belong_to(): void
    {
        $loop = $this->loop('private', Loop::ACCESS_OPEN);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertAbsente($response, $loop, '`access_mode = open` ne rattrape pas `visibility = private`.');
    }

    /**
     * 5 et 6 — `private` + `request` et `private` + `invitation`, sans
     * appartenance : ABSENTES. Ces deux cas sont tenus, a l'identique et pour
     * le meme viseur, par les tests 12 et 11 de la section B. Les redoubler
     * ici ne mesurerait rien de plus ; les retirer sans le dire aurait ete une
     * perte silencieuse, d'ou cette note.
     */

    // =====================================================================
    // B. MEMBRE DE L'ORGANIZATION — socle public + son propre espace (7 a 12)
    // =====================================================================

    /** 7. `public` + `open` : visible. */
    public function test_7_member_sees_a_public_open_loop(): void
    {
        $loop = $this->loop('public', Loop::ACCESS_OPEN);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertPresente($response, $loop, 'Le socle public vaut aussi pour un membre.');
    }

    /** 8. `public` + `request` : visible. */
    public function test_8_member_sees_a_public_request_loop(): void
    {
        $loop = $this->loop('public', Loop::ACCESS_REQUEST);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertPresente($response, $loop, 'Le socle public vaut aussi pour un membre.');
    }

    /**
     * 9. `private` + `invitation`, DONT IL EST MEMBRE : visible.
     *
     * C'est l'ajout membre. Cette Boucle fait reellement partie de son espace,
     * et la lui cacher rendrait la carte fausse pour elle.
     */
    public function test_9_member_sees_a_private_invitation_loop_they_belong_to(): void
    {
        $loop = $this->rendreMembre($this->loop('private', Loop::ACCESS_INVITATION));

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertPresente($response, $loop, 'Une Boucle dont on est membre fait partie de son espace.');
    }

    /**
     * 10. `private` + `request`, DONT IL EST MEMBRE : visible.
     *
     * Et le libelle doit dire `member`, pas `request` : la personne est deja
     * dedans, lui proposer de demander a entrer serait absurde.
     */
    public function test_10_member_sees_a_private_request_loop_they_belong_to(): void
    {
        $loop = $this->rendreMembre($this->loop('private', Loop::ACCESS_REQUEST));

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertPresente($response, $loop, 'Idem : son espace, quelle que soit la modalite d\'entree.');

        $noeud = collect($this->graph($response)['nodes'])->firstWhere('data.loop_id', $loop->id);

        $this->assertSame(
            'member',
            $noeud['data']['access'] ?? null,
            "Le libelle doit dire l'appartenance, jamais reproposer d'entrer.",
        );
    }

    /** 11. `private` + `invitation`, SANS appartenance : ABSENTE. */
    public function test_11_member_never_sees_a_private_invitation_loop_they_do_not_belong_to(): void
    {
        $loop = $this->loop('private', Loop::ACCESS_INVITATION);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertAbsente($response, $loop, "L'ajout membre n'ajoute QUE ses propres Boucles.");
    }

    /**
     * 12. `private` + `request`, SANS appartenance : ABSENTE.
     *
     * Le cas ou le flowchart diverge VOLONTAIREMENT du catalogue interne, qui
     * la nomme (TASK-1075). La page est publique ; le catalogue ne l'est pas.
     */
    public function test_12_member_never_sees_a_private_request_loop_they_do_not_belong_to(): void
    {
        $loop = $this->loop('private', Loop::ACCESS_REQUEST);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $this->assertAbsente($response, $loop, 'Privee sans appartenance : hors carte, meme pour un membre du tenant.');
    }

    // =====================================================================
    // C. SECURITE DU PAYLOAD (13 a 16)
    //
    // Une Boucle absente l'est du tableau PHP, du JSON, du DOM et des
    // attributs. Le navigateur ne doit pas pouvoir apprendre qu'elle existe.
    // =====================================================================

    /** 13. Le NOM d'une Boucle privee n'atteint jamais le navigateur. */
    public function test_13_the_name_of_a_private_loop_never_reaches_the_browser(): void
    {
        $loop = $this->loop('private', Loop::ACCESS_REQUEST);
        $loop->update(['name' => 'Cartographie Confidentielle 1608']);

        // TASK-1692 — l'anonyme n'obtient plus la page du tout : son refus est
        // mesure d'abord, et on verifie que la reponse de refus ne porte rien.
        // Puis le membre, pour qui la page EXISTE : c'est la que l'assertion
        // garde tout son mordant, parce qu'il y a un rendu a inspecter.
        $this->get($this->url())
            ->assertRedirect(route('organization.login', ['organization' => $this->organization->slug]))
            ->assertDontSee('Cartographie Confidentielle 1608', false);

        $this->actingAs($this->membre)->get($this->url())
            ->assertOk()
            ->assertDontSee('Cartographie Confidentielle 1608', false);
    }

    /** 14. Son SLUG non plus. */
    public function test_14_the_slug_of_a_private_loop_never_reaches_the_browser(): void
    {
        $loop = $this->loop('private', Loop::ACCESS_REQUEST);
        $loop->update(['slug' => 'slug-confidentiel-1608']);

        $this->get($this->url())
            ->assertRedirect(route('organization.login', ['organization' => $this->organization->slug]))
            ->assertDontSee('slug-confidentiel-1608', false);

        $this->actingAs($this->membre)->get($this->url())
            ->assertOk()
            ->assertDontSee('slug-confidentiel-1608', false);
    }

    /** 15. Son IDENTIFIANT non plus — ni en node, ni en `data-*`, ni en URL. */
    public function test_15_the_id_of_a_private_loop_never_reaches_the_browser(): void
    {
        $loop = $this->loop('private', Loop::ACCESS_INVITATION);

        $this->get($this->url())
            ->assertRedirect(route('organization.login', ['organization' => $this->organization->slug]))
            ->assertDontSee($loop->id, false);

        $this->actingAs($this->membre)->get($this->url())
            ->assertOk()
            ->assertDontSee($loop->id, false);
    }

    /**
     * 16. Aucune ARETE ne pointe vers une Boucle retiree, et aucune arete
     * n'est orpheline.
     *
     * Une arete vers un noeud absent revelerait son existence et casserait le
     * graphe. Les deux se mesurent ici.
     */
    public function test_16_no_edge_points_at_a_removed_loop(): void
    {
        $retirees = [
            $this->loop('private', Loop::ACCESS_OPEN),
            $this->loop('private', Loop::ACCESS_REQUEST),
            $this->loop('private', Loop::ACCESS_INVITATION),
            $this->loop('public', Loop::ACCESS_INVITATION),
        ];

        // Une Boucle legitime, pour que le graphe ne soit pas vide par accident.
        $this->loop('public', Loop::ACCESS_OPEN);

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $graph = $this->graph($response);

        $idsPresents = array_map(static fn (array $n): string => (string) $n['data']['id'], $graph['nodes']);

        foreach ($graph['edges'] as $edge) {
            $source = (string) $edge['data']['source'];
            $target = (string) $edge['data']['target'];

            foreach ($retirees as $loop) {
                $this->assertNotContains(
                    'loop:'.$loop->id,
                    [$source, $target],
                    'Une arete cite une Boucle retiree du graphe.',
                );
            }

            $this->assertContains($source, $idsPresents, "Arete orpheline : source `{$source}`.");
            $this->assertContains($target, $idsPresents, "Arete orpheline : cible `{$target}`.");
        }
    }

    // =====================================================================
    // D. CROSS-TENANT (17)
    // =====================================================================

    /**
     * 17. Aucune Boucle d'une AUTRE Organization, meme parfaitement ouverte.
     *
     * Le cas le plus permissif possible — `public` + `open`, dans une
     * Organization elle-meme publique — reste hors du tenant, invite comme
     * membre. Et un membre de l'autre Organization qui visite cette page-ci ne
     * ramene pas ses Boucles avec lui.
     */
    public function test_17_no_loop_from_another_organization_ever_appears(): void
    {
        $etrangere = $this->loop('public', Loop::ACCESS_OPEN, $this->autreOrganization);

        $etranger = User::factory()->complete()->create([
            'organization_id' => $this->autreOrganization->id,
        ]);
        $this->rendreMembre($etrangere, $etranger);

        // TASK-1692 — les trois viseurs n'ont plus le meme statut, et c'est
        // le coeur du contrat : seul le membre du tenant obtient un rendu.
        // Les deux autres sont refuses, chacun de la facon qui ne revele rien.

        // 1. Anonyme : redirige vers le login borne.
        $this->get($this->url())
            ->assertRedirect(route('organization.login', ['organization' => $this->organization->slug]))
            ->assertDontSee($etrangere->name, false)
            ->assertDontSee($etrangere->slug, false)
            ->assertDontSee($etrangere->id, false);

        // 2. Membre du tenant : la page existe, et la Boucle etrangere n'y est
        //    ni dans le payload, ni dans le DOM.
        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();
        $this->assertAbsente($response, $etrangere, 'Une Boucle d\'un autre tenant n\'entre jamais sur cette carte.');

        // 3. Membre de l'AUTRE Organization : 404, pas d'oracle — et il ne
        //    ramene pas sa Boucle avec lui.
        $this->actingAs($etranger->fresh())->get($this->url())
            ->assertNotFound()
            ->assertDontSee($etrangere->name, false)
            ->assertDontSee($etrangere->slug, false)
            ->assertDontSee($etrangere->id, false);
    }

    // =====================================================================
    // E. `loop_mode = mono` ne masque RIEN du socle public
    // =====================================================================

    /**
     * Une Organization mono-Boucle sert le socle public comme les autres.
     *
     * Correction MASTER du 2026-09-20 : `loop_mode = mono` est une regle du
     * CATALOGUE INTERNE — `VisibleLoops::groupedFor()` y vide l'ensemble
     * « autres », parce que `LoopController::index()` redirige au lieu de
     * lister. Le flowchart n'est pas ce catalogue : il a son propre role de
     * decouverte publique, et s'en servir pour masquer une Boucle qui
     * satisfait explicitement le socle public serait detourner une regle
     * d'une surface vers une autre.
     *
     * Cas mesure en base sur `launchpals` (`loop_mode = mono`) : `Welcome loop`
     * est `public` + `open`. Elle DOIT apparaitre, invite compris. Sa voisine
     * `QA Gouvernance 1079` est `private` + `invitation` — et reste absente.
     */
    public function test_a_mono_loop_organization_still_serves_the_public_base_to_its_members(): void
    {
        $mono = Organization::factory()->create([
            'slug' => 'org-1608-mono',
            'name' => 'Org 1608 Mono',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'loop_mode' => 'mono',
        ]);

        $bienvenue = $this->loop('public', Loop::ACCESS_OPEN, $mono);
        $gouvernance = $this->loop('private', Loop::ACCESS_INVITATION, $mono);

        $mono->update(['primary_loop_id' => $gouvernance->id]);

        // TASK-1692 — le socle public est desormais servi aux MEMBRES de
        // l'Organization. Ce que ce test prouve n'a pas bouge : `loop_mode =
        // mono` reste une regle du catalogue interne, et ne retire pas du
        // socle une Boucle qui le satisfait. Seul le viseur a change.
        $membreMono = User::factory()->complete()->create([
            'organization_id' => $mono->id,
        ]);

        $response = $this->actingAs($membreMono)->get($this->url($mono));
        $response->assertOk();

        $this->assertPresente(
            $response,
            $bienvenue,
            '`loop_mode = mono` est une regle du catalogue interne, pas du socle public.',
        );
        $this->assertAbsente($response, $gouvernance, 'La Boucle privee sur invitation reste hors carte.');
    }

    // =====================================================================
    // F. Le graphe structurel existe, pour tout le monde
    // =====================================================================

    /**
     * La carte porte bien un graphe — pas une page vide.
     *
     * CHANGEMENT D'INTENTION PRODUIT — TASK-1692. Ce test s'appelait
     * `test_the_structural_graph_is_served_to_everyone` et le premier mot du
     * commentaire etait « La page est publique ». Elle ne l'est plus : la
     * carte prefixee est reservee aux membres de son Organization. Le graphe
     * structurel — une racine, les quatre portes du §4 — est inchange, c'est
     * son public qui l'est.
     */
    public function test_the_structural_graph_is_served_to_every_member(): void
    {
        // L'ANONYME D'ABORD : `actingAs()` ne se defait pas, une requete posee
        // apres lui n'est plus anonyme. Sans ce volet, le test serait vert
        // meme si la carte s'etait rouverte a tout le monde.
        $this->get($this->url())
            ->assertRedirect(route('organization.login', ['organization' => $this->organization->slug]));

        $response = $this->actingAs($this->membre)->get($this->url());
        $response->assertOk();

        $graph = $this->graph($response);

        $racines = array_filter($graph['nodes'], static fn (array $n): bool => $n['data']['kind'] === 'root');
        $intentions = array_filter($graph['nodes'], static fn (array $n): bool => $n['data']['kind'] === 'intent');

        $this->assertCount(1, $racines, 'Le graphe doit avoir un point d\'entree unique.');
        $this->assertCount(4, $intentions, 'Les quatre portes du §4 doivent etre presentes.');
        $this->assertNotEmpty($graph['edges'], "Un graphe sans arete n'est pas un graphe.");
    }
}
