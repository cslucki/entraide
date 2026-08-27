<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierChunkEmbeddingService;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Services\Dossiers\DossierSemanticSearchService;
use DomainException;

/**
 * Source RAG `dossier.retrieval` (TASK-1213 / IA RAG V1).
 *
 * Ce que la source fait : pour la QUESTION du contexte, elle recherche dans les
 * chunks documentaires (pgvector, moteur TASK-1204) des Dossiers de
 * l'Organization que l'utilisateur a le droit de voir, garde au plus
 * `top_k` <= 5 extraits, et les rend numerotes [S1]..[Sn] avec une provenance
 * complete (Dossier, Article, chunk, distance, lien canonique).
 *
 * TASK-1309 : une question PANORAMIQUE (« que contiennent les dossiers ? »)
 * n'a par construction aucun excellent voisin vectoriel — le filtre
 * `max_distance` ecarte alors tout, et cette source rend zero extrait alors
 * que le corpus est riche. Quand — et SEULEMENT quand — la question porte un
 * marqueur de largeur reconnu localement par `DocumentaryQuestionShape`, la
 * selection est reconstruite en VUE D'ENSEMBLE : un extrait court par
 * DOCUMENT, plusieurs documents, complete au besoin par l'ouverture
 * representative des documents absents
 * (`DossierSemanticSearchService::representativeChunksAcrossDossiers()`).
 * Aucun `top_k` gonfle, aucun `max_distance` desactive, aucun appel provider
 * supplementaire, aucun second pipeline — la meme source, le meme perimetre,
 * la meme provenance [Sn].
 *
 * TASK-1309 (revue) : l'absence de resultat n'est PAS un declencheur. Une
 * premiere version elargissait aussi des que la selection semantique etait
 * vide ; une question documentaire PRECISE sans voisin basculait alors en vue
 * d'ensemble, et ce mode se mettait a fabriquer de la pertinence a partir de
 * l'ouverture arbitraire de plusieurs documents. Le contrat du mode Dossiers
 * est le grounding STRICT : sans extrait pertinent, il le dit — il n'invente
 * pas un panorama. La table de verite tient en cinq lignes :
 *
 *   panoramique + zero hit   -> ouvertures representatives multi-document
 *   panoramique + hits       -> semantique + largeur multi-document
 *   inventaire               -> manifest seul, aucun [Sn] injecte
 *   precise    + hits        -> semantique normale, extraits entiers
 *   precise    + zero hit    -> RIEN. Le manifest seul, et un refus honnete.
 *
 * TASK-1307 : les `top_k` extraits cites sont choisis par `diversify()` parmi
 * un bassin de candidats plus large (CANDIDATE_POOL_SIZE, meme cout provider
 * — un seul embedding de requete), au plus PER_DOCUMENT_CAP par document en
 * premiere passe. Une question large qui trouve plusieurs documents
 * pertinents n'est plus ecrasee par les meilleurs chunks d'un seul fichier ;
 * une question precise dont un seul document est pertinent garde jusqu'a
 * `top_k` chunks de ce document (repechage).
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

    /**
     * TASK-1307 : au plus 2 chunks du MEME document dans la selection finale.
     * Une question precise sur un document tres pertinent peut toujours
     * remplir tout `topK()` depuis ce seul document (voir `diversify()` —
     * le repechage relache le plafond quand la diversite ne peut pas, a
     * elle seule, completer la selection) ; une question large qui trouve
     * plusieurs documents pertinents proches n'est plus ecrasee par les 5
     * meilleurs chunks du MEME fichier.
     */
    private const PER_DOCUMENT_CAP = 2;

    /**
     * TASK-1307 : bassin de candidats interroge en base (recherche vectorielle
     * seule, AUCUN appel provider supplementaire — un seul embedding de
     * requete est calcule, comme avant) pour que `diversify()` ait de quoi
     * choisir. Le nombre de sources CITEES reste borne par `topK()` (<=5) :
     * elargir ce bassin ne change jamais combien de sources sont montrees ou
     * facturees au modele, seulement lesquelles sont candidates.
     */
    private const CANDIDATE_POOL_SIZE = 20;

    public function __construct(
        private readonly DossierSemanticSearchService $search,
        private readonly DossierSemanticSearchGate $gate,
        private readonly DossierChunkEmbeddingService $embeddings,
        private readonly ProviderResolver $providers,
        private readonly DossierAccessScope $scope,
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
        if ($contexte->loopId !== null && ! $this->scope->loopBelongsToOrganization($contexte->loopId, $contexte->organizationId)) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION);
        }

        $dossierIds = $this->scope->accessibleDossierIds($contexte->organizationId, $user, $contexte->loopId);

        if ($dossierIds === []) {
            throw new SourceDenied(self::NAME, self::REASON_NO_ACCESSIBLE_DOSSIER);
        }

        $topK = $this->topK();

        // TASK-1307 : le bassin de candidats (CANDIDATE_POOL_SIZE) est plus
        // large que ce qui est finalement cite (topK) — UN SEUL embedding de
        // requete est calcule quel que soit le nombre de candidats, la
        // recherche vectorielle elle-meme n'appelle aucun provider
        // supplementaire. `diversify()` choisit ensuite, parmi ces candidats
        // deja tries par distance, au plus `topK` chunks a citer.
        $rows = $this->search->searchAcrossDossiers(
            $contexte->organizationId,
            $dossierIds,
            $query,
            $resolved->instance,
            $topK,
            // TASK-1229 : la feature emettrice (essais de doctrine) suit la
            // recherche jusqu'au ledger.
            ['capability' => $contexte->capability, 'loop_id' => $contexte->loopId, 'feature' => $contexte->feature],
            max($topK, self::CANDIDATE_POOL_SIZE),
        );

        $maxDistance = (float) config('ai.knowledge.max_distance', 1.0);
        $rows = array_values(array_filter($rows, fn (array $row): bool => $row['distance'] <= $maxDistance));
        $rows = $this->diversify($rows, $topK);

        // TASK-1309 (revue) : le complement panoramique depend de la FORME DE
        // LA QUESTION, et d'elle seule.
        //
        // Une premiere version ajoutait un second declencheur — « la
        // selection semantique est vide » — pense comme un filet structurel.
        // C'etait une faute de contrat : une question sans voisin vectoriel
        // n'est pas panoramique pour autant, elle est simplement sans
        // reponse. Une question documentaire PRECISE dont aucun chunk ne
        // passe `max_distance` basculait alors en vue d'ensemble
        // multi-document, et le mode Dossiers fabriquait de la pertinence a
        // partir de l'ouverture arbitraire de plusieurs documents — au lieu
        // de dire qu'il ne peut rien etayer. Le grounding STRICT, qui est
        // toute la valeur de ce mode, s'en trouvait affaibli.
        //
        // Zero hit n'autorise donc rien. En cas de doute sur une question, on
        // n'elargit pas.
        $overview = DocumentaryQuestionShape::wantsCorpusOverview($query);

        if ($overview) {
            $rows = $this->overviewSelection($rows, $contexte, $dossierIds);
        }

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

            // TASK-1309 : en vue d'ensemble, la LARGEUR prime sur la
            // profondeur. Sans ce plafond par document, les deux premiers
            // extraits (~2900 caracteres chacun sur le corpus reel)
            // consommeraient tout `max_context_chars` et les documents
            // suivants seraient coupes a rien — une « vue d'ensemble » de
            // deux documents. Le plafond n'a AUCUN effet hors vue d'ensemble :
            // une question precise garde ses extraits entiers.
            if ($overview) {
                $available = min($available, $this->overviewCharsPerDocument());
            }

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
                'distance' => $row['distance'] === null ? null : round($row['distance'], 4),
                // TASK-1309 : COMMENT cet extrait a ete choisi — par
                // proximite semantique, ou comme ouverture representative de
                // son document (vue d'ensemble). Trace de diagnostic
                // uniquement : `KnowledgeAnswer::publicSource()` ne l'expose
                // jamais, ni au JSON, ni a la metadata du message.
                'selection' => $row['distance'] === null ? 'overview' : 'semantic',
                'extrait' => mb_strimwidth($content, 0, 240, '…'),
                'url' => $row['source_type'] === 'file'
                    ? DossierSourceUrl::forFile($organizationSlug, $row['dossier_id'], $row['dossier_file_id'], $row['mime_type'] ?? null)
                    : DossierSourceUrl::forArticle($organizationSlug, $row['slug']),
            ];
        }

        if ($provenance === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(implode("\n\n", $lines), $provenance);
    }

    /**
     * TASK-1307 : choisit, parmi des candidats DEJA tries par distance
     * croissante, au plus `$limit` chunks a citer — au plus `PER_DOCUMENT_CAP`
     * par document en premiere passe (diversite), puis un repechage dans
     * l'ordre de distance pour completer jusqu'a `$limit` si la diversite
     * seule n'y suffit pas (une question precise dont le seul document
     * pertinent porte tous les bons chunks garde son comportement actuel :
     * jusqu'a `$limit` chunks de CE document).
     *
     * @param  list<array{chunk_id: string, dossier_id: string, dossier_name: string, source_type: string, blog_post_id: ?string, dossier_file_id: ?string, distance: float}>  $rows
     * @return list<array{chunk_id: string, dossier_id: string, dossier_name: string, source_type: string, blog_post_id: ?string, dossier_file_id: ?string, distance: float}>
     */
    private function diversify(array $rows, int $limit): array
    {
        $selected = [];
        $countByDocument = [];
        $leftover = [];

        foreach ($rows as $row) {
            if (count($selected) >= $limit) {
                break;
            }

            $documentKey = $row['source_type'].':'.($row['dossier_file_id'] ?? $row['blog_post_id']);

            if (($countByDocument[$documentKey] ?? 0) < self::PER_DOCUMENT_CAP) {
                $selected[] = $row;
                $countByDocument[$documentKey] = ($countByDocument[$documentKey] ?? 0) + 1;
            } else {
                $leftover[] = $row;
            }
        }

        foreach ($leftover as $row) {
            if (count($selected) >= $limit) {
                break;
            }

            $selected[] = $row;
        }

        return $selected;
    }

    /**
     * TASK-1309 : UNE entree par DOCUMENT, plusieurs documents.
     *
     * Reconstruit la selection en vue d'ensemble :
     * 1. le MEILLEUR extrait semantique de chaque document deja represente
     *    (l'ordre par distance est conserve : le document le plus pertinent
     *    reste [S1]) ;
     * 2. puis, pour les documents accessibles encore absents, leur extrait
     *    REPRESENTATIF (premier chunk), dans l'ordre deterministe rendu par
     *    `representativeChunksAcrossDossiers()`.
     *
     * Le tout borne a `ai.knowledge.overview.max_documents` DOCUMENTS — pas a
     * un `top_k` gonfle : ce qui grandit est le nombre de documents
     * representes, chacun par un extrait court, jamais le nombre d'extraits
     * d'un meme document. AUCUN appel provider supplementaire : un seul
     * embedding de requete a ete calcule plus haut, le complement est une
     * lecture SQL.
     *
     * @param  list<array<string, mixed>>  $selected
     * @param  list<string>  $dossierIds
     * @return list<array<string, mixed>>
     */
    private function overviewSelection(array $selected, ContexteIa $contexte, array $dossierIds): array
    {
        $maxDocuments = $this->overviewMaxDocuments();

        $byDocument = [];

        foreach ($selected as $row) {
            $key = self::documentKey($row);

            // Les lignes arrivent triees par distance : la premiere vue d'un
            // document EST son meilleur extrait.
            if (! isset($byDocument[$key]) && count($byDocument) < $maxDocuments) {
                $byDocument[$key] = $row;
            }
        }

        if (count($byDocument) >= $maxDocuments) {
            return array_values($byDocument);
        }

        $representative = $this->search->representativeChunksAcrossDossiers(
            $contexte->organizationId,
            $dossierIds,
            $maxDocuments,
        );

        foreach ($representative as $row) {
            if (count($byDocument) >= $maxDocuments) {
                break;
            }

            $key = self::documentKey($row);

            if (! isset($byDocument[$key])) {
                $byDocument[$key] = $row;
            }
        }

        return array_values($byDocument);
    }

    /**
     * Identite d'un DOCUMENT (Article ou fichier), jamais d'un chunk — la
     * meme cle que `diversify()`, pour que « deja represente » veuille dire
     * la meme chose des deux cotes.
     *
     * @param  array<string, mixed>  $row
     */
    private static function documentKey(array $row): string
    {
        return $row['source_type'].':'.($row['dossier_file_id'] ?? $row['blog_post_id']);
    }

    private function overviewMaxDocuments(): int
    {
        return max(1, min(12, (int) config('ai.knowledge.overview.max_documents', 6)));
    }

    private function overviewCharsPerDocument(): int
    {
        return max(120, (int) config('ai.knowledge.overview.chars_per_document', 700));
    }

    private function topK(): int
    {
        return max(1, min(5, (int) config('ai.knowledge.top_k', 5)));
    }
}
