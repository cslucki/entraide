<?php

namespace App\Services\Ai\DTO;

/**
 * Contexte de conversation borne (TASK-1308) — agnostique du moteur qui
 * l'utilise (RAG ou LLM direct). `text` alimente le prompt ; `messageIds` est
 * la provenance (pour l'observabilite/`context_message_ids`), jamais une
 * source documentaire.
 *
 * TASK-1567 / CDC-01 V0-L — deux mesures s'ajoutent, et AUCUNE ne change ce que
 * le moteur fait : elles disent seulement ce qu'il a fait.
 *
 * `chars` est la taille de ce qui a REELLEMENT ete injecte, en-tete compris —
 * pas le sous-total budgetaire. Un lecteur qui compare `chars` au budget doit
 * donc savoir que le budget gouverne les CORPS de messages, et que l'en-tete
 * (« Echange precedent dans la Boucle : ») s'ajoute par-dessus. Mesurer le
 * sous-total aurait donne un nombre plus « propre » et faux : le modele n'a pas
 * recu ce nombre-la.
 *
 * `budgetExhausted` dit qu'une contrainte de budget a REELLEMENT ampute
 * l'historique — soit en tronquant le message conserve, soit en abandonnant un
 * message plus ancien. Il ne s'allume JAMAIS pour un arret du parcours pour une
 * autre raison (type de message, autre Boucle, profondeur maximale, plus de
 * parent) : ces arrets-la ne sont pas une amputation, et les confondre
 * ferait croire a une perte qui n'a pas eu lieu.
 */
final class ConversationContext
{
    /** @param  list<string>  $messageIds */
    public function __construct(
        public readonly string $text,
        public readonly array $messageIds,
        public readonly int $chars = 0,
        public readonly bool $budgetExhausted = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->text === '';
    }
}
