<?php

namespace App\Services\Dossiers\Extractors;

/**
 * TASK-1272 : frontiere d'integration MINIMALE entre le pipeline RAG et
 * les bibliotheques de lecture Office/PDF. Une implementation par format,
 * resolue par MIME dans FileContentExtractor — rien d'autre : pas de
 * registry, pas de config. Remplacer le parseur d'un format (un autre
 * lecteur PDF, par exemple) = remplacer UNE classe, le pipeline ne bouge pas.
 *
 * Contrat : `extract()` recoit un CHEMIN ABSOLU (les bibliotheques lisent
 * un fichier, pas une chaine) et retourne le texte brut tel que la
 * bibliotheque le livre — FileContentExtractor assainit et normalise
 * ensuite, de facon identique pour les trois formats. `null` = rien
 * d'extractible ; une exception de bibliotheque est capturee par
 * l'implementation, jamais propagee au pipeline.
 */
interface DocumentTextExtractor
{
    public function supports(string $mimeType): bool;

    public function extract(string $absolutePath): ?string;
}
