<?php

namespace App\Support\ScenarioManifest;

/**
 * Compteurs du rapport de synthese (spec 9.1 phase 8) et limites GLOBALES,
 * celles qui ne portent sur aucune collection en particulier.
 *
 * Les compteurs ne sont pas decoratifs : la spec 14 en fait le contenu
 * minimal de la previsualisation SuperAdmin, et la spec 5.2 impose de les
 * repeter avant Load, au moment ou l'humain accepte une ECRITURE metier. Ils
 * sont donc produits par la validation elle-meme, jamais recalcules plus tard
 * par un ecran qui pourrait compter autrement.
 */
final class ManifestCounters
{
    /**
     * Ordre STABLE : le rapport se lit et se compare d'une validation a
     * l'autre.
     *
     * @return array<string, int>
     */
    public function count(ManifestGraph $graph): array
    {
        $rootDocuments = 0;

        foreach ($graph->collection('dossiers') as $dossier) {
            if (($dossier->root_document ?? null) instanceof \stdClass) {
                $rootDocuments++;
            }
        }

        return [
            'users' => count($graph->collection('users')),
            'loops' => count($graph->collection('loops')),
            'memberships' => count($graph->collection('memberships')),
            'dossiers' => count($graph->collection('dossiers')),
            'root_documents' => $rootDocuments,
            'articles' => count($graph->collection('articles')),
            'files' => count($graph->collection('files')),
            'messages' => count($graph->collection('messages')),
            'categories' => count($graph->collection('categories')),
            'skills' => count($graph->collection('skills')),
            'service_requests' => count($graph->collection('service_requests')),
            'services' => count($graph->collection('services')),
            'polls' => count($graph->collection('polls')),
            'events' => count($graph->collection('events')),
            'decisions' => count($graph->collection('decisions')),
            'roadmap_items' => count($graph->collection('roadmap_items')),
            'training_modules' => count($graph->collection('training.modules')),
            'training_sequences' => count($graph->collection('training.sequences')),
            'training_progress' => count($graph->collection('training.progress')),
            'training_assignments' => count($graph->collection('training.assignments')),
            'training_submissions' => count($graph->collection('training.submissions')),
            'declared_objects' => $this->countObjects($graph->root()),
            'content_bytes' => $this->contentBytes($graph),
        ];
    }

    /**
     * @param  array<string, int>  $counters
     */
    public function validateGlobalLimits(array $counters, ManifestErrorBag $errors): void
    {
        if ($counters['declared_objects'] > ManifestSchema::MAX_OBJECTS) {
            $errors->add(ManifestErrorCode::LIMIT_EXCEEDED, JsonPointer::ROOT, sprintf(
                'Manifest declares %d objects, children included; the limit is %d.',
                $counters['declared_objects'],
                ManifestSchema::MAX_OBJECTS,
            ));
        }

        if ($counters['content_bytes'] > ManifestSchema::MAX_CONTENT_BYTES) {
            $errors->add(ManifestErrorCode::LIMIT_EXCEEDED, JsonPointer::ROOT, sprintf(
                'Root document, article, file and message contents total %d bytes; the limit is %d.',
                $counters['content_bytes'],
                ManifestSchema::MAX_CONTENT_BYTES,
            ));
        }
    }

    /**
     * "Total d'objets declares, ENFANTS INCLUS" (spec 9.2) : tout noeud objet
     * du document compte, y compris l'enveloppe, un `member_ai_profile` ou une
     * option de sondage.
     */
    private function countObjects(mixed $node): int
    {
        if ($node instanceof \stdClass) {
            $total = 1;

            foreach (get_object_vars($node) as $child) {
                $total += $this->countObjects($child);
            }

            return $total;
        }

        if (! is_array($node)) {
            return 0;
        }

        $total = 0;

        foreach ($node as $child) {
            $total += $this->countObjects($child);
        }

        return $total;
    }

    /**
     * La limite porte sur la SOMME des contenus root/article/file/message
     * (spec 9.2), pas sur chacun : c'est ce total qui borne ce qu'une
     * previsualisation doit rendre lisible et ce que le Load devra ecrire.
     */
    private function contentBytes(ManifestGraph $graph): int
    {
        $total = 0;

        foreach ($graph->collection('dossiers') as $dossier) {
            $document = $dossier->root_document ?? null;

            if ($document instanceof \stdClass && is_string($document->content ?? null)) {
                $total += strlen($document->content);
            }
        }

        foreach ([['articles', 'content'], ['files', 'content'], ['messages', 'body']] as [$collection, $field]) {
            foreach ($graph->collection($collection) as $item) {
                if (is_string($item->{$field} ?? null)) {
                    $total += strlen($item->{$field});
                }
            }
        }

        return $total;
    }
}
