<?php

namespace App\Ai;

use InvalidArgumentException;

final class PromptRepository
{
    public function __construct(
        private readonly Constitution $constitution,
        private readonly CapabilityRegistry $capabilities,
    ) {}

    public function compose(string $capability, string $instructions): string
    {
        $definition = $this->capabilities->get($capability);
        $instructions = trim($instructions);

        if ($instructions === '') {
            throw new InvalidArgumentException("Instructions are required for AI capability [{$capability}].");
        }

        return implode("\n\n", [
            $this->constitution->text(),
            "Capability: {$definition->id}",
            "Instructions capability ({$definition->promptKey}):\n{$instructions}",
        ]);
    }
}
