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
 * TASK-1488 (P0 privacy) — la fiche d'un Service, celle d'une Demande et la
 * recherche rejoignent la frontiere d'Organization posee par TASK-1479.
 *
 * ## Ce qui a ete mesure AVANT tout code, au HEAD 497d934b
 *
 * Sans aucun cookie, sur une Organization `is_public = false` :
 *
 * | Surface | Statut | Ce qui etait rendu |
 * |---|---|---|
 * | `/org/{slug}/services/{uuid}` | **200** | nom reel du proprietaire, titre, contenu |
 * | `/org/{slug}/requests/{uuid}` | **200** | idem |
 * | `/services/{uuid}` | **200** | idem |
 * | `/requests/{uuid}` | **200** | idem |
 * | `/search?q=` | **200** | titres Services + Demandes, nom complet, ville et note des membres |
 *
 * Et, authentifie : un membre de l'Organization **B** obtenait **200** sur la
 * fiche d'un Service de l'Organization **A**.
 *
 * ## L'autorite invoquee n'existait pas
 *
 * Le bloc portait le commentaire « Public organization-scoped detail routes
 * used by Explorer ». Verifie plutot que cru :
 *
 * - l'Explorer est `auth` + `organization.member` depuis TASK-1479 ;
 * - `profile.show`, sur la ligne SUIVANTE du meme bloc, porte deja la garde —
 *   TASK-1479 avait ferme un tiers du bloc et laisse deux routes ;
 * - `docs/05-DOMAIN_ARCHITECTURE.md` dit **« Public != global »** et ne classe
 *   `/services` et `/requests` que par le tenant RESOLU, jamais par le lecteur
 *   autorise.
 *
 * Seul un NOM DE TEST — `test_anyone_can_view_active_service` — affirmait
 * l'acces invite. C'est exactement l'artefact que TASK-1479 a reecrit sous
 * arbitrage MASTER : « profil public » veut dire visible des autres MEMBRES.
 *
 * ## Ou etait le defaut
 *
 * PAS dans la requete SQL, comme pour TASK-1479. `ServiceController@show`
 * verifie la coherence de tenant — donc l'Organization que l'URL DESIGNE. Elle
 * sert ce tenant a qui le demande, sans jamais demander qui le demande. C'est
 * pourquoi le membre de B passe : l'URL dit A, le Service est bien dans A.
 *
 * Le correctif porte donc uniquement sur QUI atteint le controleur. Aucune
 * donnee ne change de visibilite, aucun champ n'est masque, aucune requete
 * n'est reecrite, aucune migration.
 *
 * ## /search : un contournement, pas une extension de perimetre
 *
 * Inclus sur decision MASTER. `/search` rendait les MEMES champs membres
 * (`fullName`, `public_location`) que `/membres`, ferme par TASK-1479 — et sans
 * exiger le moindre UUID. Fermer les quatre fiches en le laissant ouvert aurait
 * ete le demi-correctif que le precedent nomme explicitement.
 *
 * ## Hors perimetre, signale et NON decide
 *
 * `/api/services/{uuid}` et `/api/requests/{uuid}` rendent le meme dossier en
 * JSON a un anonyme, `user.name` / `bio` / `location` compris. Mais cette
 * surface porte un contrat ECRIT et TESTE (« Public read-only endpoints »,
 * `ApiTenantScopingTest`). Le modifier ici serait une decision produit prise a
 * l'occasion d'un correctif. Classee par MASTER
 * SECURITY_PRODUCT_DECISION_REQUIRED_BEFORE_STABILIZATION.
 */
class TASK1488ServiceRequestPrivacyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $memberA;

    private User $memberB;

    private Service $service;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_public' => false, 'slug' => 'task1488-a']);
        $this->orgB = Organization::factory()->create(['is_public' => false, 'slug' => 'task1488-b']);

        $this->memberA = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'name' => 'Jeanne Mesuree',
            'is_admin' => false,
        ]);
        $this->memberB = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'is_admin' => false,
        ]);

        $category = Category::factory()->create(['organization_id' => $this->orgA->id]);

        $this->service = Service::factory()->forUser($this->memberA)->forCategory($category)->create([
            'organization_id' => $this->orgA->id,
            'title' => 'Accompagnement mesure TASK1488',
        ]);

        $this->request = ServiceRequest::factory()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->memberA->id,
            'title' => 'Demande mesuree TASK1488',
        ]);
    }

    /**
     * Les quatre fiches, sous leurs DEUX formes, ne sont plus servies a un
     * anonyme. La forme non prefixee compte autant que l'autre : c'est celle
     * qu'un moteur de recherche indexerait.
     */
    public function test_a_guest_never_reads_a_service_or_request_detail(): void
    {
        foreach ($this->detailUrls() as $label => $url) {
            $response = $this->get($url);

            $this->assertNotSame(
                200,
                $response->getStatusCode(),
                "[$label] rend encore 200 a un visiteur anonyme."
            );
        }
    }

    /**
     * La mesure de la fuite elle-meme, pas seulement du statut : le nom reel et
     * le contenu metier ne doivent atteindre aucun anonyme.
     *
     * Un `assertDontSee` sur le seul statut passerait si la page devenait une
     * erreur 500 qui rend quand meme le nom.
     */
    public function test_no_human_name_or_business_content_reaches_a_guest(): void
    {
        foreach ($this->detailUrls() as $label => $url) {
            $body = $this->get($url)->getContent();

            $this->assertStringNotContainsString('Jeanne Mesuree', $body, "[$label] laisse fuir le nom du proprietaire.");
            $this->assertStringNotContainsString('TASK1488', $body, "[$label] laisse fuir le contenu metier.");
        }
    }

    /**
     * Un membre de l'Organization lit normalement les fiches de la sienne. Le
     * correctif ne doit rien fermer d'utile : c'est la moitie du contrat.
     */
    public function test_a_member_reads_the_details_of_their_own_organization(): void
    {
        foreach ($this->detailUrls() as $label => $url) {
            $this->actingAs($this->memberA)
                ->get($url)
                ->assertOk();
        }
    }

    /**
     * Le coeur du fichier. `auth` seul rendrait ce test VERT alors que la fuite
     * cross-tenant AUTHENTIFIEE resterait ouverte — elle etait mesuree a 200 au
     * HEAD. C'est la section qui prouve que les DEUX gardes sont necessaires.
     */
    public function test_a_member_of_another_organization_is_refused(): void
    {
        foreach ($this->detailUrls() as $label => $url) {
            $response = $this->actingAs($this->memberB)->get($url);

            $this->assertNotSame(
                200,
                $response->getStatusCode(),
                "[$label] sert l'Organization A a un membre de l'Organization B."
            );
            $this->assertStringNotContainsString(
                'Jeanne Mesuree',
                $response->getContent(),
                "[$label] laisse fuir un nom de l'Organization A vers un membre de B."
            );
        }
    }

    /**
     * `/search` : le contournement. Un anonyme n'obtient plus ni le titre du
     * Service, ni celui de la Demande, ni le nom du membre.
     */
    public function test_search_is_closed_to_a_guest(): void
    {
        $response = $this->get('/search?q=TASK1488');

        $this->assertNotSame(200, $response->getStatusCode(), '/search rend encore 200 a un anonyme.');
        $this->assertStringNotContainsString('Jeanne Mesuree', $response->getContent());
        $this->assertStringNotContainsString('TASK1488', $response->getContent());
    }

    /** La recherche reste pleinement utilisable par un membre. */
    public function test_search_still_serves_a_member_of_the_organization(): void
    {
        $this->actingAs($this->memberA)
            ->get('/search?q=TASK1488')
            ->assertOk()
            ->assertSee('Accompagnement mesure TASK1488');
    }

    /**
     * Les cinq surfaces, chacune sous la forme qui la designe.
     *
     * @return array<string, string>
     */
    private function detailUrls(): array
    {
        return [
            'organization.services.show' => route('organization.services.show', [$this->orgA, $this->service]),
            'organization.requests.show' => route('organization.requests.show', [$this->orgA, $this->request]),
            'services.show' => route('services.show', $this->service),
            'requests.show' => route('requests.show', $this->request),
        ];
    }
}
