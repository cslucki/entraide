<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AcquisitionEvent;
use App\Models\Country;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\User;
use App\Rules\UniqueEmailInOrganization;
use App\Rules\NomDePersonneAcceptable;
use App\Rules\TurnstileValide;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\Acquisition\GuestAttribution;
use App\Services\GuestShell\GuestIdentityThrottle;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\InvitationResumption;
use App\Services\ReferralService;
use App\Services\Security\TurnstileVerifier;
use App\Services\Workshops\WorkshopResumption;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
            // TASK-1714 volet 1 — voir `menuEstPropose()`.
            //
            // La resolution est la MEME que celle de `store()` : sur la route
            // globale `/register`, `currentOrganization()` est nul et c'est
            // l'organisation par defaut qui s'applique. Lire `$organization`
            // seul y rendait `null`, donc aucun menu — alors que c'est
            // precisement le point de chute de qui ne sait pas ou aller.
            'organizationsOuvertes' => $this->menuEstPropose($organization ?? DefaultOrganizationResolver::resolve())
                ? $this->organizationsOuvertes()
                : collect(),
        ]);
    }

    /**
     * TASK-1714 volet 1 — le menu de choix est-il propose ici ?
     *
     * ## A quoi il sert, et c'est ce qui fixe sa portee
     *
     * Cyril, 27/10 : « les personnes ne connaissent pas forcement l'adresse
     * pour s'inscrire dans leur organisation, et c'est pour ca qu'on donne
     * cette possibilite de passer par celle de BouclePro par defaut ».
     *
     * C'est un RATTRAPAGE, pas un selecteur universel. Il n'a donc de sens que
     * sur l'organisation PRINCIPALE — le point de chute de qui ne sait pas ou
     * aller. Quelqu'un qui arrive sur `/org/launchpals/register` connait son
     * adresse : lui proposer de choisir ne l'aiderait pas, et brouillerait le
     * message de la page.
     *
     * Deuxieme condition : au moins deux organisations ouvertes. Un choix sans
     * alternative n'est pas un choix.
     */
    private function menuEstPropose(?Organization $courante): bool
    {
        $defaut = DefaultOrganizationResolver::resolve();

        return $defaut !== null
            && $courante !== null
            && $courante->is($defaut)
            && $this->organizationsOuvertes()->count() > 1;
    }

    /**
     * TASK-1714 volet 1 — les Organizations ou l'on peut s'inscrire.
     *
     * ## Partout, et c'est un arbitrage de Cyril
     *
     * La liste etait d'abord bornee au point d'entree generique, au motif que
     * quelqu'un arrivant sur `/org/ofsh/register` y venait deliberement.
     * Arbitrage du 27/10 : le menu reste sur TOUTES les Organizations. Se
     * tromper d'adresse est au moins aussi frequent que viser juste, et le
     * menu indique de toute facon ou l'on se trouve.
     *
     * La liste ne contient qu'une entree quand une seule Organization est
     * ouverte : la vue n'affiche alors rien, un choix sans alternative n'etant
     * pas un choix.
     *
     * ## Ce qui rend une Organization « ouverte »
     *
     * Trois conditions, et aucune n'est nouvelle :
     *
     * - `is_active` et `is_public` — le couple deja utilise par
     *   `GuestShellGate`, `WorkshopResumption`, `GuestPageContextResolver` et
     *   `OrganizationLandingController`. Inventer ici un quatrieme critere
     *   ferait diverger ce que « publique » veut dire.
     * - **pas une sandbox de scenario** : `store()` refuse deja l'inscription
     *   dans un monde de demonstration (TASK-1650). Lister une Organization
     *   dont l'inscription est fermee quelques lignes plus bas enverrait les
     *   gens dans un mur.
     *
     * Les Organizations supprimees sont exclues par le SoftDeletes du modele.
     *
     * @return \Illuminate\Support\Collection<int, Organization>
     */
    private function organizationsOuvertes(): Collection
    {
        return Organization::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->whereNull('scenario_sandbox_created_at')
            // Departage par `id` derriere le nom : deux Organizations
            // homonymes laisseraient sinon PostgreSQL choisir au gre du plan
            // (meme lecon que DefaultOrganizationResolver).
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, GuestVisitorResolver $visitors, GuestAttribution $attribution, GuestIdentityThrottle $identities, AcquisitionEventRecorder $events, TurnstileVerifier $turnstile): RedirectResponse
    {
        // TASK-1714 — l'Organization d'ARRIVEE, celle que l'URL designe. Elle
        // est resolue avant la validation, dont la regle ci-dessous depend.
        $arrivee = currentOrganization() ?? DefaultOrganizationResolver::resolve();
        $menu = $this->menuEstPropose($arrivee);
        $ouvertes = $menu ? $this->organizationsOuvertes() : collect();

        // TASK-1729 (A4) — l'Organization d'accueil, resolue AVANT la
        // validation parce que la regle d'unicite en a besoin.
        //
        // L'expression est celle qui vivait APRES le `validate()`, deplacee
        // telle quelle. Elle est sure ici pour la raison que son commentaire
        // d'origine donnait deja : `firstWhere` puise dans `$ouvertes`, la
        // liste DEJA validee, donc un slug inconnu rend `null` et l'on retombe
        // sur l'Organization d'arrivee. Et si ce slug est invalide, la regle
        // `organisation_confirmee` ci-dessous refuse de toute facon la
        // requete : aucun compte ne nait.
        $organization = $menu
            ? ($ouvertes->firstWhere('slug', $request->string('organisation_confirmee')->toString()) ?? $arrivee)
            : $arrivee;

        $request->validate([
            'attribution' => ['sometimes', 'array:shortcut,utm_source,utm_medium,utm_campaign'],
            'attribution.shortcut' => ['nullable', 'string', 'max:32'],
            'attribution.utm_source' => ['nullable', 'string', 'max:200'],
            'attribution.utm_medium' => ['nullable', 'string', 'max:200'],
            'attribution.utm_campaign' => ['nullable', 'string', 'max:200'],
            // TASK-1712 — `max:255` etait une borne de COLONNE, pas de sens :
            // le 39e compte de la PROD s'est inscrit sous 76 caracteres de
            // publicite crypto, et ce nom est ressorti dans le sujet de son
            // email de bienvenue.
            'name' => ['required', 'string', 'max:255', new NomDePersonneAcceptable],
            'first_name' => ['required', 'string', 'max:255', new NomDePersonneAcceptable],
            // TASK-1729 (A4) — l'unicite devient celle de l'ORGANIZATION d'accueil.
            // `unique:User::class` posait la question a la plateforme entiere ;
            // elle se pose desormais au tenant dans lequel on s'inscrit, et sans
            // distinction de casse. L'Organization est resolue plus bas, mais la
            // regle en a besoin MAINTENANT : on la resout donc en amont.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255',
                new UniqueEmailInOrganization($organization?->getKey())],
            'phone' => ['required', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],

            // TASK-1713 — Cloudflare Turnstile. S'AJOUTE aux regles de nom de
            // TASK-1712 au-dessus, ne les remplace pas : un robot qui
            // resoudrait le defi reste soumis a `NomDePersonneAcceptable`, et
            // un humain qui ecrit une publicite dans son nom reste refuse.
            //
            // Le branchement est CONDITIONNEL et c'est delibere : captcha
            // eteint, le champ n'a aucune regle et le formulaire se comporte
            // exactement comme avant. C'est ce qui permet aux 39 POST
            // `/register` des tests existants de rester verts, et a la PROD de
            // recevoir ce code sans que l'inscription change.
            //
            // `required` est implicite : un jeton absent est refuse SANS appel
            // reseau. `TurnstileValide` ne tourne donc que sur un jeton
            // present, et c'est lui seul qui parle a Cloudflare.
            // TASK-1714 — le choix d'organisation DESIGNE, et il est obligatoire.
            //
            // Il ne navigue plus (retour Cyril, 27/10 : « tu n'es pas oblige de
            // rafraichir la page »). Le champ determine donc l'organisation ou
            // le compte nait.
            //
            // CE QUE LA REGLE GARANTIT, et pourquoi elle ne cede rien. Les
            // valeurs acceptees sont EXACTEMENT les organisations ouvertes a
            // l'inscription — `is_active`, `is_public`, pas une sandbox. Ce
            // sont celles dont la page d'inscription est de toute facon
            // publiquement joignable : poster leur slug n'ouvre donc rien que
            // naviguer n'ouvrirait. Une organisation privee, inactive ou
            // sandbox n'est PAS dans la liste, et son slug est refuse.
            //
            // Et la regle n'existe QUE si le menu est rendu, c'est-a-dire sur
            // l'organisation principale. Sur `/org/launchpals/register`, le
            // champ est purement et simplement IGNORE — sans quoi il suffirait
            // de le poster a la main pour se faire router ailleurs depuis
            // n'importe quelle page.
            'organisation_confirmee' => $menu
                ? ['required', 'string', Rule::in($ouvertes->pluck('slug')->all())]
                : [],

            'cf-turnstile-response' => $turnstile->enabled()
                ? ['required', 'string', 'max:2048', new TurnstileValide($turnstile)]
                : [],
        ]);

        // L'organisation DESIGNEE l'emporte quand le menu existe ; sinon celle
        // de l'URL. Resolue plus haut, avant la validation — voir TASK-1729.
        if (! $organization) {
            throw ValidationException::withMessages([
                'email' => 'Aucune organisation active n\'est disponible pour l\'inscription.',
            ]);
        }

        // TASK-1650 — on ne s'INSCRIT pas dans une sandbox de scenario.
        //
        // Mesure faite en revue, et le contournement etait total : `/register`
        // vit sous `/org/{organization}`, et la resolution par slug n'exige
        // que `is_active`, jamais `is_public`. Une sandbox nait active. Donc
        // `POST /org/<slug-sandbox>/register` inscrivait n'importe qui dans la
        // sandbox, SANS authentification — et le slug est affiche sur l'ecran
        // du Scenario Manager.
        //
        // La consequence etait l'inverse exact du but recherche : le preflight
        // voyait ensuite un compte etranger et refusait Reset ET Remove, et la
        // garde de `/admin/users` interdisait de sortir ce compte. La sandbox
        // devenait irretirable par tout geste produit.
        //
        // Le refus est pose ICI, apres la resolution et AVANT toute creation
        // de compte. La sandbox reste parfaitement NAVIGABLE : c'est un monde
        // de demonstration, et la fermer entierement lui oterait sa raison
        // d'etre. Seule l'inscription est fermee.
        if ($organization->scenario_sandbox_created_at !== null) {
            throw ValidationException::withMessages([
                'email' => "Cette organisation est un monde de demonstration : on ne peut pas s'y inscrire.",
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

        // TASK-1714 volet 2 — l'approbation, posee HORS assignation de masse.
        //
        // `approved_at` n'est deliberement PAS dans `User::$fillable`, comme
        // `email_verified_at` et `must_set_password` : un drapeau qui ouvre
        // l'acces ne doit pas pouvoir etre bascule par un formulaire qui
        // recopierait trop large. `forceFill()` est le seul chemin, et il est
        // ici explicite.
        //
        // `now()` quand l'Organization n'exige rien, jamais `null` : un compte
        // approuve doit l'etre EXPLICITEMENT en base, pour qu'un `SELECT`
        // reponde a « qui attend ? » sans connaitre le reglage. C'est aussi ce
        // qui rend la non-retroactivite stable — activer le reglage plus tard
        // ne remet personne en attente.
        $user->forceFill([
            'approved_at' => $organization->requires_account_approval ? null : now(),
        ])->save();

        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => null,
            'delta' => 100,
            'organization_id' => $user->organization_id,
            'reason' => 'welcome_bonus',
        ]);

        // TASK-1464 (audit OPUS final P1-2, Growth V3 §5) : le chemin « lien court → inscription » a un porteur.
        // Au POST seulement (afficher le formulaire ne cree rien) : si aucun cookie Guest de CETTE Organization et
        // si le formulaire porte une attribution valide (code de Shortcut relu en base et/ou UTM autorises), l'identite
        // Guest nait ici — apres la garde de creation pre-identite (F1), attribution relue en base, jamais crue sur
        // parole. Sans attribution : aucune identite (une inscription directe reste anonyme). Un echec ne casse
        // jamais l'inscription. Ensuite `account_created`, le claim SW-11, `converted` et le CRM ont leur porteur.
        rescue(function () use ($request, $organization, $visitors, $attribution, $identities, $events): void {
            if ($visitors->find($request, $organization) !== null) {
                return;
            }
            $claimed = (array) $request->input('attribution', []);
            $resolved = $attribution->resolve($organization, $claimed);
            if ($resolved['shortcut'] === null && $resolved['utm_source'] === null && $resolved['utm_medium'] === null && $resolved['utm_campaign'] === null) {
                return;
            }
            if (! $identities->allowNewIdentity($organization)) {
                return;
            }
            $visitor = $visitors->ensure($request, $organization, ['locale' => app()->getLocale(), 'referrer' => $request->headers->get('referer')] + $resolved);
            $events->record($organization, AcquisitionEvent::GUEST_CREATED, $events->visitorDimensions($visitor), ['surface' => 'signup'], AcquisitionEvent::GUEST_CREATED.':visitor:'.$visitor->getKey());
            $events->record($organization, AcquisitionEvent::SIGNUP_STARTED, $events->visitorDimensions($visitor), [], AcquisitionEvent::SIGNUP_STARTED.':visitor:'.$visitor->getKey());
        });

        event(new Registered($user));

        // TASK-1453 (V3 §11, MASTER Q80) : le Guest ENGAGE la creation de compte depuis son interet Workshop —
        // une reference STRUCTUREE est parquee (jamais une URL) ; VerifyEmailController la consomme apres la verification.
        rescue(fn () => app(WorkshopResumption::class)->park($organization, app(GuestVisitorResolver::class)->find($request, $organization)));

        // TASK-1694 bug I — l'annonce des points NE PART PLUS ICI.
        //
        // Elle part apres la confirmation d'adresse
        // (`SendPointsGrantedEmailOnVerification`, sur l'evenement `Verified`).
        // La verification etant redevenue obligatoire au bug C, annoncer
        // 100 points a quelqu'un qui ne peut pas encore entrer n'avait plus de
        // sens — et deux emails partaient coup sur coup.
        //
        // Le CREDIT, lui, reste ici : `points_balance` et la ligne de
        // `PointLedger` plus haut. Seule l'ANNONCE se deplace.

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
