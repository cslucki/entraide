<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\ProfileLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 18 — un pictogramme par type de lien.
 *
 * « Pour que cela soit plus Premium […] pour chaque type de lien, on va
 * ajouter un icone. Exemple, pour Instagram, l'icone d'Instagram. »
 *
 * `twitter` garde sa CLEF (les lignes deja enregistrees ne doivent pas
 * s'orpheliner), seul son libelle devient « X (ex Twitter) » et son icone
 * devient le logo X. `facebook` est un type ajoute pour cette TASK.
 */
class TASK1762IconesLiensProfilTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $titulaire;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-icones', 'is_active' => true, 'is_public' => true,
        ]);

        $this->titulaire = User::factory()->complete()->create([
            'organization_id' => $this->org->id,
            'email_verified_at' => now(),
            'profile_links' => array_map(
                fn ($type) => ['type' => $type, 'url' => "https://{$type}.exemple.test"],
                ProfileLinks::clefs(),
            ),
        ]);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function fiche(): string
    {
        return $this->actingAs($this->visiteur)
            ->get(route('organization.profile.show', ['organization' => $this->org, 'user' => $this->titulaire]))
            ->assertOk()->getContent();
    }

    /** 🔴 Chaque type a sa PROPRE icone — aucune ne retombe sur le trombone generique. */
    public function test_chaque_type_a_sa_propre_icone(): void
    {
        $icones = array_map(fn ($type) => ProfileLinks::icone($type), ProfileLinks::clefs());

        $this->assertSame(count($icones), count(array_unique($icones)),
            'Deux types partagent la meme icone.');

        foreach ($icones as $icone) {
            $this->assertNotSame('', trim($icone));
            $this->assertStringNotContainsString(
                'M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656',
                $icone,
                'Le trombone generique n\'a plus sa place : chaque type doit porter SON icone.',
            );
        }
    }

    /** Les logos de marque sont RENDUS PLEINS, les icones de trait gardent leur trait. */
    public function test_le_mode_de_rendu_suit_le_type(): void
    {
        foreach (['instagram', 'twitter', 'facebook'] as $marque) {
            $this->assertTrue(ProfileLinks::iconeRemplie($marque), "{$marque} doit etre un logo plein.");
        }

        foreach (['website', 'blog', 'app'] as $trait) {
            $this->assertFalse(ProfileLinks::iconeRemplie($trait), "{$trait} doit rester une icone de trait.");
        }
    }

    /** 🔴 Sur la fiche, chaque pastille porte l'icone adaptee a son mode de rendu. */
    public function test_la_fiche_rend_fill_ou_stroke_selon_le_type(): void
    {
        $html = $this->fiche();

        $this->assertStringContainsString('data-profile-link="instagram"', $html);
        $this->assertStringContainsString('data-profile-link="twitter"', $html);
        $this->assertStringContainsString('data-profile-link="facebook"', $html);

        foreach (['instagram', 'twitter', 'facebook'] as $marque) {
            $debut = strpos($html, 'data-profile-link="'.$marque.'"');
            $segment = substr($html, max(0, $debut - 100), 900);

            $this->assertMatchesRegularExpression('/fill="currentColor"[^>]*viewBox="0 0 24 24"/', $segment,
                "{$marque} doit etre rempli (fill=currentColor).");
        }

        foreach (['website', 'blog', 'app'] as $trait) {
            $debut = strpos($html, 'data-profile-link="'.$trait.'"');
            $segment = substr($html, max(0, $debut - 100), 900);

            $this->assertMatchesRegularExpression('/fill="none"[^>]*stroke="currentColor"/', $segment,
                "{$trait} doit rester une icone de trait (fill=none, stroke=currentColor).");
        }
    }

    // ── Le renommage ─────────────────────────────────────────────────────────

    /** 🔴 Le menu du formulaire propose « X (ex Twitter) », pas « Twitter ». */
    public function test_le_menu_propose_x_ex_twitter(): void
    {
        $html = $this->actingAs($this->titulaire)
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="twitter"', $html);
        $this->assertStringContainsString(__('profile.link_type_twitter'), $html);
        $this->assertSame('X (ex Twitter)', __('profile.link_type_twitter'));
        $this->assertStringNotContainsString('>Twitter<', $html);
    }

    /** Et la fiche publique annonce le MEME libelle (lu par les lecteurs d'ecran). */
    public function test_la_fiche_porte_le_meme_libelle(): void
    {
        $html = $this->fiche();

        $this->assertStringContainsString('X (ex Twitter)', $html);
    }

    /**
     * 🔴 La clef reste « twitter » : une ligne enregistree AVANT ce point ne
     * s'orpheline pas au prochain `assainir()`.
     */
    public function test_la_clef_twitter_n_est_pas_renommee(): void
    {
        $this->assertContains('twitter', ProfileLinks::clefs());
        $this->assertNotContains('x', ProfileLinks::clefs());

        $lignes = ProfileLinks::assainir([['type' => 'twitter', 'url' => 'https://x.com/ancienne-ligne']]);

        $this->assertSame([['type' => 'twitter', 'url' => 'https://x.com/ancienne-ligne']], $lignes);
    }

    // ── Le type ajoute ───────────────────────────────────────────────────────

    /** 🔴 « facebook » est un type valide, propose au formulaire et accepte au serveur. */
    public function test_facebook_est_un_type_valide(): void
    {
        $this->assertContains('facebook', ProfileLinks::clefs());

        $html = $this->actingAs($this->titulaire)
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="facebook"', $html);

        $this->actingAs($this->titulaire)
            ->put(route('profile.update'), [
                'first_name' => $this->titulaire->first_name,
                'name' => $this->titulaire->name,
                'email' => $this->titulaire->email,
                'phone' => $this->titulaire->phone,
                'slogan' => $this->titulaire->slogan,
                'profile_links' => [['type' => 'facebook', 'url' => 'https://facebook.com/nouvelle-ligne']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['type' => 'facebook', 'url' => 'https://facebook.com/nouvelle-ligne']],
            $this->titulaire->fresh()->liensDeProfil(),
        );
    }
}
