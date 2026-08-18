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
        'member_profile_agent' => 'App\Services\Ai\MemberProfileAgentResponder',
        // TASK-1233 : `chatloop_direct_answer` (ChatLoopAiService::answer/ask)
        // est sorti d'ici — capabilities canoniques `loop_answer` / `loop_ask`.
        'blog_ai' => 'App\Services\BlogAiService',
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
