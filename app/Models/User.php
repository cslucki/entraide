<?php

namespace App\Models;

use App\Models\Community;
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

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, HasApiTokens, Notifiable;

    protected $fillable = [
        'community_id',
        'name',
        'email',
        'password',
        'avatar',
        'bio',
        'location',
        'phone',
        'show_email',
        'show_phone',
        'website',
        'linkedin_url',
        'points_balance',
        'is_available',
        'is_admin',
        'banned_at',
        'rating',
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

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
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
        return \App\Models\Message::whereHas('transaction', function ($q) {
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

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return Storage::disk('public')->url($this->avatar);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=6366f1&color=fff';
    }
}
