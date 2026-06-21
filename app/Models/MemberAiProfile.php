<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberAiProfile extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'member_ai_profiles';

    const STATUS_DRAFT = 'draft';

    const STATUS_READY_FOR_GENERATION = 'ready_for_generation';

    const STATUS_GENERATED = 'generated';

    const STATUS_PENDING_VALIDATION = 'pending_validation';

    const STATUS_PUBLISHED = 'published';

    const STATUS_DISABLED = 'disabled';

    public static array $statuses = [
        self::STATUS_DRAFT,
        self::STATUS_READY_FOR_GENERATION,
        self::STATUS_GENERATED,
        self::STATUS_PENDING_VALIDATION,
        self::STATUS_PUBLISHED,
        self::STATUS_DISABLED,
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'locale',
        'member_profile_summary',
        'service_scope',
        'experience_context',
        'preferred_contact_action',
        'tone',
        'generated_summary',
        'target_audience',
        'problems_helped',
        'skills',
        'help_types',
        'boundaries',
        'good_request_examples',
        'bad_request_examples',
        'wizard_state',
        'metadata',
        'validated_at',
        'published_at',
        'generated_at',
        'disabled_at',
        'last_saved_at',
    ];

    protected function casts(): array
    {
        return [
            'target_audience' => 'array',
            'problems_helped' => 'array',
            'skills' => 'array',
            'help_types' => 'array',
            'boundaries' => 'array',
            'good_request_examples' => 'array',
            'bad_request_examples' => 'array',
            'wizard_state' => 'array',
            'metadata' => 'array',
            'validated_at' => 'datetime',
            'published_at' => 'datetime',
            'generated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_saved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopePublished($query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeForUser($query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    public function scopeForOrganization($query, Organization $organization): void
    {
        $query->where('organization_id', $organization->id);
    }

    public function loops(): HasMany
    {
        return $this->hasMany(Loop::class, 'member_ai_profile_id');
    }
}
