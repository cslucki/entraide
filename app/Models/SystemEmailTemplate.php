<?php

namespace App\Models;

use Database\Factories\SystemEmailTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemEmailTemplate extends Model
{
    /** @use HasFactory<SystemEmailTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'locale',
        'slug',
        'name',
        'subject',
        'content_html',
        'variables',
        'enabled',
    ];

    protected $casts = [
        'variables' => 'array',
        'enabled' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }
}
