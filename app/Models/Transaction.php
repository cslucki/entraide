<?php

namespace App\Models;

use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory, HasOrganizationId, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new BelongsToOrganizationScope);
    }

    protected $fillable = [
        'organization_id',
        'service_id',
        'request_id',
        'buyer_id',
        'seller_id',
        'points_proposed',
        'points_agreed',
        'status',
        'buyer_confirmed_at',
        'seller_confirmed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'buyer_confirmed_at' => 'datetime',
            'seller_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'points_proposed' => 'integer',
            'points_agreed' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function pointLedgerEntries(): HasMany
    {
        return $this->hasMany(PointLedger::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function hasReviewFrom(string $userId): bool
    {
        return $this->reviews()->where('reviewer_id', $userId)->exists();
    }

    public function getOtherParticipant(User $user): User
    {
        return $user->id === $this->buyer_id ? $this->seller : $this->buyer;
    }

    public function isDirectConversation(): bool
    {
        return $this->service_id === null
            && $this->request_id === null
            && (int) $this->points_proposed === 0;
    }

    public function getSubjectAttribute(): string
    {
        if ($this->isDirectConversation()) {
            return 'Conversation directe';
        }

        if ($this->service) {
            return $this->service->title;
        }
        if ($this->serviceRequest) {
            return $this->serviceRequest->title;
        }

        return 'Échange #'.substr($this->id, 0, 8);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'En attente',
            'accepted' => 'Accepté',
            'buyer_done' => 'Terminé (acheteur)',
            'completed' => 'Complété',
            'refused' => 'Refusé',
            'cancelled' => 'Annulé',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'orange',
            'accepted' => 'blue',
            'buyer_done' => 'purple',
            'completed' => 'green',
            'refused', 'cancelled' => 'red',
            default => 'gray',
        };
    }
}
