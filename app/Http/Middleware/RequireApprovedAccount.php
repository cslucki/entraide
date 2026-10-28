<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-1714 volet 2 — email verifie n'est pas compte valide.
 *
 * Quand une Organization active `requires_account_approval`, un nouveau compte
 * confirme son adresse puis **attend** la validation d'un SuperAdmin. Tant
 * qu'elle n'est pas donnee, l'acces fonctionnel a l'Organization est ferme.
 *
 * ## Le critere, et pourquoi il n'est pas une liste de routes
 *
 * **Toute route gardee par `auth`**, lue sur la pile EFFECTIVE via
 * `RouteFacade::gatherRouteMiddleware()`. Meme critere que
 * `RequireVerifiedEmail`, et pour la meme raison : une liste de routes a tenir
 * a jour aurait un trou le jour ou quelqu'un en ajoute une. Une page publique
 * ne porte pas `auth` et reste donc ouverte, y compris pour un compte en
 * attente — on ne ferme pas les mentions legales a cause d'une validation qui
 * tarde.
 *
 * `$route->gatherMiddleware()` ne conviendrait PAS : il ignore les exclusions
 * (`withoutMiddleware`), et plusieurs routes d'Organization s'en servent.
 *
 * ## Les DEUX groupes, et c'est le coeur de la garde
 *
 * Declaree dans `web` **et** `api`. `RequireVerifiedEmail` ne vit que dans
 * `web` : poser celle-ci au meme endroit l'aurait rendue contournable par
 * `/api/*`, ou `auth:sanctum` ouvre les memes donnees. C'est exactement le
 * contournement que MASTER interdit. Le modele est `EnsureUserIsNotBanned`,
 * qui couvre les deux groupes et lit les deux gardes.
 *
 * ## Ce qui reste atteignable, et pourquoi chaque exception existe
 *
 * - la page d'attente elle-meme — sinon la garde empecherait de lire son
 *   propre message ;
 * - `verification.*` — l'ordre est « email d'abord, approbation ensuite » :
 *   il faut pouvoir verifier son adresse en attendant ;
 * - `logout` — enfermer quelqu'un dans une session qu'il ne peut pas quitter
 *   serait pire que le probleme ;
 * - l'ADMINISTRATION — arbitrage MASTER (A4) : c'est la que vit « Valider
 *   compte ». Un SuperAdmin qui se retrouverait en attente et ne pourrait pas
 *   y acceder ne pourrait debloquer personne, lui compris.
 *
 * **`profile.*` n'est PAS une exception**, et la difference avec
 * `RequireVerifiedEmail` est deliberee : celle-ci ouvre le profil parce qu'une
 * adresse mal saisie s'y corrige, et que sans cette porte une faute de frappe
 * rend le compte definitivement inutilisable. Un compte en attente, lui, n'a
 * RIEN a corriger — l'attente ne depend pas de lui.
 */
class RequireApprovedAccount
{
    /**
     * Les noms de route qui restent ouverts a un compte en attente.
     *
     * Des NOMS, jamais des URL : les deux formes d'une meme page (prefixee par
     * l'Organization ou non) portent le meme nom a un prefixe pres, et une
     * comparaison d'URL raterait l'une des deux.
     *
     * @var list<string>
     */
    public const ALWAYS_ALLOWED = [
        'approval.notice',
        'verification.notice',
        'verification.send',
        'verification.verify',
        'logout',
        'password.confirm',
        'password.confirm.store',
        // TASK-1716 — fermer son compte reste possible en attendant.
        //
        // UNE entree nommee, et non l'ouverture de `profile.*`. La difference
        // est tout l'interet : le docblock ci-dessus explique pourquoi le
        // profil est ferme a un compte en attente — il n'a RIEN a y corriger,
        // l'attente ne depend pas de lui. Mais renoncer, si. Enfermer
        // quelqu'un dans une attente qu'il ne peut ni faire avancer ni quitter
        // pour de bon serait la meme faute que de lui refuser `logout`.
        //
        // C'est aussi la raison pour laquelle `account.close` est une route
        // GLOBALE declaree dans `routes/auth.php` : les onze routes de profil
        // restent fermees, et une seule porte s'ouvre.
        'account.close',
    ];

    /**
     * Les prefixes de nom laisses ouverts, dans les deux formes de route.
     *
     * @var list<string>
     */
    public const ALLOWED_PREFIXES = [
        'admin.',
        'organization.admin.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Les deux gardes, comme `EnsureUserIsNotBanned` : sur `/api/*`,
        // `Auth::user()` est nul et seul `sanctum` sait qui parle.
        $user = Auth::guard('sanctum')->check()
            ? Auth::guard('sanctum')->user()
            : (Auth::check() ? Auth::user() : null);

        if ($user === null || ! method_exists($user, 'attendApprobation') || ! $user->attendApprobation()) {
            return $next($request);
        }

        $route = $request->route();

        if ($route === null || $this->estOuverte((string) ($route->getName() ?? ''))) {
            return $next($request);
        }

        // Une page PUBLIQUE reste publique. On lit la pile EFFECTIVE via le
        // routeur : deduire la protection du nom de route serait une seconde
        // verite, qui divergerait le jour ou une route change de groupe.
        if (! $this->exigeUneAuthentification(RouteFacade::gatherRouteMiddleware($route))) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.account_pending_approval')], 403);
        }

        return redirect()->guest(route('approval.notice'));
    }

    /**
     * Cette pile exige-t-elle une authentification ?
     *
     * Le test ne peut PAS etre une egalite stricte, et c'est une mesure, pas
     * une precaution : sur `/api/*`, la pile effective contient
     * `Illuminate\Auth\Middleware\Authenticate:sanctum` — le nom de classe
     * SUIVI du garde, en une seule chaine. Un `in_array($classe, $pile, true)`
     * y rend `false`, et la garde laissait donc passer **toute l'API** : le
     * contournement exact qu'elle existe pour fermer.
     *
     * Le prefixe couvre les deux formes, `web` (`Authenticate` nu) et `api`
     * (`Authenticate:sanctum`), ainsi que tout garde ajoute plus tard.
     *
     * @param  array<int, mixed>  $pile
     */
    private function exigeUneAuthentification(array $pile): bool
    {
        foreach ($pile as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === AuthenticatesRequests::class
                || str_starts_with($middleware, AuthenticatesRequests::class.':')) {
                return true;
            }
        }

        return false;
    }

    private function estOuverte(string $nom): bool
    {
        if ($nom === '') {
            return false;
        }

        if (in_array($nom, self::ALWAYS_ALLOWED, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefixe) {
            if (str_starts_with($nom, $prefixe)) {
                return true;
            }
        }

        return false;
    }
}
