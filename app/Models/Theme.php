<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Theme extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'is_default',
        'tokens',
        'dark_tokens',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'tokens' => 'array',
            'dark_tokens' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeOwnedBy(Builder $query, string $organizationId): void
    {
        $query->where('organization_id', $organizationId);
    }

    public function scopeAccessibleBy(Builder $query, string $organizationId): void
    {
        $mainOrgId = Organization::orderBy('created_at')->value('id');
        $query->where(function (Builder $q) use ($organizationId, $mainOrgId) {
            $q->where('organization_id', $organizationId)
                ->orWhere('organization_id', $mainOrgId);
        });
    }

    public static function regenerateCache(): void
    {
        $configThemes = config('bouclepro_themes.themes');
        $dbThemes = static::all()->keyBy('key');
        $defaultKey = config('bouclepro_themes.default', 'zen');

        $themes = [];

        foreach ($dbThemes as $key => $dbTheme) {
            $configTheme = $configThemes[$key] ?? null;
            if ($configTheme) {
                $label = $dbTheme->label ?? $configTheme['label'];
                $tokens = array_merge($configTheme['tokens'], $dbTheme->tokens ?? []);
                $darkTokens = array_merge($configTheme['dark'] ?? $configTheme['tokens'], $dbTheme->dark_tokens ?? []);
                $themes[$key] = [
                    'label' => $label,
                    'tokens' => $tokens,
                    'dark' => $darkTokens,
                ];
            } else {
                $themes[$key] = [
                    'label' => $dbTheme->label ?? $key,
                    'tokens' => $dbTheme->tokens ?? [],
                    'dark' => $dbTheme->dark_tokens ?? [],
                ];
            }
            if ($dbTheme->is_default) {
                $defaultKey = $key;
            }
        }

        foreach ($configThemes as $key => $configTheme) {
            if (! isset($themes[$key])) {
                $themes[$key] = $configTheme;
            }
        }

        $themes['_meta'] = ['default' => $defaultKey];

        $cachePath = storage_path('app/bouclepro-themes.php');
        $export = var_export($themes, true);
        $content = "<?php return {$export};".PHP_EOL;

        file_put_contents($cachePath, $content, LOCK_EX);
    }
}
