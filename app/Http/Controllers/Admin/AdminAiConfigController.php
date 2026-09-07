<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Models\BlogAiConfig;
use App\Models\Organization;
use App\Models\OrganizationGuestShellPolicy;
use App\Services\Ai\SupervisionProviderResolver;
use App\Services\GuestShell\GuestShellPolicyService;
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

        // TASK-1429 — SW-1 : l'etat Shell Welcome calcule de chaque Organization (jamais persiste).
        $guestShellStates = [];
        $guestShell = app(GuestShellPolicyService::class);
        foreach (Organization::orderBy('name')->get() as $organization) {
            $guestShellStates[$organization->id] = $guestShell->state($organization);
        }

        return view('admin.ai-config.index', [
            'providers' => $providers,
            'defaultProvider' => $defaultProvider,
            'defaultModel' => $defaultModel,
            'currentProviderConfig' => $defaultProvider ? $this->resolver->providerConfig($defaultProvider) : null,
            'isProduction' => app()->isProduction(),
            'organizations' => $organizations,
            'blogConfigs' => $blogConfigs,
            'clarificationEnabled' => $clarificationEnabled,
            'guestShellStates' => $guestShellStates,
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

    /**
     * TASK-1429 — SW-1 : la politique Shell Welcome d'une Organization
     * (ON/OFF, limite de messages, retention, budget Guest). Le provider, le
     * modele et la cle restent dans l'autorite IA existante : rien ici.
     */
    public function updateGuestShellConfig(Request $request, GuestShellPolicyService $policies): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => 'required|string|exists:organizations,id',
            'enabled' => 'sometimes|boolean',
            'max_messages' => ['required', 'integer', 'min:1', 'max:'.OrganizationGuestShellPolicy::MAX_MESSAGES_LIMIT],
            'retention_days' => ['required', 'integer', 'min:1', 'max:'.OrganizationGuestShellPolicy::RETENTION_DAYS_LIMIT],
            'guest_monthly_budget_usd' => 'nullable|numeric|min:0|max:100000',
        ]);

        $organization = Organization::findOrFail($validated['organization_id']);
        $policies->update($organization, [
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'max_messages' => (int) $validated['max_messages'],
            'retention_days' => (int) $validated['retention_days'],
            'guest_monthly_budget_usd' => $validated['guest_monthly_budget_usd'] ?? null,
        ]);

        return redirect()->route('admin.ai-config')
            ->with('success', __('admin.guest_shell_saved', ['name' => $organization->name]));
    }
}
