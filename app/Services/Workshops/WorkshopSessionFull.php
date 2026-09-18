<?php

namespace App\Services\Workshops;

use App\Models\WorkshopSession;
use RuntimeException;

/** TASK-1453 — La session est complete au moment canonique de l'inscription : refus, rien d'ecrit. */
final class WorkshopSessionFull extends RuntimeException
{
    public function __construct(public readonly WorkshopSession $session)
    {
        parent::__construct('This session is full.');
    }
}
