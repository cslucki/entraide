<?php

namespace App\Jobs;

use App\Models\BlogPostFile;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\Extractors\PdfTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-1907 — extrait le texte d'un PDF d'article, HORS requete.
 *
 * Un PDF peut peser 20 Mo : l'analyser pendant le depot ferait attendre
 * l'auteur et pourrait epuiser la memoire d'une requete web. Le texte sert a
 * la recherche du site et a l'indexation IA de l'article, avec la meme
 * audience que l'article (rien n'est expose de plus).
 *
 * Une extraction est faite UNE fois : un PDF sans texte (scan) est marque
 * `extracted_at` avec un texte vide, et n'est pas retente indefiniment.
 */
class ExtractBlogPostFileText implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * TASK-1909 — file DEDIEE, dans l'allowlist du worker planifie
     * (routes/console.php) et du worker local d'indexation.
     */
    public const QUEUE = 'blog-files-extraction';

    public int $tries = 2;

    public int $timeout = 120;

    /** Au-dela, le texte est tronque : la recherche et l'IA n'en ont pas besoin de plus. */
    public const MAX_CHARS = 200_000;

    public function __construct(public readonly string $fileId) {}

    public function handle(PdfTextExtractor $extractor, DossierArticleIndexingDispatcher $indexing): void
    {
        $file = BlogPostFile::find($this->fileId);

        if (! $file || $file->kind !== BlogPostFile::KIND_PDF || $file->extracted_at !== null) {
            return;
        }

        $text = $this->extract($file, $extractor);

        $file->forceFill([
            'extracted_text' => $text !== null ? mb_substr($text, 0, self::MAX_CHARS) : null,
            'extracted_at' => now(),
        ])->save();

        // Article deja rattache : son index IA doit inclure ce texte.
        if ($text !== null && $file->blogPost) {
            $indexing->dispatchForBlogPost($file->blogPost);
        }
    }

    private function extract(BlogPostFile $file, PdfTextExtractor $extractor): ?string
    {
        $disk = Storage::disk($file->disk);

        if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
            return $extractor->extract($disk->path($file->path));
        }

        // Disque distant : copie temporaire locale, supprimee quoi qu'il arrive.
        $temporary = tempnam(sys_get_temp_dir(), 'bp-pdf-');

        try {
            $stream = $disk->readStream($file->path);
            if (! $stream) {
                return null;
            }
            file_put_contents($temporary, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $extractor->extract($temporary);
        } finally {
            @unlink($temporary);
        }
    }
}
