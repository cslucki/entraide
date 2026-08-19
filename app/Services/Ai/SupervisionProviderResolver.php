<?php

namespace App\Services\Ai;

use App\Ai\ProviderResolver;
use App\Models\AiConfig;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use App\Services\Ai\Providers\EconomicSupervisionProvider;
use App\Services\Ai\Providers\LoggingSupervisionProvider;
use App\Services\Ai\Providers\OllamaSupervisionProvider;
use App\Services\Ai\Providers\OpenRouterSupervisionProvider;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiRefusedException;

class SupervisionProviderResolver
{
    public function resolve(string $provider): SupervisionProvider
    {
        return match ($provider) {
            'ollama' => $this->wrapWithLogging(
                app(OllamaSupervisionProvider::class),
                'ollama',
            ),
            'openrouter' => $this->wrapWithLogging(
                app(OpenRouterSupervisionProvider::class),
                'openrouter',
            ),
            default => app(SupervisionProvider::class),
        };
    }

    /**
     * TASK-1250 : le MEME provider que `resolve()`, sous l'AUTORITE ECONOMIQUE
     * — le point d'entree des chemins herites authentifies (formulation
     * d'offre de service, banc de supervision SuperAdmin). Dans l'ordre :
     *
     *  1. la cle PLATEFORME du provider est verifiee et DECLAREE
     *     (`declarePlatformCredential()`) : absente = refus `ai_not_configured`
     *     AVANT tout appel et avant toute ecriture ;
     *  2. la trace operationnelle `admin_ai_interactions` recoit le tenant du
     *     perimetre (explicite), plus jamais « current_organization sinon
     *     l'Organization de l'utilisateur connecte » ;
     *  3. le tout est enveloppe dans `EconomicSupervisionProvider` : garde
     *     AVANT provider, ledger canonique sur chaque appel tente.
     *
     * `resolve()` reste tel quel pour les autres appelants (rien d'autre ne
     * change de comportement).
     *
     * @throws AiRefusedException cle/configuration plateforme absente
     */
    public function resolveUnderEconomicAuthority(string $provider, SupervisionEconomicScope $scope): SupervisionProvider
    {
        $instance = $this->declarePlatformCredential($provider);

        $inner = $this->resolve($provider);

        if ($inner instanceof LoggingSupervisionProvider) {
            $inner = $inner->forTenant((string) $scope->organization->id);
        }

        return new EconomicSupervisionProvider(
            $inner,
            $this->economicAuthority($scope),
            $provider,
            (string) ($this->providerConfig($provider)['model'] ?? ''),
            $instance,
        );
    }

    /**
     * TASK-1251 : l'autorite economique d'un perimetre EXPLICITE (garde AVANT
     * provider + ledger sur chaque tentative), pour les chemins herites qui
     * N'EMPRUNTENT PAS le contrat `SupervisionProvider` et font leurs propres
     * appels HTTP plateforme (`MemberProfileAgentResponder`). Le decorateur de
     * `resolveUnderEconomicAuthority()` en est construit de la meme maniere :
     * une seule logique, deux formes. Le credential reste a declarer par
     * l'appelant via `declarePlatformCredential()` (le `ResolvedModel` passe a
     * l'autorite porte l'instance declaree).
     */
    public function economicAuthority(SupervisionEconomicScope $scope): SupervisionEconomicAuthority
    {
        return new SupervisionEconomicAuthority(
            app(AiEconomicGuard::class),
            app(AiProviderInvocationLedger::class),
            $scope,
        );
    }

    /**
     * TASK-1250 : preuve du credential PLATEFORME d'un provider herite — la
     * primitive T1247 (`ProviderResolver::declareLegacyPlatformCredential()`)
     * appelee au seul endroit de ce resolveur qui LIT la configuration
     * plateforme (`providerConfig()`), juste avant l'appel. Une cle absente
     * (ou un Ollama sans `base_url`) est une indisponibilite AVANT tout appel
     * : refus code `ai_not_configured`, rien d'ecrit — et le provider concret
     * ne levera plus sa `SupervisionException` pre-HTTP, qui aurait ete
     * indiscernable d'un echec apres depart.
     *
     * @return string nom d'instance du registre de preuve (`legacy:platform:{provider}`)
     *
     * @throws AiRefusedException
     */
    public function declarePlatformCredential(string $provider): string
    {
        $keyless = $provider === 'ollama';

        if ($keyless) {
            // Meme test que `OllamaSupervisionProvider` (`baseUrl === ''`),
            // sur la valeur brute : un `base_url` absent y vaut ''.
            if (trim((string) config('ai.ollama.base_url', '')) === '') {
                throw AiRefusedException::notConfigured(
                    new \RuntimeException('Ollama non configuré (base_url plateforme absente).')
                );
            }
        } elseif (trim((string) ($this->providerConfig($provider)['api_key'] ?? '')) === '') {
            throw AiRefusedException::notConfigured(
                new \RuntimeException('Clé API plateforme manquante pour le provider '.$provider.'.')
            );
        }

        return ProviderResolver::declareLegacyPlatformCredential($provider, keyless: $keyless);
    }

    private function wrapWithLogging(SupervisionProvider $inner, string $providerName): LoggingSupervisionProvider
    {
        return new LoggingSupervisionProvider(
            $inner,
            app(AiBenchmarkLogger::class),
            app(AdminAiInteractionPersistence::class),
            $providerName,
        );
    }

    public function defaultProvider(): ?string
    {
        $override = AiConfig::get('default_provider') ?: config('ai.default_provider');
        if ($override) {
            if ($override === 'ollama' && config('ai.ollama.enabled')) {
                return 'ollama';
            }
            if ($override === 'openrouter' && config('ai.openrouter.enabled')) {
                return 'openrouter';
            }
            if ($override === 'openai' && config('ai.openai.supervision_enabled')) {
                return 'openai';
            }
        }

        if (config('ai.ollama.enabled')) {
            return 'ollama';
        }

        if (config('ai.openrouter.enabled')) {
            return 'openrouter';
        }

        if (config('ai.openai.supervision_enabled')) {
            return 'openai';
        }

        return null;
    }

    public function availableProviders(): array
    {
        $providers = [];

        if (config('ai.ollama.enabled')) {
            $providers['ollama'] = [
                'label' => 'Ollama (local)',
                'type' => 'local',
                'models' => [
                    config('ai.ollama.model', 'ministral-3:3b') => config('ai.ollama.model', 'ministral-3:3b'),
                ],
            ];
        }

        if (config('ai.openrouter.enabled')) {
            $providers['openrouter'] = [
                'label' => 'OpenRouter',
                'type' => 'cloud_proxy',
                'models' => [
                    config('ai.openrouter.model', 'deepseek/deepseek-chat-v3-0324') => config('ai.openrouter.model', 'deepseek/deepseek-chat-v3-0324'),
                ],
            ];
        }

        if (config('ai.openai.supervision_enabled')) {
            $providers['openai'] = [
                'label' => 'OpenAI',
                'type' => 'cloud',
                'models' => [
                    'gpt-4o-mini' => 'GPT-4o Mini',
                    'gpt-4o' => 'GPT-4o',
                    'gpt-4.1-mini' => 'GPT-4.1 Mini',
                    'gpt-4.1-nano' => 'GPT-4.1 Nano',
                    'o4-mini' => 'o4-mini',
                ],
            ];
        }

        return $providers;
    }

    public function supportedScenarios(string $provider): array
    {
        return ['supervision_content', 'clarify_help_request', 'service_offer_master'];
    }

    public function scenarioSupportsProvider(string $scenario, string $provider): bool
    {
        return in_array($scenario, $this->supportedScenarios($provider), true);
    }

    public function providerConfig(string $provider): array
    {
        return match ($provider) {
            'ollama' => [
                'base_url' => config('ai.ollama.base_url', 'http://localhost:11434'),
                'api_key' => null,
                'model' => config('ai.ollama.model', 'ministral-3:3b'),
                'timeout' => config('ai.ollama.timeout', 30),
                'max_output_tokens' => 900,
            ],
            'openrouter' => [
                'base_url' => config('ai.openrouter.base_url', 'https://openrouter.ai/api/v1'),
                'api_key' => config('ai.openrouter.api_key'),
                'model' => config('ai.openrouter.model', 'mistralai/ministral-3b-2512'),
                'timeout' => config('ai.openrouter.timeout', 30),
                'max_output_tokens' => config('ai.openrouter.max_output_tokens', 900),
            ],
            default => [
                'base_url' => config('ai.openai.base_url', 'https://api.openai.com/v1'),
                'api_key' => config('ai.openai.api_key'),
                'model' => config('ai.openai.model', 'gpt-4o-mini'),
                'timeout' => config('ai.openai.timeout', 15),
                'max_output_tokens' => config('ai.openai.max_output_tokens', 900),
            ],
        };
    }
}
