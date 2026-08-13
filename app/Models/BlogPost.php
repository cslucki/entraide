<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    use HasFactory, HasOrganizationId, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'organization_id',
        'category_id',
        'title',
        'slug',
        'summary',
        'content',
        'image',
        'status',
        'published_at',
        'views_count',
        'read_time',
        'meta_title',
        'meta_description',
        'show_toc',
        'audience',
        'listed_in_blog',
        'toc_max_level',
        'toc_navigation_enabled',
    ];

    protected $casts = [
        'listed_in_blog' => 'boolean',
        'published_at' => 'datetime',
        'views_count' => 'integer',
        'read_time' => 'integer',
        'show_toc' => 'boolean',
        'toc_max_level' => 'integer',
        'toc_navigation_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            // Le slug se derive du titre, et il doit etre UNIQUE : la contrainte
            // `blog_posts_slug_unique` est globale. Deux personnes qui creent un
            // Article « Test » le meme jour tombaient sinon sur un 23505 brut,
            // affiche tel quel a l'ecran (signale sur le Drive, TASK-1130).
            //
            // La regle vit ici, dans la primitive d'ecriture, et pas dans le
            // controleur qui se trouvait etre le premier a la rencontrer : tous
            // les chemins de creation d'Article en beneficient.
            if (empty($post->slug) && $post->title) {
                $post->slug = Str::slug($post->title);
            }

            if ($post->isDirty('slug') && filled($post->slug)) {
                $post->slug = static::slugDisponible($post->slug, $post->getKey());
            }
            // Estime le temps de lecture (200 mots/min)
            $wordCount = str_word_count(strip_tags($post->content ?? ''));
            $post->read_time = max(1, (int) ceil($wordCount / 200));
        });
    }

    /**
     * Le premier slug libre a partir de celui qu'on souhaitait.
     *
     * Les Articles supprimes comptent : leur ligne existe encore et l'index
     * unique ne fait pas de difference. Le suffixe est numerique tant qu'il
     * reste lisible, puis aleatoire — un titre tres commun ne doit pas faire
     * boucler la creation.
     */
    protected static function slugDisponible(string $souhaite, ?string $ignorerId = null): string
    {
        $base = Str::slug($souhaite) ?: 'article';
        $slug = $base;
        $suffixe = 1;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignorerId !== null, fn ($q) => $q->whereKeyNot($ignorerId))
            ->exists()) {
            $suffixe++;
            $slug = $suffixe <= 20
                ? $base.'-'.$suffixe
                : $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blog_post_tag');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class)->whereNull('parent_id')->where('is_approved', true)->latest();
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(BlogSnapshot::class);
    }

    public function coAuthors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blog_post_user')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(BlogPostAnnotation::class);
    }

    public function loops(): BelongsToMany
    {
        return $this->belongsToMany(Loop::class, 'blog_post_loop')
            ->withTimestamps();
    }

    public function todos(): HasMany
    {
        return $this->hasMany(BlogTodo::class);
    }

    public function dossierEntry(): HasOne
    {
        return $this->hasOne(DossierBlogPost::class);
    }

    public function analysisNotes(): HasMany
    {
        return $this->hasMany(BlogAnalysisNote::class);
    }

    public function isLikedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->likes()->where('user_id', $user->id)->exists();
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return Storage::disk('public')->url($this->image);
    }

    /** Who may read an article — orthogonal to its editorial status. */
    public const AUDIENCE_PUBLIC = 'public';

    public const AUDIENCE_ORGANIZATION = 'organization';

    /** Access governed by the associated Loop's own rules. */
    public const AUDIENCE_LOOP = 'loop';

    public const AUDIENCES = [self::AUDIENCE_PUBLIC, self::AUDIENCE_ORGANIZATION, self::AUDIENCE_LOOP];

    /**
     * Published articles that belong in the Blog.
     *
     * `listed_in_blog` is deliberately part of this scope rather than a filter
     * every caller must remember: a Loop's root document is published and
     * perfectly readable, but it is not a blog article and must never surface
     * in a listing, a feed, a related-posts block or a sitemap.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('listed_in_blog', true);
    }

    /** Every published article, listed or not — for direct access paths. */
    public function scopePubliclyReadable($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
