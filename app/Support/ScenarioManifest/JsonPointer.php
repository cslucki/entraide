<?php

namespace App\Support\ScenarioManifest;

/**
 * Construction des `path` d'erreur en JSON Pointer RFC 6901 (spec 9.4).
 *
 * La racine est `/`, et non la chaine vide de la RFC : la spec l'impose
 * explicitement ("`/` vise la racine") parce qu'un path vide serait invisible
 * dans un rapport affiche.
 */
final class JsonPointer
{
    public const ROOT = '/';

    /**
     * Ajoute un segment (nom de propriete ou index de tableau) a un pointer.
     */
    public static function child(string $parent, string|int $segment): string
    {
        $encoded = is_int($segment)
            ? (string) $segment
            : str_replace(['~', '/'], ['~0', '~1'], $segment);

        return $parent === self::ROOT ? '/'.$encoded : $parent.'/'.$encoded;
    }
}
