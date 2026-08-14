<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class Loop extends Model
{
    /** @use HasFactory<LoopFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    public const ACCESS_OPEN = 'open';

    public const ACCESS_REQUEST = 'request';

    public const ACCESS_INVITATION = 'invitation';

    public const ACCESS_MODES = [self::ACCESS_OPEN, self::ACCESS_REQUEST, self::ACCESS_INVITATION];

    /** A Loop carries at most 3 domains (Annuaire referential) — TASK-1076. */
    public const MAX_DOMAINS = 3;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'tagline',
        'cover_image_path',
        'type',
        'status',
        'archived_at',
        'archived_by',
        'visibility',
        'access_mode',
        'created_by',
        'member_ai_profile_id',
        'manifesto_blog_post_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'status' => 'string',
            'archived_at' => 'datetime',
            'visibility' => 'string',
            'access_mode' => 'string',
        ];
    }

    public static function isValidAccessMode(string $mode): bool
    {
        return in_array($mode, self::ACCESS_MODES, true);
    }

    public function isOpenAccess(): bool
    {
        return $this->access_mode === self::ACCESS_OPEN;
    }

    public function isRequestAccess(): bool
    {
        return $this->access_mode === self::ACCESS_REQUEST;
    }

    public function isInvitationAccess(): bool
    {
        return $this->access_mode === self::ACCESS_INVITATION;
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        if (! $this->cover_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->cover_image_path);
    }

    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopePrivate($query)
    {
        return $query->where('visibility', 'private');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function hasContent(): bool
    {
        return $this->messages()->exists();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LoopMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(LoopMember::class)->where('status', 'active');
    }

    /**
     * The active owner LoopMember — used to present "who runs this Loop" in
     * the catalog and presentation views. May be null if ownership was
     * transferred imperfectly or the owner left (edge case, handled
     * gracefully in views rather than enforced here).
     */
    /**
     * Manifesto body, sanitised for display.
     *
     * Same allowlist as the workspace card: the Blog editor sanitises on save,
     * but the starter content is inserted directly, and an admin page is not the
     * place to trust that either.
     */
    public function manifestoHtmlForAdmin(): string
    {
        $manifesto = $this->manifesto;

        if (! $manifesto) {
            return '';
        }

        // h1 included since TASK-1084 — a root document written from a
        // Markdown paste opens with one.
        $allowed = ['h1', 'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u', 'br', 'a', 'code', 'pre', 'blockquote'];

        $html = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', '', (string) $manifesto->content);
        $html = strip_tags((string) $html, '<'.implode('><', $allowed).'>');
        $html = preg_replace('/<(\w+)\s[^>]*on\w+\s*=\s*["\'][^"\']*["\']/i', '<$1', $html);

        return (string) preg_replace('/<(\w+)\s[^>]*(?:javascript|data)\s*:\s*[^"\'>\s]+/i', '<$1', $html);
    }

    /**
     * Absolute URL of this Loop's workspace, scoped to its own Organization.
     *
     * `route('loops.show', $loop)` resolves the Organization from the *current*
     * request context, which is wrong anywhere a Loop of another Organization is
     * listed — the platform admin browsing every Loop is exactly that case, and
     * the link landed on their own Organization instead.
     */
    public function workspaceUrl(): string
    {
        $slug = $this->organization?->slug;

        if ($slug && Route::has('organization.loops.show')) {
            return route('organization.loops.show', ['organization' => $slug, 'loop' => $this->id]);
        }

        return route('loops.show', $this);
    }

    /**
     * Every active owner. A Loop may have several; they all have equal rights.
     *
     * **Depart avec `id` en second critere.** `joined_at` est a la seconde :
     * deux personnes promues dans la meme seconde — ce qui arrive a chaque
     * fixture et a chaque promotion en lot — s'egalent, et PostgreSQL rend
     * alors l'ordre qu'il veut. L'identifiant est un UUIDv7, donc ordonne dans
     * le temps : il departage sans rien changer a la chronologie.
     */
    public function owners(): HasMany
    {
        return $this->hasMany(LoopMember::class)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->orderBy('joined_at')
            ->orderBy('id');
    }

    /**
     * @deprecated TASK-1079 CP5ter — display compatibility only.
     *
     * A Loop can have several owners and there is no business notion of a
     * "primary owner". This relation exists solely so the screens written when
     * a single owner was assumed keep rendering; il est ordonne par `joined_at`
     * **puis par `id`** pour que la valeur soit reellement deterministe.
     *
     * La version precedente s'arretait a `joined_at` en affirmant que cela
     * suffisait. C'etait faux sur PostgreSQL, ou l'horodatage est a la seconde :
     * des ex aequo rendaient la main a la base, c'est-a-dire exactement ce que
     * le commentaire pretendait avoir evite.
     *
     * **Never use it to authorise anything** — LoopPermissionResolver is the
     * only authority. New screens use owners().
     */
    public function owner(): HasOne
    {
        return $this->hasOne(LoopMember::class)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->orderBy('joined_at')
            ->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LoopMessage::class);
    }

    public function roadmapItems(): HasMany
    {
        return $this->hasMany(LoopRoadmapItem::class);
    }

    /**
     * Domains (Annuaire referential) this Loop is tagged with — 0 to 3, scoped
     * to the Loop's own Organization. Deliberately not linked to Skill.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_loop')->withTimestamps();
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(LoopJoinRequest::class);
    }

    public function pendingJoinRequests(): HasMany
    {
        return $this->hasMany(LoopJoinRequest::class)->where('status', LoopJoinRequest::STATUS_PENDING);
    }

    /**
     * The designated primary Manifesto (a BlogPost). Null if none is designated
     * or if the designated post has been (soft) deleted (SoftDeletes scope).
     */
    /** Targeted e-mail invitations sent for this Loop (TASK-1077). */
    public function invitations(): HasMany
    {
        return $this->hasMany(LoopInvitation::class);
    }

    /** Cards enabled on this Loop (TASK-1079); the catalogue lives in config. */
    public function cards(): HasMany
    {
        return $this->hasMany(LoopCard::class);
    }

    /** Dossiers documents linked as sources of this Loop's Manifesto. */
    public function manifestoSources(): HasMany
    {
        return $this->hasMany(LoopManifestoSource::class);
    }

    /**
     * The Manifesto is only public material once a human published it, and only
     * on a Loop that is not private. Confidentiality of the Loop always wins:
     * this is never a fallback for a missing description.
     */
    public function hasPublicManifesto(): bool
    {
        return $this->visibility !== 'private'
            && $this->manifesto !== null
            && $this->manifesto->status === 'published';
    }

    public function manifesto(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'manifesto_blog_post_id');
    }

    public function memberAiProfile(): BelongsTo
    {
        return $this->belongsTo(MemberAiProfile::class);
    }

    public function isAiAgent(): bool
    {
        return $this->type === 'ai_agent';
    }
}
