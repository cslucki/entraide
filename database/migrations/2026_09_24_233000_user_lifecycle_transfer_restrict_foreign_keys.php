<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1636 (M3) — USER LIFECYCLE : les vraies PROPRIETES transferables.
 *
 * ## Ce que TASK-1635 avait reporte, et pourquoi
 *
 * `UserDataLifecycleRegistry` classe ces 4 colonnes en TRANSFER : ce sont des
 * biens qui appartiennent a quelqu'un et qui doivent CHANGER de proprietaire
 * quand ce quelqu'un s'en va. Le schema disait `ON DELETE CASCADE` : supprimer
 * le User DETRUISAIT le bien au lieu de le transmettre.
 *
 *     ON DELETE CASCADE   ->   ON DELETE RESTRICT
 *
 * TASK-1635 avait ecrit cette migration puis l'a **volontairement reportee**
 * ici : un RESTRICT sur une colonne TRANSFER ne protege vraiment que si un
 * executeur sait transferer. Tant qu'il n'existait pas, ce RESTRICT
 * n'empechait pas une perte de donnees — il empechait TOUTE suppression, y
 * compris legitime, et faisait rougir le retrait des ScenarioPacks.
 *
 * `UserDeletionExecutor` sait desormais transferer. Le filet peut donc etre
 * pose : il devient le dernier recours si l'application oublie un transfert,
 * la ou il n'etait jusqu'ici qu'un obstacle.
 *
 * ## Pourquoi RESTRICT et pas SET NULL
 *
 * Ces colonnes sont `NOT NULL` et le restent : un article sans auteur n'existe
 * pas dans ce produit. On ne peut donc pas les detacher — il faut les
 * TRANSFERER, ce qui est une decision applicative (vers QUI ?) qu'une
 * contrainte de base ne peut pas prendre.
 *
 * **Aucune donnee n'est transferee par cette migration.** Elle ne deplace pas
 * une seule ligne : elle installe le filet qui rend le transfert obligatoire.
 *
 * ## Portabilite et idempotence
 *
 * Memes regles que M1 et M2 (TASK-1635), pour les memes raisons mesurees :
 * ces FK existent sur les deux moteurs, Laravel 13 reconstruit la table SQLite
 * pour changer une FK, et l'etat est lu dans le catalogue du moteur avant
 * toute reecriture — une colonne deja conforme n'est pas touchee.
 *
 * La protection des index PARTIELS est conservee telle quelle : aucune de ces
 * 4 tables n'en porte aujourd'hui, mais la garder coute une requete et protege
 * celle qui en recevrait un demain.
 */
return new class extends Migration
{
    /**
     * Colonne => regle ON DELETE d'ORIGINE, celle que `down()` doit rendre.
     *
     * Les quatre viennent de CASCADE. La forme « colonne => regle » est reprise
     * de M2, ou elle etait indispensable (`organizations.admin_id` venait de
     * SET NULL) ; on la garde ici pour que les deux migrations se lisent
     * pareil.
     *
     * @var array<string, array<string, string>>
     */
    private const COLUMNS = [
        'blog_posts' => ['user_id' => 'CASCADE'],
        'feed_posts' => ['user_id' => 'CASCADE'],
        'services' => ['user_id' => 'CASCADE'],
        'service_requests' => ['user_id' => 'CASCADE'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $this->apply($table, array_fill_keys(array_keys($columns), 'RESTRICT'));
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $this->apply($table, $columns);
        }
    }

    /**
     * Repose la FK de chaque colonne avec la regle visee, sans jamais toucher
     * a la nullabilite.
     *
     * @param  array<string, string>  $targets  colonne => regle ON DELETE visee
     */
    private function apply(string $table, array $targets): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $todo = [];

        foreach ($targets as $column => $rule) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            // Idempotence : une colonne deja dans l'etat vise n'est pas
            // touchee. En SQLite une reconstruction inutile reecrit toute la
            // table ; en PostgreSQL elle prend un verrou exclusif.
            if ($this->foreignKeyRule($table, $column) === $rule) {
                continue;
            }

            $todo[$column] = $rule;
        }

        if ($todo === []) {
            return;
        }

        $names = [];

        if (DB::getDriverName() !== 'sqlite') {
            foreach ($todo as $column => $rule) {
                $names[$column] = $this->constraintName($table, $column);
            }
        }

        // Voir le bloc « Le piege de l'index PARTIEL » en tete de fichier.
        $partialIndexes = $this->capturePartialIndexes($table);

        Schema::table($table, function (Blueprint $blueprint) use ($todo, $names) {
            foreach ($todo as $column => $rule) {
                // Par NOM REEL en PostgreSQL (cf. `communities_admin_id_foreign`),
                // par colonne en SQLite, qui ne sait faire que cela.
                isset($names[$column]) && $names[$column] !== null
                    ? $blueprint->dropForeign($names[$column])
                    : $blueprint->dropForeign([$column]);

                $foreign = $blueprint->foreign($column)->references('id')->on('users');

                match ($rule) {
                    'RESTRICT' => $foreign->restrictOnDelete(),
                    'SET NULL' => $foreign->nullOnDelete(),
                    default => $foreign->cascadeOnDelete(),
                };
            }
        });

        $this->restorePartialIndexes($partialIndexes);
    }

    /**
     * Definitions SQL des index PARTIELS de cette table, avant reconstruction.
     *
     * SQLite uniquement : PostgreSQL modifie la contrainte en place et ne
     * touche jamais aux index.
     *
     * On relit le SQL REEL dans `sqlite_master` au lieu de coder la moindre
     * definition en dur : cette migration n'a pas a connaitre les index des
     * autres, et un index partiel ajoute plus tard sera protege sans qu'on y
     * revienne.
     *
     * Le motif ne suppose AUCUN formatage : `sqlite_master` rend le SQL tel
     * qu'il a ete ecrit, et celui de `dossiers` porte un saut de ligne juste
     * avant sa clause — `...(organization_id, owner_id)\nWHERE ...`. Un
     * `LIKE '% WHERE %'` ne l'aurait jamais vu, et la protection aurait ete
     * silencieusement inerte.
     *
     * @return list<object{name: string, sql: string}>
     */
    private function capturePartialIndexes(string $table): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        return DB::select(
            "SELECT name, sql FROM sqlite_master
             WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL
               AND upper(sql) LIKE '%WHERE %'",
            [$table]
        );
    }

    /**
     * Repose les index partiels tels qu'ils etaient, a l'identique.
     *
     * @param  list<object{name: string, sql: string}>  $indexes
     */
    private function restorePartialIndexes(array $indexes): void
    {
        foreach ($indexes as $index) {
            // La reconstruction en a recree un homonyme SANS clause WHERE :
            // il faut le retirer avant de reposer le vrai.
            DB::statement('DROP INDEX IF EXISTS '.$index->name);
            DB::statement($index->sql);
        }
    }

    /**
     * Nom REEL de la contrainte portant cette colonne, lu dans le catalogue.
     *
     * Jamais deduit : la convention Laravel n'est pas tenue partout.
     */
    private function constraintName(string $table, string $column): ?string
    {
        $rows = DB::select(<<<'SQL'
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_name = ?
              AND kcu.column_name = ?
              AND tc.table_schema = current_schema()
            LIMIT 1
        SQL, [$table, $column]);

        return $rows === [] ? null : (string) $rows[0]->constraint_name;
    }

    private function foreignKeyRule(string $table, string $column): ?string
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select('PRAGMA foreign_key_list('.$table.')') as $fk) {
                if ($fk->from === $column) {
                    return strtoupper((string) $fk->on_delete);
                }
            }

            return null;
        }

        $rows = DB::select(<<<'SQL'
            SELECT rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            JOIN information_schema.referential_constraints rc
              ON tc.constraint_name = rc.constraint_name
             AND tc.table_schema = rc.constraint_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_name = ?
              AND kcu.column_name = ?
              AND tc.table_schema = current_schema()
            LIMIT 1
        SQL, [$table, $column]);

        return $rows === [] ? null : strtoupper((string) $rows[0]->delete_rule);
    }
};
