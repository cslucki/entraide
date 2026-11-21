<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImageOptimizationBatch;
use App\Support\Images\ImageOptimizationBatches;
use App\Support\Images\ManualImageOptimization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-1875 — avancer un lot MANUEL d'UNE image.
 *
 * Appele par le navigateur du SuperAdmin, une requete apres l'autre. Groupe
 * `auth` + `admin` (SuperAdmin), CSRF du groupe `web`, debit limite par la
 * route. Le navigateur ne transmet que l'identifiant du lot : les images sont
 * celles enregistrees au lancement, jamais un chemin fourni par le client.
 */
class AdminImageManualStepController extends Controller
{
    public function __invoke(Request $request, ImageOptimizationBatch $batch, ManualImageOptimization $manual, ImageOptimizationBatches $batches): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($batch->isManual(), 404);

        $result = $manual->step($batch, $request);
        $batch->refresh();
        $progress = $batches->progress($batch);

        return response()->json($result + [
            // Le navigateur enchaine tant que le lot avance ; il s'arrete sur
            // une pause, un lot termine, ou a la fermeture de l'onglet.
            'continue' => $batch->isActive() && ! $batch->isPaused(),
            'batch_status' => $batch->status,
            'done' => $progress['done'],
            'total' => $progress['total'],
        ]);
    }
}
