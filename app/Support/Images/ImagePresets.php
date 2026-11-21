<?php

namespace App\Support\Images;

use InvalidArgumentException;

/**
 * TASK-1873 — lecture typee de `config/image_variants.php`.
 *
 * Une surface ou un preset inconnu leve : une faute de frappe dans un appel
 * ne doit pas produire silencieusement « aucune variante ».
 */
final class ImagePresets
{
    /**
     * @return array{mode: string, width: int, height: int|null, version: int}
     */
    public function preset(string $name): array
    {
        $preset = config("image_variants.presets.{$name}");

        if (! is_array($preset)) {
            throw new InvalidArgumentException("Preset d'image inconnu : {$name}");
        }

        return [
            'mode' => (string) $preset['mode'],
            'width' => (int) $preset['width'],
            'height' => isset($preset['height']) ? (int) $preset['height'] : null,
            'version' => (int) $preset['version'],
        ];
    }

    /**
     * @return array<string, array{mode: string, width: int, height: int|null, version: int}>
     */
    public function forSurface(string $surface): array
    {
        $definition = config("image_variants.surfaces.{$surface}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Surface d'image inconnue : {$surface}");
        }

        $presets = [];
        foreach ($definition['presets'] as $name) {
            $presets[$name] = $this->preset($name);
        }

        return $presets;
    }

    /** @return list<string> */
    public function surfaces(): array
    {
        return array_keys((array) config('image_variants.surfaces'));
    }

    /** @return list<string> */
    public function backfillSurfaces(): array
    {
        return array_values(array_filter(
            $this->surfaces(),
            fn (string $surface) => (bool) config("image_variants.surfaces.{$surface}.backfill"),
        ));
    }
}
