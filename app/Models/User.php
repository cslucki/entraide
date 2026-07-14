<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use App\Services\ReferralCodeGenerator;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string|null $organization_id
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasOrganizationId, HasUuids, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (! $user->isDirty('referral_code')) {
                $user->referral_code = app(ReferralCodeGenerator::class)->generate($user);
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'name',
        'first_name',
        'email',
        'password',
        'avatar',
        'bio',
        'location',
        'phone',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country_code',
        'preferred_locale',
        'membership_value',
        'show_email',
        'show_phone',
        'website',
        'linkedin_url',
        'points_balance',
        'is_available',
        'is_admin',
        'banned_at',
        'rating',
        'referral_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_available' => 'boolean',
            'is_admin' => 'boolean',
            'show_email' => 'boolean',
            'show_phone' => 'boolean',
            'banned_at' => 'datetime',
            'points_balance' => 'integer',
            'rating' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    public function getPublicLocationAttribute(): ?string
    {
        if (! $this->city) {
            return null;
        }

        if ($this->organization?->show_country === false || ! $this->country) {
            return $this->city;
        }

        return $this->city.', '.$this->country->getLocalizedName();
    }

    public function getFullNameAttribute(): string
    {
        if ($this->first_name) {
            return trim($this->first_name.' '.$this->name);
        }

        return $this->name ?? '';
    }

    public function getInitialsAttribute(): string
    {
        if ($this->first_name) {
            $first = strtoupper(mb_substr($this->first_name, 0, 1));
            $last = strtoupper(mb_substr($this->name, 0, 1));

            return $first.$last;
        }

        $parts = preg_split('/\s+/', trim($this->name ?? ''));
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(mb_substr($part, 0, 1));
        }

        return $initials ?: '?';
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function buyerTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'buyer_id');
    }

    public function sellerTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'seller_id');
    }

    public function pointLedger(): HasMany
    {
        return $this->hasMany(PointLedger::class);
    }

    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewed_id');
    }

    public function reviewsGiven(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function recalculateRating(): void
    {
        $avg = $this->reviewsReceived()->avg('rating');
        $this->update(['rating' => $avg ? round($avg, 2) : null]);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function hasFavorited(string $serviceId): bool
    {
        return $this->favorites()->where('service_id', $serviceId)->exists();
    }

    public function unreadMessagesCount(): int
    {
        return Message::whereHas('transaction', function ($q) {
            $q->where('buyer_id', $this->id)->orWhere('seller_id', $this->id);
        })
            ->where('sender_id', '!=', $this->id)
            ->whereNull('read_at')
            ->where('type', 'user')
            ->count();
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'badge_user', 'user_id', 'badge_id')
            ->withPivot('earned_at')
            ->orderBy('badge_user.earned_at');
    }

    public function sentReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_user_id');
    }

    public function receivedReferrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referred_user_id');
    }

    public function referralRewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class);
    }

    public function loopMemberships(): HasMany
    {
        return $this->hasMany(LoopMember::class);
    }

    public function coAuthoredBlogPosts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_user')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return Storage::disk('public')->url($this->avatar);
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->full_name).'&background=6366f1&color=fff';
    }

    /**
     * Determine the post-login redirect target based on user role.
     */
    public function getLoginRedirectTarget(): string
    {
        // Global admin → admin dashboard
        if ($this->is_admin) {
            return route('admin.dashboard', absolute: false);
        }

        // Org admin (not global) → org-scoped admin dashboard
        if ($this->organization_id
            && $this->organization?->admin_id === $this->id
            && $this->organization?->is_active
        ) {
            return route('organization.admin.dashboard', [
                'organization' => $this->organization->slug,
            ], absolute: false);
        }

        // Regular user → canonical home (loops for default org, org home for scoped org)
        if ($this->organization_id && $this->organization?->is_active) {
            return canonicalHome($this->organization);
        }

        return '/dashboard';
    }
}
