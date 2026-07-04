<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminAiPromptController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $scenarioId = $request->input('scenario_id');
        $scenarioLabels = $this->scenarioLabels();

        $prompts = AdminAiPrompt::when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")
            ->orWhere('description', 'like', "%{$search}%"))
            ->when($scenarioId, fn ($q) => $q->where('scenario_id', $scenarioId))
            ->orderBy('scenario_id')
            ->orderBy('version', 'desc')
            ->paginate(25);

        return view('admin.ai-prompts.index', compact('prompts', 'search', 'scenarioId', 'scenarioLabels'));
    }

    public function create(): View
    {
        return view('admin.ai-prompts.create', ['scenarioLabels' => $this->scenarioLabels()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'scenario_id' => ['required', 'string', Rule::in(array_keys($this->scenarioLabels()))],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'prompt_text' => 'required|string',
            'metadata' => 'nullable|string',
        ]);

        $maxVersion = AdminAiPrompt::where('scenario_id', $validated['scenario_id'])->max('version') ?? 0;
        $validated['version'] = $maxVersion + 1;

        if (isset($validated['metadata']) && is_string($validated['metadata']) && trim($validated['metadata']) !== '') {
            $decoded = json_decode($validated['metadata'], true);
            $validated['metadata'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        } else {
            $validated['metadata'] = null;
        }

        AdminAiPrompt::create($validated);

        return redirect()->route('admin.ai-prompts')
            ->with('success', 'Prompt IA créé avec succès.');
    }

    public function show(AdminAiPrompt $prompt): View
    {
        return view('admin.ai-prompts.show', compact('prompt'));
    }

    public function edit(AdminAiPrompt $prompt): View
    {
        return view('admin.ai-prompts.edit', [
            'prompt' => $prompt,
            'scenarioLabels' => $this->scenarioLabels(),
        ]);
    }

    public function update(Request $request, AdminAiPrompt $prompt): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'prompt_text' => 'required|string',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|string',
        ]);

        if (isset($validated['metadata']) && is_string($validated['metadata']) && trim($validated['metadata']) !== '') {
            $decoded = json_decode($validated['metadata'], true);
            $validated['metadata'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        } else {
            $validated['metadata'] = null;
        }

        $prompt->update($validated);

        return redirect()->route('admin.ai-prompts')
            ->with('success', 'Prompt IA mis à jour avec succès.');
    }

    public function destroy(AdminAiPrompt $prompt): RedirectResponse
    {
        $prompt->delete();

        return redirect()->route('admin.ai-prompts')
            ->with('success', 'Prompt IA supprimé avec succès.');
    }

    private function scenarioLabels(): array
    {
        return [
            'supervision_content' => 'Supervision de contenu',
            'clarify_help_request' => 'Clarification de demande d\'aide',
            'blog_generate' => 'Blog — Génération d\'article',
            'blog_correct' => 'Blog — Correction d\'article',
            'profile_agent_master' => 'Agent de profil IA — Prompt master',
            'profile_agent_setup' => 'Agent de profil IA — Prompt setup',
            'profile_agent_visitor_chat' => 'Agent de profil IA — Chat visiteur',
        ];
    }
}
