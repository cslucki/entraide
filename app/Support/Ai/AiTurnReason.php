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
 * que CDC-01 P0.4 annonce comme « nouveaux codes necessaires » et qu'AUCUN
 * etage n'emet encore (`NO_GROUNDED_EVIDENCE`, `FAKE_PROVIDER_FALLBACK`,
 * `FEATURE_DISABLED`, `RERANK_NOT_CONFIGURED`). Les inventer ici reviendrait a
 * figer, sans les etages qui les emettent, un vocabulaire que personne n'aurait
 * encore eu l'occasion de confronter au code reel.
 *
 * ## Ce que V0-G (TASK-1568) y a ajoute — et a quel titre
 *
 * Deux familles NOUVELLES, de statut different, a ne pas confondre :
 *
 *   `context_builder` — EMISE par V0-G. Les deux codes de bypass sont ecrits
 *   des aujourd'hui par les writers dont le moteur n'appelle pas le
 *   `ContextBuilder` que sa capability declare (CDC-01 P0.3, P0.7, C3).
 *
 *   `fallthrough` — VOCABULAIRE GELE, AUCUN EMETTEUR (decision G-β, C20).
 *   Les 25 `return null` du dispatcher Shell rendent la main a la branche
 *   suivante sans qu'aucune identite de tour commune n'existe entre la
 *   tentative declinee et le writer final (`AiShellResponder::respond()` ne
 *   mint aucun turnId ; chaque moteur mint le sien). Une etape deposee sous le
 *   turnId d'un moteur qui decline ne serait JAMAIS reclamee : elle
 *   disparaitrait en silence. `TRACE0_SCHEMA_FROZEN` gele donc le NOM de ces
 *   codes, et V0-I — qui porte l'identite Shell — les branchera. Ecrire un
 *   `AiTurnTrace::step()` avec l'un d'eux avant V0-I est une faute : un test
 *   statique le garde.
 *
 *   Trois de ces codes (`NO_SOURCES_FOUND`, `EMPTY_MODEL_ANSWER`,
 *   `CONTEXT_EMPTY`) sont ceux que P0.4 annoncait pour V0-C : ils sont poses ici
 *   parce que le mapping des fallthroughs les REFERENCE (arbitrage S1 : V0-C les
 *   reprend tels quels et reste proprietaire de la fermeture du registre).
 *
 *   Ces codes sont ecrits en MAJUSCULES, graphie du CDC (`reason_code:
 *   DOCUMENT_PATH_DIRECT_EXECUTION`, §5.2 et scenario 8) : ce ne sont pas des
 *   alias d'un vocabulaire existant, il n'y a donc pas de graphie d'origine a
 *   respecter. L'harmonisation eventuelle avec les familles 1-5 (minuscules)
 *   est une decision de V0-C, pas un detail a trancher ici.
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

    // -----------------------------------------------------------------
    // Famille 6 — pourquoi le CONTEXT BUILDER a ete bypasse (V0-G, EMISE)
    // -----------------------------------------------------------------

    /**
     * `LOOP_ASK` declare `loop.messages`, mais `respondInThread()` ne construit
     * jamais ce contexte : il lit la chaine de reply via
     * `AiConversationContextBuilder`, un autre composant (CDC-01 C3). Un FACT
     * architectural rendu lisible, pas un bug qualifie.
     */
    public const CONTEXT_BUILDER_LLM_PATH_NO_CONTEXT_BUILDER = 'LLM_PATH_NO_CONTEXT_BUILDER';

    /**
     * `DossierInsightsService` recherche et repond directement sur ses sources,
     * sans passer par le `ContextBuilder` que `LOOP_KNOWLEDGE_ANSWER` declare —
     * la ou `LoopKnowledgeAnswerService`, sous la MEME capability, l'appelle
     * (CDC-01 P0.7). Une capability, deux moteurs, deux comportements.
     */
    public const CONTEXT_BUILDER_DOCUMENT_PATH_DIRECT_EXECUTION = 'DOCUMENT_PATH_DIRECT_EXECUTION';

    // -----------------------------------------------------------------
    // Famille 7 — les FALLTHROUGHS du dispatcher Shell (V0-G, VOCABULAIRE SEUL)
    //
    // 25 points, 8 codes. `skipped` = la branche n'a pas essaye ; `failed` =
    // elle a essaye et casse. AUCUN de ces codes n'est emis avant V0-I (C20).
    // -----------------------------------------------------------------

    /** `skipped` — la page ou la forme de la question ne designe pas cette branche. */
    public const FALLTHROUGH_BRANCH_SHAPE_NOT_MATCHED = 'BRANCH_SHAPE_NOT_MATCHED';

    /** `skipped` — l'identifiant exige (dossier, article, fichier…) est absent du contexte de page. */
    public const FALLTHROUGH_CONTEXT_OBJECT_ABSENT = 'CONTEXT_OBJECT_ABSENT';

    /**
     * `skipped` — objet introuvable OU non visible par ce membre.
     *
     * La fusion est VOULUE et ne doit pas etre affinee : deux codes distincts
     * feraient de la trace un oracle d'existence pour un objet que
     * l'utilisateur n'a pas le droit de voir (invariant de confidentialite).
     */
    public const FALLTHROUGH_OBJECT_NOT_ACCESSIBLE = 'OBJECT_NOT_ACCESSIBLE';

    /** `skipped` — le contenu de l'objet est vide une fois les balises retirees. */
    public const FALLTHROUGH_CONTEXT_EMPTY = 'CONTEXT_EMPTY';

    /** `failed` — le moteur a ESSAYE et leve (`catch (\Throwable)` + `report()`). */
    public const FALLTHROUGH_ENGINE_EXCEPTION = 'ENGINE_EXCEPTION';

    /** `skipped` — le moteur a repondu sans consulter aucune source (`consulted === []`). */
    public const FALLTHROUGH_NO_SOURCES_FOUND = 'NO_SOURCES_FOUND';

    /** `skipped` — le modele a rendu une reponse vide apres nettoyage. */
    public const FALLTHROUGH_EMPTY_MODEL_ANSWER = 'EMPTY_MODEL_ANSWER';

    /** `skipped` — la resolution de reference n'a produit aucun candidat. */
    public const FALLTHROUGH_NO_REFERENCE_CANDIDATE = 'NO_REFERENCE_CANDIDATE';

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
            'context_builder' => [
                self::CONTEXT_BUILDER_LLM_PATH_NO_CONTEXT_BUILDER,
                self::CONTEXT_BUILDER_DOCUMENT_PATH_DIRECT_EXECUTION,
            ],
            'fallthrough' => self::fallthroughVocabulary(),
        ];
    }

    /**
     * Le vocabulaire GELE des fallthroughs Shell — sans emetteur avant V0-I.
     *
     * Expose a part pour que la garde de scope (aucun `step()` ne porte l'un de
     * ces codes avant V0-I) puisse l'enumerer sans connaitre les autres familles.
     *
     * @return list<string>
     */
    public static function fallthroughVocabulary(): array
    {
        return [
            self::FALLTHROUGH_BRANCH_SHAPE_NOT_MATCHED,
            self::FALLTHROUGH_CONTEXT_OBJECT_ABSENT,
            self::FALLTHROUGH_OBJECT_NOT_ACCESSIBLE,
            self::FALLTHROUGH_CONTEXT_EMPTY,
            self::FALLTHROUGH_ENGINE_EXCEPTION,
            self::FALLTHROUGH_NO_SOURCES_FOUND,
            self::FALLTHROUGH_EMPTY_MODEL_ANSWER,
            self::FALLTHROUGH_NO_REFERENCE_CANDIDATE,
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
