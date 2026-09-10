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
 * TASK-1516 — « Interroger ce Dossier ».
 *
 * Porte unique de la reponse documentaire d'un Dossier. Elle ne contient
 * aucune logique de RAG : tout vit dans `DossierInsightsService::answer()`,
 * methode soeur de `generate()`. Ce controleur borne, valide et traduit les
 * refus — rien d'autre.
 *
 * Le PERIMETRE est pose deux fois, et ce n'est pas une redondance : ici, parce
 * que le route-model-binding accepterait un Dossier d'une autre Organization ;
 * et dans le service, parce qu'il est appele aussi par le Shell (TASK a venir)
 * et qu'une garde qui vit chez UN appelant ne protege pas les autres.
 *
 * Meme discipline d'erreurs que `DossierSemanticSearchController` : un refus
 * economique se dit avec son code (429), jamais deguise en « aucun resultat ».
 */
class DossierAnswerController extends Controller
{
    public function __invoke(
        Request $request,
        Organization $organization,
        Dossier $dossier,
        DossierInsightsService $insights,
    ): JsonResponse {
        abort_unless((string) $dossier->organization_id === (string) $organization->getKey(), 404);

        $this->authorize('view', $dossier);

        foreach (['question', 'file'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([$field => trim($value)]);
            }
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:500'],
            // Le NOM tel que l'utilisateur l'a ecrit, jamais un identifiant :
            // la resolution est serveur, sur le Dossier deja autorise.
            'file' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $answer = $insights->answer(
                $organization,
                $dossier,
                $request->user(),
                $validated['question'],
                $validated['file'] ?? null,
            );
        } catch (AiRefusedException $exception) {
            return response()->json([
                'code' => $exception->refusalCode,
                'message' => $exception->getMessage(),
                'offers_url' => $exception->offersUrl($organization),
            ], 429);
        } catch (AiException|ConnectionException|RequestException|RuntimeException|\DomainException $exception) {
            Log::warning('Dossier answer unavailable.', [
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'code' => 'dossier_answer_unavailable',
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'data' => $answer->toArray(),
            // Le markdown est rendu SERVEUR, comme pour Smart Dossier : aucun
            // rendu metier cote JS, et rien d'interprete dans le navigateur.
            'html' => view('dossiers.partials.answer-result', ['answer' => $answer])->render(),
        ]);
    }
}
