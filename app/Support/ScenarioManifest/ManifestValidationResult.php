<?php

namespace App\Support\ScenarioManifest;

/**
 * Le verdict rendu par le Validator (spec 9.4).
 *
 * "Une validation reussie retourne `VALID` avec le digest, les compteurs et
 * zero erreur. Toute erreur retourne `INVALID`; il n'existe pas
 * d'avertissement qui soit secretement ignore au Load." Il n'y a donc pas de
 * troisieme etat, pas de severite, pas de liste de warnings : l'etat `VALID`
 * du cycle de vie ne peut naitre que d'un rapport vide.
 *
 * Le digest est rendu des que le document a pu etre PARSE, y compris quand il
 * est invalide : c'est ce qui permet de prouver que deux validations du meme
 * texte rendent le meme digest, dans un sens comme dans l'autre. Il n'autorise
 * jamais un Load a lui seul, l'etat `VALID` et la confirmation humaine restant
 * des preconditions distinctes (spec 5.2).
 */
final class ManifestValidationResult
{
    public const VALID = 'VALID';

    public const INVALID = 'INVALID';

    /**
     * @param  list<ManifestError>  $errors
     * @param  array<string, int>  $counters
     */
    private function __construct(
        private readonly array $errors,
        private readonly ?string $digest,
        private readonly array $counters,
    ) {}

    /**
     * @param  list<ManifestError>  $errors
     * @param  array<string, int>  $counters
     */
    public static function make(array $errors, ?string $digest, array $counters): self
    {
        return new self($errors, $digest, $counters);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function verdict(): string
    {
        return $this->isValid() ? self::VALID : self::INVALID;
    }

    public function digest(): ?string
    {
        return $this->digest;
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * @return list<ManifestError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return array_map(static fn (ManifestError $error): string => $error->code->value, $this->errors);
    }

    /**
     * @return array{verdict: string, digest: string|null, counters: array<string, int>, error_count: int, errors: list<array{code: string, path: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'digest' => $this->digest,
            'counters' => $this->counters,
            'error_count' => count($this->errors),
            'errors' => array_map(static fn (ManifestError $error): array => $error->toArray(), $this->errors),
        ];
    }
}
