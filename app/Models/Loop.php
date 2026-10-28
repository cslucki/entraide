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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

    /*
     * TASK-1717 — PAS de `resolveRouteBinding()` ni de `getRouteKey()` ici,
     * et c'est une decision prise APRES les avoir ecrits puis mesures.
     *
     * 1. La resolution « slug OU identifiant » EXISTE DEJA, dans
     *    `AppServiceProvider` : `Route::bind('loop', ...)`. Une liaison
     *    explicite prime sur la liaison implicite du modele, donc une
     *    surcharge ici ne serait jamais appelee — du code mort qui ferait
     *    croire a une seconde autorite.
     *
     * 2. Surcharger `getRouteKey()` pour rendre le slug a CASSE 40+ tests.
     *    Le binder ci-dessus refuse volontairement un slug quand la route ne
     *    porte pas de parametre `{organization}` — « un slug n'a de sens que
     *    dans une Organization », le meme pouvant exister dans plusieurs.
     *    Or `loops.show` (forme nue) et les vingt routes `admin.loops.*` n'en
     *    portent aucun : elles recevaient le slug et rendaient 404.
     *
     * L'URL lisible se construit donc la ou l'Organization est connue —
     * `publicUrl()` ci-dessous et `LoopController::loopRoute()` — jamais par
     * une surcharge globale. Meme conclusion qu'en TASK-1693 pour `{user}`,
     * ou `profileRouteKey()` existe precisement pour ne pas toucher aux 30
     * routes d'administration.
     */

    /**
     * TASK-1717 — les segments statiques que `/loops/{loop}` ne doit pas voler.
     *
     * `/loops/create` est declare AVANT `/loops/{loop}` : une Boucle sluggee
     * `create` serait parfaitement valide en base et DEFINITIVEMENT
     * inatteignable par son URL.
     *
     * @var list<string>
     */
    public const SLUGS_RESERVES = ['create'];

    /**
     * TASK-1717 — ce qu'un slug de Boucle a le droit d'etre, en UN seul endroit.
     *
     * Posee sur le modele parce que TROIS ecrans la lisent desormais : la
     * creation et « Modifier la boucle » cote membre (`LoopController`), et la
     * fiche du SuperAdmin (`AdminLoopController`). La recopier aurait garanti
     * que les trois divergent — sur la forme acceptee, sur les mots reserves,
     * ou sur la borne d'unicite.
     *
     * Quatre refus, et deux ne sont pas evidents :
     *
     *  - la FORME (`a-z`, chiffres, tirets simples) : un slug est une portion
     *    d'URL, pas un titre. `Str::slug()` produit deja exactement cela a la
     *    creation automatique ;
     *  - les segments RESERVES, voir `SLUGS_RESERVES` ;
     *  - la forme UUID : `Route::bind('loop', ...)` teste `Str::isUuid()`
     *    AVANT de chercher un slug. Un slug en forme d'UUID serait donc
     *    cherche dans `id` et ne resoudrait jamais sa propre Boucle ;
     *  - l'UNICITE **par Organization**, jamais globale. `$organizationId` est
     *    donc un parametre et non `currentOrganization()` : depuis
     *    l'administration, le SuperAdmin modifie une Boucle d'un AUTRE tenant
     *    que le sien, et borner sur le sien y verifierait la mauvaise chose.
     *
     * `nullable` est delibere : laisser le champ vide garde le comportement
     * existant — slug derive du nom a la creation, slug INCHANGE a la
     * modification.
     *
     * @return array<int, mixed>
     */
    public static function slugRules(string $organizationId, ?self $loop = null): array
    {
        return [
            'nullable',
            'string',
            'max:80',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'not_in:'.implode(',', self::SLUGS_RESERVES),
            function (string $attribut, mixed $valeur, callable $echec) {
                if (is_string($valeur) && Str::isUuid($valeur)) {
                    $echec(__('loops.slug_cannot_be_uuid'));
                }
            },
            Rule::unique('loops', 'slug')
                ->where(fn ($query) => $query->where('organization_id', $organizationId))
                ->ignore($loop?->getKey()),
        ];
    }

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
