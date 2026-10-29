<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password.
     *
     * TASK-1728 (A3) — le compte authentifie est confirme par son UUID.
     *
     * ## Le defaut, et c'est le plus insidieux des quatre
     *
     * L'ancien code connaissait le compte PARFAITEMENT — `$request->user()`,
     * deja authentifie — puis jetait cette certitude pour re-resoudre par
     * email : `Auth::guard('web')->validate(['email' => ..., 'password' => ...])`
     * passe par `retrieveByCredentials()`, donc par un `->first()`.
     *
     * En A4, une personne aurait pu confirmer son mot de passe en saisissant
     * celui de l'HOMONYME — sur sa propre session, devant la garde
     * `password.confirm` qui protege precisement les gestes sensibles. Le
     * formulaire aurait dit « mot de passe correct » pour un mot de passe qui
     * n'est pas le sien.
     *
     * ## La correction
     *
     * `validateCredentials()` ne resout RIEN : il compare le mot de passe
     * fourni au hash du compte qu'on lui DONNE (`$user->getAuthPassword()`).
     * L'identite vient donc de la session, et le mot de passe ne sert qu'a la
     * verifier — ce qui est son role, et le seul.
     *
     * Le hachage reste l'affaire du provider : on ne reimplemente pas de
     * `Hash::check()` ici, ce qui laisserait deux juges libres de diverger (et
     * perdrait le rehachage automatique).
     */
    public function store(Request $request): RedirectResponse
    {
        if (! Auth::guard('web')->getProvider()->validateCredentials(
            $request->user(),
            ['password' => $request->string('password')->toString()],
        )) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
