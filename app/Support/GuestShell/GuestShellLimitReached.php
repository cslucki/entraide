<?php

namespace App\Support\GuestShell;

use App\Models\GuestConversation;
use RuntimeException;

/**
 * TASK-1434 — SW-4 : la limite de messages de la conversation est atteinte
 * (ou la conversation n'accepte plus rien). Levee AVANT tout appel provider ;
 * la conversation est preservee, jamais purgee par ce refus.
 */
final class GuestShellLimitReached extends RuntimeException
{
    public function __construct(public readonly GuestConversation $conversation, public readonly string $reason)
    {
        parent::__construct("Guest shell limit reached [{$reason}] for conversation {$conversation->id}.");
    }
}
