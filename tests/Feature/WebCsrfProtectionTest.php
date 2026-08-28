<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\ResolveApiOrganization;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\ResolveUrlOrganization;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * TASK-1318 — tripwire : le groupe `web` verifie l'origine des requetes, et
 * l'ordre TASK-145 est preserve.
 *
 * `bootstrap/app.php` declare le groupe `web` avec `$middleware->group()`, qui
 * REMPLACE le groupe par defaut de Laravel au lieu de l'amender
 * (`Illuminate\Foundation\Configuration\Middleware::group()` ecrit
 * `$this->groups['web']`, puis `getMiddlewareGroups()` fait un `array_merge`
 * qui ecrase la cle). Le middleware CSRF du defaut a donc disparu en silence :
 * 355 routes mutatives sur 368 passaient par ce groupe et 249 formulaires
 * posaient un jeton que rien ne verifiait.
 *
 * Ce test fige les deux invariants qui ne doivent plus jamais diverger :
 *
 *  1. `PreventRequestForgery` EST dans le groupe `web` resolu au runtime — la
 *     lecture du fichier ne suffit pas, seule la resolution par le routeur
 *     prouve qu'aucun autre appel n'a re-remplace le groupe ;
 *  2. l'ordre TASK-145 tient : `ResolveUrlOrganization` AVANT
 *     `SubstituteBindings`, faute de quoi le route model binding tire avant la
 *     resolution de l'Organization, `BelongsToTenantScope` bloque tout en
 *     `whereRaw('0=1')` et les routes a modele lie rendent 404.
 *
 * Si ce test devient rouge, ne pas l'assouplir : c'est le groupe `web` qu'il
 * faut reparer.
 */
class WebCsrfProtectionTest extends TestCase
{
    /**
     * Le groupe `web` tel que le routeur le resout reellement.
     *
     * @return array<int, string>
     */
    private function resolvedWebGroup(): array
    {
        return array_values(array_filter(
            Route::getMiddlewareGroups()['web'] ?? [],
            'is_string'
        ));
    }

    public function test_prevent_request_forgery_is_registered_in_the_resolved_web_group(): void
    {
        $this->assertContains(
            PreventRequestForgery::class,
            $this->resolvedWebGroup(),
            'Le groupe web ne verifie plus l origine des requetes : toute route mutative '
            .'derriere `web` accepterait un POST forge. Voir TASK-1318.'
        );
    }

    public function test_the_deprecated_csrf_alias_is_not_used(): void
    {
        $this->assertNotContains(
            ValidateCsrfToken::class,
            $this->resolvedWebGroup(),
            'ValidateCsrfToken est un alias @deprecated de PreventRequestForgery en Laravel 13. '
            .'L enregistrer ferait rater le withoutMiddleware(PreventRequestForgery::class) de Tests\TestCase.'
        );
    }

    public function test_task_145_ordering_is_preserved(): void
    {
        $group = $this->resolvedWebGroup();

        $position = function (string $middleware) use ($group): int {
            $index = array_search($middleware, $group, true);

            $this->assertNotFalse($index, "Middleware absent du groupe web : {$middleware}");

            return $index;
        };

        // L'invariant TASK-145 lui-meme.
        $this->assertLessThan(
            $position(SubstituteBindings::class),
            $position(ResolveUrlOrganization::class),
            'ORDRE TASK-145 CASSE : ResolveUrlOrganization doit passer AVANT SubstituteBindings, '
            .'sinon les routes a modele lie rendent 404 (BelongsToTenantScope -> whereRaw(0=1)).'
        );

        // La chaine tenant complete, dans l'ordre voulu par TASK-145.
        $this->assertLessThan($position(ResolveOrganization::class), $position(ResolveUrlOrganization::class));
        $this->assertLessThan($position(SetLocale::class), $position(ResolveOrganization::class));
        $this->assertLessThan($position(SubstituteBindings::class), $position(SetLocale::class));

        // Le CSRF a besoin de la session, et doit rejeter avant toute
        // resolution de tenant : il se place entre ShareErrorsFromSession et
        // EnsureUserIsNotBanned.
        $this->assertLessThan($position(PreventRequestForgery::class), $position(StartSession::class));
        $this->assertLessThan($position(PreventRequestForgery::class), $position(ShareErrorsFromSession::class));
        $this->assertLessThan($position(EnsureUserIsNotBanned::class), $position(PreventRequestForgery::class));
        $this->assertLessThan($position(ResolveUrlOrganization::class), $position(PreventRequestForgery::class));

        // Les deux middlewares de cookies restent en tete.
        $this->assertLessThan($position(StartSession::class), $position(EncryptCookies::class));
        $this->assertLessThan($position(StartSession::class), $position(AddQueuedCookiesToResponse::class));
    }

    public function test_the_api_group_is_left_untouched(): void
    {
        $api = array_values(array_filter(
            Route::getMiddlewareGroups()['api'] ?? [],
            'is_string'
        ));

        $this->assertContains(SubstituteBindings::class, $api);
        $this->assertContains(ResolveApiOrganization::class, $api);
        $this->assertContains(EnsureUserIsNotBanned::class, $api);

        // TASK-1318 ne touche pas au CSRF cote API : le groupe api est
        // authentifie par jeton Bearer, il n'a jamais porte de CSRF.
        $this->assertNotContains(PreventRequestForgery::class, $api);
    }

    public function test_sanctum_stateful_pipeline_is_not_introduced_by_this_change(): void
    {
        // `EnsureFrontendRequestsAreStateful` n'a jamais ete enregistre dans
        // BouclePro (`statefulApi()` n'est jamais appele). TASK-1318 ne
        // l'introduit pas : la configuration `sanctum.middleware
        // .validate_csrf_token` reste de la configuration morte, et ce test
        // empeche qu'on l'active par effet de bord.
        foreach (Route::getMiddlewareGroups() as $group) {
            $this->assertNotContains(
                EnsureFrontendRequestsAreStateful::class,
                array_filter($group, 'is_string')
            );
        }
    }

    public function test_the_test_suite_is_not_blocked_by_the_restored_middleware(): void
    {
        // Tests\TestCase::setUp appelle withoutMiddleware(PreventRequestForgery::class),
        // et le middleware lui-meme sort sur runningUnitTests(). Les deux
        // ceintures doivent tenir : sans elles, toute la suite virerait au 419.
        $user = User::factory()->create(['password' => bcrypt('password-tripwire-1318')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password-tripwire-1318',
        ]);

        $response->assertStatus(302);
        $this->assertAuthenticatedAs($user);
    }
}
