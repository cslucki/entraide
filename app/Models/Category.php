<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name_b2c', 'name_b2b', 'slug', 'color', 'organization_id',
        'service_1', 'service_2', 'service_3', 'service_4', 'service_5',
    ];

    protected function casts(): array
    {
        return [
            'name_b2c' => 'string',
            'name_b2b' => 'string',
            'slug' => 'string',
            'color' => 'string',
        ];
    }

    public function displayName(string $context = 'transactions'): string
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($context === 'blog' && $org && $org->blog_naming === 'b2b') {
            return $this->name_b2b;
        }

        if ($context === 'transactions' && $org && $org->transactions_naming === 'b2b') {
            return $this->name_b2b;
        }

        return $this->name_b2c;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }

    public function pointGuidelines(): HasMany
    {
        return $this->hasMany(PointGuideline::class);
    }

    public function blogPosts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }
}
