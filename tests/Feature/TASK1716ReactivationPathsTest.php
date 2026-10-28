<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1716 — aucun chemin ne rouvre un compte ferme par accident.
 *
 * ## Ce que l'audit a trouve, et que MASTER a demande d'auditer
 *
 * « Empecher toute reactivation accidentelle d'un compte ferme volontairement.
 * Auditer les chemins existants de debannissement. » Il y en avait **TROIS**,
 * et aucun ne savait distinguer une sanction d'une decision personnelle :
 *
 *  1. `AdminController::unbanUser()` — `banned_at = null`. L'administrateur lit
 *     « Banni » sur la ligne, clique « Debannir », et rend un acces que
 *     personne n'avait demande.
 *  2. `OrgAdminController::toggleUserBan()` — un TOGGLE aveugle
 *     (`$user->banned_at ? null : now()`). Le plus dangereux : le bouton
 *     s'intitulait lui-meme « Debannir ».
 *  3. `AdminController::updateUser()` — decocher la case « banni » du
 *     formulaire d'edition. Le plus discret : on venait changer une ville.
 *
 * ## Pourquoi une REOUVERTURE explicite existe quand meme
 *
 * La fermeture RESERVE l'adresse email (arbitrage MASTER). Sans porte de
 * sortie, une personne qui ferme son compte par erreur ne pourrait ni y
 * revenir ni se reinscrire. Le geste n'est donc pas interdit : il est DEPLACE
 * la ou il est conscient — une autre route, un autre libelle, une
 * confirmation qui dit ce qu'elle fait.
 *
 * ## La non-regression porte la moitie de la valeur
 *
 * Chaque refus est double d'une garde qui prouve que le chemin fonctionne
 * toujours sur un VRAI banni. Sans elles, condamner les trois chemins
 * passerait aussi les tests — et casserait le bannissement administratif.
 */
class TASK1716ReactivationPathsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-1716c', 'is_active' => true, 'is_public' => true, 'is_default' => true,
        ]);
        app()->instance('current_organization', $this->organisation);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organisation->id, 'is_admin' => true,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);
    }

    private function ferme(): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);
        $user->forceFill(['banned_at' => now(), 'self_closed_at' => now()])->save();

        return $user;
    }

    private function banni(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(), 'approved_at' => now(),
            'banned_at' => now(),
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Chemin 1 — « Debannir » du SuperAdmin
    // ───────────────────────────────────────────────────────────────────────

    public function test_debannir_refuse_de_lever_une_fermeture_volontaire(): void
    {
        $ferme = $this->ferme();

        $this->actingAs($this->admin)
            ->patch(route('admin.users.unban', $ferme))
            ->assertSessionHas('error', __('admin.users_closed_use_reopen'));

        $ferme->refresh();
        $this->assertNotNull($ferme->banned_at, 'le compte doit RESTER ferme');
        $this->assertNotNull($ferme->self_closed_at);
    }

    public function test_debannir_fonctionne_toujours_sur_un_vrai_banni(): void
    {
        $banni = $this->banni();

        $this->actingAs($this->admin)->patch(route('admin.users.unban', $banni));

        $this->assertNull($banni->refresh()->banned_at);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Chemin 2 — le TOGGLE de l'administrateur d'Organization
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_toggle_d_organisation_refuse_de_lever_une_fermeture(): void
    {
        $ferme = $this->ferme();

        $this->actingAs($this->admin)
            ->patch(route('organization.admin.users.toggle-ban', [$this->organisation, $ferme]))
            ->assertSessionHas('error', __('admin.users_closed_org_admin_refused'));

        $this->assertNotNull($ferme->refresh()->banned_at);
    }

    public function test_le_toggle_d_organisation_bannit_et_debannit_toujours(): void
    {
        $actif = User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->patch(route('organization.admin.users.toggle-ban', [$this->organisation, $actif]));
        $this->assertNotNull($actif->refresh()->banned_at);

        $this->actingAs($this->admin)
            ->patch(route('organization.admin.users.toggle-ban', [$this->organisation, $actif]));
        $this->assertNull($actif->refresh()->banned_at);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Chemin 3 — la case « banni » du formulaire d'edition
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_formulaire_d_edition_n_ouvre_pas_un_compte_ferme(): void
    {
        $ferme = $this->ferme();

        // Le formulaire poste CE QUE LE FORMULAIRE POSTE : la case decochee
        // n'apparait pas dans la requete. C'est l'absence qui ecrivait
        // `banned_at = null`.
        $this->actingAs($this->admin)->put(route('admin.users.update', $ferme), [
            'name' => 'Nom Change',
            'email' => $ferme->email,
            'city' => 'Lyon',
        ]);

        $ferme->refresh();
        $this->assertNotNull($ferme->banned_at, 'la case decochee ne doit pas rouvrir le compte');
        $this->assertNotNull($ferme->self_closed_at);
        // Et le RESTE du formulaire s'enregistre : la case est ignoree, pas la
        // requete refusee. Sans cette assertion, bloquer tout l'ecran
        // passerait aussi.
        $this->assertSame('Nom Change', $ferme->name);
        $this->assertSame('Lyon', $ferme->city);
    }

    public function test_le_formulaire_d_edition_debannit_toujours_un_vrai_banni(): void
    {
        $banni = $this->banni();

        $this->actingAs($this->admin)->put(route('admin.users.update', $banni), [
            'name' => $banni->name,
            'email' => $banni->email,
        ]);

        $this->assertNull($banni->refresh()->banned_at);
    }

    // ───────────────────────────────────────────────────────────────────────
    // La porte explicite
    // ───────────────────────────────────────────────────────────────────────

    public function test_la_reouverture_explicite_efface_les_trois_colonnes(): void
    {
        $ferme = $this->ferme();
        $ferme->forceFill(['erasure_requested_at' => now()])->save();

        $this->actingAs($this->admin)
            ->patch(route('admin.users.reopen', $ferme))
            ->assertSessionHas('success', __('admin.users_reopen_done'));

        $ferme->refresh();
        $this->assertNull($ferme->banned_at);
        $this->assertNull($ferme->self_closed_at);
        // Rouvrir un compte contredit une demande d'effacement : la laisser
        // afficherait une demande pendante sur un compte actif, que personne
        // ne saurait plus comment traiter.
        $this->assertNull($ferme->erasure_requested_at);
        $this->assertFalse($ferme->estFermeVolontairement());
    }

    public function test_la_reouverture_refuse_un_compte_qui_n_est_pas_ferme(): void
    {
        $banni = $this->banni();

        $this->actingAs($this->admin)
            ->patch(route('admin.users.reopen', $banni))
            ->assertSessionHas('error', __('admin.users_reopen_not_closed'));

        // Elle ne doit pas servir de « debannir » de contrebande : un vrai
        // banni reste banni.
        $this->assertNotNull($banni->refresh()->banned_at);
    }

    public function test_la_reouverture_est_refusee_a_qui_n_est_pas_superadmin(): void
    {
        $ferme = $this->ferme();
        $simple = User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        $this->actingAs($simple)
            ->patch(route('admin.users.reopen', $ferme))
            ->assertStatus(403);

        $this->assertNotNull($ferme->refresh()->self_closed_at);
    }
}
