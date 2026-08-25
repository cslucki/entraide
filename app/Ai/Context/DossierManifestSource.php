<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\User;

/**
 * Source RAG `dossier.manifest` (TASK-1307).
 *
 * Une question d'INVENTAIRE (« quels fichiers dans cette Boucle ? », « quels
 * documents sont disponibles ? ») n'a pas de bonne reponse par recherche
 * semantique sur des chunks : le plus proche voisin vectoriel d'« inventaire »
 * n'est pas la liste des fichiers, c'est un paragraphe qui PARLE de fichiers.
 * `DossierRetrievalSource` reste donc structurellement incapable d'y repondre
 * seule — ce n'est pas un bug de ranking, c'est la question qui ne se pose
 * pas en similarite de contenu.
 *
 * Cette source ne fait AUCUNE recherche : elle liste, en texte deterministe
 * et borne, les elements (Articles publies, DossierFiles) des Dossiers de
 * CETTE Boucle que l'utilisateur peut deja voir dans l'interface — les MEMES
 * metadonnees que le panneau Dossiers du ChatLoop, jamais leur contenu.
 *
 * Ce qu'elle garantit :
 * - Organization = Tenant, permission-safe, loop-scoped : exactement le meme
 *   perimetre que `DossierRetrievalSource` (`DossierAccessScope`), pour ne
 *   jamais devenir une deuxieme source de permissions qui pourrait diverger ;
 * - aucun contenu de fichier ni d'article n'est lu — seulement nom, type,
 *   Dossier ;
 * - aucun embedding, aucun appel provider : cout zero ;
 * - hors d'une Boucle (`$contexte->loopId === null`), rien n'est produit —
 *   un inventaire Organization entiere n'est pas borne de la meme facon et
 *   reste hors du perimetre de cette source.
 */
final class DossierManifestSource implements ContextSource
{
    public const NAME = 'dossier.manifest';

    /** Nombre maximum d'elements listes — un Dossier tres charge reste borne. */
    private const MAX_ITEMS = 30;

    public function __construct(private readonly DossierAccessScope $scope) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        if ($contexte->loopId === null || $contexte->userId === null) {
            return SourceFragment::empty();
        }

        if (! $this->scope->loopBelongsToOrganization($contexte->loopId, $contexte->organizationId)) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION);
        }

        $user = User::query()->find($contexte->userId);

        if ($user === null || (string) $user->organization_id !== $contexte->organizationId) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_USER_IN_CONTEXT);
        }

        $dossierIds = $this->scope->accessibleDossierIds($contexte->organizationId, $user, $contexte->loopId);

        if ($dossierIds === []) {
            return SourceFragment::empty();
        }

        $dossiers = Dossier::query()
            ->whereIn('id', $dossierIds)
            ->where('organization_id', $contexte->organizationId)
            ->orderBy('created_at')
            ->get(['id', 'name', 'organization_id']);

        if ($dossiers->isEmpty()) {
            return SourceFragment::empty();
        }

        $items = [];

        foreach ($dossiers as $dossier) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $items = [...$items, ...$this->itemsFor($dossier, self::MAX_ITEMS - count($items))];
        }

        if ($items === []) {
            return SourceFragment::empty();
        }

        $lines = ['--- ELEMENTS DU DOSSIER DE CETTE BOUCLE (metadonnees, pas de contenu) ---'];
        $used = mb_strlen($lines[0]);

        foreach ($items as $item) {
            $line = $item;
            $projected = $used + mb_strlen($line) + 1;

            if ($projected > $charBudget) {
                break;
            }

            $lines[] = $line;
            $used = $projected;
        }

        if (count($lines) === 1) {
            return SourceFragment::empty();
        }

        return new SourceFragment(implode("\n", $lines), []);
    }

    /**
     * @return list<string>
     */
    private function itemsFor(Dossier $dossier, int $remaining): array
    {
        if ($remaining <= 0) {
            return [];
        }

        $items = [];

        // TASK-1307 : memes colonnes qualifiees par table que
        // `DossierSemanticSearchService` (T1216) — `dossier_blog_posts` ET
        // `blog_posts` portent toutes deux `organization_id`, un `where`
        // non qualifie est ambigu des que les deux tables sont jointes.
        // Meme eligibilite que `publiclyReadable()` (T1307, voir
        // `DossierArticleIndexer`) : publie, sans rapport avec
        // `listed_in_blog`.
        $articleTitles = DossierBlogPost::query()
            ->where('dossier_blog_posts.dossier_id', $dossier->id)
            ->where('dossier_blog_posts.organization_id', $dossier->organization_id)
            ->join('blog_posts', 'blog_posts.id', '=', 'dossier_blog_posts.blog_post_id')
            ->where('blog_posts.status', 'published')
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', now())
            ->whereNull('blog_posts.deleted_at')
            ->orderBy('dossier_blog_posts.position')
            ->limit($remaining)
            ->pluck('blog_posts.title');

        foreach ($articleTitles as $title) {
            $items[] = "- Article : {$title} — Dossier « {$dossier->name} »";
        }

        $remaining -= count($items);

        if ($remaining <= 0) {
            return $items;
        }

        $files = DossierFile::query()
            ->where('dossier_files.dossier_id', $dossier->id)
            ->where('dossier_files.organization_id', $dossier->organization_id)
            ->orderBy('created_at')
            ->limit($remaining)
            ->get(['display_name', 'original_name', 'mime_type']);

        foreach ($files as $file) {
            $label = $this->mimeLabel((string) $file->mime_type);
            $name = (string) ($file->display_name ?: $file->original_name);
            $items[] = "- Fichier {$label} : {$name} — Dossier « {$dossier->name} »";
        }

        return $items;
    }

    private function mimeLabel(string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => 'PDF',
            in_array($mimeType, ['text/markdown', 'text/x-markdown'], true) => 'MD',
            $mimeType === 'text/plain' => 'TXT',
            str_starts_with($mimeType, 'image/') => strtoupper(substr($mimeType, 6)),
            default => $mimeType !== '' ? $mimeType : 'fichier',
        };
    }
}
