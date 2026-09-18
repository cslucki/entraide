<?php

namespace App\Services\Dossiers;

use App\Models\DossierFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * TASK-1515 — LE chemin de suppression d'un fichier de Dossier. Un seul.
 *
 * Ce service n'invente rien : il extrait, mot pour mot, le geste que
 * `DossierFileController::destroy()` pratique depuis toujours, pour que la
 * console d'Organization, la console de plateforme et l'espace membre ne
 * puissent pas diverger. Le droit d'appeler reste, lui, entierement a la
 * charge de l'appelant — ce service ne verifie AUCUNE autorisation.
 *
 * ## Pourquoi un `delete()` doux, et jamais `forceDelete()`
 *
 * Cinq cles etrangeres pointent vers `dossier_files`. Deux sont en
 * `cascadeOnDelete` : `article_series_items` et `loop_manifesto_sources`. Un
 * `forceDelete()` detruirait donc en silence des elements de series et des
 * sources de manifeste qu'aucun ecran n'a montres a l'operateur. La ligne
 * survit en pierre tombale ; c'est le BLOB qui part pour de bon.
 *
 * ## Ce qui se passe apres, et qui n'est pas synchrone
 *
 * `$file->delete()` reveille `DossierFileObserver::deleted()`, qui pousse un
 * job sur la file dediee ; `DossierFileIndexer::synchronize()` ne retrouve
 * plus de fichier eligible et appelle `deleteChunks()`. Les extraits partent
 * donc a la passe suivante, pas dans la seconde. Cette latence n'est PAS une
 * fuite : `DossierSemanticSearchService` joint `dossier_files` et filtre
 * `whereNull('deleted_at')`, et `OrganizationRagOverview` fait de meme — un
 * extrait orphelin n'est jamais servi ni montre entre-temps.
 *
 * ## L'ordre des deux gestes
 *
 * Le BLOB d'abord, la ligne ensuite. Si le stockage echoue, la ligne part
 * quand meme : un fichier fantome dans l'inventaire serait plus nuisible
 * qu'un octet orphelin sur le disque, que l'operateur ne peut de toute facon
 * plus atteindre par aucune route.
 */
class DossierFileRemover
{
    public function remove(DossierFile $file): void
    {
        try {
            Storage::disk($file->disk)->delete($file->path);
        } catch (Throwable) {
            // Un disque absent, mal configure ou en erreur ne doit pas
            // empecher le nettoyage de la base : sinon la suppression
            // echouerait a moitie, sans que rien ne le dise.
        }

        $file->delete();
    }
}
