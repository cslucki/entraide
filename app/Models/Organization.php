<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Organization extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'organizations';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'is_public',
        'is_default',
        'admin_id',
        'hero_image',
        'hero_title',
        'hero_description',
        'accent_color',
        'welcome_points',
        'loops_enabled',
        'maintenance_mode',
        'platform_name',
        'platform_tagline',
        'global_color_mode',
        'blog_naming',
        'transactions_naming',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_default' => 'boolean',
            'welcome_points' => 'integer',
            'loops_enabled' => 'boolean',
            'maintenance_mode' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->where('is_active', true)->first();
    }

    public function getHeroImageUrl(): string
    {
        if ($this->hero_image) {
            return Storage::disk('public')->url($this->hero_image);
        }

        return asset('images/default-hero.jpg');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function loops(): HasMany
    {
        return $this->hasMany(Loop::class, 'organization_id');
    }
}
