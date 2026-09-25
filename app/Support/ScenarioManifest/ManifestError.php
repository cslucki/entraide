<?php

namespace App\Support\ScenarioManifest;

/**
 * Une erreur de validation, exactement de la forme imposee par la section 9.4
 * de la spec : `{code, path, message}`.
 *
 * `path` est un JSON Pointer RFC 6901 ; la racine vaut `/`. `message` est
 * humainement lisible et ne contient jamais de stack trace, de nom de classe,
 * de SQL, de chemin serveur ni de secret : ce contrat est verifie par un test
 * d'architecture, parce qu'un message d'erreur de Validator est destine a etre
 * affiche a un SuperAdmin et copie-colle a un producteur externe.
 */
final class ManifestError
{
    public function __construct(
        public readonly ManifestErrorCode $code,
        public readonly string $path,
        public readonly string $message,
    ) {}

    /**
     * @return array{code: string, path: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'path' => $this->path,
            'message' => $this->message,
        ];
    }
}
