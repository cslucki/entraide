<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1635 (M2/3) — USER LIFECYCLE : les relations qui doivent BLOQUER.
 *
 * ## Le probleme
 *
 * `UserDataLifecycleRegistry` classe ces relations en BLOCK : tant qu'elles
 * existent, un User ne doit PAS pouvoir disparaitre. Le schema disait autre
 * chose — et de deux facons differentes :
 *
 *   - `ON DELETE CASCADE` sur sept d'entre elles : la donnee etait effacee
 *     sans bruit, c'est-a-dire l'exact oppose d'un blocage ;
 *   - `ON DELETE SET NULL` sur `organizations.admin_id` : l'Organization se
 *     retrouvait silencieusement SANS responsable, alors que la justification
 *     du registre dit « must be reassigned before deletion ».
 *
 * Un BLOCK que la base n'applique pas n'est pas un BLOCK, c'est un commentaire.
 *
 *     CASCADE (ou SET NULL)   ->   ON DELETE RESTRICT
 *
 * A partir d'ici, un `forceDelete()` brut sur User ECHOUE bruyamment tant que
 * le cas n'a pas ete resolu explicitement. La base devient le filet de
 * securite du futur executeur (TASK-1636).
 *
 * ## Les 8 colonnes
 *
 *   organizations.admin_id        <- ajoutee par l'addendum du 24/09 21h53
 *   dossiers.owner_id
 *   point_ledger.user_id
 *   loop_poll_votes.user_id
 *   course_submissions.user_id
 *   course_quiz_attempts.user_id
 *   transactions.buyer_id
 *   transactions.seller_id
 *
 * `member_ai_profiles.user_id` n'en fait **plus** partie : decision MASTER du
 * 24/09 21h53, c'est une donnee personnelle structuree propre au User, donc
 * DELETE. Sa FK reste volontairement en CASCADE — un filet coherent avec cette
 * policy — et n'est pas touchee ici.
 *
 * `transactions` : une transaction est un historique economique BILATERAL.
 * Reattribuer son `buyer_id` ou son `seller_id` a un autre User reecrirait
 * l'histoire d'un echange entre deux personnes. Ce n'est pas un transfert de
 * propriete, c'est une falsification — d'ou le passage de TRANSFER a BLOCK
 * dans le registre, en meme temps que ce changement de schema.
 *
 * ## Le piege du nom de contrainte
 *
 * `dropForeign(['colonne'])` laisse Laravel DEDUIRE le nom
 * `{table}_{colonne}_foreign`. Les 28 autres colonnes de TASK-1635 suivent
 * cette convention — verifie par requete sur le catalogue.
 *
 * **`organizations.admin_id` ne la suit pas** : sa contrainte s'appelle
 * `communities_admin_id_foreign`, survivance de l'ere Community, du temps ou
 * la table se nommait `communities`. Deduire le nom aurait produit un
 * `DROP CONSTRAINT organizations_admin_id_foreign` qui n'existe pas.
 *
 * On lit donc le nom REEL dans le catalogue PostgreSQL. SQLite, lui, ne
 * nomme pas ses contraintes : la reconstruction de table se fait par colonne,
 * et `dropForeign(['colonne'])` y est la seule forme acceptee.
 *
 * ## Le piege de l'index PARTIEL
 *
 * Reconstruire une table SQLite recree ses index — mais Laravel les relit via
 * `PRAGMA index_list`, qui ne rend PAS la clause `WHERE`. Un index unique
 * PARTIEL en ressort donc en index unique TOTAL.
 *
 * `dossiers` en porte un, pose en SQL brut par
 * `2026_08_12_120000_add_system_role_to_dossiers_table` :
 *
 *     CREATE UNIQUE INDEX dossiers_personal_documents_unique
 *     ON dossiers (organization_id, owner_id)
 *     WHERE system_role = 'personal_documents' AND deleted_at IS NULL
 *
 * Sans precaution, « un seul dossier PERSONNEL par membre » devenait « un seul
 * dossier TOUT COURT par membre ». Defaut silencieux : la migration passait,
 * et c'est le modele de donnees qui cassait — mesure par les ScenarioPacks,
 * qui creent plusieurs Dossiers pour un meme proprietaire.
 *
 * On capture donc le SQL REEL des index partiels avant la reconstruction et on
 * les repose ensuite a l'identique. Rien n'est code en dur : un index partiel
 * ajoute plus tard sur une de ces tables sera protege sans modifier ce
 * fichier. PostgreSQL n'est pas concerne — il modifie la contrainte en place.
 *
 * ## Ce que cette migration ne fait PAS
 *
 * Aucune colonne ne devient nullable : rendre `point_ledger.user_id` nullable
 * reviendrait a accepter une ecriture de ledger sans titulaire. Le contenu
 * metier de ces relations n'est pas resolu ici — c'est le travail explicite de
 * TASK-1636. Pour `dossiers.owner_id`, la decision est deja prise : le blocage
 * sera leve via `DossierTreePurger` AVANT le DELETE final du User. **Aucun
 * Dossier n'est purge ici.**
 */
return new class extends Migration
{
    /**
     * Colonne => regle ON DELETE d'ORIGINE, celle que `down()` doit rendre.
     *
     * Elle n'est pas la meme partout : sept colonnes viennent de CASCADE,
     * `organizations.admin_id` vient de SET NULL. Un `down()` qui remettrait
     * CASCADE partout donnerait a une Organization un responsable supprime en
     * cascade — un etat que le schema n'a jamais eu.
     *
     * @var array<string, array<string, string>>
     */
    private const COLUMNS = [
        'organizations' => ['admin_id' => 'SET NULL'],
        'dossiers' => ['owner_id' => 'CASCADE'],
        'point_ledger' => ['user_id' => 'CASCADE'],
        'loop_poll_votes' => ['user_id' => 'CASCADE'],
        'course_submissions' => ['user_id' => 'CASCADE'],
        'course_quiz_attempts' => ['user_id' => 'CASCADE'],
        'transactions' => ['buyer_id' => 'CASCADE', 'seller_id' => 'CASCADE'],
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
