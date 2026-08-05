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

    /** The owner, plus whoever is explicitly listed in dossier_members. */
    public const VISIBILITY_PRIVATE = 'private';

    /** Every active member of the same Organization. Never another one. */
    public const VISIBILITY_ORGANIZATION = 'organization';

    /** Governed by the rules of the Loop it is shared with. */
    public const VISIBILITY_LOOP = 'loop';

    /**
     * Historical value. Never a choice — it was written by syncVisibility()
     * whenever a Dossier had members. Migrated to `private`, kept only so an
     * unmigrated row cannot break a comparison.
     *
     * @deprecated use VISIBILITY_PRIVATE with dossier_members
     */
    public const VISIBILITY_SHARED = 'shared';

    public const VISIBILITIES = [self::VISIBILITY_PRIVATE, self::VISIBILITY_ORGANIZATION, self::VISIBILITY_LOOP];

    protected $fillable = [
        'organization_id',
        'owner_id',
        'loop_id',
        'shared_with_loop_id',
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

    /** The Loop that OWNS this Dossier — its root Dossier. */
    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    /**
     * The Loop this personal Dossier is SHARED with.
     *
     * Not the same thing as loop(): the owner stays the owner, and the Dossier
     * never becomes that Loop's root Dossier.
     */
    public function sharedWithLoop(): BelongsTo
    {
        return $this->belongsTo(Loop::class, 'shared_with_loop_id');
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
    /**
     * Historical hook, now almost inert — and that is the point.
     *
     * It used to rewrite `visibility` from the mere presence of members, which
     * is why a user could never keep a chosen value: the column looked like a
     * setting and behaved like a computed field. Adding someone to a private
     * Dossier is not a change of audience, so it no longer touches anything.
     *
     * It survives only to normalise the legacy `shared` value on rows that
     * predate the migration.
     */
    public function syncVisibility(): void
    {
        if ($this->isLoopDossier() || $this->visibility !== self::VISIBILITY_SHARED) {
            return;
        }

        $this->update(['visibility' => self::VISIBILITY_PRIVATE]);
    }

    /**
     * The visibility actually in force.
     *
     * A root Dossier has none of its own: it is its Loop's, evaluated on read.
     * Nothing is duplicated and nothing is synchronised, so a Loop that becomes
     * private takes its Dossier with it immediately.
     */
    public function effectiveVisibility(): string
    {
        return $this->isLoopDossier() ? self::VISIBILITY_LOOP : $this->visibility;
    }

    /** True when the user may not choose this Dossier's audience. */
    public function visibilityIsInherited(): bool
    {
        return $this->isLoopDossier();
    }
}
