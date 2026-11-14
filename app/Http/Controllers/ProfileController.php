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
use App\Support\Entraide\BilanEntraide;
use App\Rules\NomDePersonneAcceptable;
use App\Services\UserSlugService;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use App\Support\ProfileLinks;
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
use App\Support\Tenancy\UserWriteLock;
use Illuminate\Support\Facades\DB;

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
            // TASK-1808 §2.E — un avis MASQUE par le cockpit ne doit
            // jamais apparaitre sur le profil public. La suppression, elle,
            // est deja exclue sans rien ecrire ici (SoftDeletes).
            ->whereNull('hidden_at')
            ->whereHas('transaction', fn ($q) => $q->where('organization_id', $organization->id))
            ->with('reviewer')
            ->latest('created_at')
            ->get();
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

        // TASK-1755 §9.6 — le profil comme BILAN D'ENTRAIDE, pas comme solde.
        // Quatre nombres, UNE requete agregee : cette page est publique et lue
        // bien plus souvent qu'ecrite.
        // L'Organization est passee EXPLICITEMENT : la frontiere de locataire
        // d'un avis est portee par sa TRANSACTION, pas par l'avis lui-meme.
        $bilan = BilanEntraide::pour($user, $organization);
        $mercis = BilanEntraide::mercis($user, $organization);

        return view('profile.show', compact('user', 'services', 'openRequests', 'completedCount', 'reviews', 'blogPosts', 'boucles', 'commentaires', 'ogTitle', 'ogDescription', 'ogImage', 'jsonLd', 'memberAiProfile', 'bilan', 'mercis'));
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

    /**
     * TASK-1824 — « Securite du compte » : mot de passe, acces/comptes lies,
     * fermeture. Memes deux formes que `edit()` (nue et prefixee par
     * l'Organization, voir `profileOrganization()`), et aucune donnee
     * supplementaire : les trois partials qu'elle rend lisent deja `$user`
     * via les routes globales (`password.update`, `account.links`,
     * `account.close`) qu'elles soumettent — rien ici n'est scope par cette
     * Organization.
     */
    public function security(Request $request): View
    {
        return view('profile.security', [
            'user' => $request->user(),
            'organization' => $this->profileOrganization($request),
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
            // TASK-1788 correctif 02 — l'INTENTION de supprimer sa photo.
            // Le formulaire la pose toujours (0 ou 1) quand une photo existe ;
            // elle est consommee plus bas, puis retiree de `$data` avant le
            // `fill()` — `users` n'a pas de colonne de ce nom.
            'supprimer_avatar' => ['nullable', 'boolean'],
            // TASK-1762 point 17 — le slogan, OBLIGATOIRE et sans condition
            // d'Organization (contrairement a `structure`, `address_line1` ou
            // `preferred_locale` plus bas) : « c'est important que les gens
            // puissent se definir en une phrase ». 120 caracteres : la limite
            // qui fait tenir la phrase sur une carte d'annuaire a 3 colonnes.
            'slogan' => ['required', 'string', 'max:120'],
            // TASK-1762 point 16 — 2000 et non 500 : la presentation est
            // desormais du Markdown, et le BALISAGE est compte dans la limite.
            // « **Auditeur Qualiopi** » coute 24 caracteres pour 18 lisibles,
            // un lien en coute 31 pour 8.
            // TASK-1788 correctif 12 (demande Cyril) — 2000 releve a 3500 :
            // meme raisonnement, la video et les liens inseres depuis la
            // barre d'outils alourdissent encore le balisage compte ici.
            'bio' => ['nullable', 'string', 'max:3500'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2', Rule::exists('countries', 'code')->where('active', true)],
            'website' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            // TASK-1750 — la liste de liens supplementaires.
            //
            // `url:http,https` et pas `url` seul : ces adresses sortent dans
            // un `href` rendu a des visiteurs, et `url` accepte `javascript:`
            // comme `data:`. La liste BLANCHE est la seule forme qui tienne —
            // interdire au cas par cas laisserait passer le schema suivant.
            //
            // Les lignes VIDES ne sont pas refusees : le formulaire renvoie
            // celles que la personne a ajoutees puis abandonnees, et les
            // refuser transformerait un geste anodin en message d'erreur.
            // Elles sont ecartees plus bas, a l'assainissement.
            'profile_links' => ['nullable', 'array', 'max:'.ProfileLinks::MAXIMUM],
            'profile_links.*.type' => ['nullable', Rule::in(ProfileLinks::clefs())],
            'profile_links.*.url' => ['nullable', 'url:'.implode(',', ProfileLinks::SCHEMAS), 'max:255'],
            // TASK-1693 bug Z — l'adresse publique. `nullable` : la laisser
            // vide revient a l'adresse technique, ce qui doit rester possible.
            'slug' => ['nullable', 'string', 'max:60'],
        ];

        if ($organization?->membership_enabled) {
            $rules['membership_value'] = ['nullable', 'string', 'max:255'];
        }

        // TASK-1762 point 2 — la structure n'existe que si l'Organization l'a
        // voulu.
        //
        // **C'est ici que la garde tient, pas dans la vue.** Masquer le champ
        // ne garde rien : un `PUT /profile` forge porterait `structure` et il
        // serait enregistre. Une regle absente du tableau fait que la cle
        // n'entre jamais dans `$data`, donc jamais dans le `fill()`.
        //
        // Une chaine simple, et **pas** `NomDePersonneAcceptable` : cette regle
        // (T1712) refuse ce qui ne ressemble pas a un nom de personne, et elle
        // ecarterait « Association Les Amis du Quartier » ou « SCOP Oxalis ».
        // Le champ decrit justement autre chose qu'une personne.
        // L'Organization est passee EXPLICITEMENT : une garde d'ecriture se lie
        // a l'Organization de la personne ecrite, jamais a ce que l'URL raconte.
        if (structureIsShown($organization)) {
            $rules['structure'] = ['nullable', 'string', 'max:255'];
        }

        // TASK-1762 point 15 — l'adresse de facturation n'est recueillie que si
        // l'Organization propose des abonnements.
        //
        // Au SERVEUR, comme la structure : masquer la section ne garde rien,
        // un `PUT` forge ecrirait les trois champs. Une regle absente du
        // tableau fait que la cle n'entre jamais dans `$data`.
        //
        // Elle n'EFFACE pas pour autant ce qui etait deja saisi : la cle est
        // simplement absente du `fill()`. Une Organization qui coupe les
        // abonnements cache les champs, elle ne detruit pas les adresses.
        // TASK-1762 point 16 — la langue principale n'existe que sur un site
        // multilingue. Masquer la section ne garde rien : un `PUT` forge
        // changerait la langue d'un membre sur un site qui n'en a qu'une.
        if ($organization?->multilingual_enabled) {
            $rules['preferred_locale'] = ['nullable', Rule::in(['fr', 'en'])];
        }

        if (billingIsCollected($organization)) {
            $rules['address_line1'] = ['nullable', 'string', 'max:255'];
            $rules['address_line2'] = ['nullable', 'string', 'max:255'];
            $rules['postal_code'] = ['nullable', 'string', 'max:30'];
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

        // TASK-1788 correctif 02 — deposer une photo et demander sa suppression
        // sont deux gestes qui se contredisent. Le FICHIER gagne : on est venu
        // avec une photo en main. L'ecran les rend d'ailleurs exclusifs, mais
        // un formulaire renvoye ne se croit pas sur parole.
        if ($request->hasFile('avatar')) {
            $data['avatar'] = $this->stockerAvatar($request->file('avatar'));
        } elseif ($request->boolean('supprimer_avatar')) {
            // `null` et non une chaine vide : `getAvatarUrlAttribute()` teste
            // `if ($this->avatar)`, et rend les initiales generees des que la
            // colonne est vide. Supprimer sa photo, c'est revenir a ce defaut.
            //
            // Le FICHIER sur le disque n'est pas efface, et c'est deliberement
            // le meme comportement que le REMPLACEMENT, qui laisse lui aussi
            // l'ancien derriere lui depuis toujours. Ramasser les orphelins est
            // un autre sujet, qui se decide — pas un effet de bord qu'on glisse
            // dans un correctif d'interface.
            $data['avatar'] = null;
        }

        // Jamais jusqu'au `fill()` : `supprimer_avatar` est une intention, pas
        // une colonne. Eloquent n'est pas en mode strict ici, il l'ignorerait
        // donc EN SILENCE — et le jour ou quelqu'un active
        // `Model::shouldBeStrict()`, l'enregistrement du profil casserait sans
        // que rien n'explique pourquoi.
        unset($data['supprimer_avatar']);

        // TASK-1750 — on n'enregistre que les lignes COMPLETES, et seulement
        // si le formulaire a reellement porte le champ.
        //
        // Le test sur `has()` compte : le formulaire de profil n'est pas le
        // seul a passer ici, et un `PUT` qui n'envoie pas `profile_links`
        // demande de ne RIEN changer — assigner un tableau vide effacerait
        // silencieusement la liste de la personne.
        if ($request->has('profile_links')) {
            $data['profile_links'] = ProfileLinks::assainir($data['profile_links'] ?? null);
        } else {
            unset($data['profile_links']);
        }

        // Handle booleans (checkboxes are not sent if unchecked)
        $data['show_email'] = $request->has('show_email');
        $data['show_phone'] = $request->has('show_phone');

        // TASK-1729 phase 2 — l'enregistrement du profil passe sous verrou.
        //
        // Changer son adresse est une ecriture sur `users` comme une autre, et
        // la regle d'unicite ci-dessus tourne hors transaction. La fenetre y
        // etait meme plus large qu'ailleurs : entre la validation et ce
        // `save()`, le controleur redimensionne et ecrit un avatar sur le
        // disque. Une inscription concurrente, ou la suppression d'une
        // Organization qui detache ses membres, pouvait donc prendre l'adresse
        // pendant ce temps-la.
        //
        // Le recontrole porte la MEME portee que la regle : son Organization,
        // son propre compte excepte. L'Organization est lue sur le compte et
        // non sur l'URL — elle est immuable depuis TASK-1726.
        $moiAEnregistrer = $request->user();

        // TASK-1752 — « presentation terminee » est une TRANSITION.
        //
        // La definition du produit est unique et tient en une ligne :
        // `MemberOnboardingSteps::doneFor()` dit `filled($user->bio)`. Il n'y
        // a pas de colonne `presentation_completed_at`, pas d'etat persiste —
        // et le docblock de cette classe explique pourquoi : elle existe pour
        // qu'il n'y ait PAS de seconde verite d'onboarding. En inventer une
        // ici la contredirait.
        //
        // Il faut donc observer le PASSAGE de vide a rempli, et le capturer
        // AVANT le `save()` : apres, `getOriginal()` a deja bascule et la
        // transition est invisible.
        //
        // Un `PUT /profile` ordinaire — changer son telephone — ne doit rien
        // emettre. Et vider puis remplir a nouveau sa bio ne doit pas
        // reannoncer : c'est la contrainte d'unicite qui l'achete, pas ce test.
        $presentationVientDEtreEcrite = blank($moiAEnregistrer->getOriginal('bio'))
            && filled($data['bio'] ?? null);

        DB::transaction(function () use ($moiAEnregistrer, $data) {
            UserWriteLock::acquire();

            UniqueEmailInOrganization::refuserSiPrise(
                'email', $data['email'], $moiAEnregistrer->organization_id, $moiAEnregistrer->id
            );

            $moiAEnregistrer->fill($data);

            if ($moiAEnregistrer->isDirty('email')) {
                $moiAEnregistrer->email_verified_at = null;
            }

            $moiAEnregistrer->save();
        });

        if ($presentationVientDEtreEcrite) {
            rescue(fn () => app(FeedEventEmitter::class)->emettre(
                FeedEventCatalogue::PRESENTATION_TERMINEE,
                $moiAEnregistrer,
                $moiAEnregistrer,
                (string) $moiAEnregistrer->organization_id,
            ), report: true);
        }

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

    /**
     * TASK-1749 (demande Cyril, 04/11) — changer sa photo SANS traverser le
     * formulaire de profil.
     *
     * ## Pourquoi une route a elle seule
     *
     * Le geste existait deja, mais il coutait quatre etapes : crayon ->
     * `/profile/edit` -> chercher le champ au milieu d'une vingtaine d'autres
     * -> « Enregistrer ». Le champ se trouve entre « Profil LinkedIn » et la
     * case de disponibilite, et rien ne le signale.
     *
     * Reemployer `profile.update` n'etait PAS possible, et c'est la raison
     * concrete de cette methode : ses regles declarent `name`, `email` et
     * `phone` en `required`. Un envoi qui ne porte qu'une photo y serait
     * refuse. Il aurait fallu soit assouplir ces trois regles — on y perdrait
     * la garde de TASK-1712 sur le nom et celle de TASK-1729 sur l'unicite de
     * l'adresse —, soit faire porter au formulaire modal une copie cachee de
     * tout le profil, qui ecraserait silencieusement les champs modifies
     * ailleurs entre-temps. Les deux sont pires que la route dediee.
     *
     * ## Ce qu'elle ne touche pas
     *
     * Une seule colonne, `avatar`. Ni le nom, ni l'adresse, ni le telephone,
     * ni le slug : aucun verrou `UserWriteLock` n'est donc requis ici, a la
     * difference de `update()`, dont la transaction protege l'unicite de
     * l'adresse. Rien a serialiser quand on n'ecrit qu'un chemin de fichier.
     *
     * `required` et non `nullable` : on arrive ici en ayant choisi une photo.
     * Un envoi vide est une erreur a signaler, pas un no-op silencieux.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        // TASK-1788 correctif 02 — cette fenetre SUPPRIME aussi, desormais.
        //
        // `required` devient conditionnel : on arrive ici soit avec une photo
        // en main, soit pour enlever celle qui est posee. Exiger un fichier
        // dans le second cas refuserait le seul geste qui restait a inventer.
        //
        // Le choix est lu sur la REQUETE et non sur une regle croisee du genre
        // `required_without` : le formulaire envoie toujours `supprimer_avatar`
        // (0 ou 1) quand une photo existe, donc « le champ est absent » ne dit
        // rien — c'est sa VALEUR qui decide.
        $request->validate([
            'supprimer_avatar' => ['nullable', 'boolean'],
            'avatar' => [
                $request->boolean('supprimer_avatar') ? 'nullable' : 'required',
                'image', 'max:2048',
            ],
        ]);

        $moi = $request->user();

        // Meme arbitrage que dans `update()` : deposer une photo et demander sa
        // suppression se contredisent, et le FICHIER l'emporte.
        if ($request->boolean('supprimer_avatar') && ! $request->hasFile('avatar')) {
            // Le fichier sur le disque n'est pas efface — comportement
            // identique au REMPLACEMENT, qui laisse lui aussi l'ancien derriere
            // lui depuis toujours.
            $moi->update(['avatar' => null]);

            return back()->with('success', __('profile.avatar_removed'));
        }

        $moi->update(['avatar' => $this->stockerAvatar($request->file('avatar'))]);

        return back()->with('success', __('profile.avatar_updated'));
    }

    /**
     * TASK-1749 — le redimensionnement et l'ecriture sur disque, en UN
     * exemplaire.
     *
     * Ce bloc vivait en ligne dans `update()`. Il est desormais appele par
     * deux chemins : le formulaire complet et la fenetre « changer ma photo ».
     * Le recopier aurait garanti qu'ils divergent — au premier changement de
     * dimension, de disque ou de format.
     *
     * Le comportement est repris A L'IDENTIQUE, y compris le nom de fichier
     * prefixe par `time()` : le modifier aurait change l'adresse des avatars
     * sans que personne ne l'ait demande.
     */
    private function stockerAvatar(\Illuminate\Http\UploadedFile $file): string
    {
        $filename = time().'_'.$file->getClientOriginalName();

        $img = Image::decode($file);
        $img->cover(300, 300);

        Storage::disk('public')->put('avatars/'.$filename, (string) $img->encode());

        return 'avatars/'.$filename;
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
