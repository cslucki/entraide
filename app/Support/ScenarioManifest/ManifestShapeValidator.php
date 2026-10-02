<?php

namespace App\Support\ScenarioManifest;

/**
 * Phases 2 a 4 de la spec 9.1 : schema/version, champs autorises, types,
 * formats, enums, limites locales et unicite des stable keys.
 *
 * C'est la seule traversee du document guidee par `ManifestSchema`. Elle rend
 * un `ManifestShapeScan` que les phases relationnelles consomment : references
 * a resoudre, contenus a sanitizer, avatars a verifier, et champs dont la forme
 * est deja fautive.
 *
 * Un champ absent du catalogue n'est jamais ignore : il est classe par
 * `ManifestForbiddenNames` puis refuse. C'est l'allowlist exigee par la
 * spec 4.2 — le loader futur ne fera jamais de mass assignment du JSON, mais
 * encore faut-il qu'aucune propriete inconnue n'ait survecu jusqu'a lui.
 */
final class ManifestShapeValidator
{
    private const STABLE_KEY_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    private const STABLE_KEY_MAX = 64;

    private const SEMVER_PATTERN = '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/';

    private const EMAIL_MAX = 254;

    private const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    public function validate(\stdClass $root, ManifestErrorBag $errors): ManifestShapeScan
    {
        $scan = new ManifestShapeScan;

        $this->validateObject($root, ManifestSchema::envelope(), JsonPointer::ROOT, $errors, $scan, isRoot: true);

        return $scan;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function validateObject(
        \stdClass $node,
        array $fields,
        string $path,
        ManifestErrorBag $errors,
        ManifestShapeScan $scan,
        bool $isRoot = false,
    ): void {
        foreach ($fields as $name => $spec) {
            $childPath = JsonPointer::child($path, $name);

            if (! property_exists($node, $name)) {
                $errors->add(
                    ManifestErrorCode::MISSING_FIELD,
                    $childPath,
                    sprintf("Required property '%s' is missing.", $name),
                );
                $scan->markMalformed($childPath);

                continue;
            }

            $this->validateValue($node->{$name}, $spec, $childPath, $errors, $scan);
        }

        $this->collectSanitizableContent($node, $fields, $path, $scan);

        foreach (get_object_vars($node) as $name => $value) {
            if (array_key_exists($name, $fields)) {
                continue;
            }

            $childPath = JsonPointer::child($path, $name);
            $code = ManifestForbiddenNames::classify($name, $isRoot) ?? ManifestErrorCode::UNKNOWN_FIELD;

            $errors->add($code, $childPath, ManifestForbiddenNames::messageFor($code, $name));
            $scan->markMalformed($childPath);
        }
    }

    /**
     * Recolte les contenus a sanitizer AVEC leur format effectif.
     *
     * Le format vit dans une propriete VOISINE (`format`, ou `media_type` pour
     * un fichier) : il ne peut donc etre resolu qu'ici, au niveau de l'objet
     * qui porte les deux. Un contenu dont la forme est deja fautive n'est pas
     * recolte : le sanitizer n'a rien a dire d'une valeur qui n'est pas encore
     * une chaine valide.
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function collectSanitizableContent(\stdClass $node, array $fields, string $path, ManifestShapeScan $scan): void
    {
        foreach ($fields as $name => $spec) {
            if (! array_key_exists('sanitize_format_field', $spec)) {
                continue;
            }

            $childPath = JsonPointer::child($path, $name);
            $content = $node->{$name} ?? null;

            if (! is_string($content) || $scan->isMalformed($childPath)) {
                continue;
            }

            // `null` signifie "Markdown impose" : le champ n'a aucune
            // propriete voisine qui porte un format.
            $formatField = $spec['sanitize_format_field'];
            $declared = $formatField === null ? 'markdown' : ($node->{$formatField} ?? null);

            $format = match ($declared) {
                'text/markdown', 'markdown' => 'markdown',
                'text/html', 'html' => 'html',
                'plain' => 'plain',
                default => null,
            };

            if ($format !== null) {
                $scan->contents[] = ['path' => $childPath, 'content' => $content, 'format' => $format];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateValue(
        mixed $value,
        array $spec,
        string $path,
        ManifestErrorBag $errors,
        ManifestShapeScan $scan,
    ): void {
        if ($value === null) {
            if (($spec['nullable'] ?? false) !== true) {
                $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must not be null.');
            }

            return;
        }

        match ($spec['type']) {
            'const' => $this->validateConst($value, $spec, $path, $errors, $scan),
            'string' => $this->validateString($value, $spec, $path, $errors, $scan),
            'enum' => $this->validateEnum($value, $spec, $path, $errors, $scan),
            'int' => $this->validateInt($value, $spec, $path, $errors, $scan),
            'bool' => $this->validateBool($value, $path, $errors, $scan),
            'stable_key' => $this->validateStableKey($value, 1, self::STABLE_KEY_MAX, $path, $errors, $scan),
            'slug' => $this->validateStableKey($value, (int) $spec['min'], (int) $spec['max'], $path, $errors, $scan),
            'semver' => $this->validateSemver($value, $path, $errors, $scan),
            'email' => $this->validateEmail($value, $path, $errors, $scan),
            'avatar' => $this->validateAvatar($value, $path, $errors, $scan),
            'color' => $this->validatePattern($value, self::COLOR_PATTERN, 'a six-digit hexadecimal colour such as #4F46E5', $path, $errors, $scan),
            'url' => $this->validateUrl($value, $spec, $path, $errors, $scan),
            'timezone' => $this->validateTimezone($value, $spec, $path, $errors, $scan),
            'filename' => $this->validateFilename($value, $spec, $path, $errors, $scan),
            'ref' => $this->validateReference($value, $spec, $path, $errors, $scan),
            'object' => $this->validateNestedObject($value, $spec, $path, $errors, $scan),
            'array' => $this->validateArray($value, $spec, $path, $errors, $scan),
            'sequence_content' => $this->validateSequenceContent($value, $path, $errors, $scan),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateConst(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if ($value === $spec['value']) {
            return;
        }

        $code = $spec['code'];
        $this->fail($errors, $scan, $code, $path, sprintf(
            "Expected the exact value '%s'.",
            (string) $spec['value'],
        ));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateString(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        $multiline = ($spec['multiline'] ?? false) === true;

        // Texte court : aucun caractere de controle (spec 6.2). Texte long : le
        // saut de ligne et la tabulation restent legitimes, le retour chariot
        // non, pour qu'une meme ligne ne puisse pas s'ecrire de deux facons
        // et produire deux digests.
        $forbidden = $multiline
            ? '/[\x00-\x08\x0B-\x1F\x7F]/u'
            : '/[\x00-\x1F\x7F]/u';

        if (preg_match($forbidden, $value) === 1) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property contains a control character.');

            return;
        }

        if (! \Normalizer::isNormalized($value, \Normalizer::FORM_C)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property must be Unicode NFC normalised.');

            return;
        }

        $length = mb_strlen($value, 'UTF-8');

        if (isset($spec['max']) && $length > $spec['max']) {
            $this->fail($errors, $scan, ManifestErrorCode::VALUE_TOO_LONG, $path, sprintf(
                'Property is %d characters long; the maximum is %d.',
                $length,
                (int) $spec['max'],
            ));

            return;
        }

        if (isset($spec['min']) && $length < $spec['min']) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, sprintf(
                'Property must be at least %d characters long.',
                (int) $spec['min'],
            ));

            return;
        }

    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateEnum(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        // Correspondance SENSIBLE A LA CASSE (spec 6.2) : `Admin` n'est pas
        // `admin`, et le Validator ne devine pas l'intention.
        if (! in_array($value, $spec['values'], true)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_ENUM, $path, sprintf(
                'Property must be one of: %s.',
                implode(', ', $spec['values']),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateInt(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        // Une chaine numerique est invalide, un booleen aussi (spec 6.2).
        if (! is_int($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a JSON integer.');

            return;
        }

        if ((isset($spec['min']) && $value < $spec['min']) || (isset($spec['max']) && $value > $spec['max'])) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, sprintf(
                'Property must be between %d and %d.',
                (int) $spec['min'],
                (int) $spec['max'],
            ));
        }
    }

    private function validateBool(mixed $value, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_bool($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be true or false.');
        }
    }

    private function validateStableKey(mixed $value, int $min, int $max, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        $length = mb_strlen($value, 'UTF-8');

        if ($length > $max) {
            $this->fail($errors, $scan, ManifestErrorCode::VALUE_TOO_LONG, $path, sprintf(
                'Property is %d characters long; the maximum is %d.',
                $length,
                $max,
            ));

            return;
        }

        if ($length < $min || preg_match(self::STABLE_KEY_PATTERN, $value) !== 1) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, sprintf(
                'Property must be a stable key of %d to %d lowercase characters, such as trainer-1.',
                $min,
                $max,
            ));
        }
    }

    private function validateSemver(mixed $value, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        // Ni preversion ni metadonnee de build en V1 (spec 6.1).
        $this->validatePattern($value, self::SEMVER_PATTERN, 'a MAJOR.MINOR.PATCH version such as 1.0.0', $path, $errors, $scan);
    }

    private function validateEmail(mixed $value, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        $length = mb_strlen($value, 'UTF-8');

        if ($length > self::EMAIL_MAX) {
            $this->fail($errors, $scan, ManifestErrorCode::VALUE_TOO_LONG, $path, sprintf(
                'Property is %d characters long; the maximum is %d.',
                $length,
                self::EMAIL_MAX,
            ));

            return;
        }

        // Le suffixe `.test` est une garde MECANIQUE : il empeche qu'une
        // adresse reelle soit provisionnee par erreur. Il n'autorise pas pour
        // autant a recopier l'identite d'une personne reelle (spec 7.2), ce
        // qu'aucun controle automatique ne peut etablir.
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || ! str_ends_with(strtolower($value), '.test')) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property must be a fictional email address in a .test domain.');
        }
    }

    private function validateAvatar(mixed $value, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        if (preg_match(ManifestAvatarBank::KEY_PATTERN, $value) !== 1) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property must be an avatar key such as female-03.');

            return;
        }

        $scan->avatars[] = ['path' => $path, 'key' => $value];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateUrl(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        if (mb_strlen($value, 'UTF-8') > $spec['max']) {
            $this->fail($errors, $scan, ManifestErrorCode::VALUE_TOO_LONG, $path, sprintf(
                'Property exceeds the maximum of %d characters.',
                (int) $spec['max'],
            ));

            return;
        }

        // L'URL est AFFICHEE, jamais visitee (spec 7.7). La seule garantie
        // demandee ici est qu'elle soit une URL HTTPS bien formee.
        if (! str_starts_with($value, 'https://') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property must be an https:// URL.');
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateTimezone(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        if (mb_strlen($value, 'UTF-8') > $spec['max']) {
            $this->fail($errors, $scan, ManifestErrorCode::VALUE_TOO_LONG, $path, sprintf(
                'Property exceeds the maximum of %d characters.',
                (int) $spec['max'],
            ));

            return;
        }

        // Catalogue LOCAL de PHP : aucun appel reseau.
        if (! in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property must be a valid IANA time zone identifier such as Europe/Paris.');
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateFilename(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        $length = mb_strlen($value, 'UTF-8');

        if ($length > $spec['max']) {
            $this->fail($errors, $scan, ManifestErrorCode::VALUE_TOO_LONG, $path, sprintf(
                'Property is %d characters long; the maximum is %d.',
                $length,
                (int) $spec['max'],
            ));

            return;
        }

        // Un basename, et rien d'autre : un separateur ou un `..` serait une
        // tentative de designer un emplacement de stockage (spec 7.4).
        $isBasename = $length >= $spec['min']
            && ! str_contains($value, '/')
            && ! str_contains($value, '\\')
            && ! str_contains($value, '..')
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;

        if (! $isBasename || ! (str_ends_with($value, '.md') || str_ends_with($value, '.html'))) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property must be a plain file name ending in .md or .html, without any path separator.');
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateReference(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        if (preg_match(self::STABLE_KEY_PATTERN, $value) !== 1 || mb_strlen($value, 'UTF-8') > self::STABLE_KEY_MAX) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, 'Property must be a stable key.');

            return;
        }

        $scan->references[] = ['path' => $path, 'collection' => $spec['collection'], 'value' => $value];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateNestedObject(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! $value instanceof \stdClass) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a JSON object.');

            return;
        }

        $this->validateObject($value, $spec['fields'], $path, $errors, $scan);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function validateArray(mixed $value, array $spec, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a JSON array.');

            return;
        }

        $count = count($value);

        if (isset($spec['max']) && $count > $spec['max']) {
            $errors->add(ManifestErrorCode::LIMIT_EXCEEDED, $path, sprintf(
                'Property holds %d entries; the limit is %d.',
                $count,
                (int) $spec['max'],
            ));
        }

        if (isset($spec['min']) && $count < $spec['min']) {
            $errors->add(ManifestErrorCode::INVALID_FORMAT, $path, sprintf(
                'Property must hold at least %d entries.',
                (int) $spec['min'],
            ));
        }

        $keyField = $spec['key_field'] ?? null;
        $seenKeys = [];

        foreach ($value as $index => $item) {
            $itemPath = JsonPointer::child($path, $index);
            $this->validateValue($item, $spec['of'], $itemPath, $errors, $scan);

            if ($keyField === null || ! $item instanceof \stdClass || ! is_string($item->{$keyField} ?? null)) {
                continue;
            }

            $key = $item->{$keyField};
            $keyPath = JsonPointer::child($itemPath, $keyField);

            if (array_key_exists($key, $seenKeys)) {
                $errors->add(ManifestErrorCode::DUPLICATE_KEY, $keyPath, sprintf(
                    "Stable key '%s' is already used at %s.",
                    $key,
                    $seenKeys[$key],
                ));

                continue;
            }

            $seenKeys[$key] = $keyPath;
        }
    }

    /**
     * Les trois variantes de `sequences[].content` (spec 12.2). Exactement une
     * variante, et son unique champ : la forme depend de `type`, ce qu'aucun
     * schema statique ne peut exprimer.
     */
    private function validateSequenceContent(mixed $value, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! $value instanceof \stdClass) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a JSON object.');

            return;
        }

        $variants = ManifestSchema::sequenceContentVariants();
        $typePath = JsonPointer::child($path, 'type');
        $type = $value->type ?? null;

        if (! property_exists($value, 'type')) {
            $this->fail($errors, $scan, ManifestErrorCode::MISSING_FIELD, $typePath, "Required property 'type' is missing.");

            return;
        }

        if (! is_string($type) || ! array_key_exists($type, $variants)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_ENUM, $typePath, sprintf(
                'Property must be one of: %s.',
                implode(', ', array_keys($variants)),
            ));

            return;
        }

        $fields = ['type' => ['type' => 'enum', 'values' => array_keys($variants)]] + $variants[$type];

        $this->validateObject($value, $fields, $path, $errors, $scan);
    }

    private function validatePattern(mixed $value, string $pattern, string $expectation, string $path, ManifestErrorBag $errors, ManifestShapeScan $scan): void
    {
        if (! is_string($value)) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_TYPE, $path, 'Property must be a string.');

            return;
        }

        if (preg_match($pattern, $value) !== 1) {
            $this->fail($errors, $scan, ManifestErrorCode::INVALID_FORMAT, $path, sprintf('Property must be %s.', $expectation));
        }
    }

    private function fail(ManifestErrorBag $errors, ManifestShapeScan $scan, ManifestErrorCode $code, string $path, string $message): void
    {
        $errors->add($code, $path, $message);
        $scan->markMalformed($path);
    }
}
