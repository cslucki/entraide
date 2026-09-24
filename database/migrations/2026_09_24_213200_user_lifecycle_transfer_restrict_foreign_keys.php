<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1635 (M3/3) — USER LIFECYCLE : les vraies PROPRIETES transferables.
 *
 * ## Le probleme
 *
 * `UserDataLifecycleRegistry` classe ces 4 colonnes en TRANSFER : ce sont des
 * biens qui appartiennent a quelqu'un et qui doivent CHANGER de proprietaire
 * quand ce quelqu'un s'en va. Un article de blog, une publication de fil, un
 * service offert, une demande de service : chacun a un auteur, et un autre
 * User peut legitimement le reprendre.
 *
 * Le schema disait `ON DELETE CASCADE` : supprimer le User detruisait le bien
 * au lieu de le transmettre. La policy n'etait tenue par personne.
 *
 *     ON DELETE CASCADE   ->   ON DELETE RESTRICT
 *
 * ## Pourquoi RESTRICT et pas SET NULL
 *
 * Ces colonnes sont `NOT NULL` et le restent : un article sans auteur n'existe
 * pas dans ce produit. On ne peut donc pas les detacher — il faut les
 * TRANSFERER, ce qui est une decision applicative (vers QUI ?) qu'une
 * contrainte de base ne peut pas prendre.
 *
 * RESTRICT transforme cette impossibilite en refus bruyant : tant que
 * TASK-1636 n'a pas explicitement transfere ces proprietes vers un autre User,
 * la suppression ECHOUE. Aucune donnee n'est perdue par defaut.
 *
 * **Aucun transfert reel n'est execute ici.** Cette migration ne deplace pas
 * une seule ligne : elle installe le filet de securite qui rend le transfert
 * obligatoire.
 *
 * ## Portabilite
 *
 * Identique a M1 et M2 : FK presentes sur les deux moteurs, reconstruction
 * SQLite par Laravel 13, index uniques et cles voisines preserves.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'blog_posts' => ['user_id'],
        'feed_posts' => ['user_id'],
        'services' => ['user_id'],
        'service_requests' => ['user_id'],
    ];

    public function up(): void
    {
        $this->applyRule('RESTRICT');
    }

    public function down(): void
    {
        $this->applyRule('CASCADE');
    }

    /**
     * Repose la FK avec la regle visee, sans toucher a la nullabilite.
     */
    private function applyRule(string $deleteRule): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $todo = $this->columnsNeedingChange($table, $columns, $deleteRule);

            if ($todo === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($todo, $deleteRule) {
                foreach ($todo as $column) {
                    $blueprint->dropForeign([$column]);

                    $foreign = $blueprint->foreign($column)->references('id')->on('users');

                    $deleteRule === 'RESTRICT'
                        ? $foreign->restrictOnDelete()
                        : $foreign->cascadeOnDelete();
                }
            });
        }
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function columnsNeedingChange(string $table, array $columns, string $deleteRule): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $todo = [];

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            if ($this->foreignKeyRule($table, $column) === $deleteRule) {
                continue;
            }

            $todo[] = $column;
        }

        return $todo;
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
