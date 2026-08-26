<?php

namespace App\Services\Ai;

use App\Models\LoopMessage;
use App\Services\Ai\DTO\ConversationContext;

/**
 * Contexte de conversation borne, agnostique du moteur (TASK-1308).
 *
 * Remonte la chaine `reply_to_id` depuis le message DEJA PERSISTE qui
 * declenche un tour IA (mode IA ou mode Dossiers), quel que soit le type de
 * son parent — humain OU IA. TASK-1300 ne remontait le fil que si le parent
 * etait un message IA (`continuationParent()`, dedie a l'auto-detection
 * d'une reponse a `/ia`) ; TASK-1308 retire cette auto-detection — le choix
 * du moteur est desormais explicite dans le composeur — et generalise le
 * contexte a TOUT reply, y compris a une bulle humaine (brief section 16).
 *
 * Le contexte AIDE a comprendre la question (« cela », « ce point ») ; il
 * n'est JAMAIS une source documentaire — chaque moteur reste seul
 * responsable de son propre grounding (brief section 19).
 *
 * Bornes reprises telles quelles de TASK-1300 (arbitrage Cyril 24/08, non
 * rouvertes ici) : MAX_THREAD_DEPTH messages ET `ai.chatloop.max_context_chars`
 * caracteres, le plus ancien tronque en premier, le parent direct toujours
 * conserve (tronque au besoin). Seuls les types `user` et `ai` entrent au
 * transcript ; un maillon d'un autre type arrete la remontee, un maillon
 * supprime est saute (son slot de profondeur est consomme).
 */
class AiConversationContextBuilder
{
    private const MAX_THREAD_DEPTH = 6;

    public function build(?LoopMessage $trigger): ConversationContext
    {
        $parent = $trigger?->replyTo;

        if ($parent === null || $parent->isDeleted() || ! in_array($parent->type, ['user', 'ai'], true)) {
            return new ConversationContext('', []);
        }

        $budget = (int) config('ai.chatloop.max_context_chars', 12000);
        $lines = [];
        $ids = [];
        $total = 0;
        $current = $parent;

        for ($depth = 0; $depth < self::MAX_THREAD_DEPTH && $current !== null; $depth++) {
            if (! in_array($current->type, ['user', 'ai'], true)) {
                break;
            }

            if (! $current->isDeleted()) {
                $line = ($current->type === 'ai' ? 'Assistant : ' : 'Membre : ').trim((string) $current->body);

                if ($lines === []) {
                    $line = mb_substr($line, 0, $budget);
                    $lines[] = $line;
                    $ids[] = $current->id;
                    $total = mb_strlen($line);
                } elseif ($total + mb_strlen($line) + 1 <= $budget) {
                    $lines[] = $line;
                    $ids[] = $current->id;
                    $total += mb_strlen($line) + 1;
                } else {
                    break;
                }
            }

            $current = $current->replyTo;
        }

        if ($lines === []) {
            return new ConversationContext('', []);
        }

        $text = "Echange precedent dans la Boucle :\n".implode("\n", array_reverse($lines));

        return new ConversationContext($text, array_reverse($ids));
    }
}
