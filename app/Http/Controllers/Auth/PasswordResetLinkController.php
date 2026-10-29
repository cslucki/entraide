<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\Users\PasswordResetAccountResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function __construct(private readonly PasswordResetAccountResolver $comptes) {}

    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * TASK-1727 (A2) — la demande designe des COMPTES, pas une adresse.
     *
     * ## Ce qui a change
     *
     * Trois resolutions `User::where('email', ...)->first()` vivaient ici — une
     * pour la garde `banned_at`, une pour le journal, et la troisieme CACHEE
     * dans `Password::sendResetLink(['email' => ...])`, ou le broker la refait
     * lui-meme. Elles sont remplacees par une resolution unique et explicite,
     * puis le compte trouve est passe au broker par son `id` : il n'a plus rien
     * a redeviner.
     *
     * ## Le parcours d'Organization (arbitrage MASTER D3)
     *
     * Dans `/org/{slug}/forgot-password`, la recherche est BORNEE au tenant.
     * Une adresse appartenant a une autre Organization rend donc une collection
     * vide — strictement indiscernable d'une adresse inconnue, puisque les deux
     * produisent `INVALID_USER` et le meme message. Rien ne revele jamais
     * l'appartenance d'un tiers a un autre tenant.
     *
     * ## Anti-enumeration : le comportement est CONSERVE, pas ameliore
     *
     * Ce formulaire distingue aujourd'hui l'adresse connue de l'inconnue (l'une
     * rend un `status`, l'autre une erreur de champ), et un compte BANNI est
     * deja traite comme un succes silencieux. T1727 ne touche a aucune des deux
     * reponses : durcir l'anti-enumeration serait un changement de
     * comportement, et le point 6 demande de le preserver.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $organization = currentOrganization();

        $comptes = $this->comptes->comptesCibles($request->string('email')->toString(), $organization);

        // Un compte desactive repond comme un succes, sans rien envoyer :
        // comportement anterieur, conserve a l'identique.
        if ($comptes->isNotEmpty() && $comptes->every(fn (User $u): bool => $u->banned_at !== null)) {
            return back()->with('status', trans(Password::RESET_LINK_SENT));
        }

        // Boucle, et non `first()`. Aujourd'hui l'unique global sur
        // `users.email` borne la collection a un element : le comportement est
        // donc identique a l'existant. Mais c'est la forme qui sera JUSTE le
        // jour ou deux comptes partageront une adresse — chacun recevra son
        // propre lien, portant son propre token, ce que la table sait
        // desormais stocker. Point 7 : prevu, pas active.
        $envoye = false;
        $dernierStatut = Password::INVALID_USER;

        foreach ($comptes as $compte) {
            if ($compte->banned_at !== null) {
                continue;
            }

            $dernierStatut = Password::sendResetLink($this->comptes->credentials($compte));

            if ($dernierStatut === Password::RESET_LINK_SENT) {
                $envoye = true;

                EmailLog::create([
                    'template_id' => null,
                    'user_id' => $compte->id,
                    'organization_id' => $compte->organization_id,
                    'to_email' => $compte->email,
                    'subject' => 'Réinitialisation de votre mot de passe',
                    'status' => 'sent',
                    'data' => [
                        'source' => 'public-password-reset',
                        'broker' => 'users',
                    ],
                ]);
            }
        }

        return $envoye
            ? back()->with('status', __(Password::RESET_LINK_SENT))
            : back()->withInput($request->only('email'))
                ->withErrors(['email' => __($dernierStatut)]);
    }
}
