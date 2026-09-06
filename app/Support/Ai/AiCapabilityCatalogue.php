<?php

namespace App\Support\Ai;

use App\Ai\CapabilityRegistry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiShellResponder;

/**
 * TASK-1350 — le petit catalogue des capacites REELLEMENT offertes a ce
 * membre, dans CETTE organisation.
 *
 * ## Pourquoi un catalogue, et pourquoi si petit
 *
 * « Que puis-je faire ici ? » est la seule question de self-knowledge dont la
 * reponse depend du tenant. Y repondre en listant les fonctionnalites du
 * produit serait mentir a une organisation qui a desactive les Boucles ou les
 * profils IA. Ce catalogue lit donc les MEMES drapeaux que les surfaces
 * elles-memes, et ne nomme que ce qui est ouvert.
 *
 * ## Ce qu'il n'est pas
 *
 * Ni un registre de capabilities IA ({@see CapabilityRegistry} garde ce
 * role), ni une autorite d'acces, ni une table de navigation. Il ne porte
 * VOLONTAIREMENT aucune URL : la reponse du Shell est du texte, et une adresse
 * qu'on ne peut pas cliquer n'aide personne. Emmener quelqu'un quelque part est
 * le sujet de Context-to-Action, pas celui-ci. Aucune nouvelle table, aucune
 * nouvelle route, aucun parsing de Blade : les drapeaux sont ceux
 * d'`Organization`, les libelles ceux de `lang/{fr,en}/ai.php` — donc
 * « organisation » en francais visible, jamais « Organization ».
 *
 * ## Pourquoi la classe n'est pas `final`
 *
 * Elle ne protege aucun invariant : elle lit des drapeaux et rend des libelles.
 * La laisser extensible permet d'en substituer un double la ou le comportement
 * de REPLI compte — un catalogue qui leve doit renvoyer le tour au provider
 * legacy, jamais produire une erreur ({@see AiShellResponder}).
 * C'est un chemin de secours qu'on doit pouvoir prouver.
 */
class AiCapabilityCatalogue
{
    /**
     * Les capacites ouvertes a ce membre, dans l'ordre ou on les lui presente :
     * demander, proposer, se relier, se rendre trouvable, parler a l'IA.
     *
     * @return list<array{key: string, label: string}>
     */
    public function forMember(Organization $organization, User $user): array
    {
        $entries = [
            ['key' => 'ask_help', 'label' => __('ai.self_knowledge_capability_ask_help')],
            ['key' => 'offer_help', 'label' => __('ai.self_knowledge_capability_offer_help')],
        ];

        // Meme drapeau que la navigation : une organisation sans Boucles ne
        // s'entend pas dire qu'elle en a.
        if ((bool) $organization->loops_enabled) {
            $entries[] = ['key' => 'loops', 'label' => __('ai.self_knowledge_capability_loops')];

            // `membersCanCreateLoops()` est l'autorite du modele — on ne
            // recompose pas la regle ici.
            if ($organization->membersCanCreateLoops()) {
                $entries[] = ['key' => 'create_loop', 'label' => __('ai.self_knowledge_capability_create_loop')];
            }
        }

        if ((bool) $organization->ai_profiles_enabled) {
            $entries[] = ['key' => 'ai_profile', 'label' => __('ai.self_knowledge_capability_ai_profile')];
        }

        // Toujours en dernier : la personne y est deja.
        $entries[] = ['key' => 'assistant', 'label' => __('ai.self_knowledge_capability_assistant')];

        return $entries;
    }
}
