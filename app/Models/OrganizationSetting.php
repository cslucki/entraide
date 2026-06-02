<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSetting extends Model
{
    protected $table = 'organization_settings';

    protected $fillable = ['organization_id', 'key', 'value'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function getDefaultOrgId(): ?string
    {
        return Organization::where('is_default', true)->value('id');
    }

    public static function get(Organization|string|null $organization = null, string $key, mixed $default = null): mixed
    {
        $organizationId = $organization instanceof Organization
            ? $organization->id
            : ($organization ?? static::getDefaultOrgId());

        return static::where('organization_id', $organizationId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    public static function set(Organization|string|null $organization = null, string $key, mixed $value): void
    {
        $organizationId = $organization instanceof Organization
            ? $organization->id
            : ($organization ?? static::getDefaultOrgId());

        static::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => $key],
            ['value' => $value]
        );
    }
}
