<?php

namespace App\Services\Dossiers;

use App\Jobs\IndexDossierFileChunks;
use App\Models\DossierFile;

/**
 * Clone structurel de DossierArticleIndexingDispatcher (TASK-1216). Un
 * fichier n'a pas de pivot attach/detach (contrairement a l'Article) : il
 * appartient a exactement un Dossier via `dossier_id`. Un "detach" est donc
 * un changement de `dossier_id` (voir DossierFileObserver::updated()).
 */
class DossierFileIndexingDispatcher
{
    /**
     * TASK-1268 : queue DEDIEE de l'indexation des fichiers de Dossier.
     * Jamais `default` : sur la surface produit `main`, la queue `default`
     * porte deja des jobs historiques que personne n'a decide d'executer ;
     * un worker n'ecoute QUE cette queue-ci.
     *
     * TASK-1407 : elle etait la queue de la seule reindexation EXPLICITE
     * (commande `dossiers:index-files`). Elle est desormais celle de TOUTE
     * indexation de fichier, l'auto-indexation de l'Observer comprise.
     */
    public const DEDICATED_QUEUE = 'dossier-files-indexing';

    public function dispatch(string $organizationId, string $dossierId, string $fileId, ?string $queue = null): void
    {
        $pending = IndexDossierFileChunks::dispatch($organizationId, $dossierId, $fileId)->afterCommit();

        // TASK-1407 : `null` et `''` signifient « aucune queue explicite
        // demandee », jamais « la queue `default` ». Sans cette ligne, les six
        // chemins de DossierFileObserver (created, contenu modifie, les DEUX
        // cotes d'un deplacement, deleted, restored) partaient sur `default`,
        // en contradiction avec la doctrine ci-dessus : un worker dedie ne les
        // aurait jamais vus.
        //
        // Le defaut est pose ICI, dans le corps, et NON comme valeur par
        // defaut du parametre. `dispatchForFiles()` retransmet son propre
        // `$queue` EXPLICITEMENT, y compris quand il vaut null — or une valeur
        // par defaut de parametre ne s'applique qu'a un argument OMIS.
        // `dispatchForFiles($files)` serait donc reste sur `default`, et la
        // correction aurait eu un trou invisible a la lecture de la signature.
        $pending->onQueue($queue !== null && $queue !== '' ? $queue : self::DEDICATED_QUEUE);
    }

    public function dispatchForFile(DossierFile $file, ?string $dossierId = null): void
    {
        if (! is_string($file->organization_id) || $file->organization_id === '') {
            return;
        }

        $dossierId ??= $file->dossier_id;

        if (! is_string($dossierId) || $dossierId === '') {
            return;
        }

        $this->dispatch($file->organization_id, $dossierId, (string) $file->id);
    }

    /**
     * TASK-1268 : clone structurel de
     * DossierArticleIndexingDispatcher::dispatchForEntries(). Un fichier sans
     * Organization ou sans Dossier n'est pas dispatche (meme regle que
     * dispatchForFile()) et n'est pas compte.
     *
     * @param  iterable<int, DossierFile|array{organization_id: string, dossier_id: string, id: string}>  $files
     */
    public function dispatchForFiles(iterable $files, ?string $queue = null): int
    {
        $count = 0;

        foreach ($files as $file) {
            $organizationId = (string) data_get($file, 'organization_id');
            $dossierId = (string) data_get($file, 'dossier_id');
            $fileId = (string) data_get($file, 'id');

            if ($organizationId === '' || $dossierId === '' || $fileId === '') {
                continue;
            }

            $this->dispatch($organizationId, $dossierId, $fileId, $queue);

            $count++;
        }

        return $count;
    }
}
