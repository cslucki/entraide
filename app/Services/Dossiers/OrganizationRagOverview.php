<?php

namespace App\Services\Dossiers;

use App\Ai\ProviderResolver;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Support\Ai\AiEconomicGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 *
 * TASK-1226 (Observatoire vivant) — ce read model porte en plus :
 * - le PERIMETRE de chaque source, derive de la racine gouvernante de son
 *   Dossier (`loop_id`, `visibility`, `shared_with_loop_id`), jamais du nom ;
 * - les agregats par perimetre (Organization / Boucles / prive) ;
 * - le volume du corpus (tokens, caracteres) deja stocke dans les chunks ;
 * - l'etat AU PRESENT de l'infrastructure d'indexation, au niveau
 *   Organization (activation, credential, budget) via les autorites
 *   existantes — jamais impute a une source ;
 * - le moment ou une source est apparue dans son Dossier.
 * Il reste strictement read-only : il ne lit aucun contenu de fichier, ne
 * re-chunke rien, n'appelle aucun provider. Aucun etat « obsolete » n'est
 * derive : `content_hash` est une empreinte PAR CHUNK du texte extrait, sans
 * equivalent memorise cote source — le prouver exigerait de relire et
 * re-chunker la source, ce qui n'a pas sa place dans un poll.
 */
class OrganizationRagOverview
{
    /** Le Dossier gouvernant est visible de toute l'Organization. */
    public const SCOPE_ORGANIZATION = 'organization';

    /** Le Dossier gouvernant est celui d'une Boucle (`loop_id`). */
    public const SCOPE_LOOP = 'loop';

    /** Dossier personnel partage avec une Boucle (`shared_with_loop_id`). */
    public const SCOPE_LOOP_SHARED = 'loop_shared';

    /** Proprietaire + membres explicites (`dossier_members`), et rien d'autre. */
    public const SCOPE_PRIVATE = 'private';

    public function __construct(
        private readonly DossierSemanticSearchGate $gate,
        private readonly ProviderResolver $providers,
        private readonly AiEconomicGuard $economicGuard,
    ) {}

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
     * TASK-1226 : `corpus_tokens` / `corpus_characters` mesurent ce que
     * l'index CONTIENT (somme de `token_count`, longueur des `content`
     * stockes) — un vecteur par chunk, donc `chunks` est aussi le nombre de
     * vecteurs. Aucun contenu source n'est relu pour cela.
     *
     * @return array{dossiers: int, articles: int, files: int, chunks: int, indexed_sources: int, last_indexed_at: ?string, corpus_tokens: int, corpus_characters: int}
     */
    public function summary(string $organizationId): array
    {
        $chunks = DB::table('dossier_chunks')->where('organization_id', $organizationId);

        $corpus = (clone $chunks)
            ->selectRaw('COUNT(*) as chunk_count, MAX(indexed_at) as last_indexed_at, COALESCE(SUM(token_count), 0) as tokens, COALESCE(SUM(LENGTH(content)), 0) as characters')
            ->first();

        return [
            'dossiers' => $this->dossierCount($organizationId),
            'articles' => $this->eligibleArticleQuery($organizationId)->count(),
            'files' => $this->eligibleFileQuery($organizationId)->count(),
            'chunks' => (int) ($corpus->chunk_count ?? 0),
            'indexed_sources' => $this->indexedSourceCount($organizationId),
            'last_indexed_at' => $corpus->last_indexed_at ?? null,
            'corpus_tokens' => (int) ($corpus->tokens ?? 0),
            'corpus_characters' => (int) ($corpus->characters ?? 0),
        ];
    }

    /**
     * Une ligne par source pouvant alimenter le RAG (Article ou fichier),
     * indexee ou non. L'absence de chunk est un fait, pas une anomalie.
     *
     * TASK-1226 : chaque ligne porte aussi son `format` (article / txt /
     * markdown, depuis MIME + extension — la regle de FileContentExtractor),
     * `created_at` (apparition dans CE Dossier : upload du fichier,
     * attachement de l'Article) et son `scope` (perimetre reel, voir
     * `dossierScopes()`).
     *
     * @return list<array{type: string, id: string, title: string, format: string, dossier_id: string, dossier_name: string, indexed: bool, chunks: int, embedding_provider: ?string, embedding_model: ?string, indexed_at: ?string, created_at: ?string, slug: ?string, scope: array{kind: string, loop_id: ?string, loop_name: ?string}}>
     */
    public function sources(string $organizationId): array
    {
        $scopes = $this->dossierScopes($organizationId);
        $unknown = ['kind' => self::SCOPE_PRIVATE, 'loop_id' => null, 'loop_name' => null];

        return array_map(function (array $source) use ($scopes, $unknown): array {
            $source['scope'] = $scopes[$source['dossier_id']] ?? $unknown;

            return $source;
        }, array_merge(
            $this->articleSources($organizationId),
            $this->fileSources($organizationId),
        ));
    }

    /**
     * Cartes de perimetre : combien de sources et d'extraits vivent dans
     * chaque espace. Calcule depuis les lignes de `sources()` — aucune
     * requete supplementaire — donc toujours coherent avec le tableau.
     *
     * Une source d'un Dossier personnel partage avec une Boucle est comptee
     * dans la carte de CETTE Boucle (`shared_sources`) : c'est la que ses
     * membres la rencontrent. Elle n'est pas comptee deux fois.
     *
     * `external` : aucun connecteur n'existe aujourd'hui (`dossier_files.source`
     * ne connait que l'upload). La carte le dit, sans compteur invente.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return array{organization: array{sources: int, chunks: int}, loops: list<array{loop_id: string, name: string, sources: int, chunks: int, owned_sources: int, shared_sources: int}>, private: array{sources: int, chunks: int}, external: array{connected: bool, sources: int}}
     */
    public function perimeters(array $sources): array
    {
        $organization = ['sources' => 0, 'chunks' => 0];
        $private = ['sources' => 0, 'chunks' => 0];
        $loops = [];

        foreach ($sources as $source) {
            $kind = $source['scope']['kind'] ?? self::SCOPE_PRIVATE;
            $chunks = (int) ($source['chunks'] ?? 0);

            if ($kind === self::SCOPE_ORGANIZATION) {
                $organization['sources']++;
                $organization['chunks'] += $chunks;

                continue;
            }

            if ($kind === self::SCOPE_LOOP || $kind === self::SCOPE_LOOP_SHARED) {
                $loopId = (string) $source['scope']['loop_id'];
                $loops[$loopId] ??= [
                    'loop_id' => $loopId,
                    'name' => (string) $source['scope']['loop_name'],
                    'sources' => 0,
                    'chunks' => 0,
                    'owned_sources' => 0,
                    'shared_sources' => 0,
                ];
                $loops[$loopId]['sources']++;
                $loops[$loopId]['chunks'] += $chunks;
                $loops[$loopId][$kind === self::SCOPE_LOOP ? 'owned_sources' : 'shared_sources']++;

                continue;
            }

            $private['sources']++;
            $private['chunks'] += $chunks;
        }

        usort($loops, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'organization' => $organization,
            'loops' => array_values($loops),
            'private' => $private,
            'external' => ['connected' => false, 'sources' => 0],
        ];
    }

    /**
     * L'etat AU PRESENT de l'infrastructure d'indexation de l'Organization,
     * lu aupres des autorites existantes — jamais deduit du ledger, jamais des
     * logs, jamais impute a une source en particulier :
     * - `DossierSemanticSearchGate` : la recherche semantique est-elle
     *   activee pour ce tenant ;
     * - `ProviderResolver::resolveEmbeddingInstance` : un credential
     *   Organization capable de signer l'index existe-t-il (null = non ;
     *   doctrine TASK-1214/1225, aucun repli plateforme) ;
     * - `AiEconomicGuard::authorizeEmbeddings` : le budget courant
     *   permet-il de nouvelles indexations.
     * Trois lectures locales (config, organization_ai_settings, sommes du
     * ledger) : aucun appel provider.
     *
     * @return array{semantic_search_enabled: bool, embedding_credential_available: bool, budget_allows_indexing: bool, budget_reason: ?string, available: bool}
     */
    public function indexingAvailability(Organization $organization): array
    {
        $organizationId = (string) $organization->id;
        $enabled = $this->gate->isEnabledFor($organizationId);

        try {
            $credential = $this->providers->resolveEmbeddingInstance($organizationId) !== null;
        } catch (DomainException) {
            // Defaut de configuration PLATEFORME (famille d'embedding sans
            // provider) : aucun embedding n'est possible pour ce tenant non
            // plus, sans que ce soit sa faute — on ne l'affirme pas plus.
            $credential = false;
        }

        $verdict = $this->economicGuard->authorizeEmbeddings($organization);

        return [
            'semantic_search_enabled' => $enabled,
            'embedding_credential_available' => $credential,
            'budget_allows_indexing' => $verdict->allowed,
            'budget_reason' => $verdict->allowed ? null : $verdict->reason,
            'available' => $enabled && $credential && $verdict->allowed,
        ];
    }

    /**
     * Le perimetre de chaque Dossier vivant de l'Organization, indexe par id.
     *
     * Regle (TASK-1226, audit A), evaluee sur la RACINE GOUVERNANTE — un
     * enfant ne porte ni `owner_id` ni `loop_id` (contrainte
     * `dossiers_holder_xor`), il herite :
     * - `loop_id` present               -> SCOPE_LOOP (Dossier de la Boucle) ;
     * - `visibility = organization`      -> SCOPE_ORGANIZATION ;
     * - `visibility = loop` + `shared_with_loop_id` designant une Boucle de
     *   la MEME Organization           -> SCOPE_LOOP_SHARED ;
     * - tout le reste (`private`, legacy `shared`, « Mes documents »,
     *   visibilite `loop` sans Boucle valide) -> SCOPE_PRIVATE : personne
     *   d'autre que le proprietaire et ses invites ne voit ce Dossier, c'est
     *   exactement ce que `DossierPolicy::view` repond.
     *
     * Deux requetes en tout (dossiers, boucles referencees) ; la remontee
     * vers la racine se fait en memoire, bornee par `Dossier::MAX_DEPTH`.
     *
     * @return array<string, array{kind: string, loop_id: ?string, loop_name: ?string}>
     */
    private function dossierScopes(string $organizationId): array
    {
        $rows = DB::table('dossiers')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->get(['id', 'parent_id', 'loop_id', 'owner_id', 'visibility', 'shared_with_loop_id'])
            ->keyBy('id');

        $loopIds = $rows
            ->flatMap(fn (object $row): array => [$row->loop_id, $row->shared_with_loop_id])
            ->filter()
            ->unique()
            ->values();

        $loopNames = $loopIds->isEmpty()
            ? collect()
            : Loop::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $loopIds->all())
                ->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name]);

        $scopes = [];

        foreach ($rows as $id => $row) {
            $root = $row;
            $depth = 0;

            while ($root->parent_id !== null && $depth < Dossier::MAX_DEPTH) {
                $parent = $rows->get((string) $root->parent_id);

                if ($parent === null) {
                    break;
                }

                $root = $parent;
                $depth++;
            }

            $scopes[(string) $id] = $this->scopeOfRoot($root, $loopNames);
        }

        return $scopes;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, string>  $loopNames  Boucles de CETTE Organization uniquement
     * @return array{kind: string, loop_id: ?string, loop_name: ?string}
     */
    private function scopeOfRoot(object $root, \Illuminate\Support\Collection $loopNames): array
    {
        $loopId = $root->loop_id !== null ? (string) $root->loop_id : null;

        if ($loopId !== null && $loopNames->has($loopId)) {
            return ['kind' => self::SCOPE_LOOP, 'loop_id' => $loopId, 'loop_name' => $loopNames->get($loopId)];
        }

        if ($root->visibility === Dossier::VISIBILITY_ORGANIZATION) {
            return ['kind' => self::SCOPE_ORGANIZATION, 'loop_id' => null, 'loop_name' => null];
        }

        $sharedId = $root->shared_with_loop_id !== null ? (string) $root->shared_with_loop_id : null;

        if ($root->visibility === Dossier::VISIBILITY_LOOP && $sharedId !== null && $loopNames->has($sharedId)) {
            return ['kind' => self::SCOPE_LOOP_SHARED, 'loop_id' => $sharedId, 'loop_name' => $loopNames->get($sharedId)];
        }

        return ['kind' => self::SCOPE_PRIVATE, 'loop_id' => null, 'loop_name' => null];
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
                DB::raw('MIN(dossier_blog_posts.created_at) as source_created_at'),
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
            ->groupBy('dossier_files.id', 'dossier_files.display_name', 'dossier_files.original_name', 'dossier_files.mime_type', 'dossier_files.created_at', 'dossiers.id', 'dossiers.name')
            ->select([
                'dossier_files.id as source_id',
                'dossier_files.display_name as display_name',
                'dossier_files.original_name as original_name',
                'dossier_files.mime_type as mime_type',
                'dossier_files.created_at as source_created_at',
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
            'format' => $type === 'article'
                ? 'article'
                : $this->fileFormat((string) $row->mime_type, (string) $row->original_name),
            'slug' => $type === 'article' ? (string) $row->slug : null,
            'dossier_id' => (string) $row->dossier_id,
            'dossier_name' => (string) $row->dossier_name,
            'indexed' => $chunkCount > 0,
            'chunks' => $chunkCount,
            'embedding_provider' => $chunkCount > 0 ? (string) $row->embedding_provider : null,
            'embedding_model' => $chunkCount > 0 ? (string) $row->embedding_model : null,
            'indexed_at' => $chunkCount > 0 ? $row->last_indexed_at : null,
            'created_at' => $row->source_created_at ?? null,
        ];
    }

    /**
     * Le format REEL d'un fichier, par la meme regle que FileContentExtractor
     * (`isMarkdown`) : MIME d'abord, extension ensuite. Une donnee, pas une
     * heuristique de titre.
     */
    private function fileFormat(string $mimeType, string $originalName): string
    {
        if ($mimeType === 'text/markdown') {
            return 'markdown';
        }

        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));

        return in_array($extension, ['md', 'markdown'], true) ? 'markdown' : 'txt';
    }
}
