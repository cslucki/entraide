<?php

namespace App\Support\Images;

use App\Models\ImageOptimizationBatch;
use RuntimeException;

/**
 * TASK-1873 — un lot est deja actif : on n'en lance pas un second.
 */
final class ActiveBatchExists extends RuntimeException
{
    public function __construct(public readonly ?ImageOptimizationBatch $batch)
    {
        parent::__construct('Un lot d optimisation est deja en cours.');
    }
}
