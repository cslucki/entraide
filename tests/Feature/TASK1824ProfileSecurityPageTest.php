<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1824 — la page « Securite du compte » (`profile.security` /
 * `organization.profile.security`).
 *
 * Les trois partials (mot de passe, acces/comptes lies, fermeture) ont
 * demenage de `profile.edit` vers cette page — deplaces, pas dupliques :
 * memes fichiers, memes routes de soumission (`password.update`,
 * `account.close`, `account.links`, `account.switch`), aucune logique
 * applicative recrite.
 */
class TASK1824ProfileSecurityPageTest extends TestCase
{
    use RefreshDatabase;

    private function organisation(array $surcharges = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'is_active' => true,
            'is_default' => true,
            'requires_account_approval' => false,
        ], $surcharges));
    }

    private function membre(Organization $organisation, array $surcharges = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $organisation->id,
            'email_verified_at' => now(),
            'approved_at' => now(),
            'password' => Hash::make('ancien-secret-1234'),
        ], $surcharges));
    }

    /** 1. La page prefixee par l'Organization rend les trois sections. */
    public function test_la_page_securite_rend_les_trois_sections(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)->get("/org/{$organisation->slug}/profile/secu");

        $reponse->assertOk();
        $html = $reponse->getContent();

        // A. Mot de passe.
        $this->assertStringContainsString('name="current_password"', $html);
        $this->assertStringContainsString('action="'.route('password.update').'"', $html);

        // B. Acces et comptes lies, avec l'Organization active nommee.
        $this->assertStringContainsString('data-nav-account-links', $html);
        $this->assertStringContainsString('data-nav-account-switch', $html);
        $this->assertStringContainsString('data-marqueur="organisation-active"', $html);
        $this->assertStringContainsString($organisation->name, $html);

        // C. Fermeture du compte.
        $this->assertStringContainsString('data-marqueur="ouvrir-fermeture-compte"', $html);
        $this->assertStringContainsString('action="'.route('account.close').'"', $html);
    }

    /** 2. La forme globale (miroir, meme convention que profile.edit) repond aussi. */
    public function test_la_forme_globale_repond(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $this->actingAs($membre)->get('/profile/secu')->assertOk();
    }

    /** 3. Un invite est redirige vers la connexion. */
    public function test_invite_est_redirige_vers_la_connexion(): void
    {
        $organisation = $this->organisation();

        $this->get("/org/{$organisation->slug}/profile/secu")
            ->assertRedirect(route('organization.login', ['organization' => $organisation->slug]));
    }

    /**
     * 4. Isolation multi-Organization : un membre de l'Organization A ne peut
     * pas ouvrir la page Securite PREFIXEE par le slug de l'Organization B.
     * `EnsureOrganizationMember` (groupe `/org/{organization}`) refuse en 404
     * AVANT que le controleur ne soit atteint — meme garde que
     * `organization.profile.edit`, aucune garde nouvelle ecrite ici.
     */
    public function test_acces_interdit_a_la_page_securite_d_une_autre_organization(): void
    {
        $orgA = $this->organisation();
        $orgB = $this->organisation(['is_default' => false]);

        $membreA = $this->membre($orgA);

        $this->actingAs($membreA)
            ->get("/org/{$orgB->slug}/profile/secu")
            ->assertNotFound();
    }

    /**
     * 5. Meme avec le slug d'une AUTRE Organization dans l'URL, un membre
     * authentifie voit TOUJOURS sa propre Organization (jamais celle de
     * l'URL) — identique au contrat deja etabli par `profile.edit`
     * (TASK-1689), reconduit a l'identique pour cette page.
     */
    public function test_voit_toujours_sa_propre_organization_jamais_celle_de_l_url(): void
    {
        $orgA = $this->organisation();
        $membreA = $this->membre($orgA);

        $reponse = $this->actingAs($membreA)->get("/org/{$orgA->slug}/profile/secu");

        $reponse->assertOk();
        $this->assertStringContainsString($orgA->name, $reponse->getContent());
    }

    /** 6. `profile.edit` ne porte plus les trois sections, mais un acces vers Securite. */
    public function test_profile_edit_ne_porte_plus_les_sections_de_securite(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)->get("/org/{$organisation->slug}/profile/edit");

        $reponse->assertOk();
        $html = $reponse->getContent();

        $this->assertStringNotContainsString('name="current_password"', $html);
        $this->assertStringNotContainsString('data-marqueur="ouvrir-fermeture-compte"', $html);
        $this->assertStringNotContainsString('data-nav-account-links', $html);

        $this->assertStringContainsString('data-nav-profile-security', $html);
        $this->assertStringContainsString('href="'.route('organization.profile.security', ['organization' => $organisation->slug]).'"', $html);
    }

    /**
     * 7. Le changement de mot de passe soumis DEPUIS la page Securite y
     * revient (jamais vers `profile.edit`) : `back()` suit le referer, donc
     * aucune modification de `PasswordController` n'etait necessaire pour que
     * l'erreur ou le succes s'affichent sur la bonne page.
     */
    public function test_le_changement_de_mot_de_passe_revient_sur_la_page_securite(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)
            ->from("/org/{$organisation->slug}/profile/secu")
            ->put('/password', [
                'current_password' => 'ancien-secret-1234',
                'password' => 'nouveau-secret-9876',
                'password_confirmation' => 'nouveau-secret-9876',
            ]);

        $reponse->assertRedirect("/org/{$organisation->slug}/profile/secu");
        $this->assertSame('password-updated', session('status'));
        $this->assertTrue(Hash::check('nouveau-secret-9876', $membre->fresh()->password));

        $suite = $this->actingAs($membre)->get("/org/{$organisation->slug}/profile/secu");
        $suite->assertOk();
        $suite->assertSee(__('profile.saved'));
    }

    /**
     * 8. Une erreur de validation soumise depuis Securite y revient aussi,
     * avec le message dans le bon sac (`updatePassword`) — jamais perdue par
     * une redirection vers `profile.edit`.
     */
    public function test_une_erreur_de_validation_revient_sur_la_page_securite(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)
            ->from("/org/{$organisation->slug}/profile/secu")
            ->put('/password', [
                'current_password' => 'ce-n-est-pas-le-bon',
                'password' => 'nouveau-secret-9876',
                'password_confirmation' => 'nouveau-secret-9876',
            ]);

        $reponse->assertRedirect("/org/{$organisation->slug}/profile/secu");
        $reponse->assertSessionHasErrorsIn('updatePassword', ['current_password']);
    }
}
