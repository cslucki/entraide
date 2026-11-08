<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TASK-1786 — un commentaire depose sous une proposition de service ou une
 * demande d'aide.
 *
 * ## Ce qu'un commentaire N'EST PAS
 *
 * Ni une demande de transaction, ni une acceptation d'aide, ni un engagement de
 * temps. Rien ici ne touche au registre des points ni aux transactions : ce
 * modele n'a aucune relation vers eux, et c'est deliberé.
 *
 * ## Pas de portee globale d'Organization, et pourquoi
 *
 * `Service` et `ServiceRequest` portent `BelongsToOrganizationScope` ; les deux
 * modeles de COMMENTAIRES du depot (`BlogComment`, `FeedPostComment`) ne le
 * portent pas. On suit les seconds, pour une raison mesuree ailleurs : sous
 * worker, cette portee **echoue FERME** faute de `current_organization`, et
 * toute lecture oubliant `withoutGlobalScopes()` rend « introuvable » **en
 * silence** — exactement le defaut que `NotificationEmailDeliverer` documente
 * s'etre pris deux fois.
 *
 * La frontiere de tenant n'est donc pas deleguee a une portee implicite : elle
 * est REPOSEE explicitement a chaque lecture, par `pourAnnonce()` ici et par un
 * `where('organization_id', …)` partout ailleurs. Une frontiere qu'on voit vaut
 * mieux qu'une frontiere qui disparait selon le contexte d'execution.
 *
 * ## `organization_id` vient de l'ANNONCE
 *
 * `HasOrganizationId` le remplit depuis `current_organization` quand il est
 * vide, ce qui marche en requete web et pas sous worker. L'autorite est
 * l'Organization de l'annonce commentee, et les producteurs la posent
 * explicitement. Le trait reste present comme filet, jamais comme source.
 */
class EntraideComment extends Model
{
    use HasFactory, HasOrganizationId, HasUuids, SoftDeletes;

    /** Les deux seuls types d'annonce commentables. Toute autre valeur est refusee. */
    public const TYPES_COMMENTABLES = [
        Service::class,
        ServiceRequest::class,
    ];

    /** Plafond du texte, identique a celui des commentaires de blog. */
    public const CONTENU_MAX = 2000;

    protected $fillable = [
        'organization_id',
        'commentable_type',
        'commentable_id',
        'user_id',
        'content',
        'is_approved',
        'moderated_by',
        'moderated_at',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'moderated_at' => 'datetime',
    ];

    /** L'annonce commentee : une `Service` ou une `ServiceRequest`. */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Le SuperAdmin qui a modere, s'il y en a un. */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * Les commentaires d'une annonce, bornes a son Organization.
     *
     * L'Organization est prise sur l'ANNONCE et non sur le contexte de la
     * requete : c'est ce qui rend cette lecture juste aussi bien dans une
     * requete web que sous worker, ou aucun contexte n'existe.
     *
     * L'ordre est CHRONOLOGIQUE — un fil de suggestions se lit du plus ancien au
     * plus recent, comme les reponses d'un commentaire de blog, et a la
     * difference de la liste des articles.
     */
    public function scopePourAnnonce(Builder $query, Service|ServiceRequest $annonce): Builder
    {
        return $query
            ->where('organization_id', $annonce->organization_id)
            ->where('commentable_type', $annonce->getMorphClass())
            ->where('commentable_id', $annonce->getKey())
            ->where('is_approved', true)
            ->oldest();
    }
}
