<?php

namespace Tests\Feature;

use App\Support\AssignData\DatasetClassification;
use App\Support\AssignData\DatasetRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1631 — la garde contre la prochaine omission.
 *
 * L'outil assign-data est devenu incomplet SANS QUE PERSONNE NE LE SACHE :
 * son registre etait un tableau en dur, jamais confronte au schema. Le releve
 * l'a montre — 5 tables nullables ajoutees apres lui n'y figuraient pas
 * (`ai_credit_setting_changes`, `custom_loop_types`, `loop_type_settings`,
 * `system_email_templates`, `themes`), et 7 de ses entrees visaient au
 * contraire une colonne NOT NULL, dont le compteur ne pouvait qu'afficher
 * zero.
 *
 * Ces tests ferment les deux sens. Ils lisent le SCHEMA, pas une liste : une
 * table ajoutee demain les fait rougir d'elle-meme, avec son nom dans le
 * message.
 */
class TASK1631AssignDataSchemaCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatasetRegistry::flushCache();
    }

    /**
     * Le volet MORDANT : toute table dont `organization_id` est nullable doit
     * porter une classification explicite.
     */
    public function test_every_nullable_organization_id_table_is_classified(): void
    {
        $registry = app(DatasetRegistry::class);

        $this->assertSame(
            [],
            $registry->unclassifiedNullableTables(),
            "Ces tables portent un `organization_id` NULLABLE sans classification.\n"
            .'Choisissez-en une dans `DatasetRegistry::definitions()` — `AssignableTenant` '
            .'seulement si un NULL y est une anomalie, jamais par defaut.'
        );
    }

    /**
     * Le volet COHERENCE : une declaration doit correspondre a une table
     * reelle, ET a une colonne reellement nullable.
     *
     * Attrape une table renommee ou supprimee, et une classification posee
     * sur une colonne NOT NULL — cas ou le dataset ne pourrait jamais montrer
     * autre chose que zero, exactement les 7 entrees mortes de l'outil
     * historique.
     */
    public function test_no_declared_dataset_points_at_a_missing_or_not_null_column(): void
    {
        $this->assertSame(
            [],
            app(DatasetRegistry::class)->staleDeclarations(),
            'Ces datasets sont declares mais leur table n\'existe pas, ou son `organization_id` n\'est pas nullable.'
        );
    }

    /**
     * La liste BLANCHE doit nommer des colonnes qui existent.
     *
     * Une colonne disparue rendrait une vue detail vide sans rien dire.
     * (Ce test a effectivement attrape `service_images.original_name`, que
     * j'avais declaree alors que la colonne s'appelle `path`.)
     */
    public function test_every_declared_column_exists(): void
    {
        $this->assertSame([], app(DatasetRegistry::class)->missingColumns());
    }

    /**
     * La preuve que les tables NOT NULL sont hors perimetre par CONSTRUCTION
     * et non par oubli.
     *
     * C'est ce qui justifie de ne pas les declarer une a une : aucune d'elles
     * ne peut porter un NULL a rattacher. C'est aussi ce qui regle
     * `ai_provider_invocations` — ledger economique sans FK depuis TASK-1220 —
     * sans une ligne de code pour l'exclure, et sans aucune purge ni
     * reassignation.
     */
    public function test_a_not_null_column_can_never_hold_a_null_to_assign(): void
    {
        $registry = app(DatasetRegistry::class);
        $nullable = $registry->nullableTablesInSchema();

        $notNull = array_values(array_diff($this->allOrganizationIdTables(), $nullable));

        $this->assertNotEmpty($notNull, 'Le schema devrait porter des colonnes `organization_id` NOT NULL.');
        $this->assertContains('ai_provider_invocations', $notNull);

        foreach ($notNull as $table) {
            $this->assertSame(
                0,
                DB::table($table)->whereNull('organization_id')->count(),
                "La table {$table} porte un `organization_id` NOT NULL : elle ne peut pas contenir de NULL."
            );
        }
    }

    public function test_the_registry_covers_the_five_tables_the_old_tool_had_missed(): void
    {
        $registry = app(DatasetRegistry::class);

        // Les cinq angles morts mesures sur le schema : ajoutees apres
        // l'outil, jamais declarees, donc invisibles au diagnostic.
        foreach ([
            'ai_credit_setting_changes',
            'custom_loop_types',
            'loop_type_settings',
            'system_email_templates',
            'themes',
        ] as $key) {
            $this->assertNotNull($registry->find($key), "Le dataset {$key} devrait etre declare.");
        }
    }

    public function test_the_seven_dead_entries_of_the_old_tool_are_gone(): void
    {
        $registry = app(DatasetRegistry::class);

        // Ces sept tables ont un `organization_id` NOT NULL : le registre
        // historique les proposait a l'affectation avec un compteur
        // structurellement bloque a zero.
        foreach ([
            'blog_ai_configs',
            'bug_reports',
            'feed_post_comments',
            'feed_posts',
            'member_ai_profile_interactions',
            'member_ai_profiles',
            'reactions',
        ] as $key) {
            $this->assertNull($registry->find($key), "Le dataset {$key} vise une colonne NOT NULL et ne devrait plus etre declare.");
        }
    }

    /**
     * La preuve que la garde DECOUVRE, au lieu de comparer deux listes ecrites
     * a la main.
     *
     * Une liste figee confrontee a une autre liste figee serait verte pour
     * toujours — c'est exactement ce qui a laisse l'outil historique devenir
     * incomplet. Ici on cree une table pour de vrai, et on verifie qu'elle
     * remonte. Le test porte sur les DEUX moteurs : l'introspection passe par
     * `information_schema` en PostgreSQL et par `PRAGMA table_info` en SQLite,
     * deux chemins distincts qui doivent rendre la meme reponse.
     */
    public function test_the_guard_discovers_a_table_that_did_not_exist_when_it_was_written(): void
    {
        DatasetRegistry::flushCache();

        Schema::create('task1631_table_de_demain', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
        });

        try {
            $this->assertContains(
                'task1631_table_de_demain',
                app(DatasetRegistry::class)->unclassifiedNullableTables(),
                'Une table nullable ajoutee apres coup doit remonter, sinon la garde ne garde rien.'
            );
        } finally {
            Schema::dropIfExists('task1631_table_de_demain');
        }
    }

    /**
     * Et symetriquement : une nouvelle table NOT NULL ne cree AUCUNE friction,
     * parce qu'elle ne peut porter aucun NULL a rattacher.
     */
    public function test_a_new_not_null_table_does_not_demand_a_classification(): void
    {
        DatasetRegistry::flushCache();

        Schema::create('task1631_table_non_nullable', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
        });

        try {
            $this->assertNotContains(
                'task1631_table_non_nullable',
                app(DatasetRegistry::class)->unclassifiedNullableTables()
            );
        } finally {
            Schema::dropIfExists('task1631_table_non_nullable');
        }
    }

    /**
     * Le garde-fou du garde-fou : aucune classification autre que
     * `AssignableTenant` ne doit ouvrir une mutation.
     */
    public function test_only_the_tenant_classification_is_assignable(): void
    {
        foreach (DatasetClassification::cases() as $classification) {
            $this->assertSame(
                $classification === DatasetClassification::AssignableTenant,
                $classification->isAssignable(),
                "La classification {$classification->value} ne doit pas ouvrir de mutation."
            );
        }
    }

    /**
     * @return list<string>
     */
    private function allOrganizationIdTables(): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                SELECT c.table_name AS name
                FROM information_schema.columns c
                JOIN information_schema.tables t
                  ON t.table_name = c.table_name AND t.table_schema = c.table_schema
                WHERE c.column_name = 'organization_id'
                  AND c.table_schema = 'public'
                  AND t.table_type = 'BASE TABLE'
                ORDER BY c.table_name
            SQL);

            return array_map(fn ($r) => $r->name, $rows);
        }

        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        return collect($tables)
            ->map(fn ($t) => $t->name)
            ->filter(fn (string $name) => Schema::hasColumn($name, 'organization_id'))
            ->values()
            ->all();
    }
}
