<?php

namespace App\Support\Images;

/**
 * TASK-1873 — ce qu'un traitement a fait d'UNE source.
 */
final readonly class ImageProcessOutcome
{
    public const PROCESSED = 'processed';

    public const UP_TO_DATE = 'up_to_date';

    public const LOCKED = 'locked';

    public const SKIPPED = 'skipped';

    public const MISSING = 'missing';

    public const FAILED = 'failed';

    /**
     * @param  int  $generated  variantes (re)ecrites par ce passage
     * @param  int|null  $sourceBytes  poids de l'original, quand il a ete lu
     * @param  int  $variantBytes  poids cumule des variantes ecrites
     */
    public function __construct(
        public string $status,
        public ?string $reason = null,
        public int $generated = 0,
        public ?int $sourceBytes = null,
        public int $variantBytes = 0,
    ) {}

    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::PROCESSED, self::UP_TO_DATE], true);
    }
}
