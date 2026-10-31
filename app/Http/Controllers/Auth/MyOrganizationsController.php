<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountLinkConfirmationRequested;
use App\Notifications\AccountLinkEstablished;
use App\Services\Users\AccountLinkEmailProof;
use App\Services\Users\AccountLinkService;
use App\Services\Users\AccountSwitch;
use App\Services\Users\AccountSwitchPreflight;
use App\Services\Users\Exceptions\AccountLinkRefused;
use App\Services\Users\SiblingAccountsPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TASK-1734 — « Mes organisations » : bascule en un clic, premiere
 * association par la preuve de la boite.
 *
 * ## Le principe que tout ce controleur sert
 *
 * **Jamais d'acces sur la seule egalite d'email.** Le lien `account_links`
 * reste l'UNIQUE autorisation de bascule sans mot de passe ; la preuve par la
 * boite ({@see AccountLinkEmailProof}) ne fait que le CREER. Un frere non lie
 * qui clique « passer » ne bascule pas : il recoit un email.
 *
 * ## La resolution de la cible ne regarde que MON panneau
 *
 * `SiblingAccountsPanel::entreePourSlug()` est la seule resolution employee —
 * jamais une recherche generale d'Organization. Un frere peut vivre dans une
 * Organization PRIVEE (le selecteur public de T1728 ne la montrerait pas), et
 * un slug etranger poste a la main rend exactement le meme refus qu'un slug
 * inexistant : rien a apprendre.
 *
 * ## Pourquoi la bascule par lien n'exige plus rien ici
 *
 * Decision MASTER 31/10 : le lien EST l'autorisation — ni mot de passe, ni
 * `LinkSwitchConfirmation` (la fenetre de 300 s reste a l'ancien chemin
 * `/linked-accounts`, inchange). Ce qui demeure, parce que c'est l'etat du
 * MONDE et non l'identite de la personne : le verrou de ligne detenu jusqu'a
 * la decision (une revocation concurrente attend ce verrou, patron T1731), et
 * le preflight de T1730 sous ce meme verrou.
 *
 * ## Les refus rendent un ECRAN, pas une redirection
 *
 * Ces POST partent du menu avatar : il n'y a aucun formulaire derriere ou
 * afficher une erreur de validation. Le refus generique est donc une page —
 * toujours la MEME, quel que soit le motif (journalise, lui, precisement).
 */
class MyOrganizationsController extends Controller
{
    private const MAX_TENTATIVES = 10;

    private const FENETRE_SECONDES = 60;

    /** Trois emails par paire et par fenetre de vie d'un jeton : relancer
     *  reste possible, bombarder sa propre boite non. */
    private const ENVOIS_MAX = 3;

    public function store(
        Request $request,
        string $organization,
        SiblingAccountsPanel $panneau,
        AccountLinkService $liens,
        AccountSwitchPreflight $preflight,
        AccountLinkEmailProof $preuve,
        AccountSwitch $bascule,
    ): RedirectResponse|Response {
        if ($reponse = $this->reponseSiTropDeTentatives($request)) {
            return $reponse;
        }

        $moi = $request->user();

        $entree = $panneau->entreePourSlug($moi, $organization);

        if ($entree === null || $entree->etat === SiblingAccountsPanel::ETAT_INDISPONIBLE) {
            return $this->refuser($request, $moi, null, 'cible_absente_ou_indisponible');
        }

        $cible = $entree->compte;

        if ($entree->etat === SiblingAccountsPanel::ETAT_BASCULABLE) {
            // LA DECISION, sous verrou, et le verrou tient jusqu'a ce que la
            // transaction rende la main (patron T1731, inchange).
            $motif = DB::transaction(function () use ($liens, $preflight, $moi, $cible): ?string {
                if ($liens->lienVerrouilleEntre($moi, $cible) === null) {
                    return 'lien_revoque';
                }

                return $preflight->motifDeRefus($cible);
            });

            if ($motif !== null) {
                return $this->refuser($request, $moi, $cible, $motif);
            }

            RateLimiter::clear($this->cleDeLimitation($request));

            return redirect()->to($bascule->versLeCompte($cible));
        }

        // ETAT_A_CONFIRMER — le panneau est un AFFICHAGE, pas une decision :
        // tout se reverifie au present, au moment d'engager la preuve.
        if (mb_strtolower($moi->email) !== mb_strtolower($cible->email)
            || ! $moi->hasVerifiedEmail()
            || ! $cible->hasVerifiedEmail()
            || $preflight->motifDeRefus($cible) !== null) {
            return $this->refuser($request, $moi, $cible, 'preuve_boite_inapplicable');
        }

        $cleEnvoi = $this->cleDEnvoi($moi, $cible);

        if (RateLimiter::tooManyAttempts($cleEnvoi, self::ENVOIS_MAX)) {
            return $this->ecranRefus(trans('auth.throttle', [
                'seconds' => $secondes = RateLimiter::availableIn($cleEnvoi),
                'minutes' => ceil($secondes / 60),
            ]));
        }

        RateLimiter::hit($cleEnvoi, AccountLinkEmailProof::TTL_SECONDES);

        $jeton = $preuve->emettre($moi, $cible);

        // HORS de toute transaction : la PROD tourne en QUEUE=sync, cet appel
        // ENVOIE l'email, maintenant (lecon A6).
        $moi->notify(new AccountLinkConfirmationRequested($cible, $jeton));

        return redirect()->route('my-organizations.sent')
            ->with('myorg_target', $cible->organization?->name ?? '');
    }

    /**
     * L'ecran « un email vient de vous etre envoye », apres redirection.
     *
     * Garde par le flash : l'URL tapee a la main, sans envoi qui precede,
     * n'affiche rien — elle renvoie au profil.
     */
    public function sent(Request $request): RedirectResponse|\Illuminate\View\View
    {
        if (! $request->session()->has('myorg_target')) {
            return redirect()->route('profile.edit');
        }

        return view('auth.my-organizations-sent', [
            'organizationName' => $request->session()->get('myorg_target'),
            'email' => $request->user()->email,
        ]);
    }

    /**
     * Le GET du lien de l'email. Il ne consomme JAMAIS le jeton.
     *
     * Les scanners de boite (SafeLinks et consorts) prechargent les liens :
     * un GET consommant rendrait le bouton mort avant d'etre vu. Seul le POST
     * de {@see finalize()} consomme.
     */
    public function confirm(Request $request, string $token, AccountLinkEmailProof $preuve): \Illuminate\View\View
    {
        $valeur = $preuve->lire($token);

        if ($valeur === null) {
            // Inconnu, expire ou deja consomme : indiscernables, et l'ecran
            // ne nomme AUCUNE organisation.
            return view('auth.my-organizations-invalid');
        }

        $moi = $request->user();

        if ((string) $moi->getKey() !== $valeur['source_id']) {
            // La bonne personne au mauvais poste (autre compte de la meme
            // boite, ou tiers) : on dit QUOI faire, sans nommer qui que ce
            // soit — le jeton reste vivant pour la bonne session.
            return view('auth.my-organizations-wrong-account');
        }

        $cible = User::query()->find($valeur['cible_id']);

        if ($cible === null) {
            return view('auth.my-organizations-invalid');
        }

        return view('auth.my-organizations-confirm', [
            'token' => $token,
            'organizationName' => $cible->organization?->name ?? '',
        ]);
    }

    /**
     * Le POST du bouton : consomme le jeton, cree le lien, bascule.
     */
    public function finalize(
        Request $request,
        string $token,
        AccountLinkEmailProof $preuve,
        AccountLinkService $liens,
        AccountSwitchPreflight $preflight,
        AccountSwitch $bascule,
    ): RedirectResponse|Response {
        if ($reponse = $this->reponseSiTropDeTentatives($request)) {
            return $reponse;
        }

        $moi = $request->user();

        // Consommation ATOMIQUE, en premier. Un jeton presente par la
        // mauvaise session est donc BRULE, et c'est voulu : il a ete expose a
        // une session qui n'avait pas a le voir.
        $valeur = $preuve->consommer($token);

        if ($valeur === null) {
            return $this->refuser($request, $moi, null, 'jeton_invalide_ou_consomme');
        }

        if ((string) $moi->getKey() !== $valeur['source_id']) {
            return $this->refuser($request, $moi, null, 'jeton_mauvaise_session');
        }

        $cible = User::query()->find($valeur['cible_id']);

        if ($cible === null) {
            return $this->refuser($request, $moi, null, 'cible_disparue');
        }

        $motif = DB::transaction(function () use ($liens, $preflight, $moi, $cible): ?string {
            try {
                // Toutes les gardes de la preuve par la boite sont DANS la
                // methode, reverifiees au present : egalite LOWER des deux
                // adresses, verification des deux cotes, auto-liaison.
                $liens->creerParPreuveDeBoite($moi, $cible);
            } catch (AccountLinkRefused $refus) {
                if ($refus->motif !== 'deja_lie') {
                    return $refus->motif;
                }
                // Deja lie — par une confirmation concurrente ou par
                // l'ancien formulaire : c'est l'etat qu'on visait, on
                // continue vers la bascule.
            }

            return $preflight->motifDeRefus($cible);
        });

        if ($motif !== null) {
            return $this->refuser($request, $moi, $cible, $motif);
        }

        RateLimiter::clear($this->cleDeLimitation($request));

        // Un seul avis : les deux comptes partagent la boite par construction
        // (garde d'egalite ci-dessus), deux envois seraient le meme email en
        // double. HORS transaction, QUEUE=sync (lecon A6).
        $moi->notify(new AccountLinkEstablished($cible));

        return redirect()->to($bascule->versLeCompte($cible))
            ->with('success', trans('auth.myorg_linked_flash', [
                'organization' => $cible->organization?->name ?? '',
            ]));
    }

    private function cleDeLimitation(Request $request): string
    {
        // Clef PROPRE, comme T1730/T1731 : partagee avec le login, des echecs
        // ici verrouilleraient la connexion ordinaire.
        return 'my-organizations|'.$request->user()?->getKey().'|'.$request->ip();
    }

    private function cleDEnvoi(User $moi, User $cible): string
    {
        return 'my-organizations-mail|'.$moi->getKey().'|'.$cible->getKey();
    }

    private function reponseSiTropDeTentatives(Request $request): ?Response
    {
        $clef = $this->cleDeLimitation($request);

        if (! RateLimiter::tooManyAttempts($clef, self::MAX_TENTATIVES)) {
            return null;
        }

        return $this->ecranRefus(trans('auth.throttle', [
            'seconds' => $secondes = RateLimiter::availableIn($clef),
            'minutes' => ceil($secondes / 60),
        ]));
    }

    private function refuser(Request $request, User $moi, ?User $cible, string $motif): Response
    {
        RateLimiter::hit($this->cleDeLimitation($request), self::FENETRE_SECONDES);

        Log::info('TASK-1734 refus Mes organisations', [
            'user_id' => (string) $moi->getKey(),
            'cible_id' => $cible ? (string) $cible->getKey() : null,
            'motif' => $motif,
        ]);

        // UN seul message pour tous les motifs : distinguer dirait ce que le
        // panneau de quelqu'un d'autre contient (meme discipline que T1728,
        // T1730 et T1731).
        return $this->ecranRefus(trans('auth.myorg_refused'));
    }

    private function ecranRefus(string $message): Response
    {
        return response()->view('auth.my-organizations-refused', [
            'message' => $message,
        ], 422);
    }
}
