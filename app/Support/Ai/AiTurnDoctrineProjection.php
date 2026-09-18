<?php

namespace App\Support\Ai;

use App\Models\AiCreditSettingChange;
use App\Models\Organization;
use App\Services\Ai\AiRerankSettings;

/**
 * TASK-1582 — Doctrine Strip V0 : « les regles qui ont gouverne ce tour ».
 *
 * Deux regles seulement, parce que deux seulement sont OBSERVABLES a la fois
 * dans le tour (mesure) et dans la configuration (aujourd'hui) :
 *
 *   1. le filtre vectoriel `max_distance` ;
 *   2. l'autorite du rerank : plateforme ET Organization.
 *
 * Ce sibling est read-only et requete peu (l'autorite rerank d'aujourd'hui,
 * bornee a l'Organization du tour, et sa derniere modification connue). Il
 * ne touche ni `AiTurnInspection` (query-free), ni le pipeline, ni le bloc
 * `turn`. Il ne RECONSTRUIT rien : une valeur d'aujourd'hui n'est jamais
 * presentee comme la valeur qui gouvernait un ancien tour — les deux sont
 * rendues cote a cote, avec leur portee (`turn` | `current`) et leur label.
 *
 * Aucune pretention a expliquer la Constitution BouclePro : V0.
 */
final class AiTurnDoctrineProjection
{
    public const SCOPE_TURN = 'turn';

    public const SCOPE_CURRENT = 'current';

    public const RERANK_RULE = 'PLATFORM_ENABLED AND ORGANIZATION_ENABLED';

    public const MAX_DISTANCE_HISTORY_REASON = 'config_or_env_without_audit_trail';

    /**
     * @param  array<string, mixed>  $trace  l'inspection (V0-H) du tour
     * @return array<string, mixed>
     */
    public static function project(array $trace, Organization $organization, AiRerankSettings $rerank): array
    {
        $rt = is_array($trace['retrieval_trace'] ?? null) ? $trace['retrieval_trace'] : null;

        return [
            'max_distance' => self::maxDistance($rt),
            'rerank' => self::rerank($rt, $organization, $rerank),
        ];
    }

    /**
     * A. mesure de CE tour ; B. configuration d'aujourd'hui ; C. ecart ;
     * D. historique — UNAVAILABLE : `config/ai.php` / `.env` n'ont aucune
     * piste d'audit, et l'inventer serait une reconstruction.
     *
     * @param  array<string, mixed>|null  $rt
     * @return array<string, mixed>
     */
    private static function maxDistance(?array $rt): array
    {
        $mesure = $rt !== null && isset($rt['max_distance']) ? (float) $rt['max_distance'] : null;
        $actuelle = (float) config('ai.knowledge.max_distance', 1.0);

        return [
            'measured' => $mesure,
            'measured_label' => $mesure === null ? AiTruthLabel::UNAVAILABLE : AiTruthLabel::MEASURED,
            'measured_scope' => self::SCOPE_TURN,
            'measured_reason' => $mesure === null ? ($rt === null ? 'no_retrieval_trace' : 'max_distance_not_recorded') : null,
            'current' => $actuelle,
            'current_label' => AiTruthLabel::DECLARED,
            'current_scope' => self::SCOPE_CURRENT,
            'current_source' => 'config(ai.knowledge.max_distance)',
            // `null` quand la mesure manque : on ne compare pas a du vide.
            'differs' => $mesure === null ? null : abs($mesure - $actuelle) > 1e-9,
            'history' => null,
            'history_label' => AiTruthLabel::UNAVAILABLE,
            'history_reason' => self::MAX_DISTANCE_HISTORY_REASON,
        ];
    }

    /**
     * Observe sur ce tour (retrieval_trace, MEASURED) ; autorite d'aujourd'hui
     * (lue maintenant, portee `current`) ; regle (DECLARED) ; derniere
     * modification connue (audit `AiCreditSettingChange`, plateforme et
     * Organization du tour — jamais une autre).
     *
     * @param  array<string, mixed>|null  $rt
     * @return array<string, mixed>
     */
    private static function rerank(?array $rt, Organization $organization, AiRerankSettings $rerank): array
    {
        $observe = [];
        foreach (['rerank_attempted' => 'attempted', 'reason_not_attempted' => 'reason_not_attempted', 'rerank_provider' => 'provider', 'rerank_model' => 'model', 'rerank_duration_ms' => 'duration_ms'] as $cleTrace => $cle) {
            $valeur = $rt[$cleTrace] ?? null;
            $observe[$cle] = $valeur;
            // `null` porte par une trace presente reste une mesure pour les
            // champs que la source ecrit toujours (`reason_not_attempted` est
            // `null` quand le rerank a ete tente : c'est ce qu'elle a ecrit).
            $observe[$cle.'_label'] = $rt === null
                ? AiTruthLabel::UNAVAILABLE
                : (array_key_exists($cleTrace, $rt) ? AiTruthLabel::MEASURED : AiTruthLabel::UNAVAILABLE);
        }

        $plateforme = $rerank->platformEnabled();
        $organisation = $rerank->organizationEnabled((string) $organization->id);

        return [
            'observed' => $observe + ['scope' => self::SCOPE_TURN, 'reason' => $rt === null ? 'no_retrieval_trace' : null],
            'current' => [
                'platform_enabled' => $plateforme,
                'organization_enabled' => $organisation,
                'can_be_enabled_for_organization' => $rerank->canBeEnabledFor($organization),
                'effective' => $plateforme && $organisation,
                'label' => AiTruthLabel::MEASURED,
                'scope' => self::SCOPE_CURRENT,
                // Jamais une preuve pour un tour passe : la portee le dit, et ce
                // texte le repete la ou un lecteur presse le lirait.
                'caveat' => 'valeurs lues aujourd\'hui ; elles ne prouvent pas ce qui gouvernait un tour passe',
            ],
            'rule' => [
                'expression' => self::RERANK_RULE,
                'label' => AiTruthLabel::DECLARED,
                'source' => 'AiRerankSettings::platformEnabled() && organizationEnabled()',
            ],
            'last_change' => [
                'platform' => self::changement($rerank->lastChange(null)),
                'organization' => self::changement($rerank->lastChange($organization)),
                'label_present' => AiTruthLabel::MEASURED,
                'wording' => 'Dernière modification connue de cette configuration',
            ],
        ];
    }

    /**
     * Une ligne d'audit rendue telle quelle (jamais de causalite deduite) ;
     * absence de ligne = UNAVAILABLE, pas « jamais modifie ».
     *
     * @return array<string, mixed>
     */
    private static function changement(?AiCreditSettingChange $change): array
    {
        if ($change === null) {
            return ['available' => false, 'label' => AiTruthLabel::UNAVAILABLE, 'reason' => 'no_audit_row'];
        }

        $delta = is_array($change->changes) ? ($change->changes['rerank_enabled'] ?? null) : null;

        return [
            'available' => true,
            'label' => AiTruthLabel::MEASURED,
            'from' => is_array($delta) ? ($delta['from'] ?? null) : null,
            'to' => is_array($delta) ? ($delta['to'] ?? null) : null,
            'changed_by' => $change->author?->name,
            'created_at' => $change->created_at?->toIso8601String(),
        ];
    }
}
