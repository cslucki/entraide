<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopAiAssistant;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopAiAssistants;
use App\Services\Loops\LoopPluginActivation;
use App\Services\Loops\LoopPluginAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1616 / SLICE B — le plugin s'active DANS une Boucle, et se regle.
 *
 * Ce fichier mesure trois choses, et la premiere commande les deux autres :
 *
 *  1. **le hard gate Organization.** Sans disponibilite accordee par le
 *     SuperAdmin (SLICE A), le plugin n'existe pas pour cette Boucle : absent
 *     de l'ecran, activation refusee, configuration refusee — y compris par
 *     POST forge. Retirer l'autorisation eteint toutes les Boucles de
 *     l'Organization d'un coup, sans reecrire une seule ligne ;
 *  2. **les permissions.** `loop_plugins.configure` et rien d'autre. Owner et
 *     facilitator OUI, membre NON. La doctrine des Cards n'est pas touchee ;
 *  3. **la persistance.** Les postures SURVIVENT a l'extinction, dans la
 *     Boucle comme dans l'Organization.
 *
 * Ce qu'il ne mesure PAS, parce que rien de tout cela n'existe encore :
 * aucune capability, aucun appel provider, aucun Evidence Build, aucun
 * follow-up. Un test qui les nommerait mesurerait une intention.
 */
class TASK1616LoopPluginActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN = 'multi_ai_assistants';

    private Organization $autorisee;

    private Organization $nonAutorisee;

    private Loop $loop;

    private Loop $loopAilleurs;

    private User $superAdmin;

    private User $owner;

    private User $facilitator;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        // Une Organization par defaut doit exister : les routes `/org/` la
        // resolvent avant tout, et son absence rend 404 avant meme d'atteindre
        // la garde qu'on veut mesurer.
        Organization::factory()->create(['is_active' => true, 'is_default' => true]);

        // `loop_composition_policy` : l'ecran `/outils` est garde par
        // `LoopPresetConfigurator::canConfigure()`, anterieur a cette TASK.
        // Sans cette politique, le PROPRIETAIRE lui-meme recoit 403 sur la
        // page — et le test mesurerait cette garde-la, pas le plugin.
        $this->autorisee = Organization::factory()->create([
            'name' => 'Alpha', 'is_active' => true, 'loops_enabled' => true,
            'loop_composition_policy' => 'owner_allowed',
        ]);
        $this->nonAutorisee = Organization::factory()->create([
            'name' => 'Beta', 'is_active' => true, 'loops_enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->autorisee->id,
            'preferred_locale' => 'fr',
        ]);

        $this->owner = $this->membreDe($this->autorisee, 'owner');
        $this->facilitator = $this->membreDe($this->autorisee, 'facilitator');
        $this->membre = $this->membreDe($this->autorisee, 'member');

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->autorisee->id,
            'created_by' => $this->owner->id,
            'status' => 'active',
            'type' => 'general',
        ]);

        $this->loopAilleurs = Loop::factory()->create([
            'organization_id' => $this->nonAutorisee->id,
            'created_by' => $this->superAdmin->id,
        ]);

        $this->adhesion($this->loop, $this->owner, 'owner');
        $this->adhesion($this->loop, $this->facilitator, 'facilitator');
        $this->adhesion($this->loop, $this->membre, 'member');

        // La portee `/org/` se lit sur l'Organization COURANTE : sans ce lien,
        // `currentOrganization()` est nulle et la garde rend 404 avant la
        // permission.
        app()->instance('current_organization', $this->autorisee);

        // SLICE A : le SuperAdmin autorise le plugin pour Alpha, PAS pour Beta.
        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->autorisee, true, $this->superAdmin);
    }

    // ── 1. HARD GATE ORGANIZATION ───────────────────────────────────────────

    public function test_le_plugin_est_disponible_dans_une_organization_autorisee(): void
    {
        $this->assertTrue(app(LoopPluginActivation::class)->isAvailableFor(self::PLUGIN, $this->loop));
    }

    public function test_le_plugin_n_est_pa_s_disponible_dans_une_organization_non_autorisee(): void
    {
        $this->assertFalse(app(LoopPluginActivation::class)->isAvailableFor(self::PLUGIN, $this->loopAilleurs));
    }

    /**
     * « Pas autorise » n'est pas « eteint ». L'ecran doit rendre un tableau
     * VIDE, et non un plugin affiche desactive : un plugin visible suggere un
     * geste possible.
     */
    public function test_une_organization_non_autorisee_ne_voit_aucun_plugin(): void
    {
        $activation = app(LoopPluginActivation::class);

        $this->assertNotSame([], $activation->describeFor($this->loop));
        $this->assertSame([], $activation->describeFor($this->loopAilleurs));
    }

    public function test_activer_dans_une_organization_non_autorisee_est_refuse_par_le_service(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(LoopPluginActivation::class)
            ->setEnabled(self::PLUGIN, $this->loopAilleurs, true, $this->superAdmin);
    }

    /** Un POST forge par un SuperAdmin lui-meme ne franchit pas le gate. */
    public function test_un_post_forge_sur_une_organization_non_autorisee_est_refuse(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.plugins.update', [
                'loop' => $this->loopAilleurs->id,
                'plugin' => self::PLUGIN,
            ]), ['enabled' => 1])
            ->assertForbidden();

        $this->assertDatabaseCount('loop_plugins', 0);
    }

    public function test_configurer_une_organization_non_autorisee_est_refuse(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.plugins.configure', [
                'loop' => $this->loopAilleurs->id,
                'plugin' => self::PLUGIN,
            ]))
            ->assertForbidden();
    }

    /**
     * Retirer la disponibilite a l'Organization eteint TOUTES ses Boucles,
     * sans qu'aucune ligne `loop_plugins` ne soit reecrite. C'est ce qui rend
     * un retrait immediat — la Product Spec exige qu'un plugin puisse etre
     * coupe vite.
     */
    public function test_retirer_l_autorisation_organization_eteint_la_boucle_sans_reecrire_sa_ligne(): void
    {
        $activation = app(LoopPluginActivation::class);
        $activation->setEnabled(self::PLUGIN, $this->loop, true, $this->owner);

        $this->assertTrue($activation->isEnabled(self::PLUGIN, $this->loop));

        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->autorisee, false, $this->superAdmin);

        $this->assertFalse($activation->isEnabled(self::PLUGIN, $this->loop->fresh()));

        // La ligne de la Boucle n'a pas bouge : elle dit toujours « allume ».
        // C'est l'etage du dessus qui refuse, et rallumer l'Organization doit
        // rendre la Boucle telle qu'elle etait.
        $this->assertDatabaseHas('loop_plugins', [
            'loop_id' => $this->loop->id,
            'plugin_key' => self::PLUGIN,
            'enabled' => true,
        ]);

        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->autorisee, true, $this->superAdmin);

        $this->assertTrue($activation->isEnabled(self::PLUGIN, $this->loop->fresh()));
    }

    // ── 2. PERMISSIONS ──────────────────────────────────────────────────────

    public function test_owner_et_facilitator_peuvent_configurer_le_membre_non(): void
    {
        $activation = app(LoopPluginActivation::class);

        $this->assertTrue($activation->canConfigure($this->owner, self::PLUGIN, $this->loop));
        $this->assertTrue($activation->canConfigure($this->facilitator, self::PLUGIN, $this->loop));
        $this->assertFalse($activation->canConfigure($this->membre, self::PLUGIN, $this->loop));
        $this->assertFalse($activation->canConfigure(null, self::PLUGIN, $this->loop));
    }

    public function test_le_facilitator_active_le_plugin_depuis_la_surface_organization(): void
    {
        $this->actingAs($this->facilitator)
            ->put($this->urlOrg('organization.loops.plugins.update'), ['enabled' => 1])
            ->assertRedirect();

        $this->assertTrue(app(LoopPluginActivation::class)->isEnabled(self::PLUGIN, $this->loop));
        $this->assertDatabaseHas('loop_plugins', [
            'loop_id' => $this->loop->id,
            'organization_id' => $this->autorisee->id,
            'enabled' => true,
            'updated_by' => $this->facilitator->id,
        ]);
    }

    public function test_le_membre_ne_peut_ni_activer_ni_configurer(): void
    {
        $this->actingAs($this->membre)
            ->put($this->urlOrg('organization.loops.plugins.update'), ['enabled' => 1])
            ->assertForbidden();

        $this->actingAs($this->membre)
            ->get($this->urlOrg('organization.loops.plugins.configure'))
            ->assertForbidden();

        $this->assertDatabaseCount('loop_plugins', 0);
    }

    /**
     * Le nouveau droit ne donne AUCUN pouvoir sur la composition. La doctrine
     * de TASK-1083 — `loops.manage_cards` absente de l'owner comme du
     * facilitator — doit rester vraie apres cette TASK.
     */
    public function test_le_nouveau_droit_n_elargit_pas_les_droits_cards(): void
    {
        $defauts = config('loop_permissions.role_defaults');

        foreach (['owner', 'facilitator', 'member'] as $role) {
            $this->assertNotContains('loops.manage_cards', $defauts[$role],
                'TASK-1083 : `loops.manage_cards` ne doit revenir dans AUCUN socle de role.');
        }

        $this->assertContains('loop_plugins.configure', $defauts['owner']);
        $this->assertContains('loop_plugins.configure', $defauts['facilitator']);
        $this->assertNotContains('loop_plugins.configure', $defauts['member']);
    }

    // ── 3. ACTIVATION ET PERSISTANCE ────────────────────────────────────────

    public function test_ferme_par_defaut(): void
    {
        $this->assertFalse(app(LoopPluginActivation::class)->isEnabled(self::PLUGIN, $this->loop));
        $this->assertDatabaseCount('loop_plugins', 0);
    }

    public function test_activer_puis_desactiver_conserve_la_ligne_et_sa_trace(): void
    {
        $this->actingAs($this->owner)
            ->put($this->urlOrg('organization.loops.plugins.update'), ['enabled' => 1])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->put($this->urlOrg('organization.loops.plugins.update'), ['enabled' => 0])
            ->assertRedirect();

        $this->assertFalse(app(LoopPluginActivation::class)->isEnabled(self::PLUGIN, $this->loop));
        $this->assertDatabaseCount('loop_plugins', 1);
        $this->assertDatabaseHas('loop_plugins', [
            'loop_id' => $this->loop->id,
            'enabled' => false,
            'updated_by' => $this->owner->id,
        ]);
    }

    public function test_activer_une_boucle_n_active_pas_sa_voisine(): void
    {
        $voisine = Loop::factory()->create([
            'organization_id' => $this->autorisee->id,
            'created_by' => $this->owner->id,
        ]);

        $activation = app(LoopPluginActivation::class);
        $activation->setEnabled(self::PLUGIN, $this->loop, true, $this->owner);

        $this->assertTrue($activation->isEnabled(self::PLUGIN, $this->loop));
        $this->assertFalse($activation->isEnabled(self::PLUGIN, $voisine));
    }

    // ── 4. LES TROIS ASSISTANTS ─────────────────────────────────────────────

    public function test_le_catalogue_garde_limen_mais_les_ecrans_ne_proposent_que_deux_roles(): void
    {
        // TASK-1621 — `limen` est DORMANT, pas supprime. La distinction porte
        // tout : ses lignes existent en base, des bulles deja publiees portent
        // sa cle, et `label()` doit encore savoir la rendre. Ce qu'on retire,
        // c'est sa presence sur les ECRANS de reglage.
        $service = app(LoopAiAssistants::class);

        $this->assertSame(['aperio', 'traverse', 'limen'], $service->keys(),
            'le catalogue reste complet : les donnees anciennes doivent rester lisibles');
        $this->assertTrue($service->exists('limen'));
        $this->assertSame('Limen', $service->label('limen'));

        $this->assertSame(['aperio', 'traverse'], array_keys($service->catalogueVivant()),
            'les ecrans ne proposent que les roles reellement lances');
    }

    public function test_sans_reglage_une_boucle_herite_des_postures_par_defaut(): void
    {
        $assistants = app(LoopAiAssistants::class)->describeFor($this->loop);

        $this->assertCount(2, $assistants, 'TASK-1621 : deux roles proposes, limen est dormant');
        $this->assertDatabaseCount('loop_ai_assistants', 0);

        foreach ($assistants as $assistant) {
            $this->assertNull($assistant['own_instruction'], 'aucun ecart ne doit exister par defaut');
            $this->assertNotSame('', $assistant['instruction'], 'la posture en vigueur vient du catalogue');
            $this->assertTrue($assistant['enabled'], 'les deux repondent tant que personne ne les eteint');
        }
    }

    public function test_une_posture_reecrite_est_enregistree_et_relue(): void
    {
        $this->actingAs($this->owner)
            ->put($this->urlOrg('organization.loops.plugins.configure.update'), [
                'assistants' => [
                    'aperio' => ['instruction' => 'Chercher ce qui tient debout dans cette piste.', 'enabled' => 1],
                    'traverse' => ['instruction' => '', 'enabled' => 1],
                    'limen' => ['instruction' => '', 'enabled' => 1],
                ],
            ])
            ->assertRedirect();

        $assistants = collect(app(LoopAiAssistants::class)->describeFor($this->loop->fresh()))->keyBy('key');

        $this->assertSame('Chercher ce qui tient debout dans cette piste.', $assistants['aperio']['instruction']);
        $this->assertTrue($assistants['aperio']['customised']);

        // L'autre role suit toujours le catalogue : aucun ecart n'a ete ecrit
        // pour lui. `limen` ne figure plus a l'ecran (dormant), mais la ligne
        // envoyee pour lui reste ACCEPTEE : un formulaire ancien, ou un
        // reglage ecrit avant le pivot, ne doit pas faire echouer la
        // sauvegarde des roles vivants.
        $this->assertNull($assistants['traverse']['own_instruction']);
        $this->assertArrayNotHasKey('limen', $assistants->all(),
            'un role dormant ne se propose plus au reglage');
        $this->assertDatabaseCount('loop_ai_assistants', 1);
    }

    /**
     * Vider le champ SUPPRIME l'ecart au lieu de recopier le defaut. Sinon la
     * Boucle figerait la posture du jour et cesserait de suivre le produit.
     */
    public function test_vider_une_posture_retire_l_ecart_au_lieu_de_figer_le_defaut(): void
    {
        $service = app(LoopAiAssistants::class);

        $service->save($this->loop, ['aperio' => ['instruction' => 'Une posture locale.', 'enabled' => true]], $this->owner);
        $this->assertDatabaseCount('loop_ai_assistants', 1);

        $service->save($this->loop, ['aperio' => ['instruction' => '', 'enabled' => true]], $this->owner);
        $this->assertDatabaseCount('loop_ai_assistants', 0);

        $assistants = collect($service->describeFor($this->loop))->keyBy('key');
        $this->assertNull($assistants['aperio']['own_instruction']);
        $this->assertSame($service->defaultInstruction('aperio'), $assistants['aperio']['instruction']);
    }

    public function test_une_posture_identique_au_defaut_n_est_pas_un_ecart(): void
    {
        $service = app(LoopAiAssistants::class);

        $service->save($this->loop, [
            'aperio' => ['instruction' => $service->defaultInstruction('aperio'), 'enabled' => true],
        ], $this->owner);

        $this->assertDatabaseCount('loop_ai_assistants', 0);
    }

    public function test_eteindre_un_assistant_est_enregistre(): void
    {
        app(LoopAiAssistants::class)->save($this->loop, [
            'traverse' => ['instruction' => '', 'enabled' => false],
        ], $this->owner);

        $assistants = collect(app(LoopAiAssistants::class)->describeFor($this->loop))->keyBy('key');

        $this->assertFalse($assistants['traverse']['enabled']);
        $this->assertTrue($assistants['aperio']['enabled']);
        $this->assertDatabaseHas('loop_ai_assistants', [
            'loop_id' => $this->loop->id,
            'key' => 'traverse',
            'enabled' => false,
            'updated_by' => $this->owner->id,
        ]);
    }

    /** Un quatrieme assistant ne se cree pas par un formulaire forge. */
    public function test_une_cle_hors_catalogue_est_ignoree(): void
    {
        app(LoopAiAssistants::class)->save($this->loop, [
            'oracle' => ['instruction' => 'Tout savoir.', 'enabled' => true],
        ], $this->owner);

        $this->assertDatabaseCount('loop_ai_assistants', 0);
        $this->assertCount(2, app(LoopAiAssistants::class)->describeFor($this->loop));
    }

    /**
     * LE point de la Product Spec : desactiver ne detruit pas le travail de
     * reglage. Ni l'extinction dans la Boucle, ni le retrait de la
     * disponibilite Organization.
     */
    public function test_les_postures_survivent_a_l_extinction_de_la_boucle_e_t_de_l_organization(): void
    {
        $service = app(LoopAiAssistants::class);
        $activation = app(LoopPluginActivation::class);

        $service->save($this->loop, ['limen' => ['instruction' => 'Chercher le point commun.', 'enabled' => true]], $this->owner);
        $activation->setEnabled(self::PLUGIN, $this->loop, true, $this->owner);

        $activation->setEnabled(self::PLUGIN, $this->loop, false, $this->owner);
        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->autorisee, false, $this->superAdmin);

        $this->assertDatabaseHas('loop_ai_assistants', [
            'loop_id' => $this->loop->id,
            'key' => 'limen',
            'instruction' => 'Chercher le point commun.',
        ]);

        $this->assertSame(1, LoopAiAssistant::query()->where('loop_id', $this->loop->id)->count());
    }

    public function test_les_reglages_d_une_boucle_ne_fuient_pas_vers_une_autre(): void
    {
        $voisine = Loop::factory()->create([
            'organization_id' => $this->autorisee->id,
            'created_by' => $this->owner->id,
        ]);

        $service = app(LoopAiAssistants::class);
        $service->save($this->loop, ['aperio' => ['instruction' => 'Posture de la premiere.', 'enabled' => true]], $this->owner);

        $ici = collect($service->describeFor($this->loop))->keyBy('key');
        $ailleurs = collect($service->describeFor($voisine))->keyBy('key');

        $this->assertSame('Posture de la premiere.', $ici['aperio']['instruction']);
        $this->assertNull($ailleurs['aperio']['own_instruction']);
    }

    // ── 5. LES DEUX SURFACES ────────────────────────────────────────────────

    public function test_la_surface_superadmin_montre_le_plugin_a_cote_des_actions_de_chatloop(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.plugins.multi_ai_assistants.label'))
            ->assertSee('data-plugin="'.self::PLUGIN.'"', false)
            ->assertSee('data-plugin-status="experimental"', false);
    }

    public function test_la_surface_organization_montre_la_zone_actions_de_chatloop(): void
    {
        $this->actingAs($this->owner)
            ->get(route('organization.loops.tools', [
                'organization' => $this->autorisee->slug,
                'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->assertSee('data-zone="chatloop-actions"', false)
            ->assertSee(__('loops.plugins.multi_ai_assistants.label'));
    }

    /** L'ecran de configuration rend les trois assistants, et seulement eux. */
    public function test_l_ecran_de_configuration_ne_rend_que_les_roles_vivants(): void
    {
        // TASK-1621 — l'ecran affichait encore « Limen » et « Les trois
        // assistants » alors que le moteur ne lance plus que deux roles : un
        // membre pouvait regler une posture qui ne servait jamais.
        $this->actingAs($this->owner)
            ->get($this->urlOrg('organization.loops.plugins.configure'))
            ->assertOk()
            ->assertSee('data-assistant="aperio"', false)
            ->assertSee('data-assistant="traverse"', false)
            ->assertDontSee('data-assistant="limen"', false)
            ->assertDontSee('data-assistant="oracle"', false)
            ->assertSee(__('loops.plugins_assistants_title'))
            ->assertDontSee('Les trois assistants');
    }

    public function test_un_plugin_inconnu_rend_404(): void
    {
        $this->actingAs($this->owner)
            ->get(route('organization.loops.plugins.configure', [
                'organization' => $this->autorisee->slug,
                'loop' => $this->loop->id,
                'plugin' => 'plugin_invente',
            ]))
            ->assertNotFound();
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    private function membreDe(Organization $organization, string $suffixe): User
    {
        return User::factory()->create([
            'is_admin' => false,
            'organization_id' => $organization->id,
            'preferred_locale' => 'fr',
            'name' => ucfirst($suffixe).' '.$organization->name,
        ]);
    }

    private function adhesion(Loop $loop, User $user, string $role): void
    {
        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'organization_id' => $loop->organization_id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function urlOrg(string $name): string
    {
        return route($name, [
            'organization' => $this->autorisee->slug,
            'loop' => $this->loop->id,
            'plugin' => self::PLUGIN,
        ]);
    }
}
