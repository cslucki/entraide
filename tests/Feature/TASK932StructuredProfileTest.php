<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK932StructuredProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_based_responder_still_uses_legacy_fields_when_structured_profile_is_filled(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jean Test']);

        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'structured_profile' => [
                'summary' => 'Expert Laravel depuis 10 ans',
                'skills' => ['PHP', 'Laravel', 'API'],
            ],
            'member_profile_summary' => 'Résumé legacy',
            'skills' => ['ancienne_competence'],
        ]);

        $this->seed(AiPromptSeeder::class);

        $responder = app(MemberProfileAgentResponder::class);
        $result = $responder->answerRuleBased($profile, 'compétence');

        $this->assertStringContainsString('ancienne_competence', $result['response']);
        $this->assertStringNotContainsString('Expert Laravel depuis 10 ans', $result['response']);
    }

    public function test_rule_based_responder_falls_back_to_legacy_fields_when_structured_profile_null(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Marie Test']);

        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'structured_profile' => null,
            'skills' => ['PHP', 'Laravel'],
            'experience_context' => 'Expérience legacy détaillée',
        ]);

        $this->seed(AiPromptSeeder::class);

        $responder = app(MemberProfileAgentResponder::class);
        $result = $responder->answerRuleBased($profile, 'compétence');

        $this->assertStringContainsString('PHP', $result['response']);
        $this->assertStringContainsString('Expérience legacy détaillée', $result['response']);
    }

    public function test_profile_agent_setup_prompt_is_seeded(): void
    {
        $this->seed(AiPromptSeeder::class);

        $setupPrompt = AdminAiPrompt::where('scenario_id', 'profile_agent_setup')
            ->where('version', 1)
            ->first();

        $this->assertNotNull($setupPrompt);
        $this->assertEquals('Agent de profil IA — Prompt setup v1', $setupPrompt->name);
        $this->assertTrue($setupPrompt->is_active);
    }

    public function test_profile_agent_setup_seeding_is_idempotent(): void
    {
        $this->seed(AiPromptSeeder::class);
        $this->seed(AiPromptSeeder::class);

        $count = AdminAiPrompt::where('scenario_id', 'profile_agent_setup')
            ->where('version', 1)
            ->count();

        $this->assertEquals(1, $count, 'Le seeder ne doit pas créer de doublon');
    }

    public function test_profile_agent_master_remains_after_setup_seed(): void
    {
        $this->seed(AiPromptSeeder::class);

        $masterPrompt = AdminAiPrompt::where('scenario_id', 'profile_agent_master')
            ->where('version', 1)
            ->first();

        $this->assertNotNull($masterPrompt, 'profile_agent_master doit toujours exister');
        $this->assertEquals('Agent de profil IA — Prompt master v1', $masterPrompt->name);
    }

    public function test_admin_can_see_all_six_prompts_including_profile_agent_setup(): void
    {
        $this->seed(AiPromptSeeder::class);

        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($admin)->get(route('admin.ai-prompts'));

        $response->assertOk();
        $response->assertSee('Agent de profil IA — Prompt setup v1');
        $response->assertSee('Agent de profil IA — Prompt master v1');
        $response->assertSee('profile_agent_setup');
    }
}
