<?php

namespace App\Services\Dossiers;

use App\Services\Dossiers\Extractors\DocumentTextExtractor;
use App\Services\Dossiers\Extractors\PdfTextExtractor;
use App\Services\Dossiers\Extractors\SpreadsheetTextExtractor;
use App\Services\Dossiers\Extractors\WordTextExtractor;
use Illuminate\Support\Str;

/**
 * Extraction locale deterministe du contenu textuel d'un DossierFile
 * (TASK-1216 : TXT/Markdown ; TASK-1272 : DOCX/PDF/XLSX). Jamais de LLM,
 * jamais d'execution/rendu du Markdown — seulement un depouillement de
 * syntaxe, meme esprit que ArticleTextExtractor pour le HTML.
 *
 * `extract()` retourne `null` pour tout ce qui n'est pas ingestible
 * proprement (MIME/extension non supporte, encodage invalide, binaire
 * deguise, taille excessive, document sans texte) : c'est le signal
 * "aucun chunk partiel", jamais une exception — le fichier reste un fichier
 * valide du Drive, il n'est simplement pas une source RAG.
 *
 * TASK-1272 : les formats Office/PDF sont delegues a UNE implementation de
 * DocumentTextExtractor par format (aucun parseur maison), resolue ici par
 * MIME. Le texte qu'elles livrent passe ensuite par la MEME normalisation
 * que le texte brut.
 */
class FileContentExtractor
{
    /**
     * Garde-fou d'ingestion sur le TEXTE : un texte de plusieurs Mo
     * degraderait le chunking/l'embedding bien avant d'approcher la limite
     * de stockage. Pour TXT/Markdown, octets bruts = texte, la garde porte
     * sur le fichier (comportement TASK-1216, inchange). Pour Office/PDF
     * (TASK-1272), la taille du fichier ne dit rien de celle du texte (images,
     * polices) : la garde porte sur le texte EXTRAIT, apres extraction.
     */
    private const MAX_INGESTIBLE_BYTES = 5_000_000;

    /**
     * TASK-1272 : garde SEPAREE sur le fichier BRUT Office/PDF, alignee sur
     * la limite d'upload (50 Mo, `max:51200` ko dans StoreDossierFileRequest)
     * — on ne charge jamais en memoire un fichier que le Drive n'aurait pas
     * accepte. Elle ne remplace pas la garde texte, elle la precede.
     */
    private const MAX_RAW_DOCUMENT_BYTES = 51_200 * 1024;

    /**
     * Public depuis TASK-1268 : la commande `dossiers:index-files` selectionne
     * les `dossier_files` sur ce MEME contrat, sans dupliquer la liste.
     */
    public const SUPPORTED_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        WordTextExtractor::MIME_TYPE,
        PdfTextExtractor::MIME_TYPE,
        SpreadsheetTextExtractor::MIME_TYPE,
    ];

    /**
     * Second signal apres le MIME (TASK-1216 : un `.md` arrive parfois en
     * `text/plain`). Public depuis TASK-1272 : OrganizationRagOverview
     * selectionne les fichiers eligibles sur ce MEME contrat.
     */
    public const SUPPORTED_EXTENSIONS = ['txt', 'md', 'markdown', 'docx', 'pdf', 'xlsx'];

    /**
     * Second signal, comme pour le Markdown : un `.docx` devine
     * `application/zip` par un vieux libmagic reste un DOCX. Public : le
     * cockpit RAG en derive le format affiche (voir `format()`).
     */
    public const EXTENSION_MIME_TYPES = [
        'docx' => WordTextExtractor::MIME_TYPE,
        'pdf' => PdfTextExtractor::MIME_TYPE,
        'xlsx' => SpreadsheetTextExtractor::MIME_TYPE,
    ];

    /** @var list<DocumentTextExtractor> */
    private readonly array $documentExtractors;

    /**
     * @param  list<DocumentTextExtractor>|null  $documentExtractors  null = les trois implementations du produit
     */
    public function __construct(?array $documentExtractors = null)
    {
        $this->documentExtractors = $documentExtractors ?? [
            new WordTextExtractor,
            new PdfTextExtractor,
            new SpreadsheetTextExtractor,
        ];
    }

    public function extract(string $raw, string $mimeType, string $originalName): ?string
    {
        if (! $this->isSupported($mimeType, $originalName)) {
            return null;
        }

        $documentExtractor = $this->documentExtractorFor($mimeType, $originalName);

        if ($documentExtractor !== null) {
            return $this->extractDocument($raw, $documentExtractor);
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

    /**
     * TASK-1272 : fichier brut -> garde 50 Mo -> extraction par la
     * bibliotheque -> garde 5 Mo sur le texte -> assainissement -> meme
     * normalisation que le texte brut.
     *
     * Le pipeline livre le contenu en memoire (Storage::get, quel que soit
     * le disque) alors que les bibliotheques lisent un fichier : un fichier
     * temporaire fait le pont, supprime quoi qu'il arrive.
     */
    private function extractDocument(string $raw, DocumentTextExtractor $documentExtractor): ?string
    {
        if ($raw === '' || strlen($raw) > self::MAX_RAW_DOCUMENT_BYTES) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'bouclepro-ingest-');

        if ($temporaryPath === false) {
            return null;
        }

        try {
            if (file_put_contents($temporaryPath, $raw) !== strlen($raw)) {
                return null;
            }

            $text = $documentExtractor->extract($temporaryPath);
        } finally {
            @unlink($temporaryPath);
        }

        if ($text === null || strlen($text) > self::MAX_INGESTIBLE_BYTES) {
            return null;
        }

        $text = $this->sanitizeExtractedText($text);

        return $text === '' ? null : $text;
    }

    /**
     * Le texte livre par une bibliotheque n'est pas celui d'un humain : une
     * police exotique ou un PDF mal forme peuvent laisser des sequences
     * invalides ou des caracteres de controle. On nettoie au lieu de
     * refuser — le document, lui, est legitime.
     */
    private function sanitizeExtractedText(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        // Caracteres de controle C0/C1 hors tabulation et retours a la ligne.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]+/u', ' ', $text) ?? $text;

        return $this->normalizePlainText($text);
    }

    private function documentExtractorFor(string $mimeType, string $originalName): ?DocumentTextExtractor
    {
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        $candidates = array_unique(array_filter([$mimeType, self::EXTENSION_MIME_TYPES[$extension] ?? null]));

        foreach ($candidates as $candidate) {
            foreach ($this->documentExtractors as $documentExtractor) {
                if ($documentExtractor->supports($candidate)) {
                    return $documentExtractor;
                }
            }
        }

        return null;
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
     * Le format REEL d'un fichier supporte, par la regle de ce service :
     * MIME d'abord, extension ensuite. `txt` | `markdown` | `docx` | `pdf` |
     * `xlsx` — une donnee pour le cockpit RAG (TASK-1226/1272), pas une
     * heuristique de titre.
     */
    public static function format(string $mimeType, string $originalName): string
    {
        $byMime = [
            'text/markdown' => 'markdown',
            WordTextExtractor::MIME_TYPE => 'docx',
            PdfTextExtractor::MIME_TYPE => 'pdf',
            SpreadsheetTextExtractor::MIME_TYPE => 'xlsx',
        ];

        if (isset($byMime[$mimeType])) {
            return $byMime[$mimeType];
        }

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($extension) {
            'md', 'markdown' => 'markdown',
            'docx', 'pdf', 'xlsx' => $extension,
            default => 'txt',
        };
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
