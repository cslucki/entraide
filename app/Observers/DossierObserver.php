<?php

namespace App\Observers;

use App\Models\Dossier;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DossierObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private DossierArticleIndexingDispatcher $indexing) {}

    public function deleted(Dossier $dossier): void
    {
        if ($dossier->isForceDeleting()) {
            return;
        }

        $this->indexing->dispatchForDossier($dossier);
    }

    public function restored(Dossier $dossier): void
    {
        $this->indexing->dispatchForDossier($dossier);
    }
}
