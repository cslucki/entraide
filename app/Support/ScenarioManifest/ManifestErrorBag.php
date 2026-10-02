<?php

namespace App\Support\ScenarioManifest;

/**
 * Collecte les erreurs de validation et les rend dans l'ORDRE NORMATIF de la
 * section 9.4 : tri par `path`, puis `code`, puis `message`, et une meme cause
 * sur un meme path emise une seule fois.
 *
 * Le tri est fait a la LECTURE, jamais a l'ecriture : l'ordre dans lequel les
 * phases decouvrent les defauts n'a donc aucune influence sur le rapport. Deux
 * validations du meme document rendent la meme liste, dans le meme ordre,
 * meme si l'implementation des phases change d'ordre interne (critere
 * d'acceptation 2 de la spec).
 *
 * La deduplication porte sur le couple (code, path) : deux causes DIFFERENTES
 * sur le meme path restent deux erreurs, ce qui evite de masquer un defaut
 * derriere un autre.
 */
final class ManifestErrorBag
{
    /** @var array<string, ManifestError> indexe par "code\0path" */
    private array $errors = [];

    public function add(ManifestErrorCode $code, string $path, string $message): void
    {
        $dedupKey = $code->value."\0".$path;

        if (! array_key_exists($dedupKey, $this->errors)) {
            $this->errors[$dedupKey] = new ManifestError($code, $path, $message);
        }
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    public function count(): int
    {
        return count($this->errors);
    }

    /**
     * @return list<ManifestError>
     */
    public function all(): array
    {
        $sorted = array_values($this->errors);

        usort($sorted, static function (ManifestError $a, ManifestError $b): int {
            return [$a->path, $a->code->value, $a->message]
                <=> [$b->path, $b->code->value, $b->message];
        });

        return $sorted;
    }
}
