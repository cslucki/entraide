<?php

namespace App\Http\Controllers;

use App\Services\OpenSource\OpenSourceSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * TASK-1612 — les deux seules portes du drawer Open Source.
 *
 * `repository()` sert l'instantane assaini. `github()` est la SORTIE : une
 * redirection serveur, qui existe pour que l'URL du depot n'apparaisse ni
 * dans le HTML, ni dans la barre d'etat du navigateur au survol d'un lien.
 * Regle de presentation posee par MASTER, pas frontiere de securite : la
 * destination est publique et le restera.
 */
class OpenSourceController extends Controller
{
    /**
     * Destinations autorisees de la sortie. Une liste FERMEE : le parametre
     * vient de l'URL, et un `?to=` libre transformerait `/open-source/github`
     * en redirecteur ouvert.
     *
     * @var array<string, string>
     */
    private const DESTINATIONS = [
        'repository' => '',
        'contributing' => '/blob/{branch}/CONTRIBUTING.md',
    ];

    public function repository(OpenSourceSnapshotService $snapshots): JsonResponse
    {
        /*
         * Le service ne leve pas : une panne GitHub produit un instantane
         * `available: false`. L'endpoint repond donc 200 dans tous les cas —
         * l'indisponibilite du depot est un ETAT de l'interface, pas une
         * erreur de notre application.
         */
        return response()->json($snapshots->snapshot());
    }

    public function github(Request $request): RedirectResponse
    {
        $destination = (string) $request->query('to', 'repository');
        $path = self::DESTINATIONS[$destination] ?? self::DESTINATIONS['repository'];

        $url = sprintf(
            'https://github.com/%s/%s',
            (string) config('open_source.repository.owner'),
            (string) config('open_source.repository.name'),
        ).str_replace('{branch}', (string) config('open_source.repository.branch', 'main'), $path);

        return redirect()->away($url, 302);
    }
}
