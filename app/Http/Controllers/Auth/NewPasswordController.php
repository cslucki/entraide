<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Users\PasswordResetAccountResolver;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function __construct(private readonly PasswordResetAccountResolver $comptes) {}

    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * TASK-1727 (A2) — c'est le TOKEN qui designe le compte.
     *
     * ## Arbitrage MASTER D1, option B
     *
     * L'URL du lien n'a pas eu a changer, et les liens deja envoyes continuent
     * de fonctionner : la desambiguisation ne vient pas d'un identifiant ajoute
     * dans l'adresse, mais du token lui-meme. Depuis TASK-1727 une ligne de
     * token porte un `user_id` ; l'email ne sert plus qu'a borner les
     * candidats, et le token tranche entre eux.
     *
     * C'est la seule donnee qui PUISSE trancher : entre deux comptes portant la
     * meme adresse, l'adresse ne distingue rien, par definition.
     *
     * ## Pourquoi resoudre ici, alors que le broker revalide ensuite
     *
     * Ce n'est pas une double verification redondante, c'est une passation
     * d'identite. On resout le compte, puis on le passe au broker par son `id`
     * — qui n'a donc plus a le redeviner par email (`->first()`). Le broker
     * revalide le token POUR CE COMPTE, par le meme depot que celui qu'on vient
     * d'interroger : une seule autorite sur le hachage et l'expiration, jamais
     * deux juges libres de diverger.
     *
     * ## Le parcours d'Organization
     *
     * Dans `/org/{slug}/reset-password`, les candidats sont bornes au tenant :
     * un token valide pour un compte d'une AUTRE Organization ne resout rien
     * ici, et rend le meme refus qu'un token inconnu. Le retour apres succes
     * renvoie vers la connexion DE CE tenant, et non vers la connexion globale.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $organization = currentOrganization();

        $compte = $this->comptes->compteDuToken(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $organization,
        );

        // Un token qui ne designe aucun compte ATTEIGNABLE depuis ce parcours :
        // inconnu, expire, deja consomme, ou appartenant a un compte d'un autre
        // tenant. Les quatre cas rendent le MEME refus — distinguer le dernier
        // reviendrait a confirmer qu'une adresse existe ailleurs.
        if ($compte === null) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __(Password::INVALID_TOKEN)]);
        }

        if ($compte->banned_at !== null) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => trans('auth.deactivated')]);
        }

        $status = Password::reset(
            [
                ...$this->comptes->credentials($compte),
                'password' => $request->string('password')->toString(),
                'password_confirmation' => $request->string('password_confirmation')->toString(),
                'token' => $request->string('token')->toString(),
            ],
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
        }

        return redirect()
            ->route($organization ? 'organization.login' : 'login', $organization ? [$organization] : [])
            ->with('status', __($status));
    }
}
