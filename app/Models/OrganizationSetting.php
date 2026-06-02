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

    public static function get(string $organizationId, string $key, mixed $default = null): mixed
    {
        return static::where('organization_id', $organizationId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    public static function set(string $organizationId, string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['organization_id' => $organizationId, 'key' => $key],
            ['value' => $value]
        );
    }
}
