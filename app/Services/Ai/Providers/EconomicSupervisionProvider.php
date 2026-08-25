<?php

namespace App\Services\Ai\Providers;

use App\Ai\ResolvedModel;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\SupervisionEconomicAuthority;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiUsage;

/**
 * AUTORITE ECONOMIQUE des appels herites `SupervisionProvider` (TASK-1250) —
 * le meme patron que `BlogAiService::callAi()` (T1247) et
 * `BlogExplorerController::callProvider()` (T1248), pose UNE fois en
 * decorateur plutot que recopie dans chaque appelant :
 *
 *   GARDE (`AiEconomicGuard::authorize()`, aucun appel si refus, rien d'ecrit)
 *     -> appel provider (inner : `LoggingSupervisionProvider` -> HTTP)
 *     -> LEDGER canonique `ai_provider_invocations` (succes ET echec).
 *
 * TASK-1251 : la logique garde + tentative + ledger vit desormais dans
 * `SupervisionEconomicAuthority` (reutilisee par les chemins qui n'empruntent
 * pas ce contrat — la reponse automatique de l'agent de profil) ; ce
 * decorateur ne fait plus que l'appliquer au contrat `SupervisionProvider`.
 * Son comportement est inchange.
 *
 * Ce decorateur ne sait rien du credential : il recoit le nom d'instance du
 * registre de preuve pose par `SupervisionProviderResolver::declarePlatformCredential()`
 * (qui a lu la cle dans la configuration PLATEFORME et l'a declaree telle
 * quelle — jamais deduite). Il ne touche ni au contrat `SupervisionProvider`,
 * ni aux providers concrets, ni a la trace operationnelle `admin_ai_interactions`
 * (toujours ecrite par `LoggingSupervisionProvider`, inchangee) : la ligne du
 * ledger ne porte ni contenu ni cout invente.
 *
 * Couts : `supervise()` rapporte un usage observe -> cout catalogue ;
 * `runScenario()` ne rapporte AUCUN usage (contrat du provider, voir la note
 * TASK-1132 de `LoggingSupervisionProvider`) -> `cost_status = unknown`
 * (ou `known 0` si le catalogue declare le provider gratuit, ollama) — jamais
 * un 0 fabrique. Un echec (SupervisionException apres depart de la requete,
 * ou tout autre Throwable) est une tentative economiquement reelle : ligne
 * `failed`, cout NULL/unknown, `failure_reason` = classe d'exception, puis
 * l'exception d'origine est relancee telle quelle. Les retries internes des
 * providers (429) restent invisibles d'ici : une invocation = une ligne.
 */
final class EconomicSupervisionProvider implements SupervisionProvider
{
    public function __construct(
        private readonly SupervisionProvider $inner,
        private readonly SupervisionEconomicAuthority $authority,
        /** Famille du provider (`openai`, `openrouter`, `ollama`) : trace + catalogue. */
        private readonly string $provider,
        /** Modele du provider quand l'appelant n'en impose pas (`providerConfig()['model']`). */
        private readonly string $defaultModel,
        /** Cle du registre de preuve du credential (`legacy:platform:{provider}`). */
        private readonly string $credentialInstance,
    ) {}

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        $process = AiProcess::fromScenarioId('supervision_content');
        $requested = $this->resolvedModel($model);

        $this->authority->authorize($process, $requested);

        return $this->authority->attempt(
            $process,
            $requested,
            fn (): AiSupervisionResult => $this->inner->supervise($content, $model),
            fn (AiSupervisionResult $result): AiUsage => self::usageOf($result),
            // Le modele RAPPORTE par le provider (celui que son propre calcul
            // de cout a utilise), sinon le modele demande.
            fn (AiSupervisionResult $result): ?string => trim($result->model) !== '' ? $result->model : null,
        );
    }

    public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array
    {
        $process = AiProcess::fromScenarioId($scenario->id());
        $resolved = $this->resolvedModel($model);

        $this->authority->authorize($process, $resolved);

        return $this->authority->attempt(
            $process,
            $resolved,
            fn (): array => $this->inner->runScenario($scenario, $content, $model),
            // Usage structurellement NON observe sur ce contrat : le catalogue
            // tranche (UNKNOWN, ou 0 connu pour un provider declare gratuit).
            fn (): AiUsage => AiUsage::notObserved(),
        );
    }

    private function resolvedModel(?string $model): ResolvedModel
    {
        $name = trim((string) $model) !== '' ? (string) $model : $this->defaultModel;

        return new ResolvedModel($this->provider, $name, $this->credentialInstance);
    }

    /**
     * Usage observe d'un `AiSupervisionResult`. Le DTO type ses compteurs
     * `int` (0 par defaut) : un 0 y est indiscernable d'un compteur absent
     * DANS L'OBJET. On retablit la distinction ici, compteur par compteur,
     * par le meme raisonnement que `AiUsage::fromSdkTextTokens()` : une
     * generation reelle ne consomme jamais 0 token — un 0 signe un compteur
     * non rapporte (ollama ne rapporte pas l'entree, par exemple) et reste
     * NULL au ledger plutot qu'un zero fabrique.
     */
    private static function usageOf(AiSupervisionResult $result): AiUsage
    {
        return AiUsage::of(
            $result->inputTokens > 0 ? $result->inputTokens : null,
            $result->outputTokens > 0 ? $result->outputTokens : null,
        );
    }
}
