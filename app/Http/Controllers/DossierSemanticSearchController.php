<?php

namespace App\Http\Controllers;

use App\Models\Dossier;
use App\Models\Organization;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DossierSemanticSearchController extends Controller
{
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
            $results = $search->search($organization->id, $dossier->id, $validated['query'], 5);
        } catch (RuntimeException $exception) {
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
                    'citation_url' => route('organization.blog.show', [
                        'organization' => $organization,
                        'post' => $result['slug'],
                    ]),
                ],
                $results,
            ),
        ]);
    }
}
