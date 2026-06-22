<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiBenchmarkPrompt;
use Database\Seeders\AiBenchmarkPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiBenchmarkPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_prompt_via_model(): void
    {
        $prompt = AdminAiBenchmarkPrompt::create([
            'category' => 'clarification',
            'title' => 'Test prompt',
            'prompt_text' => 'This is a test prompt.',
            'expected_output_hint' => 'Expected output here.',
            'complexity' => 3,
            'tags' => ['test', 'feature'],
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('admin_ai_benchmark_prompts', [
            'id' => $prompt->id,
            'category' => 'clarification',
            'title' => 'Test prompt',
        ]);
    }

    public function test_active_scope_filters_correctly(): void
    {
        AdminAiBenchmarkPrompt::create([
            'category' => 'clarification',
            'title' => 'Active prompt',
            'prompt_text' => 'Active',
            'is_active' => true,
        ]);

        AdminAiBenchmarkPrompt::create([
            'category' => 'clarification',
            'title' => 'Inactive prompt',
            'prompt_text' => 'Inactive',
            'is_active' => false,
        ]);

        $active = AdminAiBenchmarkPrompt::active()->get();

        $this->assertCount(1, $active);
        $this->assertSame('Active prompt', $active->first()->title);
    }

    public function test_by_category_scope_filters_correctly(): void
    {
        AdminAiBenchmarkPrompt::create([
            'category' => 'clarification',
            'title' => 'Clarify',
            'prompt_text' => 'Clarify this.',
        ]);

        AdminAiBenchmarkPrompt::create([
            'category' => 'technical',
            'title' => 'Technical',
            'prompt_text' => 'Technical task.',
        ]);

        $technical = AdminAiBenchmarkPrompt::byCategory('technical')->get();

        $this->assertCount(1, $technical);
        $this->assertSame('Technical', $technical->first()->title);
    }

    public function test_tags_cast_to_array(): void
    {
        $prompt = AdminAiBenchmarkPrompt::create([
            'category' => 'technical',
            'title' => 'Tagged prompt',
            'prompt_text' => 'Prompt with tags.',
            'tags' => ['json', 'strict', 'test'],
        ]);

        $this->assertIsArray($prompt->tags);
        $this->assertContains('json', $prompt->tags);
        $this->assertContains('strict', $prompt->tags);
    }

    public function test_seeder_creates_prompts(): void
    {
        $this->seed(AiBenchmarkPromptSeeder::class);

        $this->assertGreaterThanOrEqual(10, AdminAiBenchmarkPrompt::count());

        $categories = AdminAiBenchmarkPrompt::pluck('category')->unique()->values()->all();
        $this->assertContains('clarification', $categories);
        $this->assertContains('supervision_content', $categories);
        $this->assertContains('review', $categories);
        $this->assertContains('technical', $categories);
    }

    public function test_complexity_is_integer_and_in_range(): void
    {
        $prompt = AdminAiBenchmarkPrompt::create([
            'category' => 'technical',
            'title' => 'Complex prompt',
            'prompt_text' => 'Complex task.',
            'complexity' => 5,
        ]);

        $this->assertSame(5, $prompt->complexity);
        $this->assertIsInt($prompt->complexity);
    }
}
