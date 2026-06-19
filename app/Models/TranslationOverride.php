<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationOverride extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'locale',
        'group',
        'key',
        'value',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }

    public function scopeForOrganization($query, ?string $organizationId = null)
    {
        if ($organizationId === null) {
            return $query->whereNull('organization_id');
        }

        return $query->where('organization_id', $organizationId);
    }

    public function scopeForKey($query, string $group, string $key)
    {
        return $query->where('group', $group)->where('key', $key);
    }
}
