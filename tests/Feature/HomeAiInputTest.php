<?php

namespace Tests\Feature;

use App\Livewire\HomeAiInput;
use App\Models\Category;
use App\Services\AI\AIIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class HomeAiInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_successfully()
    {
        Livewire::test(HomeAiInput::class)
            ->assertStatus(200)
            ->assertSee('Comment pouvons-nous vous aider ?');
    }

    public function test_it_sets_prompt_from_suggestion()
    {
        Livewire::test(HomeAiInput::class)
            ->call('selectSuggestion', 'I need help')
            ->assertSet('prompt', 'I need help');
    }

    public function test_it_redirects_to_services_create_for_service_offer_intent()
    {
        $category = Category::factory()->create(['slug' => 'it']);

        $aiService = Mockery::mock(AIIntentService::class);
        $aiService->shouldReceive('classify')
            ->with('I want to teach Excel')
            ->andReturn([
                'intent' => 'service_offer',
                'category' => 'it',
                'confidence' => 0.9,
            ]);

        $this->app->instance(AIIntentService::class, $aiService);

        Livewire::test(HomeAiInput::class)
            ->set('prompt', 'I want to teach Excel')
            ->call('submit')
            ->assertRedirect(route('services.create', [
                'category_id' => $category->id,
                'title' => 'I want to teach Excel'
            ]));
    }
}
