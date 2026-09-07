<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1429 — SW-1 : politique Shell Welcome d'une Organization.
 *
 * Lecture sans ecriture : `forOrganization()` renvoie la ligne ou une
 * instance NON sauvegardee aux defauts (DISABLED) — consulter la page admin
 * ne cree rien en base.
 */
class OrganizationGuestShellPolicy extends Model
{
    use HasUuids;

    public const DEFAULT_MAX_MESSAGES = 10;

    public const DEFAULT_RETENTION_DAYS = 90;

    public const MAX_MESSAGES_LIMIT = 100;

    public const RETENTION_DAYS_LIMIT = 365;

    protected $fillable = [
        'organization_id',
        'enabled',
        'max_messages',
        'retention_days',
        'guest_monthly_budget_usd',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'max_messages' => 'integer',
        'retention_days' => 'integer',
        'guest_monthly_budget_usd' => 'decimal:2',
    ];

    protected $attributes = [
        'enabled' => false,
        'max_messages' => self::DEFAULT_MAX_MESSAGES,
        'retention_days' => self::DEFAULT_RETENTION_DAYS,
    ];

    public static function forOrganization(Organization|string $organization): self
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return static::query()->firstOrNew(['organization_id' => $id]);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
