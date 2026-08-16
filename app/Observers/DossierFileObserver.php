<?php

namespace App\Observers;

use App\Models\DossierFile;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Clone structurel de BlogPostObserver (TASK-1216). Un fichier n'a pas de
 * pivot attach/detach : `move()` (changement de `dossier_id`) est
 * l'equivalent fonctionnel d'un detach de l'ancien Dossier + attach au
 * nouveau — les deux cotes sont donc synchronises.
 */
class DossierFileObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private DossierFileIndexingDispatcher $indexing) {}

    public function created(DossierFile $file): void
    {
        $this->indexing->dispatchForFile($file);
    }

    public function updated(DossierFile $file): void
    {
        if ($file->wasChanged('dossier_id')) {
            $previousDossierId = $file->getOriginal('dossier_id');

            if (is_string($previousDossierId) && $previousDossierId !== '' && is_string($file->organization_id)) {
                $this->indexing->dispatch($file->organization_id, $previousDossierId, (string) $file->id);
            }

            $this->indexing->dispatchForFile($file);

            return;
        }

        // `rename()` ne touche que `display_name` : aucun impact sur le
        // contenu indexe, aucune reindexation. `updateMarkdown()` touche
        // `checksum_sha256` (contenu change). `mime_type` par prudence, au
        // cas ou un futur endpoint le modifierait.
        if ($file->wasChanged(['checksum_sha256', 'mime_type'])) {
            $this->indexing->dispatchForFile($file);
        }
    }

    public function deleted(DossierFile $file): void
    {
        if ($file->isForceDeleting()) {
            return;
        }

        $this->indexing->dispatchForFile($file);
    }

    public function restored(DossierFile $file): void
    {
        $this->indexing->dispatchForFile($file);
    }
}
