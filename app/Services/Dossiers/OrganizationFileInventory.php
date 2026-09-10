<?php

namespace App\Services\Dossiers;

use App\Models\DossierFile;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1513 — l'inventaire des fichiers d'une Organization.
 *
 * ## Pourquoi ce n'est pas `OrganizationRagOverview::sources()`
 *
 * Ce read model repond a une autre question. `sources()` dit « qu'est-ce que
 * l'IA connait », et ne montre donc que les sources INGERABLES : un `.zip` ou
 * un `.png` n'y figure jamais. Or c'est precisement ce qu'un admin doit voir
 * ici — savoir qu'un document ne sera jamais indexe est une reponse, pas un
 * silence. On part donc de TOUS les fichiers vivants, et l'etat se derive.
 *
 * `sources()` charge par ailleurs son corpus entier en memoire : le reutiliser
 * derriere une pagination ferait payer tout le corpus a chaque page. Ce qu'on
 * reprend de lui, c'est sa FORME de requete — la jointure agregee sur
 * `dossier_chunks` — pas son point d'entree.
 *
 * ## Trois etats, tous prouves localement
 *
 * - `indexed`        : au moins un chunk. Fait.
 * - `not_ingestible` : le format n'est pas ingerable (`FileContentExtractor`).
 *                      Fait sur le FICHIER, jamais une hypothese sur la queue.
 * - `not_indexed`    : ni l'un ni l'autre. On dit ce qu'on constate, on
 *                      n'invente pas pourquoi.
 *
 * Le troisieme ne s'appelle deliberement PAS « en attente ». La table `jobs`
 * ne porte ni `organization_id` ni identifiant de source, et un job consomme
 * ne laisse aucune ligne : deduire l'attente de « 0 chunk » remplacerait un
 * faux vert par un faux orange (arbitrage MASTER, TASK-1512).
 *
 * ## Tenant : explicite, parce que rien ne rattrape un oubli
 *
 * `DossierFile` n'a PAS `BelongsToOrganizationScope` — seulement
 * `HasOrganizationId`, qui ECRIT `organization_id` a la creation sans jamais
 * FILTRER en lecture. Il n'existe donc aucun garde-fou fail-closed sur ce
 * modele : une requete sans `where('organization_id')` lit toute la
 * plateforme, silencieusement. Chaque requete d'ici est bornee a la main, et
 * `forPlatform()` est le SEUL endroit ou l'absence de borne est voulue.
 */
final class OrganizationFileInventory
{
    public const STATE_INDEXED = 'indexed';

    public const STATE_NOT_INDEXED = 'not_indexed';

    public const STATE_NOT_INGESTIBLE = 'not_ingestible';

    /** @var list<string> */
    public const STATES = [self::STATE_INDEXED, self::STATE_NOT_INDEXED, self::STATE_NOT_INGESTIBLE];

    /**
     * Les tris acceptes. Une whitelist, jamais l'entree brute dans un
     * `orderBy` : le nom de colonne d'une requete ne se prend pas dans l'URL.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'name' => 'dossier_files.display_name',
        'dossier' => 'dossiers.name',
        'chunks' => 'chunk_count',
        'size' => 'dossier_files.size_bytes',
        'created_at' => 'dossier_files.created_at',
        'indexed_at' => 'last_indexed_at',
    ];

    public function __construct(private readonly FileContentExtractor $extractor = new FileContentExtractor) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forOrganization(Organization $organization, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->paginate($this->query((string) $organization->getKey()), $filters, $perPage);
    }

    /**
     * Toutes les Organizations, ou une seule.
     *
     * C'est le SEUL point du produit ou l'absence de `where('organization_id')`
     * est intentionnelle. Il n'est atteignable que sous le middleware `admin`
     * (SuperAdmin), et il ramene le nom de l'Organization de chaque ligne —
     * sans quoi une liste inter-tenants ne voudrait rien dire.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forPlatform(?Organization $only = null, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->paginate($this->query($only !== null ? (string) $only->getKey() : null), $filters, $perPage);
    }

    /** Les valeurs de filtre reellement presentes, pour n'en proposer aucune qui ne rendrait rien. */
    public function filterOptions(?Organization $organization = null): array
    {
        $base = fn () => DossierFile::query()
            ->whereNull('dossier_files.deleted_at')
            ->when($organization !== null, fn (Builder $q) => $q->where('dossier_files.organization_id', (string) $organization->getKey()));

        return [
            'dossiers' => $base()
                ->join('dossiers', 'dossiers.id', '=', 'dossier_files.dossier_id')
                ->whereNull('dossiers.deleted_at')
                ->select('dossiers.id', 'dossiers.name')
                ->distinct()
                ->orderBy('dossiers.name')
                ->get()
                ->map(fn ($row) => ['id' => (string) $row->id, 'name' => (string) $row->name])
                ->all(),
            'states' => self::STATES,
        ];
    }

    /**
     * La requete de base. `$organizationId` a `null` = plateforme entiere,
     * atteignable seulement par `forPlatform()`.
     */
    private function query(?string $organizationId): Builder
    {
        return DossierFile::query()
            ->when($organizationId !== null, fn (Builder $q) => $q->where('dossier_files.organization_id', $organizationId))
            ->whereNull('dossier_files.deleted_at')
            // Un fichier sans Dossier vivant n'est plus supervisable ici : il
            // n'appartient a aucun espace, et l'indexeur l'ignore deja.
            ->join('dossiers', function (JoinClause $join) {
                $join->on('dossiers.id', '=', 'dossier_files.dossier_id')
                    ->on('dossiers.organization_id', '=', 'dossier_files.organization_id')
                    ->whereNull('dossiers.deleted_at');
            })
            ->join('organizations', 'organizations.id', '=', 'dossier_files.organization_id')
            // `organization_id` DANS la condition de jointure, jamais dans un
            // `where` externe : sans lui, le chunk d'un autre tenant portant le
            // meme `dossier_file_id` viendrait gonfler le compteur.
            ->leftJoin('dossier_chunks', function (JoinClause $join) {
                $join->on('dossier_chunks.dossier_file_id', '=', 'dossier_files.id')
                    ->on('dossier_chunks.organization_id', '=', 'dossier_files.organization_id')
                    ->on('dossier_chunks.dossier_id', '=', 'dossier_files.dossier_id');
            })
            ->groupBy('dossier_files.id', 'dossiers.id', 'dossiers.name', 'organizations.id', 'organizations.name', 'organizations.slug')
            ->select([
                'dossier_files.id',
                'dossier_files.display_name',
                'dossier_files.original_name',
                'dossier_files.mime_type',
                'dossier_files.size_bytes',
                'dossier_files.created_at',
                'dossier_files.dossier_id',
                'dossier_files.organization_id',
                'dossiers.name as dossier_name',
                'organizations.name as organization_name',
                'organizations.slug as organization_slug',
            ])
            // `path` et `disk` ne sortent PAS de ce read model : l'emplacement
            // disque d'un fichier n'a rien a faire dans une console.
            ->selectRaw('COUNT(dossier_chunks.id) as chunk_count')
            ->selectRaw('MAX(dossier_chunks.indexed_at) as last_indexed_at');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(Builder $query, array $filters, int $perPage): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw('lower(dossier_files.display_name) like ?', [$like])
                    ->orWhereRaw('lower(dossier_files.original_name) like ?', [$like]);
            });
        }

        if (! empty($filters['dossier'])) {
            $query->where('dossier_files.dossier_id', (string) $filters['dossier']);
        }

        // L'etat se filtre en SQL, jamais apres la pagination : filtrer la page
        // courante rendrait des pages incompletes et des compteurs faux.
        // Les trois etats sont exprimables — c'est ce qui rend ce filtre juste.
        $state = (string) ($filters['state'] ?? '');

        if ($state === self::STATE_INDEXED) {
            $query->havingRaw('COUNT(dossier_chunks.id) > 0');
        } elseif ($state === self::STATE_NOT_INDEXED) {
            $query->havingRaw('COUNT(dossier_chunks.id) = 0');
            $this->whereIngestible($query, true);
        } elseif ($state === self::STATE_NOT_INGESTIBLE) {
            $this->whereIngestible($query, false);
        }

        $sortKey = (string) ($filters['sort'] ?? 'created_at');
        $column = self::SORTS[$sortKey] ?? self::SORTS['created_at'];
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Le compteur de chunks est un agregat : il se trie par la meme
        // expression, pas par un alias que tous les moteurs n'acceptent pas
        // dans un ORDER BY.
        $query->orderBy($column === 'chunk_count' ? DB::raw('COUNT(dossier_chunks.id)') : $column, $direction)
            ->orderBy('dossier_files.id');

        /** @var LengthAwarePaginator<int, object> $page */
        $page = $query->paginate($perPage)->withQueryString();

        return $page->through(fn (object $row) => $this->present($row));
    }

    /**
     * Le predicat d'ingerabilite, en SQL — la meme regle que
     * `FileContentExtractor::isSupported()` : le MIME d'abord, l'EXTENSION
     * ensuite (un `.docx` arrive parfois en `application/zip`, un `.md` en
     * `text/plain`).
     *
     * `lower(original_name)` : `LIKE` est sensible a la casse en PostgreSQL —
     * sans lui, un `Rapport.DOCX` serait declare non ingerable alors que le
     * pipeline l'accepte. En SQLite le defaut ne se verrait pas, `LIKE` y
     * etant insensible a la casse.
     */
    private function whereIngestible(Builder $query, bool $ingestible): void
    {
        $matcher = function (Builder $q): void {
            $q->whereIn('dossier_files.mime_type', FileContentExtractor::SUPPORTED_MIME_TYPES);

            foreach (FileContentExtractor::SUPPORTED_EXTENSIONS as $extension) {
                $q->orWhereRaw('lower(dossier_files.original_name) like ?', ['%.'.$extension]);
            }
        };

        $ingestible ? $query->where($matcher) : $query->whereNot($matcher);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $chunks = (int) ($row->chunk_count ?? 0);
        $name = (string) ($row->display_name ?: $row->original_name);

        return [
            'id' => (string) $row->id,
            'name' => $name,
            'original_name' => (string) $row->original_name,
            'dossier_id' => (string) $row->dossier_id,
            'dossier_name' => (string) $row->dossier_name,
            'organization_id' => (string) $row->organization_id,
            'organization_name' => (string) ($row->organization_name ?? ''),
            'organization_slug' => (string) ($row->organization_slug ?? ''),
            'mime_type' => (string) $row->mime_type,
            'size_bytes' => (int) $row->size_bytes,
            'created_at' => $row->created_at,
            'chunks' => $chunks,
            'last_indexed_at' => $row->last_indexed_at ?? null,
            'state' => $this->stateOf((string) $row->mime_type, (string) $row->original_name, $chunks),
        ];
    }

    /**
     * L'etat d'un fichier, en trois valeurs et sans la moindre inference sur
     * la queue. `not_ingestible` est un fait sur le FORMAT, lu par la meme
     * regle que le pipeline d'indexation.
     */
    public function stateOf(string $mimeType, string $originalName, int $chunks): string
    {
        if ($chunks > 0) {
            return self::STATE_INDEXED;
        }

        return $this->extractor->isSupported($mimeType, $originalName)
            ? self::STATE_NOT_INDEXED
            : self::STATE_NOT_INGESTIBLE;
    }
}
