<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1866 — mode GRILLE N du catalogue : tuiles denses + tiroir d'apercu.
 *
 * Le comportement du tiroir (ouverture, focus, Echap, feuille mobile) se juge
 * au navigateur ; ici, le CONTRAT SERVEUR de la page : chaque tuile garde un
 * vrai lien vers la Boucle et porte l'URL de son apercu (bonne route selon la
 * portee), la page initiale ne contient AUCUN contenu reserve, la liste est
 * inchangee, filtres et actions restent en place.
 */
class TASK1866LoopsGridTilesDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        app()->instance('current_organization', $this->org);
        $this->service = new LoopService;
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['organization_id' => $this->org->id] + $attributes);
    }

    public function test_each_tile_keeps_a_real_link_and_carries_its_preview_url_on_both_scopes(): void
    {
        $moi = $this->user();
        $mienne = $this->service->createLoop($moi, 'Ma Boucle');
        $autre = $this->service->createLoop($this->user(), 'Boucle voisine');

        $short = $this->actingAs($moi)->get(route('loops.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($short, 'data-loops-catalog-tile='));
        $this->assertStringContainsString('href="'.e(route('loops.show', $mienne)).'"', $short);
        $this->assertStringContainsString('data-loop-preview-url="'.e(route('loops.preview', $mienne)).'"', $short);
        $this->assertStringContainsString('data-loop-preview-url="'.e(route('loops.preview', $autre)).'"', $short);
        $this->assertStringContainsString('data-loops-preview-drawer', $short);
        $this->assertStringContainsString('role="dialog"', $short);
        $this->assertStringContainsString('aria-modal="true"', $short);

        $scoped = $this->actingAs($moi)
            ->get(route('organization.loops.index', ['organization' => $this->org->slug]))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            'data-loop-preview-url="'.e(route('organization.loops.preview', ['organization' => $this->org->slug, 'loop' => $autre->id])).'"',
            $scoped,
        );
        $this->assertStringContainsString(
            'href="'.e(route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $autre->slug ?: $autre->id])).'"',
            $scoped,
        );
    }

    public function test_the_initial_grid_page_discloses_no_reserved_content(): void
    {
        $moi = $this->user();
        $collegue = $this->user(['first_name' => 'Camille', 'name' => 'Collegue']);
        $mienne = $this->service->createLoop($moi, 'Ma Boucle');
        $this->service->addMemberByUserId($mienne, $collegue->id);
        $this->message($mienne, $collegue, 'Contenu de conversation membre');

        $etranger = $this->user(['first_name' => 'Dominique', 'name' => 'Membre']);
        $fermee = $this->service->createLoop($etranger, 'Boucle fermee');
        $this->message($fermee, $etranger, 'Contenu reserve non-membre');

        $html = $this->actingAs($moi)->get(route('loops.index'))->assertOk()->getContent();

        // Aucun message (meme des Boucles dont je suis membre), aucun membre
        // nominatif, aucun fragment d'apercu : tout passe par le tiroir.
        $this->assertStringNotContainsString('Contenu de conversation membre', $html);
        $this->assertStringNotContainsString('Contenu reserve non-membre', $html);
        $this->assertStringNotContainsString(e($collegue->publicDisplayName()), $html);
        $this->assertStringNotContainsString(e($etranger->publicDisplayName()), $html);
        // Sur une BALISE : le script du tiroir cite le selecteur `[data-loop-preview="…"]`.
        $this->assertDoesNotMatchRegularExpression('/<[a-z]+\s[^>]*data-loop-preview="/i', $html);
        $this->assertStringNotContainsString('data-loop-preview-message', $html);
        $this->assertStringNotContainsString('data-loop-preview-member', $html);
    }

    public function test_the_list_view_is_unchanged_and_has_no_tile_nor_drawer(): void
    {
        $moi = $this->user();
        $mienne = $this->service->createLoop($moi, 'Ma Boucle');
        $this->service->createLoop($this->user(), 'Boucle voisine');
        $this->message($mienne, $moi, 'Dernier message de la liste');

        $html = $this->actingAs($moi)->get(route('loops.index', ['view' => 'list']))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-loops-catalog-row='));
        $this->assertStringContainsString('Dernier message de la liste', $html);
        $this->assertStringNotContainsString('data-loops-catalog-tile=', $html);
        $this->assertStringNotContainsString('data-loops-preview-drawer', $html);
        $this->assertStringNotContainsString('loopsPreviewDrawer', $html);
    }

    public function test_filters_tabs_switch_and_existing_actions_stay_in_the_grid(): void
    {
        $moi = $this->user();
        $mienne = $this->service->createLoop($moi, 'Ma Boucle');
        $autre = $this->service->createLoop($this->user(), 'Boucle sur demande');
        foreach (range(1, 6) as $i) {
            $this->service->createLoop($this->user(), "Remplissage {$i}");
        }

        $html = $this->actingAs($moi)->get(route('loops.index'))->assertOk()->getContent();

        // Recherche (> 6 Boucles), onglets « Mes Boucles », bascule de vue.
        $this->assertStringContainsString('x-model="q"', $html);
        $this->assertStringContainsString('data-loops-tab-mine', $html);
        $this->assertStringContainsString('data-loops-view-switch-option="grid"', $html);
        $this->assertStringContainsString('data-loops-discover', $html);
        // Chaque tuile se filtre par la meme condition que la liste.
        $this->assertSame(8, substr_count($html, 'x-show="(q === \'\''));
        // Crayon pour la Boucle que je peux modifier, badge d'acces pour l'autre.
        $this->assertStringContainsString('href="'.e(route('loops.edit', $mienne)).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('loops.edit', $autre)).'"', $html);
        $this->assertStringContainsString(e(__('loops.access_badge_request')), $html);
    }

    private function message(Loop $loop, User $sender, string $body): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $loop->id,
            'organization_id' => $loop->organization_id,
            'sender_id' => $sender->id,
            'body' => $body,
            'type' => 'user',
        ]);
    }
}
