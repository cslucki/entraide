<?php

namespace App\Http\Controllers;

use App\Models\Dossier;
use App\Models\Organization;
use App\Services\Dossiers\DossierInsightsService;
use App\Support\Ai\AiRefusedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;

/**
 * TASK-1341 — Smart Dossier V1. Une seule route, POST, gatee et jetee : rien
 * n'est relu au chargement de la page (contrat §9/§12 du mandat).
 */
class DossierInsightsController extends Controller
{
    public function __invoke(
        Request $request,
        Organization $organization,
        Dossier $dossier,
        DossierInsightsService $insights,
    ): JsonResponse {
        abort_unless($dossier->organization_id === $organization->id, 404);

        $this->authorize('view', $dossier);

        // Aucun appel provider sur un corpus vide — verifie AVANT toute
        // depense, la meme regle d'eligibilite que la generation elle-meme.
        if (! $insights->hasIndexedContent($organization, $dossier)) {
            return response()->json(['code' => 'dossier_insights_no_content'], 422);
        }

        try {
            $answer = $insights->generate($organization, $dossier, $request->user());
        } catch (AiRefusedException $exception) {
            // TASK-1229 : credential tenant absent (mandat §9) reste une
            // indisponibilite — 503, jamais confondue avec un refus
            // economique (credit/budget), qui seul propose des offres.
            if ($exception->refusalCode === AiRefusedException::CODE_NOT_CONFIGURED) {
                Log::warning('Dossier insights refused: no tenant AI credential.', [
                    'organization_id' => $organization->id,
                    'dossier_id' => $dossier->id,
                ]);

                return response()->json(['code' => 'dossier_insights_unavailable'], 503);
            }

            return response()->json([
                'code' => $exception->refusalCode,
                'message' => $exception->getMessage(),
                'offers_url' => $exception->offersUrl($organization),
            ], 429);
        } catch (AiException|ConnectionException|RequestException|RuntimeException|\DomainException $exception) {
            Log::warning('Dossier insights unavailable.', [
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'exception' => $exception::class,
            ]);

            return response()->json(['code' => 'dossier_insights_unavailable'], 503);
        }

        return response()->json([
            'html' => view('dossiers.partials.insights-result', ['answer' => $answer])->render(),
        ]);
    }
}
