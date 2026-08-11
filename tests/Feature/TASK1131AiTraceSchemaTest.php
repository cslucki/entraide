<?php

namespace Tests\Feature;

use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1131 / IA P1-1 — schéma des trois tables de trace IA.
 *
 * Couvre :
 * - A : chaque table accepte les nouvelles métadonnées ;
 * - I : les lignes historiques (colonnes nullable) ne cassent aucune lecture ;
 * - J : aucune modification des coûts dans cette TASK.
 */
class TASK1131AiTraceSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TRACE_TABLES = [
        'ai_interactions',
        'admin_ai_interactions',
        'member_ai_profile_interactions',
    ];

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    /**
     * A — une seule représentation pour les trois tables, pas trois variantes.
     */
    public function test_the_three_trace_tables_carry_correlation_id_and_process(): void
    {
        foreach (self::TRACE_TABLES as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'correlation_id'),
                "Table [{$table}] should carry correlation_id."
            );

            $this->assertTrue(
                Schema::hasColumn($table, 'process'),
                "Table [{$table}] should carry process."
            );
        }
    }

    public function test_ai_interactions_accepts_the_new_trace_metadata(): void
    {
        $correlationId = (string) Str::uuid();

        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => $correlationId,
            'process' => 'chatloop.answer',
            'feature' => 'chatloop_ai_answer',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'question',
            'response' => 'reponse',
        ]);

        $this->assertDatabaseHas('ai_interactions', [
            'id' => $interaction->id,
            'correlation_id' => $correlationId,
            'process' => 'chatloop.answer',
        ]);
    }

    public function test_admin_ai_interactions_accepts_the_new_trace_metadata(): void
    {
        $correlationId = (string) Str::uuid();

        $interaction = AdminAiInteraction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'correlation_id' => $correlationId,
            'process' => 'supervision.content',
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('admin_ai_interactions', [
            'id' => $interaction->id,
            'correlation_id' => $correlationId,
            'process' => 'supervision.content',
        ]);
    }

    public function test_member_ai_profile_interactions_accepts_the_new_trace_metadata(): void
    {
        $correlationId = (string) Str::uuid();
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
        ]);

        $interaction = MemberAiProfileInteraction::create([
            'organization_id' => $this->organization->id,
            'correlation_id' => $correlationId,
            'process' => 'member_profile.loop_agent_reply',
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $this->user->id,
            'visitor_type' => 'guest',
            'status' => 'success',
            'question' => 'question',
            'response' => 'reponse',
        ]);

        $this->assertDatabaseHas('member_ai_profile_interactions', [
            'id' => $interaction->id,
            'correlation_id' => $correlationId,
            'process' => 'member_profile.loop_agent_reply',
        ]);
    }

    /**
     * I — une ligne écrite sans les nouvelles colonnes (cas des lignes
     * antérieures à cette TASK) reste valide et lisible. Aucun backfill n'a
     * été fait : la corrélation d'une ligne historique est nulle, pas fausse.
     */
    public function test_historical_rows_without_correlation_remain_valid_and_readable(): void
    {
        $legacyAi = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'prompt historique',
            'response' => 'reponse historique',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cost_usd' => 0.001234,
        ]);

        $legacyAdmin = AdminAiInteraction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'status' => 'success',
        ]);

        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
        ]);

        $legacyMember = MemberAiProfileInteraction::create([
            'organization_id' => $this->organization->id,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $this->user->id,
            'visitor_type' => 'guest',
            'status' => 'success',
            'question' => 'question historique',
            'response' => 'reponse historique',
        ]);

        foreach ([$legacyAi, $legacyAdmin, $legacyMember] as $row) {
            $reloaded = $row->newQuery()->findOrFail($row->id);

            $this->assertNull($reloaded->correlation_id);
            $this->assertNull($reloaded->process);
        }

        // Lectures existantes : filtres et agrégats métier restent opérants.
        $this->assertSame(1, AiInteraction::where('feature', 'blog_generate')->count());
        $this->assertSame('0.001234', (string) $legacyAi->fresh()->cost_usd);
        $this->assertSame(1, AdminAiInteraction::where('scenario_id', 'supervision_content')->count());
        $this->assertSame(
            1,
            MemberAiProfileInteraction::forOrganization($this->organization)->count()
        );
    }

    /**
     * J — cette TASK ne touche pas au coût. Aucune colonne de coût n'a été
     * ajoutée, retirée ou redéfinie, et `member_ai_profile_interactions`
     * reste sans coût (c'est P1-2 qui traitera ce sujet).
     */
    public function test_cost_columns_are_untouched_by_this_task(): void
    {
        $this->assertTrue(Schema::hasColumn('ai_interactions', 'cost_usd'));
        $this->assertTrue(Schema::hasColumn('admin_ai_interactions', 'cost_usd'));

        $this->assertFalse(Schema::hasColumn('member_ai_profile_interactions', 'cost_usd'));
        $this->assertFalse(Schema::hasColumn('member_ai_profile_interactions', 'input_tokens'));
        $this->assertFalse(Schema::hasColumn('member_ai_profile_interactions', 'output_tokens'));

        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'blog.article_generate',
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'prompt',
            'response' => 'reponse',
            'input_tokens' => 1200,
            'output_tokens' => 800,
            'cost_usd' => 0.000660,
        ]);

        // La valeur écrite est conservée à l'identique : renseigner la
        // corrélation ne recalcule ni n'altère le coût.
        $this->assertSame('0.000660', (string) $interaction->fresh()->cost_usd);
    }
}
