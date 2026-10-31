<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Users\AccountSwitch;
use App\Services\Users\AccountSwitchPreflight;
use App\Services\Users\LoginAccountResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * TASK-1730 (A5a) — passer a SON autre compte, dans une autre Organization.
 *
 * ## Ce que ce geste est, et ce qu'il n'est pas
 *
 * Depuis A4 (T1729), une meme adresse peut porter deux comptes dans deux
 * Organizations. Les deux restent ETRANGERS l'un a l'autre : aucune relation
 * n'est enregistree, et ce controleur n'en cree aucune. Il n'y a ici ni
 * fusion d'identites, ni liaison persistante — c'est A5b — ni mode persona
 * avec retour arriere. C'est un VRAI login du compte cible (arbitrage
 * MASTER D5), et la session d'origine disparait.
 *
 * ## L'adresse n'est JAMAIS postee
 *
 * Elle est lue sur le compte authentifie. On ne peut donc atteindre que des
 * comptes portant SA PROPRE adresse, et il faut encore en prouver le mot de
 * passe. Poster l'adresse aurait fait de cet ecran un moyen d'eprouver les
 * comptes d'autrui.
 *
 * ## Un seul refus, pour cinq causes
 *
 * Organization inconnue, adresse absente de cette Organization, ambiguite,
 * mot de passe faux, compte inutilisable : le MEME message. T1728 a pris
 * cette decision pour le login, et la relacher ici ferait de la bascule
 * l'oracle d'appartenance que le login refuse d'etre. Le motif PRECIS part au
 * journal (arbitrage MASTER D4).
 */
class AccountSwitchController extends Controller
{
    /** Memes bornes que le login. */
    private const MAX_TENTATIVES = 5;

    private const FENETRE_SECONDES = 60;

    public function create(Request $request, LoginAccountResolver $comptes): View
    {
        return view('auth.switch-account', [
            // Les Organizations ACTIVES ET PUBLIQUES, exactement celles que le
            // selecteur de login propose deja. La liste ne dit RIEN de l'endroit
            // ou cette personne possede un compte : elle ne revele donc rien
            // (arbitrage MASTER D3). C'est le mot de passe, et lui seul, qui
            // prouve qu'un compte existe et qu'on le controle.
            'organizationsProposables' => $comptes->organizationsProposables(),
            'organizationCourante' => $request->user()->organization,
        ]);
    }

    public function store(
        Request $request,
        LoginAccountResolver $comptes,
        AccountSwitchPreflight $preflight,
    ): RedirectResponse {
        $request->validate([
            'organization' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $this->refuserSiTropDeTentatives($request);

        $moi = $request->user();

        // Le compte cible, resolu par l'AUTORITE de T1728 — jamais une seconde
        // implementation. `organizationSelectionnable()` n'accepte qu'une
        // Organization active et publique ; `compteUnique()` rend `null` aussi
        // bien pour une adresse absente que pour une ambiguite.
        $organization = $comptes->organizationSelectionnable(
            trim((string) $request->input('organization'))
        );

        $cible = $organization === null
            ? null
            : $comptes->compteUnique($moi->email, $organization);

        if ($cible === null) {
            $this->journaliser($moi, $organization, 'compte_introuvable_ou_ambigu');

            $this->refuserGeneriquement($request);
        }

        // On ne bascule pas vers soi-meme : le geste n'aurait aucun effet, et
        // detruire une session pour la recreer a l'identique serait un risque
        // sans contrepartie.
        if ($cible->getKey() === $moi->getKey()) {
            $this->journaliser($moi, $organization, 'deja_sur_ce_compte');

            throw ValidationException::withMessages([
                'organization' => trans('auth.switch_already_here'),
            ]);
        }

        // ORDRE CRITIQUE — le mot de passe AVANT toute mutation de session.
        //
        // `Auth::attempt()` connecterait immediatement en cas de succes, ce qui
        // interdirait de pre-controler ensuite. On verifie donc le condensat
        // directement, comme le fait deja `Api\AuthController::login()`. La
        // RESOLUTION reste celle de T1728 ; seule la verification est locale.
        if (! Hash::check((string) $request->input('password'), $cible->password)) {
            $this->journaliser($moi, $organization, 'mot_de_passe_invalide');

            $this->refuserGeneriquement($request);
        }

        // Le pre-controle, AVANT de toucher a la session (critere 4).
        if (($motif = $preflight->motifDeRefus($cible)) !== null) {
            $this->journaliser($moi, $organization, 'compte_inutilisable:'.$motif);

            $this->refuserGeneriquement($request);
        }

        RateLimiter::clear($this->cleDeLimitation($request));

        return redirect()->to($this->basculer($cible));
    }

    /**
     * La bascule elle-meme. Rien avant cette ligne n'a touche a la session.
     *
     * TASK-1731 — le geste est EXTRAIT dans `AccountSwitch`, parce que la
     * bascule par lien (A5b) doit faire exactement la meme chose a partir de
     * conditions d'entree differentes. Les quatre subtilites de
     * l'enchainement — invalidate avant regenerate, login avant regenerate,
     * jeton CSRF, `canonicalHome()` — sont documentees la-bas, une seule fois.
     */
    private function basculer(User $cible): string
    {
        return app(AccountSwitch::class)->versLeCompte($cible);
    }

    /**
     * Un compteur PROPRE a la bascule, et c'est delibere.
     *
     * Partager la clef du login aurait fait interferer les deux : des
     * tentatives de bascule auraient verrouille la connexion ordinaire, et
     * reciproquement. L'arbitrage MASTER demandait de reutiliser le mecanisme
     * « lorsque c'est possible, sans creer de contournement ni d'interference ».
     * Le MECANISME est le meme (`RateLimiter`, memes bornes) ; la clef ne l'est
     * pas.
     *
     * La clef porte l'identifiant du compte de DEPART, pas l'adresse : cet
     * ecran n'est atteignable qu'authentifie.
     */
    private function cleDeLimitation(Request $request): string
    {
        return 'switch|'.$request->user()?->getKey().'|'.$request->ip();
    }

    private function refuserSiTropDeTentatives(Request $request): void
    {
        $clef = $this->cleDeLimitation($request);

        if (! RateLimiter::tooManyAttempts($clef, self::MAX_TENTATIVES)) {
            return;
        }

        throw ValidationException::withMessages([
            'password' => trans('auth.throttle', [
                'seconds' => $secondes = RateLimiter::availableIn($clef),
                'minutes' => ceil($secondes / 60),
            ]),
        ]);
    }

    private function refuserGeneriquement(Request $request): never
    {
        RateLimiter::hit($this->cleDeLimitation($request), self::FENETRE_SECONDES);

        throw ValidationException::withMessages([
            'password' => trans('auth.switch_failed'),
        ]);
    }

    /**
     * Le motif PRECIS, cote serveur seulement.
     *
     * L'ecran reste non enumerant ; l'exploitation doit pouvoir diagnostiquer.
     * L'adresse n'est pas journalisee — l'identifiant du compte suffit a
     * retrouver la trace, et il ne fuit pas dans les journaux.
     */
    private function journaliser(User $moi, ?Organization $cible, string $motif): void
    {
        Log::info('TASK-1730 bascule de compte refusee', [
            'depuis_user_id' => $moi->getKey(),
            'depuis_organization_id' => $moi->organization_id,
            'vers_organization_id' => $cible?->getKey(),
            'motif' => $motif,
        ]);
    }
}
