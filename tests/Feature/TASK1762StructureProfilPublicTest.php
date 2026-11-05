<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\UserSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 6 — la structure sur la fiche de profil public.
 *
 * Sous le prenom et le nom, a TOUTES les largeurs, « un peu comme la card de
 * l'annuaire » (arbitrage de Cyril en recette : la premiere version la posait a
 * droite de la carte sur bureau, ce qu'il a refuse).
 */
class TASK1762StructureProfilPublicTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $titulaire;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-fiche', 'is_active' => true, 'locale' => 'fr',
            'structure_enabled' => true,
        ]);
        app()->instance('current_organization', $this->org);

        $this->titulaire = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU',
            'structure' => 'Association Les Amis du Quartier',
            'approved_at' => now(),
        ]);
        app(UserSlugService::class)->attribuer($this->titulaire, 'camille');

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function fiche(?User $commeQui = null): string
    {
        return $this->actingAs($commeQui ?? $this->visiteur)
            ->get(route('organization.profile.show', ['organization' => $this->org->slug, 'user' => 'camille']))
            ->assertOk()->getContent();
    }

    /**
     * 🔴 UN SEUL exemplaire.
     *
     * La premiere version en avait deux — un par largeur d'ecran. L'arbitrage
     * de Cyril les ramene a un, et c'est autant de divergence en moins.
     */
    public function test_un_seul_exemplaire_est_rendu(): void
    {
        $html = $this->fiche();

        $this->assertSame(1, substr_count($html, 'data-profile-structure'));
        $this->assertSame(1, substr_count($html, 'Association Les Amis du Quartier'));
    }

    /** 🔴 Elle suit immediatement le prenom et le nom. */
    public function test_elle_suit_le_prenom_et_le_nom(): void
    {
        $html = $this->fiche();

        $positionNom = strpos($html, 'Camille MOREAU');
        $positionStructure = strpos($html, 'data-profile-structure');

        $this->assertNotFalse($positionNom);
        $this->assertNotFalse($positionStructure);
        $this->assertLessThan($positionStructure, $positionNom);
    }

    /**
     * 🔴 A toutes les largeurs — aucune classe ne la cache.
     *
     * Le defaut probable, apres avoir retire la version bureau, serait de
     * laisser un `sm:hidden` qui la ferait disparaitre sur ordinateur.
     */
    public function test_elle_est_visible_a_toutes_les_largeurs(): void
    {
        $html = $this->fiche();

        preg_match('/<p class="([^"]*)"[^>]*data-profile-structure/', $html, $m);
        $this->assertNotEmpty($m, 'La ligne de structure est introuvable.');

        $this->assertStringNotContainsString('hidden', $m[1]);
    }

    /** 🔴 En gras, dans la couleur du theme, et plus grande que sur l'annuaire. */
    public function test_elle_est_en_gras_dans_la_couleur_du_theme(): void
    {
        $html = $this->fiche();

        preg_match('/<p class="([^"]*)"[^>]*data-profile-structure/', $html, $m);

        $this->assertStringContainsString('font-bold', $m[1]);
        $this->assertStringContainsString('text-[var(--bp-accent)]', $m[1]);
        $this->assertStringNotContainsString('#', $m[1]);

        // Plus grande que la vignette de l'annuaire (`text-xs`), sans atteindre
        // la taille du nom (`text-xl sm:text-2xl`) qui reste le premier mot.
        $this->assertStringContainsString('text-base', $m[1]);
        $this->assertStringNotContainsString('text-xs', $m[1]);
        $this->assertStringNotContainsString('text-2xl', $m[1]);
    }

    /** 🔴 Rien du tout si l'Organization ne l'a pas active. */
    public function test_rien_si_l_organization_ne_l_a_pas_active(): void
    {
        $this->org->update(['structure_enabled' => false]);

        $html = $this->fiche();

        $this->assertStringNotContainsString('data-profile-structure', $html);
        $this->assertStringNotContainsString('Association Les Amis du Quartier', $html);
    }

    /** Et rien pour qui n'a rien renseigne : pas de ligne vide. */
    public function test_rien_sans_structure(): void
    {
        $this->titulaire->update(['structure' => null]);

        $this->assertStringNotContainsString('data-profile-structure', $this->fiche());
    }
}
