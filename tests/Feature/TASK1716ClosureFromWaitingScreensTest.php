<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireApprovedAccount;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1716 — fermer son compte depuis une salle d'attente.
 *
 * ## Les deux portes, et ce qui se joue dans leur largeur
 *
 * MASTER : « permettre la fermeture depuis l'attente d'approbation et, si
 * necessaire, depuis l'attente de verification email, **sans ouvrir
 * globalement les routes du profil** ».
 *
 * La contrainte n'est pas de forme. `RequireApprovedAccount` ferme `profile.*`
 * a dessein — son docblock le dit : un compte en attente n'a RIEN a corriger,
 * l'attente ne depend pas de lui. Rouvrir le prefixe pour atteindre un bouton
 * rendrait les onze routes du profil accessibles a un compte non valide.
 *
 * `account.close` est donc une route GLOBALE, autorisee par UNE entree nommee.
 * Les gardes ci-dessous mesurent les deux moities de cette phrase : la porte
 * s'ouvre, et le reste du profil RESTE FERME. La seconde est celle qui a de la
 * valeur — sans elle, ouvrir `profile.*` passerait tous les tests.
 *
 * ## « Si necessaire » etait NECESSAIRE, et c'est mesure
 *
 * `RequireVerifiedEmail` ouvre `profile.*` en entier : l'ancienne
 * `profile.destroy` passait donc sans rien declarer. En sortant la route de ce
 * prefixe, TASK-1716 l'aurait fermee a tout compte non verifie — y compris
 * depuis la page de profil, que ce compte peut atteindre. La garde
 * `test_un_compte_non_verifie_peut_fermer_son_compte` est la contre-epreuve de
 * cette entree-la.
 */
class TASK1716ClosureFromWaitingScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-1716b', 'is_active' => true, 'is_public' => true, 'is_default' => true,
            'requires_account_approval' => true,
        ]);
        app()->instance('current_organization', $this->organisation);
    }

    private function enAttenteDApprobation(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(),
            'approved_at' => null,
            'password' => Hash::make('mot-de-passe-valide'),
        ]);
    }

    private function nonVerifie(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => null,
            'approved_at' => now(),
            'password' => Hash::make('mot-de-passe-valide'),
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. L'attente d'approbation
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_compte_en_attente_peut_fermer_son_compte(): void
    {
        $user = $this->enAttenteDApprobation();

        $this->actingAs($user)
            ->delete(route('account.close'), ['password' => 'mot-de-passe-valide'])
            ->assertRedirect(route('organization.login', ['organization' => $this->organisation->slug]));

        $this->assertTrue($user->refresh()->estFermeVolontairement());
    }

    public function test_un_compte_en_attente_reste_prive_du_reste_du_profil(): void
    {
        // LA garde qui donne son sens a la precedente : si on avait ouvert
        // `profile.*` au lieu d'une entree nommee, celle-ci rougirait.
        $this->actingAs($this->enAttenteDApprobation())
            ->get('/profile/edit')
            ->assertRedirect(route('approval.notice'));
    }

    public function test_le_prefixe_profile_n_est_pas_dans_les_exceptions_de_la_garde(): void
    {
        // Garde ARCHITECTURALE, et non comportementale : elle interdit
        // d'obtenir le comportement ci-dessus en elargissant la garde plus
        // tard, pour une raison qui semblera bonne sur le moment.
        $this->assertNotContains('profile.', RequireApprovedAccount::ALLOWED_PREFIXES);
        $this->assertNotContains('organization.profile.', RequireApprovedAccount::ALLOWED_PREFIXES);
        $this->assertContains('account.close', RequireApprovedAccount::ALWAYS_ALLOWED);
    }

    public function test_la_page_d_attente_offre_la_fermeture(): void
    {
        $this->actingAs($this->enAttenteDApprobation())
            ->get(route('approval.notice'))
            ->assertOk()
            // La REGION du DOM, pas un attribut pose pour la mesure : le
            // formulaire doit viser `account.close`, sinon le bouton ouvre un
            // panneau qui ne ferme rien.
            ->assertSee('data-marqueur="ouvrir-fermeture-depuis-attente"', false)
            ->assertSee('action="'.route('account.close').'"', false);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. L'attente de verification d'email
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_compte_non_verifie_peut_fermer_son_compte(): void
    {
        $user = $this->nonVerifie();

        $this->actingAs($user)
            ->delete(route('account.close'), ['password' => 'mot-de-passe-valide'])
            ->assertRedirect(route('organization.login', ['organization' => $this->organisation->slug]));

        $this->assertTrue($user->refresh()->estFermeVolontairement());
    }

    public function test_account_close_est_declaree_dans_la_garde_de_verification(): void
    {
        // Sans cette entree, la route sortie de `profile.*` serait renvoyee
        // vers /verify-email et ne fermerait rien — y compris depuis la page
        // de profil, que `RequireVerifiedEmail` laisse pourtant ouverte.
        $this->assertContains('account.close', RequireVerifiedEmail::ALWAYS_ALLOWED);
    }

    public function test_la_page_de_verification_offre_la_fermeture(): void
    {
        $this->actingAs($this->nonVerifie())
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('data-marqueur="ouvrir-fermeture-depuis-verification"', false)
            ->assertSee('action="'.route('account.close').'"', false);
    }

    // Pas de garde « un visiteur ne peut pas fermer un compte » : la route est
    // sous `auth`, et cette garde mesurerait le middleware de Laravel, pas
    // TASK-1716. Elle ne rougirait sur aucune erreur que nous puissions
    // commettre ici.
}
