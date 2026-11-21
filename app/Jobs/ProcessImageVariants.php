<?php

namespace App\Jobs;

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

    /**
     * Deux essais : le second ne sert qu'apres une EXCEPTION ou un depassement
     * de duree (worker tue). Un echec metier (image illisible, ecriture
     * refusee) n'est PAS une exception : le service l'inscrit au registre et
     * le job se termine normalement — c'est le registre qui le reprend.
     */
    public int $tries = 2;

    /**
     * STRICTEMENT sous `retry_after` (90 s, queue `database`) : au-dela, la
     * queue croit le job perdu et le redonne a un autre worker PENDANT qu'il
     * tourne encore — deux derivations concurrentes de la meme image.
     */
    public int $timeout = 75;

    public int $backoff = 30;

    /** Deux uploads identiques en file ne produisent qu'un seul job. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $surface,
        public readonly string $path,
        public readonly ?string $organizationId = null,
        public readonly string $disk = 'public',
        public readonly bool $backfill = false,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
        $this->afterCommit();

        // Connexion DEDIEE optionnelle : en local, les images seules partent
        // sur `database` (worker dedie) quand le reste reste en `sync`.
        if ($connection = config('image_variants.queue_connection')) {
            $this->onConnection($connection);
        }
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

        // `force` : un upload peut REECRIRE un chemin deja connu (nom
        // horodate a la seconde, par exemple) ; ses anciennes variantes
        // seraient sinon tenues pour a jour.
        static::dispatch($surface, $path, $organizationId, 'public', false, true);
    }

    public function uniqueId(): string
    {
        return sha1($this->disk.'|'.$this->path);
    }

    public function handle(ImageVariantService $service): void
    {
        // `backfill` est conserve dans la signature pour les jobs deja
        // serialises ; les lots de rattrapage passent desormais par
        // `ProcessImageBatchItem`, dont la pause est portee par le lot en base.
        $outcome = $service->process(new ImageSource($this->surface, $this->path, $this->organizationId, $this->disk), $this->force);

        if ($outcome->status === ImageProcessOutcome::LOCKED) {
            $this->release(60);
        }
    }
}
