<?php

namespace Tests\Feature;

use App\Http\Controllers\SitemapController;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1673 — `/sitemap.xml` ne publie plus que des surfaces REELLEMENT
 * publiques.
 *
 * Deux decisions P0 privacy ont ferme au Web anonyme la fiche d'un membre
 * (TASK-1479) et celle d'un Service (TASK-1488). Le sitemap, ecrit en
 * TASK-007, continuait de les annoncer aux moteurs : 54 URL dont 53
 * redirigeaient vers `/login`, et 52 UUID utilisateurs de trois Organizations
 * etaient exposes dans un document public declare par `robots.txt`.
 *
 * Ces tests gardent les quatre regles d'inclusion, et surtout la promesse
 * NEGATIVE : ce que le sitemap ne doit plus jamais contenir.
 */
class TASK1673SitemapPublicSurfacesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les huit surfaces attendues, URI telle qu'un crawler la lit.
     */
    private const SURFACES_ATTENDUES = [
        '/',
        '/blog',
        '/boucles',
        '/partenaires',
        '/about',
        '/aide',
        '/open-source',
        '/mentions-legales',
    ];

    private function xml(): string
    {
        $reponse = $this->get('/sitemap.xml');
        $reponse->assertOk();

        return $reponse->getContent();
    }

    /**
     * @return list<string>
     */
    private function locs(): array
    {
        preg_match_all('#<loc>(.*?)</loc>#', $this->xml(), $m);

        return $m[1];
    }

    // -------------------------------------------------------------------------
    // 1. La reponse
    // -------------------------------------------------------------------------

    public function test_le_sitemap_repond_200_en_xml(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml');
    }

    public function test_le_sitemap_est_un_xml_bien_forme(): void
    {
        $precedent = libxml_use_internal_errors(true);

        $document = simplexml_load_string($this->xml());

        libxml_use_internal_errors($precedent);

        $this->assertNotFalse($document, 'Le sitemap doit etre un XML valide.');
    }

    // -------------------------------------------------------------------------
    // 2. Les promesses NEGATIVES — ce qui ne doit plus jamais y figurer
    // -------------------------------------------------------------------------

    public function test_aucune_url_de_profil_n_est_publiee(): void
    {
        // Des membres existent, et nombreux : le vide ne vient pas d'une base
        // vide mais du generateur.
        $org = Organization::factory()->create();
        User::factory()->count(3)->create(['organization_id' => $org->id]);

        $xml = $this->xml();

        $this->assertStringNotContainsString('/profile/', $xml);
    }

    public function test_aucune_url_de_service_n_est_publiee(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
            'status' => 'active',
        ]);

        $xml = $this->xml();

        $this->assertStringNotContainsString('/services/', $xml);
    }

    public function test_explorer_n_est_pas_publie_car_son_acces_anonyme_redirige(): void
    {
        // UNE ORGANIZATION DOIT EXISTER, et ce n'est pas un detail de
        // confort. Sur une base vide, `/explorer` ne passe jamais par `auth` :
        // il est a la fois dans `$defaultOrganizationRoutes` et dans
        // `$passthroughNoOrgRoutes`, donc `ResolveUrlOrganization` court-circuite
        // sur la vue `members.setup-required` et rend **200**
        // (`ResolveUrlOrganization.php:130`). Mesure faite : sans cette ligne,
        // le test observait 200 et aurait pu faire croire que `/explorer` est
        // public. Sur l'application reelle, qui a une Organization par defaut,
        // la reponse anonyme est bien **302 vers `/login`**.
        Organization::factory()->create(['is_default' => true, 'is_active' => true]);

        // La CONDITION est verifiee, pas supposee : si `/explorer` devenait
        // public un jour, ce test le dirait au lieu de defendre un dogme.
        $this->get('/explorer')->assertRedirect(route('login'));

        $this->assertStringNotContainsString('/explorer', $this->xml());
    }

    public function test_abonnements_n_est_pas_publie_car_la_route_rend_404(): void
    {
        $this->get('/abonnements')->assertNotFound();

        $this->assertStringNotContainsString('/abonnements', $this->xml());
    }

    public function test_aucun_uuid_n_est_expose_dans_le_xml(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
            'status' => 'active',
        ]);

        $xml = $this->xml();

        $this->assertSame(
            0,
            preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $xml),
            'Le sitemap ne doit exposer AUCUN identifiant.'
        );
        $this->assertStringNotContainsString((string) $user->id, $xml);
    }

    // -------------------------------------------------------------------------
    // 3. Les promesses POSITIVES — et chaque URL est verifiee ANONYMEMENT
    // -------------------------------------------------------------------------

    public function test_les_huit_surfaces_publiques_sont_publiees(): void
    {
        $chemins = array_map(
            fn (string $loc) => '/'.ltrim(parse_url($loc, PHP_URL_PATH) ?? '/', '/'),
            $this->locs()
        );

        // `/` se normalise en chaine vide par `ltrim` : on le remet.
        $chemins = array_map(fn (string $c) => $c === '/' ? '/' : rtrim($c, '/'), $chemins);

        foreach (self::SURFACES_ATTENDUES as $attendu) {
            $this->assertContains($attendu, $chemins, "Le sitemap doit publier $attendu.");
        }

        $this->assertCount(count(self::SURFACES_ATTENDUES), $chemins,
            'Le sitemap ne doit publier QUE ces surfaces.');
    }

    public function test_chaque_url_publiee_repond_200_a_un_visiteur_anonyme(): void
    {
        $locs = $this->locs();

        $this->assertNotEmpty($locs);

        foreach ($locs as $loc) {
            $chemin = parse_url($loc, PHP_URL_PATH) ?: '/';

            // AUCUNE authentification : c'est la regle d'inclusion n.2.
            $this->get($chemin)->assertOk();
        }
    }

    public function test_les_noms_de_route_declares_existent_tous(): void
    {
        // Sans ce test, un renommage de route ferait DISPARAITRE une URL en
        // silence : le controleur la saute et journalise, ce qui protege la
        // production mais ne previendrait personne. Ici, la CI rougit.
        $sonde = new class extends SitemapController
        {
            /** @return list<array{name: string, changefreq: string, priority: string}> */
            public function surfaces(): array
            {
                return $this->publicSurfaces();
            }
        };

        $surfaces = $sonde->surfaces();

        $this->assertCount(count(self::SURFACES_ATTENDUES), $surfaces);

        foreach ($surfaces as $surface) {
            $this->assertTrue(
                Route::has($surface['name']),
                "La route declaree {$surface['name']} doit exister."
            );
        }
    }

    public function test_une_route_declaree_absente_est_omise_et_journalisee(): void
    {
        // La garde est EXERCEE sur le vrai `index()`, grace a la couture
        // `publicSurfaces()` : on observe son effet, on ne recopie pas sa
        // logique dans le test.
        $this->assertFalse(Route::has('route.qui.n.existe.pas'));

        $controleur = new class extends SitemapController
        {
            protected function publicSurfaces(): array
            {
                return [
                    ['name' => 'home', 'changefreq' => 'daily', 'priority' => '1.0'],
                    ['name' => 'route.qui.n.existe.pas', 'changefreq' => 'daily', 'priority' => '0.5'],
                ];
            }
        };

        Log::spy();

        $xml = $controleur->index()->getContent();

        // L'URL manquante est omise, et `/` survit : un nom casse n'emporte
        // pas le sitemap entier.
        $this->assertStringNotContainsString('route.qui.n.existe.pas', $xml);
        $this->assertStringContainsString('<loc>'.route('home').'</loc>', $xml);
        $this->assertSame(1, preg_match_all('#<loc>#', $xml));

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $contexte = null) => str_contains((string) $message, 'declared public route is missing')
                && is_array($contexte) && $contexte['route'] === 'route.qui.n.existe.pas'
        )->atLeast()->once();
    }

    // -------------------------------------------------------------------------
    // 4. Le scope tenant n'est ni sollicite ici, ni affaibli ailleurs
    // -------------------------------------------------------------------------

    public function test_la_route_sitemap_ne_produit_aucun_warning_de_scope_tenant(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
            'status' => 'active',
        ]);

        Log::spy();

        $this->get('/sitemap.xml')->assertOk();

        Log::shouldNotHaveReceived('warning', [
            'BelongsToOrganizationScope: no current Organization context; query denied fail-closed.',
            \Mockery::any(),
        ]);
    }

    public function test_le_fail_closed_reste_actif_ailleurs(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
            'status' => 'active',
        ]);

        // Hors contexte tenant : toujours RIEN, et le SQL porte la garde.
        $this->assertCount(0, Service::all());
        $this->assertStringContainsString('0 = 1', Service::query()->toSql());

        // Et la ligne existe bel et bien.
        $this->assertCount(1, Service::withoutGlobalScope(BelongsToOrganizationScope::class)->get());
    }
}
