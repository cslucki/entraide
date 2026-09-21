<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationLoopPlugin;
use App\Models\User;
use App\Services\Loops\LoopPluginAvailabilityService;
use App\Support\Loops\LoopPluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1614 / SLICE A — le catalogue plateforme et la disponibilite Organization.
 *
 * Ce fichier mesure UNE phrase, et rien d'autre :
 *
 *     « ce plugin existe au catalogue, et il est autorise ou non pour telle
 *      Organization. »
 *
 * Ce qu'il ne mesure PAS, parce que rien de tout cela n'existe encore : aucun
 * assistant, aucun prompt, aucune capability, aucun appel provider, aucune
 * activation dans une Boucle. Un test qui les nommerait mesurerait une
 * intention, pas un comportement.
 *
 * La garde centrale est la derniere : allumer dans A ne doit RIEN changer
 * dans B. Elle est assertee dans les deux sens — A allume n'allume pas B, et
 * B allume n'allume pas A —, parce qu'un seul sens laisserait passer une
 * lecture qui ignore purement et simplement l'`organization_id`.
 */
class TASK1614LoopPluginCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN = 'multi_ai_assistants';

    private User $superAdmin;

    private User $membre;

    private Organization $organisationA;

    private Organization $organisationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationA = Organization::factory()->create(['name' => 'Alpha']);
        $this->organisationB = Organization::factory()->create(['name' => 'Beta']);

        // `preferred_locale` est PINNE. Sans lui, SetLocale retombe sur la
        // langue du navigateur — absente en test — et l'ecran se rend en `en`
        // pendant que `__()` dans le processus de test rend `fr`. Le test
        // comparerait alors deux langues et echouerait sur une difference qui
        // n'est pas celle qu'il mesure.
        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->organisationA->id,
            'preferred_locale' => 'fr',
        ]);

        // Membre d'une Organization, sans aucune autorite plateforme : c'est
        // le profil qui atteint le layout admin sans etre SuperAdmin.
        $this->membre = User::factory()->create([
            'is_admin' => false,
            'organization_id' => $this->organisationA->id,
            'preferred_locale' => 'fr',
        ]);
    }

    // ── Catalogue ───────────────────────────────────────────────────────────

    public function test_le_plugin_existe_au_catalogue_plateforme(): void
    {
        $registry = app(LoopPluginRegistry::class);

        $this->assertTrue($registry->exists(self::PLUGIN));
        $this->assertSame('experimental', $registry->status(self::PLUGIN));
        $this->assertTrue($registry->isExperimental(self::PLUGIN));
        // Le mot vient du fichier de langue, pas d'une chaine recopiee ici :
        // le libelle est provisoire, la CLE ne l'est pas.
        $this->assertSame(__('loops.plugins.multi_ai_assistants.label'), $registry->label(self::PLUGIN));
        $this->assertNotSame('loops.plugins.multi_ai_assistants.label', $registry->label(self::PLUGIN));
    }

    public function test_une_cle_inconnue_n_existe_pas_au_catalogue(): void
    {
        $registry = app(LoopPluginRegistry::class);

        $this->assertFalse($registry->exists('plugin_invente'));
        $this->assertFalse($registry->exists(null));
        $this->assertFalse($registry->exists(''));
    }

    // ── Acces a la surface ──────────────────────────────────────────────────

    public function test_le_superadmin_accede_a_la_surface(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-plugins'))
            ->assertOk();
    }

    public function test_un_membre_non_superadmin_est_refuse(): void
    {
        $this->actingAs($this->membre)
            ->get(route('admin.loop-plugins'))
            ->assertForbidden();
    }

    public function test_un_visiteur_est_renvoye_vers_la_connexion(): void
    {
        $this->get(route('admin.loop-plugins'))->assertRedirect(route('login'));
    }

    public function test_un_membre_non_superadmin_ne_peut_pas_ecrire(): void
    {
        $this->actingAs($this->membre)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->organisationA->id,
                'available' => 1,
            ])
            ->assertForbidden();

        // Le refus n'est pas seulement un code : rien n'a ete ecrit.
        $this->assertDatabaseCount('organization_loop_plugins', 0);
    }

    // ── Ce que l'ecran montre ───────────────────────────────────────────────

    public function test_la_surface_affiche_le_plugin_son_statut_et_les_organizations(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-plugins'))
            ->assertOk()
            ->assertSee(__('loops.plugins.multi_ai_assistants.label'))
            ->assertSee(self::PLUGIN)
            ->assertSee(__('loops.plugins_admin_status_experimental'))
            // L'etat, lu sur l'attribut plutot que sur le mot : celui-la ne
            // depend d'aucune langue.
            ->assertSee('data-plugin-status="experimental"', false)
            ->assertSee('Alpha')
            ->assertSee('Beta');
    }

    // ── Le geste ────────────────────────────────────────────────────────────

    public function test_le_superadmin_active_le_plugin_pour_une_organization(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->organisationA->id,
                'available' => 1,
            ])
            ->assertRedirect(route('admin.loop-plugins'));

        $this->assertDatabaseHas('organization_loop_plugins', [
            'organization_id' => $this->organisationA->id,
            'plugin_key' => self::PLUGIN,
            'available' => true,
            'updated_by' => $this->superAdmin->id,
        ]);
    }

    public function test_la_desactivation_fonctionne_et_conserve_la_trace(): void
    {
        $service = app(LoopPluginAvailabilityService::class);
        $service->setAvailability(self::PLUGIN, $this->organisationA, true, $this->superAdmin);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->organisationA->id,
                'available' => 0,
            ])
            ->assertRedirect(route('admin.loop-plugins'));

        $this->assertFalse($service->isAvailable(self::PLUGIN, $this->organisationA->fresh()));

        // Eteindre ne supprime PAS la ligne : qui a coupe et quand survit au
        // geste. C'est l'ecart assume avec loop_type_settings.
        $this->assertDatabaseHas('organization_loop_plugins', [
            'organization_id' => $this->organisationA->id,
            'plugin_key' => self::PLUGIN,
            'available' => false,
            'updated_by' => $this->superAdmin->id,
        ]);
    }

    public function test_l_etat_survit_a_une_relecture_depuis_la_base(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->organisationA->id,
                'available' => 1,
            ]);

        // On repart de la base, pas d'un objet garde en memoire : c'est la
        // persistance qu'on mesure, pas le retour de la requete precedente.
        $relue = Organization::query()->findOrFail($this->organisationA->id);

        $this->assertTrue(app(LoopPluginAvailabilityService::class)->isAvailable(self::PLUGIN, $relue));

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-plugins'))
            ->assertOk()
            ->assertSee(__('loops.plugins_admin_available'));
    }

    public function test_deux_gestes_successifs_ne_creent_qu_une_ligne(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->organisationA->id,
                'available' => 1,
            ]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->organisationA->id,
                'available' => 0,
            ]);

        $this->assertDatabaseCount('organization_loop_plugins', 1);
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_ferme_par_defaut_aucune_organization_n_a_le_plugin(): void
    {
        $service = app(LoopPluginAvailabilityService::class);

        $this->assertFalse($service->isAvailable(self::PLUGIN, $this->organisationA));
        $this->assertFalse($service->isAvailable(self::PLUGIN, $this->organisationB));
        $this->assertDatabaseCount('organization_loop_plugins', 0);
    }

    public function test_activer_dans_A_ne_rend_rien_disponible_dans_B(): void
    {
        $service = app(LoopPluginAvailabilityService::class);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->organisationA->id,
                'available' => 1,
            ]);

        $this->assertTrue($service->isAvailable(self::PLUGIN, $this->organisationA));
        $this->assertFalse($service->isAvailable(self::PLUGIN, $this->organisationB));

        // Et rien n'a ete ecrit du cote de B : l'absence de fuite se lit en
        // base, pas seulement dans la reponse du service.
        $this->assertDatabaseMissing('organization_loop_plugins', [
            'organization_id' => $this->organisationB->id,
        ]);
    }

    public function test_activer_dans_B_ne_rend_rien_disponible_dans_A(): void
    {
        $service = app(LoopPluginAvailabilityService::class);

        $service->setAvailability(self::PLUGIN, $this->organisationB, true, $this->superAdmin);

        $this->assertTrue($service->isAvailable(self::PLUGIN, $this->organisationB));
        $this->assertFalse($service->isAvailable(self::PLUGIN, $this->organisationA));
    }

    public function test_eteindre_dans_A_n_eteint_pas_B(): void
    {
        $service = app(LoopPluginAvailabilityService::class);

        $service->setAvailability(self::PLUGIN, $this->organisationA, true, $this->superAdmin);
        $service->setAvailability(self::PLUGIN, $this->organisationB, true, $this->superAdmin);

        $service->setAvailability(self::PLUGIN, $this->organisationA, false, $this->superAdmin);

        $this->assertFalse($service->isAvailable(self::PLUGIN, $this->organisationA));
        $this->assertTrue($service->isAvailable(self::PLUGIN, $this->organisationB));
    }

    public function test_la_carte_par_organization_ne_lit_que_sa_propre_ligne(): void
    {
        $service = app(LoopPluginAvailabilityService::class);
        $service->setAvailability(self::PLUGIN, $this->organisationB, true, $this->superAdmin);

        $this->assertSame([self::PLUGIN => false], $service->mapFor($this->organisationA));
        $this->assertSame([self::PLUGIN => true], $service->mapFor($this->organisationB));
    }

    // ── Entrees forgees ─────────────────────────────────────────────────────

    public function test_un_plugin_inconnu_rend_404(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', 'plugin_invente'), [
                'organization_id' => $this->organisationA->id,
                'available' => 1,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('organization_loop_plugins', 0);
    }

    /**
     * `organizations.id` est une colonne `uuid`. Une valeur qui n'en est pas
     * un doit rendre 404, pas 500 : PostgreSQL leve `22P02` sur la
     * comparaison, et SQLite ne dirait rien — le defaut ne se verrait donc
     * qu'en production.
     */
    public function test_un_organization_id_qui_n_est_pas_un_uuid_rend_404(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => 'pas-un-uuid',
                'available' => 1,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('organization_loop_plugins', 0);
    }

    public function test_un_uuid_inconnu_rend_404(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => '00000000-0000-4000-8000-000000000000',
                'available' => 1,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('organization_loop_plugins', 0);
    }

    public function test_le_service_refuse_d_ecrire_une_cle_hors_catalogue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(LoopPluginAvailabilityService::class)
            ->setAvailability('plugin_invente', $this->organisationA, true, $this->superAdmin);
    }

    /**
     * Une ligne orpheline — un plugin retire du catalogue dont la decision
     * reste en base — ne doit plus autoriser quoi que ce soit. Le catalogue
     * est l'autorite sur l'EXISTENCE, la table seulement sur la portee.
     */
    public function test_une_ligne_dont_le_plugin_a_quitte_le_catalogue_n_autorise_plus_rien(): void
    {
        OrganizationLoopPlugin::query()->create([
            'organization_id' => $this->organisationA->id,
            'plugin_key' => 'plugin_retire_du_catalogue',
            'available' => true,
        ]);

        $this->assertFalse(
            app(LoopPluginAvailabilityService::class)
                ->isAvailable('plugin_retire_du_catalogue', $this->organisationA)
        );
    }
}
