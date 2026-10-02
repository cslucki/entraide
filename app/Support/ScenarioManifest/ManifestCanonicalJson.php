<?php

namespace App\Support\ScenarioManifest;

/**
 * Serialisation canonique RFC 8785 (JCS) et digest SHA-256 du manifeste
 * (spec 5.1).
 *
 * Le digest est ce qu'un SuperAdmin approuve HUMAINEMENT avant Load : il doit
 * donc etre une fonction du CONTENU, pas de sa mise en forme. Deux documents
 * qui different seulement par l'indentation ou par l'ordre des proprietes d'un
 * objet rendent le meme digest ; deux documents qui different par l'ordre d'un
 * TABLEAU rendent deux digests, parce que la spec fait de l'ordre des tableaux
 * une donnee significative (5.1), meme quand un champ `order` porte par
 * ailleurs l'ordre metier.
 *
 * `json_encode()` n'est PAS utilise : il n'ordonne pas les cles, echappe le
 * non-ASCII par defaut et n'offre aucune garantie de stabilite entre versions
 * de PHP. Un digest approuve doit survivre a une montee de version.
 */
final class ManifestCanonicalJson
{
    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function encode(mixed $value): string
    {
        if ($value instanceof \stdClass) {
            return self::encodeObject(get_object_vars($value));
        }

        if (is_array($value)) {
            return '['.implode(',', array_map(self::encode(...), $value)).']';
        }

        if (is_string($value)) {
            return self::encodeString($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return self::encodeFloat((float) $value);
    }

    /**
     * @param  array<string, mixed>  $members
     */
    private static function encodeObject(array $members): string
    {
        $names = array_keys($members);

        // RFC 8785 : tri sur les UNITES DE CODE UTF-16, pas sur les octets
        // UTF-8. Les deux ordres divergent des qu'une cle sort du BMP ; comparer
        // la forme UTF-16BE donne exactement l'ordre demande.
        usort($names, static fn (string $a, string $b): int => strcmp(
            self::utf16Sortable($a),
            self::utf16Sortable($b),
        ));

        $parts = [];

        foreach ($names as $name) {
            $parts[] = self::encodeString($name).':'.self::encode($members[$name]);
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function utf16Sortable(string $value): string
    {
        $converted = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');

        return is_string($converted) ? $converted : $value;
    }

    /**
     * Echappement minimal RFC 8785 : seuls `"`, `\` et les caracteres de
     * controle sont echappes. Le reste, y compris le non-ASCII, sort en UTF-8
     * brut.
     */
    private static function encodeString(string $value): string
    {
        $out = '"';

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $out .= match ($char) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\x08" => '\\b',
                "\x09" => '\\t',
                "\x0A" => '\\n',
                "\x0C" => '\\f',
                "\x0D" => '\\r',
                default => (strlen($char) === 1 && ord($char) < 0x20)
                    ? sprintf('\\u%04x', ord($char))
                    : $char,
            };
        }

        return $out.'"';
    }

    /**
     * Les nombres du langage V1 sont des ENTIERS (spec 6.2) : un flottant est
     * deja INVALID_TYPE. Cette branche n'existe donc que pour que le digest
     * d'un document invalide reste calculable et stable, condition du critere
     * de determinisme.
     */
    private static function encodeFloat(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return 'null';
        }

        if ($value === floor($value) && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }

        return str_replace('E', 'e', (string) $value);
    }
}
