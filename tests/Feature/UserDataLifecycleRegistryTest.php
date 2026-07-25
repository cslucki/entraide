<?php

namespace Tests\Feature;

use App\Services\UserDataLifecycleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserDataLifecycleRegistryTest extends TestCase
{
    public function test_every_user_foreign_key_is_declared_in_lifecycle_registry(): void
    {
        $actualForeignKeys = $this->userForeignKeys();
        $registeredForeignKeys = collect(UserDataLifecycleRegistry::sqlRegistryPairs());

        $this->assertSame(
            [],
            $actualForeignKeys->diff($registeredForeignKeys)->values()->all(),
            'Every FK to users.id must be classified in UserDataLifecycleRegistry.'
        );

        $this->assertSame(
            [],
            $registeredForeignKeys
                ->filter(fn (string $pair) => $this->schemaHasRegisteredPair($pair))
                ->diff($actualForeignKeys)
                ->values()
                ->all(),
            'Registered SQL entries whose table/column exists must still be real FKs to users.id.'
        );
    }

    public function test_non_sql_lifecycle_surfaces_are_declared_with_policy_and_justification(): void
    {
        $entries = collect(UserDataLifecycleRegistry::nonSqlEntries());

        $this->assertNotEmpty($entries);
        $this->assertContains('sessions.user_id', $entries->pluck('surface')->all());
        $this->assertContains('dossier_chunks.content/embedding', $entries->pluck('surface')->all());

        $entries->each(function (array $entry): void {
            $this->assertNotEmpty($entry['key'] ?? null);
            $this->assertNotEmpty($entry['surface'] ?? null);
            $this->assertContains($entry['policy'] ?? null, [
                UserDataLifecycleRegistry::POLICY_TRANSFER,
                UserDataLifecycleRegistry::POLICY_DETACH,
                UserDataLifecycleRegistry::POLICY_DELETE,
                UserDataLifecycleRegistry::POLICY_ANONYMIZE,
                UserDataLifecycleRegistry::POLICY_RETAIN,
                UserDataLifecycleRegistry::POLICY_BLOCK,
            ]);
            $this->assertNotEmpty($entry['org_scope'] ?? null);
            $this->assertNotEmpty($entry['justification'] ?? null);
        });
    }

    public function test_sql_lifecycle_entries_are_declared_with_policy_scope_and_existing_columns(): void
    {
        collect(UserDataLifecycleRegistry::entries())
            ->where('type', 'sql')
            ->each(function (array $entry): void {
                $this->assertNotEmpty($entry['key'] ?? null);
                $this->assertNotEmpty($entry['table'] ?? null);
                $this->assertNotEmpty($entry['column'] ?? null);
                $this->assertNotEmpty($entry['policy'] ?? null);
                $this->assertNotEmpty($entry['org_scope'] ?? null);
                $this->assertNotEmpty($entry['justification'] ?? null);

                if (($entry['org_scope'] ?? null) === 'legacy' && ! Schema::hasTable($entry['table'])) {
                    return;
                }

                $this->assertTrue(Schema::hasTable($entry['table']), "Missing lifecycle table [{$entry['table']}].");
                $this->assertTrue(Schema::hasColumn($entry['table'], $entry['column']), "Missing lifecycle column [{$entry['table']}.{$entry['column']}].");
            });
    }

    private function userForeignKeys()
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => $this->sqliteUserForeignKeys(),
            'pgsql' => $this->pgsqlUserForeignKeys(),
            default => collect(),
        };
    }

    private function sqliteUserForeignKeys()
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->flatMap(function (string $table) {
                return collect(DB::select('PRAGMA foreign_key_list('.$this->quoteSqliteIdentifier($table).')'))
                    ->filter(fn (object $foreignKey) => $foreignKey->table === 'users' && $foreignKey->to === 'id')
                    ->map(fn (object $foreignKey) => $table.'.'.$foreignKey->from);
            })
            ->sort()
            ->values();
    }

    private function pgsqlUserForeignKeys()
    {
        return collect(DB::select(<<<'SQL'
            select
                kcu.table_name || '.' || kcu.column_name as pair
            from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu
                on tc.constraint_name = kcu.constraint_name
                and tc.table_schema = kcu.table_schema
            join information_schema.constraint_column_usage ccu
                on ccu.constraint_name = tc.constraint_name
                and ccu.table_schema = tc.table_schema
            where tc.constraint_type = 'FOREIGN KEY'
                and ccu.table_name = 'users'
                and ccu.column_name = 'id'
                and tc.table_schema = current_schema()
            order by kcu.table_name, kcu.column_name
        SQL))
            ->pluck('pair')
            ->values();
    }

    private function schemaHasRegisteredPair(string $pair): bool
    {
        [$table, $column] = explode('.', $pair, 2);

        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
