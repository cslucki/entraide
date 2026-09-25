<?php

namespace App\Support\ScenarioManifest;

/**
 * Phase 1 de la validation (spec 9.1) : enveloppe et encodage.
 *
 * Le contrat d'import (spec 15) est STRICT : pas de commentaire, pas de
 * trailing comma, pas de `NaN`/`Infinity`, pas de cle dupliquee, pas de BOM, et
 * un bloc Markdown ```json n'est PAS un document valide. C'est la seule phase
 * qui peut echouer sans qu'aucune autre ne s'execute : sans arbre, il n'y a ni
 * champ a valider ni digest a calculer.
 *
 * La detection des MEMBRES dupliques est faite ici, a la main, parce que
 * `json_decode()` garde silencieusement la derniere occurrence : accepter ce
 * silence laisserait deux documents textuellement differents produire le meme
 * arbre et donc le meme digest approuve par un humain.
 */
final class ManifestJsonParser
{
    /** 2 MiB, spec 9.2. */
    public const MAX_BYTES = 2097152;

    /** Profondeur JSON maximale, spec 9.2. La racine compte pour 1. */
    public const MAX_DEPTH = 20;

    /**
     * Marge de decodage : au-dela, `json_decode()` rend une erreur de
     * profondeur que l'on traduit en MAX_DEPTH_EXCEEDED. La marge existe pour
     * pouvoir MESURER la profondeur reelle et la citer dans le message.
     */
    private const DECODE_DEPTH = 512;

    private string $source = '';

    private int $cursor = 0;

    /**
     * Rend l'arbre decode (objets = stdClass, tableaux = array), ou `null` si
     * le document n'est pas parsable. Les defauts sont pousses dans le bag.
     */
    public function parse(string $json, ManifestErrorBag $errors): ?object
    {
        if (strlen($json) > self::MAX_BYTES) {
            $errors->add(
                ManifestErrorCode::PAYLOAD_TOO_LARGE,
                JsonPointer::ROOT,
                sprintf('Manifest is %d bytes; the limit is %d bytes.', strlen($json), self::MAX_BYTES),
            );

            return null;
        }

        if (! mb_check_encoding($json, 'UTF-8')) {
            $errors->add(
                ManifestErrorCode::INVALID_UTF8,
                JsonPointer::ROOT,
                'Manifest is not valid UTF-8.',
            );

            return null;
        }

        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $errors->add(
                ManifestErrorCode::INVALID_JSON,
                JsonPointer::ROOT,
                'Manifest starts with a byte order mark; send raw UTF-8 JSON without a BOM.',
            );

            return null;
        }

        try {
            $decoded = json_decode($json, false, self::DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                $errors->add(
                    ManifestErrorCode::MAX_DEPTH_EXCEEDED,
                    JsonPointer::ROOT,
                    sprintf('Manifest nesting exceeds the maximum depth of %d.', self::MAX_DEPTH),
                );

                return null;
            }

            // Message de la librairie uniquement : il decrit la syntaxe JSON,
            // jamais un chemin serveur ni une classe.
            $errors->add(
                ManifestErrorCode::INVALID_JSON,
                JsonPointer::ROOT,
                sprintf('Manifest is not valid JSON (%s).', $exception->getMessage()),
            );

            return null;
        }

        if (! $decoded instanceof \stdClass) {
            $errors->add(
                ManifestErrorCode::INVALID_TYPE,
                JsonPointer::ROOT,
                'Manifest must be a JSON object.',
            );

            return null;
        }

        $depth = $this->depthOf($decoded);

        if ($depth > self::MAX_DEPTH) {
            $errors->add(
                ManifestErrorCode::MAX_DEPTH_EXCEEDED,
                JsonPointer::ROOT,
                sprintf('Manifest nesting is %d levels deep; the maximum is %d.', $depth, self::MAX_DEPTH),
            );

            return null;
        }

        $this->scanDuplicateMembers($json, $errors);

        if (! $errors->isEmpty()) {
            return null;
        }

        return $decoded;
    }

    /**
     * Profondeur reelle de l'arbre, racine comprise.
     */
    private function depthOf(mixed $node): int
    {
        if ($node instanceof \stdClass) {
            $node = get_object_vars($node);
        }

        if (! is_array($node)) {
            return 0;
        }

        $deepest = 0;

        foreach ($node as $child) {
            $deepest = max($deepest, $this->depthOf($child));
        }

        return $deepest + 1;
    }

    /**
     * Relit le TEXTE source pour reperer les membres d'objet dupliques.
     *
     * Le document a deja passe `json_decode()` : le scanner peut donc supposer
     * une syntaxe bien formee et se contenter de suivre la structure pour
     * construire le JSON Pointer de la seconde occurrence.
     */
    private function scanDuplicateMembers(string $json, ManifestErrorBag $errors): void
    {
        $this->source = $json;
        $this->cursor = 0;

        $this->scanValue(JsonPointer::ROOT, $errors);
    }

    private function scanValue(string $path, ManifestErrorBag $errors): void
    {
        $this->skipWhitespace();

        $char = $this->source[$this->cursor] ?? '';

        if ($char === '{') {
            $this->scanObject($path, $errors);

            return;
        }

        if ($char === '[') {
            $this->scanArray($path, $errors);

            return;
        }

        if ($char === '"') {
            $this->readString();

            return;
        }

        // Nombre, `true`, `false` ou `null` : avancer jusqu'au prochain
        // separateur structurel.
        while ($this->cursor < strlen($this->source)
            && ! str_contains(",}] \t\n\r", $this->source[$this->cursor])) {
            $this->cursor++;
        }
    }

    private function scanObject(string $path, ManifestErrorBag $errors): void
    {
        $this->cursor++; // '{'
        $this->skipWhitespace();

        if (($this->source[$this->cursor] ?? '') === '}') {
            $this->cursor++;

            return;
        }

        $seen = [];

        while (true) {
            $this->skipWhitespace();
            $name = $this->readString();
            $childPath = JsonPointer::child($path, $name);

            if (array_key_exists($name, $seen)) {
                $errors->add(
                    ManifestErrorCode::DUPLICATE_KEY,
                    $childPath,
                    sprintf("Object member '%s' is declared more than once.", $name),
                );
            }

            $seen[$name] = true;

            $this->skipWhitespace();
            $this->cursor++; // ':'
            $this->scanValue($childPath, $errors);
            $this->skipWhitespace();

            if (($this->source[$this->cursor] ?? '') === ',') {
                $this->cursor++;

                continue;
            }

            $this->cursor++; // '}'

            return;
        }
    }

    private function scanArray(string $path, ManifestErrorBag $errors): void
    {
        $this->cursor++; // '['
        $this->skipWhitespace();

        if (($this->source[$this->cursor] ?? '') === ']') {
            $this->cursor++;

            return;
        }

        $index = 0;

        while (true) {
            $this->scanValue(JsonPointer::child($path, $index), $errors);
            $index++;
            $this->skipWhitespace();

            if (($this->source[$this->cursor] ?? '') === ',') {
                $this->cursor++;

                continue;
            }

            $this->cursor++; // ']'

            return;
        }
    }

    /**
     * Lit un litteral chaine et rend sa valeur DECODEE : `"key"` et
     * `"key"` nomment le meme membre, et doivent donc se detecter comme un
     * doublon.
     */
    private function readString(): string
    {
        $start = $this->cursor;
        $this->cursor++; // '"' ouvrant

        while ($this->cursor < strlen($this->source)) {
            $char = $this->source[$this->cursor];

            if ($char === '\\') {
                $this->cursor += 2;

                continue;
            }

            $this->cursor++;

            if ($char === '"') {
                break;
            }
        }

        $raw = substr($this->source, $start, $this->cursor - $start);
        $decoded = json_decode($raw);

        return is_string($decoded) ? $decoded : $raw;
    }

    private function skipWhitespace(): void
    {
        while ($this->cursor < strlen($this->source)
            && str_contains(" \t\n\r", $this->source[$this->cursor])) {
            $this->cursor++;
        }
    }
}
