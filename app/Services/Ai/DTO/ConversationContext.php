<?php

namespace App\Services\Ai\DTO;

/**
 * Contexte de conversation borne (TASK-1308) — agnostique du moteur qui
 * l'utilise (RAG ou LLM direct). `text` alimente le prompt ; `messageIds` est
 * la provenance (pour l'observabilite/`context_message_ids`), jamais une
 * source documentaire.
 */
final class ConversationContext
{
    /** @param  list<string>  $messageIds */
    public function __construct(
        public readonly string $text,
        public readonly array $messageIds,
    ) {}

    public function isEmpty(): bool
    {
        return $this->text === '';
    }
}
