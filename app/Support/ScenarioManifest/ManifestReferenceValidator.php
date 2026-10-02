<?php

namespace App\Support\ScenarioManifest;

/**
 * Phase 5 de la spec 9.1 : resolution de TOUT le graphe avant Load.
 *
 * "Toute reference manquante ou cyclique rend le manifeste entier INVALID"
 * (spec 8.2). C'est ce qui permet au loader futur d'appliquer un ordre
 * topologique sans jamais rencontrer une cle qu'il ne sait pas resoudre, et
 * donc sans jamais avoir a inventer un objet ou a abandonner un chargement a
 * moitie ecrit.
 *
 * Une reference ne peut viser que l'objet attendu PAR SON CHAMP : la meme
 * chaine peut exister dans deux collections sans ambiguite, parce que le type
 * cible est fixe par le schema et non devine.
 */
final class ManifestReferenceValidator
{
    public function validate(ManifestGraph $graph, ManifestShapeScan $scan, ManifestErrorBag $errors): void
    {
        foreach ($scan->references as $reference) {
            if ($graph->has($reference['collection'], $reference['value'])) {
                continue;
            }

            $errors->add(ManifestErrorCode::REFERENCE_NOT_FOUND, $reference['path'], sprintf(
                "Unknown %s key '%s'.",
                $this->label($reference['collection']),
                $reference['value'],
            ));
        }

        $this->validateDossierGraph($graph, $errors);
    }

    /**
     * Le graphe de Dossiers est le seul du langage qui soit recursif : il doit
     * etre acyclique et sa profondeur ne depasse pas 10 (spec 7.4). Un cycle
     * ferait boucler le loader ; une hierarchie sans fond rendrait la
     * previsualisation illisible.
     */
    private function validateDossierGraph(ManifestGraph $graph, ManifestErrorBag $errors): void
    {
        foreach ($graph->collection('dossiers') as $index => $dossier) {
            $key = $dossier->key ?? null;

            if (! is_string($key)) {
                continue;
            }

            $path = JsonPointer::child(JsonPointer::child($graph->pathOf('dossiers'), $index), 'parent');
            $seen = [$key => true];
            $depth = 1;
            $current = $dossier;

            while (true) {
                $parent = $current->parent ?? null;

                if (! is_string($parent)) {
                    break;
                }

                if (array_key_exists($parent, $seen)) {
                    $errors->add(ManifestErrorCode::REFERENCE_CYCLE, $path, sprintf(
                        "Dossier '%s' takes part in a parent cycle.",
                        $key,
                    ));

                    break;
                }

                $next = $graph->find('dossiers', $parent);

                if ($next === null) {
                    // Reference inconnue : deja signalee par la resolution
                    // ci-dessus, inutile de l'annoncer une seconde fois.
                    break;
                }

                $seen[$parent] = true;
                $depth++;
                $current = $next;

                if ($depth > ManifestSchema::MAX_DOSSIER_DEPTH) {
                    $errors->add(ManifestErrorCode::LIMIT_EXCEEDED, $path, sprintf(
                        'Dossier nesting exceeds the maximum depth of %d.',
                        ManifestSchema::MAX_DOSSIER_DEPTH,
                    ));

                    break;
                }
            }
        }
    }

    private function label(string $collection): string
    {
        return match ($collection) {
            'users' => 'user',
            'loops' => 'loop',
            'dossiers' => 'dossier',
            'articles' => 'article',
            'files' => 'file',
            'messages' => 'message',
            'categories' => 'category',
            'skills' => 'skill',
            'decisions' => 'decision',
            'training.modules' => 'training module',
            'training.sequences' => 'training sequence',
            'training.assignments' => 'training assignment',
            default => $collection,
        };
    }
}
