<?php

namespace App\Http\Controllers;

use App\Support\Pwa\AndroidAssetLinks;
use Illuminate\Http\JsonResponse;

/**
 * TASK-1895 — `GET /.well-known/assetlinks.json` (Digital Asset Links).
 *
 * Lu par Android et par l'API Google Digital Asset Links pour verifier que
 * l'application `com.bouclepro.app` appartient bien a ce site. Le
 * verificateur exige un 200 DIRECT (aucune redirection) et du JSON : la
 * route est donc enregistree HORS du groupe `web` (ni session, ni cookie,
 * ni garde susceptible de rediriger). Voir `routes/web.php`.
 */
class AndroidAssetLinksController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            AndroidAssetLinks::current(),
            200,
            ['Cache-Control' => 'public, max-age=300'],
            JSON_UNESCAPED_SLASHES,
        );
    }
}
