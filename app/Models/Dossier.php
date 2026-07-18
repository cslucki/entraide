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
        'name',
        'visibility',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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

    public function syncVisibility(): void
    {
        $hasMembers = $this->dossierMembers()->exists();

        $this->update([
            'visibility' => $hasMembers ? self::VISIBILITY_SHARED : self::VISIBILITY_PRIVATE,
        ]);
    }
}
