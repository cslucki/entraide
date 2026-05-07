<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PointLedger;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'referral_code' => ['nullable', 'string', 'exists:users,referral_code'],
        ]);

        $community = app()->has('current_community') ? app('current_community') : null;

        $referrer = null;
        if ($request->referral_code) {
            $referrer = User::where('referral_code', $request->referral_code)->first();

            if ($referrer && $referrer->email === $request->email) {
                return back()->withInput()->withErrors(['referral_code' => 'Vous ne pouvez pas vous parrainer vous-même.']);
            }
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'points_balance' => 100,
            'community_id' => $community?->id,
            'referrer_id' => $referrer?->id,
        ]);

        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => null,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);

        if ($referrer && $referrer->id !== $user->id) {
            $bonus = (int) Setting::get('referral_reward_registration', 50);

            Referral::create([
                'referrer_id' => $referrer->id,
                'referee_id' => $user->id,
                'registration_reward_paid' => true,
            ]);

            // Give bonus to referrer
            $referrer->increment('points_balance', $bonus);
            PointLedger::create([
                'user_id' => $referrer->id,
                'delta' => $bonus,
                'reason' => 'referral_bonus',
            ]);

            // Give bonus to referee
            $user->increment('points_balance', $bonus);
            PointLedger::create([
                'user_id' => $user->id,
                'delta' => $bonus,
                'reason' => 'referral_bonus',
            ]);
        }

        event(new Registered($user));

        $user->notify(new WelcomeNotification());

        Auth::login($user);

        if ($user->community_id) {
            $community = $user->community;
            if ($community && $community->is_active) {
                return redirect()->intended(route('community.home', ['community' => $community->slug]));
            }
        }

        return redirect(route('dashboard', absolute: false));
    }
}
