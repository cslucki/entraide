<?php

namespace Tests\Feature;

use App\Ai\ResolvedModel;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\SupervisionEconomicScope;
use App\Services\Ai\SupervisionProviderResolver;
use App\Services\UserDataLifecycleRegistry;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiUsage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1254 — ledger d'invocation economique complet V1 : RETENTION.
 *
 * Le ledger canonique `ai_provider_invocations` portait une FK
 * `ON DELETE CASCADE` vers `organizations` : supprimer un tenant effacait toute
 * son histoire economique, alors que `user_id` (sans FK) survivait deja a la
 * suppression du compte. Un ledger economique durable ne peut dependre ni de
 * la vie du compte ni de celle du tenant (gap analysis T1246, G12).
 *
 *  - A. la ligne ledger SURVIT a la suppression de son Organization (prouve
 *    AVANT la migration — le CASCADE effacait — et APRES : la FK est retiree,
 *    la ligne ne bouge pas, l'uuid du tenant reste lisible) et de son acteur ;
 *  - B. `UserDataLifecycleRegistry` dit la VERITE (G13) : `ai_interactions`
 *    est DELETE parce que son schema cascade reellement ; le ledger est
 *    declare RETAIN et n'a, par conception, aucune FK sur aucun axe ; l'apercu
 *    de suppression le compte, scope au tenant ;
 *  - C. metadonnees sans secret ni contenu : le schema du ledger n'a
 *    structurellement aucune colonne de contenu ou de metadonnees libres, et
 *    une ligne `failed` porte une CLASSE d'exception, jamais un message.
 */
class TASK1254LedgerRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_08_19_150000_drop_organization_foreign_key_from_ai_provider_invocations.php';

    // =====================================================================
    // A. La ligne ledger survit a la suppression du tenant et de l'acteur
    // =====================================================================

    public function test_a_ledger_line_survives_the_force_deletion_of_its_organization(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $organization->id]);
        $tenantId = (string) $organization->id;

        $generation = $this->generation($organization, $actor);
        $embedding = $this->embedding($organization, $actor);

        $organization->forceDelete();

        $this->assertDatabaseMissing('organizations', ['id' => $tenantId]);

        // Les deux lignes existent toujours, avec le MEME tenant de record :
        // ni effacees (ancien CASCADE), ni reecrites (un SET NULL l'aurait fait).
        $this->assertSame(2, AiProviderInvocation::query()->where('organization_id', $tenantId)->count());
        $this->assertSame($tenantId, (string) $generation->fresh()->organization_id);
        $this->assertSame($tenantId, (string) $embedding->fresh()->organization_id);
        $this->assertNull($generation->fresh()->organization);

        // Et les mesures economiques sont intactes : l'histoire reste attribuable.
        $this->assertSame('known', $generation->fresh()->cost_status);
        $this->assertSame(12, $generation->fresh()->input_tokens);
    }

    public function test_before_this_task_the_cascade_erased_the_tenant_history_and_the_migration_is_reversible(): void
    {
        $migration = $this->migration();

        // AVANT (down = FK CASCADE remise) : supprimer le tenant efface le ledger.
        $migration->down();
        $this->assertTrue($this->ledgerHasForeignKeyTo('organizations'));

        $before = Organization::factory()->create();
        $beforeId = (string) $before->id;
        $this->generation($before, null);
        $this->assertSame(1, AiProviderInvocation::query()->where('organization_id', $beforeId)->count());

        $before->forceDelete();

        $this->assertSame(0, AiProviderInvocation::query()->where('organization_id', $beforeId)->count(),
            'Avant TASK-1254, la FK CASCADE effacait l\'histoire economique du tenant.');

        // APRES (up = FK retiree) : la meme operation laisse la ligne intacte.
        $migration->up();
        $this->assertFalse($this->ledgerHasForeignKeyTo('organizations'));

        $after = Organization::factory()->create();
        $afterId = (string) $after->id;
        $this->generation($after, null);

        $after->forceDelete();

        $this->assertSame(1, AiProviderInvocation::query()->where('organization_id', $afterId)->count());
        // Les colonnes, index et contraintes utiles survivent a la reconstruction
        // SQLite / au DROP CONSTRAINT PostgreSQL : NOT NULL tenu, index presents.
        $this->assertContains('feature', Schema::getColumnListing('ai_provider_invocations'));
        $this->assertContains('ai_provider_invocations_organization_id_created_at_index', $this->ledgerIndexNames());
        $this->assertContains('ai_provider_invocations_correlation_id_index', $this->ledgerIndexNames());
    }

    public function test_the_ledger_refuses_a_line_without_tenant_of_record(): void
    {
        // Sans FK, le schema continue d'exiger un tenant : NOT NULL est conserve.
        $this->expectException(QueryException::class);

        DB::table('ai_provider_invocations')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => null,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_ledger_line_survives_the_deletion_of_its_actor(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $organization->id]);
        $actorId = (string) $actor->id;

        $line = $this->generation($organization, $actor);

        // Le schema est ce qui s'execute le jour ou un DELETE users part.
        DB::table('users')->where('id', $actorId)->delete();

        $this->assertDatabaseMissing('users', ['id' => $actorId]);
        $this->assertSame($actorId, (string) $line->fresh()->user_id);
        $this->assertNull($line->fresh()->user);
        $this->assertSame((string) $organization->id, (string) $line->fresh()->organization_id);
    }

    // =====================================================================
    // B. Le registre du cycle de vie dit la verite (G13)
    // =====================================================================

    public function test_the_registry_declares_ai_interactions_deleted_because_the_schema_really_cascades(): void
    {
        $entry = $this->registryEntry('ai_interactions');

        $this->assertSame(UserDataLifecycleRegistry::POLICY_DELETE, $entry['policy']);
        $this->assertSame('sql', $entry['type']);

        // Et le schema fait exactement cela : un DELETE users efface la trace.
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $interaction = AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'feature' => 'loop_summary',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'prompt personnel',
            'response' => 'reponse',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => 0.0001,
            'cost_unknown' => false,
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertDatabaseMissing('ai_interactions', ['id' => $interaction->id]);
    }

    public function test_the_registry_declares_the_ledger_retained_and_the_schema_has_no_foreign_key_on_either_axis(): void
    {
        $entry = $this->registryEntry('ai_provider_invocations');

        $this->assertSame(UserDataLifecycleRegistry::POLICY_RETAIN, $entry['policy']);
        // `non_sql` = surface sans FK vers users (comme `sessions`) : le test
        // de garde du registre exige qu'une entree `sql` soit une vraie FK.
        $this->assertSame('non_sql', $entry['type']);
        $this->assertSame('direct', $entry['org_scope']);
        $this->assertSame(['table' => 'ai_provider_invocations', 'column' => 'user_id'], $entry['count']);
        $this->assertStringContainsString('organization_id', $entry['surface']);
        $this->assertGreaterThan(20, strlen($entry['justification']));

        // La verite du schema : aucune FK, ni vers users, ni vers organizations.
        $this->assertFalse($this->ledgerHasForeignKeyTo('users'));
        $this->assertFalse($this->ledgerHasForeignKeyTo('organizations'));
        $this->assertSame([], $this->ledgerForeignKeyTargets()->all(), 'Le ledger ne porte aucune FK.');
        $this->assertNotContains('ai_provider_invocations.user_id', UserDataLifecycleRegistry::sqlRegistryPairs());
    }

    public function test_the_deletion_preview_counts_the_ledger_as_retained_and_scopes_it_to_the_tenant(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $orgA->id]);

        $this->generation($orgA, $user);
        $this->embedding($orgA, $user);
        $this->generation($orgB, $user);
        // Une ligne d'un autre acteur dans le meme tenant : jamais comptee pour ce user.
        $this->generation($orgA, User::factory()->create(['organization_id' => $orgA->id]));

        $registry = app(UserDataLifecycleRegistry::class);

        $global = $registry->preview($user);
        $this->assertSame(3, $global['retain']['ai_provider_invocations']);
        $this->assertSame(3, $global['policies'][UserDataLifecycleRegistry::POLICY_RETAIN]['ai_provider_invocations']);

        $scoped = $registry->preview($user, $orgA);
        $this->assertSame(2, $scoped['retain']['ai_provider_invocations']);

        // `ai_interactions` a change de panier : supprimable, plus « anonymisable ».
        $this->assertArrayHasKey('ai_interactions', $global['delete']);
        $this->assertArrayNotHasKey('ai_interactions', $global['audit']);

        // L'apercu ne touche a rien.
        $this->assertSame(4, AiProviderInvocation::query()->count());
    }

    public function test_the_preview_labels_exist_in_both_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->assertNotSame('admin.user_data_ai_provider_invocations', __('admin.user_data_ai_provider_invocations', [], $locale), $locale);
            $this->assertNotSame('admin.user_data_ai_interactions', __('admin.user_data_ai_interactions', [], $locale), $locale);
        }
    }

    // =====================================================================
    // C. Metadonnees : ni secret, ni contenu — structurellement
    // =====================================================================

    public function test_the_ledger_schema_has_no_free_content_or_metadata_column(): void
    {
        $columns = collect(Schema::getColumnListing('ai_provider_invocations'))->sort()->values()->all();

        // Liste FERMEE : ajouter une colonne au ledger (a fortiori un JSON
        // libre) exige de revenir ici consciemment.
        $this->assertSame([
            'capability', 'completed_at', 'correlation_id', 'cost_source', 'cost_status', 'created_at',
            'credential_source', 'currency', 'embedding_count', 'embedding_dimensions', 'embedding_operation',
            'failure_reason', 'feature', 'id', 'input_tokens', 'model', 'operation', 'organization_id',
            'output_tokens', 'process', 'provider', 'provider_cost', 'provider_invocation_id',
            'sdk_invocation_id', 'started_at', 'status', 'total_tokens', 'updated_at', 'user_id',
        ], $columns);

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/metadata|prompt|response|content|payload|excerpt|question|message|secret|api_key|credential$/i',
                $column,
                "Le ledger ne doit porter aucune colonne de contenu ou de metadonnees libres : [{$column}]."
            );
        }

        $this->assertSame($columns, collect((new AiProviderInvocation)->getFillable())->push('id', 'created_at', 'updated_at')->sort()->values()->all(),
            'Le fillable du modele est exactement le schema : aucune porte d\'entree pour un champ libre.');
    }

    public function test_a_failed_line_carries_an_exception_class_never_its_message(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $organization->id]);
        $resolver = app(SupervisionProviderResolver::class);
        $authority = $resolver->economicAuthority(new SupervisionEconomicScope($organization, $actor, $actor, 'task1254_probe'));
        $resolved = new ResolvedModel('openrouter', 'openai/gpt-4o-mini', $resolver->declarePlatformCredential('openrouter'));

        $secretMessage = 'Bearer sk-or-task1254-secret-in-message / prompt: "texte prive"';

        try {
            $authority->attempt(
                'member_profile.agent_visitor_chat',
                $resolved,
                static fn () => throw new RuntimeException($secretMessage),
                static fn (): AiUsage => AiUsage::notObserved(),
            );
            $this->fail('L\'exception du provider doit etre relancee telle quelle.');
        } catch (RuntimeException $exception) {
            $this->assertSame($secretMessage, $exception->getMessage());
        }

        $line = AiProviderInvocation::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $line->status);
        $this->assertSame(RuntimeException::class, $line->failure_reason);
        $this->assertTrue(class_exists($line->failure_reason));
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $line->credential_source);
        $this->assertNull($line->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $line->cost_status);

        $serialized = json_encode($line->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('sk-or-task1254', $serialized);
        $this->assertStringNotContainsString('texte prive', $serialized);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function generation(Organization $organization, ?User $actor): AiProviderInvocation
    {
        return app(AiProviderInvocationLedger::class)->recordGeneration(
            organizationId: (string) $organization->id,
            userId: $actor !== null ? (string) $actor->id : null,
            capability: null,
            process: 'blog.article_generate',
            resolved: new ResolvedModel('openai', 'gpt-4o-mini'),
            usage: AiUsage::of(12, 34),
            cost: AiPricingCatalog::cost('openai', 'gpt-4o-mini', AiUsage::of(12, 34)),
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: (string) Str::uuid(),
            sdkInvocationId: null,
            failureReason: null,
            startedAtMicrotime: microtime(true),
            feature: 'blog_generate',
        );
    }

    private function embedding(Organization $organization, ?User $actor): AiProviderInvocation
    {
        return app(AiProviderInvocationLedger::class)->recordEmbedding(
            organizationId: (string) $organization->id,
            userId: $actor !== null ? (string) $actor->id : null,
            capability: null,
            process: 'dossier.embeddings_query',
            embeddingOperation: AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            provider: 'openai',
            model: 'text-embedding-3-small',
            credentialSource: AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            totalTokens: 5,
            embeddingCount: 1,
            embeddingDimensions: 1536,
            cost: null,
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: (string) Str::uuid(),
            sdkInvocationId: (string) Str::uuid(),
            startedAtMicrotime: microtime(true),
        );
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    private function registryEntry(string $key): array
    {
        $entry = collect(UserDataLifecycleRegistry::entries())->firstWhere('key', $key);

        $this->assertNotNull($entry, "Missing lifecycle registry entry [{$key}].");

        return $entry;
    }

    private function ledgerHasForeignKeyTo(string $referencedTable): bool
    {
        return $this->ledgerForeignKeyTargets()->contains($referencedTable);
    }

    /**
     * Tables referencees par les FK de `ai_provider_invocations`, lues dans le
     * catalogue du moteur (pas dans le code des migrations).
     *
     * @return Collection<int, string>
     */
    private function ledgerForeignKeyTargets(): Collection
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => collect(DB::select('PRAGMA foreign_key_list("ai_provider_invocations")'))
                ->map(fn (object $fk) => (string) $fk->table)
                ->unique()
                ->values(),
            'pgsql' => collect(DB::select(<<<'SQL'
                select ccu.table_name as referenced
                from information_schema.table_constraints tc
                join information_schema.constraint_column_usage ccu
                    on ccu.constraint_name = tc.constraint_name
                    and ccu.table_schema = tc.table_schema
                where tc.constraint_type = 'FOREIGN KEY'
                    and tc.table_name = 'ai_provider_invocations'
                    and tc.table_schema = current_schema()
            SQL))
                ->map(fn (object $row) => (string) $row->referenced)
                ->unique()
                ->values(),
            default => $this->fail('Driver non couvert : '.DB::connection()->getDriverName()),
        };
    }

    /**
     * @return list<string>
     */
    private function ledgerIndexNames(): array
    {
        return collect(Schema::getIndexes('ai_provider_invocations'))->pluck('name')->all();
    }
}
