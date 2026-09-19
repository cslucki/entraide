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
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // TASK-1603 — la page 404 parle la langue de l'utilisateur.
        //
        // LE DEFAUT, mesure. Sur une URI NON ROUTEE, le routeur leve la 404
        // AVANT tout middleware de groupe : `StartSession` n'a jamais tourne
        // (`$request->session()->isStarted()` vaut `false`), donc `SetLocale`
        // non plus. La page sortait toujours dans la langue par DEFAUT, meme
        // pour un utilisateur ayant explicitement choisi l'anglais — choix
        // prouve actif sur les pages normales de la meme session.
        //
        // Le 404 leve DEPUIS un controleur (refus cross-tenant) n'a pas ce
        // defaut : la pile `web` y a tourne, et il rendait deja `lang="en"`.
        // Ce correctif ne le touche donc pas — la garde ci-dessous s'efface.
        //
        // POURQUOI PAS UNE ROUTE DE REPLI. `Route::fallback()` est la reponse
        // idiomatique, et elle a ete essayee puis MESUREE : faire passer toute
        // URI inconnue par le groupe `web` la soumet aussi a la resolution
        // tenant. `GET /services` sans Organization passait alors de 405 a 404,
        // refuse par `ResolveUrlOrganization` avant meme d'atteindre la route.
        // Six tests voisins l'ont dit (MembersPageTest, TASK-1077, TASK-1078
        // x2, TASK-1513, TASK-1515). C'eut ete une modification du routage
        // tenant : hors mandat, et a juste titre.
        //
        // CE QUE FAIT CE BLOC. Il rejoue les middlewares EXISTANTS dont la
        // locale depend, uniquement au moment de rendre l'erreur. Aucune
        // detection de langue n'est ajoutee : `SetLocale` reste seul juge, avec
        // sa cascade (session, utilisateur, Organization, navigateur, defaut).
        // Le routage n'est pas touche, et les 405 restent des 405.
        //
        // La session n'est demarree que si la requete PORTE DEJA son cookie :
        // une 404 anonyme n'en cree jamais. Sans cookie, la cascade se poursuit
        // sur le navigateur puis le defaut, ce qui est exactement voulu.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            // La condition EXACTE, et non un proxy : si le routeur a resolu une
            // route, sa pile de middleware a tourne et la locale est deja
            // etablie — c'est le cas du refus cross-tenant. Seule une URI que
            // le routeur n'a jamais fait correspondre arrive ici sans locale.
            // (Un premier jet testait `hasSession()` : vrai proxy, mauvaise
            // question, et il divergeait entre le harnais et le HTTP reel.)
            if ($request->expectsJson() || $request->route() !== null) {
                return null;
            }

            $pile = [EncryptCookies::class];

            if ($request->cookies->has((string) config('session.cookie'))) {
                $pile[] = StartSession::class;
            }

            $pile[] = SetLocale::class;

            return app(Pipeline::class)
                ->send($request)
                ->through($pile)
                ->then(fn () => response()->view('errors.404', [], 404));
        });
    })->create();
