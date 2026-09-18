<?php

namespace App\Ai\Context;

use App\Models\DossierFile;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1307 (revue) : construction d'URL partagee entre `DossierRetrievalSource`
 * (extraits [Sn]) et `DossierManifestSource` (inventaire [Mn]) — les deux
 * sources pointent vers le MEME document, la meme URL doit en sortir des deux
 * cotes plutot que deux logiques qui pourraient diverger.
 */
final class DossierSourceUrl
{
    public static function forArticle(?string $organizationSlug, ?string $postSlug): ?string
    {
        if ($postSlug === null) {
            return null;
        }

        if ($organizationSlug && Route::has('organization.blog.show')) {
            return route('organization.blog.show', ['organization' => $organizationSlug, 'post' => $postSlug]);
        }

        return Route::has('blog.show') ? route('blog.show', ['post' => $postSlug]) : null;
    }

    public static function forFile(?string $organizationSlug, string $dossierId, ?string $fileId, ?string $mimeType): ?string
    {
        // TASK-1296 : URL honnete. Un fichier previewable s'ouvre en apercu
        // (`files.preview`, Content-Disposition inline) ; les autres gardent
        // le telechargement (`files.show`). Les deux routes portent les memes
        // gardes, dans le meme ordre.
        $routeName = DossierFile::isPreviewableMime($mimeType)
            ? 'organization.dossiers.files.preview'
            : 'organization.dossiers.files.show';

        if ($fileId === null || $organizationSlug === null || ! Route::has($routeName)) {
            return null;
        }

        return route($routeName, [
            'organization' => $organizationSlug,
            'dossier' => $dossierId,
            'file' => $fileId,
        ]);
    }

    /**
     * TASK-1534 — une note derivee renvoie vers la CONVERSATION dont elle vient,
     * jamais vers elle-meme.
     *
     * La note est un resume : la verifier suppose de lire ce qui a reellement
     * ete dit. Et le lecteur y a acces par construction — l'eligibilite ne lui
     * a propose cette source que parce qu'il est membre actif de la Boucle.
     * `loops.show` reapplique de toute facon sa propre policy.
     */
    public static function forDerivedNote(?string $loopId): ?string
    {
        if ($loopId === null || $loopId === '' || ! Route::has('loops.show')) {
            return null;
        }

        return route('loops.show', ['loop' => $loopId]);
    }
}
