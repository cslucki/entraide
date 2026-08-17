<?php

namespace App\Ai;

use App\Models\AiProviderInvocation;
use App\Models\OrganizationAiSetting;
use DomainException;
use Illuminate\Support\Facades\Context;
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
     * TASK-1220 : registre `nom d'instance SDK -> source du credential`,
     * porte par le `Context` Laravel de la requete/du job courant.
     *
     * C'est la PREUVE du credential pour le ledger canonique
     * (`ai_provider_invocations`) : seule `registerInstance()` — la primitive
     * qui pose reellement la cle dans la configuration d'instance — ecrit ici.
     * `organization` quand la cle vient de `organization_ai_settings`, `none`
     * quand le driver est keyless (ollama local). Une instance absente du
     * registre (famille nue `openai`, dont la cle vient de la config
     * plateforme) reste `unknown` : on ne DEDUIT jamais `platform` de la
     * config — le jour ou une primitive plateforme existera, elle se
     * declarera elle-meme.
     */
    public const CREDENTIAL_SOURCES_CONTEXT_KEY = 'ai_provider_credential_sources';

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
     * TASK-1214 : instance SDK d'embedding pour l'INGESTION d'une Organization.
     *
     * La famille/modele d'embedding est l'identite de l'index (partagee par
     * l'ingestion et la requete via `ai.default_for_embeddings`) : on ne la
     * derive PAS du provider de chat du tenant. Seul le CREDENTIAL devient
     * celui de l'Organization.
     *
     * Retourne le nom d'instance a passer a `DossierChunkEmbeddingService::embed`
     * quand l'Organization peut produire des embeddings, ou `null` quand elle ne
     * le peut pas — auquel cas l'appelant ne doit PLUS jamais retomber sur la
     * cle plateforme (doctrine P4 : aucun fallback silencieux). Un `null`
     * signifie « pas d'embedding tenant disponible », jamais « utilise la
     * plateforme ».
     *
     * `null` est renvoye quand : aucune configuration IA utilisable ; le
     * provider du tenant n'appartient pas a la famille d'embedding de l'index
     * (une cle d'une autre famille ne peut pas signer cet index) ; ou aucun
     * credential alors que le driver en exige un. Une erreur de configuration
     * plateforme (famille d'embedding sans `ai.providers.*`) reste une
     * DomainException : c'est un defaut d'exploitation, pas une Organization.
     */
    public function resolveEmbeddingInstance(string $organizationId): ?string
    {
        $family = trim((string) config('ai.default_for_embeddings', 'openai'));

        if ($family === '') {
            throw new DomainException('No embedding provider family is configured (ai.default_for_embeddings).');
        }

        $base = config('ai.providers.'.$family);

        if (! is_array($base) || $base === []) {
            throw new DomainException("Embedding provider family [{$family}] has no [ai.providers.{$family}] configuration.");
        }

        $driver = is_string($base['driver'] ?? null) ? trim($base['driver']) : '';

        if ($driver === '') {
            throw new DomainException("Embedding provider family [{$family}] has no driver configured.");
        }

        $instance = self::instanceName($organizationId, $family);

        // Driver local sans cle (ollama) : aucune configuration tenant requise.
        if (in_array($driver, self::KEYLESS_DRIVERS, true)) {
            $this->registerInstance($instance, $base, null);

            return $instance;
        }

        $setting = OrganizationAiSetting::query()
            ->where('organization_id', $organizationId)
            ->first();

        // Pas de configuration IA utilisable = pas de nouvel embedding. Aucun
        // repli plateforme.
        if ($setting === null || ! $setting->isUsable()) {
            return null;
        }

        // La cle du tenant vaut pour SA famille. Si sa famille ne coincide pas
        // avec celle de l'index, elle ne peut pas signer cet index — et on ne
        // bascule pas sur la plateforme pour combler l'ecart.
        if (trim((string) $setting->provider) !== $family) {
            return null;
        }

        $key = trim((string) $setting->api_key);

        if ($key === '') {
            return null;
        }

        $this->registerInstance($instance, $base, $key);

        return $instance;
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

        // TASK-1220 : la preuve du credential, posee au seul endroit qui sait.
        // Les cles de CE resolver viennent exclusivement de
        // `organization_ai_settings` ; un driver keyless n'en a aucune.
        $sources = Context::get(self::CREDENTIAL_SOURCES_CONTEXT_KEY, []);
        $sources[$instance] = $key !== null
            ? AiProviderInvocation::CREDENTIAL_ORGANIZATION
            : AiProviderInvocation::CREDENTIAL_NONE;
        Context::add(self::CREDENTIAL_SOURCES_CONTEXT_KEY, $sources);
    }

    /**
     * Source PROUVEE du credential d'une instance SDK, pour le ledger
     * canonique. `unknown` pour toute instance que ce resolver n'a pas
     * enregistree pendant la requete/le job courant.
     */
    public static function credentialSourceFor(?string $instance): string
    {
        if ($instance === null || $instance === '') {
            return AiProviderInvocation::CREDENTIAL_UNKNOWN;
        }

        $sources = Context::get(self::CREDENTIAL_SOURCES_CONTEXT_KEY, []);
        $source = is_array($sources) ? ($sources[$instance] ?? null) : null;

        return is_string($source) ? $source : AiProviderInvocation::CREDENTIAL_UNKNOWN;
    }
}
