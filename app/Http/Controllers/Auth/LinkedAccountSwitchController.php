<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountLink;
use App\Models\User;
use App\Services\Users\AccountLinkService;
use App\Services\Users\AccountSwitch;
use App\Services\Users\AccountSwitchPreflight;
use App\Services\Users\LinkSwitchConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * TASK-1731 (A5b) — basculer vers un compte LIE.
 *
 * ## Ce que le lien dispense de faire, et ce qu'il ne dispense pas de faire
 *
 * Il dispense de donner le mot de passe du compte CIBLE a chaque bascule :
 * c'est tout son interet, et c'est aussi tout son risque. Un lien non protege
 * ferait d'un compte compromis une porte vers l'autre.
 *
 * Il ne dispense donc PAS de prouver qu'on est bien la personne devant le
 * clavier : une confirmation RECENTE du mot de passe du compte SOURCE est
 * exigee (300 secondes, arbitrage MASTER). La difference compte : on prouve
 * qu'on controle le compte d'ou l'on part, pas celui ou l'on va.
 *
 * ## Le verrou doit etre DETENU jusqu'a la decision
 *
 * Precision MASTER : `lockForUpdate()` ne prouve rien s'il est relache avant.
 * La transaction ci-dessous prend le verrou sur la ligne de lien et ne rend la
 * main qu'apres avoir TRANCHE — lien present, confirmation recente, compte
 * cible utilisable. Une revocation concurrente attend donc ce verrou : soit
 * elle passe avant et la bascule ne trouve plus rien, soit elle passe apres et
 * la bascule etait legitime a l'instant ou elle a ete decidee.
 *
 * Le mot de passe est verifie AVANT d'entrer dans la transaction, et c'est
 * delibere : `Hash::check` coute une centaine de millisecondes, et les
 * depenser en tenant un verrou de ligne punirait tout le monde.
 */
class LinkedAccountSwitchController extends Controller
{
    private const MAX_TENTATIVES = 5;

    private const FENETRE_SECONDES = 60;

    public function store(
        Request $request,
        AccountLink $accountLink,
        AccountLinkService $liens,
        AccountSwitchPreflight $preflight,
        LinkSwitchConfirmation $confirmation,
        AccountSwitch $bascule,
    ): RedirectResponse {
        $request->validate([
            'password' => ['sometimes', 'nullable', 'string'],
        ]);

        $this->refuserSiTropDeTentatives($request);

        $moi = $request->user();

        // Un lien qui ne me concerne pas se comporte comme un lien qui
        // n'existe pas : 404, jamais un refus qui confirmerait son existence.
        if (! $accountLink->concerne($moi)) {
            $this->journaliser($moi, null, 'lien_etranger');

            abort(404);
        }

        $cible = $accountLink->autreQue($moi);

        if ($cible === null) {
            abort(404);
        }

        // La confirmation, AVANT la transaction. Si elle est deja recente, on
        // ne redemande rien — c'est la fenetre de cinq minutes.
        if (! $confirmation->estRecente($moi)) {
            $motDePasse = (string) $request->input('password', '');

            if ($motDePasse === '' || ! Hash::check($motDePasse, $moi->password)) {
                $this->journaliser($moi, $cible, 'confirmation_refusee');

                $this->refuserGeneriquement($request);
            }

            $confirmation->poser($moi);
        }

        // LA DECISION, sous verrou, et le verrou tient jusqu'a ce que la
        // transaction rende la main.
        $motif = DB::transaction(function () use ($liens, $preflight, $confirmation, $moi, $cible): ?string {
            $lien = $liens->lienVerrouilleEntre($moi, $cible);

            if ($lien === null) {
                // Revoque entre l'affichage et maintenant.
                return 'lien_revoque';
            }

            // Reverifiee SOUS verrou : entre la pose ci-dessus et ici, rien ne
            // garantissait qu'elle soit encore valable.
            if (! $confirmation->estRecente($moi)) {
                return 'confirmation_expiree';
            }

            // Les cinq etats bloquants, par l'autorite de T1730, inchangee.
            return $preflight->motifDeRefus($cible);
        });

        if ($motif !== null) {
            $this->journaliser($moi, $cible, $motif);

            $this->refuserGeneriquement($request);
        }

        RateLimiter::clear($this->cleDeLimitation($request));

        // Deuxieme ceinture : `versLeCompte()` invalide la session, donc la
        // confirmation disparait de toute facon. On l'oublie quand meme
        // explicitement, pour que la propriete reste vraie si un jour la
        // bascule cessait d'invalider.
        $confirmation->oublier();

        return redirect()->to($bascule->versLeCompte($cible));
    }

    private function cleDeLimitation(Request $request): string
    {
        return 'linked-switch|'.$request->user()?->getKey().'|'.$request->ip();
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

    private function journaliser(User $moi, ?User $cible, string $motif): void
    {
        Log::info('TASK-1731 bascule par lien refusee', [
            'depuis_user_id' => $moi->getKey(),
            'vers_user_id' => $cible?->getKey(),
            'motif' => $motif,
        ]);
    }
}
