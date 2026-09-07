<?php

namespace App\Services\GuestShell;

use App\Models\AdminAiPrompt;
use App\Support\GuestShell\GuestShellPrompt;

/**
 * TASK-1432 — SW-2 : resout le prompt d'accueil du Shell Welcome depuis la
 * BASE (registre `admin_ai_prompts`, scenario `guest_shell_welcome`), et
 * seulement depuis la base.
 *
 * Resolution (Addendum V2 §5) : version active la plus recente du scenario
 * plateforme → sinon NULL = fail-closed (le Shell Welcome n'appelle aucun
 * provider et affiche un accueil non-IA avec CTA compte). Aucun override
 * Organization en V1 (MASTER Q46) : la personnalisation vient du contexte
 * public ajoute par le runtime a l'execution.
 *
 * Ce service ne contient AUCUN texte de prompt : sabotage S = supprimer la
 * ligne active doit rendre null, pas un texte de secours.
 */
final class GuestShellPromptResolver
{
    public const SCENARIO = 'guest_shell_welcome';

    public function active(): ?AdminAiPrompt
    {
        return AdminAiPrompt::active()->byScenario(self::SCENARIO)->orderByDesc('version')->first();
    }

    /**
     * Le prompt actif tel qu'il est en base, ou null (fail-closed). Aucun
     * templating maison (MASTER Q46 refocus 2) : le registre n'en a pas, le
     * runtime Guest (SW-5/SW-8) ajoutera identite, locale et contexte PUBLIC
     * de l'Organization A LA SUITE du texte, comme le font les responders
     * du Shell membre.
     */
    public function resolve(): ?GuestShellPrompt
    {
        $prompt = $this->active();
        if ($prompt === null) {
            return null;
        }

        return new GuestShellPrompt($prompt->scenario_id, (int) $prompt->version, (string) $prompt->getKey(), (string) $prompt->prompt_text);
    }
}
