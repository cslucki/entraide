<?php

namespace App\Ai;

use App\Models\OrganizationAiSetting;
use DomainException;
use Laravel\Ai\Ai;

/**
 * Resolution du provider et du modele d'une capability (TASK-1207 / IA P3,
 * TASK-1212 / IA P4-lite).
 *
 * Responsabilite UNIQUE : repondre « quel provider, quel modele, et avec quel
 * credential, pour cette capability, dans ce contexte ? ».
 *
 * P4-lite : la reponse vient de l'ORGANIZATION du contexte
 * (`organization_ai_settings`). Une Organization sans configuration,
 * desactivee ou sans credential n'a pas de provider : DomainException,
 * explicite, AVANT tout appel. Il n'existe aucun repli vers la cle plateforme
 * ou l'environnement — un appel facture a la plateforme pour le compte d'un
 * tenant qui n'a rien configure serait pire qu'un appel qui echoue.
 *
 * Ce que le resolver ne fait toujours PAS :
 * - il n'appelle aucun provider ;
 * - il ne route pas, ne benchmarke pas, ne compare pas les couts ;
 * - il ne fait aucun failover ;
 * - il ne contient aucun prompt ;
 * - il n'ecrit le credential nulle part ailleurs que dans la configuration
 *   d'instance SDK du tenant, en memoire, au moment de l'appel.
 */
final class ProviderResolver
{
    /**
     * Drivers executes localement, qui n'ont legitimement aucune cle d'API.
     */
    private const KEYLESS_DRIVERS = ['ollama'];

    /**
     * Providers offerts aux Organizations : ceux dont l'application porte une
     * configuration SDK (`ai.providers.*`).
     */
    public const ALLOWED_PROVIDERS = ['openrouter', 'openai', 'ollama'];

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

        $setting = OrganizationAiSetting::query()
            ->where('organization_id', $contexte->organizationId)
            ->first();

        if ($setting === null || ! $setting->isUsable()) {
            throw new DomainException(
                "AI is not configured for Organization [{$contexte->organizationId}]: no enabled provider/model."
            );
        }

        $provider = trim((string) $setting->provider);
        $model = trim((string) $setting->model);

        if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            throw new DomainException("AI provider [{$provider}] is not available to Organizations.");
        }

        $base = config('ai.providers.'.$provider);

        if (! is_array($base) || $base === []) {
            throw new DomainException(
                "AI provider [{$provider}] has no [ai.providers.{$provider}] configuration."
            );
        }

        $driver = is_string($base['driver'] ?? null) ? trim($base['driver']) : '';

        if ($driver === '') {
            throw new DomainException("AI provider [{$provider}] has no driver configured.");
        }

        $instance = self::instanceName($contexte->organizationId, $provider);

        if (in_array($driver, self::KEYLESS_DRIVERS, true)) {
            $this->registerInstance($instance, $base, null);

            return new ResolvedModel($provider, $model, $instance);
        }

        $key = trim((string) $setting->api_key);

        if ($key === '') {
            throw new DomainException(
                "AI provider [{$provider}] has no credential configured for Organization [{$contexte->organizationId}]."
            );
        }

        $this->registerInstance($instance, $base, $key);

        return new ResolvedModel($provider, $model, $instance);
    }

    /**
     * Nom de l'instance SDK d'un tenant : deterministe, sans secret dedans.
     */
    public static function instanceName(string $organizationId, string $provider): string
    {
        return 'org:'.$organizationId.':'.$provider;
    }

    /**
     * L'instance SDK du tenant = configuration de base du provider (driver,
     * URL, modeles) avec LE credential de l'Organization a la place de celui
     * de l'environnement. `forgetInstance` d'abord : un worker longue duree ne
     * doit jamais reutiliser une instance construite avec une ancienne cle.
     *
     * @param  array<string, mixed>  $base
     */
    private function registerInstance(string $instance, array $base, ?string $key): void
    {
        $config = $base;
        unset($config['key']);

        if ($key !== null) {
            $config['key'] = $key;
        }

        config()->set('ai.providers.'.$instance, $config);
        Ai::forgetInstance($instance);
    }
}
