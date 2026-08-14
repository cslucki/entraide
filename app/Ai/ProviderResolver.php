<?php

namespace App\Ai;

use App\Models\AiConfig;
use DomainException;

/**
 * Resolution du provider et du modele d'une capability (TASK-1207 / IA P3).
 *
 * Responsabilite UNIQUE : repondre « quel provider et quel modele, pour cette
 * capability, dans ce contexte ? » avec deux valeurs explicites.
 *
 * Ce que le resolver ne fait PAS, et ne doit pas se mettre a faire :
 * - il n'appelle aucun provider ;
 * - il ne route pas (aucun choix entre plusieurs modeles) ;
 * - il ne benchmarke pas, ne compare pas les couts ;
 * - il n'active aucun mode « OpenRouter auto » ;
 * - il ne connait aucune cle tenant (P4 hors scope) ;
 * - il ne contient aucun prompt.
 *
 * La resolution reproduit A L'IDENTIQUE celle de
 * `ChatLoopAiService::resolveProviderAndModel()` : la migration vers le SDK ne
 * doit changer ni le provider ni le modele effectifs. Toute divergence ici
 * serait un changement de facturation deguise en refactor.
 *
 * En cas de configuration absente ou incoherente : DomainException. Jamais de
 * repli silencieux vers un autre provider — un appel qui part chez un provider
 * que personne n'a choisi est pire qu'un appel qui echoue.
 */
final class ProviderResolver
{
    /**
     * Drivers executes localement, qui n'ont legitimement aucune cle d'API.
     */
    private const KEYLESS_DRIVERS = ['ollama'];

    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    public function resolve(string $capability, ContexteIa $contexte): ResolvedModel
    {
        // Default deny : une capability inconnue n'a pas de provider.
        $this->capabilities->get($capability);

        if ($contexte->capability !== $capability) {
            throw new DomainException(
                "AI context capability [{$contexte->capability}] does not match requested capability [{$capability}]."
            );
        }

        $provider = trim((string) (AiConfig::get('default_provider') ?: config('ai.default_provider', 'openai')));

        if ($provider === '') {
            throw new DomainException("No AI provider is configured for capability [{$capability}].");
        }

        $model = trim((string) (AiConfig::get('default_model') ?? config('ai.default_model') ?? match ($provider) {
            'openrouter' => config('ai.openrouter.model'),
            'ollama' => config('ai.ollama.model'),
            default => config('ai.openai.model'),
        }));

        if ($model === '') {
            throw new DomainException("No AI model is configured for provider [{$provider}].");
        }

        $this->assertProviderIsUsableByTheSdk($provider);

        return new ResolvedModel($provider, $model);
    }

    /**
     * Le provider resolu doit exister en tant qu'instance Laravel AI SDK.
     *
     * `AiManager::getInstanceConfig()` retombe sur `['driver' => $name]` quand
     * `ai.providers.{name}` est absent : le SDK n'echouerait alors qu'au moment
     * de lire une cle inexistante, loin du point de decision. On tranche ici,
     * pendant qu'on sait encore de quelle capability il s'agit.
     */
    private function assertProviderIsUsableByTheSdk(string $provider): void
    {
        $config = config('ai.providers.'.$provider);

        if (! is_array($config) || $config === []) {
            throw new DomainException(
                "AI provider [{$provider}] has no [ai.providers.{$provider}] configuration."
            );
        }

        $driver = is_string($config['driver'] ?? null) ? trim($config['driver']) : '';

        if ($driver === '') {
            throw new DomainException("AI provider [{$provider}] has no driver configured.");
        }

        if (in_array($driver, self::KEYLESS_DRIVERS, true)) {
            return;
        }

        $key = is_string($config['key'] ?? null) ? trim($config['key']) : '';

        if ($key === '') {
            throw new DomainException("AI provider [{$provider}] has no API key configured.");
        }
    }
}
