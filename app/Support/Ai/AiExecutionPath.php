<?php

namespace App\Support\Ai;

/**
 * TASK-1568 / CDC-01 V0-G — le registre des chemins d'execution : chaque
 * chemin porte son nom.
 *
 * ## Ce qu'est un `execution_path`
 *
 * Le nom stable du CHEMIN PRODUIT reellement execute par un tour IA
 * (CDC-01 P0.2). Il distingue les chemins actuels sans les unifier : deux
 * points d'entree qui partagent un moteur restent deux chemins, parce que ce
 * qui les separe (surface, gardes, publication, forme de la reponse) est
 * precisement ce qu'une trace doit rendre lisible.
 *
 * Il est ecrit sous `turn.identity.execution_path` par le writer du moteur,
 * lu par `AiTurnInspection`. Aucun lecteur produit ne le consomme.
 *
 * ## L'appelant est l'autorite (correction C15)
 *
 * Un moteur partage ne peut PAS deduire son propre chemin. FACT corrige par
 * cette TASK : V0-A derivait la valeur du seul `$mode` dans
 * `LoopKnowledgeAnswerService`, si bien que l'endpoint JSON
 * `LoopController::knowledge()` etait trace `loop_chat.dossiers` — une trace
 * plausible et fausse, exactement ce que CDC-01 interdit.
 *
 * La regle est donc : le POINT D'ENTREE fournit son chemin au moteur, en
 * parametre nomme, comme il lui fournit deja `turnId:` ou `$history`. Le
 * moteur l'ecrit tel quel. S'il ne recoit rien, il n'ecrit RIEN — la cle est
 * absente et se lit `UNAVAILABLE` (CDC-01 §11). Jamais un defaut derive, jamais
 * une inspection de pile : une valeur devinee se lirait comme une mesure.
 *
 * ## 18 valeurs gelees, 14 ecrites (C14)
 *
 * Le gate `TRACE0_SCHEMA_FROZEN` gele le VOCABULAIRE — les 18 constantes
 * ci-dessous — pas la couverture runtime. Les quatre branches Shell
 * zero-provider (`self_knowledge`, `reference`, `people_matching`,
 * `people_self`) ecrivent dans `ai_shell_messages.metadata`, un support qui ne
 * porte aujourd'hui aucune identite de tour (C11). Les nommer ici les fige ;
 * les ECRIRE appartient a V0-I, qui possede cette identite.
 *
 * `ai_shell.people` du CDC est scinde en deux (C16) : `peopleTurn()` separe
 * explicitement « qui me correspond » de « mon propre profil » — une frontiere
 * de securite (TASK-1546), pas une nuance de formulation.
 *
 * ## Ce que ce registre n'est pas
 *
 * Ni un `producer` (V0-D — deux axes distincts : le producer dit QUI a
 * fabrique la reponse, le chemin dit PAR OU elle est passee), ni une
 * `capability`, ni un `feature`/`scenario_id` de prompt. Il n'introduit aucun
 * comportement : le supprimer ne changerait pas une reponse.
 */
final class AiExecutionPath
{
    // ----------------------------------------------------------------
    // LoopChat — composeur, 3 modes IA + chemins herites
    // ----------------------------------------------------------------

    /** `ChatLoopAiService::respondInThread()` — mode IA du composeur. */
    public const LOOP_CHAT_IA = 'loop_chat.ia';

    /** `LoopChat::respondWithDossiers()` → `LoopKnowledgeAnswerService::answer()`. */
    public const LOOP_CHAT_DOSSIERS = 'loop_chat.dossiers';

    /** `LoopChat` mode IA + Dossiers → `LoopKnowledgeAnswerService::answerHybrid()`. */
    public const LOOP_CHAT_IA_DOSSIERS = 'loop_chat.ia_dossiers';

    /** `ChatLoopAiService::ask()` — formulaire `loops.ai` herite. */
    public const LOOP_CHAT_LEGACY_ASK = 'loop_chat.legacy_ask';

    /** `ChatLoopAiService::answer()` — « Demander a l'IA » herite. */
    public const LOOP_CHAT_LEGACY_ANSWER = 'loop_chat.legacy_answer';

    /** `LoopController::knowledge()` — endpoint JSON de la modale knowledge. */
    public const LOOP_CONTROLLER_KNOWLEDGE_JSON = 'loop_controller.knowledge_json';

    // ----------------------------------------------------------------
    // AI Shell — 9 branches heuristiques (10 noms, C16)
    // ----------------------------------------------------------------

    /** Zero-provider — RESERVE a V0-I. */
    public const AI_SHELL_SELF_KNOWLEDGE = 'ai_shell.self_knowledge';

    /** `AiShellResponder::dossierAnswerTurn()` → `DossierInsightsService::answer()`. */
    public const AI_SHELL_DOSSIER = 'ai_shell.dossier';

    /** `AiShellResponder::articleAnswerTurn()` → `DossierInsightsService::answerOverSources()`. */
    public const AI_SHELL_ARTICLE = 'ai_shell.article';

    /** `AiShellResponder::dossierContinuationTurn()` → `DossierInsightsService::answer()`. */
    public const AI_SHELL_CONTINUATION = 'ai_shell.continuation';

    /** Zero-provider — RESERVE a V0-I. */
    public const AI_SHELL_REFERENCE = 'ai_shell.reference';

    /** Zero-provider — RESERVE a V0-I. `peopleTurn()`, « qui me correspond ». */
    public const AI_SHELL_PEOPLE_MATCHING = 'ai_shell.people_matching';

    /** Zero-provider — RESERVE a V0-I. `peopleTurn()`, « mon propre profil ». */
    public const AI_SHELL_PEOPLE_SELF = 'ai_shell.people_self';

    /** Decouverte documentaire du Shell → `DossierInsightsService::answerOverSources()`. */
    public const AI_SHELL_DISCOVERY = 'ai_shell.discovery';

    /** `ShellGeneralAnswerService::answer()`. */
    public const AI_SHELL_GENERAL = 'ai_shell.general';

    /** `AiShellResponder::generate()` → `ClarifyUserHelpRequestService::clarifyForOrganization()` (C6). */
    public const AI_SHELL_CLARIFY = 'ai_shell.clarify';

    // ----------------------------------------------------------------
    // Pages Dossier — meme moteur que les branches Shell documentaires
    // ----------------------------------------------------------------

    /** `DossierAnswerController` → `DossierInsightsService::answer()`. */
    public const DOSSIER_PAGE_ANSWER = 'dossier_page.answer';

    /** `DossierInsightsController` → `DossierInsightsService::generate()`. */
    public const DOSSIER_PAGE_INSIGHTS = 'dossier_page.insights';

    /**
     * Les 18 chemins geles par `TRACE0_SCHEMA_FROZEN`.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LOOP_CHAT_IA,
            self::LOOP_CHAT_DOSSIERS,
            self::LOOP_CHAT_IA_DOSSIERS,
            self::LOOP_CHAT_LEGACY_ASK,
            self::LOOP_CHAT_LEGACY_ANSWER,
            self::LOOP_CONTROLLER_KNOWLEDGE_JSON,
            self::AI_SHELL_SELF_KNOWLEDGE,
            self::AI_SHELL_DOSSIER,
            self::AI_SHELL_ARTICLE,
            self::AI_SHELL_CONTINUATION,
            self::AI_SHELL_REFERENCE,
            self::AI_SHELL_PEOPLE_MATCHING,
            self::AI_SHELL_PEOPLE_SELF,
            self::AI_SHELL_DISCOVERY,
            self::AI_SHELL_GENERAL,
            self::AI_SHELL_CLARIFY,
            self::DOSSIER_PAGE_ANSWER,
            self::DOSSIER_PAGE_INSIGHTS,
        ];
    }

    /**
     * Les 4 chemins NOMMES ici mais ECRITS par V0-I (C14, C20).
     *
     * Aucun writer d'`ai_interactions` ne doit jamais porter l'un d'eux : ces
     * branches n'atteignent aucun provider et n'ecrivent aucune interaction.
     *
     * @return list<string>
     */
    public static function reservedForShellZeroProvider(): array
    {
        return [
            self::AI_SHELL_SELF_KNOWLEDGE,
            self::AI_SHELL_REFERENCE,
            self::AI_SHELL_PEOPLE_MATCHING,
            self::AI_SHELL_PEOPLE_SELF,
        ];
    }

    /**
     * Les 14 chemins qu'un writer d'`ai_interactions` peut porter des V0-G.
     *
     * @return list<string>
     */
    public static function writtenByInteractionWriters(): array
    {
        return array_values(array_diff(self::all(), self::reservedForShellZeroProvider()));
    }

    public static function isKnown(?string $path): bool
    {
        return $path !== null && in_array($path, self::all(), true);
    }
}
