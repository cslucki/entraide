<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Organization;
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
 * TASK-1307 (revue) : chaque element porte desormais une provenance CITABLE,
 * numerotee [M1]..[Mn] — un espace de reference distinct de [S1]..[Sn]
 * (`DossierRetrievalSource`), pour qu'une affirmation d'EXISTENCE (« ce
 * fichier fait partie de la Boucle ») ne se confonde jamais avec une
 * affirmation de CONTENU (« ce document dit que... »). Avant cette revue, le
 * manifest ne portait aucune provenance : une reponse purement structurelle
 * (aucun chunk semantique pertinent) tombait alors dans le refus
 * `knowledge_no_sources` alors meme que le manifest, seul, pouvait repondre —
 * `LoopKnowledgeAnswerService` combine desormais les deux provenances pour
 * decider si une reponse est possible.
 *
 * Ce qu'elle garantit :
 * - Organization = Tenant, permission-safe, loop-scoped : exactement le meme
 *   perimetre que `DossierRetrievalSource` (`DossierAccessScope`), pour ne
 *   jamais devenir une deuxieme source de permissions qui pourrait diverger ;
 * - aucun contenu de fichier ni d'article n'est lu ni expose en provenance —
 *   seulement nom, type, Dossier, URL d'ouverture : jamais de `extrait`, pour
 *   qu'aucune carte source ne laisse croire qu'un [Mn] a lu le document ;
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

        $organizationSlug = Organization::query()->whereKey($contexte->organizationId)->value('slug');

        $items = [];

        foreach ($dossiers as $dossier) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $items = [...$items, ...$this->itemsFor($dossier, self::MAX_ITEMS - count($items), $organizationSlug)];
        }

        if ($items === []) {
            return SourceFragment::empty();
        }

        $lines = ['--- ELEMENTS DU DOSSIER DE CETTE BOUCLE (metadonnees, pas de contenu) ---'];
        $provenance = [];
        $used = mb_strlen($lines[0]);

        foreach ($items as $item) {
            $ref = 'M'.(count($provenance) + 1);
            $line = "[{$ref}] {$item['text']}";
            $projected = $used + mb_strlen($line) + 1;

            if ($projected > $charBudget) {
                break;
            }

            $lines[] = $line;
            $used = $projected;

            $provenance[] = [
                'source' => self::NAME,
                'type' => 'manifest',
                'ref' => $ref,
                'id' => $item['id'],
                'dossier_id' => $item['dossier_id'],
                'dossier_name' => $item['dossier_name'],
                'source_type' => $item['source_type'],
                'blog_post_id' => $item['blog_post_id'],
                'dossier_file_id' => $item['dossier_file_id'],
                'title' => $item['title'],
                'url' => $item['url'],
                // Volontairement AUCUNE cle `extrait` : le manifest ne lit
                // jamais le contenu, une carte source ne doit jamais laisser
                // croire qu'un [Mn] documente ce qu'un document DIT.
            ];
        }

        if ($provenance === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(implode("\n", $lines), $provenance);
    }

    /**
     * @return list<array{text: string, id: string, dossier_id: string, dossier_name: string, source_type: string, blog_post_id: ?string, dossier_file_id: ?string, title: string, url: ?string}>
     */
    private function itemsFor(Dossier $dossier, int $remaining, ?string $organizationSlug): array
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
        $articles = DossierBlogPost::query()
            ->where('dossier_blog_posts.dossier_id', $dossier->id)
            ->where('dossier_blog_posts.organization_id', $dossier->organization_id)
            ->join('blog_posts', 'blog_posts.id', '=', 'dossier_blog_posts.blog_post_id')
            ->where('blog_posts.status', 'published')
            ->whereNotNull('blog_posts.published_at')
            ->where('blog_posts.published_at', '<=', now())
            ->whereNull('blog_posts.deleted_at')
            ->orderBy('dossier_blog_posts.position')
            ->limit($remaining)
            ->get(['blog_posts.id as blog_post_id', 'blog_posts.title', 'blog_posts.slug']);

        foreach ($articles as $article) {
            $items[] = [
                'text' => "Article : {$article->title} — Dossier « {$dossier->name} »",
                'id' => (string) $article->blog_post_id,
                'dossier_id' => (string) $dossier->id,
                'dossier_name' => (string) $dossier->name,
                'source_type' => 'article',
                'blog_post_id' => (string) $article->blog_post_id,
                'dossier_file_id' => null,
                'title' => (string) $article->title,
                'url' => DossierSourceUrl::forArticle($organizationSlug, (string) $article->slug),
            ];
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
            ->get(['id', 'display_name', 'original_name', 'mime_type']);

        foreach ($files as $file) {
            $label = $this->mimeLabel((string) $file->mime_type);
            $name = (string) ($file->display_name ?: $file->original_name);
            $items[] = [
                'text' => "Fichier {$label} : {$name} — Dossier « {$dossier->name} »",
                'id' => (string) $file->id,
                'dossier_id' => (string) $dossier->id,
                'dossier_name' => (string) $dossier->name,
                'source_type' => 'file',
                'blog_post_id' => null,
                'dossier_file_id' => (string) $file->id,
                'title' => $name,
                'url' => DossierSourceUrl::forFile($organizationSlug, (string) $dossier->id, (string) $file->id, (string) $file->mime_type),
            ];
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
