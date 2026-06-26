<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminThemeController extends Controller
{
    public function index(Request $request): View
    {
        $themes = Theme::with('organization')
            ->orderBy('is_default', 'desc')
            ->orderBy('label')
            ->get();

        $currentTheme = null;
        if ($request->filled('theme')) {
            $currentTheme = $themes->firstWhere('key', $request->theme);
        }
        if (! $currentTheme) {
            $currentTheme = $themes->firstWhere('is_default', true) ?? $themes->first();
        }

        $themeKeys = $themes->pluck('key')->values()->all();
        $currentIndex = array_search($currentTheme->key, $themeKeys);
        $prevTheme = $currentIndex > 0 ? $themes[$currentIndex - 1] : null;
        $nextTheme = $currentIndex < count($themeKeys) - 1 ? $themes[$currentIndex + 1] : null;

        $organizations = Organization::orderBy('name')->get(['id', 'name', 'slug']);

        return view('admin.themes.index', compact('themes', 'currentTheme', 'prevTheme', 'nextTheme', 'organizations'));
    }

    public function create(): View
    {
        $organizations = Organization::orderBy('name')->get(['id', 'name', 'slug']);

        return view('admin.themes.create', compact('organizations'));
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->has('tokens') && is_string($request->tokens)) {
            $request->merge(['tokens' => json_decode($request->tokens, true) ?? []]);
        }
        if ($request->has('dark_tokens') && is_string($request->dark_tokens)) {
            $request->merge(['dark_tokens' => json_decode($request->dark_tokens, true) ?? []]);
        }

        $data = $request->validate([
            'key' => 'required|string|max:50|unique:themes,key',
            'label' => 'required|string|max:100',
            'description' => 'nullable|string',
            'organization_id' => 'nullable|string|exists:organizations,id',
            'tokens' => ['required', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'dark_tokens' => ['nullable', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token sombre « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'is_default' => 'boolean',
        ]);

        $data['is_default'] = $data['is_default'] ?? false;
        $data['dark_tokens'] = $data['dark_tokens'] ?? [];

        if ($data['is_default']) {
            Theme::where('is_default', true)->update(['is_default' => false]);
        }

        Theme::create($data);

        Theme::regenerateCache();

        return redirect()->route('admin.themes')->with('success', 'Thème « '.$data['label'].' » créé.');
    }

    public function edit(Theme $theme): View
    {
        return view('admin.themes.edit', compact('theme'));
    }

    public function update(Request $request, Theme $theme): RedirectResponse
    {
        if ($request->has('tokens') && is_string($request->tokens)) {
            $request->merge(['tokens' => json_decode($request->tokens, true) ?? []]);
        }
        if ($request->has('dark_tokens') && is_string($request->dark_tokens)) {
            $request->merge(['dark_tokens' => json_decode($request->dark_tokens, true) ?? []]);
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', Rule::unique('themes', 'key')->ignore($theme->id)],
            'label' => 'required|string|max:100',
            'description' => 'nullable|string',
            'organization_id' => 'nullable|string|exists:organizations,id',
            'tokens' => ['required', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'dark_tokens' => ['nullable', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token sombre « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'is_default' => 'boolean',
        ]);

        $data['is_default'] = $data['is_default'] ?? false;
        $data['dark_tokens'] = $data['dark_tokens'] ?? [];

        if ($data['is_default']) {
            Theme::where('is_default', true)
                ->where('id', '!=', $theme->id)
                ->update(['is_default' => false]);
        }

        $theme->update($data);

        Theme::regenerateCache();

        return redirect()->route('admin.themes', ['theme' => $theme->key])->with('success', 'Thème « '.$theme->label.' » mis à jour.');
    }

    public function destroy(Theme $theme): RedirectResponse
    {
        if ($theme->is_default) {
            return back()->with('error', 'Impossible de supprimer le thème par défaut.');
        }

        Organization::where('theme_id', $theme->id)->update(['theme_id' => null]);

        $theme->delete();

        Theme::regenerateCache();

        return redirect()->route('admin.themes')->with('success', 'Thème « '.$theme->label.' » supprimé.');
    }
}
