<?php

namespace Tests\Feature;

use App\Models\BlogPost;
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

    /**
     * TASK-1488 (P0 privacy) — `/search` etait un CONTOURNEMENT vivant du
     * correctif deja merge par TASK-1479 : il rendait a un anonyme le nom
     * complet, la ville et la note de membres d'une Organization privee — les
     * memes champs pour lesquels `/membres` a ete ferme — sans exiger le
     * moindre UUID. La recherche exige donc desormais une session de membre.
     *
     * Chaque test de ce fichier mesurait la recherche depuis un visiteur
     * anonyme. Aucune de ses assertions ne portait sur l'anonymat lui-meme :
     * elles portent sur ce que la recherche TROUVE et EXCLUT. Le lecteur
     * devient donc un membre de l'Organization de test, et pas une assertion
     * n'est affaiblie.
     *
     * Ce lecteur est deliberement INERTE : son nom, sa ville et sa biographie
     * ne peuvent croiser aucun terme recherche ici (« Jean », « Alice »,
     * « Paris », « Orchard »...), sans quoi il ferait basculer les assertions
     * de comptage `count() === 1`.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $this->actingAs($this->orgUser([
            'name' => 'Zzz Lecteur Inerte',
            'first_name' => 'Zzz',
            'city' => 'Zzzville',
            'bio' => 'Zzz.',
            'banned_at' => null,
        ]));
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

    public function test_search_excludes_published_posts_from_banned_authors(): void
    {
        $activeAuthor = User::factory()->create(['organization_id' => $this->testOrganization->id, 'banned_at' => null]);
        $bannedAuthor = User::factory()->create(['organization_id' => $this->testOrganization->id, 'banned_at' => now()]);

        BlogPost::create([
            'user_id' => $activeAuthor->id,
            'organization_id' => $this->testOrganization->id,
            'title' => 'Visible Orchard Story',
            'slug' => 'visible-orchard-story',
            'content' => 'Orchard content visible in search.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        BlogPost::create([
            'user_id' => $bannedAuthor->id,
            'organization_id' => $this->testOrganization->id,
            'title' => 'Hidden Orchard Story',
            'slug' => 'hidden-orchard-story',
            'content' => 'Orchard content hidden in search.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->get(route('search', ['q' => 'Orchard']));

        $response->assertOk()
            ->assertViewHas('posts', fn ($posts) => $posts->pluck('title')->all() === ['Visible Orchard Story']);
    }

    public function test_search_finds_users_by_city_not_legacy_location(): void
    {
        User::factory()->create(['name' => 'Bob', 'city' => 'Paris', 'location' => 'Legacy Hidden']);
        User::factory()->create(['name' => 'Carol', 'city' => 'Lyon', 'location' => 'Paris 75']);

        $response = $this->get(route('search', ['q' => 'Paris']));

        $response->assertOk()
            ->assertViewHas('users', fn ($v) => $v->count() === 1 && $v->first()->name === 'Bob');
    }
}
