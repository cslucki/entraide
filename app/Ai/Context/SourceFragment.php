<?php

namespace App\Ai\Context;

/**
 * Ce qu'une source rend au Context Builder (TASK-1209 / IA P3).
 */
final class SourceFragment
{
    /**
     * @param  list<array{source: string, id: string, type: string, extrait: string}>  $provenance
     */
    public function __construct(
        public readonly string $text,
        public readonly array $provenance,
    ) {}

    public static function empty(): self
    {
        return new self('', []);
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
