<?php

namespace App\Http\Controllers;

use App\Ai\ProviderResolver;
use App\Models\Dossier;
use App\Models\Organization;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiRefusedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;

class DossierSemanticSearchController extends Controller
{
    public function __construct(private readonly ProviderResolver $providers) {}

    public function __invoke(
        Request $request,
        Organization $organization,
        Dossier $dossier,
        DossierSemanticSearchService $search,
    ): JsonResponse {
        abort_unless($dossier->organization_id === $organization->id, 404);

        $this->authorize('view', $dossier);

        $query = $request->query('query');

        if (is_string($query)) {
            $request->merge(['query' => trim($query)]);
        }

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        try {
            // TASK-1225 : la recherche semantique d'un Dossier passe par le
            // credential de l'ORGANIZATION, comme l'ingestion (TASK-1214) et
            // le retrieval (TASK-1213). `null` signifie « pas d'embedding
            // tenant disponible » : refus explicite, JAMAIS un repli vers la
            // cle plateforme.
            $embeddingInstance = $this->providers->resolveEmbeddingInstance((string) $organization->id);

            if ($embeddingInstance === null) {
                Log::warning('Dossier semantic search refused: no tenant embedding credential.', [
                    'organization_id' => $organization->id,
                    'dossier_id' => $dossier->id,
                ]);

                return response()->json(['code' => 'semantic_search_unavailable'], 503);
            }

            $results = $search->search($organization->id, $dossier->id, $validated['query'], 5, $embeddingInstance);
        } catch (AiRefusedException $exception) {
            // TASK-1229 : refus economique AVANT tout appel (credit utilisateur
            // epuise / budget Organization atteint), dit avec son code — jamais
            // un « aucun resultat » ni un « indisponible » generique.
            return response()->json([
                'code' => $exception->refusalCode,
                'message' => $exception->getMessage(),
                'offers_url' => $exception->offersUrl($organization),
            ], 429);
        } catch (AiException|ConnectionException|RequestException|RuntimeException|\DomainException $exception) {
            Log::warning('Dossier semantic search unavailable.', [
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'exception' => $exception::class,
            ]);

            return response()->json(['code' => 'semantic_search_unavailable'], 503);
        }

        return response()->json([
            'data' => array_map(
                fn (array $result): array => $result + [
                    'citation_url' => $this->citationUrl($organization, $dossier, $result),
                ],
                $results,
            ),
        ]);
    }

    /**
     * TASK-1267 : la citation suit la source du chunk. Un resultat `file`
     * (slug/title nuls, cf. DossierSemanticSearchService::mapSourceRow())
     * pointe vers la route fichier existante ; tout le reste garde la route
     * article inchangee — `post = null` levait UrlGenerationException (500).
     *
     * @param  array<string, mixed>  $result
     */
    private function citationUrl(Organization $organization, Dossier $dossier, array $result): string
    {
        if (($result['source_type'] ?? null) === 'file') {
            return route('organization.dossiers.files.show', [
                'organization' => $organization,
                'dossier' => $dossier,
                'file' => $result['dossier_file_id'],
            ]);
        }

        return route('organization.blog.show', [
            'organization' => $organization,
            'post' => $result['slug'],
        ]);
    }
}
