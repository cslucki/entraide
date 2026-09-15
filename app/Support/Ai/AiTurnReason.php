<?php

namespace App\Support\Ai;

use App\Ai\Context\DossierRerankOutcome;
use App\Ai\Context\SourceDenied;

/**
 * TASK-1566 / CDC-01 V0-A — le point d'entree UNIQUE des `reason_code` du tour.
 *
 * ## SQUELETTE — etat declare
 *
 * Cette classe est VOLONTAIREMENT incomplete. V0-A la pose ; **V0-C la
 * finalise** (CDC-01 §13). Ce qu'elle contient aujourd'hui : uniquement les
 * vocabulaires qui EXISTENT DEJA dans le depot, references par alias.
 *
 * Ce qu'elle ne contient PAS, et ne doit pas contenir avant V0-C : les codes
 * que CDC-01 P0.4 annonce comme « nouveaux codes necessaires »
 * (`NO_SOURCES_FOUND`, `NO_GROUNDED_EVIDENCE`, `EMPTY_MODEL_ANSWER`,
 * `FAKE_PROVIDER_FALLBACK`, `FEATURE_DISABLED`, `DOCUMENT_PATH_DIRECT_EXECUTION`,
 * `CONTEXT_EMPTY`, `RERANK_NOT_CONFIGURED`). Les inventer ici reviendrait a
 * figer, sans les etages qui les emettent, un vocabulaire que personne n'aurait
 * encore eu l'occasion de confronter au code reel.
 *
 * ## Pourquoi des ALIAS et non des chaines recopiees
 *
 * Le depot porte deja trois vocabulaires stables, chacun autoritaire chez lui.
 * La regle de CDC-01 P0.4 est « reutiliser l'existant avant d'inventer ». Une
 * constante recopiee serait une QUATRIEME source de verite, qui divergerait
 * silencieusement le jour ou l'originale changerait. Chaque constante ci-dessous
 * pointe donc la constante d'origine : il n'y a toujours qu'un seul endroit ou
 * la valeur est ecrite.
 *
 * ## Une collision reelle, exposee et NON tranchee (FACT @dc2109b3)
 *
 * Deux classes nomment le meme concept avec deux chaines DIFFERENTES :
 *
 *     AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED = 'organization_monthly_budget_reached'
 *     AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED = 'organization_budget_reached'
 *
 *     AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED       = 'user_monthly_credit_exhausted'
 *     AiRefusedException::CODE_USER_CREDIT_EXHAUSTED       = 'user_credit_exhausted'
 *
 * Le GUARD nomme ce qu'il a MESURE ; l'EXCEPTION nomme ce qu'elle REMONTE a
 * l'appelant. Ce sont deux moments distincts du meme refus, et rien ne prouve
 * aujourd'hui qu'ils doivent fusionner.
 *
 * Ce squelette ne choisit donc PAS de vainqueur : il expose les deux familles
 * sous leur origine. Elire une chaine canonique serait une decision de
 * conception a part entiere — elle appartient a V0-C, avec les traces reelles
 * sous les yeux. Choisir maintenant, c'est trancher a l'aveugle et risquer de
 * renommer un code que des lecteurs consomment deja.
 *
 * ## Et une collision INVERSE, symetrique de la precedente (FACT @dc2109b3)
 *
 * La meme chaine sert deja a deux concepts distincts :
 *
 *     DossierRerankOutcome::REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable'
 *     AiTurnState::DEGRADED_PROVIDER_UNAVAILABLE        = 'provider_unavailable'
 *
 * L'une dit « le rerank n'a pas ete tente », l'autre « le tour a ete rendu en
 * mode degrade ». Un `reason_code` lu HORS de son etape est donc ambigu.
 *
 * Consequence pour le schema : un code ne se lit JAMAIS seul. Il se lit avec
 * l'etape qui le porte (`steps[].name`) ou avec l'axe qui le porte
 * (`state.degraded_reason`). C'est pourquoi `byFamily()` conserve la famille
 * d'origine au lieu de rendre une liste plate — la liste plate d'`all()`
 * n'existe que pour les gardes de test, jamais pour interpreter un tour.
 *
 * ## Ce qu'un `reason_code` est — et n'est jamais (invariant I5)
 *
 * Un identifiant TECHNIQUE borne, stable, lisible par une machine. Jamais un
 * extrait du contenu refuse, jamais une phrase destinee a un humain. Un message
 * humain COMPLETE un code, il ne le remplace pas.
 */
final class AiTurnReason
{
    // -----------------------------------------------------------------
    // Famille 1 — ce que le GARDE ECONOMIQUE a mesure (AiEconomicGuard)
    // -----------------------------------------------------------------

    public const ECONOMIC_MONTHLY_BUDGET_REACHED = AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED;

    public const ECONOMIC_UNKNOWN_QUOTA_REACHED = AiEconomicGuard::REASON_UNKNOWN_QUOTA_REACHED;

    public const ECONOMIC_ORGANIZATION_BUDGET_REACHED = AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED;

    public const ECONOMIC_EMBEDDING_UNKNOWN_QUOTA_REACHED = AiEconomicGuard::REASON_EMBEDDING_UNKNOWN_QUOTA_REACHED;

    public const ECONOMIC_USER_CREDIT_EXHAUSTED = AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED;

    // -----------------------------------------------------------------
    // Famille 2 — ce que le REFUS a remonte a l'appelant (AiRefusedException)
    // -----------------------------------------------------------------

    public const REFUSED_USER_CREDIT_EXHAUSTED = AiRefusedException::CODE_USER_CREDIT_EXHAUSTED;

    public const REFUSED_ORGANIZATION_BUDGET_REACHED = AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED;

    public const REFUSED_NOT_CONFIGURED = AiRefusedException::CODE_NOT_CONFIGURED;

    public const REFUSED_UNAVAILABLE = AiRefusedException::CODE_UNAVAILABLE;

    public const REFUSED_AUTHENTICATION_REQUIRED = AiRefusedException::CODE_AUTHENTICATION_REQUIRED;

    // -----------------------------------------------------------------
    // Famille 3 — pourquoi une SOURCE a ete refusee (SourceDenied)
    // -----------------------------------------------------------------

    public const SOURCE_NO_LOOP_IN_CONTEXT = SourceDenied::REASON_NO_LOOP_IN_CONTEXT;

    public const SOURCE_LOOP_OUTSIDE_ORGANIZATION = SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION;

    public const SOURCE_NO_USER_IN_CONTEXT = SourceDenied::REASON_NO_USER_IN_CONTEXT;

    // -----------------------------------------------------------------
    // Famille 4 — pourquoi le RERANK n'a pas ete tente (DossierRerankOutcome)
    // -----------------------------------------------------------------

    public const RERANK_BELOW_MINIMUM_CANDIDATES = DossierRerankOutcome::REASON_BELOW_MINIMUM_CANDIDATES;

    public const RERANK_EMPTY_QUERY = DossierRerankOutcome::REASON_EMPTY_QUERY;

    public const RERANK_GATE_CLOSED = DossierRerankOutcome::REASON_GATE_CLOSED;

    public const RERANK_ORGANIZATION_SETTING_MISSING_OR_UNUSABLE = DossierRerankOutcome::REASON_ORGANIZATION_SETTING_MISSING_OR_UNUSABLE;

    public const RERANK_NO_CREDENTIAL = DossierRerankOutcome::REASON_NO_CREDENTIAL;

    public const RERANK_PROVIDER_UNAVAILABLE = DossierRerankOutcome::REASON_PROVIDER_UNAVAILABLE;

    public const RERANK_EMPTY_AFTER_DISTANCE_FILTER = DossierRerankOutcome::REASON_EMPTY_AFTER_DISTANCE_FILTER;

    public const RERANK_NOT_APPLICABLE = DossierRerankOutcome::REASON_NOT_APPLICABLE;

    // -----------------------------------------------------------------
    // Famille 5 — la DEGRADATION d'un tour rendu quand meme (AiTurnState)
    // -----------------------------------------------------------------

    public const DEGRADED_PROVIDER_UNAVAILABLE = AiTurnState::DEGRADED_PROVIDER_UNAVAILABLE;

    public const DEGRADED_REQUEST_PREPARATION_UNAVAILABLE = AiTurnState::DEGRADED_REQUEST_PREPARATION_UNAVAILABLE;

    public const DEGRADED_PARTIAL_FAILURE = AiTurnState::DEGRADED_PARTIAL_FAILURE;

    public const DEGRADED_SOURCE_DENIED = AiTurnState::DEGRADED_SOURCE_DENIED;

    public const DEGRADED_TENANT_SCOPE = AiTurnState::DEGRADED_TENANT_SCOPE;

    public const DEGRADED_LOOP_SCOPE = AiTurnState::DEGRADED_LOOP_SCOPE;

    /**
     * Tous les codes que CE squelette connait, par famille d'origine.
     *
     * Sert la garde de V0-A : un code ecrit dans une trace doit venir d'ici.
     * La liste est INCOMPLETE par construction — c'est V0-C qui la fermera, et
     * c'est seulement a ce moment-la qu'un test pourra exiger que TOUT statut
     * bloquant porte un code connu.
     *
     * @return array<string, list<string>>
     */
    public static function byFamily(): array
    {
        return [
            'economic' => [
                self::ECONOMIC_MONTHLY_BUDGET_REACHED,
                self::ECONOMIC_UNKNOWN_QUOTA_REACHED,
                self::ECONOMIC_ORGANIZATION_BUDGET_REACHED,
                self::ECONOMIC_EMBEDDING_UNKNOWN_QUOTA_REACHED,
                self::ECONOMIC_USER_CREDIT_EXHAUSTED,
            ],
            'refused' => [
                self::REFUSED_USER_CREDIT_EXHAUSTED,
                self::REFUSED_ORGANIZATION_BUDGET_REACHED,
                self::REFUSED_NOT_CONFIGURED,
                self::REFUSED_UNAVAILABLE,
                self::REFUSED_AUTHENTICATION_REQUIRED,
            ],
            'source' => [
                self::SOURCE_NO_LOOP_IN_CONTEXT,
                self::SOURCE_LOOP_OUTSIDE_ORGANIZATION,
                self::SOURCE_NO_USER_IN_CONTEXT,
            ],
            'rerank' => [
                self::RERANK_BELOW_MINIMUM_CANDIDATES,
                self::RERANK_EMPTY_QUERY,
                self::RERANK_GATE_CLOSED,
                self::RERANK_ORGANIZATION_SETTING_MISSING_OR_UNUSABLE,
                self::RERANK_NO_CREDENTIAL,
                self::RERANK_PROVIDER_UNAVAILABLE,
                self::RERANK_EMPTY_AFTER_DISTANCE_FILTER,
                self::RERANK_NOT_APPLICABLE,
            ],
            'degraded' => [
                self::DEGRADED_PROVIDER_UNAVAILABLE,
                self::DEGRADED_REQUEST_PREPARATION_UNAVAILABLE,
                self::DEGRADED_PARTIAL_FAILURE,
                self::DEGRADED_SOURCE_DENIED,
                self::DEGRADED_TENANT_SCOPE,
                self::DEGRADED_LOOP_SCOPE,
            ],
        ];
    }

    /**
     * La liste plate des codes connus de ce squelette.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::byFamily()))));
    }

    /**
     * Ce code vient-il d'un vocabulaire deja etabli du depot ?
     *
     * `false` ne signifie PAS « code invalide » tant que V0-C n'a pas ferme le
     * registre : il signifie « ce squelette ne le connait pas encore ».
     */
    public static function isKnown(?string $code): bool
    {
        return $code !== null && in_array($code, self::all(), true);
    }
}
