<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1752 (design A, choix Cyril) — la ligne compacte d'un evenement.
 *
 * Trois gardes, pas plus : les deux LIENS qui font le design (profil public
 * de l'acteur, objet concerne), et le compte DESACTIVE qui ne doit plus en
 * porter aucun. Le rendu visuel appartient a la recette navigateur.
 */
class TASK1752FeedEventCompactLineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $acteur;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-ligne-1752', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->acteur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => $this->acteur->id,
        ]);

        app(FeedEventEmitter::class)->emettre(
            FeedEventCatalogue::BOUCLE_CREEE, $this->boucle, $this->acteur, $this->organization,
        );
    }

    private function flux(User $lecteur): string
    {
        return $this->actingAs($lecteur)
            ->get(route('organization.flux', ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();
    }

    /** La ligne porte les DEUX liens : le profil de l'acteur et l'objet. */
    public function test_la_ligne_porte_le_lien_du_profil_et_celui_de_l_objet(): void
    {
        $html = $this->flux($this->acteur);

        $this->assertStringContainsString('data-feed-event="'.FeedEventCatalogue::BOUCLE_CREEE.'"', $html);

        $this->assertStringContainsString(
            e(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->acteur->slug ?: $this->acteur->id,
            ])),
            $html,
            'Le nom de l\'acteur doit mener a son profil public.',
        );

        $this->assertStringContainsString(
            e(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->boucle->slug ?: $this->boucle->id,
            ])),
            $html,
            'Le nom de la Boucle doit mener a sa page.',
        );
    }

    /**
     * Un nom de personne ne devient JAMAIS une balise.
     *
     * `ligneHtml()` rend du HTML consomme avec `{!! !!}` : c'est la garde de
     * son contrat — tout ce qui est dynamique passe par `e()`.
     */
    public function test_un_nom_hostile_sort_en_texte_jamais_en_balise(): void
    {
        $this->acteur->forceFill(['first_name' => '<script>alert(1)</script>', 'name' => 'Hostile'])->save();

        $html = $this->flux($this->acteur);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html,
            'Un nom contenant une balise est sorti NON echappe dans le Flux.');
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Un compte desactive : libelle neutre, et AUCUN lien de profil. */
    public function test_un_compte_desactive_ne_porte_plus_de_lien_de_profil(): void
    {
        $lecteur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->acteur->forceFill(['banned_at' => now()])->save();

        $html = $this->flux($lecteur);

        $this->assertStringNotContainsString(
            e(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->acteur->slug ?: $this->acteur->id,
            ])),
            $html,
            'Un compte desactive ne doit plus mener nulle part.',
        );
    }
}
