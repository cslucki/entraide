<?php

namespace App\Support\Ops;

use App\Support\Http\PublicHostPolicy;

/**
 * TASK-1741 — les sorties HTTP que la RECETTE LOCALE autorise, et elles seules.
 *
 * ## Le probleme que cette classe resout
 *
 * `ExternalEgressGuard` s'arme des que la base visee est le jumeau de
 * production, et il refuse alors TOUTE sortie HTTP. C'est voulu : le jumeau
 * porte de vraies adresses, et une sortie involontaire pourrait atteindre de
 * vraies personnes.
 *
 * Mais `test.laravel` doit pouvoir eprouver les fonctionnalites REELLES de
 * BouclePro — les apercus de lien en font partie, et ils revenaient vides.
 *
 * MASTER, 01/11 : « Nous voulons une solution reutilisable et simple, pas une
 * accumulation d'exceptions dispersees. » D'ou cette classe : UN seul endroit
 * ou l'on declare ce qui est admis, et une regle qui vaut pour tout appelant
 * futur plutot qu'un `if` de plus dans le garde.
 *
 * ## Les quatre conditions, CUMULATIVES
 *
 * Une sortie n'est admise que si les quatre sont vraies ensemble :
 *
 * 1. l'environnement est `local` — jamais la production, jamais Laravel Cloud ;
 * 2. la derogation est explicitement ACTIVEE par configuration, donc absente
 *    par defaut : personne ne l'obtient sans l'avoir demandee ;
 * 3. la requete porte le MARQUEUR du transport concerne, pose par ce transport
 *    et par lui seul. Une requete HTTP ordinaire vers le meme hote reste
 *    refusee — c'est la condition que MASTER a explicitement posee ;
 * 4. l'hote satisfait la politique de destination du transport.
 *
 * Le marqueur est une option Guzzle et non un en-tete : un en-tete serait
 * recopie par une redirection et voyagerait hors de notre controle.
 *
 * ## Pourquoi la condition 4 n'est pas decorative
 *
 * Sans elle, poser le marqueur suffirait a joindre n'importe quoi — y compris
 * le reseau interne. Pour les apercus, la destination doit donc rester
 * PUBLIQUE au sens de {@see PublicHostPolicy}, exactement comme avant
 * l'assouplissement. Le garde cesse de refuser la sortie ; il ne cesse pas de
 * refuser une cible interne.
 */
final class SortieDeRecette
{
    /**
     * Le marqueur du transport « apercu de lien ».
     *
     * Pose UNIQUEMENT par {@see \App\Services\UrlPreviewService}.
     */
    public const OPTION_APERCU_LIEN = 'bouclepro_apercu_lien';

    /**
     * Le registre : un marqueur, la cle de configuration qui l'active, et la
     * politique de destination qui s'applique.
     *
     * Ajouter un transport, c'est ajouter UNE entree ici — pas une condition
     * de plus dans le garde.
     *
     * @var array<string, array{config: string, hote: callable(string): bool}>
     */
    private static function registre(): array
    {
        return [
            self::OPTION_APERCU_LIEN => [
                'config' => 'ops.sorties_de_recette.apercus_de_lien',
                'hote' => PublicHostPolicy::estPublic(...),
            ],
        ];
    }

    /**
     * Cette sortie est-elle admise en recette locale ?
     *
     * @param  array<string, mixed>  $options  les options de la requete Guzzle
     */
    public static function autorisee(string $hote, array $options): bool
    {
        // 1 — jamais hors de `local`. Premiere condition verifiee, et la plus
        // importante : elle rend la derogation inatteignable en production,
        // quoi qu'on configure par ailleurs.
        if (app()->environment() !== 'local') {
            return false;
        }

        foreach (self::registre() as $marqueur => $regle) {
            // 3 — le marqueur du transport, pose par lui seul.
            if (($options[$marqueur] ?? false) !== true) {
                continue;
            }

            // 2 — la derogation doit avoir ete ACTIVEE, explicitement.
            if (config($regle['config']) !== true) {
                return false;
            }

            // 4 — et la destination doit satisfaire la politique du transport.
            return ($regle['hote'])($hote);
        }

        return false;
    }
}
