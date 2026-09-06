<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TASK-1369 — sauter quand la BASE COURANTE ne sait pas evaluer `->>`.
 *
 * ## Pourquoi une sonde, et pas un numero de version
 *
 * L'operateur JSON `->>` n'existe qu'a partir de SQLite 3.38. Sur un poste
 * equipe de 3.37.2, toute requete qui l'emploie leve
 * `SQLSTATE[HY000] General error: 1 near ">>": syntax error`, et Laravel la
 * transforme en **HTTP 500**. Le test echouait donc avec « Expected 200 but
 * received 500 » : le symptome designait l'application, la cause etait la
 * version de la bibliotheque installee sur la machine.
 *
 * Comparer `sqlite_version()` a `3.38` serait une regle de plus a maintenir, et
 * elle serait fausse le jour ou une distribution retroporte la fonctionnalite.
 * On DEMANDE donc a la base si elle sait faire, au lieu de le deduire de son
 * numero — c'est la difference entre mesurer et supposer.
 *
 * ## Ce que ce saut n'est pas
 *
 * Ce n'est ni un assouplissement d'assertion, ni une reecriture du SQL
 * applicatif pour contenter une vieille SQLite. Le contrat du test reste
 * intact ; il est simplement declare INEXECUTABLE ici, et il s'execute
 * pleinement partout ou l'operateur existe — PostgreSQL en CI, SQLite >= 3.38.
 */
trait RequiresSqlJsonArrowOperator
{
    protected function skipWithoutJsonArrowOperator(): void
    {
        $connection = DB::connection();

        try {
            // Le CAST n'est pas cosmetique. Sans lui, PostgreSQL refuse
            // l'expression pour AMBIGUITE — « operator is not unique » —, car
            // un litteral non type peut etre `json` ou `jsonb`. La sonde aurait
            // alors saute sur PostgreSQL AUSSI, c'est-a-dire la ou l'operateur
            // existe : un skip universel, exactement ce qu'il ne faut pas.
            // Mesure : PostgreSQL rend 1, SQLite 3.37.2 leve « near ">>" ».
            // `CAST(... AS jsonb)` est valide en SQLite, qui accepte tout nom
            // de type (affinite), donc l'echec y reste bien celui de `->>`.
            $connection->selectOne('select CAST(\'{"probe":1}\' AS jsonb) ->> \'probe\' as valeur');
        } catch (Throwable $exception) {
            $this->markTestSkipped(sprintf(
                "La base courante (%s) n'evalue pas l'operateur JSON `->>` ; ce test ne peut pas exercer son contrat ici. Detail : %s",
                $connection->getDriverName(),
                explode("\n", $exception->getMessage())[0],
            ));
        }
    }
}
