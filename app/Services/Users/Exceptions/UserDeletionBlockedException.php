<?php

namespace App\Services\Users\Exceptions;

use RuntimeException;

/**
 * TASK-1636 — la suppression est refusee, et on sait DIRE pourquoi.
 *
 * Levee a l'interieur de la transaction de `UserDeletionExecutor::execute()`,
 * apres le verrou et le recontrole autoritatif. Elle annule donc tout ce que la
 * transaction avait commence, et rend a l'appelant les raisons exactes du
 * refus plutot qu'un echec de contrainte illisible.
 */
class UserDeletionBlockedException extends RuntimeException
{
    /**
     * @param  list<array{key: string, count: int, message: string}>  $blocks
     */
    public function __construct(public readonly array $blocks)
    {
        parent::__construct(
            'Suppression refusee : '.implode(', ', array_column($blocks, 'key'))
        );
    }
}
