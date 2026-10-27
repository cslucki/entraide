<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CheckAiProfilesEnabled;
use App\Http\Middleware\CheckLoopsEnabled;
use App\Http\Middleware\ConsumeOrgParams;
use App\Http\Middleware\EnsureInvitationPasswordIsSet;
use App\Http\Middleware\RequireApprovedAccount;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureScenarioPersonaContextIsValid;
use App\Http\Middleware\EnsureOrganizationMember;
use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\ResolveApiOrganization;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\ResolveUrlOrganization;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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
        // TASK-1649 — le document d'un scenario n'est PAS rogne.
        //
        // `TrimStrings` coupe les extremites de toute chaine recue. C'est le
        // bon reflexe pour un nom ou une cle. C'en est un mauvais pour le
        // texte d'un Manifest : le CDC 9.2 promet que le document est conserve
        // TEL QUEL, meme invalide, et le digest se calcule sur ce qui est
        // stocke. Un octet rogne en silence, et le texte exporte cesse d'etre
        // celui qui a ete colle.
        //
        // Mesure du 27/09/2026 : un document colle se terminant par un espace
        // etait enregistre sans lui. L'exemption est volontairement NOMMEE et
        // etroite — `json` seulement, les autres champs restent rognes.
        $middleware->trimStrings(except: ['json']);

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

        // TASK-1692 — l'ordre est une frontiere de securite, pas une
        // consequence accidentelle de l'ordre d'ecriture des routes.
        //
        // `auth` reste la premiere porte : un invite est redirige vers la
        // connexion bornee a l'Organization. Ensuite seulement, l'appartenance
        // est verifiee en 404 sans oracle. La verification d'email arrive en
        // dernier, afin qu'un compte non verifie d'un autre tenant ne puisse
        // pas apprendre que la surface existe via `/verify-email`.
        //
        // On INSERE ces deux classes dans la priorite Laravel existante au lieu
        // de la remplacer : l'ordre relatif des middlewares du framework reste
        // donc intact pour tous les autres groupes.
        $middleware->appendToPriorityList(
            AuthenticatesRequests::class,
            EnsureOrganizationMember::class,
        );
        $middleware->appendToPriorityList(
            EnsureOrganizationMember::class,
            EnsureEmailIsVerified::class,
        );
        // TASK-1694 bug C — l'ordre de la garde de verification devient
        // EXPLICITE. Il etait deja correct ; il ne l'etait par PERSONNE.
        //
        // CE QUE J'AI CRU, ET CE QUE LA MESURE DIT. J'ai d'abord annonce une
        // regression : declaree dans le groupe `web`, la garde aurait tourne
        // avant `EnsureOrganizationMember`, et un compte non verifie d'un
        // AUTRE tenant aurait recu une redirection vers /verify-email la ou il
        // doit recevoir un 404 — soit l'oracle que TASK-1692 a ferme.
        //
        // C'etait FAUX. Mesure de la pile effective, avec et sans cette
        // insertion : `Authenticate` (10), `EnsureOrganizationMember` (11),
        // `RequireVerifiedEmail` (14 sans, 12 avec). L'etranger recevait son
        // 404 dans les deux cas, parce que `EnsureOrganizationMember` est deja
        // dans la liste de priorite et s'y trouve remonte.
        //
        // L'INSERTION RESTE, et je dis pourquoi plutot que de la presenter
        // comme un correctif : elle rend la dependance EXPLICITE. Aujourd'hui
        // l'ordre tient par un effet de bord de la priorite d'un AUTRE
        // middleware ; demain, une declaration deplacee pourrait le defaire
        // sans que rien ne rougisse. Aucun test ne distingue les deux
        // versions — je ne pretends donc pas qu'elle est prouvee.
        $middleware->appendToPriorityList(
            EnsureOrganizationMember::class,
            RequireVerifiedEmail::class,
        );
        // TASK-1714 volet 2 — l'approbation vient APRES la verification d'email.
        //
        // L'ordre porte une regle produit : on ne demande pas a un
        // administrateur de valider un compte dont l'adresse n'est pas encore
        // confirmee. Chaque etage a donc son refus propre — invite -> login,
        // etranger -> 404 sans oracle, non verifie -> /verify-email,
        // non approuve -> page d'attente — et aucun n'en masque un autre.
        $middleware->appendToPriorityList(
            RequireVerifiedEmail::class,
            RequireApprovedAccount::class,
        );
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
            // TASK-1654 — le mode persona se reverifie a chaque requete et se
            // ferme seul quand une condition tombe.
            //
            // Place ICI, et l'ordre compte deux fois. APRES `PreventRequestForgery`,
            // pour qu'une requete sans jeton valide ne declenche aucune bascule
            // d'identite. Et AVANT `EnsureUserIsNotBanned`, parce qu'un persona
            // banni pendant la session serait sinon renvoye au login par cette
            // garde-la — et l'administrateur d'origine serait perdu au lieu
            // d'etre restaure.
            //
            // Hors mode persona : une lecture de session, aucune requete SQL.
            EnsureScenarioPersonaContextIsValid::class,
            EnsureUserIsNotBanned::class,
            ResolveUrlOrganization::class,
            ResolveOrganization::class,
            SetLocale::class,
            SubstituteBindings::class,
            // TASK-1659 — un compte cree par invitation doit poser son mot de
            // passe avant d'aller ou que ce soit. APRES SubstituteBindings :
            // la garde s'exprime en noms de routes, qui doivent etre resolus.
            // Ne coute qu'une lecture d'attribut deja charge pour tout le monde.
            EnsureInvitationPasswordIsSet::class,
            // TASK-1694 bug C — la verification d'email redevient OBLIGATOIRE.
            //
            // APRES `SubstituteBindings` et apres `EnsureInvitationPasswordIsSet`,
            // pour la meme raison que cette derniere : la garde s'exprime en
            // NOMS DE ROUTES, qui doivent etre resolus. Et apres elle, parce
            // qu'un compte cree par invitation doit d'abord poser son mot de
            // passe — le renvoyer vers /verify-email avant cela l'enverrait
            // vers une page qu'il ne peut pas utiliser.
            //
            // Dans le groupe `web` et non sur des groupes de routes : la garde
            // precedente vivait sur DEUX declarations (blog, Dossiers) et
            // laissait donc le tableau de bord, l'Explorer et la Messagerie
            // ouverts. « Obligatoire » n'aurait rien voulu dire.
            RequireVerifiedEmail::class,
            RequireApprovedAccount::class,
        ]);
        $middleware->appendToGroup('api', [
            ResolveApiOrganization::class,
            EnsureUserIsNotBanned::class,
            // TASK-1714 volet 2 — SANS cette ligne, la garde d'approbation
            // serait contournable par `/api/*`, ou `auth:sanctum` ouvre les
            // memes donnees. `RequireVerifiedEmail` n'est PAS ici, et c'est
            // precisement le trou a ne pas reproduire.
            RequireApprovedAccount::class,
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
