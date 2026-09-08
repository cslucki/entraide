<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AcquisitionEvent;
use App\Models\Country;
use App\Models\PointLedger;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopResumption;
use App\Services\InvitationResumption;
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
    public function create(Request $request, GuestVisitorResolver $visitors, AcquisitionEventRecorder $events): View
    {
        $organization = currentOrganization();

        // TASK-1449 (Growth V3 §5) : `signup_started`, une fois par visiteur Guest de la MEME Organization —
        // lecture pure du cookie (`find()`, jamais `ensure()`) : afficher le formulaire ne cree aucune identite.
        if ($organization !== null) {
            rescue(function () use ($request, $organization, $visitors, $events): void {
                $visitor = $visitors->find($request, $organization);
                if ($visitor !== null) {
                    $events->record($organization, AcquisitionEvent::SIGNUP_STARTED, $events->visitorDimensions($visitor), [], AcquisitionEvent::SIGNUP_STARTED.':visitor:'.$visitor->getKey());
                }
            });
        }
        $localeColumn = app()->getLocale() === 'en' ? 'name_en' : 'name_fr';

        $defaultCountry = $organization?->defaultCountry;
        $priorityCountries = $organization
            ? $organization->priorityCountries()->where('active', true)->get()
            : collect();
        $priorityCountryCodes = $priorityCountries->pluck('code');
        $otherCountries = Country::query()
            ->where('active', true)
            ->when($priorityCountryCodes->isNotEmpty(), fn ($query) => $query->whereNotIn('code', $priorityCountryCodes))
            ->orderBy($localeColumn)
            ->get();

        $allCountries = $priorityCountries->concat($otherCountries);

        if ($defaultCountry && $allCountries->first()?->code !== $defaultCountry->code) {
            $allCountries = $allCountries->reject(fn (Country $c) => $c->code === $defaultCountry->code)->prepend($defaultCountry);
        }

        return view('auth.register', [
            'ref' => $request->input('ref'),
            'countries' => $allCountries,
            'defaultCountryCode' => $defaultCountry?->code,
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

        // TASK-1453 (V3 §11, MASTER Q80) : le Guest ENGAGE la creation de compte depuis son interet Workshop —
        // une reference STRUCTUREE est parquee (jamais une URL) ; VerifyEmailController la consomme apres la verification.
        rescue(fn () => app(WorkshopResumption::class)->park($organization, app(GuestVisitorResolver::class)->find($request, $organization)));

        rescue(fn () => $user->notify(new WelcomeNotification));

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

        // Same as on login: an invitation parked before registration is consumed
        // inside this POST, and the address just registered is re-checked against
        // the one the invitation was issued to.
        if ($redirect = app(InvitationResumption::class)->resume($user)) {
            return $redirect;
        }

        return redirect()->intended($user->getLoginRedirectTarget());
    }
}
