<?php

namespace App\Support\ScenarioManager\Capture;

/**
 * TASK-1652 — ce qu'une Capture a produit, ou ce qui l'a empechee.
 *
 * Un blocker n'est pas une exception : la Capture lit TOUT avant de conclure,
 * pour que l'operateur voie l'inventaire complet de ce qui bloque plutot que le
 * premier obstacle rencontre. Une seule passe, un seul rapport.
 */
final class ScenarioCaptureResult
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<array{famille: string, raison: string, detail: string}>  $blockers
     */
    public function __construct(
        public readonly array $document,
        public readonly array $blockers = [],
    ) {}

    public function estBloquee(): bool
    {
        return $this->blockers !== [];
    }

    /**
     * Le rapport, lisible, pour l'operateur comme pour un test.
     */
    public function rapport(): string
    {
        return implode("\n", array_map(
            static fn (array $b): string => sprintf('[%s] %s — %s', $b['famille'], $b['raison'], $b['detail']),
            $this->blockers
        ));
    }
}
