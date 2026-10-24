<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-1694 bug C — la verification d'email redevient OBLIGATOIRE.
 *
 * ## L'histoire, parce qu'elle explique la forme
 *
 * `verified` gardait 149 routes jusqu'au bug E de TASK-1693, ou Cyril l'a fait
 * retirer : « il n'y a aucune raison de verifier l'email a ce stade ». Le
 * defaut reel etait qu'un membre ancien, jamais verifie, se faisait ejecter de
 * SES PROPRES brouillons. Cyril revient sur cette decision : la verification
 * doit etre obligatoire une fois l'inscription faite.
 *
 * ## Pourquoi ici, et pas en reposant `verified` sur des groupes
 *
 * Avant, la garde vivait sur DEUX declarations de routes — le blog et les
 * Dossiers. Elle ne couvrait donc ni le tableau de bord, ni l'Explorer, ni la
 * Messagerie : « obligatoire » n'aurait rien voulu dire. Et la marque
 * `organization.member` ne suffit pas non plus : `organization.dashboard` ne la
 * porte pas (mesure faite avant d'ecrire ce fichier).
 *
 * Le critere juste est donc : **toute route gardee par `auth`**. Une page
 * publique n'en porte pas et reste ouverte, y compris pour quelqu'un de
 * connecte mais non verifie — on ne ferme pas les mentions legales a cause
 * d'une adresse non confirmee.
 *
 * ## Ce qui reste atteignable, et pourquoi chaque exception existe
 *
 * - `verification.*` — sinon la garde empecherait de la lever.
 * - `logout` — enfermer quelqu'un dans une session qu'il ne peut pas quitter
 *   serait pire que le probleme.
 * - le PROFIL — pour corriger une adresse mal saisie. C'est le cas le plus
 *   frequent : sans cette porte, une faute de frappe rend le compte
 *   definitivement inutilisable. `ProfileController::update()` remet
 *   `email_verified_at` a null et renvoie un nouvel email.
 * - l'ADMINISTRATION — c'est la que vit « Valider l'email » (TASK-1693 bug B),
 *   le depannage. Un SuperAdmin non verifie qui ne pourrait pas y acceder ne
 *   pourrait debloquer personne, lui compris.
 */
class RequireVerifiedEmail
{
    /**
     * Les noms de route qui restent ouverts a un compte non verifie.
     *
     * Des NOMS, jamais des URL : les deux formes d'une meme page (prefixee par
     * l'Organization ou non) portent le meme nom a un prefixe pres, et une
     * comparaison d'URL raterait l'une des deux.
     *
     * @var list<string>
     */
    public const ALWAYS_ALLOWED = [
        'verification.notice',
        'verification.send',
        'verification.verify',
        'logout',
        'password.confirm',
        'password.confirm.store',
    ];

    /**
     * Les prefixes de nom laisses ouverts, dans les deux formes de route.
     *
     * @var list<string>
     */
    public const ALLOWED_PREFIXES = [
        'profile.',
        'organization.profile.',
        'admin.',
        'organization.admin.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'hasVerifiedEmail') || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $route = $request->route();

        if ($route === null || $this->estOuverte((string) ($route->getName() ?? ''))) {
            return $next($request);
        }

        // Une page PUBLIQUE reste publique. On lit la pile EFFECTIVE via le
        // routeur : `$route->gatherMiddleware()` ignore les exclusions, et
        // deduire la protection du nom de route serait une seconde verite.
        if (! in_array(AuthenticatesRequests::class, RouteFacade::gatherRouteMiddleware($route), true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.email_not_verified')], 409);
        }

        return redirect()->guest(route('verification.notice'));
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
