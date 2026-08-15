<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Models\User;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TASK1211ClarifyPromptProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_15_111450_provision_clarify_help_request_v2_admin_ai_prompt.php';

    public function test_an_absent_scenario_is_provisioned_as_active_v2(): void
    {
        $this->deleteClarifyPrompts();

        $this->migration()->up();

        $prompt = $this->clarifyVersion(2);
        $this->assertTrue($prompt->is_active);
        $this->assertTrue(Str::isUuid($prompt->id));
        $this->assertStringContainsString('suggested_category_id', $prompt->prompt_text);
        $this->assertStringContainsString('suggested_loop_id', $prompt->prompt_text);
    }

    public function test_an_active_v1_keeps_authority_and_v2_is_created_inactive(): void
    {
        $this->deleteClarifyPrompts();
        $v1 = $this->createPrompt(version: 1, active: true, text: 'ADMIN V1 ACTIVE');
        $before = $v1->fresh()->getRawOriginal();

        $this->migration()->up();

        $this->assertSame($before, $v1->fresh()->getRawOriginal());
        $this->assertFalse($this->clarifyVersion(2)->is_active);
    }

    public function test_a_higher_active_version_is_never_replaced_or_deactivated(): void
    {
        $this->deleteClarifyPrompts();
        $v3 = $this->createPrompt(version: 3, active: true, text: 'ADMIN V3 SUPERIEURE');
        $before = $v3->fresh()->getRawOriginal();

        $this->migration()->up();

        $this->assertSame($before, $v3->fresh()->getRawOriginal());
        $this->assertFalse($this->clarifyVersion(2)->is_active);
    }

    public function test_an_admin_edited_v2_is_never_overwritten(): void
    {
        $this->deleteClarifyPrompts();
        $v2 = $this->createPrompt(
            version: 2,
            active: true,
            text: 'TEXTE V2 ADMINISTRE',
            metadata: ['owner' => 'admin', 'purpose' => 'custom'],
        );
        $before = $v2->fresh()->getRawOriginal();

        $this->migration()->up();

        $this->assertSame($before, $v2->fresh()->getRawOriginal());
    }

    public function test_an_inactive_v2_is_never_reactivated(): void
    {
        $this->deleteClarifyPrompts();
        $v2 = $this->createPrompt(version: 2, active: false, text: 'V2 DESACTIVEE PAR ADMIN');

        $this->migration()->up();

        $this->assertFalse($v2->fresh()->is_active);
        $this->assertSame('V2 DESACTIVEE PAR ADMIN', $v2->fresh()->prompt_text);
    }

    public function test_an_entirely_inactive_history_does_not_reactivate_the_capability(): void
    {
        $this->deleteClarifyPrompts();
        $v1 = $this->createPrompt(version: 1, active: false, text: 'HISTORIQUE DESACTIVE');

        $this->migration()->up();

        $this->assertFalse($v1->fresh()->is_active);
        $this->assertFalse($this->clarifyVersion(2)->is_active);
    }

    public function test_other_scenarios_are_strictly_unchanged(): void
    {
        $this->deleteClarifyPrompts();
        $other = AdminAiPrompt::create([
            'scenario_id' => 'profile_agent_visitor_chat',
            'name' => 'Prompt admin hors scope',
            'description' => 'Ne doit pas changer.',
            'prompt_text' => 'TEXTE HORS SCOPE',
            'version' => 91,
            'is_active' => true,
            'metadata' => ['protected' => true],
        ]);
        $before = $other->fresh()->getRawOriginal();

        $this->migration()->up();

        $this->assertSame($before, $other->fresh()->getRawOriginal());
    }

    public function test_a_second_logical_execution_never_duplicates_v2(): void
    {
        $this->deleteClarifyPrompts();

        $this->migration()->up();
        $before = $this->clarifyVersion(2)->getRawOriginal();
        $this->migration()->up();

        $this->assertSame(1, AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 2)
            ->count());
        $this->assertSame($before, $this->clarifyVersion(2)->getRawOriginal());
    }

    public function test_down_is_conservative_and_keeps_the_admin_prompt(): void
    {
        $this->deleteClarifyPrompts();
        $migration = $this->migration();
        $migration->up();
        $before = $this->clarifyVersion(2)->getRawOriginal();

        $migration->down();

        $this->assertSame($before, $this->clarifyVersion(2)->getRawOriginal());
    }

    public function test_the_provisioned_prompt_is_listed_by_the_admin_screen(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.ai-prompts', ['scenario_id' => 'clarify_help_request']))
            ->assertOk()
            ->assertSee('Clarification de demande d&#039;aide — v2', false)
            ->assertSee('Clarification de demande d&#039;aide', false);
    }

    public function test_the_migration_and_demo_seeder_keep_the_same_v2_text(): void
    {
        $migration = $this->migration();
        $reflection = new \ReflectionClass($migration);
        $migrationPrompt = $reflection->getReflectionConstant('PROMPT')->getValue();
        $this->deleteClarifyPrompts();

        $this->seed(AiPromptSeeder::class);

        $this->assertSame($migrationPrompt, $this->clarifyVersion(2)->prompt_text);
    }

    public function test_the_historical_migration_has_no_mutable_model_dependency(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('AdminAiPrompt::', $source);
    }

    private function deleteClarifyPrompts(): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'clarify_help_request')->delete();
    }

    private function createPrompt(
        int $version,
        bool $active,
        string $text,
        ?array $metadata = null,
    ): AdminAiPrompt {
        return AdminAiPrompt::create([
            'scenario_id' => 'clarify_help_request',
            'name' => 'Clarify admin v'.$version,
            'description' => 'Prompt administré avant migration.',
            'prompt_text' => $text,
            'version' => $version,
            'is_active' => $active,
            'metadata' => $metadata,
        ]);
    }

    private function clarifyVersion(int $version): AdminAiPrompt
    {
        return AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', $version)
            ->firstOrFail();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }
}
