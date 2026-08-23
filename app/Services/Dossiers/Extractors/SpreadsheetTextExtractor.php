<?php

namespace App\Services\Dossiers\Extractors;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * XLSX via phpoffice/phpspreadsheet (MIT, utilise non modifie). Une ligne
 * de texte par ligne de feuille, le nom de la feuille en tete : le
 * chunker recoit des phrases, pas une grille. Lecture des DONNEES
 * uniquement (pas de styles, pas de formules evaluees).
 */
class SpreadsheetTextExtractor implements DocumentTextExtractor
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function supports(string $mimeType): bool
    {
        return $mimeType === self::MIME_TYPE;
    }

    public function extract(string $absolutePath): ?string
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolutePath);

            $lines = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $this->collect($sheet, $lines);
            }

            $spreadsheet->disconnectWorksheets();
        } catch (\Throwable) {
            return null;
        }

        $text = trim(implode("\n", $lines));

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<string>  $lines
     */
    private function collect(Worksheet $sheet, array &$lines): void
    {
        $lines[] = (string) $sheet->getTitle();

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];

            foreach ($row->getCellIterator() as $cell) {
                if (! $cell instanceof Cell) {
                    continue;
                }

                // Valeur telle que saisie ; pour une formule, le resultat
                // mis en cache par le tableur — jamais le moteur de calcul,
                // inutile au RAG et couteux.
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $value = is_scalar($value) ? trim((string) $value) : '';

                if ($value !== '') {
                    $cells[] = $value;
                }
            }

            if ($cells !== []) {
                $lines[] = implode(' | ', $cells);
            }
        }
    }
}
