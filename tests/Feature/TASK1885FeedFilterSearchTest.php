<?php

namespace Tests\Feature;

use App\Livewire\OrganizationFeed;
use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1885 C8 (demande Cyril, 22/11) — page Flux : titre « Flux - <Organisation> »
 * (bureau et barre du haut mobile), filtre par type (seuls les types presents
 * dans le Flux VISIBLE du membre) et recherche (titre, texte, auteur).
 */
class TASK1885FeedFilterSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1885-c8', 'name' => 'Collectif Test', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->org);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Philomene Rare']);

        $this->publier(['type' => FeedPost::TYPE_ANNOUNCEMENT, 'title' => 'Jardin partage', 'content' => 'Rendez-vous samedi au potager.']);
        $this->publier(['type' => FeedPost::TYPE_ANNOUNCEMENT, 'title' => 'Assemblee', 'content' => 'Ordre du jour en ligne.']);
        $this->publier(['type' => FeedEventCatalogue::BOUCLE_CREEE, 'object_type' => 'loop', 'object_id' => (string) \Illuminate\Support\Str::uuid(), 'content' => null]);
        // Restreinte a une Boucle dont le membre n'est pas : son type ne doit
        // pas apparaitre dans le menu.
        $this->publier(['type' => FeedEventCatalogue::SONDAGE_PUBLIE, 'visibility' => 'loop', 'loop_id' => null, 'object_type' => 'poll', 'object_id' => (string) \Illuminate\Support\Str::uuid()]);
    }

    private function publier(array $attributs): FeedPost
    {
        return FeedPost::create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $this->auteur->id,
            'status' => FeedPost::STATUS_PUBLISHED,
            'visibility' => 'organization',
            'published_at' => now(),
        ], $attributs));
    }

    public function test_title_names_the_organization_on_desktop_and_mobile(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.flux', ['organization' => $this->org->slug]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-feed-title>Flux - Collectif Test<', $html);
        // Barre du haut mobile.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Flux - Collectif Test'));
    }

    public function test_type_menu_lists_only_types_visible_to_the_member_and_filters(): void
    {
        $composant = Livewire::actingAs($this->membre)->test(OrganizationFeed::class);

        $types = $composant->viewData('types');
        sort($types);
        $this->assertSame([FeedPost::TYPE_ANNOUNCEMENT, FeedEventCatalogue::BOUCLE_CREEE], $types);
        $composant->assertSee(__('feed.filter_types.announcement'))
            ->assertSee(__('feed.filter_types.event_loop_created'))
            ->assertDontSee(__('feed.filter_types.event_loop_poll_created'));

        $composant->set('type', FeedEventCatalogue::BOUCLE_CREEE);
        $this->assertSame(1, $composant->viewData('items')->total());

        // Une valeur forgee hors liste est ignoree : tout le Flux visible.
        $composant->set('type', FeedEventCatalogue::SONDAGE_PUBLIE);
        $this->assertSame(3, $composant->viewData('items')->total());
    }

    public function test_search_matches_title_content_and_author_case_insensitively(): void
    {
        $composant = Livewire::actingAs($this->membre)->test(OrganizationFeed::class);

        $composant->set('q', 'jardin');
        $this->assertSame(1, $composant->viewData('items')->total());

        $composant->set('q', 'ordre du');
        $this->assertSame(1, $composant->viewData('items')->total());

        $composant->set('q', 'philomene');
        $this->assertSame(3, $composant->viewData('items')->total());

        $composant->set('q', 'introuvable-xyz')
            ->assertSee(__('feed.no_result'))
            ->call('resetFilters');
        $this->assertSame(3, $composant->viewData('items')->total());
        $this->assertSame('', $composant->get('q'));
    }
}
