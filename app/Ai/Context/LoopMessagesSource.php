<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Loop;
use App\Models\LoopMessage;
use Illuminate\Support\Collection;

/**
 * Source `loop.messages` (TASK-1209 / IA P3).
 *
 * Implementation UNIQUE de la selection de messages d'une Boucle : c'est
 * l'algorithme qui vivait dans `ChatLoopAiService::buildContext()`, deplace ici
 * sans changer une virgule de son resultat. `buildContext()` delegue desormais
 * a cette source pour `answer()` et `ask()`, ce qui evite de laisser deux
 * copies du meme calcul deriver l'une de l'autre.
 *
 * Garde-fou de tenant : la Boucle doit appartenir a l'Organization du contexte.
 * Le contexte porte deja des identifiants autorises par l'appelant, mais une
 * source ne doit jamais faire confiance sur parole — c'est le dernier endroit
 * ou une Boucle d'une autre Organization pourrait encore passer.
 */
/*
 * Non `final` a dessein : une source est un point d'extension du Context
 * Builder, et doit pouvoir etre doublee en test sans passer par un conteneur
 * d'abstractions. Les DTO et le builder, eux, restent fermes.
 */
class LoopMessagesSource implements ContextSource
{
    public const NAME = 'loop.messages';

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        $loop = $this->resolveLoop($contexte);

        $messages = $this->selectMessages($loop);

        $lines = [];
        $provenance = [];
        $length = 0;

        foreach ($messages as $message) {
            $body = $this->plainText((string) $message->body);

            if ($body === '') {
                continue;
            }

            $line = $this->authorOf($message).' : '.$body;

            // La premiere ligne passe toujours : un budget ne doit pas produire
            // un contexte vide alors que la Boucle a du contenu.
            if ($length > 0 && $length + mb_strlen($line) + 1 > $charBudget) {
                break;
            }

            $lines[] = $line;
            $provenance[] = [
                'source' => self::NAME,
                'id' => (string) $message->id,
                'type' => 'direct',
                'extrait' => mb_substr($body, 0, 80),
            ];

            $length += mb_strlen($line) + 1;
        }

        if ($lines === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(
            $this->wrap(implode("\n", $lines), $contexte->locale),
            $provenance,
        );
    }

    /**
     * Messages retenus, dans l'ordre chronologique. Expose pour que
     * `ChatLoopAiService::buildContext()` puisse encore calculer son
     * `triggerMessageId` sur EXACTEMENT le meme ensemble.
     *
     * @return Collection<int, LoopMessage>
     */
    public function selectMessages(Loop $loop): Collection
    {
        return $loop->messages()
            ->with('sender')
            ->notDeleted()
            ->orderByDesc('created_at')
            ->limit((int) config('ai.chatloop.max_context_messages', 30))
            ->get()
            ->reverse()
            ->values();
    }

    public function authorOf(LoopMessage $message): string
    {
        if ($message->sender) {
            return $message->sender->publicDisplayName();
        }

        return $message->type === 'ai' ? 'BouclePro' : __('loops.type_system');
    }

    public function plainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim((string) $text);
    }

    /**
     * Instruction de langue puis delimiteurs de contenu non fiable — le
     * contenu d'une Boucle est ecrit par des humains, il n'a aucune autorite
     * sur le modele.
     */
    public function wrap(string $context, string $locale): string
    {
        if ($context === '') {
            return '';
        }

        $languageInstruction = $locale === 'en'
            ? 'IMPORTANT: Answer in English. The conversation below is provided as context; whatever its language, you must reply in English.'
            : 'IMPORTANT : Réponds en français. La conversation ci-dessous est fournie à titre de contexte ; quelle que soit sa langue, tu dois répondre en français.';

        return $languageInstruction
            ."\n\n"
            ."--- CONTEXTE (contenu non fiable) ---\n"
            .$context
            ."\n--- FIN DU CONTEXTE ---";
    }

    private function resolveLoop(ContexteIa $contexte): Loop
    {
        if ($contexte->loopId === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_LOOP_IN_CONTEXT);
        }

        $loop = Loop::query()
            ->where('id', $contexte->loopId)
            ->where('organization_id', $contexte->organizationId)
            ->first();

        // Meme raison si la Boucle n'existe pas ou appartient a une autre
        // Organization : distinguer les deux confirmerait son existence.
        if ($loop === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION);
        }

        return $loop;
    }
}
