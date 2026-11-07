<?php

namespace App\Support\Ops;

use App\Support\Http\PublicHostPolicy;

/**
 * Les sorties HTTP que la RECETTE LOCALE autorise, et elles seules.
 *
 * ## Le probleme que cette classe resout
 *
 * `ExternalEgressGuard` s'arme des que la base visee est le jumeau de
 * production, et il refuse alors TOUTE sortie HTTP. C'est voulu : le jumeau
 * porte de vraies adresses, et une sortie involontaire pourrait atteindre de
 * vraies personnes.
 *
 * Mais `test.laravel` doit pouvoir eprouver les fonctionnalites REELLES de
 * BouclePro : les notifications Web Push (T1740) et les apercus de lien des
 * ChatLoops (T1741) en font partie, et toutes deux etaient muettes.
 *
 * MASTER, 01/11 : « Nous voulons une solution reutilisable et simple, pas une
 * accumulation d'exceptions dispersees. » D'ou cette classe : UN seul endroit
 * ou l'on declare ce qui est admis, et une regle qui vaut pour tout appelant
 * futur plutot qu'un `if` de plus dans le garde.
 *
 * ## Les conditions, CUMULATIVES
 *
 * Une sortie n'est admise que si toutes sont vraies ensemble :
 *
 * 1. le socle est respecte — jamais en production, jamais sur Laravel Cloud ;
 * 2. la requete porte le MARQUEUR du transport, pose par ce transport et par
 *    lui seul. Une requete HTTP ordinaire vers le meme hote reste refusee :
 *    ce n'est pas le DOMAINE qui est admis, c'est le TRANSPORT ;
 * 3. la derogation de ce transport est explicitement ACTIVEE par
 *    configuration, donc absente par defaut ;
 * 4. l'environnement et le schema sont admis par ce transport ;
 * 5. l'hote satisfait la politique de destination de ce transport.
 *
 * Le marqueur est une option Guzzle et non un en-tete : un en-tete serait
 * recopie par une redirection et voyagerait hors de notre controle.
 *
 * ## Pourquoi l'environnement et le schema sont declares PAR TRANSPORT
 *
 * Les deux chantiers ont ete valides avec des exigences differentes, et les
 * unifier par le haut ou par le bas aurait change un comportement deja
 * eprouve en recette sur un appareil reel :
 *
 * - le **Web Push** exige HTTPS — un endpoint de fournisseur n'est jamais en
 *   clair — et admet tout environnement hors du socle ;
 * - les **apercus** admettent `http` comme `https`, parce qu'un site public
 *   peut encore etre servi en clair, mais se restreignent au seul
 *   environnement `local`.
 *
 * Chaque transport declare donc ses propres bornes. La classe ne les invente
 * pas, elle les applique — et aucune entree ne peut s'affranchir du socle.
 */
final class SortieDeRecette
{
    /** Le marqueur du transport « apercu de lien » (T1741). */
    public const OPTION_APERCU_LIEN = 'bouclepro_apercu_lien';

    /** Le marqueur du transport « import RSS » (T1785). */
    public const OPTION_IMPORT_RSS = 'bouclepro_import_rss';

    /** Le marqueur du transport « Web Push » (T1740). */
    public const OPTION_TRANSPORT_PUSH = 'bouclepro_web_push';

    /**
     * Les hotes des fournisseurs Push, donnes par suffixe de domaine et jamais
     * par motif libre : un `str_contains($hote, 'push')` aurait laisse passer
     * `fcm.googleapis.com.exemple.net`, que n'importe qui enregistre.
     */
    private const HOTES_PUSH = [
        'fcm.googleapis.com',                 // Chrome, Android
        'updates.push.services.mozilla.com',  // Firefox
        '.push.apple.com',                    // Safari, iOS/macOS
        '.notify.windows.com',                // Edge, Windows
    ];

    /**
     * Le registre. Ajouter un transport, c'est ajouter UNE entree ici — pas
     * une condition de plus dans le garde.
     *
     * @return array<string, array{config: string, schemas: list<string>, env: callable(): bool, hote: callable(string): bool}>
     */
    private static function registre(): array
    {
        return [
            self::OPTION_APERCU_LIEN => [
                'config' => 'ops.sorties_de_recette.apercus_de_lien',
                'schemas' => ['http', 'https'],
                'env' => static fn (): bool => app()->environment() === 'local',
                'hote' => PublicHostPolicy::estPublic(...),
            ],

            // TASK-1785 — l'outil SuperAdmin d'import RSS.
            //
            // Meme forme que l'apercu de lien, et pour la meme raison : la
            // destination est un hote PUBLIC quelconque, choisi par
            // l'administrateur, pas une liste fermee de fournisseurs connus.
            // `PublicHostPolicy::estPublic()` est donc le bon juge.
            //
            // Sans cette entree, l'outil etait INEPROUVABLE sur un banc dont la
            // base vise un jumeau : le garde n'admet que le bouclage, et la
            // garde SSRF de T1785 refuse justement le bouclage. Les deux etant
            // justes, la seule sortie honnete etait d'emprunter le mecanisme
            // prevu — pas d'affaiblir l'une des deux.
            //
            // Les quatre conditions du socle s'appliquent entierement :
            // `local` SEULEMENT, drapeau faux par defaut, marqueur pose par le
            // seul `RssFeedFetcher`, et hote public. Une requete HTTP
            // ordinaire vers le meme domaine reste REFUSEE.
            self::OPTION_IMPORT_RSS => [
                'config' => 'ops.sorties_de_recette.import_rss',
                'schemas' => ['http', 'https'],
                'env' => static fn (): bool => app()->environment() === 'local',
                'hote' => PublicHostPolicy::estPublic(...),
            ],

            self::OPTION_TRANSPORT_PUSH => [
                'config' => 'webpush.egress_allowed',
                'schemas' => ['https'],
                'env' => static fn (): bool => true,
                'hote' => self::estUnFournisseurPush(...),
            ],
        ];
    }

    /**
     * Cette sortie est-elle admise en recette ?
     *
     * @param  array<string, mixed>  $options  les options de la requete Guzzle
     */
    public static function autorisee(string $schema, string $hote, array $options): bool
    {
        // Le SOCLE, verifie une seule fois et avant tout : aucune entree du
        // registre ne peut s'en affranchir.
        if (app()->environment('production') || (function_exists('laravel_cloud') && laravel_cloud())) {
            return false;
        }

        $schema = mb_strtolower(trim($schema));
        $hote = mb_strtolower(trim($hote, " \t[]"));

        foreach (self::registre() as $marqueur => $regle) {
            if (($options[$marqueur] ?? false) !== true) {
                continue;
            }

            if (! (bool) config($regle['config'], false)) {
                return false;
            }

            if (! ($regle['env'])()) {
                return false;
            }

            if (! in_array($schema, $regle['schemas'], true)) {
                return false;
            }

            return ($regle['hote'])($hote);
        }

        return false;
    }

    /**
     * Cet hote est-il un fournisseur Push reconnu ?
     *
     * Deux formes, et la distinction n'est pas cosmetique :
     *
     * - sans point initial (`fcm.googleapis.com`) = correspondance EXACTE. Un
     *   `str_ends_with` seul admettait `xfcm.googleapis.com` et
     *   `evil-fcm.googleapis.com` — mesure faite, les deux passaient. Le trou
     *   etait dans ce fichier, trouve en l'EPROUVANT plutot qu'en le relisant ;
     * - avec point initial (`.push.apple.com`) = SOUS-DOMAINE, et le point
     *   force la frontiere d'etiquette : `evil.push.apple.com` est bien un
     *   sous-domaine d'Apple, `evilpush.apple.com` ne l'est pas.
     */
    private static function estUnFournisseurPush(string $hote): bool
    {
        foreach (self::HOTES_PUSH as $admis) {
            $correspond = str_starts_with($admis, '.')
                ? str_ends_with($hote, $admis)
                : $hote === $admis;

            if ($correspond) {
                return true;
            }
        }

        return false;
    }
}
