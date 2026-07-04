<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\PointLedger;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Services\ReferralService;
use App\Support\Tenancy\DefaultOrganizationResolver;
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
        $organization = currentOrganization();
        $localeColumn = app()->getLocale() === 'en' ? 'name_en' : 'name_fr';

        $priorityCountries = $organization
            ? $organization->priorityCountries()->where('active', true)->get()
            : collect();
        $priorityCountryCodes = $priorityCountries->pluck('code');
        $otherCountries = Country::query()
            ->where('active', true)
            ->when($priorityCountryCodes->isNotEmpty(), fn ($query) => $query->whereNotIn('code', $priorityCountryCodes))
            ->orderBy($localeColumn)
            ->get();

        return view('auth.register', [
            'ref' => $request->input('ref'),
            'countries' => $priorityCountries->concat($otherCountries),
        ]);
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
            'first_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['required', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $organization = currentOrganization() ?? DefaultOrganizationResolver::resolve();

        if (! $organization) {
            throw ValidationException::withMessages([
                'email' => 'Aucune organisation active n\'est disponible pour l\'inscription.',
            ]);
        }

        $user = User::create([
            'name' => $request->name,
            'first_name' => $request->first_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'country_code' => $request->country_code,
            'password' => Hash::make($request->password),
            'points_balance' => 100,
            'organization_id' => $organization->id,
        ]);

        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => null,
            'delta' => 100,
            'organization_id' => $user->organization_id,
            'reason' => 'welcome_bonus',
        ]);

        event(new Registered($user));

        $user->notify(new WelcomeNotification);

        Auth::login($user);

        if ($ref = $request->input('ref')) {
            try {
                app(ReferralService::class)->attributeByCode(
                    $user, $ref,
                    organizationId: $organization->id,
                );
            } catch (\RuntimeException) {
            }
        }

        if ($user->organization_id) {
            $organization = $user->organization;
            if ($organization && $organization->is_active) {
                return redirect()->intended(canonicalHome($organization));
            }
        }

        return redirect('/');
    }
}
