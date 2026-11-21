<?php

namespace App\Jobs;

use App\Support\Images\ImageOptimizationControl;
use App\Support\Images\ImageProcessOutcome;
use App\Support\Images\ImageSource;
use App\Support\Images\ImageVariantService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * TASK-1873 — deriver les variantes d'UNE image source.
 *
 * ## Queue dediee `images`, jamais `default`
 *
 * `default` porte des jobs historiques en quarantaine et n'est consommee par
 * aucun worker (voir `routes/console.php` et `TASK1511SchedulerIndexingTest`).
 * `GenerateServiceThumbnail`, qui y partait, n'a donc vraisemblablement
 * jamais tourne en PROD. `images` rejoint l'allowlist du worker planifie, EN
 * DERNIER : l'indexation passe avant.
 *
 * ## Apres commit
 *
 * L'upload ecrit l'original puis la ligne metier dans la meme requete. Un job
 * parti avant le commit pourrait lire un chemin que le rollback a deja rendu
 * orphelin ; `afterCommit()` attend la fin de la transaction.
 *
 * ## Erreurs
 *
 * Le service n'echoue pas en levant : il INSCRIT l'echec au registre, qui le
 * reprend au lot suivant (3 tentatives, puis abandon visible en console). Le
 * seul cas renvoye a la file est le verrou pris par un traitement concurrent.
 */
class ProcessImageVariants implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'images';

    public int $tries = 3;

    /** Une image bornee a 25 Mpx se derive en quelques secondes ; marge large. */
    public int $timeout = 120;

    /** Deux uploads identiques en file ne produisent qu'un seul job. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $surface,
        public readonly string $path,
        public readonly ?string $organizationId = null,
        public readonly string $disk = 'public',
        public readonly bool $backfill = false,
    ) {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();
    }

    /**
     * Met en file la derivation d'une image tout juste ecrite. Sans effet
     * pour un chemin vide ou une URL externe (avatar distant, par exemple).
     */
    public static function forUpload(string $surface, ?string $path, ?string $organizationId = null): void
    {
        if ($path === null || $path === '' || preg_match('#^https?://#i', $path) === 1) {
            return;
        }

        static::dispatch($surface, $path, $organizationId);
    }

    public function uniqueId(): string
    {
        return sha1($this->disk.'|'.$this->path);
    }

    public function handle(ImageVariantService $service, ImageOptimizationControl $control): void
    {
        if ($this->backfill && $control->paused()) {
            // Pause du rattrapage : on n'ecrit rien. La reprise relance un
            // lot, et l'idempotence reprend exactement ici.
            return;
        }

        $outcome = $service->process(new ImageSource($this->surface, $this->path, $this->organizationId, $this->disk));

        if ($outcome->status === ImageProcessOutcome::LOCKED) {
            $this->release(60);
        }
    }
}
