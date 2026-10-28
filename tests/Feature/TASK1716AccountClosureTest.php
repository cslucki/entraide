<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1716 — la fermeture volontaire d'un compte.
 *
 * ## Le defaut corrige, et pourquoi il a survecu
 *
 * `DELETE /profile` appelait `$user->delete()` nu. Chaque inscription ecrit une
 * ligne `point_ledger` (`reason = welcome_bonus`), et depuis TASK-1635 cette
 * table porte un `ON DELETE RESTRICT` : la suppression levait donc une
 * `QueryException` pour TOUT compte inscrit. Mesure de l'audit :
 *
 *     QueryException : FOREIGN KEY constraint failed (delete from "users" ...)
 *     compte survit         : OUI
 *     encore authentifie    : NON
 *
 * Un 500, la personne deconnectee, et le compte intact. Aucun test ne couvrait
 * `profile.destroy` — zero resultat sur `tests/` — ce qui explique que T1635
 * ait pose les RESTRICT sans rien faire rougir. La premiere garde ci-dessous
 * est donc celle qui manquait.
 *
 * ## Ce que les gardes mesurent, et ce qu'elles ne mesurent pas
 *
 * Elles mesurent l'ETAT en base et le COMPORTEMENT des gardes, jamais le
 * libelle d'un bouton pour lui-meme : un libelle se verifie quand il porte une
 * distinction qui change ce que la personne comprend — ici « ferme » contre
 * « banni », et la seule difference qui compte est celle du message de refus.
 */
class TASK1716AccountClosureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-1716', 'is_active' => true, 'is_public' => true, 'is_default' => true,
            'requires_account_approval' => false,
        ]);
        app()->instance('current_organization', $this->organisation);
    }

    private function membre(array $surcharges = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(),
            'approved_at' => now(),
            'password' => Hash::make('mot-de-passe-valide'),
        ], $surcharges));
    }

    /**
     * Le bonus de bienvenue, tel que `RegisteredUserController` l'ecrit.
     *
     * C'est LUI qui faisait lever la FK RESTRICT. Les gardes qui prouvent que
     * la fermeture n'echoue plus doivent donc l'avoir en base, sinon elles
     * passeraient sur un compte que l'ancien code supprimait tres bien.
     */
    private function bonusDeBienvenue(User $user): PointLedger
    {
        return PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => null,
            'delta' => 100,
            'organization_id' => $user->organization_id,
            'reason' => 'welcome_bonus',
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. Le 500 est mort : la fermeture aboutit sur le compte qui le levait
    // ───────────────────────────────────────────────────────────────────────

    public function test_la_fermeture_aboutit_sur_un_compte_portant_le_bonus_de_bienvenue(): void
    {
        $user = $this->membre();
        $this->bonusDeBienvenue($user);

        $reponse = $this->actingAs($user)
            ->delete(route('account.close'), ['password' => 'mot-de-passe-valide']);

        // La page de connexion de SON Organization, meme idiome que la
        // deconnexion. Defaut vu dans le navigateur : fermer depuis
        // `/org/ofsh/profile/edit` renvoyait sur la page nue.
        $reponse->assertRedirect(route('organization.login', ['organization' => $this->organisation->slug]));
        $reponse->assertSessionHasNoErrors();
        // `status` est rendu par `<x-auth-session-status>` : le message n'est
        // pas pose dans le vide.
        $reponse->assertSessionHas('status', __('profile.close_account_done'));
    }

    // Pas de garde sur « aucune Organization courante » : `currentOrganization()`
    // retombe sur l'Organization PAR DEFAUT, y compris pour un compte dont
    // `organization_id` est nul — mesure faite en voulant ecrire cette garde.
    // La branche `null` de `close()` reste donc du code defensif INATTEIGNABLE
    // tant qu'une Organization par defaut existe, et un test qui pretendrait
    // l'exercer mesurerait en realite la redirection vers l'Organization par
    // defaut. On la NOMME plutot que de la faire semblant de couvrir.

    public function test_la_fermeture_ne_supprime_NI_le_compte_NI_sa_ligne_de_points(): void
    {
        $user = $this->membre();
        $ligne = $this->bonusDeBienvenue($user);

        $this->actingAs($user)->delete(route('account.close'), ['password' => 'mot-de-passe-valide']);

        // Le coeur de la TASK : la fermeture n'efface RIEN.
        $this->assertNotNull(User::find($user->id), 'le compte doit survivre a sa fermeture');
        $this->assertNotNull(PointLedger::find($ligne->id), 'la ligne de points doit survivre');
    }

    public function test_la_fermeture_pose_les_deux_horodatages_et_pas_la_demande_d_effacement(): void
    {
        $user = $this->membre();

        $this->actingAs($user)->delete(route('account.close'), ['password' => 'mot-de-passe-valide']);

        $user->refresh();

        $this->assertNotNull($user->banned_at, 'la desactivation effective passe par banned_at');
        $this->assertNotNull($user->self_closed_at, 'l origine volontaire doit etre horodatee');
        // Arbitrage MASTER : la case n'est jamais prechee, et son absence ne
        // doit donc poser aucune demande.
        $this->assertNull($user->erasure_requested_at);
        $this->assertTrue($user->estFermeVolontairement());
        $this->assertFalse($user->aDemandeEffacement());
    }

    public function test_la_case_cochee_depose_une_demande_d_effacement(): void
    {
        $user = $this->membre();

        $this->actingAs($user)->delete(route('account.close'), [
            'password' => 'mot-de-passe-valide',
            'erasure' => '1',
        ]);

        $this->assertTrue($user->refresh()->aDemandeEffacement());
    }

    public function test_la_route_profile_destroy_n_existe_plus(): void
    {
        // La garde est ARCHITECTURALE : elle interdit qu'on reintroduise un
        // nom de route qui, dans ce depot, a toujours voulu dire
        // `$user->delete()` nu.
        $this->assertFalse(
            app('router')->has('profile.destroy'),
            'profile.destroy appelait $user->delete() nu : le nom ne doit pas revenir',
        );
        $this->assertFalse(app('router')->has('organization.profile.destroy'));
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. Le mot de passe, et l'ORDRE des gestes
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_mot_de_passe_errone_ne_ferme_rien_et_ne_deconnecte_pas(): void
    {
        $user = $this->membre();

        $this->actingAs($user)
            ->delete(route('account.close'), ['password' => 'ce-n-est-pas-le-bon'])
            ->assertSessionHasErrors('password', null, 'userDeletion');

        $user->refresh();
        $this->assertNull($user->banned_at);
        $this->assertNull($user->self_closed_at);
        // C'est tout l'interet d'ecrire AVANT de deconnecter : l'ancien code
        // deconnectait d'abord, donc un echec laissait la personne dehors avec
        // un compte intact et aucun moyen de recommencer.
        $this->assertTrue($this->isAuthenticated());
    }


    public function test_la_fermeture_deconnecte(): void
    {
        $user = $this->membre();

        $this->actingAs($user)->delete(route('account.close'), ['password' => 'mot-de-passe-valide']);

        $this->assertFalse($this->isAuthenticated());
    }

    public function test_la_fermeture_revoque_les_jetons_d_api(): void
    {
        $user = $this->membre();
        $user->createToken('mobile');
        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($user)->delete(route('account.close'), ['password' => 'mot-de-passe-valide']);

        // Pas une ligne de code dans `close()` : le hook `saved()` de `User`
        // supprime les jetons des que `banned_at` passe non-nul. La garde
        // mesure que la fermeture emprunte bien ce chemin-la.
        $this->assertSame(0, $user->tokens()->count());
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. Un compte ferme ne revient pas, et on ne lui dit pas n'importe quoi
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Un compte DEJA ferme, sans passer par la requete de fermeture.
     *
     * Les gardes de connexion modelisent une personne qui REVIENT : une
     * session neuve, un navigateur neuf. Enchainer `actingAs()` + fermeture +
     * `POST /login` dans un meme test ferait porter la mesure sur l'etat
     * d'authentification que le banc traine entre deux requetes — pas sur ce
     * que vit un visiteur. L'etat est donc pose en base, comme la fermeture
     * l'ecrit (gardes ci-dessus), et la connexion part de rien.
     */
    private function dejaFerme(array $surcharges = []): User
    {
        $user = $this->membre($surcharges);
        $user->forceFill(['banned_at' => now(), 'self_closed_at' => now()])->save();

        return $user;
    }

    public function test_un_compte_ferme_ne_peut_pas_se_reconnecter(): void
    {
        $user = $this->dejaFerme();

        $this->post('/login', ['email' => $user->email, 'password' => 'mot-de-passe-valide'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_le_refus_de_connexion_distingue_fermeture_et_bannissement(): void
    {
        $this->dejaFerme(['email' => 'ferme@1716.test']);

        $this->post('/login', ['email' => 'ferme@1716.test', 'password' => 'mot-de-passe-valide'])
            ->assertSessionHasErrors(['email' => trans('auth.closed_by_owner')]);
    }

    public function test_un_compte_banni_par_un_administrateur_garde_l_ancien_message(): void
    {
        // La NON-REGRESSION qui donne son sens a la garde precedente : sans
        // elle, un message unique passerait les deux.
        $banni = $this->membre(['email' => 'banni@1716.test', 'banned_at' => now()]);
        $this->assertFalse($banni->estFermeVolontairement());

        $this->post('/login', ['email' => 'banni@1716.test', 'password' => 'mot-de-passe-valide'])
            ->assertSessionHasErrors(['email' => trans('auth.deactivated')]);
    }

    public function test_une_seconde_session_ouverte_ailleurs_est_fermee_avec_le_bon_message(): void
    {
        // La revocation des acces se CONSTATE ici : la fermeture deconnecte la
        // session qui l'a demandee, une autre est fermee a sa requete suivante.
        $user = $this->dejaFerme();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', trans('auth.closed_by_owner'));
    }

    public function test_l_api_refuse_un_compte_ferme_avec_le_bon_message(): void
    {
        $user = $this->dejaFerme();

        // `EnsureUserIsNotBanned` vit dans les DEUX groupes : la distinction de
        // message ne doit pas s'arreter a `web`.
        $this->withHeader('Accept', 'application/json')
            ->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertStatus(403)
            ->assertJson(['message' => trans('auth.closed_by_owner')]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. Les drapeaux ne sont pas remplissables, et les scopes les excluent
    // ───────────────────────────────────────────────────────────────────────

    public function test_les_trois_drapeaux_resistent_a_l_assignation_de_masse(): void
    {
        $user = $this->membre();

        // Un formulaire de profil ne doit pas pouvoir fermer — ni rouvrir — un
        // compte en postant un champ de plus.
        $user->fill([
            'self_closed_at' => now(),
            'erasure_requested_at' => now(),
        ]);

        $this->assertNull($user->self_closed_at);
        $this->assertNull($user->erasure_requested_at);
    }

    public function test_un_compte_ferme_sort_des_comptes_actifs_et_n_est_plus_affichable(): void
    {
        $user = $this->membre();
        $this->actingAs($user)->delete(route('account.close'), ['password' => 'mot-de-passe-valide']);

        // Arbitrage MASTER (question 2) : OUI, sans une ligne de code — c'est
        // `banned_at` qui porte la desactivation, et les scopes le lisent deja.
        // La garde verifie que la fermeture emprunte bien ce chemin.
        $this->assertFalse(
            User::query()->activeAccount()->whereKey($user->id)->exists(),
            'scopeActiveAccount doit exclure un compte ferme',
        );
        $this->assertFalse($user->refresh()->isDisplayableIn($this->organisation));
        $this->assertTrue($user->isDeactivated());
    }
}
