<?php

namespace Tests\Support\Ai;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Support\Ai\AiCost;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * TASK-1352 — fabriquer une consommation IA deja emise, comme la PRODUCTION
 * l'ecrit, et non comme elle l'ecrivait avant le cutover du ledger.
 *
 * ## Le probleme que ce helper resout
 *
 * Une generation laisse DEUX lignes : une invocation dans le ledger
 * (`ai_provider_invocations`) et une trace (`ai_interactions`). Depuis
 * TASK-1260, l'autorite de comptage bascule de la trace vers le ledger a une
 * date par process ({@see \App\Support\Ai\AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS},
 * 2026-08-18 pour `chatloop.*` et `loop_knowledge.answer`).
 *
 * Les fixtures ecrites avant ce basculement ne creaient que la TRACE. Tant que
 * le mois courant CONTENAIT le cutover, la trace faisait encore autorite sur le
 * debut de la fenetre et les tests passaient. Des le premier mois entierement
 * POSTERIEUR au cutover — septembre 2026 —, la fenetre est integralement sous
 * l'autorite du ledger : la trace fabriquee n'est plus comptee du tout, et la
 * consommation simulee vaut zero. Les tests de plafond ne voyaient donc plus
 * aucun plafond.
 *
 * Ce n'est pas un defaut du code economique : c'est une fixture qui ecrivait
 * dans l'autorite d'hier. Ce helper ecrit dans les DEUX, comme la production,
 * et reste donc valable des deux cotes du cutover.
 *
 * ## La date est explicite, jamais celle du runner
 *
 * `$at` date les DEUX lignes. Une fixture qui ne date que la trace laisse
 * l'invocation a l'heure reelle de la machine : le test devient dependant du
 * jour ou il tourne, et une consommation censee tomber « le mois dernier »
 * atterrit dans le mois courant.
 */
trait RecordsAiConsumption
{
    /**
     * Enregistre UNE generation deja emise, dans les deux autorites.
     *
     * @param  string  $process  process canonique (ex. `loop_knowledge.answer`)
     * @param  string  $feature  capability associee (ex. `loop_knowledge_answer`)
     * @param  float|null  $cost  cout connu, ou `null` pour un cout inconnu
     * @param  CarbonInterface|null  $at  instant des deux lignes ; `null` = maintenant
     */
    protected function recordAiGeneration(
        string $organizationId,
        string $userId,
        string $process,
        string $feature,
        ?float $cost = 0.001,
        ?CarbonInterface $at = null,
    ): AiInteraction {
        $invocation = AiProviderInvocation::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'capability' => $feature,
            // Le ledger exclut l'essai de doctrine du credit par cette colonne
            // (`feature IS NULL OR feature != ai_doctrine_sandbox`). L'omettre
            // ferait compter un essai comme une generation productive.
            'feature' => $feature,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'provider_cost' => $cost,
            'currency' => $cost !== null ? 'USD' : null,
            'cost_status' => $cost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $cost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);

        $interaction = AiInteraction::create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'correlation_id' => (string) Str::uuid(),
            'process' => $process,
            'feature' => $feature,
            'model' => 'openrouter/openai/gpt-4o-mini',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $cost,
            'cost_unknown' => $cost === null,
            'metadata' => ['provider' => 'openrouter', 'status' => 'success', 'capability' => $feature],
        ]);

        if ($at !== null) {
            // `created_at` n'est pas `$fillable` : on le pose par la primitive
            // prevue, sur les DEUX lignes — dater la trace seule laisserait
            // l'invocation a l'heure du runner.
            // `created_at` seulement : les fenetres economiques filtrent sur
            // elle, et `ai_interactions` n'a pas de colonne `updated_at`.
            $invocation->forceFill(['created_at' => $at])->saveQuietly();
            $interaction->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $interaction;
    }
}
