<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 3 — « Voir mon profil public », en haut de l'edition du profil.
 */
class TASK1762BoutonProfilPublicTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1762-bouton', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->org);
    }

    private function membre(array $surcharge = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille',
            'name' => 'MOREAU',
        ], $surcharge));
    }

    /** Le bouton est la, et il pointe sur le profil public de la personne. */
    public function test_le_bouton_pointe_sur_le_profil_public(): void
    {
        $membre = $this->membre(['slug' => 'camille']);

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-profile-public-link', $html);
        $this->assertStringContainsString(__('profile.view_public_profile'), $html);
        $this->assertStringContainsString(
            route('organization.profile.show', ['organization' => $this->org->slug, 'user' => 'camille']),
            $html
        );
    }

    /**
     * 🔴 Il existe en DEUX exemplaires, et c'est voulu.
     *
     * `x-page-topbar` est `hidden md:flex` : s'en tenir a son emplacement
     * `action` aurait rendu le bouton invisible sur telephone, la ou la
     * plupart des membres consultent leur profil.
     */
    public function test_le_bouton_existe_aussi_pour_le_telephone(): void
    {
        $html = $this->actingAs($this->membre(['slug' => 'camille']))
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-profile-public-link-mobile', $html);
        $this->assertStringContainsString('md:hidden', $html);
    }

    /**
     * 🔴 Et les deux exemplaires pointent sur la MEME adresse.
     *
     * Deux constructions d'URL ecrites cote a cote finissent par diverger : la
     * page en calcule une seule, les deux boutons la portent.
     */
    public function test_les_deux_exemplaires_pointent_sur_la_meme_adresse(): void
    {
        $html = $this->actingAs($this->membre(['slug' => 'camille']))
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        preg_match_all('/href="([^"]+)"[^>]*data-profile-public-link/', $html, $m);

        $this->assertCount(2, $m[1], 'Il doit y avoir exactement deux boutons.');
        $this->assertSame($m[1][0], $m[1][1], 'Les deux boutons ne pointent pas au meme endroit.');
    }

    /**
     * 🔴 Sans slug choisi, l'adresse retombe sur l'identifiant.
     *
     * `profileRouteKey()` rend `slug ?: id`. Un bouton qui construirait l'URL
     * avec le seul slug mènerait a `/profile/` pour qui n'en a pas choisi.
     */
    public function test_sans_slug_le_bouton_reste_valide(): void
    {
        $membre = $this->membre(['slug' => null]);

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            route('organization.profile.show', ['organization' => $this->org->slug, 'user' => $membre->id]),
            $html
        );
    }

    /**
     * Et l'adresse pointee repond vraiment.
     *
     * Le slug passe par `UserSlugService::attribuer()` et non par un `update()`
     * de la colonne : la resolution lit le registre `user_slugs`, pas
     * `users.slug`. Ecrire la seule colonne fabriquerait une adresse que le
     * produit ne sait pas resoudre — un faux negatif de MA fixture, pas du
     * bouton.
     */
    public function test_l_adresse_pointee_est_servie(): void
    {
        $membre = $this->membre();
        app(\App\Services\UserSlugService::class)->attribuer($membre, 'camille');

        $this->actingAs($membre->fresh())
            ->get(route('organization.profile.show', ['organization' => $this->org->slug, 'user' => 'camille']))
            ->assertOk();
    }
}
