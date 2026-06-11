<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
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

        return view('admin.ai-config.index', [
            'providers' => $providers,
            'defaultProvider' => $defaultProvider,
            'defaultModel' => $defaultModel,
            'currentProviderConfig' => $defaultProvider ? $this->resolver->providerConfig($defaultProvider) : null,
            'isProduction' => app()->isProduction(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_provider' => ['nullable', 'string', 'in:openai,ollama,openrouter'],
            'default_model' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['default_provider'] ?? null) {
            AiConfig::set('default_provider', $validated['default_provider']);
            config(['ai.default_provider' => $validated['default_provider']]);
        }

        if (isset($validated['default_model'])) {
            AiConfig::set('default_model', $validated['default_model']);
            config(['ai.default_model' => $validated['default_model']]);
        }

        return redirect()->route('admin.ai-config')
            ->with('success', 'Configuration IA mise à jour.');
    }
}
