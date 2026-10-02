<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1635 (M1/3) — USER LIFECYCLE : les donnees qui doivent SURVIVRE au User.
 *
 * ## Le probleme
 *
 * `UserDataLifecycleRegistry` classe ces 17 colonnes en ANONYMIZE ou RETAIN :
 * la ligne survit, l'attribution devient NULL. Le schema, lui, dit l'inverse —
 * `NOT NULL` + `ON DELETE CASCADE` : supprimer le User DETRUIT la ligne.
 *
 * Le registre et la base se contredisaient donc, et c'est la base qui gagne.
 * Un `forceDelete()` sur User aurait efface des avis, des commentaires, des
 * historiques de parrainage et des journaux de connexion que la politique
 * declare explicitement durables.
 *
 * Cette migration fait converger le schema vers le registre :
 *
 *     NOT NULL + ON DELETE CASCADE   ->   nullable + ON DELETE SET NULL
 *
 * Aucun compte sentinelle « Utilisateur supprime » n'est cree : decision
 * MASTER. L'attribution devient NULL, et les lectures traitent deja ce cas.
 *
 * ## Portabilite — mesuree, pas supposee
 *
 * Contrairement aux FK `organization_id` de TASK-1633 (posees par
 * `Schema::table()` sur des tables existantes, donc absentes en SQLite), ces
 * 17 FK sont declarees dans les `Schema::create()` d'origine : elles existent
 * sur les DEUX moteurs, en CASCADE, avec `PRAGMA foreign_keys = 1`.
 *
 * Laravel 13 sait reconstruire une table SQLite pour changer une FK
 * (`SQLiteGrammar::compileDropForeign` : « Handled on table alteration »).
 * Mesure faite avant d'ecrire cette migration : la reconstruction preserve les
 * index UNIQUE composites (`reviews(transaction_id, reviewer_id)`,
 * `referrals(organization_id, referrer_user_id, referred_user_id)`) et les
 * autres cles etrangeres de la table. Une seule voie suffit donc pour les deux
 * moteurs — pas de branche `if (pgsql)`.
 *
 * ## Idempotence
 *
 * Chaque colonne est comparee a l'etat CIBLE avant d'etre touchee. Une base
 * deja convergente n'est pas reconstruite : en SQLite une reconstruction
 * inutile reecrit toute la table, en PostgreSQL elle prend un verrou exclusif.
 * L'etat est lu dans le catalogue du moteur, jamais deduit du nom.
 */
return new class extends Migration
{
    /**
     * Les 17 colonnes ANONYMIZE / RETAIN, groupees par table : une table n'est
     * reconstruite qu'une fois, meme quand elle porte deux colonnes.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'blog_annotation_replies' => ['user_id'],
        'blog_comments' => ['user_id'],
        'blog_post_annotations' => ['user_id'],
        'blog_todo_threads' => ['user_id'],
        'blog_todos' => ['user_id'],
        'feed_post_comments' => ['user_id'],
        'loop_roadmap_item_messages' => ['user_id'],
        'loop_roadmap_items' => ['created_by'],
        'member_ai_profile_interactions' => ['profile_owner_user_id'],
        'profile_agent_conversations' => ['profile_owner_user_id'],
        'reviews' => ['reviewed_id', 'reviewer_id'],
        'login_logs' => ['user_id'],
        'referral_rewards' => ['user_id'],
        'reports' => ['reporter_id'],
        'referrals' => ['referrer_user_id', 'referred_user_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $todo = $this->columnsNeedingChange($table, $columns, 'SET NULL', true);

            if ($todo === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($todo) {
                foreach ($todo as $column) {
                    $blueprint->dropForeign([$column]);
                    $blueprint->uuid($column)->nullable()->change();
                    $blueprint->foreign($column)->references('id')->on('users')->nullOnDelete();
                }
            });
        }
    }

    /**
     * Rollback : retour a `NOT NULL` + `ON DELETE CASCADE`.
     *
     * Il echoue volontairement, avec un message nommant la table et le nombre
     * de lignes, si des attributions ont deja ete mises a NULL : reposer un
     * `NOT NULL` obligerait soit a detruire ces lignes, soit a leur inventer un
     * proprietaire. Les deux sont pires qu'un rollback refuse.
     */
    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $todo = $this->columnsNeedingChange($table, $columns, 'CASCADE', false);

            if ($todo === []) {
                continue;
            }

            foreach ($todo as $column) {
                $detached = DB::table($table)->whereNull($column)->count();

                if ($detached > 0) {
                    throw new RuntimeException(
                        "Rollback refuse : {$table}.{$column} porte {$detached} ligne(s) a NULL. "
                        .'Reposer NOT NULL detruirait ces lignes ou leur inventerait un proprietaire.'
                    );
                }
            }

            Schema::table($table, function (Blueprint $blueprint) use ($todo) {
                foreach ($todo as $column) {
                    $blueprint->dropForeign([$column]);
                    $blueprint->uuid($column)->nullable(false)->change();
                    $blueprint->foreign($column)->references('id')->on('users')->cascadeOnDelete();
                }
            });
        }
    }

    /**
     * Parmi `$columns`, celles qui ne sont PAS deja dans l'etat cible.
     *
     * @param  list<string>  $columns
     * @param  string  $deleteRule  regle ON DELETE visee
     * @param  bool  $nullable  nullabilite visee
     * @return list<string>
     */
    private function columnsNeedingChange(string $table, array $columns, string $deleteRule, bool $nullable): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $todo = [];

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $current = $this->foreignKeyRule($table, $column);
            $isNullable = $this->isNullable($table, $column);

            if ($current === $deleteRule && $isNullable === $nullable) {
                continue;
            }

            $todo[] = $column;
        }

        return $todo;
    }

    /**
     * Regle ON DELETE portee par la FK de cette colonne, ou null si aucune.
     *
     * Lue dans le catalogue du moteur — le nom de la contrainte n'entre pas
     * dans la recherche : c'est le contrat qui compte, pas son etiquette.
     */
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

    private function isNullable(string $table, string $column): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select('PRAGMA table_info('.$table.')') as $info) {
                if ($info->name === $column) {
                    return ! $info->notnull;
                }
            }

            return false;
        }

        $rows = DB::select(
            'SELECT is_nullable FROM information_schema.columns '
            .'WHERE table_name = ? AND column_name = ? AND table_schema = current_schema() LIMIT 1',
            [$table, $column]
        );

        return $rows !== [] && $rows[0]->is_nullable === 'YES';
    }
};
