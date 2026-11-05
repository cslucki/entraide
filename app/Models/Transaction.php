<?php

namespace App\Models;

use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory, HasOrganizationId, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToOrganizationScope);
    }

    protected $fillable = [
        'organization_id',
        'service_id',
        'request_id',
        'buyer_id',
        'seller_id',
        'points_proposed',
        'points_agreed',
        'status',
        'buyer_confirmed_at',
        'seller_confirmed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'buyer_confirmed_at' => 'datetime',
            'seller_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'points_proposed' => 'integer',
            'points_agreed' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function pointLedgerEntries(): HasMany
    {
        return $this->hasMany(PointLedger::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function hasReviewFrom(string $userId): bool
    {
        return $this->reviews()->where('reviewer_id', $userId)->exists();
    }

    public function getOtherParticipant(User $user): User
    {
        return $user->id === $this->buyer_id ? $this->seller : $this->buyer;
    }

    /**
     * TASK-1693 bug AB — les conversations DIRECTES, et elles seules.
     *
     * « Ecrire a quelqu'un » cherchait n'importe quelle transaction entre les
     * deux personnes et rouvrait la premiere venue. On atterrissait donc dans
     * un ECHANGE en cours — avec ses points, son statut et ses boutons
     * « Confirmer / Contester » — au lieu d'une discussion.
     *
     * La condition est ecrite ici, a cote d'`isDirectConversation()` qui la
     * dit deja pour une ligne donnee : les laisser diverger, c'est se
     * condamner a ce qu'un ecran juge « directe » une conversation que la
     * requete n'aurait pas ramenee.
     */
    public function scopeDirectConversation($query): void
    {
        $query->whereNull('service_id')
            ->whereNull('request_id')
            ->where('points_proposed', 0);
    }

    /**
     * Les transactions qui lient ces deux personnes, dans un sens ou l'autre.
     *
     * Les parentheses explicites sont une ceinture, pas une bretelle : Laravel
     * groupe DEJA les conditions d'un scope (`Builder::callScope()` appelle
     * `addNewWheresWithinGroup`), et les deux ecritures rendent ici un SQL
     * IDENTIQUE — verifie par `toSql()`. Le risque de precedence existe pour
     * un `where(...)->orWhere(...)` ecrit en ligne dans un controleur, ce
     * qu'etait la version d'origine ; il disparait des lors que la regle vit
     * dans un scope.
     */
    public function scopeBetween($query, string $unId, string $autreId): void
    {
        $query->where(function ($q) use ($unId, $autreId) {
            $q->where(function ($q) use ($unId, $autreId) {
                $q->where('buyer_id', $unId)->where('seller_id', $autreId);
            })->orWhere(function ($q) use ($unId, $autreId) {
                $q->where('buyer_id', $autreId)->where('seller_id', $unId);
            });
        });
    }

    public function isDirectConversation(): bool
    {
        return $this->service_id === null
            && $this->request_id === null
            && (int) $this->points_proposed === 0;
    }

    /**
     * TASK-1693 bug S — QUI a propose cet echange, et QUI doit en decider.
     *
     * ## Le defaut que ces deux methodes ferment
     *
     * Le modele sous-jacent est « l'acheteur propose, le vendeur accepte ». Il
     * colle parfaitement a une OFFRE DE SERVICE : je vois ton annonce, je te
     * propose 20 points, tu acceptes. L'acheteur est bien le proposant, le
     * vendeur bien le decideur.
     *
     * Mais sur une DEMANDE D'AIDE, la proposition vient de l'autre cote :
     * `TransactionController` inverse les roles economiques (celui qui rend le
     * service encaisse les points, donc il est « vendeur »), et personne n'a
     * inverse le role DECISIONNEL qui va avec. « Le vendeur accepte » pointait
     * alors sur le proposant lui-meme : l'aidant devait accepter sa propre
     * offre, pendant que la personne concernee ne voyait qu'« Ajuster » et
     * « Annuler » (recette Cyril, 21/10).
     *
     * ## Pourquoi aucune colonne nouvelle
     *
     * Le sens de la proposition est deja DEDUCTIBLE sans ambiguite de la donnee
     * existante : `request_id` renseigne veut dire « quelqu'un a repondu a une
     * demande », donc le proposant est le vendeur. Ajouter un `proposed_by`
     * demanderait une migration ET une reprise de l'existant pour une
     * information deja presente.
     *
     * ## Ce que cela ne touche PAS
     *
     * Seule la decision sur la PROPOSITION (`approve` / `refuse`) etait
     * inversee. La choregraphie de FIN — l'acheteur declare la prestation
     * terminee, le vendeur confirme — est juste dans les deux sens : celui qui
     * a recu l'aide declare, celui qui l'a rendue confirme, et les points vont
     * de l'acheteur au vendeur. Elle reste intouchee.
     *
     * Les conversations directes (ni service ni demande) retombent sur la
     * branche historique : decideur = vendeur, comportement inchange. Leurs
     * boutons sont de toute facon masques.
     */
    /**
     * TASK-1754 (§4) — l'echange ACTIF d'un proposant sur une annonce donnee.
     *
     * ## Pourquoi cette methode existe, et pourquoi ICI
     *
     * La meme question se pose a TROIS endroits : la garde anti-doublon de
     * `TransactionController::store()`, et les deux pages d'annonce qui doivent
     * desormais savoir qu'un echange existe deja (`ServiceController::show()`,
     * `RequestController::show()`).
     *
     * Recopiee trois fois, elle aurait diverge — et la divergence serait MUETTE :
     * une vue qui croit qu'il n'y a pas d'echange affiche le formulaire, le POST
     * part, et la garde le refuse. On retomberait exactement sur le cul-de-sac
     * que ce chantier repare.
     *
     * ## La colonne du proposant n'est pas la meme des deux cotes
     *
     * Cote PROPOSITION, celui qui repond recoit l'aide : il est `buyer_id`.
     * Cote DEMANDE, celui qui repond apporte l'aide : il est `seller_id`.
     * C'est l'inversion deja etablie par TASK-1693 bug S, et c'est precisement
     * ce qu'une recopie a la main rate.
     *
     * ## « Actif » veut dire : qui empeche d'en ouvrir un autre
     *
     * `pending` et `accepted` seulement. Un echange `refused`, `cancelled` ou
     * `completed` ne doit RIEN empecher : on doit pouvoir re-demander de l'aide
     * a quelqu'un qui a decline, ou redemander la meme aide plus tard.
     */
    public static function echangeActif(string $proposantId, ?string $serviceId, ?string $requestId): ?self
    {
        if ($serviceId === null && $requestId === null) {
            return null;
        }

        $colonneDuProposant = $serviceId !== null ? 'buyer_id' : 'seller_id';

        return static::where($colonneDuProposant, $proposantId)
            ->whereIn('status', ['pending', 'accepted'])
            ->when($serviceId !== null,
                fn ($q) => $q->where('service_id', $serviceId),
                fn ($q) => $q->where('request_id', $requestId),
            )
            ->latest()
            ->first();
    }

    public function proposerId(): string
    {
        return $this->request_id !== null ? $this->seller_id : $this->buyer_id;
    }

    /**
     * TASK-1755 (§1.2 du CDC) — les deux roles HUMAINS, sans colonne nouvelle.
     *
     * Le modele parle d'acheteur et de vendeur. Les personnes, elles, aident ou
     * sont aidees. La correspondance est stable et sans ambiguite — celui qui
     * ENCAISSE les points est celui qui APPORTE l'aide — mais elle est contre
     * intuitive a relire : `seller` pour « l'aidant » surprend a chaque lecture,
     * et c'est ainsi qu'on finit par inverser les deux.
     *
     * Ces deux methodes sont la seule traduction du vocabulaire economique vers
     * le vocabulaire humain. Tout ce qui parle a l'utilisateur passe par elles.
     *
     * A ne pas confondre avec `proposerId()` / `decisionMakerId()` : ceux-la
     * disent qui a PROPOSE et qui DECIDE, ce qui s'inverse selon qu'on repond a
     * une proposition ou a une demande (T1693 bug S). Le sens de l'aide, lui, ne
     * s'inverse jamais.
     */
    public function estLAidant(User|string|null $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $id !== null && $this->seller_id === $id;
    }

    /** Celui qui RECOIT l'aide, et qui paie. */
    public function estLAide(User|string|null $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $id !== null && $this->buyer_id === $id;
    }

    /** L'autre partie : celle qui n'a pas propose, donc celle qui decide. */
    public function decisionMakerId(): string
    {
        return $this->request_id !== null ? $this->buyer_id : $this->seller_id;
    }

    public function getSubjectAttribute(): string
    {
        if ($this->isDirectConversation()) {
            return __('messages.direct_conversation');
        }

        if ($this->service) {
            return $this->service->title;
        }
        if ($this->serviceRequest) {
            return $this->serviceRequest->title;
        }

        return __('messages.exchange_subject', ['id' => substr($this->id, 0, 8)]);
    }

    /**
     * TASK-1754 (§3.4, obstacle n°1) — le libelle passe par le systeme de langue.
     *
     * ## Le defaut repare
     *
     * Ces libelles etaient ecrits EN FRANCAIS, EN DUR, dans le modele — et ils
     * doublonnaient `lang/{fr,en}/messages.php`, qui disait deja la meme chose.
     * Deux consequences, toutes deux constatees :
     *
     * 1. le renommage du lexique d'entraide aurait du etre fait DEUX FOIS, et
     *    la seconde aurait ete oubliee ;
     * 2. l'anglais etait casse la ou cet attribut sert : un anglophone lisait
     *    « En attente » dans son export CSV et dans ses notifications.
     *
     * Le `default` rend le statut brut : une valeur hors des six du `CHECK` de
     * PostgreSQL ne doit pas devenir une clef de traduction introuvable, qui
     * s'afficherait « messages.status.xxx » a l'ecran.
     */
    public function getStatusLabelAttribute(): string
    {
        $clef = 'messages.status.'.$this->status;
        $libelle = __($clef);

        return $libelle === $clef ? $this->status : $libelle;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'orange',
            'accepted' => 'blue',
            'buyer_done' => 'purple',
            'completed' => 'green',
            'refused', 'cancelled' => 'red',
            default => 'gray',
        };
    }
}
