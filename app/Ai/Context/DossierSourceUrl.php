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
}
