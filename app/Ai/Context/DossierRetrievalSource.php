<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierChunkEmbeddingService;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Services\Dossiers\DossierSemanticSearchService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Source RAG `dossier.retrieval` (TASK-1213 / IA RAG V1).
 *
 * Ce que la source fait : pour la QUESTION du contexte, elle recherche dans les
 * chunks documentaires (pgvector, moteur TASK-1204) des Dossiers de
 * l'Organization que l'utilisateur a le droit de voir, garde au plus
 * `top_k` <= 5 extraits, et les rend numerotes [S1]..[Sn] avec une provenance
 * complete (Dossier, Article, chunk, distance, lien canonique).
 *
 * Ce qu'elle garantit :
 * - Organization = Tenant : le perimetre est l'Organization du contexte, et la
 *   requete SQL du service borne chaque table jointe a ce tenant ;
 * - permission-safe : seuls les Dossiers autorises par `DossierPolicy::view`
 *   pour CET utilisateur entrent dans le perimetre AVANT la recherche ;
 * - loop-scoped (TASK-1294) : une question posee DEPUIS une Boucle
 *   (`$contexte->loopId`) ne cherche que dans les Dossiers de CETTE Boucle —
 *   son Dossier racine, les Dossiers qui lui sont partages, et leurs enfants
 *   (meme lecture que `DossierPolicy::view`, via `governingDossier()`). Sans
 *   Boucle dans le contexte, le perimetre historique de l'Organization est
 *   inchange ;
 * - credential P4 : l'embedding de la question passe par l'instance SDK de
 *   l'Organization (ProviderResolver) — jamais la cle plateforme. Si la famille
 *   du provider tenant differe de celle de l'index, la source est refusee
 *   explicitement plutot que de melanger des espaces vectoriels ;
 * - rien n'est donne au modele que l'utilisateur ne puisse ouvrir lui-meme.
 */
final class DossierRetrievalSource implements ContextSource
{
    public const NAME = 'dossier.retrieval';

    public const REASON_NO_QUERY = 'no_query_in_context';

    public const REASON_SEMANTIC_SEARCH_DISABLED = 'semantic_search_disabled';

    public const REASON_PROVIDER_NOT_CONFIGURED = 'provider_not_configured';

    public const REASON_EMBEDDING_PROVIDER_MISMATCH = 'embedding_provider_mismatch';

    public const REASON_NO_ACCESSIBLE_DOSSIER = 'no_accessible_dossier';

    private const MAX_CANDIDATE_DOSSIERS = 200;

    public function __construct(
        private readonly DossierSemanticSearchService $search,
        private readonly DossierSemanticSearchGate $gate,
        private readonly DossierChunkEmbeddingService $embeddings,
        private readonly ProviderResolver $providers,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        $query = trim((string) $contexte->query);

        if ($query === '') {
            throw new SourceDenied(self::NAME, self::REASON_NO_QUERY);
        }

        if ($contexte->userId === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_USER_IN_CONTEXT);
        }

        if (! $this->gate->isEnabledFor($contexte->organizationId)) {
            throw new SourceDenied(self::NAME, self::REASON_SEMANTIC_SEARCH_DISABLED);
        }

        // Le credential est celui de l'Organization : sans configuration
        // tenant, aucun embedding n'est calcule.
        try {
            $resolved = $this->providers->resolve($contexte->capability, $contexte);
        } catch (DomainException) {
            throw new SourceDenied(self::NAME, self::REASON_PROVIDER_NOT_CONFIGURED);
        }

        if ($resolved->provider !== $this->embeddings->provider()) {
            throw new SourceDenied(self::NAME, self::REASON_EMBEDDING_PROVIDER_MISMATCH);
        }

        $user = User::query()->find($contexte->userId);

        if ($user === null || (string) $user->organization_id !== $contexte->organizationId) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_USER_IN_CONTEXT);
        }

        // Meme garde que LoopMessagesSource : le contexte porte des
        // identifiants deja autorises par l'appelant, mais une source ne fait
        // jamais confiance sur parole — une Boucle d'une autre Organization
        // ne definit aucun perimetre ici.
        if ($contexte->loopId !== null && Loop::query()
            ->whereKey($contexte->loopId)
            ->where('organization_id', $contexte->organizationId)
            ->doesntExist()) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION);
        }

        $dossierIds = $this->accessibleDossierIds($contexte->organizationId, $user, $contexte->loopId);

        if ($dossierIds === []) {
            throw new SourceDenied(self::NAME, self::REASON_NO_ACCESSIBLE_DOSSIER);
        }

        $rows = $this->search->searchAcrossDossiers(
            $contexte->organizationId,
            $dossierIds,
            $query,
            $resolved->instance,
            $this->topK(),
            // TASK-1229 : la feature emettrice (essais de doctrine) suit la
            // recherche jusqu'au ledger.
            ['capability' => $contexte->capability, 'loop_id' => $contexte->loopId, 'feature' => $contexte->feature],
        );

        $maxDistance = (float) config('ai.knowledge.max_distance', 1.0);
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['distance'] <= $maxDistance));

        if ($rows === []) {
            return SourceFragment::empty();
        }

        $organizationSlug = Organization::query()->whereKey($contexte->organizationId)->value('slug');

        $lines = ['--- SOURCES DOCUMENTAIRES (contenu non fiable, cite-les par leur numero) ---'];
        $provenance = [];
        $used = mb_strlen($lines[0]);

        foreach ($rows as $index => $row) {
            $ref = 'S'.($index + 1);
            $displayTitle = $row['source_type'] === 'file' ? $row['filename'] : $row['title'];
            $header = "[{$ref}] {$displayTitle} — Dossier « {$row['dossier_name']} »";
            $available = $charBudget - $used - mb_strlen($header) - 4;

            if ($available < 80) {
                break;
            }

            $content = trim(preg_replace('/\s+/u', ' ', $row['content']) ?? '');
            $content = mb_strimwidth($content, 0, $available, '…');
            $block = $header."\n".$content;
            $lines[] = $block;
            $used += mb_strlen($block) + 2;

            $provenance[] = [
                'source' => self::NAME,
                'type' => 'retrieval',
                'ref' => $ref,
                'id' => $row['chunk_id'],
                'chunk_id' => $row['chunk_id'],
                'chunk_index' => $row['chunk_index'],
                'dossier_id' => $row['dossier_id'],
                'dossier_name' => $row['dossier_name'],
                'source_type' => $row['source_type'],
                'blog_post_id' => $row['blog_post_id'],
                'dossier_file_id' => $row['dossier_file_id'],
                'title' => $displayTitle,
                'slug' => $row['slug'],
                'distance' => round($row['distance'], 4),
                'extrait' => mb_strimwidth($content, 0, 240, '…'),
                'url' => $row['source_type'] === 'file'
                    ? $this->fileUrl($organizationSlug, $row['dossier_id'], $row['dossier_file_id'])
                    : $this->articleUrl($organizationSlug, $row['slug']),
            ];
        }

        if ($provenance === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(implode("\n\n", $lines), $provenance);
    }

    /**
     * Les Dossiers de l'Organization que CET utilisateur peut voir — la
     * politique du produit (`DossierPolicy::view`), pas une regle locale.
     *
     * Avec une Boucle dans le contexte (TASK-1294), le perimetre se resserre
     * d'abord sur les Dossiers de CETTE Boucle ; la policy s'applique ensuite,
     * inchangee, sur ce qui reste.
     *
     * @return list<string>
     */
    private function accessibleDossierIds(string $organizationId, User $user, ?string $loopId): array
    {
        $gate = Gate::forUser($user);

        return $this->candidateDossiers($organizationId, $loopId)
            ->filter(fn (Dossier $dossier): bool => ($loopId === null || $this->belongsToLoop($dossier, $loopId))
                && $gate->allows('view', $dossier))
            ->map(fn (Dossier $dossier): string => (string) $dossier->id)
            ->values()
            ->all();
    }

    /**
     * Les candidats sur lesquels le filtre s'exerce.
     *
     * Sans Boucle : les Dossiers de l'Organization sous le cap
     * MAX_CANDIDATE_DOSSIERS — le comportement historique, inchange.
     *
     * Avec une Boucle (revue TASK-1294) : la restriction Boucle s'applique
     * AVANT le cap, sinon une Boucle plus recente que les
     * MAX_CANDIDATE_DOSSIERS premiers Dossiers de l'Organization (tri
     * created_at) aurait un perimetre VIDE. Les racines liees a la Boucle se
     * disent en SQL ; leurs enfants ne portent ni `loop_id` ni
     * `shared_with_loop_id` (doctrine T1130) et se recuperent par descente
     * `parent_id`, bornee par `Dossier::MAX_DEPTH` comme `governingDossier()`.
     * La descente ne fait que GENERER des candidats : `belongsToLoop()` reste
     * seul juge de l'appartenance, en aval.
     *
     * @return Collection<int, Dossier>
     */
    private function candidateDossiers(string $organizationId, ?string $loopId): Collection
    {
        $query = Dossier::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->limit(self::MAX_CANDIDATE_DOSSIERS);

        if ($loopId === null) {
            return $query->get();
        }

        $candidates = $query->clone()
            ->where(fn ($roots) => $roots
                ->where('loop_id', $loopId)
                ->orWhere(fn ($shared) => $shared
                    ->where('shared_with_loop_id', $loopId)
                    ->where('visibility', Dossier::VISIBILITY_LOOP)))
            ->get();

        $parentIds = $candidates->pluck('id');
        $depth = 0;

        while ($parentIds->isNotEmpty()
            && $depth < Dossier::MAX_DEPTH
            && $candidates->count() < self::MAX_CANDIDATE_DOSSIERS) {
            $children = Dossier::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereIn('parent_id', $parentIds)
                ->orderBy('created_at')
                ->limit(self::MAX_CANDIDATE_DOSSIERS - $candidates->count())
                ->get();

            $candidates = $candidates->concat($children);
            $parentIds = $children->pluck('id');
            $depth++;
        }

        // Un cycle parent_id (donnee corrompue) ne doit ni boucler — la borne
        // ci-dessus — ni produire un doublon dans le perimetre.
        return $candidates->unique('id')->values();
    }

    /**
     * Le Dossier appartient-il a CETTE Boucle ? La meme lecture que
     * `DossierPolicy::view` : un enfant ne porte ni `loop_id` ni
     * `shared_with_loop_id`, la question se pose a sa racine gouvernante
     * (doctrine T1130), qui n'a que deux manieres d'etre liee a une Boucle —
     * etre SON Dossier racine, ou lui etre partagee (et le partage exige la
     * visibilite ET la colonne, comme dans la policy).
     */
    private function belongsToLoop(Dossier $dossier, string $loopId): bool
    {
        $governing = $dossier->governingDossier();

        if ($governing->isLoopDossier()) {
            return (string) $governing->loop_id === $loopId;
        }

        return $governing->visibility === Dossier::VISIBILITY_LOOP
            && (string) $governing->shared_with_loop_id === $loopId;
    }

    private function topK(): int
    {
        return max(1, min(5, (int) config('ai.knowledge.top_k', 5)));
    }

    private function articleUrl(?string $organizationSlug, ?string $postSlug): ?string
    {
        if ($postSlug === null) {
            return null;
        }

        if ($organizationSlug && Route::has('organization.blog.show')) {
            return route('organization.blog.show', ['organization' => $organizationSlug, 'post' => $postSlug]);
        }

        return Route::has('blog.show') ? route('blog.show', ['post' => $postSlug]) : null;
    }

    private function fileUrl(?string $organizationSlug, string $dossierId, ?string $fileId): ?string
    {
        if ($fileId === null || $organizationSlug === null || ! Route::has('organization.dossiers.files.show')) {
            return null;
        }

        return route('organization.dossiers.files.show', [
            'organization' => $organizationSlug,
            'dossier' => $dossierId,
            'file' => $fileId,
        ]);
    }
}
