<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\SupervisionEconomicScope;
use App\Services\Ai\SupervisionProviderResolver;
use App\Support\Ai\AiRefusedException;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Centre de supervision IA — banc SuperAdmin (surface plateforme `/admin`).
 *
 * TASK-1250 (gap #18 T1246) : l'AUTORITE ECONOMIQUE est fermee sur ce banc.
 * Le TENANT DE RECORD de ses appels est l'Organization PLATEFORME
 * (`DefaultOrganizationResolver`, l'Organization `is_default` qui porte deja
 * la marque, la racine `/` et les tests d'envoi d'e-mail admin) — plus jamais
 * l'Organization personnelle de l'administrateur connecte, qui n'est pas le
 * payeur de ce qu'il teste. La facture provider reste a la PLATEFORME
 * (`credential_source = platform`, declare). Le budget applique est donc
 * celui du tenant plateforme ; aucun credit utilisateur (banc
 * d'administration, comme le bac a sable de doctrine). Chaque appel
 * reellement tente ecrit sa ligne `ai_provider_invocations` (`feature =
 * admin_ai_supervision_bench`, `process` = celui du scenario). Un refus est
 * rendu STRUCTURE (`economicRefusal` + HTTP 429) — distinct d'une erreur
 * provider et jamais deguise en resultat.
 */
class AdminAiSupervisionController extends Controller
{
    public const BENCH_FEATURE = 'admin_ai_supervision_bench';

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

    public function analyze(Request $request): Response|RedirectResponse
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
        $refusal = null;

        try {
            $platformTenant = DefaultOrganizationResolver::resolve();

            if (! $platformTenant) {
                // Aucune Organization en base : le banc n'a pas de tenant de
                // record auquel attribuer sa consommation — il n'appelle pas.
                throw new AiRefusedException(
                    AiRefusedException::CODE_UNAVAILABLE,
                    'Aucune Organization plateforme : le banc ne peut pas attribuer sa consommation.'
                );
            }

            $provider = $this->resolver->resolveUnderEconomicAuthority(
                $selectedProvider,
                new SupervisionEconomicScope(
                    organization: $platformTenant,
                    actor: $request->user(),
                    creditUser: null,
                    feature: self::BENCH_FEATURE,
                ),
            );

            if ($selectedScenario === 'clarify_help_request') {
                $scenarioDefinition = app(AiScenarioFactory::class)->resolve('clarify_help_request');
                if (! $scenarioDefinition) {
                    throw new SupervisionException('Scénario « Clarification de demande d\'aide » non trouvé.');
                }
                $result = $provider->runScenario($scenarioDefinition, $data['content'], $selectedModel);
            } else {
                $result = $provider->supervise($data['content'], $selectedModel);
            }
        } catch (AiRefusedException $e) {
            // Refus economique AVANT tout appel : rien n'est parti, rien n'est
            // ecrit. Code stable + message produit (+ detail technique pour
            // l'administrateur, par exemple la cle plateforme absente).
            $refusal = [
                'code' => $e->refusalCode,
                'message' => $e->getMessage(),
                'detail' => $e->getPrevious()?->getMessage(),
            ];
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

        return response()->view('admin.ai-supervision.index', [
            'providers' => $providers,
            'provider' => $selectedProvider,
            'model' => $selectedModel,
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'content' => $data['content'],
            'result' => $result,
            'supervisionError' => $error,
            'economicRefusal' => $refusal,
            'scenarios' => $scenariosToShow,
            'scenario' => $selectedScenario,
            'scenarioCompat' => $scenarioCompat,
            'defaultProvider' => $this->resolver->defaultProvider(),
            'hasActiveProvider' => $this->resolver->defaultProvider() !== null,
        ], $refusal !== null ? 429 : 200);
    }
}
