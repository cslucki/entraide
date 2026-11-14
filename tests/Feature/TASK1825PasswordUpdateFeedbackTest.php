<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1825 — confirmation premium apres un changement de mot de passe reussi.
 *
 * Remplace l'ancien `profile.saved` (texte gris, minuteur JS de 2 secondes) par un
 * bloc `role="status"` persistant, rendu depuis le MEME signal serveur deja en
 * place (`back()->with('status', 'password-updated')`) — aucune modification du
 * controleur. `profile.saved` reste utilisee ailleurs
 * (`update-profile-information-form.blade.php`) et n'est pas touchee.
 */
class TASK1825PasswordUpdateFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function organisation(): Organization
    {
        return Organization::factory()->create([
            'is_active' => true,
            'is_default' => true,
            'requires_account_approval' => false,
        ]);
    }

    private function membre(Organization $organisation, array $surcharges = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $organisation->id,
            'email_verified_at' => now(),
            'approved_at' => now(),
            'password' => Hash::make('ancien-secret-1825'),
        ], $surcharges));
    }

    /** 1. Succes, sur la page prefixee par l'Organization : le message premium parait. */
    public function test_le_message_premium_parait_apres_un_succes_sur_la_page_secu(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)
            ->from("/org/{$organisation->slug}/profile/secu")
            ->followingRedirects()
            ->put('/password', [
                'current_password' => 'ancien-secret-1825',
                'password' => 'nouveau-secret-1825',
                'password_confirmation' => 'nouveau-secret-1825',
            ]);

        $reponse->assertOk();
        $html = $reponse->getContent();

        $this->assertStringContainsString('data-marqueur="mot-de-passe-modifie"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString(__('profile.password_updated_success'), $html);
        $this->assertStringContainsString(__('profile.password_updated_success_detail'), $html);

        // L'ancien feedback (texte gris, minuteur JS) a disparu de CETTE section.
        $this->assertStringNotContainsString('setTimeout(() => show = false, 2000)', $html);

        // Les trois champs de mot de passe sont vides : aucun ne porte la valeur soumise.
        $this->assertStringNotContainsString('value="nouveau-secret-1825"', $html);
        $this->assertStringNotContainsString('value="ancien-secret-1825"', $html);
    }

    /** 2. Sans statut de succes en session, le message n'apparait pas. */
    public function test_le_message_n_apparait_pas_sans_succes_recent(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)->get("/org/{$organisation->slug}/profile/secu");

        $reponse->assertOk();
        $this->assertStringNotContainsString('data-marqueur="mot-de-passe-modifie"', $reponse->getContent());
    }

    /** 3. Erreur de validation : le message de reussite est absent, l'erreur reste visible. */
    public function test_une_erreur_de_validation_n_affiche_pas_le_message_de_succes(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)
            ->from("/org/{$organisation->slug}/profile/secu")
            ->followingRedirects()
            ->put('/password', [
                'current_password' => 'ce-n-est-pas-le-bon',
                'password' => 'nouveau-secret-1825',
                'password_confirmation' => 'nouveau-secret-1825',
            ]);

        $reponse->assertOk();
        $html = $reponse->getContent();

        $this->assertStringNotContainsString('data-marqueur="mot-de-passe-modifie"', $html);
        $this->assertFalse(Hash::check('nouveau-secret-1825', $membre->fresh()->password));
    }

    /** 4. La forme globale (miroir) rend le meme message. */
    public function test_le_message_premium_parait_aussi_sur_la_forme_globale(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->actingAs($membre)
            ->from('/profile/secu')
            ->followingRedirects()
            ->put('/password', [
                'current_password' => 'ancien-secret-1825',
                'password' => 'nouveau-secret-1825',
                'password_confirmation' => 'nouveau-secret-1825',
            ]);

        $reponse->assertOk();
        $this->assertStringContainsString('data-marqueur="mot-de-passe-modifie"', $reponse->getContent());
    }

    /** 5. Parite FR/EN : les deux textes existent et different dans les deux langues. */
    public function test_le_message_est_traduit_en_fr_et_en(): void
    {
        $this->assertSame('Votre mot de passe a été modifié avec succès.', trans('profile.password_updated_success', [], 'fr'));
        $this->assertSame('Votre nouveau mot de passe est désormais actif pour ce compte.', trans('profile.password_updated_success_detail', [], 'fr'));
        $this->assertSame('Your password has been changed successfully.', trans('profile.password_updated_success', [], 'en'));
        $this->assertSame('Your new password is now active for this account.', trans('profile.password_updated_success_detail', [], 'en'));
    }
}
