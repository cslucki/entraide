<?php

namespace App\Models;

use App\Models\Scopes\BelongsToTenantScope;
use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referral extends Model
{
    use HasFactory, HasOrganizationId, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToTenantScope);
    }

    protected $fillable = [
        'organization_id',
        'referrer_user_id',
        'referred_user_id',
        'parent_referral_id',
        'depth',
        'status',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function parentReferral(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_referral_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_referral_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class);
    }
}
