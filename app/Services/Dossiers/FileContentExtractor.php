<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Str;

/**
 * Extraction locale deterministe du contenu textuel d'un DossierFile
 * TXT/Markdown (TASK-1216). Jamais de LLM, jamais d'execution/rendu du
 * Markdown — seulement un depouillement de syntaxe, meme esprit que
 * ArticleTextExtractor pour le HTML.
 *
 * `extract()` retourne `null` pour tout ce qui n'est pas ingestible
 * proprement (MIME/extension non supporte, encodage invalide, binaire
 * deguise, taille excessive) : c'est le signal "aucun chunk partiel",
 * jamais une exception — le fichier reste un fichier valide du Drive, il
 * n'est simplement pas une source RAG.
 */
class FileContentExtractor
{
    /**
     * Garde-fou d'ingestion, distinct de la limite d'upload (50 Mo,
     * StoreDossierFileRequest) : un texte de plusieurs Mo degraderait le
     * chunking/l'embedding bien avant d'approcher cette limite de stockage.
     */
    private const MAX_INGESTIBLE_BYTES = 5_000_000;

    /**
     * Public depuis TASK-1268 : la commande `dossiers:index-files` selectionne
     * les `dossier_files` sur ce MEME contrat, sans dupliquer la liste.
     */
    public const SUPPORTED_MIME_TYPES = ['text/plain', 'text/markdown'];

    private const SUPPORTED_EXTENSIONS = ['txt', 'md', 'markdown'];

    public function extract(string $raw, string $mimeType, string $originalName): ?string
    {
        if (! $this->isSupported($mimeType, $originalName)) {
            return null;
        }

        if (strlen($raw) > self::MAX_INGESTIBLE_BYTES) {
            return null;
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        // Un fichier texte valide en UTF-8 peut neanmoins contenir des octets
        // de controle qu'un editeur binaire aurait laisses derriere lui — pas
        // du texte deguise en binaire, mais pas du texte non plus.
        if ($this->looksBinary($raw)) {
            return null;
        }

        $isMarkdown = $this->isMarkdown($mimeType, $originalName);

        return $isMarkdown ? $this->stripMarkdown($raw) : $this->normalizePlainText($raw);
    }

    private function isSupported(string $mimeType, string $originalName): bool
    {
        if (in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            return true;
        }

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    private function isMarkdown(string $mimeType, string $originalName): bool
    {
        if ($mimeType === 'text/markdown') {
            return true;
        }

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        return in_array($extension, ['md', 'markdown'], true);
    }

    /**
     * Heuristique simple et deterministe : un fichier texte legitime ne
     * contient pas d'octet NUL. `mb_check_encoding` seul laisse passer des
     * sequences UTF-8 valides mais degenerees (fichier binaire dont les
     * octets forment par hasard de l'UTF-8 valide).
     */
    private function looksBinary(string $raw): bool
    {
        return str_contains($raw, "\0");
    }

    private function normalizePlainText(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;

        $lines = array_map('trim', explode("\n", $text));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Depouille la syntaxe Markdown la plus courante pour ne garder que le
     * texte utile. Deliberement simple (regex, pas de parseur) : aucune
     * dependance nouvelle, et surtout aucun rendu — le contenu n'est jamais
     * interprete comme HTML/JS.
     */
    private function stripMarkdown(string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        // Blocs de code ```...``` : on garde le contenu, on retire les
        // barrieres et l'eventuel langage annonce.
        $text = preg_replace('/^```[^\n]*\n(.*?)\n```$/ms', '$1', $text) ?? $text;
        // Code inline `x` -> x
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        // Images ![alt](url) -> alt
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        // Liens [texte](url) -> texte
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        // Titres ATX: #, ##, ... en debut de ligne
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        // Citations
        $text = preg_replace('/^>\s?/m', '', $text) ?? $text;
        // Puces de liste (-, *, +) et listes numerotees
        $text = preg_replace('/^\s*[-*+]\s+/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*\d+\.\s+/m', '', $text) ?? $text;
        // Emphase gras/italique : **x**, __x__, *x*, _x_
        $text = preg_replace('/(\*\*|__)(.+?)\1/', '$2', $text) ?? $text;
        $text = preg_replace('/(\*|_)(.+?)\1/', '$2', $text) ?? $text;
        // Regles horizontales
        $text = preg_replace('/^(-{3,}|\*{3,}|_{3,})$/m', '', $text) ?? $text;
        // Tables : ne garde que le texte des cellules
        $text = preg_replace('/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?$/m', '', $text) ?? $text;
        $text = str_replace('|', ' ', $text);

        return $this->normalizePlainText($text);
    }
}
