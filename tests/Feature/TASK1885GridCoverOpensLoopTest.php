<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1885 C2 (demande Cyril, 22/11) — en GRILLE, l'image d'une tuile ouvre
 * la Boucle directement ; seul le titre ouvre le tiroir d'apercu.
 *
 * Contrat serveur : le lien de l'image pointe vers la Boucle et ne porte AUCUN
 * intercepteur (`openLoopPreview`) ni URL d'apercu ; le lien du titre garde les
 * deux. Le clic lui-meme se juge au navigateur (recette Chrome).
 */
class TASK1885GridCoverOpensLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cover_opens_the_loop_and_only_the_title_opens_the_drawer(): void
    {
        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        app()->instance('current_organization', $org);

        $moi = User::factory()->create(['organization_id' => $org->id]);
        $boucle = (new LoopService)->createLoop($moi, 'Ma Boucle');
        // Une seule Boucle redirige l'index vers elle : une voisine garde le catalogue.
        (new LoopService)->createLoop(User::factory()->create(['organization_id' => $org->id]), 'Boucle voisine');

        $html = $this->actingAs($moi)->get(route('loops.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a\s[^>]*data-loops-tile-cover-link[^>]*href="'.preg_quote(e(route('loops.show', $boucle)), '/').'"[^>]*>|<a\s[^>]*href="'.preg_quote(e(route('loops.show', $boucle)), '/').'"[^>]*data-loops-tile-cover-link[^>]*>/', $html, $cover));
        $this->assertStringContainsString('href="'.e(route('loops.show', $boucle)).'"', $cover[0]);
        $this->assertStringNotContainsString('openLoopPreview', $cover[0]);
        $this->assertStringNotContainsString('data-loop-preview-url', $cover[0]);

        $this->assertSame(2, substr_count($html, 'data-loops-tile-cover-link'));
        $this->assertSame(1, preg_match('/<a\s[^>]*data-loops-tile-link[^>]*data-loop-id="'.$boucle->id.'"[^>]*>/', $html, $title));
        $this->assertStringContainsString('openLoopPreview($event)', $title[0]);
        $this->assertStringContainsString('data-loop-preview-url="'.e(route('loops.preview', $boucle)).'"', $title[0]);
    }
}
