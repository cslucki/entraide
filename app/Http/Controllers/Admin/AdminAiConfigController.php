<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Models\BlogAiConfig;
use App\Models\Organization;
use App\Services\Ai\SupervisionProviderResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiConfigController extends Controller
{
    public function __construct(
        private readonly SupervisionProviderResolver $resolver,
    ) {}

    public function index(): View
    {
        $providers = $this->resolver->availableProviders();
        $defaultProvider = config('ai.default_provider', $this->resolver->defaultProvider());
        $defaultModel = config('ai.default_model', '');

        $organizations = Organization::orderBy('name')->get(['id', 'name', 'slug', 'ai_profiles_enabled']);
        $blogConfigs = [];

        foreach ($organizations as $org) {
            $blogConfigs[$org->id] = BlogAiConfig::forOrganization($org->id);
        }

        $clarificationEnabled = AiConfig::get('clarification_enabled', false);

        return view('admin.ai-config.index', [
            'providers' => $providers,
            'defaultProvider' => $defaultProvider,
            'defaultModel' => $defaultModel,
            'currentProviderConfig' => $defaultProvider ? $this->resolver->providerConfig($defaultProvider) : null,
            'isProduction' => app()->isProduction(),
            'organizations' => $organizations,
            'blogConfigs' => $blogConfigs,
            'clarificationEnabled' => $clarificationEnabled,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_provider' => ['nullable', 'string', 'in:openai,ollama,openrouter'],
            'default_model' => ['nullable', 'string', 'max:255'],
            'clarification_enabled' => 'sometimes|boolean',
        ]);

        if ($validated['default_provider'] ?? null) {
            AiConfig::set('default_provider', $validated['default_provider']);
            config(['ai.default_provider' => $validated['default_provider']]);
        }

        if (isset($validated['default_model'])) {
            AiConfig::set('default_model', $validated['default_model']);
            config(['ai.default_model' => $validated['default_model']]);
        }

        AiConfig::set('clarification_enabled', $validated['clarification_enabled'] ?? false);
        config(['ai.clarification_enabled' => $validated['clarification_enabled'] ?? false]);

        return redirect()->route('admin.ai-config')
            ->with('success', 'Configuration IA mise à jour.');
    }

    public function updateBlogConfig(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => 'required|string|exists:organizations,id',
            'generate_enabled' => 'sometimes|boolean',
            'correct_enabled' => 'sometimes|boolean',
            'generate_limit' => 'required|integer|min:1|max:100',
            'correct_limit' => 'required|integer|min:1|max:100',
        ]);

        $config = BlogAiConfig::updateOrCreate(
            ['organization_id' => $validated['organization_id']],
            [
                'generate_enabled' => $validated['generate_enabled'] ?? false,
                'correct_enabled' => $validated['correct_enabled'] ?? false,
                'generate_limit' => $validated['generate_limit'],
                'correct_limit' => $validated['correct_limit'],
            ],
        );

        return redirect()->route('admin.ai-config')
            ->with('success', __('blog.ai_config_updated'));
    }

    public function updateProfileConfig(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => 'required|string|exists:organizations,id',
            'ai_profiles_enabled' => 'sometimes|boolean',
        ]);

        $organization = Organization::findOrFail($validated['organization_id']);
        $organization->update([
            'ai_profiles_enabled' => $validated['ai_profiles_enabled'] ?? true,
        ]);

        return redirect()->route('admin.ai-config')
            ->with('success', 'Configuration profil IA mise à jour pour l\'organisation.');
    }
}
