<?php

namespace Tests\Feature\Commands;

use App\Models\AdminAiPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedChatLoopAiPromptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_creates_fr_and_en_active_prompts(): void
    {
        $this->artisan('ai:seed-chatloop-ai-prompts')->assertSuccessful();

        $fr = AdminAiPrompt::active()->where('scenario_id', 'chatloop_ai_answer_fr')->first();
        $en = AdminAiPrompt::active()->where('scenario_id', 'chatloop_ai_answer_en')->first();

        $this->assertNotNull($fr);
        $this->assertNotNull($en);
        $this->assertSame('chatloop_ai_answer_fr', $fr->scenario_id);
        $this->assertSame('chatloop_ai_answer_en', $en->scenario_id);
        $this->assertTrue($fr->is_active);
        $this->assertTrue($en->is_active);
        $this->assertNotEmpty($fr->prompt_text);
        $this->assertNotEmpty($en->prompt_text);
        $this->assertStringContainsString('français', $fr->prompt_text);
        $this->assertStringContainsString('English', $en->prompt_text);
    }

    public function test_first_run_creates_summarize_prompts(): void
    {
        $this->artisan('ai:seed-chatloop-ai-prompts')->assertSuccessful();

        $fr = AdminAiPrompt::active()->where('scenario_id', 'chatloop_ai_summarize_fr')->first();
        $en = AdminAiPrompt::active()->where('scenario_id', 'chatloop_ai_summarize_en')->first();

        $this->assertNotNull($fr);
        $this->assertNotNull($en);
        $this->assertTrue($fr->is_active);
        $this->assertTrue($en->is_active);
        $this->assertStringContainsString('synthèse', $fr->prompt_text);
        $this->assertStringContainsString('summary', $en->prompt_text);
    }

    public function test_second_run_without_changes_does_not_create_duplicates(): void
    {
        $this->artisan('ai:seed-chatloop-ai-prompts')->assertSuccessful();

        $this->artisan('ai:seed-chatloop-ai-prompts')->assertSuccessful();

        $frAll = AdminAiPrompt::where('scenario_id', 'chatloop_ai_answer_fr')->get();
        $enAll = AdminAiPrompt::where('scenario_id', 'chatloop_ai_answer_en')->get();

        $this->assertCount(1, $frAll);
        $this->assertCount(1, $enAll);
    }

    public function test_only_one_active_version_per_scenario(): void
    {
        $this->artisan('ai:seed-chatloop-ai-prompts')->assertSuccessful();

        $activeFr = AdminAiPrompt::active()->where('scenario_id', 'chatloop_ai_answer_fr')->get();
        $activeEn = AdminAiPrompt::active()->where('scenario_id', 'chatloop_ai_answer_en')->get();

        $this->assertCount(1, $activeFr);
        $this->assertCount(1, $activeEn);
    }

    public function test_it_never_overwrites_a_custom_active_prompt(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'chatloop_ai_answer_fr',
            'name' => 'Custom FR',
            'description' => 'Customized by an admin.',
            'prompt_text' => 'Prompt personnalisé par l\'organisation.',
            'version' => 1,
            'is_active' => true,
        ]);

        $this->artisan('ai:seed-chatloop-ai-prompts')->assertSuccessful();

        $frRows = AdminAiPrompt::where('scenario_id', 'chatloop_ai_answer_fr')->get();

        $this->assertCount(1, $frRows);
        $this->assertTrue($frRows->first()->is_active);
        $this->assertSame('Prompt personnalisé par l\'organisation.', $frRows->first()->prompt_text);
    }
}
