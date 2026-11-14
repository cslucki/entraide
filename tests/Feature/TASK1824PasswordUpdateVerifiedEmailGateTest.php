<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1824 — `password.update` manquait a `RequireVerifiedEmail::ALWAYS_ALLOWED`.
 *
 * ## Le defaut mesure (audit lecture-seule prealable)
 *
 * `profile.*`/`organization.profile.*` sont deja ouverts par PREFIXE : la page
 * du formulaire de mot de passe (`profile.edit`) restait donc accessible a un
 * compte non verifie. Mais le `PUT /password` qu'elle soumet est une route
 * GLOBALE (`password.update`), hors de ce prefixe, et absente de la liste de
 * noms exacts. Le compte voyait le formulaire, le soumettait, et etait
 * renvoye vers `/verify-email` SANS aucune erreur affichee et SANS que le mot
 * de passe change — un echec silencieux. `test_compte_non_verifie_peut_desormais_changer_son_mot_de_passe`
 * est la garde qui rougit si la ligne ajoutee a TASK-1824 est retiree.
 *
 * Le compte NON APPROUVE n'avait besoin d'AUCUN correctif : la page et la
 * soumission etaient deja bloquees de facon coherente (mesure), voir
 * `test_compte_non_approuve_reste_bloque_page_et_soumission`.
 */
class TASK1824PasswordUpdateVerifiedEmailGateTest extends TestCase
{
    use RefreshDatabase;

    private function organisation(bool $approbationRequise = false): Organization
    {
        return Organization::factory()->create([
            'is_active' => true,
            'is_default' => true,
            'requires_account_approval' => $approbationRequise,
        ]);
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

    private function soumettre(User $auteur, Organization $organisation, array $champs = [])
    {
        return $this->actingAs($auteur)
            ->from("/org/{$organisation->slug}/profile/edit")
            ->put('/password', array_merge([
                'current_password' => 'ancien-secret-1234',
                'password' => 'nouveau-secret-9876',
                'password_confirmation' => 'nouveau-secret-9876',
            ], $champs));
    }

    /** 1. Compte verifie ET approuve : le changement reussit. */
    public function test_compte_verifie_et_approuve_peut_changer_son_mot_de_passe(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->soumettre($membre, $organisation);

        $reponse->assertRedirect("/org/{$organisation->slug}/profile/edit");
        $this->assertSame('password-updated', session('status'));
        $this->assertTrue(Hash::check('nouveau-secret-9876', $membre->fresh()->password));
    }

    /**
     * 2. Compte non verifie : le changement reussit DESORMAIS, et n'est plus
     * redirige vers `/verify-email`. C'est la garde du correctif TASK-1824.
     */
    public function test_compte_non_verifie_peut_desormais_changer_son_mot_de_passe(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation, ['email_verified_at' => null]);

        $reponse = $this->soumettre($membre, $organisation);

        $reponse->assertRedirect("/org/{$organisation->slug}/profile/edit");
        $this->assertStringNotContainsString('/verify-email', $reponse->headers->get('Location') ?? '');
        $this->assertSame('password-updated', session('status'));
        $this->assertTrue(Hash::check('nouveau-secret-9876', $membre->fresh()->password));
    }

    /**
     * 3. Compte non approuve : la page ET la soumission restent bloquees,
     * sans changement de comportement — aucune modification n'a ete faite a
     * `RequireApprovedAccount`.
     */
    public function test_compte_non_approuve_reste_bloque_page_et_soumission(): void
    {
        $organisation = $this->organisation(approbationRequise: true);
        $membre = $this->membre($organisation, ['approved_at' => null]);

        $page = $this->actingAs($membre)->get("/org/{$organisation->slug}/profile/edit");
        $page->assertRedirect(route('approval.notice'));

        $reponse = $this->soumettre($membre, $organisation);
        $reponse->assertRedirect(route('approval.notice'));
        $this->assertFalse(Hash::check('nouveau-secret-9876', $membre->fresh()->password));
    }

    /** 4. Mauvais mot de passe actuel : refus de validation, hash inchange. */
    public function test_mauvais_mot_de_passe_actuel_est_refuse(): void
    {
        $organisation = $this->organisation();
        $membre = $this->membre($organisation);

        $reponse = $this->soumettre($membre, $organisation, [
            'current_password' => 'ce-n-est-pas-le-bon',
        ]);

        $reponse->assertSessionHasErrorsIn('updatePassword', ['current_password']);
        $this->assertFalse(Hash::check('nouveau-secret-9876', $membre->fresh()->password));
        $this->assertTrue(Hash::check('ancien-secret-1234', $membre->fresh()->password));
    }

    /** 5. Invite : redirection vers la connexion, rien ne change. */
    public function test_invite_est_redirige_vers_la_connexion(): void
    {
        $organisation = $this->organisation();

        $reponse = $this->put('/password', [
            'current_password' => 'peu-importe',
            'password' => 'nouveau-secret-9876',
            'password_confirmation' => 'nouveau-secret-9876',
        ]);

        $reponse->assertRedirect(route('login'));
    }

    /**
     * 6. Deux comptes lies dans deux Organizations distinctes : seul le
     * compte AUTHENTIFIE voit son mot de passe change. Aucune confusion entre
     * les deux lignes `users`, meme reliees par `account_links`.
     */
    public function test_deux_comptes_lies_seul_le_compte_authentifie_change(): void
    {
        $orgA = $this->organisation();
        $orgB = Organization::factory()->create(['is_active' => true]);

        $compteA = $this->membre($orgA, ['email' => 'personne@exemple-a.test']);
        $compteB = $this->membre($orgB, [
            'email' => 'personne@exemple-b.test',
            'password' => Hash::make('secret-compte-b'),
        ]);

        [$premier, $second] = $compteA->id < $compteB->id
            ? [$compteA, $compteB]
            : [$compteB, $compteA];

        AccountLink::create([
            'user_a_id' => $premier->id,
            'user_b_id' => $second->id,
            'created_by_user_id' => $compteA->id,
        ]);

        $reponse = $this->soumettre($compteA, $orgA);

        $reponse->assertRedirect("/org/{$orgA->slug}/profile/edit");
        $this->assertTrue(Hash::check('nouveau-secret-9876', $compteA->fresh()->password));
        // Le compte B, simplement LIE, garde son propre mot de passe intact.
        $this->assertTrue(Hash::check('secret-compte-b', $compteB->fresh()->password));
        $this->assertFalse(Hash::check('nouveau-secret-9876', $compteB->fresh()->password));
    }
}
