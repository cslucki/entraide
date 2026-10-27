<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1697 (1) — « Voir la Boucle » remplace le badge « Dossier de Boucle ».
 *
 * ## Pourquoi
 *
 * Demande de Cyril. Le badge nommait ce que le fil d'Ariane disait déjà —
 * « Boucles › ofsh » juste à gauche — et n'offrait **aucun geste**. Le lien
 * occupe la même place et mène quelque part.
 *
 * C'est exactement le lien de `?espace=boucles` : même route, même libellé,
 * même flèche sortante. Une seconde formulation aurait dérivé.
 */
class TASK1697LoopDossierVisitLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private Loop $loop;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1697a', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->proprietaire, 'Boucle 1697', type: 'project');
        $this->racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();
    }

    private function page(Dossier $dossier): string
    {
        return $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $dossier->id,
            ]))
            ->assertOk()
            ->getContent();
    }

    /** LE geste : le lien remplace le badge. */
    public function test_the_loop_dossier_offers_a_link_instead_of_a_badge(): void
    {
        $html = $this->page($this->racine);

        $this->assertStringContainsString('data-loop-visit', $html);
        $this->assertStringContainsString(e(__('dossiers.loop_visit')), $html);
        $this->assertStringNotContainsString(e(__('dossiers.loop_dossier_badge')), $html);
    }

    /**
     * Le lien vise le SLUG, pas l'uuid.
     *
     * Défaut trouvé en écrivant ce correctif : la relation `loop` était
     * chargée sans la colonne `slug`, et le lien retombait **en silence** sur
     * `getKey()`. L'URL fonctionnait — ce n'était simplement pas celle que le
     * reste du produit publie (`?espace=boucles` et le fil d'Ariane visent le
     * slug).
     *
     * Sans cette assertion, le correctif aurait eu l'air juste.
     */
    public function test_the_link_targets_the_slug_not_the_uuid(): void
    {
        $html = $this->page($this->racine);

        $attendu = route('organization.loops.show', [
            'organization' => $this->organization->slug,
            'loop' => $this->loop->slug,
        ]);

        $this->assertNotNull($this->loop->slug, 'La fixture doit avoir un slug, sinon ce test ne prouve rien.');

        // L'ANCRE, pas toute la page : l'uuid de la Boucle y apparaît
        // légitimement ailleurs (panneau des membres, configuration Alpine).
        // Mon premier jet assertait sur le HTML entier et rougissait pour une
        // raison étrangère à ce qu'il teste.
        preg_match('/<a[^>]*data-loop-visit[^>]*>/s', $html, $ancre);
        $this->assertNotEmpty($ancre, 'Le lien « Voir la Boucle » doit être rendu.');

        $this->assertStringContainsString(e($attendu), $ancre[0]);
        $this->assertStringNotContainsString($this->loop->id, $ancre[0]);
    }

    /**
     * Un Dossier ORDINAIRE n'affiche pas ce lien.
     *
     * Sans cette assertion, sortir le lien de sa branche `isLoopDossier()`
     * resterait vert : il apparaîtrait sur « Mes documents » et sur tout
     * Dossier personnel, pointant vers nulle part.
     */
    public function test_an_ordinary_dossier_shows_no_loop_link(): void
    {
        $ordinaire = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        $this->assertStringNotContainsString('data-loop-visit', $this->page($ordinaire));
    }

    /** Et le lien mène bien à une page qui existe. */
    public function test_following_the_link_reaches_the_loop(): void
    {
        $this->actingAs($this->proprietaire)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop->slug,
            ]))
            ->assertOk();
    }
}
