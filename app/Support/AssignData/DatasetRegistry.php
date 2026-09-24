<?php

namespace App\Support\AssignData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1631 — LA source de verite de l'outil assign-data.
 *
 * ## Ce qu'elle remplace
 *
 * Un tableau de 40 entrees en dur dans `AdminOutilsController`, jamais
 * confronte au schema, ou 38 entrees sur 40 etaient marquees « assignable ».
 * Le releve sur la base reelle a montre l'ecart :
 *
 *   · **110 tables** portent `organization_id`, dont **38 seulement** l'ont
 *     NULLABLE ;
 *   · **7 entrees** du registre historique visaient une colonne NOT NULL —
 *     leur compteur « sans organisation » valait structurellement 0 et ne
 *     pouvait jamais bouger ;
 *   · **5 tables nullables** manquaient : `ai_credit_setting_changes`,
 *     `custom_loop_types`, `loop_type_settings`, `system_email_templates`,
 *     `themes`. Ajoutees apres l'outil, jamais declarees.
 *
 * ## La garde contre la prochaine omission
 *
 * `unclassifiedNullableTables()` introspecte le schema et rend les tables
 * nullables absentes d'ici. Un test la lit et rougit. Ajouter demain une
 * table avec un `organization_id` nullable oblige donc a CHOISIR une
 * classification, au lieu de laisser l'outil devenir silencieusement
 * incomplet comme il l'est devenu.
 *
 * Decouverte automatique : oui. Autorisation automatique de mutation : non.
 * L'introspection alimente le diagnostic ; seule une entree declaree
 * `AssignableTenant` ici ouvre une mutation, et jamais une table decouverte.
 *
 * ## Pourquoi les tables NOT NULL ne sont pas declarees
 *
 * `organization_id IS NULL` y est impossible — la contrainte le garantit, et
 * un test le PROUVE plutot que de le supposer. Les declarer ferait 72 lignes
 * de bruit dont aucune ne pourrait jamais montrer autre chose que zero. Le
 * risque reel est la table NULLABLE oubliee, et c'est elle que la garde vise.
 * C'est aussi ce qui regle `ai_provider_invocations` (ledger economique, sans
 * FK depuis TASK-1220) : NOT NULL, donc hors perimetre par construction, sans
 * une ligne de code pour l'exclure.
 */
class DatasetRegistry
{
    /**
     * @var array<string, Dataset>|null
     */
    private static ?array $cache = null;

    /**
     * @return array<string, Dataset>
     */
    public function all(): array
    {
        return self::$cache ??= collect($this->definitions())
            ->mapWithKeys(fn (Dataset $d) => [$d->key => $d])
            ->all();
    }

    public function find(string $key): ?Dataset
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, Dataset>
     */
    public function assignable(): array
    {
        return array_filter($this->all(), fn (Dataset $d) => $d->isAssignable());
    }

    /**
     * Les tables du schema dont `organization_id` est NULLABLE.
     *
     * Lu sur la base, jamais sur les migrations : une colonne modifiee par
     * une migration ulterieure ne se voit que la.
     *
     * @return list<string>
     */
    public function nullableTablesInSchema(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                SELECT c.table_name AS name
                FROM information_schema.columns c
                JOIN information_schema.tables t
                  ON t.table_name = c.table_name AND t.table_schema = c.table_schema
                WHERE c.column_name = 'organization_id'
                  AND c.table_schema = 'public'
                  AND c.is_nullable = 'YES'
                  AND t.table_type = 'BASE TABLE'
                ORDER BY c.table_name
            SQL);

            return array_map(fn ($r) => $r->name, $rows);
        }

        // SQLite : pas d'`information_schema`. On demande la liste des tables
        // puis leur description colonne par colonne — plus bavard, mais c'est
        // la seule maniere d'obtenir la meme reponse sur le moteur de la CI
        // legere, et la garde doit mordre sur LES DEUX.
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        $nullable = [];

        foreach ($tables as $table) {
            foreach (DB::select('PRAGMA table_info('.$table->name.')') as $column) {
                if ($column->name === 'organization_id' && (int) $column->notnull === 0) {
                    $nullable[] = $table->name;
                }
            }
        }

        return $nullable;
    }

    /**
     * Le volet MORDANT de la garde : ce que le schema porte et que personne
     * n'a classe.
     *
     * @return list<string>
     */
    public function unclassifiedNullableTables(): array
    {
        $declared = array_map(fn (Dataset $d) => $d->table, $this->all());

        return array_values(array_diff($this->nullableTablesInSchema(), $declared));
    }

    /**
     * Le volet COHERENCE : ce qui est declare ici mais que le schema ne porte
     * pas — table renommee, table supprimee, ou classification posee sur une
     * colonne NOT NULL (auquel cas elle ne pourra jamais rien montrer).
     *
     * @return list<string>
     */
    public function staleDeclarations(): array
    {
        $nullable = $this->nullableTablesInSchema();

        return collect($this->all())
            ->map(fn (Dataset $d) => $d->table)
            ->reject(fn (string $table) => in_array($table, $nullable, true))
            ->values()
            ->all();
    }

    /**
     * Les colonnes declarees qui n'existent pas dans la table.
     *
     * Une liste blanche qui nomme une colonne disparue rend une vue detail
     * vide sans rien dire ; ici elle rend un rouge avec son nom.
     *
     * @return array<string, list<string>>
     */
    public function missingColumns(): array
    {
        $missing = [];

        foreach ($this->all() as $dataset) {
            if (! Schema::hasTable($dataset->table)) {
                continue;
            }

            $existing = Schema::getColumnListing($dataset->table);
            $absent = array_values(array_diff($dataset->columns, $existing));

            if ($absent !== []) {
                $missing[$dataset->key] = $absent;
            }
        }

        return $missing;
    }

    /**
     * Les 38 tables dont `organization_id` est nullable, et ce qu'un NULL y
     * signifie.
     *
     * La preuve de chaque classification est dans le TASK file, table par
     * table. En resume : `HasOrganizationId` sur le modele prouve le tenant
     * (le trait remplit la colonne depuis l'Organization courante a la
     * creation, donc un NULL ne peut venir que d'avant le tenancy) ; une
     * migration ou un index partiel qui dit « null = Plateforme » prouve le
     * global.
     *
     * @return list<Dataset>
     */
    private function definitions(): array
    {
        $t = DatasetClassification::AssignableTenant;
        $g = DatasetClassification::GlobalNullValid;
        $h = DatasetClassification::HistoryNullValid;
        $d = DatasetClassification::DiagnosticOnly;

        return [
            // ── Tenant : le modele porte `HasOrganizationId` ──────────────
            new Dataset('users', 'users', $t, ['id', 'first_name', 'name', 'email', 'organization_id', 'created_at'], critical: true),
            new Dataset('services', 'services', $t, ['id', 'title', 'user_id', 'status', 'organization_id', 'created_at']),
            new Dataset('service_requests', 'service_requests', $t, ['id', 'title', 'user_id', 'status', 'organization_id', 'created_at']),
            new Dataset('service_images', 'service_images', $t, ['id', 'service_id', 'path', 'order', 'organization_id', 'created_at']),
            new Dataset('service_skill', 'service_skill', $t, ['service_id', 'skill_id', 'organization_id']),
            new Dataset('service_tag', 'service_tag', $t, ['service_id', 'tag_id', 'organization_id']),
            new Dataset('request_attachments', 'request_attachments', $t, ['id', 'service_request_id', 'original_name', 'organization_id', 'created_at']),
            new Dataset('reviews', 'reviews', $t, ['id', 'transaction_id', 'rating', 'organization_id', 'created_at']),
            new Dataset('transactions', 'transactions', $t, ['id', 'service_id', 'status', 'organization_id', 'created_at']),
            new Dataset('point_ledger', 'point_ledger', $t, ['id', 'user_id', 'delta', 'reason', 'organization_id', 'created_at']),
            new Dataset('messages', 'messages', $t, ['id', 'transaction_id', 'sender_id', 'type', 'organization_id', 'created_at']),
            new Dataset('loops', 'loops', $t, ['id', 'name', 'slug', 'status', 'organization_id', 'created_at']),
            new Dataset('loop_members', 'loop_members', $t, ['id', 'loop_id', 'user_id', 'role', 'status', 'organization_id']),
            new Dataset('loop_messages', 'loop_messages', $t, ['id', 'loop_id', 'sender_id', 'type', 'organization_id', 'created_at']),
            new Dataset('blog_posts', 'blog_posts', $t, ['id', 'title', 'slug', 'status', 'organization_id', 'created_at']),
            new Dataset('blog_comments', 'blog_comments', $t, ['id', 'blog_post_id', 'user_id', 'is_approved', 'organization_id', 'created_at']),
            new Dataset('blog_post_tag', 'blog_post_tag', $t, ['blog_post_id', 'tag_id', 'organization_id']),
            new Dataset('reports', 'reports', $t, ['id', 'reporter_id', 'reportable_type', 'status', 'organization_id', 'created_at']),
            new Dataset('referrals', 'referrals', $t, ['id', 'referrer_user_id', 'status', 'organization_id', 'created_at']),
            new Dataset('referral_rewards', 'referral_rewards', $t, ['id', 'referral_id', 'event_type', 'points', 'organization_id', 'created_at']),
            new Dataset('favorites', 'favorites', $t, ['id', 'user_id', 'service_id', 'organization_id', 'created_at']),
            new Dataset('likes', 'likes', $t, ['id', 'user_id', 'likeable_type', 'organization_id', 'created_at']),
            new Dataset('tags', 'tags', $t, ['id', 'name', 'slug', 'organization_id', 'created_at']),
            new Dataset('email_templates', 'email_templates', $t, ['id', 'slug', 'name', 'subject', 'organization_id', 'created_at']),
            new Dataset('email_logs', 'email_logs', $t, ['id', 'to_email', 'subject', 'status', 'organization_id', 'created_at']),

            // `themes` n'a pas le trait, mais sa migration d'ajout a elle-meme
            // rattache tous les NULL existants a l'Organization principale :
            // la table est tenant par intention, un NULL y est residuel.
            new Dataset('themes', 'themes', $t, ['id', 'key', 'label', 'is_default', 'organization_id', 'created_at']),

            // ── Global : NULL signifie « Plateforme » ────────────────────
            new Dataset('categories', 'categories', $g, ['id', 'name_b2c', 'name_b2b', 'slug', 'organization_id', 'created_at']),
            new Dataset('skills', 'skills', $g, ['id', 'category_id', 'name', 'slug', 'organization_id', 'created_at']),
            new Dataset('badges', 'badges', $g, ['id', 'key', 'name', 'organization_id', 'created_at']),
            new Dataset('point_guidelines', 'point_guidelines', $g, ['id', 'category_id', 'level', 'points_min', 'points_max', 'organization_id']),
            new Dataset('custom_loop_types', 'custom_loop_types', $g, ['id', 'key', 'label', 'based_on', 'available', 'organization_id', 'created_at']),
            new Dataset('loop_type_settings', 'loop_type_settings', $g, ['id', 'loop_type', 'label', 'available', 'organization_id', 'created_at']),
            new Dataset('translation_overrides', 'translation_overrides', $g, ['id', 'locale', 'group', 'key', 'is_active', 'organization_id', 'created_at']),
            new Dataset('ai_credit_setting_changes', 'ai_credit_setting_changes', $g, ['id', 'scope', 'setting_kind', 'changed_by', 'organization_id', 'created_at']),
            new Dataset('system_email_templates', 'system_email_templates', $g, ['id', 'slug', 'name', 'locale', 'enabled', 'organization_id', 'created_at']),
            new Dataset('admin_ai_interactions', 'admin_ai_interactions', $g, ['id', 'user_id', 'provider', 'model', 'status', 'organization_id', 'created_at']),

            // ── Historique : une trace, on la garde ──────────────────────
            new Dataset('ai_interactions', 'ai_interactions', $h, ['id', 'user_id', 'feature', 'model', 'organization_id', 'created_at']),

            // ── Diagnostic : montre, jamais offert ───────────────────────
            // Pivot entre un catalogue GLOBAL (`badges`) et un utilisateur
            // TENANT : l'affectation n'a pas de semantique claire.
            new Dataset('badge_user', 'badge_user', $d, ['badge_id', 'user_id', 'earned_at', 'organization_id']),
        ];
    }

    /** Les tests rejouent des schemas differents dans le meme processus. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
