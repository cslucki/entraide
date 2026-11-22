<?php

namespace App\Http\Controllers;

use App\Support\Notifications\NotificationPreferenceResolver;
use App\Support\Pwa\PwaAppName;
use App\Support\Sharing\ShareIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * TASK-1878 — console « Diagnostic PWA », depuis le smartphone reel.
 *
 * Elle MESURE, elle ne corrige rien : aucun `skipWaiting()`, aucun
 * desenregistrement, aucun cache vide, aucune ecriture de session.
 *
 * ## Le partage ne passe JAMAIS par `ShareTargetController::receive()`
 *
 * `receive()` range — ou EFFACE — `bp_share_intent` en session : l'appeler
 * pour « essayer » ecraserait un partage reel en attente. La verification
 * construit donc une requete SYNTHETIQUE, jamais emise sur le reseau, et la
 * donne a la meme analyse (`ShareIntent::depuisLaRequete()`). C'est une
 * simulation TECHNIQUE : elle prouve que le serveur sait lire un partage, pas
 * qu'Android propose BouclePro dans son menu — aucune API web ne le peut.
 *
 * Aucun compteur de Boucles en V1 (arbitrage MASTER 22/11) : la regle de
 * publication reste la propriete privee du controleur de partage.
 */
class PwaDiagnosticController extends Controller
{
    /** La declaration attendue, celle du gabarit de manifeste (TASK-1738). */
    public const PARTAGE_ATTENDU = [
        'action' => '/partage',
        'method' => 'GET',
        'params' => ['url' => 'url', 'text' => 'text', 'title' => 'title'],
    ];

    /** Une adresse d'exemple, jamais celle d'un membre. */
    private const URL_EXEMPLE = 'https://example.com/diagnostic-partage';

    public function show(Request $request, NotificationPreferenceResolver $resolver): View
    {
        abort_unless($request->user() !== null, 404);

        $slug = $request->route('organization');
        $bloc = NotificationDeviceController::donneesDuBloc($request, $resolver);

        return view('pwa.diagnostic', $bloc + [
            'routeVerification' => $slug ? 'organization.pwa.diagnostic.partage' : 'pwa.diagnostic.partage',
            'routeDevice' => $slug ? 'organization.notifications.device.edit' : 'notifications.device.edit',
            'nomAttendu' => PwaAppName::current(),
            'swAttendu' => self::versionDuServiceWorkerServi(),
            'versionApplication' => self::versionApplication(),
            'manifestePath' => config('pwa.manifest_path'),
        ]);
    }

    /**
     * Verification du parcours de reception, SANS effet metier.
     *
     * Lecture seule de la session (`has`), aucune ecriture ; la reponse ne
     * contient ni l'URL d'exemple analysee, ni aucun nom de Boucle.
     */
    public function verificationPartage(Request $request): JsonResponse
    {
        abort_unless($request->user() !== null, 404);

        $template = json_decode(
            (string) file_get_contents(resource_path('pwa/site-webmanifest-template.json')),
            associative: true,
        );
        $declare = is_array($template['share_target'] ?? null) ? $template['share_target'] : [];

        $synthetique = Request::create('/partage', 'GET', [
            'title' => 'Diagnostic',
            'text' => 'Diagnostic '.self::URL_EXEMPLE,
        ]);
        $analyse = ShareIntent::depuisLaRequete($synthetique);

        $recevoir = Route::has('partage.recevoir')
            ? parse_url(route('partage.recevoir'), PHP_URL_PATH)
            : null;

        return response()->json([
            'manifeste_serveur' => [
                'action' => ($declare['action'] ?? null) === self::PARTAGE_ATTENDU['action'],
                'method' => strtoupper((string) ($declare['method'] ?? 'GET')) === self::PARTAGE_ATTENDU['method'],
                'params' => ($declare['params'] ?? null) == self::PARTAGE_ATTENDU['params'],
            ],
            'route_reception' => $recevoir === self::PARTAGE_ATTENDU['action'],
            'route_choix' => Route::has('partage.choisir'),
            // Le texte partage par Android (« titre + URL ») est-il compris ?
            'analyse_synthetique' => $analyse !== null && $analyse->url === self::URL_EXEMPLE,
            'intention_en_attente' => $request->session()->has(ShareIntent::CLE_SESSION),
        ]);
    }

    /**
     * La version que `public/sw.js` DECLARE, a comparer a celle de
     * l'exemplaire ACTIF (message `bouclepro:version`) : un ecart dit qu'une
     * mise a jour n'a pas encore pris la main.
     */
    public static function versionDuServiceWorkerServi(): ?string
    {
        $source = @file_get_contents(public_path('sw.js'));

        return is_string($source) && preg_match("/const SW_VERSION = '([^']+)'/", $source, $m) === 1
            ? $m[1]
            : null;
    }

    private static function versionApplication(): ?string
    {
        $version = @file_get_contents(base_path('VERSION'));

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }
}
