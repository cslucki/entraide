<?php

namespace Tests\Feature;

use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1132 / IA P1-2 — schéma économique des trois tables de trace.
 *
 * Couvre :
 * - 6 : `member_ai_profile_interactions` peut stocker usage / coût / unknown ;
 * - 7 : les lignes historiques restent lisibles, sans backfill ;
 * - 8 : `correlation_id` / `process` de TASK-1131 restent intacts.
 */
class TASK1132AiCostSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TRACE_TABLES = [
        'ai_interactions',
        'admin_ai_interactions',
        'member_ai_profile_interactions',
    ];

    private Organization $organization;

    private User $user;

    private ?MemberAiProfile $profile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    /**
     * Une seule représentation du coût pour les trois tables, pas trois
     * variantes : c'est ce qui rend une lecture consolidée possible.
     */
    public function test_the_three_trace_tables_carry_cost_usd_and_cost_unknown(): void
    {
        foreach (self::TRACE_TABLES as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'cost_usd'),
                "Table [{$table}] should carry cost_usd."
            );

            $this->assertTrue(
                Schema::hasColumn($table, 'cost_unknown'),
                "Table [{$table}] should carry cost_unknown."
            );
        }
    }

    /**
     * 6 — la table member peut désormais stocker usage, coût et statut.
     *
     * Les colonnes token reprennent les noms des deux autres traces, et non une
     * quatrième convention.
     */
    public function test_member_profile_interactions_can_store_usage_cost_and_unknown(): void
    {
        foreach (['input_tokens', 'output_tokens'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('member_ai_profile_interactions', $column),
                "Table should carry [{$column}], matching the two other traces."
            );
        }

        $measured = $this->makeMemberInteraction([
            'input_tokens' => 640,
            'output_tokens' => 128,
            'cost_usd' => 0.00034560,
            'cost_unknown' => false,
        ])->fresh();

        $this->assertSame(640, $measured->input_tokens);
        $this->assertSame(128, $measured->output_tokens);
        $this->assertSame('0.00034560', (string) $measured->cost_usd);
        $this->assertFalse($measured->cost_unknown);

        $unmeasurable = $this->makeMemberInteraction([
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_usd' => null,
            'cost_unknown' => true,
        ])->fresh();

        $this->assertNull($unmeasurable->input_tokens);
        $this->assertNull($unmeasurable->cost_usd);
        $this->assertTrue($unmeasurable->cost_unknown);
    }

    /**
     * Le coût inconnu doit pouvoir être NULL sur les deux tables historiques,
     * sinon un tarif manquant continuerait d'être écrit comme un 0.
     */
    public function test_an_unmeasurable_cost_is_stored_as_null_not_zero(): void
    {
        $aiInteraction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'chatloop.answer',
            'feature' => 'chatloop_ai_answer',
            'model' => 'openrouter/some-exotic-model',
            'prompt' => 'prompt',
            'response' => 'reponse',
            'cost_usd' => null,
            'cost_unknown' => true,
        ]);

        $this->assertNull($aiInteraction->fresh()->cost_usd);
        $this->assertTrue($aiInteraction->fresh()->cost_unknown);

        $adminInteraction = AdminAiInteraction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'supervision.content',
            'scenario_id' => 'supervision_content',
            'provider' => 'openrouter',
            'model' => 'some-exotic-model',
            'status' => 'success',
            'cost_usd' => null,
            'cost_unknown' => true,
        ]);

        $this->assertNull($adminInteraction->fresh()->cost_usd);
        $this->assertTrue($adminInteraction->fresh()->cost_unknown);
    }

    /**
     * 7 — AUCUN BACKFILL. Une ligne historique porte `cost_usd = 0` et
     * `cost_unknown = null`, et reste lisible telle quelle.
     *
     * Le point crucial : ce 0 ancien ne doit PAS avoir été requalifié en mesure
     * certaine (`cost_unknown = false`) par la migration. `null` dit la vérité :
     * ce statut n'a jamais été évalué.
     */
    public function test_historical_rows_keep_their_value_and_an_unevaluated_status(): void
    {
        // Écriture au plus près d'une ligne d'avant P1-2 : le statut n'existe pas.
        DB::table('ai_interactions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'ancien prompt',
            'response' => 'ancienne reponse',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'metadata' => '{}',
            'created_at' => now()->subMonths(3),
        ]);

        $legacy = AiInteraction::where('feature', 'blog_generate')->firstOrFail();

        $this->assertSame('0.000000', (string) $legacy->cost_usd, 'The historical value must be preserved.');
        $this->assertNull(
            $legacy->cost_unknown,
            'A legacy zero must stay unevaluated: neither reinterpreted as measured, nor as unknown.'
        );

        // La ligne reste pleinement lisible malgré les nouvelles colonnes.
        $this->assertSame('ancien prompt', $legacy->prompt);
        $this->assertSame(0, $legacy->input_tokens);
    }

    /**
     * 7 bis — même garantie côté member : les lignes antérieures n'ont ni usage
     * ni coût, et le rester ne casse aucune lecture.
     */
    public function test_legacy_member_rows_without_usage_stay_readable(): void
    {
        DB::table('member_ai_profile_interactions')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'member_ai_profile_id' => $this->memberProfile()->id,
            'profile_owner_user_id' => $this->user->id,
            'visitor_type' => 'guest',
            'status' => 'success',
            'question' => 'ancienne question',
            'response' => 'ancienne reponse',
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);

        $legacy = MemberAiProfileInteraction::where('question', 'ancienne question')->firstOrFail();

        $this->assertNull($legacy->input_tokens);
        $this->assertNull($legacy->output_tokens);
        $this->assertNull($legacy->cost_usd);
        $this->assertNull($legacy->cost_unknown);
        $this->assertSame('ancienne reponse', $legacy->response);
    }

    /**
     * 8 — la corrélation de TASK-1131 reste intacte.
     *
     * P1-2 n'a ni retiré, ni renommé, ni remplacé `correlation_id` / `process`,
     * et écrire un coût ne les altère pas.
     */
    public function test_task_1131_correlation_and_process_are_untouched(): void
    {
        foreach (self::TRACE_TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'correlation_id'), "[{$table}] correlation_id");
            $this->assertTrue(Schema::hasColumn($table, 'process'), "[{$table}] process");
        }

        $correlationId = (string) Str::uuid();

        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => $correlationId,
            'process' => 'blog.article_generate',
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'prompt',
            'response' => 'reponse',
            'input_tokens' => 1200,
            'output_tokens' => 800,
            'cost_usd' => 0.000660,
            'cost_unknown' => false,
        ])->fresh();

        $this->assertSame($correlationId, $interaction->correlation_id);
        $this->assertSame('blog.article_generate', $interaction->process);
        $this->assertSame('0.000660', (string) $interaction->cost_usd);

        $member = $this->makeMemberInteraction([
            'correlation_id' => $correlationId,
            'process' => 'member_profile.loop_agent_reply',
            'cost_usd' => null,
            'cost_unknown' => true,
        ])->fresh();

        // Une même opération : la corrélation est partagée, le verdict
        // économique peut différer d'une trace à l'autre.
        $this->assertSame($correlationId, $member->correlation_id);
        $this->assertSame('member_profile.loop_agent_reply', $member->process);
        $this->assertTrue($member->cost_unknown);
    }

    /**
     * Un seul profil par (organization, user) : la table porte une contrainte
     * d'unicité, et plusieurs interactions se rattachent au même profil.
     */
    private function memberProfile(): MemberAiProfile
    {
        return $this->profile ??= MemberAiProfile::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
        ]);
    }

    private function makeMemberInteraction(array $attributes = []): MemberAiProfileInteraction
    {
        return MemberAiProfileInteraction::create(array_merge([
            'organization_id' => $this->organization->id,
            'member_ai_profile_id' => $this->memberProfile()->id,
            'profile_owner_user_id' => $this->user->id,
            'visitor_type' => 'guest',
            'provider' => 'openrouter',
            'model' => 'some-model',
            'status' => 'success',
            'question' => 'question '.Str::random(6),
            'response' => 'reponse',
        ], $attributes));
    }
}
