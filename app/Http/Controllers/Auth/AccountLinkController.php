<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountLink;
use App\Models\User;
use App\Notifications\AccountLinkEstablished;
use App\Services\Users\AccountLinkService;
use App\Services\Users\Exceptions\AccountLinkRefused;
use App\Services\Users\LoginAccountResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * TASK-1731 (A5b) — creer, lister et revoquer un lien entre deux comptes.
 *
 * ## L'adresse EST postee ici, contrairement a A5a
 *
 * A5a ne pouvait atteindre que des comptes portant la PROPRE adresse de la
 * personne, et c'est ce qui la rendait inoffensive sans rien demander de plus.
 * A5b lie deux comptes qui peuvent avoir des adresses DIFFERENTES (arbitrage
 * MASTER) : l'adresse doit donc etre fournie.
 *
 * Ce que cela n'ouvre pas : on exige AUSSI le mot de passe du compte vise. On
 * ne peut donc pas eprouver l'existence d'un compte sans deja le controler, et
 * le refus est le MEME quelle que soit la cause.
 *
 * ## Le lien n'est pas une propriete
 *
 * `created_by_user_id` est une trace, pas un titre. La revocation est ouverte
 * aux DEUX comptes — sans quoi celui qui n'a pas cree le lien serait prisonnier
 * d'une decision qu'il peut avoir regrettee.
 */
class AccountLinkController extends Controller
{
    private const MAX_TENTATIVES = 5;

    private const FENETRE_SECONDES = 60;

    public function index(Request $request, AccountLinkService $liens, LoginAccountResolver $comptes): View
    {
        return view('auth.account-links', [
            'liens' => $liens->liensDe($request->user()),
            'moi' => $request->user(),
            'organizationsProposables' => $comptes->organizationsProposables(),
        ]);
    }

    public function store(
        Request $request,
        AccountLinkService $liens,
        LoginAccountResolver $comptes,
    ): RedirectResponse {
        $request->validate([
            'organization' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'target_password' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $this->refuserSiTropDeTentatives($request);

        $moi = $request->user();

        $organization = $comptes->organizationSelectionnable(
            trim((string) $request->input('organization'))
        );

        $cible = $organization === null
            ? null
            : $comptes->compteUnique((string) $request->input('email'), $organization);

        if ($cible === null) {
            $this->journaliser($moi, 'compte_introuvable_ou_ambigu');

            $this->refuserGeneriquement($request);
        }

        try {
            $liens->creer(
                $moi,
                $cible,
                (string) $request->input('password'),
                (string) $request->input('target_password'),
            );
        } catch (AccountLinkRefused $refus) {
            $this->journaliser($moi, $refus->motif);

            // `deja_lie` est le seul motif qu'on peut dire : il ne revele rien
            // que la personne ne sache deja, puisque le lien figure sur SA
            // propre liste. Les autres restent indiscernables.
            if ($refus->motif === 'deja_lie') {
                throw ValidationException::withMessages([
                    'email' => trans('auth.link_already_exists'),
                ]);
            }

            $this->refuserGeneriquement($request);
        }

        RateLimiter::clear($this->cleDeLimitation($request));

        // TASK-1734 — avis de creation aux DEUX comptes : leurs adresses
        // peuvent differer sur cette porte-ci, chacun recoit le sien. Si
        // quelqu'un d'autre a obtenu cette liaison, c'est cet email qui le
        // dit — et il dit ou la revoquer. HORS transaction (QUEUE=sync).
        $moi->notify(new AccountLinkEstablished($cible));

        if (mb_strtolower($cible->email) !== mb_strtolower($moi->email)) {
            $cible->notify(new AccountLinkEstablished($moi));
        }

        return back()->with('success', trans('auth.link_created'));
    }

    public function destroy(Request $request, AccountLink $accountLink, AccountLinkService $liens): RedirectResponse
    {
        try {
            $liens->revoquer($request->user(), $accountLink);
        } catch (AccountLinkRefused $refus) {
            $this->journaliser($request->user(), $refus->motif);

            // Un lien qui ne concerne pas ce compte se comporte comme un lien
            // qui n'existe pas : 404, jamais un refus qui confirmerait son
            // existence a un tiers.
            abort(404);
        }

        return back()->with('success', trans('auth.link_revoked'));
    }

    private function cleDeLimitation(Request $request): string
    {
        // Clef PROPRE, comme a la bascule de T1730 : partager celle du login
        // permettrait de verrouiller la connexion ordinaire en echouant ici.
        return 'account-link|'.$request->user()?->getKey().'|'.$request->ip();
    }

    private function refuserSiTropDeTentatives(Request $request): void
    {
        $clef = $this->cleDeLimitation($request);

        if (! RateLimiter::tooManyAttempts($clef, self::MAX_TENTATIVES)) {
            return;
        }

        throw ValidationException::withMessages([
            'target_password' => trans('auth.throttle', [
                'seconds' => $secondes = RateLimiter::availableIn($clef),
                'minutes' => ceil($secondes / 60),
            ]),
        ]);
    }

    private function refuserGeneriquement(Request $request): never
    {
        RateLimiter::hit($this->cleDeLimitation($request), self::FENETRE_SECONDES);

        throw ValidationException::withMessages([
            'target_password' => trans('auth.link_failed'),
        ]);
    }

    private function journaliser(User $moi, string $motif): void
    {
        Log::info('TASK-1731 creation ou revocation de lien refusee', [
            'user_id' => $moi->getKey(),
            'motif' => $motif,
        ]);
    }
}
