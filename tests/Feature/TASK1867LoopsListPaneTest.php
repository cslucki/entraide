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
 * TASK-1867 — mode LISTE G : volet de detail (>= 1024 px) et feuille mobile.
 *
 * Selection, clavier, filtres et feuille se jugent au navigateur ; ici le
 * CONTRAT SERVEUR de la page liste : chaque ligne garde son vrai lien et porte
 * de quoi charger son apercu (bonne route selon la portee), la page initiale
 * n'ajoute AUCUN contenu reserve a ce que T1864 montrait deja, les actions
 * d'adhesion restent sur les lignes, et la grille n'herite pas du volet.
 */
class TASK1867LoopsListPaneTest extends TestCase
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

    public function test_each_row_keeps_its_real_link_and_carries_its_preview_on_both_scopes(): void
    {
        $moi = $this->user();
        $mienne = $this->service->createLoop($moi, 'Ma Boucle');
        $autre = $this->service->createLoop($this->user(), 'Boucle voisine');

        $short = $this->actingAs($moi)->get(route('loops.index', ['view' => 'list']))->assertOk()->getContent();

        // Sur les BALISES : le script du volet cite le selecteur `[data-loops-row-link]`.
        $this->assertSame(2, preg_match_all('/<a\s[^>]*data-loops-row-link/', $short));
        $this->assertStringContainsString('href="'.e(route('loops.show', $mienne)).'"', $short);
        $this->assertStringContainsString('data-loop-id="'.$autre->id.'"', $short);
        $this->assertStringContainsString('data-loop-preview-url="'.e(route('loops.preview', $mienne)).'"', $short);
        $this->assertStringContainsString('data-loop-preview-url="'.e(route('loops.preview', $autre)).'"', $short);

        $scoped = $this->actingAs($moi)
            ->get(route('organization.loops.index', ['organization' => $this->org->slug, 'view' => 'list']))
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

    public function test_the_list_page_renders_the_pane_empty_and_the_mobile_sheet_without_reserved_content(): void
    {
        $moi = $this->user();
        $collegue = $this->user(['first_name' => 'Camille', 'name' => 'Collegue']);
        $mienne = $this->service->createLoop($moi, 'Ma Boucle');
        $this->service->addMemberByUserId($mienne, $collegue->id);
        $this->message($mienne, $collegue, 'Ancien message membre', now()->subHour());
        $this->message($mienne, $moi, 'Dernier message membre', now());

        $etranger = $this->user(['first_name' => 'Dominique', 'name' => 'Membre']);
        $fermee = $this->service->createLoop($etranger, 'Boucle fermee');
        $this->message($fermee, $etranger, 'Contenu reserve non-membre', now());

        $html = $this->actingAs($moi)->get(route('loops.index', ['view' => 'list']))->assertOk()->getContent();

        // Volet et feuille presents, vides : rien n'est charge cote serveur.
        $this->assertStringContainsString('data-loops-list-pane', $html);
        $this->assertStringContainsString(e(__('loops.list_pane_empty')), $html);
        $this->assertStringContainsString('data-loops-preview-drawer="sheet"', $html);
        $this->assertStringContainsString('container-type: inline-size', $html);

        // Ce que T1864 montrait (dernier message, Boucles dont je suis membre)
        // et RIEN de plus : ni messages anterieurs, ni membres, ni fragment.
        $this->assertStringContainsString('Dernier message membre', $html);
        $this->assertStringNotContainsString('Ancien message membre', $html);
        $this->assertStringNotContainsString('Contenu reserve non-membre', $html);
        $this->assertStringNotContainsString(e($collegue->publicDisplayName()), $html);
        $this->assertStringNotContainsString(e($etranger->publicDisplayName()), $html);
        $this->assertDoesNotMatchRegularExpression('/<[a-z]+\s[^>]*data-loop-preview="/i', $html);
        $this->assertStringNotContainsString('data-loop-preview-member=', $html);
        $this->assertStringNotContainsString('data-loop-preview-message=', $html);
    }

    public function test_join_actions_stay_on_the_rows(): void
    {
        $moi = $this->user();
        $this->service->createLoop($moi, 'Ma Boucle');
        $ouverte = $this->service->createLoop($this->user(), 'Boucle ouverte', accessMode: Loop::ACCESS_OPEN);
        $surDemande = $this->service->createLoop($this->user(), 'Boucle sur demande');

        $html = $this->actingAs($moi)->get(route('loops.index', ['view' => 'list']))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.e(route('loops.join', $ouverte)).'"', $html);
        $this->assertStringContainsString(e(__('loops.cta_join')), $html);
        $this->assertStringContainsString(e(__('loops.cta_request')), $html);
        $this->assertStringContainsString('href="'.e(route('loops.show', $surDemande)).'"', $html);
    }

    public function test_the_grid_does_not_inherit_the_list_pane(): void
    {
        $moi = $this->user();
        $this->service->createLoop($moi, 'Ma Boucle');
        $this->service->createLoop($this->user(), 'Boucle voisine');

        $html = $this->actingAs($moi)->get(route('loops.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-loops-list-pane', $html);
        $this->assertStringNotContainsString('data-loops-row-link', $html);
        $this->assertStringNotContainsString('loopsListPane', $html);
        $this->assertStringContainsString('data-loops-preview-drawer="drawer"', $html);
    }

    private function message(Loop $loop, User $sender, string $body, $when): LoopMessage
    {
        $message = new LoopMessage([
            'loop_id' => $loop->id,
            'organization_id' => $loop->organization_id,
            'sender_id' => $sender->id,
            'body' => $body,
            'type' => 'user',
        ]);
        $message->created_at = $when;
        $message->save();

        return $message;
    }
}
