<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\UserDataLifecycleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserDataLifecycleRegistryTest extends TestCase
{
    use RefreshDatabase;

    // Deja rouge sur `develop` avant TASK-1112. Exclue du gate GitHub pour
    // qu'il puisse signifier quelque chose ; **le groupe doit se vider**, il
    // n'est pas un endroit ou ranger un test qui gene.
    #[\PHPUnit\Framework\Attributes\Group('ci-known-red')]
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

    public function test_corrected_lifecycle_policies_are_exact(): void
    {
        $this->assertSame(UserDataLifecycleRegistry::POLICY_DELETE, $this->registryEntry('favorites')['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_ANONYMIZE, $this->registryEntry('blog_comments')['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_ANONYMIZE, $this->registryEntry('feed_post_comments')['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_BLOCK, $this->registryEntry('point_ledger')['policy']);

        $this->assertSame(UserDataLifecycleRegistry::POLICY_TRANSFER, $this->registryEntry('blog_posts')['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_TRANSFER, $this->registryEntry('feed_posts')['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_TRANSFER, $this->registryEntry('services')['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_TRANSFER, $this->registryEntry('service_requests')['policy']);
    }

    public function test_preview_groups_corrected_policies_in_expected_buckets(): void
    {
        [$organization, $user] = $this->organizationAndUser();
        $this->insertLifecycleRows($organization, $user);

        $preview = app(UserDataLifecycleRegistry::class)->preview($user, $organization);

        $this->assertSame(1, $preview['delete']['favorites']);
        $this->assertSame(1, $preview['audit']['blog_comments']);
        $this->assertSame(1, $preview['audit']['feed_post_comments']);
        $this->assertSame(1, $preview['block']['point_ledger']);
        $this->assertSame(1, $preview['own']['services']);
        $this->assertSame(1, $preview['own']['blog_posts']);
        $this->assertSame(1, $preview['own']['feed_posts']);
    }

    public function test_global_preview_counts_all_user_rows_and_orgadmin_preview_is_organization_scoped(): void
    {
        [$organization, $user] = $this->organizationAndUser();
        $otherOrganization = Organization::factory()->create();

        $this->insertLifecycleRows($organization, $user);
        $this->insertLifecycleRows($otherOrganization, $user);

        $registry = app(UserDataLifecycleRegistry::class);
        $globalPreview = $registry->preview($user);
        $orgPreview = $registry->preview($user, $organization);

        $this->assertSame(2, $globalPreview['block']['point_ledger']);
        $this->assertSame(2, $globalPreview['audit']['blog_comments']);
        $this->assertSame(2, $globalPreview['delete']['favorites']);

        $this->assertSame(1, $orgPreview['block']['point_ledger']);
        $this->assertSame(1, $orgPreview['audit']['blog_comments']);
        $this->assertSame(1, $orgPreview['delete']['favorites']);
    }

    public function test_orgadmin_preview_does_not_leak_rows_from_another_organization(): void
    {
        [$organization, $user] = $this->organizationAndUser();
        $otherOrganization = Organization::factory()->create();

        $this->insertLifecycleRows($organization, $user);
        $this->insertLifecycleRows($otherOrganization, $user);

        $preview = app(UserDataLifecycleRegistry::class)->preview($user, $organization);

        $this->assertSame(1, $preview['own']['services']);
        $this->assertSame(1, $preview['own']['blog_posts']);
        $this->assertSame(1, $preview['own']['feed_posts']);
        $this->assertSame(1, $preview['audit']['blog_comments']);
        $this->assertSame(1, $preview['audit']['feed_post_comments']);
        $this->assertSame(1, $preview['delete']['favorites']);
        $this->assertSame(1, $preview['block']['point_ledger']);
    }

    public function test_point_ledger_rows_are_reported_in_block_group(): void
    {
        [$organization, $user] = $this->organizationAndUser();
        $this->insertPointLedger($organization, $user);

        $preview = app(UserDataLifecycleRegistry::class)->preview($user, $organization);

        $this->assertArrayHasKey('point_ledger', $preview['block']);
        $this->assertSame(1, $preview['block']['point_ledger']);
    }

    public function test_preview_and_transfer_estimate_do_not_mutate_data(): void
    {
        [$organization, $user] = $this->organizationAndUser();
        $target = User::factory()->for($organization)->create();
        $this->insertLifecycleRows($organization, $user);

        $tables = ['users', 'services', 'blog_posts', 'blog_comments', 'feed_posts', 'feed_post_comments', 'favorites', 'point_ledger'];
        $before = $this->tableCounts($tables);

        $registry = app(UserDataLifecycleRegistry::class);
        $registry->preview($user, $organization);
        $registry->transferEstimate($user, $target->id, $organization);

        $this->assertSame($before, $this->tableCounts($tables));
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

    private function registryEntry(string $key): array
    {
        $entry = collect(UserDataLifecycleRegistry::entries())->firstWhere('key', $key);

        $this->assertNotNull($entry, "Missing lifecycle registry entry [{$key}].");

        return $entry;
    }

    private function organizationAndUser(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->for($organization)->create();

        return [$organization, $user];
    }

    private function insertLifecycleRows(Organization $organization, User $user): void
    {
        $categoryId = $this->insertCategory($organization);
        $serviceId = $this->insertService($organization, $user, $categoryId);
        $blogPostId = $this->insertBlogPost($organization, $user, $categoryId);
        $feedPostId = $this->insertFeedPost($organization, $user);

        $this->insertFavorite($organization, $user, $serviceId);
        $this->insertBlogComment($organization, $user, $blogPostId);
        $this->insertFeedPostComment($organization, $user, $feedPostId);
        $this->insertPointLedger($organization, $user);
    }

    private function insertCategory(Organization $organization): string
    {
        $id = (string) Str::uuid();

        DB::table('categories')->insert([
            'id' => $id,
            'organization_id' => $organization->id,
            'name_b2c' => 'Lifecycle test',
            'name_b2b' => 'Lifecycle test',
            'slug' => 'lifecycle-test-'.Str::random(8),
            'color' => '#6366f1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertService(Organization $organization, User $user, string $categoryId): string
    {
        $id = (string) Str::uuid();

        DB::table('services')->insert([
            'id' => $id,
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'title' => 'Lifecycle service',
            'description' => 'Lifecycle service description',
            'delivery_mode' => 'remote',
            'points_cost' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertBlogPost(Organization $organization, User $user, string $categoryId): string
    {
        $id = (string) Str::uuid();

        DB::table('blog_posts')->insert([
            'id' => $id,
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'title' => 'Lifecycle post',
            'slug' => 'lifecycle-post-'.Str::random(8),
            'content' => 'Lifecycle post content',
            'status' => 'published',
            'views_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertBlogComment(Organization $organization, User $user, string $blogPostId): void
    {
        DB::table('blog_comments')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'blog_post_id' => $blogPostId,
            'user_id' => $user->id,
            'content' => 'Lifecycle blog comment',
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertFeedPost(Organization $organization, User $user): string
    {
        $id = (string) Str::uuid();

        DB::table('feed_posts')->insert([
            'id' => $id,
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'type' => 'announcement',
            'content' => 'Lifecycle feed post',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertFeedPostComment(Organization $organization, User $user, string $feedPostId): void
    {
        DB::table('feed_post_comments')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'feed_post_id' => $feedPostId,
            'user_id' => $user->id,
            'content' => 'Lifecycle feed comment',
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertFavorite(Organization $organization, User $user, string $serviceId): void
    {
        DB::table('favorites')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'service_id' => $serviceId,
            'created_at' => now(),
        ]);
    }

    private function insertPointLedger(Organization $organization, User $user): void
    {
        DB::table('point_ledger')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'delta' => 10,
            'reason' => 'adjustment',
            'created_at' => now(),
        ]);
    }

    private function tableCounts(array $tables): array
    {
        return collect($tables)
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
