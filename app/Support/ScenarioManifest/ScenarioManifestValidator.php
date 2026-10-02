<?php

namespace App\Support\ScenarioManifest;

/**
 * Parser et Validator du BouclePro Scenario Manifest V1 (TASK-1641), conforme
 * a `TODO/SPECS/260920-11h10-CDC-scenario-manifest.md`.
 *
 * C'est le premier morceau EXECUTABLE du langage : il recoit un JSON et rend
 * un verdict, un digest, des compteurs et des erreurs deterministes. Il ne
 * charge rien. Par construction il ne peut rien charger : il n'a pas de
 * dependance, pas de connexion, pas de facade, et n'ecrit aucun fichier.
 * "Import != Load" et "Validate ne cree aucune donnee metier" (spec 5.2 et
 * 17.3) ne sont pas ici des precautions d'usage mais une propriete de la
 * classe, verifiee par ses tests.
 *
 * Les huit phases de la spec 9.1 sont executees dans l'ordre, et TOUTES celles
 * qui peuvent encore etablir un fait le font : un document a une reference
 * manquante rend aussi ses contenus dangereux et ses ruptures d'invariant,
 * pour qu'un producteur externe corrige en une passe au lieu de dix.
 *
 * Usage :
 *
 *     $result = (new ScenarioManifestValidator)->validate($json);
 *     $result->verdict();  // VALID | INVALID
 *     $result->digest();   // sha256 du JSON canonique RFC 8785
 *     $result->errors();   // triees par path, code, message
 */
final class ScenarioManifestValidator
{
    public function validate(string $json): ManifestValidationResult
    {
        $errors = new ManifestErrorBag;

        // Phase 1 : enveloppe et encodage. Sans arbre, il n'y a rien d'autre
        // a etablir, et aucun digest a calculer.
        $root = (new ManifestJsonParser)->parse($json, $errors);

        if ($root === null) {
            return ManifestValidationResult::make($errors->all(), null, []);
        }

        // Phases 2 a 4 : schema/version, allowlist de champs, types, formats,
        // enums, limites locales et unicite des stable keys.
        $scan = (new ManifestShapeValidator)->validate($root, $errors);

        // Garde de securite independante (spec 10.1). Elle repasse sur l'arbre
        // ENTIER, y compris sous un champ dont la traversee guidee par le
        // schema s'est arretee. Une propriete de ciblage de tenant ne doit pas
        // pouvoir se cacher derriere une autre faute.
        $this->scanForbiddenProperties($root, JsonPointer::ROOT, $errors, isRoot: true);

        $graph = new ManifestGraph($root);

        // Phase 5 : resolution du graphe.
        (new ManifestReferenceValidator)->validate($graph, $scan, $errors);

        // Phase 6 : invariants relationnels et de tenant.
        (new ManifestCoreInvariants)->validate($graph, $errors);
        (new ManifestTrainingInvariants)->validate($graph, $errors);

        // Phase 7 : contenu sanitizable et actifs locaux.
        $this->validateContents($root, $scan, $errors);

        // Phase 8 : limites globales, compteurs et digest.
        $counters = new ManifestCounters;
        $counts = $counters->count($graph);
        $counters->validateGlobalLimits($counts, $errors);

        return ManifestValidationResult::make(
            $errors->all(),
            ManifestCanonicalJson::digest($root),
            $counts,
        );
    }

    /**
     * Parcourt l'arbre a TOUTE PROFONDEUR a la recherche d'un nom de propriete
     * interdit. La deduplication (code, path) fait que ce second passage
     * n'ajoute jamais un doublon a ce que la traversee du schema a deja dit.
     */
    private function scanForbiddenProperties(mixed $node, string $path, ManifestErrorBag $errors, bool $isRoot = false): void
    {
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $name => $value) {
                $childPath = JsonPointer::child($path, $name);
                $code = ManifestForbiddenNames::classify($name, $isRoot);

                if ($code === ManifestErrorCode::TENANT_TARGET_FORBIDDEN || $code === ManifestErrorCode::FORBIDDEN_PROPERTY) {
                    $errors->add($code, $childPath, ManifestForbiddenNames::messageFor($code, $name));
                }

                $this->scanForbiddenProperties($value, $childPath, $errors);
            }

            return;
        }

        if (! is_array($node)) {
            return;
        }

        foreach ($node as $index => $value) {
            $this->scanForbiddenProperties($value, JsonPointer::child($path, (string) $index), $errors);
        }
    }

    /**
     * Contenus sanitizables et cles d'avatar. Les deux controles sont locaux :
     * aucune URL n'est visitee, aucune banque distante n'est interrogee
     * (spec 9.1 et 13).
     */
    private function validateContents(\stdClass $root, ManifestShapeScan $scan, ManifestErrorBag $errors): void
    {
        foreach ($scan->contents as $content) {
            ManifestContentPolicy::check($content['content'], $content['format'], $content['path'], $errors);
        }

        $bank = ($root->assets ?? null) instanceof \stdClass ? ($root->assets->avatar_bank ?? null) : null;

        if (! is_string($bank)) {
            return;
        }

        foreach ($scan->avatars as $avatar) {
            if (! ManifestAvatarBank::has($bank, $avatar['key'])) {
                $errors->add(ManifestErrorCode::AVATAR_NOT_FOUND, $avatar['path'], sprintf(
                    "Avatar key '%s' is not part of the '%s' bank.",
                    $avatar['key'],
                    $bank,
                ));
            }
        }
    }
}
