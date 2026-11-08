<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\OrganizationInvitationService;
use App\Support\Tenancy\RegistrationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1787 — « Interdire la création de compte », par Organization.
 *
 * ## Ce que cette suite mesure vraiment
 *
 * Pas « la case existe ». Il y a **quatre portes** vers `User::create()` et le
 * dépôt s'est déjà fait prendre à n'en fermer qu'une :
 *
 * - `Api\AuthController` : « c'est déjà ce qui était arrivé à la garde sandbox
 *   de TASK-1650, **posée sur une seule des deux portes** » ;
 * - `SandboxGuard` : « la même règle a dû être posée sur CINQ surfaces (…)
 *   chaque fois que je l'ai réécrite sur place, la suivante a été oubliée. »
 *
 * Chaque porte a donc son test, et la quatrième — la création administrative
 * directe par le SuperAdmin — a le sien pour prouver qu'elle reste OUVERTE.
 *
 * ## Le cas qui ne se devine pas
 *
 * `menuEstPropose()` exigeait `organizationsOuvertes()->count() > 1`. Avec
 * `main` fermée et **une seule** autre Organization ouverte, le menu
 * disparaissait et `store()` retombait sur l'Organization d'ARRIVÉE — celle qui
 * refuse. La personne voyait un formulaire qui ne pouvait que la refuser.
 * `test_une_seule_organization_ouverte_reste_un_parcours_praticable` le garde.
 */
class TASK1787RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    private Organization $main;

    private Organization $ofsh;

    protected function setUp(): void
    {
        parent::setUp();

        // `main` : PUBLIQUE et par DEFAUT — c'est tout l'enjeu. Elle reste
        // visible et connectable, seule son inscription se ferme.
        $this->main = Organization::factory()->create([
            'slug' => 'main-1787', 'name' => 'Main 1787',
            'is_active' => true, 'is_public' => true, 'is_default' => true,
            'registration_disabled' => false,
        ]);

        $this->ofsh = Organization::factory()->create([
            'slug' => 'ofsh-1787', 'name' => 'OFSH 1787',
            'is_active' => true, 'is_public' => true, 'is_default' => false,
            'registration_disabled' => false,
        ]);
    }

    // ------------------------------------------------------------ le defaut

    public function test_le_reglage_est_desactive_par_defaut_partout(): void
    {
        $neuve = Organization::factory()->create(['is_active' => true, 'is_public' => true]);

        $this->assertFalse($neuve->registration_disabled);
        $this->assertTrue(RegistrationGuard::estOuverteAuxInscriptions($neuve));

        // La colonne est NOT NULL : un drapeau de securite n'a pas d'etat
        // « inconnu », qui se lirait `false` dans un `if` distrait.
        $this->assertNotNull(
            \Illuminate\Support\Facades\DB::table('organizations')
                ->where('id', $neuve->id)->value('registration_disabled'),
            'La colonne est NOT NULL : la base doit porter false, jamais null.'
        );
    }

    // ------------------------------- main fermee : absente de /register, presente sur /login

    public function test_main_fermee_disparait_du_choix_d_inscription_mais_reste_sur_la_connexion(): void
    {
        $this->fermer($this->main);

        // `/register` ne propose plus `main`…
        $inscription = $this->get(route('register'))->assertOk()->getContent();
        // Le MARQUEUR d'option, et non le slug nu : celui-ci apparait ailleurs
        // dans la page (liens, URL canoniques) et l'assertion serait fausse.
        $this->assertStringNotContainsString('data-marqueur="organisation-main-1787"', $inscription);
        // …mais propose bien l'autre. Sans ce volet, une page vide passerait.
        $this->assertStringContainsString('data-marqueur="organisation-ofsh-1787"', $inscription);

        // …et la CONNEXION ne bouge pas : `main` reste `is_public`, et
        // `LoginAccountResolver` resout par adresse sans jamais interroger la
        // garde. C'est l'exigence 4 du CDC.
        $this->get(route('login'))->assertOk();
        $this->get(route('organization.login', ['organization' => 'main-1787']))->assertOk();
    }

    // --------------------------------------- /org/main/register : parcours central

    public function test_org_main_register_renvoie_au_parcours_central(): void
    {
        $this->fermer($this->main);

        $this->get(route('organization.register', ['organization' => 'main-1787']))
            ->assertRedirect(route('register'));
    }

    public function test_le_parcours_central_propose_les_autres_organizations(): void
    {
        $this->fermer($this->main);
        $autre = Organization::factory()->create([
            'slug' => 'tierce-1787', 'is_active' => true, 'is_public' => true,
            'registration_disabled' => false,
        ]);

        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="organisation-ofsh-1787"', $html);
        $this->assertStringContainsString('data-marqueur="organisation-tierce-1787"', $html);
        $this->assertStringNotContainsString('data-marqueur="organisation-main-1787"', $html);

        unset($autre);
    }

    // ------------------------------------------- PORTE 1 : le formulaire web

    public function test_un_POST_forge_vers_main_ne_cree_aucun_compte(): void
    {
        $this->fermer($this->main);

        // Le POST vise explicitement `main`, et pretend meme l'avoir choisie.
        $this->post(route('organization.register', ['organization' => 'main-1787']), $this->payload([
            'organisation_confirmee' => 'main-1787',
        ]))->assertSessionHasErrors();

        $this->assertDatabaseMissing('users', ['email' => 'forge-1787@example.test']);
        $this->assertSame(0, User::where('organization_id', $this->main->id)->count());
    }

    /**
     * **LE test de la garde serveur**, et il a fallu un sabotage pour le trouver.
     *
     * Les autres POST de cette suite passaient sans la garde : avec une autre
     * Organization encore ouverte, le menu est rendu, donc la regle
     * `Rule::in($ouvertes)` existe et refuse deja le slug ferme. Retirer la
     * garde laissait les 18 tests verts — ils mesuraient la VALIDATION, pas la
     * protection.
     *
     * Ici, plus aucune Organization n'est ouverte : `menuEstPropose()` rend
     * faux, la regle `organisation_confirmee` n'existe plus, et `store()`
     * retombe sur l'Organization d'ARRIVEE. Seul le refus serveur empeche alors
     * la creation — et c'est exactement le « POST forge » que le CDC vise.
     */
    public function test_le_refus_serveur_protege_meme_quand_aucune_regle_de_menu_n_existe(): void
    {
        $this->fermer($this->main);
        $this->fermer($this->ofsh);

        $this->post(route('organization.register', ['organization' => 'main-1787']), $this->payload())
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('users', ['email' => 'forge-1787@example.test']);
        $this->assertSame(0, User::whereNotNull('organization_id')->count());
    }

    public function test_une_organization_ouverte_accepte_toujours_les_inscriptions(): void
    {
        $this->fermer($this->main);

        // TEMOIN : sans lui, une garde qui refuserait TOUT passerait pour une
        // garde juste.
        $this->post(route('organization.register', ['organization' => 'ofsh-1787']), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'forge-1787@example.test',
            'organization_id' => $this->ofsh->id,
        ]);
    }

    public function test_une_organization_fermee_AUTRE_que_main_bloque_ecran_et_POST(): void
    {
        $this->fermer($this->ofsh);

        // L'ecran : un message explicite et un lien vers SA connexion.
        $html = $this->get(route('organization.register', ['organization' => 'ofsh-1787']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-marqueur="inscriptions-fermees"', $html);
        $this->assertStringContainsString('OFSH 1787', $html);
        $this->assertStringContainsString(
            route('organization.login', ['organization' => 'ofsh-1787']),
            $html
        );

        // Le POST : refuse, et aucun compte.
        $this->post(route('organization.register', ['organization' => 'ofsh-1787']), $this->payload())
            ->assertSessionHasErrors();
        $this->assertDatabaseMissing('users', ['email' => 'forge-1787@example.test']);
    }

    // ------------------------------------------------- PORTE 2 : l'API publique

    /**
     * La porte la plus facile a oublier : `/api/auth/register` n'a AUCUN
     * prefixe d'Organization, donc `currentOrganization()` y est nul et c'est
     * l'Organization PAR DEFAUT qui s'applique — `main`.
     */
    public function test_l_API_publique_refuse_aussi_quand_main_est_fermee(): void
    {
        $this->fermer($this->main);

        $this->postJson('/api/auth/register', [
            'name' => 'Forge',
            'first_name' => 'Api',
            'email' => 'api-1787@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'api-1787@example.test']);
    }

    public function test_l_API_accepte_toujours_quand_l_organization_par_defaut_est_ouverte(): void
    {
        // TEMOIN de la porte 2.
        $this->postJson('/api/auth/register', [
            'name' => 'Temoin',
            'first_name' => 'Api',
            'email' => 'api-ouvert-1787@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'api-ouvert-1787@example.test']);
    }

    // ------------------------------------------------ PORTE 3 : les invitations

    public function test_une_organization_fermee_n_emet_plus_d_invitation(): void
    {
        $this->fermer($this->main);
        $admin = $this->membre($this->main, ['is_admin' => true]);

        $resultat = app(OrganizationInvitationService::class)->invite(
            $this->main, $admin, 'invite-1787@example.test', 'Jean', 'Dupont', 'fr'
        );

        $this->assertSame(
            OrganizationInvitationService::CASE_REGISTRATION_CLOSED,
            $resultat['case']
        );
        $this->assertNull($resultat['invitation']);
        $this->assertDatabaseMissing('users', ['email' => 'invite-1787@example.test']);
    }

    public function test_une_invitation_PENDANTE_ne_cree_plus_de_compte_apres_fermeture(): void
    {
        $admin = $this->membre($this->main, ['is_admin' => true]);

        // Emise AVANT la fermeture : le cas que seule la garde a l'acceptation
        // attrape.
        $emise = app(OrganizationInvitationService::class)->invite(
            $this->main, $admin, 'pendante-1787@example.test', 'Jeanne', 'Martin', 'fr'
        );
        $this->assertNotNull($emise['invitation']);

        $this->fermer($this->main);

        $resultat = app(OrganizationInvitationService::class)->accept(
            $emise['invitation']->token
        );

        $this->assertSame(
            OrganizationInvitationService::RESULT_REGISTRATION_CLOSED,
            $resultat['result']
        );
        $this->assertDatabaseMissing('users', ['email' => 'pendante-1787@example.test']);
    }

    public function test_une_organization_ouverte_invite_toujours(): void
    {
        $this->fermer($this->main);
        $admin = $this->membre($this->ofsh, ['is_admin' => true]);

        // TEMOIN : fermer `main` ne doit PAS perturber les autres Organizations.
        $resultat = app(OrganizationInvitationService::class)->invite(
            $this->ofsh, $admin, 'invite-ouvert-1787@example.test', 'Luc', 'Bernard', 'fr'
        );

        $this->assertNotSame(
            OrganizationInvitationService::CASE_REGISTRATION_CLOSED,
            $resultat['case']
        );
    }

    // ----------------------------- PORTE 4 : la creation SuperAdmin reste OUVERTE

    /**
     * Exigence 6 du CDC : « preserver la creation administrative de comptes de
     * test par le SuperAdmin ».
     *
     * Ce chemin ecrit DIRECTEMENT (`AdminController::storeUser()`), sans
     * invitation : il n'est pas touche, et ce test le prouve plutot que de le
     * supposer.
     */
    public function test_le_SuperAdmin_cree_toujours_un_compte_sur_une_organization_fermee(): void
    {
        $this->fermer($this->main);
        $admin = $this->membre($this->main, ['is_admin' => true]);

        // Les champs sont ceux de CET ecran — `points` y est requis, et il
        // n'attend ni `phone` ni `country_code`. Les recopier depuis le
        // formulaire d'inscription aurait fait rougir pour une mauvaise raison.
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Compte Test',
            'email' => 'superadmin-1787@example.test',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
            'points' => 100,
        ]);

        $this->assertDatabaseHas('users', ['email' => 'superadmin-1787@example.test']);
    }

    // ------------------------------------------------- les cas de bord du menu

    /**
     * UNE SEULE Organization ouverte : le parcours doit rester praticable.
     *
     * C'est le defaut que `menuEstPropose()` portait : `count() > 1` tombait a
     * faux, le menu disparaissait, et `store()` retombait sur l'arrivee —
     * c'est-a-dire sur l'Organization fermee.
     */
    public function test_une_seule_organization_ouverte_reste_un_parcours_praticable(): void
    {
        $this->fermer($this->main);
        // `ofsh` est desormais la SEULE ouverte.

        $html = $this->get(route('register'))->assertOk()->getContent();
        $this->assertStringContainsString('data-marqueur="organisation-ofsh-1787"', $html);

        // Et l'inscription aboutit vraiment, en designant la seule ouverte.
        $this->post(route('register'), $this->payload([
            'organisation_confirmee' => 'ofsh-1787',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'forge-1787@example.test',
            'organization_id' => $this->ofsh->id,
        ]);
    }

    public function test_aucune_organization_ouverte_rend_un_message_adapte(): void
    {
        $this->fermer($this->main);
        $this->fermer($this->ofsh);

        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="inscriptions-fermees-partout"', $html);
        // Et surtout PAS une liste vide, qui ressemblerait a un bogue.
        $this->assertStringNotContainsString('data-marqueur="inscriptions-fermees-alternatives"', $html);
    }

    // ------------------------------- les membres existants ne sont pas touches

    public function test_un_membre_existant_de_main_se_connecte_et_demande_son_mot_de_passe(): void
    {
        $membre = $this->membre($this->main, ['email' => 'ancien-1787@example.test']);

        $this->fermer($this->main);

        // Connexion : inchangee. `organization` est REQUIS sur la route
        // globale — le selecteur de connexion de T1728 — et c'est au passage la
        // preuve que `main` y figure toujours : un slug absent de ce selecteur
        // serait refuse.
        $this->post(route('login'), [
            'email' => 'ancien-1787@example.test',
            'password' => 'MotDePasse!2026',
            'organization' => 'main-1787',
        ])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($membre);

        // Mot de passe oublie : inchange.
        $this->post(route('password.email'), ['email' => 'ancien-1787@example.test'])
            ->assertSessionHasNoErrors();
    }

    public function test_fermer_une_organization_ne_modifie_aucun_compte(): void
    {
        $this->membre($this->main, ['email' => 'intact-1787@example.test']);
        $avant = User::where('organization_id', $this->main->id)->get()
            ->map(fn (User $u) => [$u->id, $u->email, $u->organization_id])->all();

        $this->fermer($this->main);

        $apres = User::where('organization_id', $this->main->id)->get()
            ->map(fn (User $u) => [$u->id, $u->email, $u->organization_id])->all();

        $this->assertSame($avant, $apres, 'Fermer les inscriptions ne touche aucun compte.');
    }

    // ----------------------------------------------- le reglage, cote SuperAdmin

    public function test_le_SuperAdmin_bascule_le_reglage_sans_toucher_is_public(): void
    {
        $admin = $this->membre($this->main, ['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $this->main))
            ->assertOk()
            ->assertSee('data-marqueur="reglage-inscriptions-fermees"', false);

        $this->actingAs($admin)->put(route('admin.organizations.update', $this->main), [
            'name' => $this->main->name,
            'slug' => $this->main->slug,
            // `welcome_points` est REQUIS par cet ecran : l'omettre faisait
            // echouer la validation, donc rien n'etait ecrit.
            'welcome_points' => 100,
            'is_public' => '1',
            'registration_disabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->main->refresh();

        $this->assertTrue($this->main->registration_disabled);
        // L'INDEPENDANCE des deux reglages est le coeur de la demande.
        $this->assertTrue($this->main->is_public, 'main doit rester publique.');
    }

    // ------------------------------------------------------------------ outils

    private function fermer(Organization $organization): void
    {
        $organization->forceFill(['registration_disabled' => true])->save();
        $organization->refresh();
    }

    private function membre(Organization $org, array $surcharges = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $org->id,
            'password' => Hash::make('MotDePasse!2026'),
        ], $surcharges));
    }

    /** @return array<string, string> */
    private function payload(array $surcharges = []): array
    {
        return array_merge([
            'name' => 'Dupont',
            'first_name' => 'Jean',
            'email' => 'forge-1787@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
        ], $surcharges);
    }
}
