<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1434 — SW-4 : une conversation du Shell Welcome, propre a UNE
 * Organization et a UN visiteur pseudonyme. `message_count` compte les
 * messages `role=user` (definition canonique de `max_messages`).
 */
class GuestConversation extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    /** La limite de messages de la politique est atteinte : plus aucun appel provider ici. */
    public const STATUS_LIMIT_REACHED = 'limit_reached';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_LIMIT_REACHED, self::STATUS_CLOSED];

    protected $fillable = [
        'organization_id',
        'guest_visitor_id',
        'locale',
        'status',
        'message_count',
        'started_at',
        'last_message_at',
        'source',
        'source_ref',
        'claimed_user_id',
        'claimed_at',
    ];

    protected $casts = [
        'message_count' => 'integer',
        'started_at' => 'datetime',
        'last_message_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(GuestVisitor::class, 'guest_visitor_id');
    }

    public function claimedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GuestMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('guest_conversations.organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeForVisitor(Builder $query, GuestVisitor|string $visitor): Builder
    {
        return $query->where('guest_visitor_id', $visitor instanceof GuestVisitor ? $visitor->getKey() : $visitor);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isClaimed(): bool
    {
        return $this->claimed_user_id !== null;
    }
}
