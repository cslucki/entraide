<?php

namespace App\Services\Ai\DTO;

/**
 * TASK-1567 / CDC-01 V0-L — ce que la memoire du Shell a REELLEMENT donne au
 * modele, et ce qu'elle a coute a construire.
 *
 * ## Pourquoi cet objet existe
 *
 * `AiShellResponder::conversationMemory()` rendait une `string`. Le transcript
 * partait au prompt, et les identifiants des messages qui l'avaient compose
 * etaient perdus sur place — alors qu'ils venaient d'etre parcourus.
 *
 * ## Ce que `messageIds` est, et n'est pas
 *
 * Ce sont les messages REELLEMENT INJECTES : ceux qui ont survecu a TOUS les
 * filtres de `conversationMemory()` — `remembered()`, la cle d'objet de page
 * (T1523/T1524), la visibilite COURANTE de l'objet (T1530), puis le budget de
 * caracteres.
 *
 * Ce ne sont PAS ceux que `AiShellThread::messages()` a rendus. La fenetre
 * candidate est systematiquement plus large que le contexte injecte, et les
 * confondre ecrirait une trace fausse et plausible — celle qui inspire le plus
 * confiance et trompe le mieux. CDC-01 P0.12 l'exclut mot pour mot : « ce que
 * le moteur lui a REELLEMENT donne — jamais ce qu'il aurait du voir ».
 *
 * ## `chars` et `budgetExhausted`
 *
 * `chars` est la taille du transcript reellement injecte, en-tete compris —
 * pas le sous-total budgetaire, qui aurait donne un nombre plus propre et faux.
 *
 * `budgetExhausted` dit qu'une contrainte de budget a ampute la memoire : soit
 * le tour le plus recent a ete tronque, soit un tour plus ancien a ete
 * abandonne. Il ne s'allume jamais parce que le fil s'est simplement epuise.
 */
final class ShellConversationMemory
{
    /** @param  list<string>  $messageIds */
    public function __construct(
        public readonly string $text,
        public readonly array $messageIds = [],
        public readonly int $chars = 0,
        public readonly bool $budgetExhausted = false,
    ) {}

    public static function vide(): self
    {
        return new self('');
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /**
     * Le bloc `turn.history` de CDC-01 P0.12, tel que le writer le persistera.
     *
     * @return array<string, mixed>
     */
    public function history(): array
    {
        return [
            'strategy' => 'shell_thread',
            'message_ids' => $this->messageIds,
            'count' => count($this->messageIds),
            'chars' => $this->chars,
            // Le fil du Shell n'a pas de declencheur au sens d'une chaine de
            // reply : la continuite y vient du `conversation_id`, pas d'un
            // message cible. `null` est donc la valeur EXACTE, pas un trou.
            'trigger_id' => null,
            'budget_exhausted' => $this->budgetExhausted,
        ];
    }
}
