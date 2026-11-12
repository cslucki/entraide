<?php

namespace App\Http\Controllers;

use App\Support\Pwa\PwaAppName;
use Illuminate\Http\Response;

/**
 * TASK-1805 — `GET /site.webmanifest`, enfin SERVI PAR LARAVEL.
 *
 * Avant cette TASK, `public/site.webmanifest` etait un fichier STATIQUE :
 * `public/.htaccess` ne redirige vers `index.php` QUE si le fichier demande
 * n'existe pas sur disque (`RewriteCond %{REQUEST_FILENAME} !-f`), donc tant
 * que ce fichier existait, Laravel n'etait JAMAIS invoque pour cette route —
 * aucune route PHP n'aurait pu le remplacer sans que le fichier physique soit
 * d'abord retire. C'est fait : voir le commit qui supprime
 * `public/site.webmanifest`.
 *
 * Le contenu vient d'un GABARIT (`resources/pwa/site-webmanifest-template.json`,
 * hors `public/` pour ne pas reproduire le meme court-circuit), identique a
 * l'ancien fichier statique a l'exception de `name`/`short_name`, qui portent
 * le jeton `__APP_NAME__` dans le fichier (lisible comme « ce champ est
 * calcule ») et sont ecrases ici par `PwaAppName::current()` — jamais
 * duplique en dur une seconde fois.
 *
 * ## La limite qu'aucun code ne peut lever
 *
 * Un tunnel Cloudflare temporaire (`https://<sous-domaine>.trycloudflare.com`)
 * change de sous-domaine a chaque redemarrage, et chaque sous-domaine est une
 * ORIGINE distincte pour le navigateur. Deux consequences, inherentes au
 * navigateur et non a cette application :
 *   - le Cache Storage du Service Worker, l'IndexedDB et les abonnements Web
 *     Push d'un ancien tunnel ne sont PAS repris par le nouveau — changer de
 *     tunnel peut donc creer une installation PWA entierement neuve, meme si
 *     le serveur local derriere est rigoureusement le meme ;
 *   - rien ici ne peut promettre une continuite entre deux tunnels. Seul un
 *     tunnel a domaine STABLE (ex. Cloudflare Tunnel nomme, par opposition au
 *     tunnel rapide `trycloudflare.com`) garantirait la meme origine d'une
 *     session a l'autre.
 */
class PwaManifestController extends Controller
{
    public function __invoke(): Response
    {
        $manifest = json_decode(
            file_get_contents(resource_path('pwa/site-webmanifest-template.json')),
            associative: true,
        );

        // Decode + reencode plutot qu'un `str_replace` sur le texte brut :
        // un `PWA_APP_NAME` qui contiendrait un guillemet resterait du JSON
        // VALIDE ici, la ou une substitution textuelle produirait un manifest
        // casse.
        $appName = PwaAppName::current();
        $manifest['name'] = $appName;
        $manifest['short_name'] = $appName;

        // TASK-1805 correctif (demande Cyril) — l'icone MARRON
        // (`design/brand/bouclepro-symbol-512_marron.png`, deja declinee en
        // `public/brand/*-dev.png`) distingue visuellement une installation
        // locale d'une installation de production, en plus du nom. Gate sur
        // `isLocal()` et non sur le nom calcule : un override de nom
        // (preprod) n'a pas demande une icone differente.
        if (PwaAppName::isLocal()) {
            foreach ($manifest['icons'] as &$icone) {
                $icone['src'] = preg_replace('/\.png$/', '-dev.png', $icone['src']);
            }
            unset($icone);
        }

        return response(
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            ['Content-Type' => 'application/manifest+json'],
        );
    }
}
