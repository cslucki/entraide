<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiConstitution;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\NervousSystemMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1481 — le PLAN de la gouvernance IA d'une Organization.
 *
 * ## Ce que la mesure a montre AVANT d'ecrire une ligne
 *
 * Le « systeme nerveux » n'etait pas a construire : il existe.
 * `PlatformAiConstitution`, `OrganizationAiConstitution`,
 * `OrganizationAiDoctrine`, `CapabilityRegistry` et `NervousSystemCoverage`
 * sont en place depuis TASK-1227 et TASK-1348, et `ai-behavior` en montre deja
 * une partie.
 *
 * Le manque etait ailleurs : repondre en UNE page a « pourquoi l'IA de cette
 * Organization se comporte-t-elle ainsi, et ou se regle chaque regle ? »
 * demandait de connaitre une dizaine d'URL.
 *
 * ## La regle qui empeche une carte fictive
 *
 * Un noeud n'existe que si son autorite existe, et un lien n'est propose que
 * si sa route existe. Un plan qui pointerait vers une route absente serait
 * pire qu'un plan sans lien.
 *
 * ## `locked` / `configurable` sont MESURES
 *
 * La tentation etait une table de verite ecrite a la main — qui aurait menti
 * au premier changement de route. L'etat est derive d'un fait verifiable :
 * existe-t-il une route d'ECRITURE, dans cette zone d'administration, pour
 * cette autorite ? La section C le sabote.
 */
class TASK1481NervousSystemMapTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-1481',
            'name' => 'Org 1481',
        ]);

        $this->orgAdmin = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->organization->forceFill(['admin_id' => $this->orgAdmin->id])->save();

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le plan nomme des autorites REELLES
    // =====================================================================

    public function test_every_node_names_an_authority_that_exists(): void
    {
        $nodes = $this->map();

        $this->assertNotEmpty($nodes);

        foreach ($nodes as $node) {
            $this->assertContains($node['level'], [
                NervousSystemMap::LEVEL_PLATFORM,
                NervousSystemMap::LEVEL_ORGANIZATION,
                NervousSystemMap::LEVEL_USER,
            ], $node['key']);

            $this->assertContains($node['state'], [NervousSystemMap::STATE_LOCKED, NervousSystemMap::STATE_CONFIGURABLE], $node['key']);

            // Chaque noeud porte un libelle et une explication, FR et EN.
            foreach (['fr', 'en'] as $locale) {
                app()->setLocale($locale);
                foreach (['ai.map_node_'.$node['key'], 'ai.map_node_'.$node['key'].'_hint'] as $key) {
                    $this->assertNotSame($key, __($key), "[{$locale}] {$key}");
                }
            }
            app()->setLocale('fr');
        }
    }

    /** Un lien n'est propose que s'il mene quelque part. */
    public function test_no_node_points_at_a_route_that_does_not_exist(): void
    {
        foreach ($this->map() as $node) {
            if ($node['admin_url'] === null) {
                continue;
            }

            $this->assertStringStartsWith('http', $node['admin_url'], $node['key']);
        }
    }

    // =====================================================================
    // B. Le plan dit la VERITE de cette Organization
    // =====================================================================

    /** Sans version active, le noeud le DIT — il ne disparait pas, il n'invente pas. */
    public function test_an_authority_without_an_active_version_says_so(): void
    {
        $doctrine = $this->node('doctrine');

        $this->assertSame(__('ai.map_status_none'), $doctrine['status']);
    }

    public function test_an_active_version_is_named_by_its_number(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'Parlez simplement, sans jargon.', $this->orgAdmin);
        OrganizationAiConstitution::activate($this->organization, 'Ne promettez jamais une date.', $this->orgAdmin);

        $this->assertSame('v1', $this->node('doctrine')['status']);
        $this->assertSame('v1', $this->node('organization_constitution')['status']);
    }

    /**
     * Le modele est nomme ; la CLE ne l'est jamais. Un plan de gouvernance
     * n'est pas un endroit ou un secret peut transiter, meme par accident.
     */
    public function test_the_provider_node_names_the_model_and_never_the_key(): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-task1481',
        ]);

        $node = $this->node('provider');

        $this->assertSame('gpt-4o-mini', $node['status']);

        $html = $this->page();
        $this->assertStringContainsString('gpt-4o-mini', $html);
        $this->assertStringNotContainsString('sk-secret-task1481', $html);
    }

    /** La couverture vient de l'autorite existante, pas d'un compte parallele. */
    public function test_the_capabilities_node_reads_the_existing_coverage(): void
    {
        $coverage = app(\App\Ai\NervousSystemCoverage::class);

        $this->assertSame(
            __('ai.map_status_coverage', ['covered' => $coverage->coveredCount(), 'total' => $coverage->totalCount()]),
            $this->node('capabilities')['status'],
        );
    }

    // =====================================================================
    // C. `locked` / `configurable` sont derives, pas declares
    // =====================================================================

    /**
     * La Constitution PLATEFORME s'applique a l'Organization et se gouverne
     * ailleurs : aucune route d'ecriture dans cette zone.
     */
    public function test_the_platform_constitution_is_locked_for_an_organization_admin(): void
    {
        $this->assertSame(NervousSystemMap::STATE_LOCKED, $this->node('platform_constitution')['state']);
        $this->assertSame(NervousSystemMap::LEVEL_PLATFORM, $this->node('platform_constitution')['level']);
    }

    /** Ce que cet Admin peut reellement changer est marque configurable. */
    public function test_what_this_admin_can_change_is_marked_configurable(): void
    {
        foreach (['organization_constitution', 'doctrine', 'provider'] as $key) {
            $this->assertSame(NervousSystemMap::STATE_CONFIGURABLE, $this->node($key)['state'], $key);
        }
    }

    /**
     * **Le test central.** L'etat n'est pas ecrit a la main : il est derive de
     * l'existence d'une route d'ecriture. On le PROUVE en mesurant que chaque
     * noeud `configurable` a bien une route d'ecriture declaree, et que chaque
     * noeud `locked` n'en a aucune dans cette zone.
     */
    public function test_the_state_matches_the_routes_that_actually_exist(): void
    {
        $writeRoutes = [
            'organization_constitution' => 'organization.admin.ai-behavior.constitution.update',
            'doctrine' => 'organization.admin.ai-behavior.doctrine.update',
            'provider' => 'organization.admin.ai.update',
            'consumption' => 'organization.admin.ai.user-credit.update',
        ];

        foreach ($this->map() as $node) {
            $expected = isset($writeRoutes[$node['key']]) && Route::has($writeRoutes[$node['key']])
                ? NervousSystemMap::STATE_CONFIGURABLE
                : NervousSystemMap::STATE_LOCKED;

            $this->assertSame($expected, $node['state'], "[{$node['key']}] : l'etat suit les routes, pas une table ecrite a la main");
        }
    }

    /**
     * Le registre des capabilities est du CODE. Qu'aucune route ne l'edite
     * n'est pas un oubli : c'est ce qui garantit qu'une capability ne
     * s'invente pas depuis une interface.
     */
    public function test_the_capability_registry_has_no_write_route_at_all(): void
    {
        $this->assertSame(NervousSystemMap::STATE_LOCKED, $this->node('capabilities')['state']);

        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => ! in_array('GET', $r->methods(), true))
            ->map(fn ($r) => (string) $r->getName())
            ->filter(fn ($n) => str_contains($n, 'capabilit'));

        $this->assertTrue($writeRoutes->isEmpty(), 'aucune route ne doit ecrire le registre des capabilities');
    }

    // =====================================================================
    // D. La page : lecture seule, et rien d'autre
    // =====================================================================

    public function test_the_page_renders_every_node_for_the_organization_admin(): void
    {
        $html = $this->page();

        foreach ($this->map() as $node) {
            $this->assertStringContainsString('data-ai-map-node="'.$node['key'].'"', $html, $node['key']);
        }

        $this->assertStringContainsString('data-ai-map', $html);
        $this->assertStringContainsString(e(__('ai.map_footer')), $html);
    }

    /** Aucun formulaire, aucun bouton d'ecriture : c'est un plan, pas un editeur. */
    public function test_the_page_writes_nothing(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('<form', $html === '' ? 'x' : $this->betweenMapMarkers($html));
        $this->assertStringNotContainsString('csrf', $this->betweenMapMarkers($html));
    }

    /** Et le code de la page ne connait aucune autorite d'ecriture. */
    public function test_the_map_reaches_for_no_writer(): void
    {
        $code = php_strip_whitespace(app_path('Support/Ai/NervousSystemMap.php'));

        foreach (['->save(', '::create(', 'update(', 'delete(', 'activate('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, $forbidden.' : un plan ne modifie rien');
        }
    }

    /** Le lien plateforme n'est propose qu'a qui peut l'ouvrir. */
    public function test_the_platform_link_is_not_offered_to_a_simple_organization_admin(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-ai-map-link-restricted="platform_constitution"', $html);
        $this->assertStringNotContainsString('data-ai-map-link="platform_constitution"', $html);
        $this->assertStringContainsString(e(__('ai.map_platform_only')), $html);
    }

    public function test_a_platform_admin_gets_the_link(): void
    {
        $superAdmin = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $html = $this->actingAs($superAdmin)
            ->get(route('organization.admin.ai-map', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-map-link="platform_constitution"', $html);
    }

    /** La zone reste celle de l'Admin d'Organization. */
    public function test_a_simple_member_never_reaches_the_map(): void
    {
        $member = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($member)
            ->get(route('organization.admin.ai-map', ['organization' => $this->organization->slug]));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    /** Aucune migration : un plan ne stocke rien. */
    public function test_no_migration_ships_with_this_slice(): void
    {
        $this->assertSame([], glob(database_path('migrations/*nervous_system*.php')) ?: []);
        $this->assertSame([], glob(database_path('migrations/*ai_map*.php')) ?: []);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function map(): array
    {
        return app(NervousSystemMap::class)->forOrganization($this->organization->fresh());
    }

    private function node(string $key): array
    {
        foreach ($this->map() as $node) {
            if ($node['key'] === $key) {
                return $node;
            }
        }

        $this->fail("noeud absent du plan : {$key}");
    }

    private function page(): string
    {
        return $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.ai-map', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** Le layout porte des formulaires (recherche, deconnexion) : on mesure la LISTE. */
    private function betweenMapMarkers(string $html): string
    {
        $start = strpos($html, 'data-ai-map');
        $end = strpos($html, 'data-ai-map-footer');

        return $start !== false && $end !== false && $end > $start
            ? substr($html, $start, $end - $start)
            : $html;
    }
}
