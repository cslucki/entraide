<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dossier extends Model
{
    use HasFactory;
    use HasOrganizationId;
    use HasUuids;
    use SoftDeletes;

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_SHARED = 'shared';

    protected $fillable = [
        'organization_id',
        'owner_id',
        'loop_id',
        'root_blog_post_id',
        'name',
        'visibility',
    ];

    /** True when this Dossier is held by a Loop rather than by a person. */
    public function isLoopDossier(): bool
    {
        return $this->loop_id !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function rootBlogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'root_blog_post_id');
    }

    public function dossierBlogPosts(): HasMany
    {
        return $this->hasMany(DossierBlogPost::class)->orderBy('position')->orderBy('created_at');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'dossier_blog_posts')
            ->withPivot('id', 'organization_id', 'added_by', 'position')
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderBy('blog_posts.created_at');
    }

    public function dossierMembers(): HasMany
    {
        return $this->hasMany(DossierMember::class);
    }

    public function articleSeries(): HasMany
    {
        return $this->hasMany(ArticleSeries::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(DossierFile::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dossier_members')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function isMember(string $userId): bool
    {
        return $this->dossierMembers()->where('user_id', $userId)->exists();
    }

    public function memberRoleFor(string $userId): ?string
    {
        $member = $this->dossierMembers()->where('user_id', $userId)->first();

        return $member?->role;
    }

    /**
     * Visibility derived from membership — for personal Dossiers only.
     *
     * A Loop's Dossier takes its confidentiality from the Loop, and holds no
     * rows in dossier_members at all, so deriving anything from them would
     * flip it to private on every call.
     */
    public function syncVisibility(): void
    {
        if ($this->isLoopDossier()) {
            return;
        }

        $hasMembers = $this->dossierMembers()->exists();

        $this->update([
            'visibility' => $hasMembers ? self::VISIBILITY_SHARED : self::VISIBILITY_PRIVATE,
        ]);
    }
}
