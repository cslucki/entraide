<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use App\Services\LoopMessageService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class FeedPost extends Model
{
    use HasFactory, HasOrganizationId, HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (FeedPost $post) {
            if ($post->isDirty('pinned_at') && $post->pinned_at !== null) {
                static::where('organization_id', $post->organization_id)
                    ->whereNotNull('pinned_at')
                    ->where('id', '!=', $post->id)
                    ->update(['pinned_at' => null, 'pinned_by_id' => null]);
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        // TASK-1752 — la reference de l'objet concerne. Le couple porte
        // l'idempotence (contrainte unique en base) et le lien cliquable.
        'object_type',
        'object_id',
        'title',
        'content',
        'image_path',
        'url_preview',
        'status',
        // TASK-1752 — l'audience. `organization` par defaut ; `loop` restreint
        // aux membres de `loop_id`.
        'visibility',
        'loop_id',
        'pinned_at',
        'pinned_by_id',
        'scheduled_at',
        'published_at',
        'loop_message',
    ];

    protected $casts = [
        'pinned_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'url_preview' => 'array',
    ];

    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactionable');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeedPostComment::class)
            ->where('is_approved', true)
            ->latest();
    }

    public function loops(): BelongsToMany
    {
        return $this->belongsToMany(Loop::class, 'feed_post_loop')
            ->withTimestamps();
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopePinned($query)
    {
        return $query->whereNotNull('pinned_at');
    }

    public function scopeForOrganization($query, string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeVisibleInFeed($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * TASK-1752 — le filtre d'AUDIENCE, transpose de `agendaFor()`.
     *
     * ## Ce qu'il filtre, et ce qu'il ne filtre pas
     *
     * Il ne remplace PAS la portee d'Organization : celle-ci reste posee sur
     * la requete, en dehors de ce scope. C'est elle qui garantit qu'un Flux ne
     * montre qu'un tenant, et rien ici ne l'effleure.
     *
     * Ce scope repond a une seule question : « cette ligne s'adresse-t-elle a
     * cette personne ? ». Une ligne `organization` s'adresse a tout le monde ;
     * une ligne `loop` aux seuls membres ACTIFS de sa Boucle.
     *
     * ## Le SuperAdmin plateforme voit tout le tenant
     *
     * Meme raison qu'en T1658 sur l'agenda : entre dans une Organization pour
     * l'inspecter, il n'y verrait que les lignes remontees au niveau
     * Organization. Acceder sans voir n'est pas inspecter. Le bypass porte sur
     * l'APPARTENANCE, jamais sur le tenant.
     *
     * ## Les annonces humaines restent visibles de tous
     *
     * Elles portent `visibility = organization` par defaut de colonne, donc la
     * premiere branche les prend. Aucune ligne existante ne devient invisible
     * du fait de cette TASK — c'est la condition pour que la migration soit
     * additive au sens plein.
     */
    public function scopeVisibleTo($query, ?\App\Models\User $user)
    {
        if ($user === null) {
            return $query->where('visibility', 'organization');
        }

        if ($user->is_admin) {
            return $query;
        }

        $boucles = \App\Models\LoopMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('loop_id');

        return $query->where(function ($q) use ($boucles) {
            $q->where('visibility', 'organization')
                ->orWhere(function ($interne) use ($boucles) {
                    $interne->where('visibility', 'loop')
                        ->whereIn('loop_id', $boucles);
                });
        });
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function scopeDueForPublication($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now());
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function announcementUrl(): string
    {
        $org = $this->organization;
        $route = $org?->is_default ? 'flux.show' : 'organization.flux.show';
        $params = $route === 'organization.flux.show' ? ['organization' => $org?->slug, 'feedPost' => $this->id] : ['feedPost' => $this->id];

        return route($route, $params);
    }

    public function broadcastToAssociatedLoops(LoopMessageService $service, User $user): void
    {
        $body = $this->loop_message
            ?: ($this->title ? "**{$this->title}**\n\n" : '').$this->content;

        $body .= "\n\n[Voir l'annonce](".$this->announcementUrl().')';

        foreach ($this->loops as $loop) {
            try {
                $message = $service->sendUserMessage(
                    $loop,
                    $user,
                    $body,
                    metadata: ['feed_post_id' => $this->id, 'type' => 'announcement'],
                );

                if ($this->isPinned()) {
                    $message->pin($user);
                }
            } catch (\RuntimeException) {
            }
        }
    }
}
