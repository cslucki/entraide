<?php

namespace App\Models;

use App\Support\ColorHelper;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'logo_path',
        'hero_title',
        'hero_description',
        'hero_gradient_start',
        'accent_color',
        'welcome_points',
        'service_points_min',
        'service_points_max',
        'loops_enabled',
        'ai_profiles_enabled',
        'subscriptions_enabled',
        'loop_mode',
        'primary_loop_id',
        'maintenance_mode',
        'platform_name',
        'platform_tagline',
        'global_color_mode',
        'header_javascript_enabled',
        'header_javascript',
        'blog_naming',
        'transactions_naming',
        'feed_post_publish_mode',
        'theme_id',
        'locale',
        'default_country_code',
        'show_country',
        'membership_enabled',
        'membership_label_fr',
        'membership_label_en',
        'homepage_template',
        'homepage_settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_default' => 'boolean',
            'welcome_points' => 'integer',
            'loops_enabled' => 'boolean',
            'ai_profiles_enabled' => 'boolean',
            'subscriptions_enabled' => 'boolean',
            'maintenance_mode' => 'boolean',
            'header_javascript_enabled' => 'boolean',
            'locale' => 'string',
            'show_country' => 'boolean',
            'membership_enabled' => 'boolean',
            'homepage_settings' => 'array',
        ];
    }

    public function getHeroGradientEndAttribute(): string
    {
        $start = $this->hero_gradient_start ?? $this->accent_color ?? '#4f46e5';

        return ColorHelper::darken($start, 25);
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

    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo_path) {
            return Storage::disk('public')->url($this->logo_path);
        }

        return null;
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function defaultCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'default_country_code', 'code');
    }

    public function priorityCountries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'organization_country_preferences', 'organization_id', 'country_code')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('organization_country_preferences.sort_order');
    }

    public function countryPreferences(): HasMany
    {
        return $this->hasMany(OrganizationCountryPreference::class);
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

    public function primaryLoop(): BelongsTo
    {
        return $this->belongsTo(Loop::class, 'primary_loop_id');
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function isMonoLoop(): bool
    {
        return $this->loop_mode === 'mono';
    }

    public function isMultiLoop(): bool
    {
        return $this->loop_mode !== 'mono';
    }

    public function isFeedPostPublishableByMembers(): bool
    {
        return $this->feed_post_publish_mode === 'members';
    }
}
