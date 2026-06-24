<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\TranslationOverride;
use App\Services\TranslationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminTranslationController extends Controller
{
    public function index(Request $request, TranslationService $service): View
    {
        $group = $request->query('group');
        $status = $request->query('status');
        $search = $request->query('search');
        $orgId = $request->query('org_id');

        $allEntries = $service->all();
        $groups = $service->getGroups();

        $entries = $allEntries;

        if ($group && $group !== '_all') {
            $entries = $entries->where('group', $group);
        }

        $overrides = TranslationOverride::with('organization', 'createdBy', 'updatedBy')
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        $globalOverridesKeyed = $overrides
            ->whereNull('organization_id')
            ->keyBy(fn (TranslationOverride $o) => "{$o->group}.{$o->key}:{$o->locale}");

        if ($status && $status !== '_all') {
            if ($status === 'OVERRIDDEN') {
                $entries = $entries->filter(fn ($e) =>
                    isset($globalOverridesKeyed["{$e['group']}.{$e['key']}:fr"])
                    || isset($globalOverridesKeyed["{$e['group']}.{$e['key']}:en"])
                );
            } else {
                $allowed = ['OK', 'MISSING_FR', 'MISSING_EN', 'EMPTY_FR', 'EMPTY_EN'];
                if (in_array($status, $allowed)) {
                    $entries = $entries->where('status', $status);
                }
            }
        }

        if ($search) {
            $entries = $entries->filter(function ($e) use ($search) {
                $fr = is_array($e['fr'] ?? null) ? '' : (string) ($e['fr'] ?? '');
                $en = is_array($e['en'] ?? null) ? '' : (string) ($e['en'] ?? '');

                return str_contains(strtolower($e['key']), strtolower($search))
                    || str_contains(strtolower($fr), strtolower($search))
                    || str_contains(strtolower($en), strtolower($search));
            });
        }

        $overriddenCount = $allEntries->filter(fn ($e) => isset($globalOverridesKeyed["{$e['group']}.{$e['key']}:fr"])
            || isset($globalOverridesKeyed["{$e['group']}.{$e['key']}:en"])
        )->count();

        $remainingCount = $allEntries->count() - $overriddenCount;

        $stats = [
            'total' => $allEntries->count(),
            'ok' => $allEntries->where('status', 'OK')->count(),
            'missing_fr' => $allEntries->whereIn('status', ['MISSING_FR', 'EMPTY_FR'])->count(),
            'missing_en' => $allEntries->whereIn('status', ['MISSING_EN', 'EMPTY_EN'])->count(),
            'overridden' => $overriddenCount,
            'remaining' => $remainingCount,
        ];

        $organizations = Organization::orderBy('name')->get(['id', 'name']);

        if ($orgId) {
            $overrides = $overrides->where('organization_id', $orgId);
        }

        return view('admin.translations.index', [
            'entries' => $entries,
            'groups' => $groups,
            'stats' => $stats,
            'activeGroup' => $group,
            'activeStatus' => $status,
            'search' => $search,
            'overrides' => $overrides,
            'globalOverridesKeyed' => $globalOverridesKeyed,
            'organizations' => $organizations,
            'activeOrgId' => $orgId,
        ]);
    }

    public function createOverride(Request $request): View
    {
        $organizations = Organization::orderBy('name')->get(['id', 'name']);
        $groups = app(TranslationService::class)->getGroups();

        return view('admin.translations.overrides-form', [
            'override' => null,
            'organizations' => $organizations,
            'groups' => $groups,
            'locales' => ['fr', 'en'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|uuid|exists:organizations,id',
            'locale' => 'required|string|in:fr,en',
            'group' => 'required|string|max:100',
            'key' => 'required|string|max:100',
            'value' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);

        $exists = TranslationOverride::query()
            ->forOrganization($validated['organization_id'] ?? null)
            ->forLocale($validated['locale'])
            ->forKey($validated['group'], $validated['key'])
            ->exists();

        if ($exists) {
            return redirect()->route('admin.translations')
                ->with('error', 'Un override existe déjà pour cette combinaison organisation/locale/groupe/clé.');
        }

        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        TranslationOverride::create($validated);

        return redirect()->route('admin.translations')
            ->with('success', 'Override créé avec succès.');
    }

    public function editOverride(TranslationOverride $translationOverride): View
    {
        $organizations = Organization::orderBy('name')->get(['id', 'name']);
        $groups = app(TranslationService::class)->getGroups();

        return view('admin.translations.overrides-form', [
            'override' => $translationOverride,
            'organizations' => $organizations,
            'groups' => $groups,
            'locales' => ['fr', 'en'],
        ]);
    }

    public function updateOverride(Request $request, TranslationOverride $translationOverride): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => 'nullable|uuid|exists:organizations,id',
            'locale' => 'required|string|in:fr,en',
            'group' => 'required|string|max:100',
            'key' => 'required|string|max:100',
            'value' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);

        $duplicate = TranslationOverride::query()
            ->forOrganization($validated['organization_id'] ?? null)
            ->forLocale($validated['locale'])
            ->forKey($validated['group'], $validated['key'])
            ->where('id', '!=', $translationOverride->id)
            ->exists();

        if ($duplicate) {
            return redirect()->route('admin.translations')
                ->with('error', 'Un autre override existe déjà pour cette combinaison organisation/locale/groupe/clé.');
        }

        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['updated_by'] = $request->user()->id;

        $translationOverride->update($validated);

        return redirect()->route('admin.translations')
            ->with('success', 'Override mis à jour avec succès.');
    }

    public function deactivateOverride(TranslationOverride $translationOverride): RedirectResponse
    {
        $translationOverride->update([
            'is_active' => false,
            'updated_by' => request()->user()->id,
        ]);

        return redirect()->route('admin.translations')
            ->with('success', 'Override désactivé avec succès.');
    }

    public function resetOverride(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group' => 'required|string|max:100',
            'key' => 'required|string|max:100',
        ]);

        $updated = TranslationOverride::query()
            ->whereNull('organization_id')
            ->where('group', $validated['group'])
            ->where('key', $validated['key'])
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_by' => $request->user()->id,
            ]);

        if ($updated > 0) {
            return redirect()->route('admin.translations')
                ->with('success', 'Override(s) réinitialisé(s) avec succès.');
        }

        return redirect()->route('admin.translations')
            ->with('error', 'Aucun override actif à réinitialiser.');
    }
}
