<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 15 — la section « Informations privées / facturation ».
 *
 * Elle n'apparait que si l'Organization propose des abonnements. Cyril :
 * « si on ne propose pas d'abonnement, pas la peine de parler de facturation,
 * ça fait peur aux utilisateurs. Puis ainsi on évite de recueillir des
 * coordonnées pour rien. »
 */
class TASK1762SectionFacturationTest extends TestCase
{
    use RefreshDatabase;

    private function organization(bool $abonnements): Organization
    {
        $org = Organization::factory()->create([
            'slug' => 'org-1762-fact-'.($abonnements ? 'oui' : 'non'),
            'is_active' => true,
            'locale' => 'fr',
            'subscriptions_enabled' => $abonnements,
        ]);

        app()->instance('current_organization', $org);

        return $org;
    }

    private function membre(Organization $org, array $surcharge = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU', 'phone' => '0600000000',
        ], $surcharge));
    }

    private function formulaire(User $u, array $surcharge = []): array
    {
        return array_merge([
            'first_name' => $u->first_name,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            // TASK-1762 point 17 — le slogan est desormais OBLIGATOIRE.
            'slogan' => 'Je resous des problemes, pas des tickets.',
        ], $surcharge);
    }

    // ── L'affichage ──────────────────────────────────────────────────────────

    /** Abonnements actifs : la section est la. */
    public function test_la_section_apparait_avec_les_abonnements(): void
    {
        $org = $this->organization(true);
        $membre = $this->membre($org);

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', $org))->assertOk()->getContent();

        $this->assertStringContainsString('data-profile-billing-section', $html);
        $this->assertStringContainsString('name="address_line1"', $html);
        $this->assertStringContainsString(__('profile.private_billing_section'), $html);
    }

    /** 🔴 Sans abonnements : plus un mot de facturation, ni un champ. */
    public function test_rien_du_tout_sans_abonnements(): void
    {
        $org = $this->organization(false);
        $membre = $this->membre($org);

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', $org))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-profile-billing-section', $html);
        $this->assertStringNotContainsString('name="address_line1"', $html);
        $this->assertStringNotContainsString('name="address_line2"', $html);
        $this->assertStringNotContainsString('name="postal_code"', $html);

        // Le MOT « facturation » lui-meme disparait : c'est lui qui « fait
        // peur », pas seulement les champs.
        $this->assertStringNotContainsString(__('profile.private_billing_section'), $html);
    }

    // ── L'ecriture ───────────────────────────────────────────────────────────

    /** Avec les abonnements, l'adresse s'enregistre. */
    public function test_l_adresse_s_enregistre_avec_les_abonnements(): void
    {
        $org = $this->organization(true);
        $membre = $this->membre($org);

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, [
                'address_line1' => '12 rue des Lilas',
                'postal_code' => '13001',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('12 rue des Lilas', $membre->fresh()->address_line1);
        $this->assertSame('13001', $membre->fresh()->postal_code);
    }

    /**
     * 🔴 Un `PUT` FORGE n'ecrit rien quand les abonnements sont coupes.
     *
     * C'est la garde qui compte : masquer trois champs ne protege rien.
     */
    public function test_un_put_forge_n_ecrit_pas_d_adresse(): void
    {
        $org = $this->organization(false);
        $membre = $this->membre($org);

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, [
                'address_line1' => '12 rue Forgee',
                'address_line2' => 'Bat. B',
                'postal_code' => '99999',
            ]))
            ->assertSessionHasNoErrors();

        $frais = $membre->fresh();

        $this->assertNull($frais->address_line1);
        $this->assertNull($frais->address_line2);
        $this->assertNull($frais->postal_code);
    }

    /**
     * 🔴 Couper les abonnements n'EFFACE pas les adresses deja saisies.
     *
     * On cache les champs, on ne detruit pas la donnee : rallumer les
     * abonnements doit rendre a chacun ce qu'il avait renseigne.
     */
    public function test_couper_les_abonnements_n_efface_pas_les_adresses(): void
    {
        $org = $this->organization(true);
        $membre = $this->membre($org, [
            'address_line1' => '12 rue des Lilas',
            'postal_code' => '13001',
        ]);

        $org->update(['subscriptions_enabled' => false]);

        // Une modification ordinaire du profil, la section n'etant plus la.
        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, ['phone' => '0611111111']))
            ->assertSessionHasNoErrors();

        $frais = $membre->fresh();

        $this->assertSame('12 rue des Lilas', $frais->address_line1);
        $this->assertSame('13001', $frais->postal_code);
        $this->assertSame('0611111111', $frais->phone);
    }

    /** Et le reste du profil continue de s'enregistrer, abonnements ou non. */
    public function test_le_reste_du_profil_n_est_pas_affecte(): void
    {
        $org = $this->organization(false);
        $membre = $this->membre($org);

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, [
                'bio' => 'Une presentation.',
                'city' => 'Marseille',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Une presentation.', $membre->fresh()->bio);
        $this->assertSame('Marseille', $membre->fresh()->city);
    }
}
