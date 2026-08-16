<?php

namespace App\Services\Dossiers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use Illuminate\Support\Facades\DB;

/**
 * Read model de la console RAG Organization (TASK-1217).
 *
 * Repond a une seule question, pour UNE Organization : « qu'est-ce que l'IA
 * connait de mes Dossiers, et l'index est-il sain ? ». Lecture seule, requetes
 * locales : aucune reindexation, aucun appel provider, aucune ecriture.
 *
 * Doctrine (revue MASTER) — ce read model n'invente jamais un etat :
 * - `indexe` / `non indexe` se prouvent par la presence de lignes dans
 *   `dossier_chunks` ;
 * - `pending`, `erreur par source` et `perime` NE SONT PAS exposes : la queue
 *   Laravel est transitoire (aucune ligne ne survit au traitement, et
 *   `jobs`/`job_batches` ne portent ni organization_id ni source_id), le
 *   chemin d'echec d'embedding (`RecordSdkEmbeddingsInvocation::recordFailure`)
 *   ne recoit que provider+model sans rattachement garanti a une source, et
 *   « perime » exigerait de re-extraire puis re-chunker chaque source a
 *   l'affichage. Les afficher demanderait de les deviner.
 *
 * Tenant : chaque requete est bornee par `organization_id`. La visibilite du
 * CONTENU (lien « Ouvrir ») n'est PAS decidee ici — elle reste a la
 * `DossierPolicy`, appliquee par l'appelant, ligne par ligne : un admin
 * d'Organization voit l'ETAT de l'index sans heriter d'un droit de lecture sur
 * un Dossier prive (« portee != sujet »).
 */
class OrganizationRagOverview
{
    /**
     * MIME reellement ingerables aujourd'hui (TASK-1216, cf.
     * FileContentExtractor). Un fichier hors de cette liste n'est pas « en
     * erreur » : il n'est simplement pas une source RAG.
     */
    private const INGESTIBLE_MIME_TYPES = ['text/plain', 'text/markdown'];

    private const INGESTIBLE_EXTENSIONS = ['txt', 'md', 'markdown'];

    /**
     * Compteurs de tete de page.
     *
     * @return array{dossiers: int, articles: int, files: int, chunks: int, indexed_sources: int, last_indexed_at: ?string}
     */
    public function summary(string $organizationId): array
    {
        $chunks = DB::table('dossier_chunks')->where('organization_id', $organizationId);

        return [
            'dossiers' => $this->dossierCount($organizationId),
            'articles' => $this->eligibleArticleQuery($organizationId)->count(),
            'files' => $this->eligibleFileQuery($organizationId)->count(),
            'chunks' => (clone $chunks)->count(),
            'indexed_sources' => $this->indexedSourceCount($organizationId),
            'last_indexed_at' => (clone $chunks)->max('indexed_at'),
        ];
    }

    /**
     * Une ligne par source pouvant alimenter le RAG (Article ou fichier),
     * indexee ou non. L'absence de chunk est un fait, pas une anomalie.
     *
     * @return list<array{type: string, id: string, title: string, dossier_id: string, dossier_name: string, indexed: bool, chunks: int, embedding_provider: ?string, embedding_model: ?string, indexed_at: ?string, slug: ?string}>
     */
    public function sources(string $organizationId): array
    {
        return array_merge(
            $this->articleSources($organizationId),
            $this->fileSources($organizationId),
        );
    }

    /**
     * Diagnostics techniques — uniquement ce qui se calcule reellement.
     *
     * L'identite d'un vecteur tient au COUPLE (famille, modele) : deux
     * modeles d'une meme famille produisent des espaces vectoriels
     * differents, tout autant que deux familles. Provider et modele sont
     * donc verifies separement, et l'un suffit a rendre l'index incoherent.
     *
     * Aucune compatibilite n'est supposee entre deux modeles differents :
     * « different » signifie « incoherent », jamais « probablement
     * equivalent ».
     *
     * @return array{chunks: int, distinct_articles: int, distinct_files: int, providers: list<string>, models: list<string>, index_family: string, index_model: string, provider_mismatch: bool, model_mismatch: bool, index_mismatch: bool}
     */
    public function diagnostics(string $organizationId): array
    {
        $chunks = DB::table('dossier_chunks')->where('organization_id', $organizationId);

        $providers = (clone $chunks)->distinct()->pluck('embedding_provider')
            ->filter()->map(fn ($value): string => (string) $value)->values()->all();
        $models = (clone $chunks)->distinct()->pluck('embedding_model')
            ->filter()->map(fn ($value): string => (string) $value)->values()->all();

        $indexFamily = trim((string) config('ai.default_for_embeddings', 'openai'));
        $indexModel = trim((string) config("ai.providers.{$indexFamily}.models.embeddings.default", ''));

        $providerMismatch = $this->valuesDivergeFromConfigured($providers, $indexFamily);
        $modelMismatch = $this->valuesDivergeFromConfigured($models, $indexModel);

        return [
            'chunks' => (clone $chunks)->count(),
            'distinct_articles' => (clone $chunks)->whereNotNull('blog_post_id')->distinct()->count('blog_post_id'),
            'distinct_files' => (clone $chunks)->whereNotNull('dossier_file_id')->distinct()->count('dossier_file_id'),
            'providers' => $providers,
            'models' => $models,
            'index_family' => $indexFamily,
            'index_model' => $indexModel,
            'provider_mismatch' => $providerMismatch,
            'model_mismatch' => $modelMismatch,
            'index_mismatch' => $providerMismatch || $modelMismatch,
        ];
    }

    /**
     * Deux facons, et deux seulement, de demontrer une incoherence :
     * plusieurs valeurs distinctes stockees dans le meme index, ou une
     * valeur stockee qui differe de la configuration COURANTE — cette
     * seconde comparaison n'ayant de sens que si la configuration est
     * effectivement connue. Configuration inconnue = rien a affirmer.
     *
     * @param  list<string>  $stored
     */
    private function valuesDivergeFromConfigured(array $stored, string $configured): bool
    {
        if (count($stored) > 1) {
            return true;
        }

        if ($stored === [] || $configured === '') {
            return false;
        }

        return $stored !== [$configured];
    }

    private function dossierCount(string $organizationId): int
    {
        return Dossier::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Le nombre de sources REELLEMENT representees dans l'index, toutes
     * origines confondues.
     *
     * Volontairement NON filtre sur l'eligibilite courante (Dossier vivant,
     * Article encore publie…) : ce compteur mesure ce qui occupe
     * physiquement `dossier_chunks`. C'est precisement son interet — un
     * ecart avec « Articles » + « Fichiers » revele un index qui contient
     * encore des sources qui ne devraient plus y etre.
     */
    private function indexedSourceCount(string $organizationId): int
    {
        $chunks = DB::table('dossier_chunks')->where('organization_id', $organizationId);

        return (clone $chunks)->whereNotNull('blog_post_id')->distinct()->count('blog_post_id')
            + (clone $chunks)->whereNotNull('dossier_file_id')->distinct()->count('dossier_file_id');
    }

    /**
     * Articles eligibles : publies ET attaches a un Dossier VIVANT —
     * exactement les criteres de `DossierArticleIndexer` (scope
     * `published()`), pas ceux du SQL de retrieval, qui ignore
     * `listed_in_blog`. C'est l'INGESTION que cette console decrit.
     *
     * Le Dossier non supprime fait partie de l'eligibilite, ici et pas
     * seulement dans `sources()` : sinon les compteurs de tete compteraient
     * des sources que la liste, elle, n'affiche pas — l'ecran se
     * contredirait lui-meme.
     */
    private function eligibleArticleQuery(string $organizationId)
    {
        return BlogPost::query()
            ->where('blog_posts.organization_id', $organizationId)
            ->published()
            ->whereNull('blog_posts.deleted_at')
            ->whereExists(function ($query) use ($organizationId) {
                $query->select(DB::raw(1))
                    ->from('dossier_blog_posts')
                    ->join('dossiers', 'dossiers.id', '=', 'dossier_blog_posts.dossier_id')
                    ->whereColumn('dossier_blog_posts.blog_post_id', 'blog_posts.id')
                    ->where('dossier_blog_posts.organization_id', $organizationId)
                    ->where('dossiers.organization_id', $organizationId)
                    ->whereNull('dossiers.deleted_at');
            });
    }

    private function eligibleFileQuery(string $organizationId)
    {
        return DossierFile::query()
            ->where('dossier_files.organization_id', $organizationId)
            ->whereNull('dossier_files.deleted_at')
            ->whereNotNull('dossier_files.dossier_id')
            ->whereExists(function ($query) use ($organizationId) {
                $query->select(DB::raw(1))
                    ->from('dossiers')
                    ->whereColumn('dossiers.id', 'dossier_files.dossier_id')
                    ->where('dossiers.organization_id', $organizationId)
                    ->whereNull('dossiers.deleted_at');
            })
            ->where(function ($query) {
                $query->whereIn('dossier_files.mime_type', self::INGESTIBLE_MIME_TYPES);

                // Un .md depuis un poste Windows arrive parfois en
                // `text/plain`, et l'inverse existe aussi : l'extension est
                // le second signal, exactement comme dans FileContentExtractor.
                foreach (self::INGESTIBLE_EXTENSIONS as $extension) {
                    $query->orWhere('dossier_files.original_name', 'like', '%.'.$extension);
                }
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articleSources(string $organizationId): array
    {
        return $this->eligibleArticleQuery($organizationId)
            ->join('dossier_blog_posts', function ($join) use ($organizationId) {
                $join->on('dossier_blog_posts.blog_post_id', '=', 'blog_posts.id')
                    ->where('dossier_blog_posts.organization_id', '=', $organizationId);
            })
            ->join('dossiers', function ($join) use ($organizationId) {
                $join->on('dossiers.id', '=', 'dossier_blog_posts.dossier_id')
                    ->where('dossiers.organization_id', '=', $organizationId)
                    ->whereNull('dossiers.deleted_at');
            })
            ->leftJoin('dossier_chunks', function ($join) use ($organizationId) {
                $join->on('dossier_chunks.blog_post_id', '=', 'blog_posts.id')
                    ->on('dossier_chunks.dossier_id', '=', 'dossiers.id')
                    ->where('dossier_chunks.organization_id', '=', $organizationId);
            })
            ->groupBy('blog_posts.id', 'blog_posts.title', 'blog_posts.slug', 'dossiers.id', 'dossiers.name')
            ->select([
                'blog_posts.id as source_id',
                'blog_posts.title as title',
                'blog_posts.slug as slug',
                'dossiers.id as dossier_id',
                'dossiers.name as dossier_name',
                DB::raw('COUNT(dossier_chunks.id) as chunk_count'),
                DB::raw('MAX(dossier_chunks.indexed_at) as last_indexed_at'),
                DB::raw('MAX(dossier_chunks.embedding_provider) as embedding_provider'),
                DB::raw('MAX(dossier_chunks.embedding_model) as embedding_model'),
            ])
            ->orderBy('dossiers.name')
            ->orderBy('blog_posts.title')
            ->get()
            ->map(fn (object $row): array => $this->mapSourceRow($row, 'article'))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fileSources(string $organizationId): array
    {
        return $this->eligibleFileQuery($organizationId)
            ->join('dossiers', function ($join) use ($organizationId) {
                $join->on('dossiers.id', '=', 'dossier_files.dossier_id')
                    ->where('dossiers.organization_id', '=', $organizationId)
                    ->whereNull('dossiers.deleted_at');
            })
            ->leftJoin('dossier_chunks', function ($join) use ($organizationId) {
                $join->on('dossier_chunks.dossier_file_id', '=', 'dossier_files.id')
                    ->on('dossier_chunks.dossier_id', '=', 'dossier_files.dossier_id')
                    ->where('dossier_chunks.organization_id', '=', $organizationId);
            })
            ->groupBy('dossier_files.id', 'dossier_files.display_name', 'dossier_files.original_name', 'dossiers.id', 'dossiers.name')
            ->select([
                'dossier_files.id as source_id',
                'dossier_files.display_name as display_name',
                'dossier_files.original_name as original_name',
                'dossiers.id as dossier_id',
                'dossiers.name as dossier_name',
                DB::raw('COUNT(dossier_chunks.id) as chunk_count'),
                DB::raw('MAX(dossier_chunks.indexed_at) as last_indexed_at'),
                DB::raw('MAX(dossier_chunks.embedding_provider) as embedding_provider'),
                DB::raw('MAX(dossier_chunks.embedding_model) as embedding_model'),
            ])
            ->orderBy('dossiers.name')
            ->orderBy('dossier_files.display_name')
            ->get()
            ->map(fn (object $row): array => $this->mapSourceRow($row, 'file'))
            ->all();
    }

    /**
     * `path`/`disk` ne sortent jamais d'ici : l'emplacement disque d'un
     * fichier n'a rien a faire dans une console.
     *
     * @return array<string, mixed>
     */
    private function mapSourceRow(object $row, string $type): array
    {
        $chunkCount = (int) $row->chunk_count;

        return [
            'type' => $type,
            'id' => (string) $row->source_id,
            'title' => $type === 'article'
                ? (string) $row->title
                : (string) ($row->display_name ?: $row->original_name),
            'slug' => $type === 'article' ? (string) $row->slug : null,
            'dossier_id' => (string) $row->dossier_id,
            'dossier_name' => (string) $row->dossier_name,
            'indexed' => $chunkCount > 0,
            'chunks' => $chunkCount,
            'embedding_provider' => $chunkCount > 0 ? (string) $row->embedding_provider : null,
            'embedding_model' => $chunkCount > 0 ? (string) $row->embedding_model : null,
            'indexed_at' => $chunkCount > 0 ? $row->last_indexed_at : null,
        ];
    }
}
