<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PointLedger;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Services\ReferralService;
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
    public function create(Request $request): View
    {
        return view('auth.register', ['ref' => $request->input('ref')]);
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
        ]);

        $organization = currentOrganization();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'points_balance' => 100,
            'community_id' => $organization?->id,
        ]);

        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => null,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);

        event(new Registered($user));

        $user->notify(new WelcomeNotification);

        Auth::login($user);

        if ($ref = $request->input('ref')) {
            try {
                app(ReferralService::class)->attributeByCode(
                    $user, $ref,
                    organizationId: $organization?->id,
                );
            } catch (\RuntimeException) {
            }
        }

        if ($user->community_id) {
            $community = $user->community;
            if ($community && $community->is_active) {
                return redirect()->intended(route('community.home', ['community' => $community->slug]));
            }
        }

        return redirect(route('dashboard', absolute: false));
    }
}
