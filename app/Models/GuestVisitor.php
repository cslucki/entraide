<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1433 — SW-3 : un visiteur pseudonyme du Shell Welcome, propre a UNE
 * Organization. Ne porte jamais l'IP ni le cookie en clair.
 */
class GuestVisitor extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'visitor_key_hash',
        'locale',
        'declared_first_name',
        'declared_role',
        'declared_interest',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'shortcut',
        'acquisition_journey_id',
        'first_seen_at',
        'last_seen_at',
        'expires_at',
        'claimed_user_id',
        'claimed_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(GuestConversation::class, 'guest_visitor_id');
    }

    /** TASK-1447 — la version publiee EXACTE de la Journey par laquelle ce visiteur est entre (first touch wins). */
    public function acquisitionJourney(): BelongsTo
    {
        return $this->belongsTo(AcquisitionJourney::class, 'acquisition_journey_id');
    }

    public function claimedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_user_id');
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }

    public function isClaimed(): bool
    {
        return $this->claimed_user_id !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Pseudonyme court, pour les cockpits : 8 caracteres de l'empreinte, jamais la cle. */
    public function pseudonym(): string
    {
        return substr($this->visitor_key_hash, 0, 8);
    }
}
