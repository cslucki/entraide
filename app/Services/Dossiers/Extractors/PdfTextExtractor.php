<?php

namespace App\Services\Dossiers\Extractors;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * PDF via smalot/pdfparser (LGPL-3.0, utilise non modifie). Texte des
 * objets de contenu uniquement : un PDF scanne (images) ne livre rien, et
 * c'est voulu — pas d'OCR dans cette TASK.
 */
class PdfTextExtractor implements DocumentTextExtractor
{
    public const MIME_TYPE = 'application/pdf';

    public function supports(string $mimeType): bool
    {
        return $mimeType === self::MIME_TYPE;
    }

    public function extract(string $absolutePath): ?string
    {
        try {
            $config = new Config;
            // Les images ne servent a rien ici et pesent en memoire sur les
            // gros PDF (le corpus en compte un de 5 Mo fait surtout d'images).
            $config->setRetainImageContent(false);
            // Separateurs lisibles par le chunker : un paragraphe PDF est
            // deja decoupe en lignes ; on evite la concatenation mot-a-mot.
            $config->setHorizontalOffset(' ');

            $document = (new Parser([], $config))->parseFile($absolutePath);
            $text = $document->getText();
        } catch (\Throwable) {
            return null;
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
