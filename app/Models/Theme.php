<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Theme extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'is_default',
        'tokens',
        'dark_tokens',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'tokens' => 'array',
            'dark_tokens' => 'array',
        ];
    }

    public static function regenerateCache(): void
    {
        $configThemes = config('bouclepro_themes.themes');
        $dbThemes = static::all()->keyBy('key');
        $defaultKey = config('bouclepro_themes.default', 'zen');

        $themes = [];
        foreach ($configThemes as $key => $configTheme) {
            $dbTheme = $dbThemes->get($key);
            if ($dbTheme) {
                $label = $dbTheme->label ?? $configTheme['label'];
                $tokens = array_merge($configTheme['tokens'], $dbTheme->tokens ?? []);
                $darkTokens = array_merge($configTheme['dark'] ?? $configTheme['tokens'], $dbTheme->dark_tokens ?? []);
                $themes[$key] = [
                    'label' => $label,
                    'tokens' => $tokens,
                    'dark' => $darkTokens,
                ];
                if ($dbTheme->is_default) {
                    $defaultKey = $key;
                }
            } else {
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
