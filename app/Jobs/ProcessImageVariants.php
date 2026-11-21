<?php

namespace App\Jobs;

use App\Support\Images\ImageProcessOutcome;
use App\Support\Images\ImageQueue;
use App\Support\Images\ImageSource;
use App\Support\Images\ImageVariantService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1873 — deriver les variantes d'UNE image source.
 *
 * ## Queue dediee `images`, jamais `default`
 *
 * `default` porte des jobs historiques en quarantaine et n'est consommee par
 * aucun worker (voir `routes/console.php` et `TASK1511SchedulerIndexingTest`).
 * `GenerateServiceThumbnail`, qui y partait, n'a donc vraisemblablement
 * jamais tourne en PROD. `images` figure dans l'allowlist du worker planifie,
 * EN DERNIER — mais ce worker n'utilise que la connexion par defaut, `sync`
 * en PROD (mesure 21/11/2026) : il n'y consomme RIEN. La PROD exige une
 * connexion asynchrone dediee (`IMAGE_VARIANTS_QUEUE_CONNECTION`) et un
 * worker `images` (prerequis de release, TASK-1874) ; sans eux, la garde
 * `ImageQueue` refuse lots et derivations a l'upload.
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

        // Connexion non asynchrone (PROD `QUEUE_CONNECTION=sync` sans
        // connexion dediee) : deriver ICI alourdirait la requete d'upload.
        // L'original reste servi ; le rattrapage se fera une fois la queue
        // configuree. Signale, jamais silencieux.
        if (! app(ImageQueue::class)->isAsync()) {
            Log::warning('[TASK-1873] queue images non asynchrone : variantes non produites a l upload', [
                'surface' => $surface,
                'path' => $path,
                'connection' => app(ImageQueue::class)->connection(),
            ]);

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

        // Sous `sync`, une remise en file s'executerait aussitot, dans le
        // meme processus : pas de relance, le prochain lot reprendra.
        if ($outcome->status === ImageProcessOutcome::LOCKED && app(ImageQueue::class)->isAsync()) {
            $this->release(60);
        }
    }
}
