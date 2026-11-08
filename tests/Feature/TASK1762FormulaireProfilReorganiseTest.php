<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 16 — le formulaire de profil remanie.
 *
 * Deux blocs SEPARES (« Informations de profil », « Présentation »), un ordre
 * precis, un editeur riche pour la presentation, et « Langue principale » qui
 * disparait sur un site monolingue.
 */
class TASK1762FormulaireProfilReorganiseTest extends TestCase
{
    use RefreshDatabase;

    private function organization(array $drapeaux = []): Organization
    {
        $org = Organization::factory()->create(array_merge([
            'slug' => 'org-1762-forme', 'is_active' => true, 'locale' => 'fr',
            'structure_enabled' => true,
        ], $drapeaux));

        app()->instance('current_organization', $org);

        return $org;
    }

    private function membre(Organization $org): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU', 'phone' => '0600000000',
        ]);
    }

    private function page(Organization $org, User $membre): string
    {
        return $this->actingAs($membre)
            ->get(route('organization.profile.edit', $org))->assertOk()->getContent();
    }

    // ── Les deux blocs ───────────────────────────────────────────────────────

    /** 🔴 DEUX blocs, et ils ne sont pas imbriques l'un dans l'autre. */
    public function test_deux_blocs_separes(): void
    {
        $org = $this->organization();
        $html = $this->page($org, $this->membre($org));

        $this->assertStringContainsString('data-profile-block="identite"', $html);
        $this->assertStringContainsString('data-profile-block="presentation"', $html);

        // Le second commence APRES la fermeture du premier : s'il etait
        // imbrique, les deux titres vivraient dans le meme cadre — c'est
        // exactement ce que Cyril a refuse en recette.
        $debutIdentite = strpos($html, 'data-profile-block="identite"');
        $debutPresentation = strpos($html, 'data-profile-block="presentation"');

        $this->assertLessThan($debutPresentation, $debutIdentite);

        $entreLesDeux = substr($html, $debutIdentite, $debutPresentation - $debutIdentite);
        $this->assertStringContainsString('</div>', $entreLesDeux);
    }

    /**
     * Les deux blocs alternent sur les jetons de THEME de l'Organization, pas
     * sur une couleur figee — et pas sur le meme jeton l'un que l'autre
     * (recette Cyril : `--bp-surface` est quasi identique a `--bp-page`
     * sur le theme par defaut, le bloc devenait invisible).
     */
    public function test_les_deux_blocs_alternent_sur_des_jetons_de_theme_distincts(): void
    {
        $org = $this->organization();
        $html = $this->page($org, $this->membre($org));

        preg_match('/<div class="[^"]*"\s+data-profile-block="identite">/', $html, $matchIdentite);
        preg_match('/<div class="[^"]*"\s+data-profile-block="presentation">/', $html, $matchPresentation);
        $blocIdentite = $matchIdentite[0] ?? '';
        $blocPresentation = $matchPresentation[0] ?? '';

        $this->assertStringContainsString('var(--bp-', $blocIdentite);
        $this->assertStringContainsString('var(--bp-', $blocPresentation);

        preg_match('/var\(--bp-([a-z-]+)\)/', $blocIdentite, $jetonIdentite);
        preg_match('/var\(--bp-([a-z-]+)\)/', $blocPresentation, $jetonPresentation);
        $this->assertNotSame($jetonIdentite[1] ?? null, $jetonPresentation[1] ?? null);

        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}/', $blocIdentite);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}/', $blocPresentation);
    }

    /** 🔴 L'en-tete redondant a disparu. */
    public function test_l_entete_redondant_est_supprime(): void
    {
        $org = $this->organization();
        $html = $this->page($org, $this->membre($org));

        $this->assertStringNotContainsString(__('profile.info_description'), $html);
        $this->assertStringContainsString(__('profile.identity_section'), $html);
        $this->assertStringContainsString(__('profile.presentation_section'), $html);
    }

    // ── L'ordre ──────────────────────────────────────────────────────────────

    /** 🔴 L'ordre exact demande, mesure par les POSITIONS. */
    public function test_l_ordre_des_champs(): void
    {
        $org = $this->organization();
        $html = $this->page($org, $this->membre($org));

        $attendu = [
            'name="avatar"',
            'name="structure"',
            'name="first_name"',
            'name="name"',
            'name="email"',
            'name="phone"',
            'name="city"',
            'name="country_code"',
            'data-profile-block="presentation"',
            'name="bio"',
            'name="website"',
            'name="linkedin_url"',
            'data-profile-slug-field',
        ];

        $precedent = -1;

        foreach ($attendu as $marqueur) {
            $position = strpos($html, $marqueur);

            $this->assertNotFalse($position, "Introuvable : {$marqueur}");
            $this->assertGreaterThan($precedent, $position, "Hors d'ordre : {$marqueur}");

            $precedent = $position;
        }
    }

    // ── L'editeur ────────────────────────────────────────────────────────────

    /** 🔴 La presentation passe par l'editeur riche, avec le plein ecran. */
    public function test_la_presentation_a_un_editeur_riche_et_le_plein_ecran(): void
    {
        $org = $this->organization();
        $html = $this->page($org, $this->membre($org));

        $this->assertStringContainsString('data-tiptap-container', $html);
        $this->assertStringContainsString('data-tiptap-target', $html);
        $this->assertStringContainsString('data-editeur-plein-ecran', $html);
        $this->assertStringContainsString('bp-editeur-grand', $html);
    }

    /**
     * TASK-1788 correctif 12 (demande Cyril) — 2000 releve a 3500 : le
     * balisage (lien YouTube inclus, desormais) est compte dedans.
     */
    public function test_la_presentation_accepte_3500_caracteres(): void
    {
        $org = $this->organization();
        $membre = $this->membre($org);

        $this->actingAs($membre)->put(route('profile.update'), [
            'first_name' => $membre->first_name, 'name' => $membre->name,
            'email' => $membre->email, 'phone' => $membre->phone,
            'slogan' => 'Mon slogan de test.',
            'bio' => str_repeat('a', 3500),
        ])->assertSessionHasNoErrors();

        $this->assertSame(3500, mb_strlen($membre->fresh()->bio));

        $this->actingAs($membre)->put(route('profile.update'), [
            'first_name' => $membre->first_name, 'name' => $membre->name,
            'email' => $membre->email, 'phone' => $membre->phone,
            'slogan' => 'Mon slogan de test.',
            'bio' => str_repeat('a', 3501),
        ])->assertSessionHasErrors('bio');
    }

    // ── Langue principale ────────────────────────────────────────────────────

    /** Site multilingue : la section est la, et c'est bien une SECTION. */
    public function test_la_section_langue_apparait_sur_un_site_multilingue(): void
    {
        $org = $this->organization(['multilingual_enabled' => true]);
        $html = $this->page($org, $this->membre($org));

        $this->assertStringContainsString('data-profile-locale-section', $html);
        $this->assertStringContainsString(__('profile.locale_section'), $html);
        $this->assertStringContainsString('name="preferred_locale"', $html);
    }

    /** 🔴 Site monolingue : ni la section, ni le champ. */
    public function test_rien_sur_un_site_monolingue(): void
    {
        $org = $this->organization(['multilingual_enabled' => false]);
        $html = $this->page($org, $this->membre($org));

        $this->assertStringNotContainsString('data-profile-locale-section', $html);
        $this->assertStringNotContainsString('name="preferred_locale"', $html);
    }

    /**
     * 🔴 Et un `PUT` forge ne change pas la langue sur un site monolingue.
     *
     * Masquer le champ ne garde rien.
     */
    public function test_un_put_forge_ne_change_pas_la_langue(): void
    {
        $org = $this->organization(['multilingual_enabled' => false]);
        $membre = $this->membre($org);
        $membre->update(['preferred_locale' => null]);

        $this->actingAs($membre)->put(route('profile.update'), [
            'first_name' => $membre->first_name, 'name' => $membre->name,
            'email' => $membre->email, 'phone' => $membre->phone,
            'slogan' => 'Mon slogan de test.',
            'preferred_locale' => 'en',
        ])->assertSessionHasNoErrors();

        $this->assertNull($membre->fresh()->preferred_locale);
    }

    // ── Le libelle de l'adresse ──────────────────────────────────────────────

    /** 🔴 « Adresse de votre profil sur <Organization> ». */
    public function test_l_adresse_porte_le_nom_de_l_organization(): void
    {
        $org = $this->organization(['name' => 'Les Amis du Quartier']);
        $html = $this->page($org, $this->membre($org));

        $this->assertStringContainsString(
            __('profile.slug_on', ['organization' => 'Les Amis du Quartier']),
            $html
        );
    }

    // ── Les champs que Cyril n'avait pas listes ──────────────────────────────

    /** Le champ d'appartenance n'a pas disparu au passage. */
    public function test_le_champ_d_appartenance_survit_au_remaniement(): void
    {
        $org = $this->organization(['membership_enabled' => true]);
        $html = $this->page($org, $this->membre($org));

        $this->assertStringContainsString('data-profile-membership-field', $html);
        $this->assertStringContainsString('name="membership_value"', $html);
    }

    /** La case « Disponible » non plus. */
    public function test_la_case_disponible_survit_au_remaniement(): void
    {
        $org = $this->organization(['availability_enabled' => true]);
        $html = $this->page($org, $this->membre($org));

        $this->assertStringContainsString('data-availability-field', $html);
    }
}
