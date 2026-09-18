<?php

namespace App\Support\GuestShell;

/**
 * TASK-1435 — SW-5 : ce que le Shell Welcome SAIT d'une Organization — et rien
 * d'autre. Chaque bloc porte la source whitelistee qui l'a produit (Addendum
 * V2 §4, cadre Cyril §8) ; `text()` est ce qui sera ajoute au prompt (SW-8).
 */
final class GuestPublicContext
{
    /**
     * @param  array<int, array{source: string, label: string, text: string}>  $blocks
     * @param  array<int, string>  $sources  sources whitelistees effectivement utilisees
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $organizationName,
        public readonly string $locale,
        public readonly array $blocks,
        public readonly array $sources,
        public readonly int $charBudget,
    ) {}

    public function text(): string
    {
        $parts = [];

        foreach ($this->blocks as $block) {
            $parts[] = '## '.$block['label']."\n".$block['text'];
        }

        return implode("\n\n", $parts);
    }

    public function hasSource(string $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }
}
