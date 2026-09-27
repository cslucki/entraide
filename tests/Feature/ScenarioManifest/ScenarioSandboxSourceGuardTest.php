<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1650 — la sandbox se ferme A LA SOURCE, pas seulement a la destruction.
 *
 * ## Pourquoi ce fichier existe
 *
 * Le preflight du Scenario Manager DETECTE le contenu etranger avant de
 * detruire. Deux relectures independantes ont montre que detecter ne suffit
 * pas : tant que `/admin/users` accepte de placer un vrai compte dans une
 * sandbox, la contamination continue de NAITRE, et chaque detection nouvelle
 * arrive apres coup.
 *
 * Verdict MASTER du 27/09 : « tant qu'une surface PROD existante permet
 * d'injecter un vrai utilisateur dans leur cible, leur securite n'est pas
 * fermee. »
 *
 * ## Les deux sens
 *
 * Interdire seulement l'ENTREE laisserait ouvert le chemin qui rend le contenu
 * orphelin : la personne repart, son contenu reste dans la sandbox et meurt
 * avec elle. Ces tests prouvent donc les deux sens, et prouvent aussi que le
 * cas ordinaire — deux Organizations normales — passe toujours. Une garde
 * qu'on ne teste que dans le sens du refus finit par tout refuser.
 */
class ScenarioSandboxSourceGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);
    }

    public function test_affecter_un_compte_reel_a_une_sandbox_est_refuse(): void
    {
        $sandbox = $this->sandbox();
        $client = Organization::factory()->create();
        $personne = User::factory()->create(['organization_id' => $client->id]);

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.users.assign-organization', $personne), [
                'organization_id' => $sandbox->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            $client->id,
            $personne->fresh()->organization_id,
            'Le compte NE DOIT PAS avoir bouge.'
        );
    }

    public function test_sortir_un_compte_d_une_sandbox_est_refuse(): void
    {
        // Le sens que j'avais d'abord oublie. C'est pourtant LUI qui rend le
        // contenu orphelin : la personne repart, ses messages restent dans la
        // sandbox, et le Reset ou le Remove les emporte.
        $sandbox = $this->sandbox();
        $client = Organization::factory()->create();
        $personne = User::factory()->create(['organization_id' => $sandbox->id]);

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.users.assign-organization', $personne), [
                'organization_id' => $client->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            $sandbox->id,
            $personne->fresh()->organization_id,
            'Le compte NE DOIT PAS avoir bouge.'
        );
    }

    public function test_la_fiche_complete_refuse_aussi_les_deux_sens(): void
    {
        // `updateUser()` est un SECOND chemin vers la meme ecriture. T1639 a
        // deja coute une divergence de ce genre : le comptage et le transfert
        // ne portaient pas le meme filtre. Les deux chemins partagent donc ici
        // le meme predicat, et ce test le prouve sur le chemin le moins
        // evident.
        $sandbox = $this->sandbox();
        $client = Organization::factory()->create();
        $personne = User::factory()->create(['organization_id' => $client->id]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $personne), [
                'name' => $personne->name,
                'email' => $personne->email,
                'organization_id' => $sandbox->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame($client->id, $personne->fresh()->organization_id);

        $persona = User::factory()->create(['organization_id' => $sandbox->id]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $persona), [
                'name' => $persona->name,
                'email' => $persona->email,
                'organization_id' => $client->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame($sandbox->id, $persona->fresh()->organization_id);
    }

    public function test_entre_deux_organizations_ordinaires_l_affectation_marche_toujours(): void
    {
        // L'autre sens de la garde. Sans ce test, la reduire a
        // `return back()->with('error', ...)` inconditionnel resterait VERT
        // sur les trois tests precedents.
        $depuis = Organization::factory()->create();
        $vers = Organization::factory()->create();
        $personne = User::factory()->create(['organization_id' => $depuis->id]);

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.users.assign-organization', $personne), [
                'organization_id' => $vers->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame($vers->id, $personne->fresh()->organization_id);
    }

    public function test_un_persona_reste_modifiable_DANS_sa_propre_sandbox(): void
    {
        // Une garde posee sur « l'origine est une sandbox » sans exclure la
        // destination IDENTIQUE empecherait de corriger le nom d'un persona.
        // Le Scenario Manager gere ces comptes : il ne faut pas les geler.
        $sandbox = $this->sandbox();
        $persona = User::factory()->create(['organization_id' => $sandbox->id]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $persona), [
                'name' => 'Persona corrige',
                'email' => $persona->email,
                'organization_id' => $sandbox->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Persona corrige', $persona->fresh()->name);
        $this->assertSame($sandbox->id, $persona->fresh()->organization_id);
    }

    public function test_une_sandbox_ne_se_supprime_pas_depuis_l_ecran_des_organizations(): void
    {
        // Trouve en revue. La suppression generique DETACHE les comptes
        // (`organization_id` a NULL) puis supprime l'Organization : sur une
        // sandbox, ses personas survivaient comme comptes sans Organization,
        // indiscernables de vrais comptes, et le registre partait en cascade.
        //
        // Aggravant : c'est T1650 qui conduit l'operateur la, par son propre
        // lien « Ouvrir la sandbox ».
        $sandbox = $this->sandbox();
        $persona = User::factory()->create(['organization_id' => $sandbox->id]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.organizations.destroy', $sandbox))
            ->assertSessionHas('error');

        $this->assertSame(1, Organization::query()->withTrashed()->whereKey($sandbox->id)->count(), 'La sandbox survit.');
        $this->assertSame($sandbox->id, $persona->fresh()->organization_id, 'Et le persona n est PAS detache.');
    }

    public function test_une_organization_ordinaire_se_supprime_toujours(): void
    {
        // L'autre sens. Sans ce test, refuser TOUTE suppression resterait vert
        // sur le precedent — et casserait une fonction de production.
        $ordinaire = Organization::factory()->create();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.organizations.destroy', $ordinaire))
            ->assertSessionHas('success');

        $this->assertSame(0, Organization::query()->withTrashed()->whereKey($ordinaire->id)->count());
    }

    public function test_on_ne_s_inscrit_PAS_dans_une_sandbox_par_son_slug_public(): void
    {
        // Le contournement le plus grave trouve en revue, et il ne demandait
        // AUCUNE authentification : `/register` vit sous `/org/{organization}`,
        // et la resolution par slug n'exige que `is_active` — or une sandbox
        // nait active. Le slug est affiche sur l'ecran du Scenario Manager.
        //
        // La consequence etait l'inverse du but : le compte cree rendait la
        // sandbox irretirable, le preflight refusant alors Reset ET Remove.
        $sandbox = $this->sandbox();
        $comptesAvant = User::query()->count();

        // URL construite EXPLICITEMENT : le groupe porte le prefixe de nom
        // `organization.`, et `route('register')` resout la route GLOBALE —
        // premiere ecriture de ce test, qui passait donc a cote de la surface
        // qu'il pretend garder.
        $this->post('/org/'.$sandbox->slug.'/register', [
            'name' => 'Intrus',
            'first_name' => 'Intrus',
            'email' => 'intrus@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'Motdepasse!2026',
            'password_confirmation' => 'Motdepasse!2026',
        ])->assertSessionHasErrors('email');

        $this->assertSame($comptesAvant, User::query()->count(), 'AUCUN compte ne doit etre cree.');
        $this->assertSame(0, User::query()->where('organization_id', $sandbox->id)->count());
    }

    public function test_la_sandbox_reste_NAVIGABLE_seule_l_inscription_est_fermee(): void
    {
        // MASTER : « une sandbox doit rester navigable et utilisable comme
        // monde de demonstration ». Fermer `findBySlug()` aurait ferme les
        // deux d'un coup — ce test interdit cette solution de facilite.
        $sandbox = $this->sandbox();

        $this->get('/org/'.$sandbox->slug.'/login')->assertOk();
    }

    public function test_l_affectation_EN_MASSE_ne_verse_pas_les_comptes_orphelins_dans_une_sandbox(): void
    {
        // La porte la plus large, trouvee a la troisieme relecture.
        //
        // `/admin/outils/assign-data` affecte d'un coup TOUTES les lignes sans
        // Organization vers la cible choisie. Sur `users`, cela versait dans la
        // sandbox tous les comptes reels orphelins — la population que la
        // suppression d'Organization fabrique justement.
        //
        // Et comme T1650 a ferme les SORTIES, l'effet n'est plus une fuite
        // mais un PIEGE : ces comptes ne peuvent plus ressortir, et la sandbox
        // ne peut plus etre retiree puisqu'elle contient desormais du contenu
        // etranger.
        $sandbox = $this->sandbox();

        $orphelin = User::factory()->create(['organization_id' => null]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $sandbox->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ])
            ->assertSessionHas('error');

        $this->assertNull($orphelin->fresh()->organization_id, 'Le compte orphelin ne doit PAS avoir ete verse dans la sandbox.');
        $this->assertSame(0, User::query()->where('organization_id', $sandbox->id)->count());
    }

    public function test_l_affectation_en_masse_vers_une_organization_ordinaire_marche_toujours(): void
    {
        // L'autre sens : la garde ne doit pas casser l'outil.
        $ordinaire = Organization::factory()->create();
        $orphelin = User::factory()->create(['organization_id' => null]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $ordinaire->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ]);

        $this->assertSame($ordinaire->id, $orphelin->fresh()->organization_id);
    }

    public function test_l_API_d_inscription_refuse_aussi_une_sandbox(): void
    {
        // Le jumeau non garde de la route web : le meme code, dans un second
        // controleur. Un predicat de securite recopie est un predicat qui
        // diverge — la garde vit donc desormais dans UNE classe partagee, et
        // ce test verifie que l'API s'en sert vraiment.
        //
        // COMMENT l'API atteint une sandbox, puisqu'elle n'a pas de route par
        // slug : pour un appelant anonyme, elle prend l'Organization par
        // defaut, et A DEFAUT la PREMIERE active. Or une sandbox nait active.
        // Sans Organization par defaut, une sandbox peut donc devenir la cible
        // d'une inscription anonyme — c'est exactement cette configuration
        // qu'on reproduit ici, plutot que de forcer la resolution a la main.
        $sandbox = $this->sandbox();

        Organization::query()->whereKeyNot($sandbox->id)->update([
            'is_active' => false,
            'is_default' => false,
        ]);

        $comptesAvant = User::query()->count();

        $this->postJson('/api/auth/register', [
            'name' => 'Intrus',
            'first_name' => 'Intrus',
            'email' => 'intrus-api@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'Motdepasse!2026',
            'password_confirmation' => 'Motdepasse!2026',
        ]);

        $this->assertSame($comptesAvant, User::query()->count(), 'AUCUN compte ne doit etre cree.');
        $this->assertSame(0, User::query()->where('organization_id', $sandbox->id)->count());
    }

    public function test_un_persona_ne_devient_pas_responsable_d_une_vraie_organization(): void
    {
        // `admin_id` confere OrgAdmin sur tout /org/{slug}/admin, et n'exige
        // aucune appartenance. La liste proposee montre tous les comptes non
        // bannis : les personas y figurent, indiscernables de vrais membres.
        $sandbox = $this->sandbox();
        $persona = User::factory()->create(['organization_id' => $sandbox->id]);
        $cliente = Organization::factory()->create();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.organizations.update', $cliente), [
                'name' => $cliente->name,
                'admin_id' => $persona->id,
                'welcome_points' => 100,
            ])
            ->assertSessionHas('error');

        $this->assertNull($cliente->fresh()->admin_id, 'Le persona ne doit PAS etre devenu responsable.');
    }

    public function test_un_membre_reel_devient_toujours_responsable(): void
    {
        // L'autre sens : la garde ne doit pas casser la designation normale.
        $cliente = Organization::factory()->create();
        $membre = User::factory()->create(['organization_id' => $cliente->id]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.organizations.update', $cliente), [
                'name' => $cliente->name,
                'admin_id' => $membre->id,
                'welcome_points' => 100,
            ]);

        $this->assertSame($membre->id, $cliente->fresh()->admin_id);
    }

    public function test_le_slug_d_une_sandbox_ne_se_modifie_pas(): void
    {
        // L'identite des personas derive du slug. Le liberer permettrait a une
        // sandbox suivante de le reprendre et de capturer, par updateOrCreate,
        // les comptes de la premiere.
        $sandbox = $this->sandbox();
        $slugOrigine = $sandbox->slug;

        $this->actingAs($this->superAdmin)
            ->put(route('admin.organizations.update', $sandbox), [
                'name' => $sandbox->name,
                'slug' => 'slug-repris',
                'welcome_points' => 100,
            ])
            ->assertSessionHas('error');

        $this->assertSame($slugOrigine, $sandbox->fresh()->slug);
    }

    /**
     * Une sandbox se reconnait a `scenario_sandbox_created_at`, que SEUL le
     * provisionneur pose — et qui n'est pas dans `$fillable`.
     */
    private function sandbox(): Organization
    {
        $organization = Organization::factory()->create(['name' => 'Sandbox de recette']);
        $organization->forceFill(['scenario_sandbox_created_at' => now()])->save();

        return $organization->fresh();
    }
}
