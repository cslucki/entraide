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
            'loop_knowledge_answer' => 'Réponse documentaire sourcée (Boucle)',
            'blog_generate' => 'Blog — Génération d\'article',
            'blog_correct' => 'Blog — Correction d\'article',
            'blog_method_selection_explorer_fr' => 'SuperBlog — Méthode IA sélection — Explorer — FR',
            'blog_method_selection_explorer_en' => 'SuperBlog — Method AI selection — Explore — EN',
            'blog_method_selection_clarifier_fr' => 'SuperBlog — Méthode IA sélection — Clarifier — FR',
            'blog_method_selection_clarifier_en' => 'SuperBlog — Method AI selection — Clarify — EN',
            'blog_method_selection_slow_down_fr' => 'SuperBlog — Méthode IA sélection — Ralentir — FR',
            'blog_method_selection_slow_down_en' => 'SuperBlog — Method AI selection — Slow down — EN',
            'blog_method_selection_invent_fr' => 'SuperBlog — Méthode IA sélection — Inventer — FR',
            'blog_method_selection_invent_en' => 'SuperBlog — Method AI selection — Invent — EN',
            // TASK-1249 : definitions courtes des methodes de facilitation de
            // l'Explorer d'article (chat) — fallback code si aucune ligne active.
            'blog_explorer_method_explorer_fr' => 'Explorer d\'article — Facilitation — Explorer — FR',
            'blog_explorer_method_explorer_en' => 'Article Explorer — Facilitation — Explore — EN',
            'blog_explorer_method_slow_down_fr' => 'Explorer d\'article — Facilitation — Ralentir — FR',
            'blog_explorer_method_slow_down_en' => 'Article Explorer — Facilitation — Slow down — EN',
            'blog_explorer_method_clarifier_fr' => 'Explorer d\'article — Facilitation — Clarifier — FR',
            'blog_explorer_method_clarifier_en' => 'Article Explorer — Facilitation — Clarify — EN',
            'blog_explorer_method_invent_fr' => 'Explorer d\'article — Facilitation — Inventer — FR',
            'blog_explorer_method_invent_en' => 'Article Explorer — Facilitation — Invent — EN',
            'profile_agent_master' => 'Agent de profil IA — Prompt master',
            'profile_agent_setup' => 'Agent de profil IA — Prompt setup',
            'profile_agent_visitor_chat' => 'Agent de profil IA — Chat visiteur',
            'service_offer_master_fr' => 'Service Offer Master — Prompt FR',
            'service_offer_master_en' => 'Service Offer Master — Prompt EN',
            'chatloop_ai_answer_fr' => 'ChatLoop IA — Répondre — FR',
            'chatloop_ai_answer_en' => 'ChatLoop AI — Answer — EN',
            'chatloop_ai_ask_fr' => 'ChatLoop IA — Question — FR',
            'chatloop_ai_ask_en' => 'ChatLoop AI — Ask — EN',
            'chatloop_ai_summarize_fr' => 'ChatLoop IA — Résumé — FR',
            'chatloop_ai_summarize_en' => 'ChatLoop AI — Summarize — EN',
        ];
    }
}
