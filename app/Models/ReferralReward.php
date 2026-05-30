<?php

namespace App\Models;

use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralReward extends Model
{
    use HasFactory, HasOrganizationId, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToOrganizationScope);
    }

    protected $fillable = [
        'organization_id',
        'referral_id',
        'user_id',
        'source_user_id',
        'event_type',
        'level',
        'points',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'points' => 'integer',
            'metadata' => 'json',
        ];
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
