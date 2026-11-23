<?php

namespace App\Support\Pwa;

/**
 * TASK-1895 — le contenu de `/.well-known/assetlinks.json`.
 *
 * Une seule declaration : l'application Android `android.twa.package` peut
 * ouvrir toutes les URL de ce site (`delegate_permission/common.handle_all_urls`),
 * a condition d'etre signee par l'un des certificats listes.
 *
 * ## Ce qui rend `[]`
 *
 * Une liste vide est un JSON VALIDE qui ne delegue rien : c'est l'etat sur
 * tant qu'aucune empreinte n'est configuree. On rend aussi `[]` si le nom de
 * package est mal forme. Une empreinte mal formee est ECARTEE plutot que
 * servie : publier une valeur fausse ne casse rien chez Google, mais masque
 * l'erreur de configuration — on prefere qu'elle se voie (pas de plein ecran).
 */
final class AndroidAssetLinks
{
    public const RELATION = 'delegate_permission/common.handle_all_urls';

    /** 32 octets en hexadecimal majuscule, separes par `:`. */
    private const FINGERPRINT_PATTERN = '/^(?:[0-9A-F]{2}:){31}[0-9A-F]{2}$/';

    /** Nom de package Android : au moins deux segments, chacun commencant par une lettre. */
    private const PACKAGE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/';

    /**
     * @return list<array{relation: list<string>, target: array{namespace: string, package_name: string, sha256_cert_fingerprints: list<string>}}>
     */
    public static function statements(?string $package, ?string $fingerprints): array
    {
        $package = trim((string) $package);

        if (preg_match(self::PACKAGE_PATTERN, $package) !== 1) {
            return [];
        }

        $valid = self::fingerprints($fingerprints);

        if ($valid === []) {
            return [];
        }

        return [[
            'relation' => [self::RELATION],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => $valid,
            ],
        ]];
    }

    /** La declaration effective, lue dans la configuration. */
    public static function current(): array
    {
        return self::statements(
            config('android.twa.package'),
            config('android.twa.sha256_cert_fingerprints'),
        );
    }

    /**
     * Normalise (espaces retires, majuscules), ecarte les valeurs mal formees
     * et les doublons, en conservant l'ordre de configuration.
     *
     * @return list<string>
     */
    public static function fingerprints(?string $raw): array
    {
        $valid = [];

        foreach (explode(',', (string) $raw) as $candidate) {
            $candidate = strtoupper((string) preg_replace('/\s+/', '', $candidate));

            if (preg_match(self::FINGERPRINT_PATTERN, $candidate) === 1 && ! in_array($candidate, $valid, true)) {
                $valid[] = $candidate;
            }
        }

        return $valid;
    }
}
