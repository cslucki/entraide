<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1881 (demande Cyril, 22/11) — « Membres autorises a se connecter ».
 *
 * Outil temporaire : tout le monde est autorise par defaut ; le superadmin
 * decoche ceux qui ne peuvent plus se connecter. Le responsable de
 * l'Organization et le superadmin restent toujours autorises. Decocher ferme
 * aussi une session deja ouverte, a la page suivante.
 */
class TASK1881AuthorizedMembersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superadmin;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'name' => 'Main Démo']);
        $this->superadmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $this->responsable = User::factory()->create(['organization_id' => $this->org->id, 'password' => Hash::make('password')]);
        $this->org->update(['admin_id' => $this->responsable->id]);
    }

    private function membre(array $attributs = []): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'password' => Hash::make('password')] + $attributs);
    }

    private function connexion(User $user, string $motDePasse = 'password')
    {
        return $this->post(route('organization.login', ['organization' => $this->org->slug]), [
            'email' => $user->email,
            'password' => $motDePasse,
        ]);
    }

    public function test_un_membre_autorise_par_defaut_se_connecte(): void
    {
        $membre = $this->membre();

        $this->assertNull($membre->login_blocked_at);
        $this->connexion($membre)->assertRedirect();
        $this->assertAuthenticatedAs($membre);
    }

    public function test_un_membre_bloque_voit_que_l_organisation_est_fermee(): void
    {
        $membre = $this->membre(['login_blocked_at' => now()]);

        $this->connexion($membre)
            ->assertSessionHasErrors(['email' => 'Main Démo est fermé, merci de choisir une autre organisation.']);
        $this->assertGuest();
    }

    public function test_sans_le_bon_mot_de_passe_rien_ne_revele_le_blocage(): void
    {
        $membre = $this->membre(['login_blocked_at' => now()]);

        $this->connexion($membre, 'mauvais')->assertSessionHasErrors('email');
        $this->assertStringNotContainsString('est fermé', (string) session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_le_responsable_et_le_superadmin_ne_sont_jamais_bloques(): void
    {
        $this->responsable->forceFill(['login_blocked_at' => now()])->save();
        $this->superadmin->forceFill(['login_blocked_at' => now()])->save();

        $this->assertNotNull($this->responsable->fresh()->login_blocked_at, 'Precondition : la date est bien posee.');
        $this->assertFalse($this->responsable->fresh()->estBloqueALaConnexion());
        $this->assertFalse($this->superadmin->fresh()->estBloqueALaConnexion());

        $this->connexion($this->responsable->fresh())->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->responsable);
    }

    public function test_une_session_ouverte_est_fermee_a_la_page_suivante(): void
    {
        $membre = $this->membre();
        $this->actingAs($membre);
        $membre->forceFill(['login_blocked_at' => now()])->save();

        $this->get(route('organization.flux', ['organization' => $this->org->slug]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Main Démo est fermé, merci de choisir une autre organisation.']);
        $this->assertGuest();
    }

    public function test_la_page_liste_les_membres_et_enregistre_les_cases(): void
    {
        $alice = $this->membre(['first_name' => 'Alice', 'name' => 'Martin']);
        $bob = $this->membre(['first_name' => 'Bob', 'name' => 'Durand', 'login_blocked_at' => now()]);
        $etranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $url = route('admin.organizations.authorized-members', $this->org);

        $this->actingAs($this->superadmin)->get(route('admin.organizations.edit', $this->org))
            ->assertOk()->assertSee($url, false);

        $this->actingAs($this->superadmin)->get($url)->assertOk()
            ->assertSee('Alice')->assertSee('Martin')->assertSee($alice->email)
            ->assertSee($alice->created_at->timezone('Europe/Paris')->format('d/m/Y'))
            ->assertDontSee($etranger->email);

        // Alice decochee, Bob coche ; le responsable, absent de l'envoi, reste autorise.
        $this->actingAs($this->superadmin)
            ->put(route('admin.organizations.authorized-members.update', $this->org), ['authorized' => [$bob->id]])
            ->assertRedirect($url);

        $this->assertNotNull($alice->fresh()->login_blocked_at);
        $this->assertNull($bob->fresh()->login_blocked_at);
        $this->assertNull($this->responsable->fresh()->login_blocked_at);
        $this->assertNull($this->superadmin->fresh()->login_blocked_at);
        $this->assertNull($etranger->fresh()->login_blocked_at);
    }

    public function test_la_page_est_reservee_au_superadmin(): void
    {
        $this->actingAs($this->responsable)
            ->get(route('admin.organizations.authorized-members', $this->org))
            ->assertForbidden();

        $this->actingAs($this->responsable)
            ->put(route('admin.organizations.authorized-members.update', $this->org), ['authorized' => []])
            ->assertForbidden();
    }
}
