<?php

namespace App\Http\Controllers;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Country;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\NomDePersonneAcceptable;
use App\Services\UserSlugService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Rules\UniqueEmailInOrganization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Intervention\Image\Laravel\Facades\Image;

class ProfileController extends Controller
{
    /**
     * TASK-1693 bug Z — le profil se resout par SLUG ou par identifiant.
     *
     * Accepter les deux est ce qui permet au correctif de ne rien casser : les
     * 44 points du code qui construisent une URL de profil, les liens echanges
     * en message et les favoris continuent d'aboutir.
     *
     * Et quand on arrive par l'identifiant alors qu'un slug existe, on
     * REDIRIGE vers l'adresse lisible — meme idiome que la redirection
     * canonique de TASK-1601. C'est par la que l'URL devient jolie dans la
     * barre d'adresse, sans toucher a `getRouteKey()` : 30 routes hors profil
     * resolvent un `{user}` par liaison implicite sur l'identifiant, et
     * recevraient sinon un slug qu'elles ne sauraient pas resoudre.
     *
     * Un ANCIEN slug redirige lui aussi vers le courant : un slug n'est jamais
     * recycle, donc un lien partage il y a six mois aboutit toujours, et
     * toujours chez la bonne personne.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->route('user');

        if (! $user instanceof User) {
            [$user] = app(UserSlugService::class)
                ->resoudre((string) $user, currentOrganization()?->id);

            if ($user === null) {
                abort(404);
            }
        }

        // Le segment TEL QU'IL A ETE ECRIT dans l'URL, avant la liaison.
        //
        // Relire `route('user')` apres coup rendrait l'objet resolu, donc
        // toujours son identifiant : la redirection canonique ci-dessous se
        // declencherait a chaque passage, y compris depuis l'adresse lisible —
        // une boucle infinie.
        $segment = (string) $request->route()->originalParameter('user', '');

        $organization = currentOrganization();
        if (! $organization || $user->organization_id !== $organization->id) {
            abort(404);
        }

        if ($user->banned_at === null && $user->slug !== null && (string) $segment !== $user->slug) {
            return redirect()->route(
                $request->route()->getName(),
                array_merge(
                    $request->route()->parameters(),
                    ['user' => $user->slug],
                ),
                301,
            );
        }

        if ($user->banned_at !== null) {
            abort(404);
        }

        $memberAiProfile = $organization->ai_profiles_enabled
            ? MemberAiProfile::where('user_id', $user->id)
                ->where('status', MemberAiProfile::STATUS_PUBLISHED)
                ->first()
            : null;

        $services = $user->services()->where('status', 'active')->with('category', 'skills')->latest()->get();
        $openRequests = $user->serviceRequests()->where('status', 'open')->with('category')->latest()->get();
        $completedCount = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'completed')->count();
        $reviews = $user->reviewsReceived()
            ->whereHas('transaction', fn ($q) => $q->where('organization_id', $organization->id))
            ->with('reviewer')
            ->latest('created_at')
            ->get();
        $badges = $user->badges()->get();
        $blogPosts = BlogPost::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with('category')
            ->latest('published_at')
            ->limit(6)
            ->get();

        // TASK-1697 — les Boucles de cette personne.
        //
        // MEME perimetre et MEME piege qu'a l'annuaire (correctif 15) : filtre
        // sur `loops.organization_id`, JAMAIS sur `loop_members.organization_id`
        // qui est nullable — 3 lignes sur 24 y sont NULL en base, le filtre les
        // perdrait en silence. Statut `active` des deux cotes, donc jamais une
        // Boucle archivee, ni une appartenance quittee.
        //
        // Aucun filtre de role : `LoopRoleRegistry` en connait trois (owner,
        // facilitator, member) plus l'alias `moderator`. « Membre, animateur ou
        // proprietaire » les couvre tous.
        $boucles = LoopMember::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('loop', fn ($q) => $q->where('organization_id', $organization->id)->where('status', 'active'))
            ->with(['loop' => fn ($q) => $q->select('id', 'name', 'slug', 'organization_id', 'status')])
            ->get()
            ->pluck('loop')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        // TASK-1697 — ses derniers commentaires de blog.
        //
        // Ceux qu'il a ECRITS, pas ceux recus sur ses articles : la fiche
        // presente ce que cette personne apporte (ses propositions, ses
        // demandes, ses articles, ses Boucles), et ses commentaires s'y
        // rangent. Signale a Cyril, inversable en une ligne.
        //
        // L'article d'accueil doit etre PUBLIE et de cette Organization, sinon
        // un commentaire laisse sur un brouillon devenu invisible mènerait a un
        // lien mort. `whereHas` plutot qu'un filtre apres coup : la limite de 5
        // doit porter sur les lignes RETENUES.
        $commentaires = BlogComment::query()
            ->where('user_id', $user->id)
            ->where('is_approved', true)
            ->whereHas('post', fn ($q) => $q->where('organization_id', $organization->id)
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now()))
            ->with(['post' => fn ($q) => $q->select('id', 'title', 'slug', 'organization_id', 'status', 'published_at')])
            ->latest()
            ->limit(5)
            ->get();

        $ogTitle = $user->name;
        $ogDescription = $user->bio
            ? Str::limit($user->bio, 160)
            : "Profil de {$user->name} sur Entraide";
        $ogImage = $user->avatar_url;
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $user->name,
            'url' => route('profile.show', $user->profileRouteKey()),
            'description' => $user->bio,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return view('profile.show', compact('user', 'services', 'openRequests', 'completedCount', 'reviews', 'badges', 'blogPosts', 'boucles', 'commentaires', 'ogTitle', 'ogDescription', 'ogImage', 'jsonLd', 'memberAiProfile'));
    }

    public function aiAgentChat(User $user): View
    {
        $organization = currentOrganization();
        if (! $organization || $user->organization_id !== $organization->id) {
            abort(404);
        }

        if (! $user->isDisplayableIn($organization)) {
            abort(404);
        }

        $memberAiProfile = MemberAiProfile::where('user_id', $user->id)
            ->where('status', MemberAiProfile::STATUS_PUBLISHED)
            ->firstOrFail();

        if (! $organization->ai_profiles_enabled) {
            abort(404);
        }

        return view('profile.ai-agent-chat', compact('user', 'memberAiProfile'));
    }

    /**
     * TASK-1689 — l'Organization qui gouverne le PROFIL est celle de
     * l'utilisateur, jamais celle de l'URL.
     *
     * ## Le defaut mesure
     *
     * `edit()` et `update()` lisaient toutes deux
     * `currentOrganization() ?? $request->user()->organization` : l'URL
     * D'ABORD. Or cette Organization decide si `membership_value` est un champ
     * ACCEPTE (`update()`) et s'il est RENDU, avec quel libelle (`edit()`).
     *
     * `organization.profile.edit` et `organization.profile.update` ne portent
     * pas `EnsureOrganizationMember` — a la difference de
     * `organization.profile.show`. N'importe quel membre pouvait donc poser le
     * slug d'une autre Organization et heriter de SA configuration :
     *
     * | contexte | `membership_enabled` de l'org lue | resultat mesure |
     * |---|---|---|
     * | son organisation A | false | `membership_value` ignore |
     * | URL de l'organisation B | true | `membership_value` **ECRIT** |
     *
     * Un reglage que son Organization a deliberement DESACTIVE devenait
     * accessible en changeant un segment d'URL.
     *
     * ## Pourquoi AUCUN repli vers l'URL
     *
     * `users.organization_id` est NULLABLE (mesure sur le schema, 0 ligne
     * concernee aujourd'hui). Pour un utilisateur sans Organization, le contrat
     * EXISTANT est deja le bon : `$organization` vaut `null`, donc
     * `membership_value` n'entre pas dans les regles et le champ n'est pas
     * rendu. Ajouter `?? currentOrganization()` rouvrirait precisement le trou
     * pour ces comptes. On n'en ajoute pas.
     *
     * ## Ce que ce correctif NE touche pas
     *
     * Ni autorisation, ni middleware, ni `CurrentOrganization` : la resolution
     * globale du tenant est inchangee. Dans son propre tenant — le parcours
     * normal — les deux expressions rendent la MEME Organization, et la route
     * non prefixee reste gouvernee par la redirection canonique de TASK-1601.
     */
    private function profileOrganization(Request $request): ?Organization
    {
        return $request->user()?->organization;
    }

    public function edit(Request $request): View
    {
        $organization = $this->profileOrganization($request);
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

        return view('profile.edit', [
            'user' => $request->user(),
            'organization' => $organization,
            'countries' => $priorityCountries->concat($otherCountries),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->profileOrganization($request);

        $rules = [
            'first_name' => ['nullable', 'string', 'max:255', new NomDePersonneAcceptable],
            // TASK-1712 — un nom de personne, pas un support publicitaire.
            //
            // `ProfileUpdateRequest` existe mais AUCUN controleur ne l'utilise :
            // c'est ici que la validation du profil se joue reellement. Sans
            // cette regle, il suffirait de s'inscrire proprement puis de se
            // renommer.
            'name' => ['required', 'string', 'max:255', new NomDePersonneAcceptable],
            // TASK-1729 (A4) — unicite dans SON Organization, son propre compte
            // excepte. L'Organization n'est pas un choix ici : elle est celle du
            // compte, et elle est immuable depuis T1726.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255',
                new UniqueEmailInOrganization($request->user()->organization_id, $request->user()->id)],
            'phone' => ['required', 'string', 'max:30'],
            'show_email' => ['nullable', 'boolean'],
            'show_phone' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2', Rule::exists('countries', 'code')->where('active', true)],
            'preferred_locale' => ['nullable', Rule::in(['fr', 'en'])],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            // TASK-1693 bug Z — l'adresse publique. `nullable` : la laisser
            // vide revient a l'adresse technique, ce qui doit rester possible.
            'slug' => ['nullable', 'string', 'max:60'],
        ];

        if ($organization?->membership_enabled) {
            $rules['membership_value'] = ['nullable', 'string', 'max:255'];
        }

        $data = $request->validate($rules);

        // TASK-1693 bug Z — l'adresse publique passe par son autorite unique.
        //
        // Deux refus distincts, parce qu'ils appellent deux gestes differents :
        // une FORME invalide se corrige en reecrivant, une adresse DEJA PRISE
        // se corrige en en choisissant une autre. Un message unique laisserait
        // deviner lequel des deux.
        $slugDemande = isset($data['slug']) ? trim((string) $data['slug']) : null;
        unset($data['slug']);

        if ($slugDemande !== null && $slugDemande !== '' && $slugDemande !== $request->user()->slug) {
            $slugs = app(UserSlugService::class);

            if (! $slugs->formeValide($slugDemande)) {
                return back()->withInput()->withErrors(['slug' => __('profile.slug_invalid')]);
            }

            if (! $slugs->disponiblePour($request->user(), $slugDemande)) {
                return back()->withInput()->withErrors(['slug' => __('profile.slug_taken')]);
            }

            $slugs->attribuer($request->user(), $slugDemande);
        }

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = time().'_'.$file->getClientOriginalName();

            $img = Image::decode($file);
            $img->cover(300, 300);

            Storage::disk('public')->put('avatars/'.$filename, (string) $img->encode());
            $data['avatar'] = 'avatars/'.$filename;
        }

        // Handle booleans (checkboxes are not sent if unchecked)
        $data['show_email'] = $request->has('show_email');
        $data['show_phone'] = $request->has('show_phone');

        $request->user()->fill($data);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // TASK-1693 / bug A — destination DETERMINISTE, jamais `intended()`.
        //
        // `redirect()->intended()` consomme `url.intended`, et cette clef est
        // ecrite par `Redirect::guest()` — ce que fait `EnsureEmailIsVerified`
        // quand il detourne une page vers `verification.notice`. Un compte non
        // verifie qui visitait `/org/{slug}/dossiers` laissait donc cette URL
        // en session ; « Enregistrer » sur son profil l'y renvoyait, la garde
        // `verified` s'y declenchait a nouveau, et la personne atterrissait sur
        // `/verify-email` sans comprendre le rapport. Mesure : `PUT /profile`
        // ne porte QUE `auth`, pas `verified` — la redirection ne venait pas de
        // la route mais d'une intention etrangere.
        //
        // TASK-1693 / bug Y — on revient au PROFIL PUBLIC, pas au formulaire.
        //
        // Le bug A avait pose `profile.edit` : c'etait deja mieux que de suivre
        // une intention etrangere, mais cela rendait la personne au formulaire
        // qu'elle venait de quitter, sans lui montrer le RESULTAT de ce qu'elle
        // avait change. On modifie son profil public pour le voir change.
        //
        // La garantie du bug A ne bouge PAS : la destination reste DETERMINISTE
        // et n'emprunte jamais `redirect()->intended()`, dont la clef
        // `url.intended` est ecrite par `Redirect::guest()` — c'est ce qui
        // renvoyait vers `/verify-email` sans rapport avec le geste. Seule la
        // cible change.
        //
        // L'Organization est nommee EXPLICITEMENT quand on la connait :
        // `ProfileController::show()` exige `currentOrganization()` et rend 404
        // sinon. S'en remettre au seul repli canonique de TASK-1601 ferait
        // dependre l'aboutissement d'un second saut.
        $moi = $request->user();

        if ($organization !== null) {
            return redirect()
                ->route('organization.profile.show', [
                    'organization' => $organization->slug,
                    'user' => $moi->profileRouteKey(),
                ])
                ->with('status', 'profile-updated');
        }

        return redirect()->route('profile.show', ['user' => $moi->profileRouteKey()])
            ->with('status', 'profile-updated');
    }

    public function toggleAvailability(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->update(['is_available' => ! $user->is_available]);

        return back()->with('success', __('dashboard.availability_updated'));
    }

    /**
     * TASK-1716 — la fermeture volontaire du compte, qui remplace la
     * suppression physique immediate.
     *
     * ## Le defaut corrige, MESURE
     *
     * L'ancien `destroy()` appelait `$user->delete()` nu. Or chaque
     * inscription ecrit une ligne `point_ledger` (`reason = welcome_bonus`,
     * 100 points, `RegisteredUserController`), et depuis TASK-1635 cette
     * table porte un `ON DELETE RESTRICT`. La suppression levait donc une
     * `QueryException` — un 500 — pour TOUT compte inscrit, pas pour un cas
     * limite.
     *
     * Et l'etat laisse derriere etait a moitie applique : `Auth::logout()`
     * precedait le `delete()`, donc la personne etait deconnectee, voyait un
     * 500, et son compte restait intact. Elle ne pouvait ni le fermer ni
     * comprendre pourquoi.
     *
     * ## L'ordre des quatre gestes, et pourquoi il est celui-la
     *
     * L'ECRITURE D'ABORD, la deconnexion ensuite. C'est l'inverse de l'ancien
     * code, et c'est tout l'interet : si quoi que ce soit echoue, la session
     * est encore ouverte et la personne peut recommencer. Deconnecter avant
     * d'ecrire, c'est exactement ce qui produisait l'etat batard ci-dessus.
     *
     * La revocation des jetons n'est PAS ecrite ici : `User::booted()`
     * supprime `tokens()` des que `banned_at` passe non-nul. La poser une
     * seconde fois donnerait deux verites a maintenir.
     *
     * ## `forceFill` et non `update`
     *
     * Les trois colonnes sont HORS `$fillable` a dessein (voir `User::casts`).
     * Un drapeau de cycle de vie ne doit pas pouvoir etre pose par
     * l'assignation de masse d'un formulaire de profil.
     *
     * ## Ce que cette methode ne fait PAS
     *
     * Elle n'efface rien : ni points, ni historiques, ni transactions, ni
     * Dossiers. La suppression REELLE reste au SuperAdmin, sur l'ecran de
     * TASK-1636 ou les refus metier sont deja calcules. La case a cocher ne
     * declenche donc aucune purge — elle depose une DEMANDE.
     */
    public function close(Request $request): RedirectResponse
    {
        // Le sac `userDeletion` est conserve : la modal de fermeture est la
        // meme, et changer le nom du sac ferait taire l'erreur de mot de passe
        // sans que rien ne le signale.
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
            // `sometimes` et NON `nullable` : `nullable` n'exempte pas
            // `accepted`, donc une case DECOCHEE — le cas normal — faisait
            // echouer la validation et ne fermait rien. Mesure par la garde
            // `test_la_fermeture_pose_les_deux_horodatages_...`.
            //
            // Et une regle plutot que le seul `boolean()` ci-dessous : une
            // valeur inattendue (`erasure=peut-etre`) serait lue comme `false`
            // et la demande d'effacement disparaitrait en silence. Mieux vaut
            // refuser que perdre une demande.
            'erasure' => ['sometimes', 'accepted'],
        ]);

        $user = $request->user();

        // L'Organization est lue AVANT la deconnexion, comme le fait
        // `AuthenticatedSessionController::destroy()` : apres `Auth::logout()`
        // et `session()->invalidate()`, le contexte de tenant n'est plus
        // resolvable, et la redirection retomberait sur la page de connexion
        // nue. Quelqu'un qui ferme son compte depuis `/org/ofsh/profile/edit`
        // doit revenir sur la page de SON Organization, pas sur une page
        // generique qu'il n'a jamais vue.
        $organisation = currentOrganization();

        $maintenant = now();

        $user->forceFill([
            // `banned_at` porte la desactivation EFFECTIVE : les deux gardes
            // de groupe, le refus de connexion et les trois scopes de requete
            // le lisent deja. `self_closed_at` dit seulement QUI a decide.
            'banned_at' => $maintenant,
            'self_closed_at' => $maintenant,
            'erasure_requested_at' => $request->boolean('erasure') ? $maintenant : null,
        ])->save();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Meme idiome que la deconnexion : la page de connexion de
        // l'Organization quand il y en a une. `status` est rendu par
        // `<x-auth-session-status>` sur les deux formes de la page.
        //
        // La branche `null` est du code DEFENSIF inatteignable tant qu'une
        // Organization par defaut existe : `currentOrganization()` y retombe,
        // meme pour un compte dont `organization_id` est nul (mesure faite en
        // voulant ecrire la garde correspondante). Elle reste parce qu'une
        // installation neuve, elle, n'en a pas.
        $destination = $organisation !== null
            ? Redirect::route('organization.login', ['organization' => $organisation->slug])
            : Redirect::route('login');

        return $destination->with('status', __('profile.close_account_done'));
    }
}
