<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_empty_query_returns_empty_results(): void
    {
        Service::factory()->count(3)->create(['status' => 'active', 'organization_id' => $this->testOrganization->id]);

        $response = $this->get(route('search'));

        $response->assertOk()
            ->assertViewHas('q', '')
            ->assertViewHas('services', fn ($v) => $v->isEmpty())
            ->assertViewHas('requests', fn ($v) => $v->isEmpty())
            ->assertViewHas('users', fn ($v) => $v->isEmpty());
    }

    public function test_search_finds_active_services_by_title(): void
    {
        Service::factory()->create(['title' => 'Cours de guitare', 'status' => 'active', 'organization_id' => $this->testOrganization->id]);
        Service::factory()->create(['title' => 'Jardinage', 'status' => 'active', 'organization_id' => $this->testOrganization->id]);

        $response = $this->get(route('search', ['q' => 'guitare']));

        $response->assertOk()
            ->assertViewHas('services', fn ($v) => $v->count() === 1 && $v->first()->title === 'Cours de guitare');
    }

    public function test_search_finds_services_by_description(): void
    {
        Service::factory()->create([
            'title' => 'Service divers',
            'description' => 'Je propose des cours de solfège',
            'status' => 'active',
            'organization_id' => $this->testOrganization->id,
        ]);
        Service::factory()->create(['status' => 'active', 'organization_id' => $this->testOrganization->id]);

        $response = $this->get(route('search', ['q' => 'solfège']));

        $response->assertOk()
            ->assertViewHas('services', fn ($v) => $v->count() === 1);
    }

    public function test_search_excludes_inactive_services(): void
    {
        Service::factory()->create(['title' => 'Vidéo montage', 'status' => 'active', 'organization_id' => $this->testOrganization->id]);
        Service::factory()->create(['title' => 'Vidéo production', 'status' => 'paused', 'organization_id' => $this->testOrganization->id]);

        $response = $this->get(route('search', ['q' => 'vidéo']));

        $response->assertOk()
            ->assertViewHas('services', fn ($v) => $v->count() === 1);
    }

    public function test_search_caps_service_results_at_five(): void
    {
        Service::factory()->count(8)->create(['title' => 'Service commun', 'status' => 'active', 'organization_id' => $this->testOrganization->id]);

        $response = $this->get(route('search', ['q' => 'commun']));

        $response->assertOk()
            ->assertViewHas('services', fn ($v) => $v->count() <= 5);
    }

    public function test_search_finds_open_service_requests(): void
    {
        ServiceRequest::factory()->create(['title' => 'Cherche photographe', 'status' => 'open', 'organization_id' => $this->testOrganization->id]);
        ServiceRequest::factory()->create(['title' => 'Cherche cuisinier', 'status' => 'open', 'organization_id' => $this->testOrganization->id]);
        ServiceRequest::factory()->create(['title' => 'Cherche développeur', 'status' => 'closed', 'organization_id' => $this->testOrganization->id]);

        $response = $this->get(route('search', ['q' => 'cherche']));

        $response->assertOk()
            ->assertViewHas('requests', fn ($v) => $v->count() === 2);
    }

    public function test_search_finds_users_by_name(): void
    {
        User::factory()->create(['name' => 'Jean Dupont']);
        User::factory()->create(['name' => 'Marie Curie']);

        $response = $this->get(route('search', ['q' => 'Jean']));

        $response->assertOk()
            ->assertViewHas('users', fn ($v) => $v->count() === 1 && $v->first()->name === 'Jean Dupont');
    }

    public function test_search_excludes_banned_users(): void
    {
        User::factory()->create(['name' => 'Alice Actif', 'banned_at' => null]);
        User::factory()->create(['name' => 'Alice Bannie', 'banned_at' => now()]);

        $response = $this->get(route('search', ['q' => 'Alice']));

        $response->assertOk()
            ->assertViewHas('users', fn ($v) => $v->count() === 1 && $v->first()->name === 'Alice Actif');
    }

    public function test_search_finds_users_by_location(): void
    {
        User::factory()->create(['name' => 'Bob', 'location' => 'Paris 75']);
        User::factory()->create(['name' => 'Carol', 'location' => 'Lyon']);

        $response = $this->get(route('search', ['q' => 'Paris']));

        $response->assertOk()
            ->assertViewHas('users', fn ($v) => $v->count() === 1);
    }
}
