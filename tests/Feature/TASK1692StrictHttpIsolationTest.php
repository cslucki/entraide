<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationMember;
use App\Http\Middleware\ResolveOrganization;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * TASK-1692 — la frontiere membre appartient au groupe `/org/{organization}`.
 *
 * La suite mesure l'ordre reel du pipeline, les deux sens du refus tenant, le
 * cas email non verifie, l'exemption SuperAdmin sans participation implicite,
 * et le VRAI endpoint Livewire. `Livewire::test()` n'est volontairement jamais
 * utilise : il ne rejoue pas les middlewares persistants.
 */
class TASK1692StrictHttpIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les destinations internes dont la presence, sur une surface publique,
     * signale que la navigation membre a fuite.
     */
    private const INTERNAL_DESTINATIONS = ['/dashboard', '/loops', '/agenda', '/explorer', '/messages', '/membres', '/blog'];

    private Organization $a;

    private Organization $b;

    private User $memberA;

    private User $memberB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create([
            'slug' => 't1692-a',
            'name' => 'Organisation T1692 A',
            'is_active' => true,
            'is_public' => true,
            'created_at' => now()->subDay(),
        ]);
        $this->b = Organization::factory()->create([
            'slug' => 't1692-b',
            'name' => 'Organisation T1692 B',
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
        ]);

        $this->memberA = User::factory()->complete()->create([
            'organization_id' => $this->a->id,
            'email_verified_at' => now(),
        ]);
        $this->memberB = User::factory()->complete()->create([
            'organization_id' => $this->b->id,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_a_effective_order_is_auth_then_member_then_verified_before_bindings(): void
    {
        $route = Route::getRoutes()->getByName('organization.dossiers.index');
        $this->assertNotNull($route);

        $middleware = app('router')->gatherRouteMiddleware($route);
        $position = static function (string $class) use ($middleware): int {
            foreach ($middleware as $index => $item) {
                if (str_starts_with($item, $class)) {
                    return $index;
                }
            }

            return -1;
        };

        $auth = $position(\Illuminate\Auth\Middleware\Authenticate::class);
        $member = $position(EnsureOrganizationMember::class);
        $verified = $position(EnsureEmailIsVerified::class);
        $bindings = $position(SubstituteBindings::class);

        foreach (compact('auth', 'member', 'verified', 'bindings') as $name => $index) {
            $this->assertGreaterThanOrEqual(0, $index, "middleware {$name} absent de la pile effective");
        }

        $this->assertLessThan($member, $auth, 'auth doit agir avant la frontiere membre');
        $this->assertLessThan($verified, $member, 'la frontiere membre doit agir avant verified');
        $this->assertLessThan($bindings, $member, 'aucun binding de modele ne doit preceder la frontiere membre');
    }

    public function test_a_framework_priority_keeps_its_existing_relative_order(): void
    {
        $frameworkOrder = [
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ];

        $effective = array_values(array_intersect(
            app('router')->middlewarePriority,
            $frameworkOrder,
        ));

        $this->assertSame($frameworkOrder, $effective,
            'TASK-1692 ne doit reordonner aucune priorite Laravel preexistante');
    }

    public function test_b_anonymous_member_and_both_cross_tenant_directions_are_distinct(): void
    {
        $urlB = route('organization.blog.index', ['organization' => $this->b->slug]);

        $this->get($urlB)
            ->assertRedirect(route('organization.login', ['organization' => $this->b->slug]));

        $this->forgetOrganization();
        $this->actingAs($this->memberB)->get($urlB)->assertOk();

        $this->forgetOrganization();
        $this->actingAs($this->memberA)->get($urlB)->assertNotFound();

        $this->forgetOrganization();
        $this->actingAs($this->memberB)
            ->get(route('organization.blog.index', ['organization' => $this->a->slug]))
            ->assertNotFound();
    }

    public function test_b_unknown_organization_and_refused_organization_keep_the_no_oracle_status(): void
    {
        $this->actingAs($this->memberA)
            ->get('/org/t1692-inexistante/blog')
            ->assertNotFound();

        $this->forgetOrganization();
        $this->actingAs($this->memberA)
            ->get(route('organization.blog.index', ['organization' => $this->b->slug]))
            ->assertNotFound();
    }

    public function test_c_foreign_unverified_user_is_refused_before_email_verification(): void
    {
        $unverifiedA = User::factory()->complete()->create([
            'organization_id' => $this->a->id,
            'email_verified_at' => null,
        ]);
        $unverifiedB = User::factory()->complete()->create([
            'organization_id' => $this->b->id,
            'email_verified_at' => null,
        ]);
        $url = route('organization.dossiers.index', ['organization' => $this->b->slug]);

        $this->actingAs($unverifiedA)->get($url)->assertNotFound();

        $this->forgetOrganization();
        $this->actingAs($unverifiedB)->get($url)->assertRedirectContains('verify-email');
    }

    public function test_d_superadmin_keeps_transverse_access_without_membership_side_effect(): void
    {
        $admin = User::factory()->complete()->create([
            'organization_id' => $this->a->id,
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);
        $before = LoopMember::query()->where('user_id', $admin->id)->count();

        $this->actingAs($admin)
            ->get(route('organization.flux', ['organization' => $this->b->slug]))
            ->assertOk();

        $this->assertSame($before, LoopMember::query()->where('user_id', $admin->id)->count());
        $this->assertSame($this->a->id, $admin->fresh()->organization_id,
            'l inspection transverse ne doit jamais faire participer le SuperAdmin au tenant visite');
    }

    public function test_e_membership_guard_is_persistent_and_blocks_the_real_livewire_endpoint(): void
    {
        $persistent = Livewire::getPersistentMiddleware();
        $this->assertContains(ResolveOrganization::class, $persistent);
        $this->assertContains(EnsureOrganizationMember::class, $persistent);

        $observations = $this->installMembershipProbe();
        $membersBefore = LoopMember::query()->count();
        $snapshot = $this->organizationFeedSnapshot($this->memberB, $this->b);
        $observations->exchangeArray([]);

        $this->livewireUpdate($this->memberB, $snapshot)->assertOk();
        $this->assertSame([
            'user_id' => $this->memberB->id,
            'organization_id' => $this->b->id,
            'path' => 'org/'.$this->b->slug.'/flux',
            'route' => 'organization.flux',
            'route_organization' => $this->b->slug,
        ], $observations[0]);

        // Deux appels MakesHttpRequests partagent le meme conteneur PHPUnit.
        // Le support de test Livewire emet cette frontiere entre ses requetes.
        Livewire::flushState();
        $observations->exchangeArray([]);
        $this->forgetOrganization();
        $this->actingAs($this->memberA);
        $this->assertAuthenticatedAs($this->memberA);

        $response = $this->livewireUpdate($this->memberA, $snapshot);

        $response->assertNotFound();
        $this->assertAuthenticatedAs($this->memberA);
        $this->assertSame([
            'user_id' => $this->memberA->id,
            'organization_id' => $this->b->id,
            'path' => 'org/'.$this->b->slug.'/flux',
            'route' => 'organization.flux',
            'route_organization' => $this->b->slug,
        ], $observations[0]);
        $this->assertStringNotContainsString($this->b->name, $response->getContent());
        $this->assertSame($membersBefore, LoopMember::query()->count(),
            'le rejeu refuse ne doit creer aucune participation inter-tenant');
    }

    /** A est ici le PREMIER POST Livewire du test : aucun cache B precedent. */
    public function test_f_an_isolated_a_update_executes_the_guard_with_a_and_resolves_b(): void
    {
        $observations = $this->installMembershipProbe();
        $snapshot = $this->organizationFeedSnapshot($this->memberB, $this->b);
        $observations->exchangeArray([]);

        $this->forgetOrganization();
        $this->actingAs($this->memberA);
        $this->assertAuthenticatedAs($this->memberA);

        $response = $this->livewireUpdate($this->memberA, $snapshot);

        $response->assertNotFound();
        $this->assertAuthenticatedAs($this->memberA);
        $this->assertCount(1, $observations, 'la garde persistante doit etre executee pour A');
        $this->assertSame([
            'user_id' => $this->memberA->id,
            'organization_id' => $this->b->id,
            'path' => 'org/'.$this->b->slug.'/flux',
            'route' => 'organization.flux',
            'route_organization' => $this->b->slug,
        ], $observations[0]);
    }

    public function test_g_only_home_legal_and_the_eight_auth_endpoints_are_public(): void
    {
        $public = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'org/{organization}')) {
                continue;
            }

            $middleware = array_map(
                static fn ($item) => is_string($item) ? str($item)->before(':')->toString() : $item,
                app('router')->gatherRouteMiddleware($route),
            );

            if (in_array(\Illuminate\Auth\Middleware\Authenticate::class, $middleware, true)
                || in_array(EnsureOrganizationMember::class, $middleware, true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $public[] = $method.' '.$route->uri();
            }
        }

        sort($public);

        $this->assertSame([
            'GET org/{organization}',
            'GET org/{organization}/forgot-password',
            'GET org/{organization}/login',
            'GET org/{organization}/mentions-legales',
            'GET org/{organization}/register',
            'GET org/{organization}/reset-password/{token}',
            'POST org/{organization}/forgot-password',
            'POST org/{organization}/login',
            'POST org/{organization}/register',
            'POST org/{organization}/reset-password',
        ], $public);
    }

    public function test_g_anonymous_requests_only_reach_home_legal_and_authentication(): void
    {
        $login = route('organization.login', $this->b);

        $this->get(route('organization.home', $this->b))->assertOk();
        $this->forgetOrganization();
        $this->get(route('organization.mentions-legales', $this->b))->assertOk();

        $formerlyPublic = [
            route('organization.shell.show', $this->b),
            route('organization.about', $this->b),
            route('organization.workshop.show', [$this->b, 'atelier-public']),
            route('organization.constitution', $this->b),
            route('organization.bug-reports.index', $this->b),
            route('organization.flowchart', $this->b),
            route('organization.open-source', $this->b),
            route('organization.mycelium', $this->b),
            route('organization.subscriptions', $this->b),
            route('organization.boucles.index', $this->b),
            route('organization.blog.index', $this->b),
            route('organization.blog.show', [$this->b, 'article-public']),
            route('organization.blog.category', [$this->b, 'categorie-publique']),
            route('organization.blog.tag', [$this->b, 'tag-public']),
            route('organization.agent-ia.profile.chat', [$this->b, $this->memberB]),
        ];

        foreach ($formerlyPublic as $url) {
            $this->forgetOrganization();
            $this->get($url)->assertRedirect($login);
        }

        $session = '00000000-0000-4000-8000-000000000169';
        $this->forgetOrganization();
        $this->post(route('organization.shell.message', $this->b), ['message' => 'bonjour'])
            ->assertRedirect($login);
        $this->forgetOrganization();
        $this->post(route('organization.workshop.session.interest', [$this->b, 'atelier-public', $session]))
            ->assertRedirect($login);
        $this->forgetOrganization();
        $this->delete(route('organization.workshop.session.interest.withdraw', [$this->b, 'atelier-public', $session]))
            ->assertRedirect($login);
    }

    public function test_h_the_public_homepage_contains_no_private_tenant_content_or_dead_internal_links(): void
    {
        $privateAvatar = 'avatars/t1692-private-member.png';
        User::factory()->complete()->create([
            'organization_id' => $this->b->id,
            'name' => 'Membre Prive T1692',
            'avatar' => $privateAvatar,
        ]);

        foreach (['bouclepro_hero_v2', 'artscilab_hero', 'bouclepro_default'] as $template) {
            $this->b->update(['homepage_template' => $template]);
            $this->forgetOrganization();

            $response = $this->get(route('organization.home', $this->b))->assertOk();
            $response->assertViewHas('showInternalHomepageContent', false);
            $html = $response->getContent();

            $this->assertStringNotContainsString('Membre Prive T1692', $html);
            $this->assertStringNotContainsString('t1692-private-member.png', $html);
            $this->assertStringNotContainsString('<div class="avatars">', $html);
            $this->assertStringNotContainsString('data-guest-shell', $html);
            $this->assertStringNotContainsString('data-footer-mycelium', $html);
            $this->assertStringNotContainsString('data-footer-flowchart', $html);
            $this->assertStringNotContainsString('data-bp-mobile-nav', $html);
            $this->assertStringNotContainsString('<aside x-data class="hidden md:flex', $html);

            // TASK-1692, correctif post-CI — cette mesure etait une LISTE
            // D'ABSENCES, et elle etait trop large : elle interdisait tout
            // segment `/org/{slug}/…` sur l'accueil public, confondant « lien
            // interne » et « lien mort ». Un lien qui conduit un anonyme a la
            // connexion bornee a l'Organization n'est pas mort : c'est le
            // tunnel d'entree, et c'est ce que le produit publiait avant
            // TASK-1692. Les supprimer amputait l'affiche publique — la CI l'a
            // prouve sur 29 tests.
            //
            // On mesure donc l'inverse, et c'est plus fort : CHAQUE lien interne
            // reellement present sur l'accueil public doit, pour un anonyme,
            // rendre un REFUS. Jamais 200 — sauf les deux seules surfaces
            // publiques par decision (T1489) —, jamais 5xx.
            $liens = [];
            preg_match_all('#href="(https?://[^"]*/org/'.preg_quote($this->b->slug, '#').'/[^"]*)"#', $html, $m);
            foreach ($m[1] as $lien) {
                $liens[html_entity_decode($lien, ENT_QUOTES)] = true;
            }

            foreach (array_keys($liens) as $lien) {
                $this->forgetOrganization();
                $reponse = $this->get($lien);
                $statut = $reponse->getStatusCode();

                // L'attendu n'est pas une liste en dur mais la PILE REELLE du
                // lien : une surface sans `auth` effectif est joignable en
                // anonyme par construction (l'accueil, les mentions legales et
                // les portes `guest` de connexion/inscription) ; toute autre
                // doit refuser. Deriver l'attendu de la pile evite une liste qui
                // se perimerait au premier ajout de route.
                if ($this->lienExigeAuth($lien)) {
                    $this->assertContains(
                        $statut,
                        [302, 404],
                        "[{$lien}] : porte gardee, elle doit refuser un anonyme (redirection de connexion ou 404), jamais le servir",
                    );
                } else {
                    $this->assertSame(200, $statut, "[{$lien}] : surface publique, elle doit rester servie");
                }
            }

            $this->assertNotEmpty($liens, 'l\'accueil public porte bien des liens internes : sinon cette garde serait vide');

            $this->assertStringContainsString(route('organization.mentions-legales', $this->b), $html);
            $this->assertStringContainsString(route('organization.login', $this->b), $html);
        }

        $this->b->update(['homepage_template' => 'bouclepro_default']);
        $this->forgetOrganization();
        $foreign = $this->actingAs($this->memberA)
            ->get(route('organization.home', $this->b))
            ->assertOk()
            ->assertViewHas('showInternalHomepageContent', false)
            ->getContent();

        $this->assertStringNotContainsString('t1692-private-member.png', $foreign);
        $this->assertStringNotContainsString(route('organization.blog.index', $this->b), $foreign);
        $this->assertStringNotContainsString('data-bp-mobile-nav', $foreign);
        $this->assertStringNotContainsString('<aside x-data class="hidden md:flex', $foreign);
        $this->assertStringNotContainsString('data-mobile-topbar-notifications', $foreign);
        $this->assertStringNotContainsString('data-ai-fab', $foreign);

        $this->forgetOrganization();
        $own = $this->actingAs($this->memberB)
            ->get(route('organization.home', $this->b))
            ->assertOk()
            ->assertViewHas('showInternalHomepageContent', true)
            ->getContent();

        $this->assertStringContainsString(route('organization.blog.index', $this->b), $own);
        $this->assertStringContainsString('data-bp-mobile-nav', $own);
        $this->assertStringContainsString('<aside x-data class="hidden md:flex', $own);
        $this->assertStringContainsString('data-mobile-topbar-notifications', $own);

        $admin = User::factory()->complete()->create([
            'organization_id' => $this->a->id,
            'email_verified_at' => now(),
            'is_admin' => true,
        ]);
        $membersBefore = LoopMember::query()->where('user_id', $admin->id)->count();

        $this->forgetOrganization();
        $adminHomepage = $this->actingAs($admin)
            ->get(route('organization.home', $this->b))
            ->assertOk()
            ->assertViewHas('showInternalHomepageContent', true)
            ->getContent();

        $this->assertStringContainsString('data-bp-mobile-nav', $adminHomepage);
        $this->assertStringContainsString('<aside x-data class="hidden md:flex', $adminHomepage);
        $this->assertSame($membersBefore, LoopMember::query()->where('user_id', $admin->id)->count());
        $this->assertSame($this->a->id, $admin->fresh()->organization_id);
    }

    public function test_i_former_public_features_are_tenant_scoped_in_both_directions(): void
    {
        $surfaces = [
            fn (Organization $organization) => route('organization.about', $organization),
            fn (Organization $organization) => route('organization.blog.index', $organization),
            fn (Organization $organization) => route('organization.shell.show', $organization),
        ];

        foreach ($surfaces as $surface) {
            $this->forgetOrganization();
            $this->actingAs($this->memberB)->get($surface($this->b))->assertOk();

            $this->forgetOrganization();
            $this->actingAs($this->memberA)->get($surface($this->b))->assertNotFound();

            $this->forgetOrganization();
            $this->actingAs($this->memberB)->get($surface($this->a))->assertNotFound();
        }
    }

    public function test_j_the_eight_organization_authentication_endpoints_remain_reachable(): void
    {
        $base = route('organization.home', $this->b);
        $login = $base.'/login';
        $register = $base.'/register';
        $forgot = $base.'/forgot-password';
        $reset = $base.'/reset-password';

        $this->get($login)->assertOk();
        $this->get($register)->assertOk();
        $this->get($forgot)->assertOk();
        $this->get($reset.'/token-t1692')->assertOk();

        $this->from($login)->post($login, [])->assertRedirect($login)->assertSessionHasErrors();
        $this->from($register)->post($register, [])->assertRedirect($register)->assertSessionHasErrors();
        $this->from($forgot)->post($forgot, [])->assertRedirect($forgot)->assertSessionHasErrors();
        $this->from($reset)->post($reset, [])->assertRedirect($reset)->assertSessionHasErrors();
    }

    private function organizationFeedSnapshot(User $member, Organization $organization): string
    {
        $html = $this->actingAs($member)
            ->get(route('organization.flux', ['organization' => $organization->slug]))
            ->assertOk()
            ->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $candidate) {
            $decoded = html_entity_decode($candidate, ENT_QUOTES);

            if (str_contains($decoded, 'organization-feed')) {
                return $decoded;
            }
        }

        $this->fail('Le vrai snapshot Livewire `organization-feed` est absent.');
    }

    private function livewireUpdate(User $actor, string $snapshot)
    {
        return $this->actingAs($actor)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [],
            ]]]);
    }

    private function installMembershipProbe(): \ArrayObject
    {
        $observations = new \ArrayObject;

        $this->app->bind(EnsureOrganizationMember::class, function () use ($observations) {
            return new class($observations)
            {
                public function __construct(private \ArrayObject $observations) {}

                public function handle($request, $next, ?string $mode = null)
                {
                    $this->observations->append([
                        'user_id' => $request->user()?->id,
                        'organization_id' => currentOrganization()?->id,
                        'path' => $request->path(),
                        'route' => $request->route()?->getName(),
                        'route_organization' => $request->route('organization'),
                    ]);

                    return (new EnsureOrganizationMember)->handle($request, $next, $mode);
                }
            };
        });

        return $observations;
    }

    public function test_legal_pages_render_no_internal_navigation_for_anonymous(): void
    {
        // Global legal page.
        $global = $this->get(route('mentions-legales'))->assertOk()->getContent();
        foreach (self::INTERNAL_DESTINATIONS as $segment) {
            $this->assertStringNotContainsString($segment, $global);
        }
        $this->assertStringNotContainsString('data-bp-mobile-nav', $global);
        $this->assertStringNotContainsString('<aside x-data class="hidden md:flex', $global);

        // Organization-prefixed legal page. The bare segment is too loose here
        // (`/blog` matches nothing else on the page, but `/explorer` would also
        // match the public Open Source explorer), so each destination is
        // checked as the org-scoped path it would really be rendered as.
        $org = $this->get(route('organization.mentions-legales', $this->b))->assertOk()->getContent();
        foreach (self::INTERNAL_DESTINATIONS as $segment) {
            $this->assertStringNotContainsString('/org/'.$this->b->slug.$segment, $org);
        }
        $this->assertStringNotContainsString('data-bp-mobile-nav', $org);
        $this->assertStringNotContainsString('<aside x-data class="hidden md:flex', $org);
    }

    /**
     * TASK-1692 / MICRO-07 — la page legale reste PUBLIQUE, sa navigation
     * devient MEMBRE.
     *
     * `mentions-legales` ne gardait sa navigation que derriere
     * `auth()->check()` : l'authentification seule, qui ne dit rien de
     * l'Organization ECRITE dans l'URL. Mesure avant correctif, un membre de A
     * ouvrant `/org/B/mentions-legales` recevait les 6 destinations internes de
     * B, prefixees de son slug (`dashboard` 1, `loops` 3, `agenda` 3,
     * `membres` 3, `blog` 4, `explorer` 2) et les deux marqueurs de la barre
     * basse. Le refus anonyme, lui, etait deja tenu : c'est pourquoi aucun test
     * ne voyait cette porte.
     *
     * Les deux sens sont mesures dans le MEME test : sans la contre-epreuve du
     * membre legitime, un `show-internal-navigation` cable a `false` rendrait
     * ce test vert en ayant retire la navigation a tout le monde.
     */
    public function test_legal_pages_hide_internal_navigation_from_a_non_member_but_keep_it_for_a_member(): void
    {
        $foreign = $this->actingAs($this->memberA)
            ->get(route('organization.mentions-legales', $this->b))
            ->assertOk()
            ->getContent();

        foreach (self::INTERNAL_DESTINATIONS as $segment) {
            $this->assertStringNotContainsString('/org/'.$this->b->slug.$segment, $foreign);
        }
        $this->assertStringNotContainsString('data-bp-mobile-nav', $foreign);
        $this->assertStringNotContainsString('<aside x-data class="hidden md:flex', $foreign);

        // Contre-epreuve : le membre de B garde sa navigation sur la meme page.
        $own = $this->actingAs($this->memberB->fresh())
            ->get(route('organization.mentions-legales', $this->b))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/org/'.$this->b->slug.'/dashboard', $own);
        $this->assertStringContainsString('data-bp-mobile-nav', $own);
    }

    private function forgetOrganization(): void
    {
        app()->forgetInstance('current_organization');
    }

    /**
     * Le lien exige-t-il `auth` dans sa pile EFFECTIVE ?
     *
     * `Router::gatherRouteMiddleware()` et non `gatherMiddleware()` : seul le
     * premier soustrait `excludedMiddleware()`, et tout le contrat TASK-1692
     * repose sur des exclusions nominatives posees sur un groupe garde.
     */
    private function lienExigeAuth(string $lien): bool
    {
        $route = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create($lien, 'GET')
        );

        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $classe = explode(':', $middleware, 2)[0];

            if ($classe === 'auth' || is_a($classe, \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, true)) {
                return true;
            }
        }

        return false;
    }
}
