<?php

namespace App\Support\ScenarioManager;

/**
 * TASK-1648 — la lecture d'un scenario, AVANT qu'une Organization n'existe.
 *
 * ## Pourquoi cette classe n'emprunte pas `ScenarioManifest`
 *
 * `ScenarioManifest::fromApprovedJson()` exige un digest APPROUVE et refuse
 * un document invalide : c'est la porte du Load, et elle doit le rester.
 * Or le Preview doit montrer un BROUILLON, qui peut parfaitement etre
 * invalide — c'est meme son interet principal, voir ce qu'on a ecrit avant de
 * le corriger. Emprunter cette porte obligerait a l'elargir.
 *
 * Cette classe lit donc le document, et rien d'autre. Elle ne valide pas, ne
 * calcule aucun digest, n'autorise aucun chargement, et ne peut pas servir a
 * en obtenir un.
 *
 * ## Elle ne relance jamais le Validator
 *
 * Le CDC 11.1 l'interdit pour la bibliotheque : les compteurs et le verdict
 * viennent de `validation_summary`, calcule au save. Cette classe sert les
 * ONGLETS d'une seule version, qui ont besoin des objets eux-memes : elle
 * parse UNE fois, pour UNE version. Ce n'est pas ce que le CDC interdit.
 *
 * ## Un document illisible reste consultable
 *
 * {@see isReadable()} rend `false` dans DEUX cas, et pas seulement celui que
 * le nom suggere : quand le texte ne parse pas, et quand il parse en autre
 * chose qu'un objet — `[]`, `"une chaine"`, `123`, `null` sont du JSON
 * parfaitement valide, mais aucun n'est un manifeste. Le libelle affiche dit
 * donc « ne peut pas etre lu COMME UN MANIFESTE », pas « JSON invalide » : la
 * seconde phrase serait fausse la moitie du temps.
 *
 * Dans les deux cas toutes les familles sont vides et l'ecran montre ce que
 * `validation_summary` sait des erreurs, au lieu de casser. Un brouillon qu'on
 * ne peut plus ouvrir serait un brouillon perdu.
 */
final class ScenarioPreview
{
    private readonly ?\stdClass $document;

    private function __construct(?\stdClass $document)
    {
        $this->document = $document;
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self(null);
        }

        return new self($decoded instanceof \stdClass ? $decoded : null);
    }

    public function isReadable(): bool
    {
        return $this->document !== null;
    }

    /**
     * Une valeur scalaire de l'en-tete, ou `null`.
     *
     * Jamais un tableau ni un objet : l'en-tete de Preview affiche des
     * libelles, et rendre une structure ici obligerait chaque appelant a se
     * demander ce qu'il a recu.
     */
    public function header(string $key): ?string
    {
        $value = $this->document?->{$key} ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Le slug PROPOSE par le document.
     *
     * « Propose » est le mot du schema : le loader choisit le slug reel de la
     * sandbox, et il peut differer. L'ecran doit donc le presenter comme une
     * proposition, jamais comme une adresse.
     */
    public function proposedSlug(): ?string
    {
        $organization = $this->document?->organization ?? null;

        if (! $organization instanceof \stdClass) {
            return null;
        }

        $slug = $organization->proposed_slug ?? null;

        return is_string($slug) ? $slug : null;
    }

    /**
     * Une famille du manifeste, designee comme le fait `ManifestSchema` :
     * `users`, ou `training.modules`.
     *
     * Une seule porte pour les vingt familles, parce qu'une deuxieme methode
     * pour les seules familles `training` obligerait chaque appelant a savoir
     * laquelle choisir — et un appelant qui se trompe obtient une liste vide
     * plutot qu'une erreur.
     *
     * @return list<\stdClass>
     */
    public function family(string $path): array
    {
        $noeud = $this->document;

        foreach (explode('.', $path) as $segment) {
            if (! $noeud instanceof \stdClass) {
                return [];
            }

            $noeud = $noeud->{$segment} ?? null;
        }

        return $this->listOf($noeud);
    }

    /**
     * @return list<\stdClass>
     */
    private function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => $item instanceof \stdClass));
    }
}
