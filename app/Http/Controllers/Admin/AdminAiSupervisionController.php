<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\SupervisionProviderResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiSupervisionController extends Controller
{
    public function __construct(
        protected SupervisionProviderResolver $resolver,
    ) {}

    public function index(): View
    {
        $factory = app(AiScenarioFactory::class);
        $defaultProvider = $this->resolver->defaultProvider();
        $providers = $this->resolver->availableProviders();

        $defaultModel = ($defaultProvider && isset($providers[$defaultProvider]))
            ? array_key_first($providers[$defaultProvider]['models'])
            : '';

        $scenarioCompat = [];
        foreach ($factory->all() as $id => $scenario) {
            $supportedBy = [];
            foreach (array_keys($providers) as $providerKey) {
                if ($this->resolver->scenarioSupportsProvider($id, $providerKey)) {
                    $supportedBy[] = $providerKey;
                }
            }
            $scenarioCompat[$id] = $supportedBy;
        }

        $scenariosToShow = $factory->all();

        return view('admin.ai-supervision.index', [
            'providers' => $providers,
            'provider' => $defaultProvider ?? '',
            'model' => $defaultModel,
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'scenarios' => $scenariosToShow,
            'scenario' => 'supervision_content',
            'scenarioCompat' => $scenarioCompat,
            'defaultProvider' => $defaultProvider,
            'hasActiveProvider' => $defaultProvider !== null,
        ]);
    }

    public function analyze(Request $request): View|RedirectResponse
    {
        if (! config('ai.supervision.enabled', true)) {
            abort(403, 'Centre de supervision IA désactivé.');
        }

        $providerNames = array_keys($this->resolver->availableProviders());

        if (empty($providerNames)) {
            return redirect()->route('admin.ai-supervision')
                ->with('error', 'Aucun provider IA actif. Activez Ollama, OpenRouter ou OpenAI dans la configuration.');
        }

        $data = $request->validate([
            'content' => ['required', 'string', 'min:3', 'max:5000'],
            'provider' => ['nullable', 'string', 'in:'.implode(',', $providerNames)],
            'model' => ['nullable', 'string'],
            'scenario' => ['nullable', 'string', 'in:supervision_content,clarify_help_request'],
        ]);

        $selectedProvider = $data['provider'] ?? $this->resolver->defaultProvider() ?? 'ollama';
        $selectedScenario = $data['scenario'] ?? 'supervision_content';

        $providers = $this->resolver->availableProviders();
        $selectedModel = $data['model']
            ?? ($providers[$selectedProvider]['models']
                ? array_key_first($providers[$selectedProvider]['models'])
                : '');

        $error = null;
        $result = null;

        try {
            $provider = $this->resolver->resolve($selectedProvider);

            if ($selectedScenario === 'clarify_help_request') {
                $scenarioDefinition = app(AiScenarioFactory::class)->resolve('clarify_help_request');
                if (! $scenarioDefinition) {
                    throw new SupervisionException('Scénario « Clarification de demande d\'aide » non trouvé.');
                }
                $result = $provider->runScenario($scenarioDefinition, $data['content'], $selectedModel);
            } else {
                $result = $provider->supervise($data['content'], $selectedModel);
            }
        } catch (SupervisionException $e) {
            $error = $e->getMessage();
        }

        $factory = app(AiScenarioFactory::class);

        $scenarioCompat = [];
        foreach ($factory->all() as $id => $scenario) {
            $supportedBy = [];
            foreach (array_keys($providers) as $providerKey) {
                if ($this->resolver->scenarioSupportsProvider($id, $providerKey)) {
                    $supportedBy[] = $providerKey;
                }
            }
            $scenarioCompat[$id] = $supportedBy;
        }

        $scenariosToShow = $factory->all();

        return view('admin.ai-supervision.index', [
            'providers' => $providers,
            'provider' => $selectedProvider,
            'model' => $selectedModel,
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'content' => $data['content'],
            'result' => $result,
            'supervisionError' => $error,
            'scenarios' => $scenariosToShow,
            'scenario' => $selectedScenario,
            'scenarioCompat' => $scenarioCompat,
            'defaultProvider' => $this->resolver->defaultProvider(),
            'hasActiveProvider' => $this->resolver->defaultProvider() !== null,
        ]);
    }
}
