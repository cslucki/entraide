<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageReference;
use App\Services\UsageReference\UsageReferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1439 — UsageReference V1, administrable par le SuperAdmin SEULEMENT
 * (zone admin globale, garde d'attribut `is_admin`). Brouillon -> publication
 * explicite -> retrait ; une version publiee ne s'edite plus. Pas de CMS, pas
 * de generation IA du contenu (MASTER Q66).
 */
class AdminUsageReferenceController extends Controller
{
    public function __construct(private readonly UsageReferenceService $references) {}

    public function index(): View
    {
        $rows = UsageReference::query()
            ->with(['author', 'publisher'])
            ->orderBy('surface_key')
            ->orderBy('locale')
            ->orderByDesc('version')
            ->get();

        return view('admin.usage-references.index', [
            'surfaces' => UsageReference::SURFACES,
            'locales' => UsageReference::supportedLocales(),
            'platformLocale' => UsageReference::platformLocale(),
            'grouped' => $rows->groupBy('surface_key'),
            'maxChars' => UsageReference::maxChars(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.usage-references.form', [
            'reference' => null,
            'surfaces' => UsageReference::SURFACES,
            'locales' => UsageReference::supportedLocales(),
            'surfaceKey' => (string) $request->query('surface', UsageReference::SURFACE_SHELL_WELCOME),
            'locale' => (string) $request->query('locale', UsageReference::platformLocale()),
            'maxChars' => UsageReference::maxChars(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'surface_key' => ['required', 'string', 'in:'.implode(',', UsageReference::SURFACES)],
            'locale' => ['required', 'string', 'in:'.implode(',', UsageReference::supportedLocales())],
            'title' => ['required', 'string', 'max:'.UsageReference::MAX_TITLE_CHARS],
            'content' => ['required', 'string', 'max:'.UsageReference::maxChars()],
        ]);

        $reference = $this->references->createDraft($data['surface_key'], $data['locale'], $data['title'], $data['content'], $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_draft_created', ['version' => $reference->version]));
    }

    public function edit(UsageReference $usageReference): View
    {
        abort_unless($usageReference->isDraft(), 404);

        return view('admin.usage-references.form', [
            'reference' => $usageReference,
            'surfaces' => UsageReference::SURFACES,
            'locales' => UsageReference::supportedLocales(),
            'surfaceKey' => $usageReference->surface_key,
            'locale' => $usageReference->locale,
            'maxChars' => UsageReference::maxChars(),
        ]);
    }

    public function update(Request $request, UsageReference $usageReference): RedirectResponse
    {
        abort_unless($usageReference->isDraft(), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:'.UsageReference::MAX_TITLE_CHARS],
            'content' => ['required', 'string', 'max:'.UsageReference::maxChars()],
        ]);

        $this->references->updateDraft($usageReference, $data['title'], $data['content'], $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_draft_saved', ['version' => $usageReference->version]));
    }

    public function publish(Request $request, UsageReference $usageReference): RedirectResponse
    {
        abort_unless($usageReference->isDraft(), 404);

        $this->references->publish($usageReference, $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_published', ['version' => $usageReference->version]));
    }

    public function retire(Request $request, UsageReference $usageReference): RedirectResponse
    {
        abort_unless($usageReference->isPublished(), 404);

        $this->references->retire($usageReference, $request->user());

        return redirect()->route('admin.usage-references')->with('success', __('admin.usage_reference_retired', ['version' => $usageReference->version]));
    }
}
