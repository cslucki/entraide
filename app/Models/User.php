<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use App\Services\ReferralCodeGenerator;
use App\Services\Users\Exceptions\UserOrganizationImmutableException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string|null $organization_id
 */
/**
 * TASK-1412 — `MustVerifyEmail` est l'INTERFACE, pas le trait. Le trait est
 * herite de la classe de base depuis toujours (les methodes existaient), mais
 * sans l'interface, `Registered` n'envoyait aucun email et le middleware
 * `verified` deja pose sur les routes etait un no-op silencieux. L'interface
 * est ce que le framework teste (`$user instanceof MustVerifyEmail`).
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /**
     * TASK-1412 — l'email de verification ne doit JAMAIS faire echouer une
     * inscription. Le listener framework l'envoie sur `Registered`, AVANT le
     * `rescue()` qui protege deja la notification de bienvenue (T1043) : sans
     * cette surcharge, une panne de transport mail transformerait un compte
     * cree en erreur 500. L'echec est rapporte au handler (comportement de
     * `rescue()`), le compte reste non verifie, et la page d'attente propose
     * le renvoi.
     */
    public function sendEmailVerificationNotification(): void
    {
        // TASK-1694 bug D — le gabarit vient de `/admin/system-email-templates`,
        // global et bilingue. La classe ETEND celle du framework : l'url signee
        // et sa duree restent calculees au meme endroit, et si le gabarit manque
        // c'est l'email du framework qui part — jamais un email vide.
        rescue(fn () => $this->notify(new \App\Notifications\VerifyEmailWithTemplate));
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasOrganizationId, HasUuids, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (! $user->isDirty('referral_code')) {
                $user->referral_code = app(ReferralCodeGenerator::class)->generate($user);
            }
        });

        /**
         * TASK-1726 — un compte appartient a UNE Organization, et n'en change
         * plus.
         *
         * ## La decision produit
         *
         * `Organization` EST le tenant (`Loop` ne l'est pas). Deplacer un
         * compte d'un tenant vers un autre laisse derriere lui tout ce qu'il a
         * produit : 48 tables portent `organization_id`, l'economie du compte
         * (`points_balance`, `rating`, `membership_value`) est comptee par
         * tenant, et son contenu — messages de Boucle, contacts CRM, dossiers
         * — reste ou il a ete ecrit. Le compte arrivait donc dans sa nouvelle
         * Organization avec un solde et une reputation gagnes ailleurs, tandis
         * que son ancien tenant gardait des lignes dont l'auteur n'etait plus
         * membre. Arbitrage MASTER : pour rejoindre une autre Organization, on
         * aura un compte DISTINCT, au besoin avec le meme email.
         *
         * ## Ce que cette garde couvre, et ce qu'elle ne couvre pas
         *
         * Elle est posee sur `updating`, donc sur les ecritures PAR LE MODELE
         * — `save()`, `update()`, `fill()->save()`, `forceFill()->save()`,
         * `updateOrCreate()` sur une ligne existante. C'est le chemin de tous
         * les ecrans : `organization_id` reste dans `$fillable` parce que
         * `User::create()` en a besoin (une vingtaine de sites de creation le
         * passent en assignation de masse), et le retirer aurait fait
         * disparaitre la valeur en SILENCE a la creation — exactement le genre
         * de panne qu'on ne voit qu'en recette.
         *
         * Elle ne se declenche PAS sur un UPDATE de masse par le constructeur
         * de requetes (`$organization->users()->update(...)`), qui ne leve
         * aucun evenement de modele. Ce n'est pas un oubli : ce sont les trois
         * seules exceptions legitimes recensees a l'audit du 29/10, et toutes
         * trois passent deja par ce chemin, avec un predicat qui les borne.
         *
         *   - suppression definitive d'une Organization
         *     (`AdminOrganizationController::destroy`) : ses membres sont
         *     detaches a NULL, parce que le tenant n'existe plus. Il n'y a pas
         *     de destination a choisir.
         *   - reparation des comptes orphelins
         *     (`AdminAssignDataController`, `BackfillUsersOrganizationSeeder`)
         *     : `whereNull('organization_id')` — ces deux surfaces ne touchent
         *     QUE des lignes sans tenant, donc ne deplacent rien.
         *
         * ## Le cas NULL, et pourquoi il est autorise
         *
         * Rattacher un compte ORPHELIN (`organization_id` a NULL) n'est pas un
         * deplacement : c'est la premiere affectation d'un compte que la
         * suppression d'une Organization a laisse sans tenant. Le refus porte
         * donc sur la valeur D'ORIGINE : des lors qu'un compte a une
         * Organization, il la garde. Un passage vers NULL est refuse aussi —
         * detacher, c'est perdre le tenant, et seul l'effacement de
         * l'Organization elle-meme a le droit de le faire.
         */
        static::updating(function (User $user) {
            if (! $user->isDirty('organization_id')) {
                return;
            }

            $origine = $user->getOriginal('organization_id');

            if ($origine === null) {
                return;
            }

            throw new UserOrganizationImmutableException(
                (string) $user->getKey(),
                (string) $origine,
                $user->organization_id === null ? null : (string) $user->organization_id,
            );
        });

        static::saved(function (User $user) {
            if ($user->wasChanged('banned_at') && $user->banned_at !== null) {
                try {
                    $user->tokens()->delete();
                } catch (QueryException $e) {
                    report($e);
                }
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'name',
        'first_name',
        'email',
        'password',
        'avatar',
        'bio',
        'location',
        'phone',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country_code',
        'preferred_locale',
        'membership_value',
        'show_email',
        'show_phone',
        'website',
        'linkedin_url',
        'points_balance',
        'is_available',
        'is_admin',
        'banned_at',
        'rating',
        'referral_code',
        'slug',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'is_available' => 'boolean',
            // TASK-1659 — deliberement HORS `$fillable`, comme
            // `email_verified_at` : seul le parcours d'invitation le pose,
            // et seul l'ecran de definition du mot de passe le retire.
            'must_set_password' => 'boolean',
            'is_admin' => 'boolean',
            'show_email' => 'boolean',
            'show_phone' => 'boolean',
            'banned_at' => 'datetime',
            // TASK-1716 — deliberement HORS `$fillable`, comme `approved_at`
            // (T1714) et `must_set_password` (T1659). Ce sont des drapeaux de
            // cycle de vie : seul `ProfileController::close()` les pose, et
            // seule la reouverture explicite du SuperAdmin les retire. Les
            // laisser remplissables permettrait a un formulaire de profil de
            // fermer — ou de rouvrir — un compte par assignation de masse.
            'self_closed_at' => 'datetime',
            'erasure_requested_at' => 'datetime',
            'points_balance' => 'integer',
            'rating' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    public function getPublicLocationAttribute(): ?string
    {
        if (! $this->city) {
            return null;
        }

        if ($this->organization?->show_country === false || ! $this->country) {
            return $this->city;
        }

        return $this->city.', '.$this->country->getLocalizedName();
    }

    public function getFullNameAttribute(): string
    {
        if ($this->first_name) {
            return trim($this->first_name.' '.$this->name);
        }

        return $this->name ?? '';
    }

    public function getInitialsAttribute(): string
    {
        if ($this->first_name) {
            $first = strtoupper(mb_substr($this->first_name, 0, 1));
            $last = strtoupper(mb_substr($this->name, 0, 1));

            return $first.$last;
        }

        $parts = preg_split('/\s+/', trim($this->name ?? ''));
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(mb_substr($part, 0, 1));
        }

        return $initials ?: '?';
    }

    public function getIsDeactivatedAttribute(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * TASK-1658 — cette personne peut-elle ACCEDER a cette Organization ?
     *
     * L'autorite unique de l'acces au tenant. Elle repond a « as-tu le droit
     * d'entrer ici », et a rien d'autre.
     *
     * ## Pourquoi elle existe
     *
     * Le depot posait cette question A LA MAIN, huit fois, par
     * `$user->organization_id !== $organization->id` — plus vingt-trois appels
     * a un helper prive de `LoopController`. Aucune n'exemptait le SuperAdmin
     * plateforme : il recevait donc un 404 sur les surfaces d'une Organization
     * dont il n'est pas membre, alors qu'il doit pouvoir inspecter n'importe
     * quel tenant.
     *
     * ## Ce qu'elle N'accorde PAS
     *
     * Aucun membership, ni d'Organization ni de Boucle. Le SuperAdmin garde son
     * `organization_id`, son identite et ses droits ; il n'acquiert aucun role
     * de participant. Entrer n'est pas participer : repondre a un evenement,
     * transiger ou posseder restent gardes ailleurs, et T1658 n'y touche pas.
     *
     * ## Ce qu'elle ne remplace pas
     *
     * Les cent trente-cinq gardes qui comparent un OBJET a l'Organization
     * courante — « ce Dossier appartient-il bien a ce tenant ? ». Celles-la SONT
     * l'isolation de tenant : les contourner ferait fuiter des donnees d'une
     * Organization vers l'URL d'une autre. Elles restent intactes.
     */
    public function canAccessOrganization(Organization|string|null $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        $identifiant = $organization instanceof Organization ? $organization->id : $organization;

        // Le SuperAdmin plateforme passe. C'est la SEULE exemption, et elle est
        // explicite plutot que disseminee.
        if ($this->is_admin) {
            return true;
        }

        return $this->organization_id !== null && $this->organization_id === $identifiant;
    }

    public function isDeactivated(): bool
    {
        return $this->banned_at !== null;
    }

    public function isDisplayableIn(Organization|string|null $organization = null): bool
    {
        if ($this->isDeactivated()) {
            return false;
        }

        if ($organization instanceof Organization) {
            return $this->organization_id === $organization->id;
        }

        if (is_string($organization)) {
            return $this->organization_id === $organization;
        }

        return true;
    }

    /**
     * TASK-1693 bug Z — le registre des adresses publiques de ce membre,
     * courante ET passees. Les passees restent prises : voir `UserSlug`.
     */
    public function slugs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserSlug::class);
    }

    /**
     * Le segment d'URL a utiliser pour atteindre ce profil.
     *
     * Le slug quand il existe, l'identifiant sinon. On NE surcharge PAS
     * `getRouteKey()` : 30 routes hors profil resolvent un `{user}` par
     * LIAISON IMPLICITE sur l'identifiant, et elles recevraient alors un slug
     * qu'elles ne sauraient pas resoudre — toute l'administration tomberait en
     * 404. La jolie adresse est donc obtenue par la redirection canonique de
     * `ProfileController::show()`, pas en changeant la clef du modele.
     */
    public function profileRouteKey(): string
    {
        return $this->slug ?: $this->id;
    }

    public function publicDisplayName(): string
    {
        return $this->isDeactivated() ? __('profile.deactivated_user') : $this->fullName;
    }

    public function publicAvatarUrl(): ?string
    {
        return $this->isDeactivated() ? null : $this->avatar_url;
    }

    public function scopeActiveAccount($query): void
    {
        $query->whereNull('banned_at');
    }

    public function scopeDiscoverable($query): void
    {
        $query->whereNull('banned_at');
    }

    public function scopeAssignable($query): void
    {
        $query->whereNull('banned_at');
    }

    /**
     * TASK-1720 volet B — qui peut ENTRER dans une Boucle.
     *
     * ## Pourquoi une regle a part, et non `assignable()` elargi
     *
     * `assignable()` ne regarde que `banned_at`, et **quinze** surfaces s'y
     * appuient : auteur d'article, repreneur d'une Organization, candidat a un
     * transfert de propriete, listes d'administration. Y ajouter « email
     * verifie » et « compte approuve » changerait toutes ces regles d'un coup,
     * pour un besoin qui ne concerne que l'adhesion a une Boucle. Ce serait un
     * second systeme d'approbation deguise en resserrement.
     *
     * Cette regle-ci ne redeclare rien : elle COMPOSE les predicats qui
     * existent deja — `banned_at` (T1716, qui couvre aussi la fermeture
     * volontaire), `email_verified_at` (T1694) et `approved_at` (T1714).
     *
     * ## Les trois refus
     *
     *  - **banni ou ferme** : `banned_at` porte les deux etats depuis T1716 ;
     *  - **email non verifie** : on n'ouvre pas une Boucle a une adresse dont
     *    personne n'a prouve qu'elle appartient a quelqu'un ;
     *  - **approbation en attente** : la meme condition que
     *    `attendApprobation()`, ecrite en SQL — `approved_at IS NULL` **ET**
     *    l'Organization exige la validation. Un compte sans Organization n'est
     *    jamais en attente, et une Organization qui n'exige rien n'attend
     *    personne : les deux repassent.
     *
     * La traduction en SQL est deliberee : ces listes se construisent par
     * requete, et filtrer en PHP apres coup ferait payer une pagination fausse.
     */
    public function scopeEligibleForLoop($query): void
    {
        $query->whereNull('banned_at')
            ->whereNotNull('email_verified_at')
            ->where(function ($q) {
                $q->whereNotNull('approved_at')
                    ->orWhereNull('organization_id')
                    ->orWhereHas('organization', function ($org) {
                        $org->where(function ($o) {
                            $o->where('requires_account_approval', false)
                                ->orWhereNull('requires_account_approval');
                        });
                    });
            });
    }

    /**
     * TASK-1720 volet B — la MEME regle, sur une instance deja chargee.
     *
     * Le scope sert les requetes ; celle-ci sert les gardes qui tiennent deja
     * l'objet. Les deux doivent rendre le meme verdict, et c'est pourquoi
     * celle-ci est ecrite a partir des memes trois predicats — et non d'une
     * quatrieme formulation qui finirait par en diverger.
     */
    public function estEligiblePourUneBoucle(): bool
    {
        return $this->banned_at === null
            && $this->hasVerifiedEmail()
            && ! $this->attendApprobation();
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function buyerTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'buyer_id');
    }

    public function sellerTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'seller_id');
    }

    public function pointLedger(): HasMany
    {
        return $this->hasMany(PointLedger::class);
    }

    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewed_id');
    }

    public function reviewsGiven(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function recalculateRating(): void
    {
        $avg = $this->reviewsReceived()->avg('rating');
        $this->update(['rating' => $avg ? round($avg, 2) : null]);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function hasFavorited(string $serviceId): bool
    {
        return $this->favorites()->where('service_id', $serviceId)->exists();
    }

    /**
     * TASK-1693 bug V — les messages NON LUS qui doivent attirer l'attention.
     *
     * ## Deux exclusions se cumulaient, et la seconde etait invisible
     *
     * 1. `type = 'user'` ecartait deliberement les messages SYSTEME. Or ce sont
     *    eux qui portent les nouvelles qui comptent : « Nouvel échange
     *    envoyé », « Échange accepté », « L'acheteur a déclaré la prestation
     *    terminée ». Arbitrage Cyril du 21/10 : « il faut le mettre car cela
     *    attire l'attention ».
     *
     * 2. `sender_id != $this->id` les ecartait une SECONDE fois, et celle-la ne
     *    se voyait pas : `addSystemMessage()` pose `sender_id = NULL`, et en SQL
     *    `NULL != 'x'` vaut NULL — donc jamais VRAI, donc la ligne tombe.
     *    Mesure sur la base de recette : 8 messages comptes contre 17 des que
     *    les NULL sont admis. Lever la seule premiere exclusion n'aurait donc
     *    RIEN change, et aurait laisse croire le correctif applique.
     *
     * ## Pourquoi `markRead()` devait bouger en meme temps
     *
     * `MessageThread::markRead()` portait exactement la meme comparaison, et
     * ne marquait donc JAMAIS un message systeme comme lu — tous ceux de la
     * base de recette le prouvent. Compter les messages systeme sans corriger
     * cela aurait allume le badge DEFINITIVEMENT, sans aucun moyen de
     * l'eteindre. Les deux corrections ne valent qu'ensemble.
     *
     * ## Ce qui reste absent, et qu'il ne faut pas croire resolu
     *
     * Ce compteur est evalue cote serveur au rendu de la page. Il n'y a ni
     * sondage ni temps reel : un message qui arrive pendant qu'on lit un autre
     * fil n'allume le badge qu'a la navigation suivante.
     */
    public function unreadMessagesCount(): int
    {
        return Message::whereHas('transaction', function ($q) {
            $q->where('buyer_id', $this->id)->orWhere('seller_id', $this->id);
        })
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $this->id))
            ->whereNull('read_at')
            ->count();
    }

    /**
     * TASK-1373 — les notifications non lues de ce membre DANS une Organization.
     *
     * Le parametre n'a deliberement PAS de valeur par defaut. `MemberNotification`
     * ecarte volontairement `BelongsToOrganizationScope` (il retombe sur
     * `whereRaw('0 = 1')` hors requete HTTP, donc silencieusement vide dans un
     * job) : aucun scope global ne rattraperait un appelant qui oublie le
     * tenant, et la requete partirait nue.
     *
     * C'est ce qui distingue cette methode de `unreadMessagesCount()` juste
     * au-dessus, qui ne porte aucun `organization_id` : un membre present dans
     * deux Organizations y verrait un compteur agrege.
     *
     * `forRecipient()` est la seule porte de lecture du modele — jamais un
     * `where('recipient_id')` nu.
     */
    public function unreadNotificationsCount(string $organizationId): int
    {
        return MemberNotification::query()
            ->forRecipient($organizationId, (string) $this->id)
            ->unread()
            ->count();
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'badge_user', 'user_id', 'badge_id')
            ->withPivot('earned_at')
            ->orderBy('badge_user.earned_at');
    }

    public function sentReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_user_id');
    }

    public function receivedReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referred_user_id');
    }

    public function referralRewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class);
    }

    public function loopMemberships(): HasMany
    {
        return $this->hasMany(LoopMember::class);
    }

    public function coAuthoredBlogPosts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_user')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function sharedDossiers(): BelongsToMany
    {
        return $this->belongsToMany(Dossier::class, 'dossier_members')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return Storage::disk('public')->url($this->avatar);
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->full_name).'&background=6366f1&color=fff';
    }

    /**
     * Determine the post-login redirect target based on user role.
     */
    public function getLoginRedirectTarget(): string
    {
        // Global admin → admin dashboard
        if ($this->is_admin) {
            return route('admin.dashboard', absolute: false);
        }

        // Org admin (not global) → org-scoped admin dashboard
        if ($this->organization_id
            && $this->organization?->admin_id === $this->id
            && $this->organization?->is_active
        ) {
            return route('organization.admin.dashboard', [
                'organization' => $this->organization->slug,
            ], absolute: false);
        }

        // Regular user → canonical home (loops for default org, org home for scoped org)
        if ($this->organization_id && $this->organization?->is_active) {
            return canonicalHome($this->organization);
        }

        return '/dashboard';
    }

    /**
     * TASK-1714 volet 2 — ce compte attend-il la validation d'un administrateur ?
     *
     * La reponse est une fonction VIVANTE de deux faits, jamais d'un drapeau
     * fige : le reglage de l'Organization, et la decision portee par le compte.
     *
     * La consequence est voulue : desactiver le reglage laisse repasser les
     * comptes en attente, sans migration ni ecriture. Et le reactiver les
     * remet en attente. L'alternative — figer l'etat a l'inscription —
     * obligerait a ecrire en base a chaque changement de reglage, et ferait
     * diverger ce que la base dit de ce que l'ecran montre.
     *
     * Un compte sans Organization n'est jamais en attente : il n'y a pas
     * d'Organization pour exiger quoi que ce soit, et le bloquer l'enfermerait
     * sans recours.
     */
    public function attendApprobation(): bool
    {
        if ($this->approved_at !== null) {
            return false;
        }

        return $this->organization?->requires_account_approval === true;
    }

    /**
     * TASK-1716 — ce compte a-t-il ete ferme par SON PROPRIETAIRE ?
     *
     * Le discriminant que `banned_at` seul ne portait pas. Sans lui, la page
     * de connexion disait « contactez l'administrateur » a quelqu'un qui
     * venait de fermer son compte lui-meme, et l'ecran SuperAdmin affichait
     * « banni » sur une decision qu'aucun administrateur n'avait prise.
     *
     * Les DEUX colonnes sont lues, et pas seulement `self_closed_at` : la
     * methode repond « est-ce une fermeture volontaire ACTIVE ». Un compte
     * rouvert par le SuperAdmin voit les deux colonnes remises a `null`, mais
     * exiger `banned_at` ici rend la reponse juste meme si une ecriture
     * future ne nettoyait qu'une des deux — l'invariant est verifie, pas
     * suppose.
     */
    public function estFermeVolontairement(): bool
    {
        return $this->banned_at !== null && $this->self_closed_at !== null;
    }

    /**
     * TASK-1716 — l'effacement des donnees personnelles a-t-il ete demande ?
     *
     * Une DEMANDE, jamais une execution. Rien dans cette application n'efface
     * automatiquement sur ce drapeau : il rend la demande visible au
     * SuperAdmin, qui arbitre sur l'ecran de suppression de TASK-1636 ou les
     * refus metier (ledger, transactions, gouvernance de Boucle) sont deja
     * calcules.
     */
    public function aDemandeEffacement(): bool
    {
        return $this->erasure_requested_at !== null;
    }
}
