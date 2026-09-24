<?php

namespace App\Support\Integrity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1632 — quelles tables portent une colonne de reference, et lesquelles
 * le font SANS cle etrangere.
 *
 * ## Pourquoi cette distinction porte tout l'outil
 *
 * Une colonne protegee par une FK ne peut pas pointer dans le vide : la base
 * l'interdit. Verifier son integrite ligne a ligne serait demander a
 * PostgreSQL de se contredire. Le releve est net :
 *
 *   · `organization_id` : 107 FK (87 CASCADE, 20 SET NULL) et **3 tables sans
 *     aucune contrainte** ;
 *   · `loop_id` : 24 tables, 24 FK, **aucune exception** ;
 *   · `dossier_id` : 6 tables, 6 FK, **aucune exception**.
 *
 * Le cockpit ne balaie donc PAS 137 tables : il regarde les trois ou une
 * reference cassee est seulement possible, et il DIT que les autres sont
 * garanties par le schema. C'est plus honnete qu'un compte a zero obtenu en
 * interrogeant des tables que la base protege deja.
 *
 * ## Les deux moteurs
 *
 * `information_schema` en PostgreSQL, `PRAGMA foreign_key_list` en SQLite.
 * Deux chemins, un seul contrat — et les tests l'exercent sur les deux, parce
 * qu'un cockpit d'integrite qui ne saurait pas lire le schema de la CI legere
 * serait un cockpit qu'on ne peut pas tester.
 */
class SchemaReferenceInspector
{
    /**
     * Les tables portant `$column`, qu'elles aient une FK ou non.
     *
     * @return list<string>
     */
    public function tablesWithColumn(string $column): array
    {
        if ($this->isPostgres()) {
            $rows = DB::select(<<<'SQL'
                SELECT c.table_name AS name
                FROM information_schema.columns c
                JOIN information_schema.tables t
                  ON t.table_name = c.table_name AND t.table_schema = c.table_schema
                WHERE c.column_name = ?
                  AND c.table_schema = 'public'
                  AND t.table_type = 'BASE TABLE'
                ORDER BY c.table_name
            SQL, [$column]);

            return array_map(fn ($r) => $r->name, $rows);
        }

        return collect($this->sqliteTables())
            ->filter(fn (string $table) => Schema::hasColumn($table, $column))
            ->values()
            ->all();
    }

    /**
     * Les tables ou `$column` n'est protegee par AUCUNE cle etrangere.
     *
     * Ce sont les seules ou une reference peut pointer dans le vide, donc les
     * seules que le cockpit a besoin de verifier ligne a ligne.
     *
     * @return list<string>
     */
    public function tablesWithoutForeignKey(string $column): array
    {
        if ($this->isPostgres()) {
            $rows = DB::select(<<<'SQL'
                SELECT c.table_name AS name
                FROM information_schema.columns c
                JOIN information_schema.tables t
                  ON t.table_name = c.table_name AND t.table_schema = c.table_schema
                WHERE c.column_name = ?
                  AND c.table_schema = 'public'
                  AND t.table_type = 'BASE TABLE'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM information_schema.table_constraints tc
                      JOIN information_schema.key_column_usage k
                        ON tc.constraint_name = k.constraint_name
                       AND tc.table_schema = k.table_schema
                      WHERE tc.constraint_type = 'FOREIGN KEY'
                        AND tc.table_name = c.table_name
                        AND tc.table_schema = c.table_schema
                        AND k.column_name = c.column_name
                  )
                ORDER BY c.table_name
            SQL, [$column]);

            return array_map(fn ($r) => $r->name, $rows);
        }

        return collect($this->tablesWithColumn($column))
            ->reject(fn (string $table) => $this->sqliteHasForeignKeyOn($table, $column))
            ->values()
            ->all();
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * @return list<string>
     */
    private function sqliteTables(): array
    {
        $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        return array_map(fn ($r) => $r->name, $rows);
    }

    private function sqliteHasForeignKeyOn(string $table, string $column): bool
    {
        // `PRAGMA foreign_key_list` n'accepte pas de parametre lie ; le nom de
        // table vient de `sqlite_master`, jamais d'une entree utilisateur, et
        // on le refuse quand meme s'il n'a pas la forme attendue.
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return false;
        }

        foreach (DB::select('PRAGMA foreign_key_list('.$table.')') as $fk) {
            if (($fk->from ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
