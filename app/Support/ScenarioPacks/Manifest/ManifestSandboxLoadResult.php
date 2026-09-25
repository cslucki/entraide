<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Models\Organization;
use App\Support\ScenarioPacks\ScenarioPackLoadResult;

/**
 * TASK-1642 — ce qu'un chargement de manifeste a reellement produit.
 *
 * Porte la sandbox REELLE (dont le slug peut differer de celui propose) a cote
 * du digest charge, pour qu'un appelant n'ait jamais a deviner l'un depuis
 * l'autre.
 */
final class ManifestSandboxLoadResult
{
    public function __construct(
        public readonly ScenarioManifest $manifest,
        public readonly Organization $organization,
        public readonly ScenarioPackLoadResult $packLoad,
    ) {}

    /**
     * Le slug finalement retenu par BouclePro. Different du slug propose des
     * qu'il y avait collision : c'est le contrat de la spec 10.2, pas un
     * incident.
     */
    public function sandboxSlug(): string
    {
        return (string) $this->organization->slug;
    }

    public function proposedSlugWasTaken(): bool
    {
        return $this->sandboxSlug() !== $this->manifest->proposedSlug();
    }

    public function digest(): string
    {
        return $this->manifest->digest();
    }

    /**
     * @return array<string, int>
     */
    public function entityCountsByType(): array
    {
        return $this->packLoad->entityCountsByType;
    }
}
