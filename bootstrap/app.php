<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CheckAiProfilesEnabled;
use App\Http\Middleware\CheckLoopsEnabled;
use App\Http\Middleware\ConsumeOrgParams;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureOrganizationMember;
use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\ResolveApiOrganization;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\ResolveUrlOrganization;
use App\Http\Middleware\SetLocale;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TASK-1602 — un parcours commence DANS une Organization y reste.
        //
        // Le repli par defaut de Laravel envoie tout invite sur `route('login')`,
        // c'est-a-dire `/login`, qui appartient a l'Organization par defaut.
        // Mesure avant correctif : un invite sur `/org/launchpals/dashboard`
        // recevait un 302 vers `/login`, et se retrouvait chez `main`.
        //
        // `organization.login` EXISTE deja (`routes/web.php`, groupe `guest` du
        // prefixe `/org/{organization}`) : on n'ajoute aucune route, on branche
        // le repli dessus. Le `intended` reste pose par `redirect()->guest()`.
        //
        // BORNE — et elle n'est pas cosmetique. Le declencheur est l'URL
        // `/org/{organization}/…`, JAMAIS l'Organization liee au conteneur.
        // Mesure : sur `/loops`, `/loops/create`, `/members` — des routes
        // GLOBALES courtes —, un invite n'a exprime aucune Organization, mais
        // `ResolveUrlOrganization` lui lie tout de meme celle par DEFAUT. Se
        // brancher sur ce liage aurait envoye l'invite sur `/org/main/login` :
        // ce n'est pas conserver un contexte, c'est en inventer un — le defaut
        // meme que TASK-1601 a nomme. Cinq tests voisins l'ont dit
        // (T07411 x2, TASK1506, TASK1601 x2), et ils avaient raison.
        //
        // La route SuperAdmin `admin/organizations/{organization}/homepage`
        // porte elle aussi un parametre `organization` : elle ne commence pas
        // par `org/`, donc elle garde le login universel. C'est voulu — un
        // administrateur plateforme n'entre pas par la porte d'un tenant.
        //
        // Le login universel `/login` demeure la porte de tout le reste.
        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->segment(1) !== 'org' || ! Route::has('organization.login')) {
                return route('login');
            }

            $organization = app()->bound('current_organization') ? app('current_organization') : null;

            // On ne nomme que l'Organization ECRITE DANS L'URL : si le liage
            // pointait ailleurs, on ne fabrique pas une redirection vers un
            // tenant que le visiteur n'a pas demande.
            if (! $organization || $organization->slug !== $request->segment(2)) {
                return route('login');
            }

            return route('organization.login', ['organization' => $organization->slug]);
        });

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'organization' => ResolveOrganization::class,
            'profile.complete' => EnsureProfileComplete::class,
            'url.organization' => ResolveUrlOrganization::class,
            'api.organization' => ResolveApiOrganization::class,
            'consume.org' => ConsumeOrgParams::class,
            'loops.enabled' => CheckLoopsEnabled::class,
            'ai-profiles.enabled' => CheckAiProfilesEnabled::class,
            // TASK-1479 (P0 privacy) : la frontiere d'acces d'une surface INTERNE
            // d'Organization. A poser APRES `auth` — elle verifie l'appartenance,
            // pas l'authentification.
            'organization.member' => EnsureOrganizationMember::class,
        ]);
        // TASK-145: Reorder web group so ResolveUrlOrganization runs BEFORE
        // SubstituteBindings. With appendToGroup, ResolveUrlOrganization ran AFTER
        // SubstituteBindings, meaning route model binding (Service $service, etc.)
        // fired BEFORE the Organization was resolved. BelongsToTenantScope then
        // blocked every query with whereRaw('0=1'), causing 404 on model-bound
        // routes like /services/{service}/edit.
        $middleware->group('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            EnsureUserIsNotBanned::class,
            ResolveUrlOrganization::class,
            ResolveOrganization::class,
            SetLocale::class,
            SubstituteBindings::class,
        ]);
        $middleware->appendToGroup('api', [
            ResolveApiOrganization::class,
            EnsureUserIsNotBanned::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
