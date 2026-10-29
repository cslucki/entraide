<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Report;
use App\Models\RequestAttachment;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\Tag;
use App\Models\Transaction;
use App\Notifications\WelcomeNotification;
use App\Models\User;
use App\Services\Admin\PlatformDashboardMetrics;
use App\Services\EmailerService;
use App\Services\UserDataLifecycleRegistry;
use App\Services\Users\Exceptions\UserDeletionBlockedException;
use App\Services\Users\UserDeletionExecutor;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use App\Support\Devices\DeviceKind;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    /**
     * TASK-1507 — le tableau de bord SuperAdmin, toutes Organizations
     * confondues. Les indicateurs demandes par Cyril (depense IA, Shell
     * Welcome, connexions jour/semaine/mois, comptes qui se connectent le
     * plus, interactions IA) viennent d'un service unique, miroir plateforme
     * de `OrganizationDashboardMetrics` (TASK-1504).
     */
    public function dashboard(PlatformDashboardMetrics $metrics): View
    {
        $recentUsers = User::with('organization')->latest()->limit(5)->get();
        $pendingReports = Report::with('reporter')->where('status', 'pending')->latest('created_at')->limit(10)->get();

        return view('admin.dashboard', [
            'metrics' => $metrics->get(),
            'recentUsers' => $recentUsers,
            'pendingReports' => $pendingReports,
        ]);
    }

    // ── Users ────────────────────────────────────────────────────────────────

    public function users(Request $request): View
    {
        $query = User::with(['organization'])->withCount(['services', 'buyerTransactions', 'sellerTransactions', 'reviewsReceived']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search.'%')
                    ->orWhere('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'banned' => $query->whereNotNull('banned_at'),
                'admin' => $query->where('is_admin', true),
                'available' => $query->where('is_available', true)->whereNull('banned_at'),
                // TASK-1693 bug B — sans ce filtre, un compte bloque par la
                // garde de verification etait introuvable : l'ecran n'exposait
                // ni l'etat ni le moyen de le chercher.
                'unverified' => $query->whereNull('email_verified_at'),
                default => null,
            };
        }

        // TASK-1683 BUG-06 — garde de FORME avant comparaison : `organization_id`
        // est une colonne `uuid`, et PostgreSQL leve `22P02` (500) sur une
        // chaine forgee, la ou l'ecran doit simplement ne rien filtrer. SQLite
        // compare comme du texte : sans cette garde, le defaut n'apparaitrait
        // qu'en production (meme famille que AdminLoopController::index).
        if ($request->filled('organization_id') && Str::isUuid($request->organization_id)) {
            $query->where('organization_id', $request->organization_id);
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'name' => $query->orderBy('first_name', $direction)->orderBy('name', $direction),
            'email' => $query->orderBy('email', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            'points_balance' => $query->orderBy('points_balance', $direction),
            'services_count' => $query->orderBy('services_count', $direction),
            'exchange_count' => $query->orderByRaw(
                '(SELECT COUNT(*) FROM transactions WHERE buyer_id = users.id OR seller_id = users.id) '.$direction
            ),
            'rating' => $query->orderBy('rating', $direction),
            'status' => $query->orderByRaw('banned_at IS NULL '.($direction === 'asc' ? 'ASC' : 'DESC').', banned_at '.$direction),
            'organization_id' => $query->orderBy(
                Organization::select('name')->whereColumn('id', 'users.organization_id')->limit(1),
                $direction
            ),
            default => $query->latest(),
        };

        $users = $query->paginate(20)->withQueryString();
        $organizations = Organization::where('is_active', true)->orderBy('name')->get();

        // TASK-1683 BUG-06 — l'Organization filtree, pour nommer l'ecran et
        // borner les compteurs. Lue APRES la garde de forme : `organizations.id`
        // est une colonne `uuid`, et PostgreSQL leve `22P02` (500) sur une
        // chaine forgee la ou il faut un simple ecran vide. SQLite, lui,
        // compare comme du texte : le defaut ne se verrait qu'en production.
        $scopedOrganization = $request->filled('organization_id') && Str::isUuid($request->organization_id)
            ? Organization::find($request->organization_id)
            : null;

        return view('admin.users', compact('users', 'organizations', 'scopedOrganization') + [
            'stats' => $this->userListStats($scopedOrganization?->id),
            // TASK-1694 bug L — les gabarits de l'Emailer, pour le choix pose
            // DANS la ligne. Une seule requete, constante : le test de cout de
            // cet ecran mesure qu'il ne grandit pas avec le nombre de comptes.
            'emailTemplates' => EmailTemplate::orderBy('name')->get(['id', 'name', 'subject', 'locale']),
        ]);
    }

    /**
     * TASK-1640 — les compteurs du bandeau de la liste, en UNE requete.
     *
     * Des agregats conditionnels plutot que cinq `count()` : la page est un
     * cockpit, pas un rapport, et le test de cout de `/admin/users` mesure
     * justement que cette page ne grandit pas en requetes quand le nombre de
     * comptes augmente.
     *
     * Les compteurs portent sur la POPULATION ENTIERE, pas sur le filtre courant.
     * Un compteur qui bougerait avec les filtres ne dirait plus « combien de
     * comptes bannis existe-t-il » mais « combien en vois-je », ce qui n'est pas
     * la question posee par un cockpit. Le total filtre, lui, est rendu par le
     * paginateur a cote du tableau.
     *
     * TASK-1683 BUG-06 — UNE exception, et une seule : l'Organization. Elle
     * n'est pas un filtre d'affichage mais le TENANT lui-meme ; a l'interieur
     * d'OFSH, « combien de comptes bannis existe-t-il » veut dire « chez
     * OFSH ». Recherche et statut, eux, continuent de ne RIEN changer aux
     * compteurs — la doctrine ci-dessus tient pour tout le reste.
     *
     * @return array<string, int>
     */
    private function userListStats(?string $organizationId = null): array
    {
        // Deux pieges de moteur evites ici, tous deux deja payes sur ce depot :
        //  - `count(*) FILTER (...)` est du PostgreSQL pur ;
        //  - `is_admin = 1` passe en SQLite (entier) et ECHOUE en PostgreSQL, ou
        //    la colonne est un vrai `boolean`.
        // Le predicat NU (`case when is_admin then ...`) est valide dans les deux.
        $row = User::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->toBase()->selectRaw(
            'count(*) as total,'
            .' sum(case when banned_at is null and is_available then 1 else 0 end) as disponibles,'
            .' sum(case when banned_at is not null then 1 else 0 end) as bannis,'
            .' sum(case when is_admin then 1 else 0 end) as admins,'
            .' sum(case when created_at >= ? then 1 else 0 end) as nouveaux,'
            // TASK-1693 bug C — dans la MEME requete agregee. Une seconde
            // requete pour ce seul compteur ferait grandir le cout de l'ecran
            // avec le nombre de compteurs, ce que la garde de cout de
            // `/admin/users` interdit precisement.
            .' sum(case when email_verified_at is null then 1 else 0 end) as non_verifies',
            [now()->startOfMonth()]
        )->first();

        return [
            'total' => (int) $row->total,
            'disponibles' => (int) $row->disponibles,
            'bannis' => (int) $row->bannis,
            'admins' => (int) $row->admins,
            'nouveaux' => (int) $row->nouveaux,
            'non_verifies' => (int) $row->non_verifies,
        ];
    }

    public function editUser(User $user): View
    {
        // TASK-1726 — plus de liste d'Organizations a proposer : la fiche
        // AFFICHE desormais le tenant du compte, elle ne le choisit plus. La
        // requete partait avec le selecteur ; la laisser aurait charge toutes
        // les Organizations actives a chaque ouverture de fiche pour une
        // variable que plus aucune vue ne lit.
        $userOrg = $user->organization;
        $localeColumn = app()->getLocale() === 'en' ? 'name_en' : 'name_fr';

        if ($userOrg) {
            $priorityCodes = $userOrg->priorityCountries()->where('active', true)->pluck('code');
            $priorityCountries = Country::whereIn('code', $priorityCodes)->where('active', true)->get();
            $otherCountries = Country::where('active', true)
                ->whereNotIn('code', $priorityCodes)
                ->orderBy($localeColumn)
                ->get();
            $countries = $priorityCountries->concat($otherCountries);
        } else {
            $countries = Country::where('active', true)->orderBy($localeColumn)->get();
        }

        return view('admin.users.edit', compact('user', 'countries'));
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        // TASK-1726 — cet ecran ne touche PLUS a l'Organization du compte.
        //
        // Le defaut corrige ici etait le plus discret du lot : la ligne
        // supprimee ci-dessus lisait `organization_id` dans la requete et,
        // quand le champ etait ABSENT, repliait sur
        // `DefaultOrganizationResolver`. Un PUT qui ne parlait pas
        // d'Organization — un appel d'API, un formulaire partiel, un test —
        // deplacait donc le compte vers l'Organization par defaut de la
        // plateforme sans que personne ne l'ait demande. Le formulaire complet
        // masquait le probleme en repostant toujours la valeur courante.
        //
        // Desormais l'Organization du compte est une donnee qu'on LIT ici,
        // jamais qu'on ecrit : elle sert encore a savoir si le tenant utilise
        // les adhesions, et c'est tout. La garde sandbox T1650 a disparu avec
        // le besoin — il n'y a plus de destination a refuser, puisqu'il n'y a
        // plus de deplacement.
        $organization = $user->organization;

        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            // TASK-1693 bug Z — l'adresse publique du profil.
            'slug' => 'nullable|string|max:60',
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:30',
            'bio' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'country_code' => ['nullable', 'string', 'size:2', Rule::exists('countries', 'code')->where('active', true)],
            'preferred_locale' => ['nullable', 'string', Rule::in(['fr', 'en'])],
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:30',
            'website' => 'nullable|url|max:255',
            'linkedin_url' => 'nullable|url|max:255',
            'avatar' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'is_available' => 'boolean',
            'is_admin' => 'boolean',
            'banned' => 'boolean',
        ]);

        // TASK-1693 bug Z — l'adresse publique passe par son autorite unique,
        // la meme que l'ecran de profil. Deux refus distincts : une FORME
        // invalide se corrige en reecrivant, une adresse DEJA PRISE en en
        // choisissant une autre.
        $slugDemande = isset($data['slug']) ? trim((string) $data['slug']) : null;
        unset($data['slug']);

        if ($slugDemande !== null && $slugDemande !== '' && $slugDemande !== $user->slug) {
            $slugs = app(\App\Services\UserSlugService::class);

            if (! $slugs->formeValide($slugDemande)) {
                return back()->withInput()->withErrors(['slug' => __('profile.slug_invalid')]);
            }

            if (! $slugs->disponiblePour($user, $slugDemande)) {
                return back()->withInput()->withErrors(['slug' => __('profile.slug_taken')]);
            }

            $slugs->attribuer($user, $slugDemande);
        }

        $update = [
            'first_name' => $data['first_name'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'bio' => $data['bio'] ?? null,
            'city' => $data['city'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'preferred_locale' => $data['preferred_locale'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'website' => $data['website'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'is_available' => $request->boolean('is_available'),
        ];

        // TASK-1726 — `?->` et non `->` : un compte orphelin (Organization
        // supprimee) n'a plus de tenant a interroger. L'ancien code ne pouvait
        // pas rencontrer ce cas, parce qu'il venait d'en FABRIQUER une par
        // repli — et c'est precisement ce repli qu'on retire.
        if ($organization?->membership_enabled) {
            $request->validate(['membership_value' => 'nullable|string|max:255']);
            $update['membership_value'] = $request->input('membership_value');
        }

        if ($request->hasFile('avatar')) {
            $update['avatar'] = $this->remplacerAvatar($user, $request->file('avatar'));
        }

        if ($user->id !== auth()->id()) {
            $update['is_admin'] = $request->boolean('is_admin');

            // TASK-1716 — le TROISIEME chemin de reactivation, et le plus
            // discret des trois : decocher « banni » sur ce formulaire ecrivait
            // `banned_at = null`. Un administrateur venu changer une ville ou
            // un libelle pouvait donc rouvrir un compte ferme par son
            // proprietaire sans jamais voir le mot « debannir ».
            //
            // Sur un compte ferme volontairement, la case est IGNOREE — pas
            // refusee : le reste du formulaire (nom, bio, Organization) doit
            // continuer a s'enregistrer. La vue remplace la case par un renvoi
            // vers « Reouvrir le compte », seule porte qui assume la decision.
            if (! $user->estFermeVolontairement()) {
                $update['banned_at'] = $request->boolean('banned') ? ($user->banned_at ?? now()) : null;
            }
        }

        $user->update($update);

        return back()->with('success', "Profil de {$user->name} mis à jour.");
    }

    /**
     * TASK-1705 — remplacer la photo d'un compte, une seule fois.
     *
     * Extrait de `updateUser()` a l'identique, parce que le bouton « Upload
     * photos » de la liste doit faire EXACTEMENT ce que fait la page Modifier.
     * La subtilite ci-dessous est la raison d'etre de cette extraction : la
     * recopier aurait donne deux regles pour une idee, et c'est la copie qu'on
     * oublie de corriger.
     *
     * TASK-1654 — l'ancien fichier ne se supprime que s'il appartient a CE
     * compte. Un avatar de banque Scenario est publie a un chemin PARTAGE,
     * sans discriminant d'Organization : le supprimer ici privait de son image
     * tout persona, dans toute sandbox, qui declare la meme cle. La banque est
     * seule a repondre de cette question ; ce controleur ne la redevine pas.
     *
     * Et l'inverse reste vrai : un upload individuel remplace DOIT toujours
     * etre supprime, sans quoi on echangerait une casse globale contre une
     * fuite de stockage.
     */
    private function remplacerAvatar(User $user, UploadedFile $fichier): string
    {
        if ($user->avatar && ! ManifestAvatarBank::isPublishedAsset($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        return $fichier->store('avatars', 'public');
    }

    /**
     * TASK-1705 — poser la photo d'un compte depuis la LISTE.
     *
     * Cyril : « sur /admin/users, je voudrais que tu ajoutes un bouton "Upload
     * photos" dans la colonne action, qui permette d'indiquer la photo de
     * l'utilisateur, comme si on allait sur [la page Modifier]. Ceci car
     * certaines personnes ne prennent pas le temps d'uploader leur photo et je
     * vais recuperer leur photo pour eux et la telecharger. »
     *
     * ## Pourquoi un point d'entree a part, et non `updateUser()`
     *
     * `updateUser()` valide un formulaire COMPLET : `name` et `email` y sont
     * requis, et il reecrit une trentaine de champs. Lui envoyer un seul
     * fichier echouerait sur la validation ; lui envoyer la ligne entiere
     * depuis la liste reviendrait a reposter des valeurs que personne n'a
     * voulu modifier — et a ecraser, au passage, toute modification faite
     * ailleurs entre l'affichage de la liste et le clic.
     *
     * Celui-ci ne touche QUE la photo. C'est aussi ce qui le rend sur : il n'y
     * a pas de champ a oublier de valider.
     *
     * Les memes regles que la page Modifier s'appliquent au fichier (type et
     * taille), et le remplacement passe par le MEME code.
     */
    public function updateUserAvatar(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,webp|max:2048',
        ], [
            'avatar.required' => __('admin.users_avatar_required'),
        ]);

        $user->update(['avatar' => $this->remplacerAvatar($user, $request->file('avatar'))]);

        return back()->with('success', __('admin.users_avatar_updated', ['name' => $user->full_name]));
    }

    public function banUser(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas vous bannir vous-même.');
        }
        $user->update(['banned_at' => now()]);

        return back()->with('success', 'Utilisateur banni.');
    }

    /**
     * TASK-1716 — « Debannir » ne leve PLUS une fermeture volontaire.
     *
     * L'audit des chemins de reactivation (demande MASTER) en a trouve DEUX,
     * pas un : celui-ci et `OrgAdminController::toggleUserBan()`. Tous deux
     * ecrivaient `banned_at = null` a l'aveugle. Un administrateur qui voit
     * « Banni » sur une ligne et clique « Debannir » rouvrait donc, sans le
     * savoir, un compte que son proprietaire avait ferme — et lui rendait un
     * acces qu'il n'avait pas demande.
     *
     * Le refus est explicite, pas un silence : le message renvoie vers
     * l'action qui, elle, assume la decision (`reopenUser()`).
     */
    public function unbanUser(User $user): RedirectResponse
    {
        if ($user->estFermeVolontairement()) {
            return back()->with('error', __('admin.users_closed_use_reopen'));
        }

        $user->update(['banned_at' => null]);

        return back()->with('success', 'Utilisateur débanni.');
    }

    /**
     * TASK-1716 — la reouverture EXPLICITE d'un compte ferme par son
     * proprietaire.
     *
     * Elle existe parce que le refus de `unbanUser()` ne doit pas creer une
     * impasse. La fermeture RESERVE l'adresse email (arbitrage MASTER) : sans
     * cette porte, une personne qui ferme son compte par erreur ne pourrait ni
     * y revenir ni se reinscrire avec la meme adresse. Le geste est seulement
     * deplace la ou il est conscient — un bouton distinct, un autre libelle,
     * une confirmation qui dit ce qu'elle fait.
     *
     * Les TROIS colonnes sont remises a `null`, et la troisieme demande une
     * justification : rouvrir un compte contredit une demande d'effacement. La
     * laisser en place afficherait une demande pendante sur un compte actif,
     * que personne ne saurait plus comment traiter. L'effacement reste
     * demandable a nouveau depuis le profil.
     *
     * `forceFill` et non `update` : les trois colonnes sont hors `$fillable`
     * a dessein (voir `User::casts`).
     */
    /**
     * TASK-1716 — enregistrer, ou retirer, une demande d'effacement.
     *
     * MASTER : « prevoir un canal de demande d'effacement apres fermeture ».
     * La case a cocher du formulaire de fermeture couvre l'instant de la
     * decision ; elle ne couvre pas l'APRES, et c'est la que le canal manquait
     * vraiment — une fois le compte ferme, la personne est deconnectee et ne
     * peut plus rien deposer par le produit. Sa demande arrive alors par un
     * autre chemin, et elle vivait jusqu'ici dans une boite de reception.
     *
     * L'action est reservee a un compte FERME : enregistrer une demande
     * d'effacement sur un compte actif ferait apparaitre une pastille rouge
     * devant quelqu'un qui n'a rien demande, et le bon geste pour un compte
     * actif est que la personne la depose elle-meme depuis son profil.
     *
     * Elle n'efface RIEN : la suppression reste l'ecran de TASK-1636, avec ses
     * refus metier. Ce drapeau rend la demande visible et datee.
     */
    public function recordErasureRequest(Request $request, User $user): RedirectResponse
    {
        if (! $user->estFermeVolontairement()) {
            return back()->with('error', __('admin.users_erasure_requires_closed'));
        }

        $demande = $request->boolean('demande');

        $user->forceFill([
            'erasure_requested_at' => $demande ? now() : null,
        ])->save();

        return back()->with('success', __($demande
            ? 'admin.users_erasure_recorded'
            : 'admin.users_erasure_withdrawn'));
    }

    public function reopenUser(User $user): RedirectResponse
    {
        if (! $user->estFermeVolontairement()) {
            return back()->with('error', __('admin.users_reopen_not_closed'));
        }

        $user->forceFill([
            'banned_at' => null,
            'self_closed_at' => null,
            'erasure_requested_at' => null,
        ])->save();

        return back()->with('success', __('admin.users_reopen_done'));
    }

    public function toggleUserAvailability(User $user): RedirectResponse
    {
        $user->update(['is_available' => ! $user->is_available]);

        return back()->with('success', 'Disponibilité modifiée.');
    }

    public function toggleUserAdmin(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas modifier vos propres droits admin.');
        }
        $user->update(['is_admin' => ! $user->is_admin]);

        return back()->with('success', 'Droits admin modifiés.');
    }

    public function adjustPoints(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'delta' => 'required|integer|not_in:0',
            'reason' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($user, $data) {
            PointLedger::create([
                'user_id' => $user->id,
                'delta' => $data['delta'],
                'organization_id' => $user->organization_id,
                // TASK-1667 — le motif etait VALIDE puis ignore : toute correction
                // s'ecrivait « adjustment », y compris une remise a zero. Le
                // defaut par defaut reste `adjustment`, donc rien ne change pour
                // les appels qui n'en fournissent pas.
                'reason' => $data['reason'] ?? 'adjustment',
            ]);
            $user->increment('points_balance', $data['delta']);
        });

        $sign = $data['delta'] > 0 ? '+' : '';

        return back()->with('success', "Solde ajusté de {$sign}{$data['delta']} pts pour {$user->name}.");
    }

    /**
     * TASK-1693 bug B — valider manuellement la verification d'email.
     *
     * ## Pourquoi cet ecran n'existait pas
     *
     * Avant cette tranche, TROIS chemins seulement posaient
     * `email_verified_at` : le parcours d'INVITATION (le jeton est arrive sur
     * cette adresse exacte, la possession est donc prouvee), les Scenario Packs
     * (donnees de demonstration), et le lien signe standard
     * `verification.verify`. Un compte cree a la main par un administrateur, ou
     * dont l'email de verification n'est jamais arrive, restait donc bloque sans
     * aucun recours cote produit : `EnsureEmailIsVerified` le detournait vers
     * `/verify-email` sur toute surface prefixee, et rien dans
     * l'administration ne permettait de le debloquer.
     *
     * ## Ce que ce geste est, et ce qu'il n'est pas
     *
     * C'est un DEPANNAGE, pas un outil de gestion courante : il contourne une
     * preuve de possession d'adresse. Il vit donc dans le groupe
     * `['auth','admin']` — pile effective `AdminMiddleware`, le SuperAdmin
     * PLATEFORME — et jamais a la main d'un admin d'Organization, qui pourrait
     * sinon valider une adresse qu'il ne controle pas.
     *
     * Il est IDEMPOTENT et ne reverifie jamais a la baisse : un compte deja
     * verifie conserve sa date d'origine, et aucun chemin ici ne REMET
     * `email_verified_at` a `null` (ce que fait deja, legitimement, le
     * changement d'adresse dans `ProfileController::update()`).
     *
     * `markEmailAsVerified()` plutot qu'un `forceFill()` ecrit a la main :
     * c'est le contrat du framework pour poser cette colonne.
     *
     * ## Pourquoi l'evenement `Verified` n'est PAS emis ici
     *
     * `markEmailAsVerified()` n'emet rien par lui-meme — c'est
     * `VerifyEmailController` qui emet `Verified` apres l'avoir appele. On ne
     * reproduit PAS ce geste, et c'est deliberé.
     *
     * `ClaimGuestVisitorOnVerification` reagit a cet evenement en appelant
     * `GuestClaimService::claim($user, request())`, qui lit le cookie
     * `bp_guest` de la REQUETE COURANTE. Or ici la requete est celle du
     * SuperAdmin : on lierait le visiteur anonyme de l'ADMINISTRATEUR au compte
     * du membre, et `RecordCrmEmailVerified` consignerait les dimensions
     * d'acquisition de l'admin comme si elles etaient celles du membre. Un fait
     * FAUX ecrit sur une personne, pire que l'absence de fait.
     *
     * Consequence assumee et nommee : une validation manuelle ne produit ni
     * claim de visiteur, ni fait CRM `email_verified`. C'est coherent — il n'y
     * a eu aucun parcours d'acquisition a attribuer, puisque personne n'a
     * clique de lien. Mesure par `test_the_gesture_emits_no_acquisition_event`.
     */
    public function verifyUserEmail(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('success', "L'adresse de {$user->name} était déjà vérifiée.");
        }

        $user->markEmailAsVerified();

        return back()->with('success', "Adresse email de {$user->name} marquée comme vérifiée.");
    }

    /**
     * TASK-1714 volet 2 — VALIDER LE COMPTE.
     *
     * ## Distinct de « Valider l'email », et c'est tout le point
     *
     * Le geste voisin atteste d'une ADRESSE : quelqu'un lit bien cette boite.
     * Celui-ci atteste d'une PERSONNE : elle a sa place dans cette
     * Organization. Une Organization qui active `requires_account_approval`
     * veut explicitement les deux, et dans cet ordre.
     *
     * ## Idempotent, et il le dit
     *
     * Valider deux fois n'est pas une erreur — deux onglets ouverts suffisent
     * a produire le cas. Le second passage ne REECRIT PAS `approved_at` : la
     * date doit rester celle de la decision, pas celle du dernier clic. Et le
     * message distingue les deux situations, sinon l'administrateur ne sait
     * pas si son geste a servi.
     */
    public function approveUserAccount(User $user): RedirectResponse
    {
        if ($user->approved_at !== null) {
            return back()->with('success', __('admin.users_approved_flash', ['name' => $user->full_name ?? $user->name]));
        }

        // Hors assignation de masse : `approved_at` n'est deliberement pas
        // dans `User::$fillable`, comme les autres drapeaux qui ouvrent un
        // acces.
        $user->forceFill(['approved_at' => now()])->save();

        // TASK-1714 (demande Cyril, 27/10) — l'email de bienvenue part ICI
        // quand l'organisation exige une validation.
        //
        // Condition symetrique de celle de `SendPointsGrantedEmailOnVerification` :
        // on n'envoie que si l'adresse est DEJA verifiee. Un administrateur peut
        // valider un compte avant que la personne n'ait clique son lien ; dans
        // ce cas c'est la verification qui enverra, en trouvant l'approbation
        // deja acquise. L'email part donc exactement UNE fois, au moment du
        // SECOND evenement, quel que soit leur ordre.
        //
        // Le court-circuit d'idempotence plus haut (`approved_at !== null`)
        // protege du double envoi : valider deux fois ne repasse pas ici.
        //
        // `rescue()`, comme partout ailleurs sur ce chemin : une panne de
        // transport mail ne doit pas transformer une validation reussie en
        // erreur. Le compte est valide ; l'email est un accessoire.
        if ($user->hasVerifiedEmail()) {
            rescue(fn () => $user->notify(new WelcomeNotification));
        }

        return back()->with('success', __('admin.users_approved_flash', ['name' => $user->full_name ?? $user->name]));
    }

    /**
     * TASK-1694 bug J — RENVOYER le lien de confirmation.
     *
     * ## Distinct de « Valider l'email », et c'est tout le point
     *
     * Le geste voisin CONTOURNE la preuve de possession d'adresse : c'est un
     * depannage. Celui-ci la REDEMANDE — il renvoie le lien et laisse la
     * personne prouver qu'elle lit bien cette boite.
     *
     * C'est donc le geste a tenter D'ABORD. Il vit dans le meme groupe, donc
     * sous la meme autorite : SuperAdmin plateforme.
     *
     * ## La duree repart de zero, et ce n'est pas un effet de bord
     *
     * Le lien est SIGNE avec une expiration calculee AU MOMENT DE L'ENVOI
     * (`auth.verification.expire`, 24 h depuis le bug C). Renvoyer, c'est donc
     * necessairement remettre le compteur a zero — c'est la demande de Cyril,
     * et c'est aussi la seule chose qu'un lien signe sache faire : on ne
     * prolonge pas un lien deja emis, on en emet un neuf.
     *
     * ## Rien sur un compte deja verifie
     *
     * Le bouton n'apparait pas, et l'action refuse quand meme : une garde de
     * vue n'est pas une protection. Renvoyer un lien a quelqu'un de deja
     * verifie l'inviterait a un geste qui ne sert a rien.
     */
    public function resendUserVerification(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('error', __('admin.users_resend_verification_already', ['name' => $user->email]));
        }

        $user->sendEmailVerificationNotification();

        return back()->with('success', __('admin.users_resend_verification_sent', ['name' => $user->email]));
    }

    /**
     * TASK-1694 bug L — envoyer UN gabarit de l'Emailer a UNE personne.
     *
     * ## Ce que remplace ce geste
     *
     * Le lien « Envoyer un email » de la colonne « Actions » menait au
     * CATALOGUE (`/admin/email-templates`), sans rien savoir de la personne de
     * la ligne : il fallait y rechoisir le destinataire. Demande de Cyril :
     * cocher le gabarit, puis un bouton d'envoi.
     *
     * ## Pourquoi une route a soi, et pas `email-templates.send.execute`
     *
     * Ce parcours-la met le gabarit dans l'URL — donc une URL par choix, que
     * seul du JavaScript saurait recomposer apres un clic de radio. Ici le
     * gabarit est une DONNEE du formulaire, et la personne vient du chemin :
     * c'est l'inverse, et c'est ce que la demande decrit.
     *
     * L'ENVOI, lui, n'est pas reecrit : `EmailerService::sendFromTemplate()`
     * reste l'autorite unique — interpolation des variables, `replyTo` sur
     * l'administrateur, et la ligne d'`EmailLog` qui en garde la trace.
     *
     * ## Les deux gardes de l'autre parcours sont conservees
     *
     * `activeAccount()` : on n'ecrit pas a un compte banni. C'est la meme borne
     * que `sendExecute()`, pas une politique inventee ici. Le plafond de 50 n'a
     * pas de sens pour un destinataire unique.
     */
    public function sendEmailToUser(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'uuid', Rule::exists('email_templates', 'id')],
        ]);

        if (! User::activeAccount()->whereKey($user->id)->exists()) {
            return back()->with('error', __('admin.users_send_email_inactive', ['name' => $user->email]));
        }

        $template = EmailTemplate::findOrFail($validated['template_id']);

        $log = app(EmailerService::class)->sendFromTemplate($template, $user, $request->user());

        return $log->status === 'sent'
            ? back()->with('success', __('admin.users_send_email_sent', [
                'template' => $template->name,
                'name' => $user->email,
            ]))
            : back()->with('error', __('admin.users_send_email_failed', ['name' => $user->email]));
    }

    public function createUser(): View
    {
        return view('admin.users.create');
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'is_admin' => 'boolean',
            'points' => 'required|integer|min:0',
        ]);

        $organization = $this->organizationDAccueilParDefaut();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'organization_id' => $organization->id,
            'is_admin' => $data['is_admin'] ?? false,
            'points_balance' => $data['points'],
        ]);

        // TASK-1714 volet 2 (arbitrage MASTER A2) — approuve d'office. Le geste
        // de creation par un SuperAdmin EST l'approbation : lui demander de
        // valider ce qu'il vient de creer n'ajouterait aucune decision.
        // Hors assignation de masse, comme les autres drapeaux d'acces.
        $user->forceFill(['approved_at' => now()])->save();

        if ($data['points'] > 0) {
            PointLedger::create([
                'user_id' => $user->id,
                'delta' => $data['points'],
                'organization_id' => $user->organization_id,
                'reason' => 'welcome_bonus',
            ]);
        }

        return redirect()->route('admin.users')->with('success', "Utilisateur {$user->name} créé avec succès.");
    }

    public function changePassword(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update(['password' => Hash::make($request->password)]);

        return back()->with('success', "Mot de passe de {$user->name} modifié.");
    }

    /**
     * TASK-1727 (A2) — le compte est DEJA designe, le broker ne le redevine plus.
     *
     * Cette route porte l'uuid du compte dans son URL
     * (`/admin/users/{user}/send-password-reset`), et l'ancien code le jetait
     * pour ne garder que l'email : `sendResetLink(['email' => $user->email])`
     * laissait le broker re-resoudre par `retrieveByCredentials()->first()`.
     * Un administrateur cliquait sur une ligne precise et le serveur
     * recommencait la recherche a partir de la seule donnee ambigue dont il
     * disposait.
     *
     * Le couple `id` + `email` referme cela sans forker le broker : la cle
     * primaire ET l'adresse, donc au plus un compte, et jamais un autre que
     * celui de la ligne cliquee.
     */
    public function sendPasswordResetLink(User $user): RedirectResponse
    {
        $status = Password::broker()->sendResetLink(
            app(\App\Services\Users\PasswordResetAccountResolver::class)->credentials($user)
        );

        if ($status === Password::RESET_LINK_SENT) {
            EmailLog::create([
                'template_id' => null,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'to_email' => $user->email,
                'subject' => 'Réinitialisation de votre mot de passe',
                'status' => 'sent',
                'data' => [
                    'source' => 'admin-password-reset',
                    'broker' => 'users',
                    'admin_id' => auth()->id(),
                ],
            ]);
        }

        return match ($status) {
            Password::RESET_LINK_SENT => back()->with('success', 'Lien de réinitialisation envoyé.'),
            Password::RESET_THROTTLED => back()->with('error', 'Un lien a déjà été envoyé récemment. Réessayez dans quelques instants.'),
            default => back()->with('error', 'Impossible d\'envoyer le lien de réinitialisation.'),
        };
    }

    public function loginAsUser(User $user): RedirectResponse
    {
        if ($user->banned_at) {
            return back()->with('error', 'Impossible de se connecter sous un utilisateur banni.');
        }

        if ($user->is_admin) {
            return back()->with('error', 'Impossible de se connecter sous un autre administrateur.');
        }

        session()->put('admin_original_id', auth()->id());

        auth()->login($user);
        session()->regenerate();

        return redirect('/');
    }

    public function backToAdmin(): RedirectResponse
    {
        $originalAdminId = session()->pull('admin_original_id');

        if (! $originalAdminId) {
            return redirect('/')->with('error', 'Aucune session admin précédente trouvée.');
        }

        $admin = User::find($originalAdminId);

        if (! $admin) {
            return redirect('/')->with('error', 'Compte admin introuvable.');
        }

        auth()->login($admin);
        session()->regenerate();

        return redirect()->route('admin.users');
    }

    /**
     * TASK-1726 — l'Organization d'ACCUEIL d'un compte qu'on cree.
     *
     * S'appelait `resolveOrganizationFromInput()` et acceptait un
     * `organization_id` venu de la requete : ce parametre ne servait qu'aux
     * deux chemins de reaffectation, supprimes. Son unique appelant restant
     * (`storeUser()`) ne passait deja rien, et une Organization CHOISIE n'a
     * plus de sens qu'a la creation — un compte ne bouge plus ensuite.
     *
     * La branche `Organization::withTrashed()` part avec le parametre : elle
     * permettait d'affecter quelqu'un a une Organization effacee en douce.
     * Rien ne cree dans une Organization supprimee.
     */
    private function organizationDAccueilParDefaut(): Organization
    {
        $organization = DefaultOrganizationResolver::resolve();

        if ($organization) {
            return $organization;
        }

        throw ValidationException::withMessages([
            'organization_id' => 'Aucune organisation par défaut active n\'est disponible.',
        ]);
    }

    private function adminOrganizations(): Collection
    {
        return Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);
    }

    /**
     * TASK-1693 bug J — le defaut devient un PARAMETRE, au lieu d'etre impose.
     *
     * Ce helper sert QUATRE ecrans d'administration. Changer son repli pour
     * tous aurait modifie trois ecrans que personne n'a demandes — et un
     * filtre qui change de defaut sans prevenir fait lire les mauvais chiffres
     * a qui connait l'ancien comportement.
     *
     * Le repli historique (l'Organization par defaut de la plateforme) reste
     * donc la valeur par defaut du parametre : les trois autres appels sont
     * inchanges, a l'octet. Seul le grand livre passe `'all'`, parce que c'est
     * un ecran de RECHERCHE : on y arrive sans savoir dans quel tenant se
     * trouve la personne, et pre-filtrer sur une Organization y cache
     * precisement ce qu'on vient chercher.
     */
    private function selectedAdminOrganizationId(Request $request, ?string $defaut = null): string
    {
        if ($request->input('organization_id') === 'all') {
            return 'all';
        }

        if ($request->filled('organization_id')) {
            return (string) $request->input('organization_id');
        }

        if ($defaut !== null) {
            return $defaut;
        }

        return (string) (DefaultOrganizationResolver::resolve()?->getKey() ?? 'all');
    }

    private function applyAdminOrganizationFilter($query, string $organizationId): void
    {
        if ($organizationId !== 'all') {
            $query->where('organization_id', $organizationId);
        }
    }

    // ── Services ─────────────────────────────────────────────────────────────

    public function services(Request $request): View
    {
        $query = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->with(['user', 'category', 'organization']);
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $this->applyAdminOrganizationFilter($query, $selectedOrganizationId);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('status', 'active')->whereNull('deleted_at'),
                'paused' => $query->where('status', 'paused')->whereNull('deleted_at'),
                'deleted' => $query->onlyTrashed(),
                default => null,
            };
        }

        $filteredUser = $this->applyAdminUserFilter($query, $request);

        $services = $query->latest()->paginate(25)->withQueryString();

        return view('admin.services', compact('organizations', 'selectedOrganizationId', 'services', 'filteredUser'));
    }

    public function editService(string $service): View
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($service);
        $this->authorizeServiceEdit($service);

        $service->load(['category', 'skills', 'tags']);
        $organizations = Organization::orderBy('name')->get(['id', 'name', 'slug']);
        $categories = Category::where('organization_id', $service->organization_id)->orderBy('name_b2c')->get();
        $skills = Skill::with('category')->where('organization_id', $service->organization_id)->orderBy('name')->get();
        $users = User::assignable()->orderBy('first_name')->orderBy('name')->get(['id', 'first_name', 'name', 'email']);

        return view('admin.services.edit', compact('service', 'organizations', 'categories', 'skills', 'users'));
    }

    public function updateService(Request $request, string $service): RedirectResponse
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($service);
        $this->authorizeServiceEdit($service);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost' => 'required|integer|min:1',
            'status' => 'required|in:active,paused',
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')->whereNull('banned_at')],
            'organization_id' => 'required|uuid|exists:organizations,id',
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
        ]);

        $serviceOwner = User::assignable()->find($data['user_id']);
        if (! $serviceOwner || $serviceOwner->organization_id !== $data['organization_id']) {
            throw ValidationException::withMessages([
                'user_id' => __('validation.exists', ['attribute' => 'user']),
            ]);
        }

        $service->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost' => $data['points_cost'],
            'status' => $data['status'],
            'user_id' => $data['user_id'],
            'organization_id' => $data['organization_id'],
        ]);

        $service->skills()->syncWithPivotValues($data['skills'] ?? [], ['organization_id' => $data['organization_id']]);

        if (isset($data['tags'])) {
            $tagIds = [];
            foreach (array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5) as $name) {
                $slug = Str::slug($name);
                if ($slug) {
                    $tagIds[] = Tag::firstOrCreate(['slug' => $slug, 'organization_id' => $data['organization_id']], ['name' => $name, 'slug' => $slug])->id;
                }
            }
            $service->tags()->syncWithPivotValues($tagIds, ['organization_id' => $data['organization_id']]);
        }

        return redirect()->route('admin.services')->with('success', "Service « {$service->title} » modifié.");
    }

    private function authorizeServiceEdit(Service $service): void
    {
        $user = auth()->user();
        if ($user->is_admin) {
            return; // super-admin : accès total
        }
        // admin d'une communauté : seulement les services de sa communauté
        $organization = Organization::where('admin_id', $user->id)->first();
        if (! $organization || $service->organization_id !== $organization->id) {
            abort(403);
        }
    }

    public function forceDeleteService(string $id): RedirectResponse
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($id);
        $service->forceDelete();

        return back()->with('success', 'Service définitivement supprimé.');
    }

    public function restoreService(string $id): RedirectResponse
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($id);
        $service->restore();
        $service->update(['status' => 'active']);

        return back()->with('success', 'Service restauré.');
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    /**
     * Le grand livre des points, en LECTURE SEULE.
     *
     * TASK-1667 — « Ce membre a N ecriture(s) au grand livre des points »
     * bloquait la suppression sans que rien ne permette d'aller voir ces
     * ecritures : aucun ecran n'existait. Il en fallait donc un.
     *
     * Lecture seule et sans action, a dessein : un historique comptable ne se
     * supprime pas, et c'est precisement pour cela qu'il bloque. Cet ecran
     * explique le blocage, il ne le leve pas.
     */
    public function points(Request $request): View
    {
        $query = PointLedger::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->with(['user', 'organization']);

        $organizations = $this->adminOrganizations();
        // TASK-1693 bug J — « Toutes les organisations » par DEFAUT ici.
        //
        // Le grand livre est un ecran de RECHERCHE : on y arrive sans savoir
        // dans quel tenant se trouve la personne, et pre-filtrer sur une
        // Organization y cache precisement ce qu'on vient chercher. Les trois
        // autres ecrans qui partagent ce helper gardent leur repli historique.
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request, 'all');

        $this->applyAdminOrganizationFilter($query, $selectedOrganizationId);

        if ($request->filled('reason')) {
            $query->where('reason', $request->input('reason'));
        }

        // TASK-1693 bug I — chercher une personne par email, prenom ou nom.
        //
        // Le `select` de TASK-1667 charge TOUS les membres de la portee : il
        // repond bien quand on connait deja la personne et qu'ils sont peu
        // nombreux, mais pas a « qui est ce ofsh@… ? ». La recherche precede
        // donc le choix, et BORNE aussi ce `select` plus bas : apres une
        // recherche, la liste deroulante tient dans l'ecran.
        //
        // Le groupe `where(function ...)` n'est pas decoratif : sans lui, les
        // `orWhere` s'echapperaient de la contrainte d'Organization posee par
        // `applyAdminOrganizationFilter()` et la recherche RE-ELARGIRAIT la
        // liste a toute la plateforme — un filtre qui defiltre.
        $recherche = trim((string) $request->input('search', ''));

        if ($recherche !== '') {
            $query->whereHas('user', fn ($q) => $q->where(
                fn ($q2) => $q2->where('email', 'like', '%'.$recherche.'%')
                    ->orWhere('first_name', 'like', '%'.$recherche.'%')
                    ->orWhere('name', 'like', '%'.$recherche.'%')
            ));
        }

        $filteredUser = $this->applyAdminUserFilter($query, $request);

        // TASK-1667 — tri sur les colonnes, par liste BLANCHE.
        // Passer `sort` directement a `orderBy()` laisserait choisir n'importe
        // quelle colonne, y compris une qui n'est pas affichee.
        $triables = ['created_at', 'delta', 'reason'];
        $tri = in_array($request->input('sort'), $triables, true)
            ? (string) $request->input('sort')
            : 'created_at';
        $sens = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $entries = $query->orderBy($tri, $sens)->paginate(25)->withQueryString();

        // TASK-1667 — de QUI corriger le solde, choisi a l'ecran.
        //
        // Le panneau de correction ne s'affiche que sur une personne designee.
        // Sans ce choix, il fallait fabriquer l'URL `?user_id=...` a la main :
        // la capacite existait sans aucune poignee pour l'atteindre.
        //
        // Le solde est porte par l'option, pour qu'il soit lu AVANT de choisir.
        $membres = User::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->when($selectedOrganizationId !== 'all',
                fn ($q) => $q->where('organization_id', $selectedOrganizationId))
            // TASK-1693 bug I — la recherche borne AUSSI la liste deroulante :
            // trouver quelqu'un sans pouvoir le designer ensuite n'avancerait a
            // rien. Meme groupe, meme raison qu'au-dessus.
            ->when($recherche !== '', fn ($q) => $q->where(
                fn ($q2) => $q2->where('email', 'like', '%'.$recherche.'%')
                    ->orWhere('first_name', 'like', '%'.$recherche.'%')
                    ->orWhere('name', 'like', '%'.$recherche.'%')
            ))
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'points_balance']);

        $solde = (clone $query)->sum('delta');

        return view('admin.points', compact(
            'organizations', 'selectedOrganizationId', 'entries', 'filteredUser', 'solde', 'tri', 'sens', 'membres'
        ));
    }

    public function transactions(Request $request): View
    {
        // TASK-1666 — `withCount` plutot qu'un comptage par ligne dans la vue :
        // un compteur par ligne couterait une requete par echange affiche, et
        // c'est exactement le defaut mesure en T1640 (421 requetes pour 9 lignes).
        $query = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->withCount([
                'messages',
                // Un echange porte toujours un message SYSTEME d'ouverture
                // (« Conversation directe démarrée. », `sender_id` nul). Compter
                // separement les messages humains evite de promettre une
                // discussion la ou il n'y a qu'un marqueur technique.
                'messages as human_messages_count' => fn ($q) => $q->whereNotNull('sender_id'),
            ])
            ->with(['buyer', 'seller', 'service', 'serviceRequest', 'organization']);
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $this->applyAdminOrganizationFilter($query, $selectedOrganizationId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('buyer', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'))
                    ->orWhereHas('seller', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        // TASK-1665 — ciblage EXACT d'un echange, pour le lien pose par la modal
        // de suppression d'un membre.
        //
        // `search` n'est pas detourne : il cherche des PERSONNES
        // (`buyer.name`, `seller.name`) et doit continuer a le faire.
        //
        // La forme est validee AVANT d'atteindre PostgreSQL. Sans cela, une
        // valeur libre arrivant dans une comparaison sur une colonne `uuid`
        // leverait une erreur de cast (SQLSTATE 22P02) et rendrait un 500 la ou
        // un filtre qui ne correspond a rien doit simplement ne rien rendre.
        if ($request->filled('transaction_id')) {
            $transactionId = (string) $request->input('transaction_id');

            if (Str::isUuid($transactionId)) {
                $query->whereKey($transactionId);
            } else {
                // Forme invalide : aucun echange ne peut y correspondre. On le dit
                // en SQL plutot que de laisser la valeur atteindre la colonne.
                $query->whereRaw('1 = 0');
            }
        }

        $transactions = $query->latest()->paginate(25)->withQueryString();

        return view('admin.transactions', compact('organizations', 'selectedOrganizationId', 'transactions'));
    }

    // ── Requests ──────────────────────────────────────────────────────────────

    public function requests(Request $request): View
    {
        $query = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->with(['user', 'category', 'organization']);
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $this->applyAdminOrganizationFilter($query, $selectedOrganizationId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        $filteredUser = $this->applyAdminUserFilter($query, $request);

        $requests = $query->latest()->paginate(25)->withQueryString();

        return view('admin.requests', compact('organizations', 'selectedOrganizationId', 'requests', 'filteredUser'));
    }

    public function editRequest(string $serviceRequest): View
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($serviceRequest);
        $this->authorizeRequestEdit($serviceRequest);

        $serviceRequest->load('attachments');
        $categories = Category::orderBy('name_b2c')->get();

        return view('admin.requests.edit', compact('serviceRequest', 'categories'));
    }

    public function updateRequest(Request $request, string $serviceRequest): RedirectResponse
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($serviceRequest);
        $this->authorizeRequestEdit($serviceRequest);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'budget_min' => 'required|integer|min:1',
            'budget_max' => 'nullable|integer|gte:budget_min',
            'deadline' => 'nullable|date',
            'status' => 'required|in:open,in_progress,closed',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:10240',
            'delete_attachments' => 'nullable|array',
            'delete_attachments.*' => 'uuid|exists:request_attachments,id',
        ]);

        $serviceRequest->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'budget_min' => $data['budget_min'],
            'budget_max' => $data['budget_max'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'status' => $data['status'],
        ]);

        if (! empty($data['delete_attachments'])) {
            $toDelete = RequestAttachment::whereIn('id', $data['delete_attachments'])
                ->where('service_request_id', $serviceRequest->id)
                ->get();
            foreach ($toDelete as $att) {
                Storage::disk('public')->delete($att->path);
                $att->delete();
            }
        }

        if ($request->hasFile('attachments')) {
            $currentCount = $serviceRequest->attachments()->count();
            foreach ($request->file('attachments') as $index => $file) {
                $path = $file->store('request-attachments', 'public');
                $serviceRequest->attachments()->create([
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'order' => $currentCount + $index,
                    'organization_id' => $serviceRequest->organization_id,
                ]);
            }
        }

        return redirect()->route('admin.requests')->with('success', "Demande « {$serviceRequest->title} » modifiée.");
    }

    private function authorizeRequestEdit(ServiceRequest $serviceRequest): void
    {
        $user = auth()->user();
        if ($user->is_admin) {
            return;
        }
        $organization = Organization::where('admin_id', $user->id)->first();
        if (! $organization || $serviceRequest->organization_id !== $organization->id) {
            abort(403);
        }
    }

    public function closeRequest(string $serviceRequest): RedirectResponse
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($serviceRequest);
        $serviceRequest->update(['status' => 'closed']);

        return back()->with('success', 'Demande clôturée.');
    }

    public function destroyTransaction(string $transactionId): RedirectResponse
    {
        $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($transactionId);
        PointLedger::where('transaction_id', $transaction->id)->update(['transaction_id' => null]);
        $transaction->delete();

        return back()->with('success', __('admin.transaction_deleted'));
    }

    public function destroyRequest(string $requestId): RedirectResponse
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($requestId);
        foreach ($serviceRequest->transactions as $transaction) {
            PointLedger::where('transaction_id', $transaction->id)->update(['transaction_id' => null]);
            $transaction->delete();
        }
        $serviceRequest->delete();

        return back()->with('success', __('admin.request_deleted'));
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function categories(): View
    {
        $categories = Category::withCount(['services', 'skills', 'serviceRequests'])->with('skills')->get();

        return view('admin.categories', compact('categories'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        $data['slug'] = Str::slug($data['name']);
        Category::create($data);

        return back()->with('success', 'Catégorie créée.');
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        $data['slug'] = Str::slug($data['name']);
        $category->update($data);

        return back()->with('success', 'Catégorie mise à jour.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        if ($category->services()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0 || $category->serviceRequests()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0) {
            return back()->with('error', 'Impossible de supprimer une catégorie utilisée par des services ou demandes.');
        }
        $category->skills()->delete();
        $category->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }

    public function storeSkill(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        Skill::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
        ]);

        return back()->with('success', 'Compétence ajoutée.');
    }

    public function destroySkill(Skill $skill): RedirectResponse
    {
        $skill->delete();

        return back()->with('success', 'Compétence supprimée.');
    }

    // ── Reports ───────────────────────────────────────────────────────────────

    public function reports(): View
    {
        $reports = Report::with('reporter')->latest('created_at')->paginate(20);

        return view('admin.reports', compact('reports'));
    }

    public function dismissReport(Report $report): RedirectResponse
    {
        $report->update(['status' => 'dismissed']);

        return back()->with('success', 'Signalement classé.');
    }

    public function reviewReport(Report $report): RedirectResponse
    {
        $report->update(['status' => 'reviewed']);

        return back()->with('success', 'Signalement marqué comme traité.');
    }

    // ── Login history ─────────────────────────────────────────────────────────

    public function loginHistory(Request $request): View
    {
        $query = LoginLog::with(['user', 'organization']);

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        // TASK-1694 bug AK — filtrer par type d'appareil.
        //
        // Demande de Cyril : « savoir qui se connecte avec un smartphone, qui se
        // connecte avec son ordinateur ». Rien a capturer — `user_agent` est
        // stocke depuis toujours, donc le filtre porte retroactivement sur tout
        // l'historique.
        //
        // Une valeur inconnue est IGNOREE, pas refusee : un journal qu'on
        // consulte ne doit pas rendre une erreur parce qu'un parametre d'URL a
        // ete mal recopie.
        $device = DeviceKind::tryFrom((string) $request->input('device', ''));

        if ($device !== null) {
            DeviceKind::scope($query, $device);
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'user' => $query->orderBy(
                User::select('name')->whereColumn('id', 'login_logs.user_id')->limit(1),
                $direction
            ),
            'organization_id' => $query->orderBy(
                Organization::select('name')->whereColumn('id', 'login_logs.organization_id')->limit(1),
                $direction
            ),
            'ip_address' => $query->orderBy('ip_address', $direction),
            default => $query->latest('created_at'),
        };

        $loginLogs = $query->paginate(25)->withQueryString();
        $organizations = Organization::where('is_active', true)->orderBy('name')->get();

        return view('admin.login-history.index', compact('loginLogs', 'organizations') + [
            'currentDevice' => $device?->value ?? '',
            'deviceKinds' => DeviceKind::cases(),
        ]);
    }

    public function loginHistoryUser(Request $request, User $user): View
    {
        $logs = LoginLog::where('user_id', $user->id)
            ->with('organization')
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.login-history.user', compact('user', 'logs'));
    }

    // ── User deletion dry-run ─────────────────────────────────────────────────
    // ⚠️  Dry-run only — no data is ever deleted by these methods.

    public function deletePreview(User $user): View
    {
        $counts = $this->countUserRelations($user);

        $sameOrgUsers = User::where('organization_id', $user->organization_id)
            ->assignable()
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        // TASK-1636 : ce que l'executeur refuserait, dit AVANT de proposer le
        // bouton definitif.
        $precheck = app(UserDeletionExecutor::class)->precheck($user);
        $previewFingerprint = $this->deletePreviewFingerprint($precheck);

        return view('admin.users.delete-preview', compact(
            'user', 'counts', 'sameOrgUsers', 'precheck', 'previewFingerprint'
        ));
    }

    /**
     * TASK-1636 — la suppression REELLE. SuperAdmin uniquement.
     *
     * Tout est revalide ici : le compte, la cible de transfert, et les refus.
     * L'executeur recontrole ensuite lui-meme sous verrou — ce controleur ne lui
     * fait pas gagner un raccourci.
     *
     * ## TASK-1640 — la recopie du nom a ete RETIREE
     *
     * Elle donnait l'illusion d'une garde : elle ne prouvait ni l'identite de
     * l'admin, ni la fraicheur de ce qu'il avait lu, ni que l'etat du compte
     * permettait la suppression. Elle coutait une frappe et ne protegeait rien
     * que les quatre gardes reelles ne couvrent deja :
     *
     *  1. l'authentification SuperAdmin (`AdminMiddleware`) ;
     *  2. une modal de confirmation explicite ;
     *  3. `preview_fingerprint`, qui refuse une decision prise sur un etat perime ;
     *  4. le recontrole autoritatif sous `lockForUpdate()` dans l'executeur.
     *
     * Le champ n'est pas seulement cache a l'ecran : l'exigence est retiree de la
     * validation. Cacher le champ en laissant la regle cote serveur aurait produit
     * un formulaire que le serveur refuse — le defaut symetrique de celui mesure
     * en TASK-1636, ou l'ecran demandait `fullName` et le serveur comparait `name`.
     */
    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'preview_fingerprint' => 'required|string',
            'transfer_to' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('organization_id', $user->organization_id)
                    ->whereNull('banned_at'),
            ],
        ]);

        $executor = app(UserDeletionExecutor::class);

        // Fraicheur : on ne lit AUCUN compteur venu du navigateur. On recalcule
        // l'etat et on compare deux empreintes. Si la situation a bouge depuis
        // l'ecran, l'admin doit revoir ce qu'il signe.
        if ($this->deletePreviewFingerprint($executor->precheck($user)) !== $data['preview_fingerprint']) {
            return redirect()
                ->route('admin.users.delete-preview', $user)
                ->with('error', __('admin.user_delete.stale'));
        }

        $name = $user->fullName;

        try {
            $report = $executor->execute($user, $data['transfer_to'] ?? null);
        } catch (UserDeletionBlockedException $blocked) {
            return redirect()
                ->route('admin.users.delete-preview', $user)
                ->with('error', implode(' ', array_column($blocked->blocks, 'message')));
        }

        $message = [__('admin.user_delete.done', ['name' => $name])];

        if (($transferred = array_sum($report['transferred'])) > 0) {
            $target = User::find($data['transfer_to']);
            $message[] = __('admin.user_delete.done_transferred', [
                'count' => $transferred,
                'target' => $target?->name ?? '',
            ]);
        }

        if (($purged = $report['dossiers']['dossiers'] ?? 0) > 0) {
            $message[] = __('admin.user_delete.done_dossiers', ['count' => $purged]);
        }

        if (($deleted = array_sum($report['deleted'])) > 0) {
            $message[] = __('admin.user_delete.done_deleted', ['count' => $deleted]);
        }

        return redirect()->route('admin.users')->with('success', implode(' ', $message));
    }

    /**
     * Empreinte de l'etat presente a l'admin.
     *
     * Elle ne transporte aucun compteur : le navigateur la rend telle quelle et
     * le serveur la RECALCULE pour comparer. Un simple hash suffit — il n'y a
     * rien a signer, puisque rien de ce qui revient n'est cru sur parole.
     *
     * @param  array{blocks: list<array{key: string, count: int, message: string}>, transferable: array<string, int>, requires_transfer: bool}  $precheck
     */
    /**
     * TASK-1640 — la fiche complete d'un membre, demandee AU CLIC.
     *
     * Lecture seule, bornee a UN compte, et volontairement composee de comptages
     * plutot que de listes : la question posee par cet ecran est « qu'est-ce que
     * cette personne FAIT sur la plateforme », pas « donne-moi ses contenus ».
     * Rendre les lignes elles-memes aurait fait passer du contenu personnel
     * (messages, prompts IA) dans une reponse d'administration.
     *
     * Les tables absentes du schema sont ignorees plutot que de faire echouer la
     * fiche : ce depot a des tables qui apparaissent par TASK, et une fiche qui
     * jette parce qu'une table n'existe pas encore serait un faux defaut.
     */
    public function userProfileSummary(User $user): JsonResponse
    {
        $compte = function (string $table, string $column) use ($user): int {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return 0;
            }

            return DB::table($table)->where($column, $user->id)->count();
        };

        $ledgerIA = Schema::hasTable('ai_provider_invocations')
            ? DB::table('ai_provider_invocations')->where('user_id', $user->id)->selectRaw(
                'count(*) as appels, coalesce(sum(total_tokens), 0) as tokens, coalesce(sum(provider_cost), 0) as cout'
            )->first()
            : null;

        $derniereConnexion = Schema::hasTable('login_logs')
            ? DB::table('login_logs')->where('user_id', $user->id)->max('created_at')
            : null;

        return response()->json([
            'identite' => [
                'name' => $user->fullName,
                'email' => $user->email,
                'organization' => $user->organization?->name,
                'statut' => $user->banned_at !== null
                    ? __('admin.users_status_banned')
                    : ($user->is_available ? __('admin.users_status_available') : __('admin.users_status_unavailable')),
                'is_admin' => (bool) $user->is_admin,
                'points' => (int) $user->points_balance,
                'inscrit_le' => $user->created_at?->format('d/m/Y'),
                'derniere_connexion' => $derniereConnexion ? Carbon::parse($derniereConnexion)->format('d/m/Y H:i') : null,
                'note' => $user->rating ? number_format((float) $user->rating, 1).'/5' : null,
            ],
            'contributions' => [
                __('admin.users_profile_services') => $compte('services', 'user_id'),
                __('admin.users_profile_requests') => $compte('service_requests', 'user_id'),
                __('admin.users_profile_articles') => $compte('blog_posts', 'user_id'),
                __('admin.users_profile_feed') => $compte('feed_posts', 'user_id'),
                __('admin.users_profile_loops_created') => $compte('loops', 'created_by'),
                __('admin.users_profile_loop_messages') => $compte('loop_messages', 'sender_id'),
            ],
            'interactions' => [
                __('admin.users_profile_purchases') => $compte('transactions', 'buyer_id'),
                __('admin.users_profile_sales') => $compte('transactions', 'seller_id'),
                __('admin.users_profile_reviews_given') => $compte('reviews', 'reviewer_id'),
                __('admin.users_profile_reviews_received') => $compte('reviews', 'reviewed_id'),
                __('admin.users_profile_comments') => $compte('blog_comments', 'user_id') + $compte('feed_post_comments', 'user_id'),
                __('admin.users_profile_memberships') => $compte('loop_members', 'user_id'),
                __('admin.users_profile_votes') => $compte('loop_poll_votes', 'user_id'),
            ],
            'ia' => [
                'appels' => (int) ($ledgerIA->appels ?? 0),
                'tokens' => (int) ($ledgerIA->tokens ?? 0),
                // Le cout est un fait economique : on le rend tel quel, arrondi a
                // 4 decimales, sans le transformer en jugement.
                'cout' => round((float) ($ledgerIA->cout ?? 0), 4),
                'interactions' => $compte('ai_interactions', 'user_id'),
                'shell' => $compte('ai_shell_messages', 'user_id'),
                'retours' => $compte('ai_interaction_feedbacks', 'user_id'),
                'profil_ia' => $compte('member_ai_profiles', 'user_id') > 0,
            ],
        ]);
    }

    /**
     * TASK-1640 — ce que la modal de `/admin/users` demande AU CLIC.
     *
     * Lecture seule et bornee a UN compte. Rien n'est recalcule ici : ce sont
     * `UserDeletionExecutor::precheck()` et `deletePreviewFingerprint()`, deja
     * l'autorite, qui repondent. Aucune regle metier ne vit dans cette methode.
     *
     * Pourquoi une route dediee plutot que la liste : `precheck()` fait une
     * quinzaine de comptages par compte. Les calculer pour les 20 lignes d'une
     * page aurait ajoute ~300 requetes au rendu de `/admin/users` pour un clic
     * qui n'aura lieu qu'une fois.
     */
    public function userDeletePrecheck(User $user): JsonResponse
    {
        return response()->json($this->userDeletePayload($user));
    }

    /**
     * La page de suppression d'un compte.
     *
     * TASK-1668 — la modal avait grossi a chaque TASK : blocages avec un lien
     * par element, contenus a confier par famille avec les leurs, choix du
     * repreneur, et le caractere definitif a dire clairement. Une fenetre
     * surgissante n'est plus le bon contenant.
     *
     * La page consomme EXACTEMENT le meme payload, calcule cote serveur : rien
     * du travail des TASK precedentes n'est reecrit.
     */
    public function userDeletePage(User $user): View
    {
        return view('admin.users.delete', [
            'cible' => $user,
            'donnees' => $this->userDeletePayload($user),
        ]);
    }

    /**
     * Ce que l'ecran doit savoir pour proposer — ou refuser — une suppression.
     *
     * @return array<string, mixed>
     */
    private function userDeletePayload(User $user): array
    {
        $precheck = app(UserDeletionExecutor::class)->precheck($user);

        // Les repreneurs possibles, dans la MEME Organization : c'est la borne
        // que `destroyUser()` revalide de son cote. L'ecran ne propose donc
        // jamais un choix que le serveur refusera.
        $candidats = User::query()
            ->where('organization_id', $user->organization_id)
            ->assignable()
            ->whereKeyNot($user->id)
            ->orderBy('first_name')
            ->orderBy('name')
            ->get(['id', 'first_name', 'name'])
            ->map(fn (User $candidat) => ['id' => $candidat->id, 'name' => $candidat->fullName])
            ->values();

        return [
            'user' => ['id' => $user->id, 'name' => $user->fullName],
            // Seuls le libelle, le compte et d'eventuels liens de navigation
            // sortent : la cle technique du blocage reste cote serveur, l'ecran
            // n'a rien a en faire.
            'blocks' => collect($precheck['blocks'])
                ->map(fn (array $block) => array_filter([
                    'message' => $block['message'],
                    'count' => $block['count'],
                    'links' => $this->blockNavigationLinks($user, $block) ?: null,
                ], fn ($valeur) => $valeur !== null))
                ->values(),
            'requires_transfer' => $precheck['requires_transfer'],
            'transfer_total' => array_sum($precheck['transferable']),
            // TASK-1667 — « 3 contenus a confier » ne disait pas LESQUELS. Le
            // detail par famille etait deja calcule par `precheck()` ; seul
            // `array_sum()` ci-dessus le jetait. On l'expose, sans une seule
            // requete de plus.
            'transfers' => $this->transferDetails($user, $precheck['transferable']),
            'transfer_candidates' => $candidats,
            'preview_fingerprint' => $this->deletePreviewFingerprint($precheck),
        ];
    }

    /**
     * TASK-1667 — borne une liste de contenus a UN auteur.
     *
     * Pose sur `user_id`, jamais sur `search` : ce dernier cherche un TITRE sur
     * ces ecrans, et le detourner en pseudo-filtre d'identite rendrait les deux
     * usages impossibles a distinguer.
     *
     * La forme est validee AVANT d'atteindre PostgreSQL : une valeur libre
     * arrivant sur une colonne `uuid` y leve SQLSTATE 22P02 et rendrait un 500,
     * la ou un filtre sans correspondance doit simplement ne rien rendre.
     * SQLite ne reproduit pas ce defaut.
     *
     * Rend l'auteur quand il existe, pour que l'ecran puisse le NOMMER : une
     * liste bornee qui ne dit pas sur qui elle l'est se lit comme une liste
     * complete.
     */
    private function applyAdminUserFilter($query, Request $request): ?User
    {
        if (! $request->filled('user_id')) {
            return null;
        }

        $userId = (string) $request->input('user_id');

        if (! Str::isUuid($userId)) {
            $query->whereRaw('1 = 0');

            return null;
        }

        $query->where('user_id', $userId);

        return User::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->find($userId);
    }

    /**
     * Le detail, famille par famille, de ce qu'une suppression ferait changer
     * de main.
     *
     * TASK-1667 — on demandait a l'admin de choisir un destinataire pour des
     * contenus qu'il n'avait pas vus. Chaque famille non vide sort donc avec
     * son compte et, quand un ecran d'administration existe, un lien vers ce
     * qu'elle contient.
     *
     * `feed_posts` n'a AUCUN ecran d'administration dans ce depot : son compte
     * sort donc sans `url`. Mieux vaut un nombre sans lien qu'un lien vers une
     * page qui n'existe pas.
     *
     * @param  array<string, int>  $transferable
     * @return list<array{key: string, label: string, count: int, url?: string}>
     */
    private function transferDetails(User $user, array $transferable): array
    {
        $ecrans = [
            'blog_posts' => 'admin.blog',
            'services' => 'admin.services',
            'service_requests' => 'admin.requests',
            // 'feed_posts' — volontairement absent : aucun ecran n'existe.
        ];

        $details = [];

        foreach ($transferable as $cle => $nombre) {
            if ($nombre < 1) {
                continue;
            }

            $detail = [
                'key' => $cle,
                'label' => __('admin.user_delete_transfer_family_'.$cle),
                'count' => $nombre,
            ];

            if (isset($ecrans[$cle])) {
                $detail['url'] = route($ecrans[$cle], array_filter([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->id,
                ]));
            }

            $details[] = $detail;
        }

        return $details;
    }

    /**
     * Les liens de navigation d'un blocage, quand le SuperAdmin a quelque part
     * ou aller pour le lever.
     *
     * TASK-1665 — un blocage « ce membre est vendeur dans 1 echange » laissait
     * l'ecran sans moyen d'identifier l'echange. On rend donc un lien par
     * transaction bloquante, vers l'administration des transactions filtree sur
     * celle-la. La suppression, elle, reste au workflow existant : cette modal
     * ne detruit aucune transaction.
     *
     * Seuls les blocages a cle `transactions_as_*` produisent des liens. Les
     * autres n'en ont pas, et ne doivent pas en inventer.
     *
     * @param  array{key: string, count: int, message: string}  $block
     * @return list<array{label: string, url: string}>
     */
    private function blockNavigationLinks(User $user, array $block): array
    {
        // TASK-1667 — le grand livre des points. Une seule destination suffit :
        // l'ecran est deja borne sur la personne, et aligner N liens vers la
        // meme page n'apprendrait rien de plus.
        if ($block['key'] === 'point_ledger') {
            return [[
                'label' => __('admin.user_delete_block_link_points'),
                'url' => route('admin.points', array_filter([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->id,
                ])),
            ]];
        }

        // TASK-1667 — les Boucles dont ce membre est le dernier responsable.
        // Les identifiants viennent de l'executeur, qui DERIVE son compte de
        // cette meme liste : annoncer « 1 Boucle » en montrant zero lien est
        // donc structurellement impossible.
        if ($block['key'] === 'loop_last_owner') {
            return app(UserDeletionExecutor::class)
                ->loopIdsWhereLastActiveOwner($user)
                ->map(fn (string $loopId, int $rang) => [
                    'label' => __('admin.user_delete_block_link_loop', ['rank' => $rang + 1]),
                    'url' => route('admin.loops.show', ['loop' => $loopId]),
                ])
                ->values()
                ->all();
        }

        if (! in_array($block['key'], ['transactions_as_buyer', 'transactions_as_seller'], true)) {
            return [];
        }

        return $this->blockingRowIds($user, $block['key'])
            ->map(fn (string $id, int $rang) => [
                'label' => __('admin.user_delete_block_link_transaction', [
                    'rank' => $rang + 1,
                    'short' => strtoupper(substr($id, 0, 8)),
                ]),
                'url' => route('admin.transactions', array_filter([
                    'organization_id' => $user->organization_id,
                    'transaction_id' => $id,
                ])),
            ])
            ->values()
            ->all();
    }

    /**
     * Les identifiants des lignes qui bloquent, pour une cle du registre.
     *
     * Le predicat n'est PAS reecrit ici : il est relu au registre, exactement
     * comme `UserDeletionExecutor::countBlockingRows()` le fait. Un
     * `where('seller_id', ...)` ecrit a la main aurait fabrique une seconde
     * copie de la politique, libre de deriver du compte affiche — la modal
     * aurait pu annoncer « 1 echange » et montrer zero lien, sans que rien ne le
     * signale. La politique ne vit qu'au registre.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function blockingRowIds(User $user, string $key): Collection
    {
        $entry = collect(UserDataLifecycleRegistry::entries())
            ->firstWhere('key', $key);

        if ($entry === null || ! isset($entry['table'], $entry['column']) || ! Schema::hasTable($entry['table'])) {
            return collect();
        }

        $query = DB::table($entry['table'])->where($entry['column'], $user->id);

        UserDataLifecycleRegistry::excludeResolvableRows($query, $entry, $entry['table']);

        return $query->orderBy('created_at')->pluck('id')
            ->map(fn ($id) => (string) $id)
            // Une meme ligne ne peut pas apparaitre deux fois, mais un blocage
            // qui en montrerait deux serait plus deroutant qu'utile.
            ->unique()
            ->values();
    }

    private function deletePreviewFingerprint(array $precheck): string
    {
        $blocks = collect($precheck['blocks'])
            ->map(fn (array $block) => $block['key'].':'.$block['count'])
            ->sort()
            ->values()
            ->all();

        $transferable = $precheck['transferable'];
        ksort($transferable);

        return hash('sha256', json_encode(['blocks' => $blocks, 'transferable' => $transferable]));
    }

    public function deleteUser(Request $request, User $user): View
    {
        $data = $request->validate([
            'confirmation' => 'required|string',
            'transfer_to' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('organization_id', $user->organization_id)
                    ->whereNull('banned_at'),
            ],
        ]);

        // La vue demande le nom COMPLET (`fullName`) : comparer a `name` seul
        // rendait tout compte portant un prenom impossible a confirmer en
        // suivant l'instruction affichee.
        if ($data['confirmation'] !== $user->fullName) {
            return $this->deletePreview($user);
        }

        $counts = $this->countUserRelations($user);

        if (! empty($data['transfer_to'])) {
            $counts['transfer'] = $this->estimateTransferCounts($user, $data['transfer_to']);
        }

        $counts['preview_only'] = true;

        $sameOrgUsers = User::where('organization_id', $user->organization_id)
            ->assignable()
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        // TASK-1636 : la simulation prepare l'etape definitive — ce qui
        // bloquerait, et l'empreinte de l'etat sur lequel l'admin se prononce.
        $precheck = app(UserDeletionExecutor::class)->precheck($user);
        $previewFingerprint = $this->deletePreviewFingerprint($precheck);
        $transferTo = $data['transfer_to'] ?? null;

        return view('admin.users.delete-preview', compact(
            'user', 'counts', 'sameOrgUsers', 'precheck', 'previewFingerprint', 'transferTo'
        ));
    }

    private function countUserRelations(User $user): array
    {
        return app(UserDataLifecycleRegistry::class)->preview($user);
    }

    private function estimateTransferCounts(User $user, string $transferToId): array
    {
        return app(UserDataLifecycleRegistry::class)->transferEstimate($user, $transferToId);
    }
}
