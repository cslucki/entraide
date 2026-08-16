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
    public function dispatch(string $organizationId, string $dossierId, string $fileId): void
    {
        IndexDossierFileChunks::dispatch($organizationId, $dossierId, $fileId)->afterCommit();
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
}
