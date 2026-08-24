<?php

namespace App\Ai;

/**
 * Couverture du systeme nerveux IA (TASK-1227) — read model d'affichage.
 *
 * Repond a UNE question pour l'Admin Organization : « quelles fonctions IA
 * suivent la doctrine de mon Organization, et lesquelles ne la suivent PAS
 * (encore) ? ». Objectif : qu'un Admin ne soit jamais trompe sur la portee de
 * sa doctrine.
 *
 * - COUVERT : derive de l'autorite existante, `CapabilityRegistry::all()`.
 *   Chaque capability declaree la-bas passe par `PromptRepository::compose()`
 *   (Constitution -> Doctrine -> instruction). Aucune liste codee ici.
 * - HERITE : les fonctions IA visibles par l'Organization qui n'empruntent
 *   pas encore ce chemin (appels HTTP directs, SupervisionProviderResolver,
 *   prompts admin_ai_prompts sans Constitution). Aucun registre n'en fait
 *   l'inventaire : elles sont DECLAREES A LA MAIN ici, et ici seulement
 *   (voir TASK-1227, section « Couverture »). Cette TASK n'en migre aucune :
 *   on affiche la verite, on ne la corrige pas encore.
 *
 * Ce n'est ni un second registre, ni une autorite : `covered()` ne fait que
 * lire le registre, `inherited()` ne fait que nommer une dette.
 */
final class NervousSystemCoverage
{
    /**
     * Fonctions IA heritees, hors doctrine, cle -> point d'entree principal.
     * Les libelles humains vivent dans lang/{fr,en}/ai.php sous
     * `ai.inherited_label.<cle>`.
     *
     * @var array<string, string>
     */
    public const INHERITED = [
        // Couvre les trois surfaces de l'agent de profil (reponse automatique
        // dans une Boucle agent, chat visiteur, configuration
        // conversationnelle) : toutes passent par cette classe.
        'member_profile_agent' => 'App\Services\Ai\MemberProfileAgentResponder',
        // TASK-1233 : `chatloop_direct_answer` (ChatLoopAiService::answer/ask)
        // est sorti d'ici — capabilities canoniques `loop_answer` / `loop_ask`.
        // TASK-1284 : `blog_ai` (generer/corriger) est sorti d'ici —
        // capabilities canoniques `blog_generate` / `blog_correct`. Ce qui
        // reste hors doctrine dans BlogAiService est la suggestion IA sur
        // selection (`methodSelection()`), declaree sous sa propre cle :
        // l'Admin Organization n'est jamais trompe sur la portee reelle.
        'blog_method_selection' => 'App\Services\BlogAiService::methodSelection',
        // TASK-1253 (G16 du gap analysis T1246) : l'Explorer d'article
        // (dialogue deep-chat, note d'analyse) est une fonction IA visible de
        // l'Organization qui n'emprunte pas la doctrine — il etait absent de
        // cet inventaire, l'Admin Organization n'en etait pas informe. Sous
        // autorite economique depuis TASK-1248, hors doctrine toujours.
        'blog_explorer' => 'App\Http\Controllers\BlogExplorerController',
        'service_offer_formulation' => 'App\Http\Controllers\ServiceController::formulate',
    ];

    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    /**
     * @return list<CapabilityDefinition>
     */
    public function covered(): array
    {
        return $this->capabilities->all();
    }

    /**
     * @return list<string>
     */
    public function inherited(): array
    {
        return array_keys(self::INHERITED);
    }

    public function coveredCount(): int
    {
        return count($this->covered());
    }

    public function totalCount(): int
    {
        return $this->coveredCount() + count(self::INHERITED);
    }
}
