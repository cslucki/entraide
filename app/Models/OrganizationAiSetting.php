<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration IA d'une Organization (TASK-1212 / IA P4-lite).
 *
 * Le credential est chiffre au repos (`encrypted`) et cache de toute
 * serialisation (`$hidden`) : il ne sort de ce modele que vers le SDK, au
 * moment de l'appel, via ProviderResolver. Aucune vue, log ou trace ne le
 * relit.
 */
class OrganizationAiSetting extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'provider',
        'model',
        'api_key',
        'monthly_budget_usd',
        'is_enabled',
        'api_key_updated_at',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'monthly_budget_usd' => 'decimal:2',
            'is_enabled' => 'boolean',
            'api_key_updated_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasCredential(): bool
    {
        return trim((string) $this->api_key) !== '';
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && trim((string) $this->provider) !== '' && trim((string) $this->model) !== '';
    }
}
