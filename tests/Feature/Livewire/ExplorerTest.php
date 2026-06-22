<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Explorer;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExplorerTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::factory()->create();
        $this->owner = User::factory()->create();
    }

    public function test_component_renders(): void
    {
        Livewire::test(Explorer::class)
            ->assertStatus(200);
    }

    public function test_shows_active_services_by_default(): void
    {
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Service visible',
            'status' => 'active',
        ]);
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Service pausé',
            'status' => 'paused',
        ]);

        Livewire::test(Explorer::class)
            ->assertSee('Service visible')
            ->assertDontSee('Service pausé');
    }

    public function test_search_filters_by_title(): void
    {
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Cours de piano',
            'status' => 'active',
        ]);
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Jardinage',
            'status' => 'active',
        ]);

        Livewire::test(Explorer::class)
            ->set('search', 'piano')
            ->assertSee('Cours de piano')
            ->assertDontSee('Jardinage');
    }

    public function test_search_resets_pagination(): void
    {
        Livewire::test(Explorer::class)
            ->set('search', 'something')
            ->assertSet('paginators', ['page' => 1]);
    }

    public function test_switch_tab_to_requests(): void
    {
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Mon service',
            'status' => 'active',
        ]);
        ServiceRequest::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Ma demande',
            'status' => 'open',
        ]);

        Livewire::test(Explorer::class)
            ->call('switchTab', 'requests')
            ->assertSet('tab', 'requests')
            ->assertSee('Ma demande')
            ->assertDontSee('Mon service');
    }

    public function test_filter_by_category(): void
    {
        $otherCategory = Category::factory()->create();

        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Service catégorie A',
            'status' => 'active',
        ]);
        Service::factory()->for($this->owner)->for($otherCategory)->create([
            'title' => 'Service catégorie B',
            'status' => 'active',
        ]);

        Livewire::test(Explorer::class)
            ->call('toggleCategory', $this->category->id)
            ->assertSee('Service catégorie A')
            ->assertDontSee('Service catégorie B');
    }

    public function test_toggle_category_adds_and_removes(): void
    {
        Livewire::test(Explorer::class)
            ->call('toggleCategory', $this->category->id)
            ->assertSet('selectedCategories', [$this->category->id])
            ->call('toggleCategory', $this->category->id)
            ->assertSet('selectedCategories', []);
    }

    public function test_filter_by_delivery_mode(): void
    {
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Service remote',
            'delivery_mode' => 'remote',
            'status' => 'active',
        ]);
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Service onsite',
            'delivery_mode' => 'onsite',
            'status' => 'active',
        ]);

        Livewire::test(Explorer::class)
            ->set('deliveryMode', 'remote')
            ->assertSee('Service remote')
            ->assertDontSee('Service onsite');
    }

    public function test_load_more_increases_per_page(): void
    {
        Livewire::test(Explorer::class)
            ->assertSet('perPage', 15)
            ->call('loadMore')
            ->assertSet('perPage', 30);
    }

    public function test_sort_by_points_ascending(): void
    {
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Cher',
            'points_cost' => 100,
            'status' => 'active',
        ]);
        Service::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Pas cher',
            'points_cost' => 10,
            'status' => 'active',
        ]);

        $component = Livewire::test(Explorer::class)
            ->set('sortBy', 'points_asc');

        $html = $component->html();
        $this->assertLessThan(strpos($html, 'Cher'), strpos($html, 'Pas cher'));
    }

    public function test_shows_only_open_requests_in_requests_tab(): void
    {
        ServiceRequest::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Demande ouverte',
            'status' => 'open',
        ]);
        ServiceRequest::factory()->for($this->owner)->for($this->category)->create([
            'title' => 'Demande fermée',
            'status' => 'closed',
        ]);

        Livewire::test(Explorer::class)
            ->call('switchTab', 'requests')
            ->assertSee('Demande ouverte')
            ->assertDontSee('Demande fermée');
    }
}
