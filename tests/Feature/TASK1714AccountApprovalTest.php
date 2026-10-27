<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\OrganizationInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TASK-1714 volet 2 — email verifie n'est pas compte valide.
 *
 * ## Le volet qui compte le plus
 *
 * « Empecher l'acces fonctionnel a l'Organization avant approbation, **sans
 * contournement par une autre route ou l'API** » (MASTER). Une garde posee sur
 * le seul groupe `web` serait verte sur tous les tests web et grande ouverte
 * sur `/api/*` — c'est exactement le trou que `RequireVerifiedEmail` laisse
 * aujourd'hui, mesure pendant l'audit. Les gardes ci-dessous verifient donc
 * les DEUX surfaces.
 *
 * ## L'approbation est une fonction VIVANTE
 *
 * Jamais un drapeau fige : `attendApprobation()` lit le reglage de
 * l'Organization ET `approved_at`. Desactiver le reglage laisse donc repasser
 * les comptes en attente, sans migration ni ecriture — c'est mesure, parce que
 * c'est une decision de conception et pas un effet de bord.
 */
class TASK1714AccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-1714', 'is_active' => true, 'is_public' => true, 'is_default' => true,
            'requires_account_approval' => true,
        ]);
        app()->instance('current_organization', $this->organisation);
    }

    private function membre(array $surcharges = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(),
            'approved_at' => null,
        ], $surcharges));
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. La garde ferme, et elle ferme AUSSI l'API
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_compte_en_attente_est_renvoye_vers_la_page_d_attente(): void
    {
        $this->actingAs($this->membre())
            ->get('/dashboard')
            ->assertRedirect(route('approval.notice'));
    }

    /**
     * Le coeur de la garde : l'API ne doit PAS etre une porte de service.
     *
     * `RequireVerifiedEmail` ne vit que dans le groupe `web`. Poser
     * l'approbation au meme endroit l'aurait rendue contournable ici, ou
     * `auth:sanctum` ouvre les memes donnees.
     */
    public function test_un_compte_en_attente_est_refuse_sur_l_api(): void
    {
        $attente = $this->membre(['email' => 'api-attente-1714@example.test']);
        Sanctum::actingAs($attente);

        $this->getJson('/api/profile')
            ->assertStatus(403)
            ->assertJson(['message' => __('auth.account_pending_approval')]);

        // L'IDENTITE est verifiee separement, et ce n'est pas du luxe : un 403
        // rendu a un visiteur ANONYME aurait la meme forme, et le test serait
        // vert sans rien prouver. On leve l'attente, on rejoue, et la reponse
        // doit nommer CETTE personne.
        $attente->forceFill(['approved_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($attente->fresh());

        $this->getJson('/api/profile')
            ->assertSuccessful()
            ->assertJson(['id' => $attente->id, 'email' => 'api-attente-1714@example.test']);
    }

    public function test_un_compte_approuve_passe_sur_les_deux_surfaces(): void
    {
        $approuve = $this->membre(['approved_at' => now(), 'email' => 'approuve-1714@example.test']);

        $this->actingAs($approuve)->get('/dashboard')->assertSuccessful();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($approuve);

        // L'identite est asserte, pas supposee : un 200 ne dit pas QUI a repondu.
        $this->getJson('/api/profile')
            ->assertSuccessful()
            ->assertJson(['id' => $approuve->id, 'email' => 'approuve-1714@example.test']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. Ce qui reste atteignable, et ce qui ne l'est pas
    // ───────────────────────────────────────────────────────────────────────

    public function test_une_page_publique_reste_ouverte_a_un_compte_en_attente(): void
    {
        $this->actingAs($this->membre())->get('/mentions-legales')->assertSuccessful();
    }

    public function test_la_page_d_attente_est_atteignable(): void
    {
        $this->actingAs($this->membre())
            ->get(route('approval.notice'))
            ->assertSuccessful()
            ->assertSee('data-marqueur="attente-approbation"', false);
    }

    public function test_la_deconnexion_reste_possible(): void
    {
        $this->actingAs($this->membre())->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    /**
     * L'ordre est « email d'abord, approbation ensuite ».
     *
     * Un compte non verifie ET en attente doit pouvoir verifier son adresse :
     * sinon les deux gardes se bloquent mutuellement et la personne est
     * enfermee.
     */
    public function test_la_verification_d_email_reste_atteignable(): void
    {
        $this->actingAs($this->membre(['email_verified_at' => null]))
            ->get(route('verification.notice'))
            ->assertSuccessful();
    }

    /**
     * `profile.*` est REFUSE, et c'est une difference deliberee.
     *
     * `RequireVerifiedEmail` ouvre le profil parce qu'une adresse mal saisie
     * s'y corrige — sans cette porte, une faute de frappe rend le compte
     * definitivement inutilisable. Un compte en attente, lui, n'a RIEN a
     * corriger : l'attente ne depend pas de lui.
     */
    public function test_le_profil_n_est_PAS_une_exception(): void
    {
        $this->actingAs($this->membre())
            ->get(route('profile.edit'))
            ->assertRedirect(route('approval.notice'));
    }

    /**
     * L'administration reste ouverte (arbitrage MASTER A4).
     *
     * C'est la que vit « Valider compte ». Un SuperAdmin qui se retrouverait
     * en attente et ne pourrait pas y acceder ne pourrait debloquer personne,
     * lui compris.
     */
    public function test_l_administration_reste_ouverte_a_un_admin_en_attente(): void
    {
        $this->actingAs($this->membre(['is_admin' => true]))
            ->get('/admin/users')
            ->assertSuccessful();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. Fonction VIVANTE, et non-retroactivite
    // ───────────────────────────────────────────────────────────────────────

    public function test_desactiver_le_reglage_laisse_repasser_un_compte_en_attente(): void
    {
        $membre = $this->membre();

        $this->actingAs($membre)->get('/dashboard')->assertRedirect(route('approval.notice'));

        $this->organisation->forceFill(['requires_account_approval' => false])->save();

        // `forgetGuards()` est INDISPENSABLE ici, et `fresh()` ne suffit pas.
        //
        // Mesure : sur la seconde requete, la garde recevait l'objet User de la
        // PREMIERE — identite verifiee par `spl_object_id` — avec sa relation
        // `organization` chargee et donc le reglage PERIME. La session posee
        // par la requete 1 authentifie toujours, et le guard rend l'utilisateur
        // qu'il a deja resolu : `actingAs($membre->fresh())` ne le remplace
        // pas. C'est la lecon T1689, en plus profond — ce n'est pas MA variable
        // qu'il faut rafraichir, c'est le GUARD qu'il faut oublier.
        $this->app['auth']->forgetGuards();

        // L'assertion porte sur « plus vers la page d'attente », et non sur un
        // 200 : `/dashboard` redirige de toute facon vers l'accueil canonique
        // de l'Organization. Exiger un 200 mesurerait le routage, pas la garde.
        $reponse = $this->actingAs($membre->fresh())->get('/dashboard');

        $this->assertNotSame(route('approval.notice'), $reponse->headers->get('Location'));
    }

    /**
     * Activer le reglage ne bloque PAS les comptes existants.
     *
     * C'est la demande explicite de MASTER, et la migration la rend lisible :
     * tout compte anterieur porte `approved_at = created_at`.
     */
    public function test_activer_le_reglage_ne_bloque_pas_un_compte_anterieur(): void
    {
        $ancien = $this->membre(['approved_at' => now()->subYear()]);

        $this->actingAs($ancien)->get('/dashboard')->assertSuccessful();
    }

    public function test_un_compte_sans_organisation_n_est_jamais_en_attente(): void
    {
        $orphelin = User::factory()->create(['email_verified_at' => now(), 'approved_at' => null]);
        // Le contexte d'Organization pose `organization_id` a la creation : on
        // force le vide, puis on ASSERTE la precondition. Un test qui suppose
        // son propre point de depart peut passer pour la mauvaise raison.
        $orphelin->forceFill(['organization_id' => null])->save();
        $orphelin = $orphelin->fresh();

        $this->assertNull($orphelin->organization_id, 'Le banc n\'a pas produit un compte sans Organization.');
        $this->assertFalse($orphelin->attendApprobation());
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. Les quatre chemins de creation
    // ───────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function inscription(array $surcharges = []): array
    {
        return array_merge([
            'first_name' => 'Camille', 'name' => 'Dubois',
            'email' => 'nouveau-1714@example.test', 'phone' => '0601020304',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026', 'password_confirmation' => 'MotDePasse!2026',
        ], $surcharges);
    }

    public function test_une_inscription_web_attend_quand_l_organisation_l_exige(): void
    {
        $this->post('/register', $this->inscription())->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'nouveau-1714@example.test')->first()?->approved_at);
    }

    public function test_une_inscription_web_est_approuvee_quand_l_organisation_n_exige_rien(): void
    {
        $this->organisation->forceFill(['requires_account_approval' => false])->save();

        $this->post('/register', $this->inscription())->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'nouveau-1714@example.test')->first()?->approved_at);
    }

    /**
     * L'API suit la MEME regle.
     *
     * `Api/AuthController::register()` est une copie ligne pour ligne de
     * l'inscription web. Un predicat recopie est un predicat qui diverge : la
     * garde fige les deux.
     */
    public function test_une_inscription_api_attend_quand_l_organisation_l_exige(): void
    {
        $this->postJson('/api/auth/register', $this->inscription(['email' => 'api-1714@example.test']))
            ->assertSuccessful();

        $this->assertNull(User::where('email', 'api-1714@example.test')->first()?->approved_at);
    }

    /**
     * Une invitation EST une approbation (arbitrage MASTER A2).
     *
     * Quelqu'un de l'interieur a deja vouche : faire attendre une personne
     * explicitement conviee n'ajouterait aucune decision. Le chemin passe par
     * le MEME `forceFill()` que `email_verified_at` et `must_set_password`.
     */
    public function test_un_compte_ne_d_une_invitation_est_approuve_d_office(): void
    {
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $this->organisation->id,
            'recipient_email' => 'invite-1714@example.test',
            'status' => OrganizationInvitation::STATUS_PENDING,
            'expires_at' => now()->addWeek(),
        ]);

        app(OrganizationInvitationService::class)->accept($invitation->token);

        $invite = User::where('email', 'invite-1714@example.test')->first();

        $this->assertNotNull($invite, 'L\'invitation n\'a cree aucun compte.');
        $this->assertNotNull($invite->approved_at, 'Un compte ne d\'une invitation attend a tort.');
        $this->assertFalse($invite->attendApprobation());
    }

    public function test_un_compte_cree_par_un_administrateur_est_approuve_d_office(): void
    {
        $admin = $this->membre(['is_admin' => true, 'approved_at' => now()]);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Cree Par Admin',
            'email' => 'par-admin-1714@example.test',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
            'points' => 0,
        ])->assertSessionHasNoErrors();

        // Le compte doit exister AVANT qu'on interroge son approbation :
        // `first()?->approved_at` rend `null` quand rien n'a ete cree, et le
        // test aurait alors echoue en accusant la mauvaise ligne.
        $cree = User::where('email', 'par-admin-1714@example.test')->first();
        $this->assertNotNull($cree, 'Le compte n\'a pas ete cree par l\'administrateur.');
        $this->assertNotNull($cree->approved_at);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 5. L'action « Valider compte »
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_superadmin_valide_un_compte_et_lui_ouvre_l_acces(): void
    {
        $admin = $this->membre(['is_admin' => true, 'approved_at' => now()]);
        $attente = $this->membre(['email' => 'attente-1714@example.test']);

        $this->actingAs($admin)->post(route('admin.users.approve', $attente))->assertRedirect();

        $this->assertNotNull($attente->fresh()->approved_at);

        // Meme piege que plus haut, et il rendait ce test VERT POUR LA MAUVAISE
        // RAISON : sans `forgetGuards()`, la seconde requete reste authentifiee
        // comme l'ADMIN — qui est approuve — et le 200 ne prouvait donc rien du
        // compte valide. Mesure par `spl_object_id` lors du diagnostic.
        $this->app['auth']->forgetGuards();

        $reponse = $this->actingAs($attente->fresh())->get('/dashboard');

        $this->assertNotSame(route('approval.notice'), $reponse->headers->get('Location'));
    }

    public function test_un_membre_ordinaire_ne_peut_pas_valider_un_compte(): void
    {
        $attente = $this->membre(['email' => 'cible-1714@example.test']);
        $quidam = $this->membre(['email' => 'quidam-1714@example.test', 'approved_at' => now()]);

        $this->actingAs($quidam)->post(route('admin.users.approve', $attente));

        $this->assertNull($attente->fresh()->approved_at, 'Un membre ordinaire a valide un compte.');
    }

    /**
     * Valider deux fois ne REECRIT pas la date.
     *
     * Deux onglets ouverts suffisent a produire le cas. `approved_at` doit
     * rester la date de la DECISION, pas celle du dernier clic.
     */
    public function test_valider_deux_fois_ne_reecrit_pas_la_date(): void
    {
        $admin = $this->membre(['is_admin' => true, 'approved_at' => now()]);
        $attente = $this->membre(['email' => 'deux-fois-1714@example.test']);

        $this->actingAs($admin)->post(route('admin.users.approve', $attente));
        $premiere = $attente->fresh()->approved_at;

        $this->travel(5)->minutes();
        $this->actingAs($admin)->post(route('admin.users.approve', $attente));

        $this->assertEquals($premiere, $attente->fresh()->approved_at);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 6. Gardes architecturales
    // ───────────────────────────────────────────────────────────────────────

    /**
     * `approved_at` ne doit JAMAIS devenir assignable en masse.
     *
     * Garde architecturale, pas comportementale : aucun test fonctionnel ne
     * rougirait le jour ou quelqu'un l'ajoute a `$fillable` « pour simplifier ».
     * Un formulaire qui recopierait trop large permettrait alors a un compte de
     * s'auto-approuver. Meme doctrine que `email_verified_at` et
     * `must_set_password`.
     */
    public function test_approved_at_n_est_pas_assignable_en_masse(): void
    {
        $this->assertNotContains('approved_at', (new User)->getFillable());
    }

    /**
     * Le reglage est dans SA section, en tete de page — et le reglage REPOND.
     *
     * La premiere version ne verifiait que la presence du marqueur, et elle
     * etait VERTE alors que Cyril ne trouvait pas la case : elle vivait a 91 %
     * d'une page de 4957 px, enfouie dans la section « Appartenance » — qui
     * configure en realite un champ de profil libre, pas l'adhesion.
     *
     * Une garde de presence ne dit rien de la trouvabilite. Celle-ci mesure
     * donc la PLACE : une section dediee, et avant « Informations ».
     */
    public function test_le_reglage_a_sa_propre_section_en_tete_de_page(): void
    {
        $admin = $this->membre(['is_admin' => true, 'approved_at' => now()]);
        $cible = Organization::factory()->create(['slug' => 'reglage-1714', 'requires_account_approval' => false]);

        $html = $this->actingAs($admin)->get(route('admin.organizations.edit', $cible))->assertOk()->getContent();

        $section = strpos($html, 'data-marqueur="section-acces"');
        $reglage = strpos($html, 'data-marqueur="reglage-approbation"');
        $informations = strpos($html, '>Informations</h2>');

        $this->assertNotFalse($section, 'Le reglage n\'a pas de section dediee.');
        $this->assertNotFalse($reglage, 'Le reglage est absent.');
        $this->assertNotFalse($informations, 'La section « Informations » est introuvable.');

        $this->assertGreaterThan($section, $reglage, 'Le reglage est hors de sa section.');
        $this->assertLessThan($informations, $reglage, 'Le reglage est enfoui sous « Informations ».');
    }

    /**
     * Et il RÉPOND : cocher la case se persiste, et change le comportement.
     *
     * Une case qui s'affiche sans rien commander serait pire qu'une case
     * absente.
     */
    public function test_cocher_le_reglage_met_l_organisation_en_approbation(): void
    {
        $admin = $this->membre(['is_admin' => true, 'approved_at' => now()]);
        $cible = Organization::factory()->create([
            'slug' => 'persiste-1714', 'name' => 'Persiste', 'requires_account_approval' => false,
        ]);

        $this->actingAs($admin)->put(route('admin.organizations.update', $cible), [
            'name' => $cible->name,
            'slug' => $cible->slug,
            // Champs exiges par le formulaire d'edition : le test poste ce que
            // la page poste, sinon il mesure la validation et non le reglage.
            'welcome_points' => 100,
            'requires_account_approval' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($cible->fresh()->requires_account_approval, 'Le reglage ne s\'est pas persiste.');
    }
}
