<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Livewire\LoopWatchCard;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopNotificationPreference;
use App\Models\LoopWatchCategory;
use App\Models\LoopWatchItem;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopPermissionSettingsService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\Loops\LoopPluginActivation;
use App\Services\Loops\LoopPluginAvailabilityService;
use App\Services\Loops\LoopWatchService;
use App\Services\Loops\WatchException;
use App\Services\UserDataLifecycleRegistry;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventRenderer;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopPluginRegistry;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1879 — le plugin Veille collaborative.
 *
 * Trois objets distincts, et ce fichier les mesure separement :
 *
 *  - le PLUGIN : catalogue, disponibilite par Organization, activation par
 *    Boucle, et les routes IA qui ne doivent jamais le traiter comme un
 *    plugin a assistants ;
 *  - la CARD `plugin.veille` : presente seulement quand le plugin est actif,
 *    jamais composee a la main, jamais principale par defaut ; ses droits ;
 *  - l'ANNONCE ChatLoop `watch_item` : metadata minimales, relecture en
 *    direct ; notification in-app + Web Push (PWA) aux membres, jamais
 *    d'e-mail (demande Cyril, 22/11).
 */
class TASK1879VeillePluginTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN = 'veille';

    private Organization $orgA;

    private Organization $orgB;

    private Loop $loop;

    private Loop $autreBoucle;

    private Loop $boucleB;

    private User $superAdmin;

    private User $owner;

    private User $facilitator;

    private User $membre;

    private User $autreMembre;

    private User $ancien;

    private User $etranger;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);

        $this->orgA = Organization::factory()->create([
            'name' => 'Alpha', 'is_active' => true, 'loops_enabled' => true,
            'loop_composition_policy' => 'owner_allowed',
        ]);
        $this->orgB = Organization::factory()->create([
            'name' => 'Beta', 'is_active' => true, 'loops_enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->orgA->id, 'preferred_locale' => 'fr',
        ]);

        $this->owner = $this->personne($this->orgA, 'Owner');
        $this->facilitator = $this->personne($this->orgA, 'Animateur');
        $this->membre = $this->personne($this->orgA, 'Membre');
        $this->autreMembre = $this->personne($this->orgA, 'Autre');
        $this->ancien = $this->personne($this->orgA, 'Ancien');
        $this->etranger = $this->personne($this->orgB, 'Etranger');

        $this->loop = $this->boucle($this->orgA, $this->owner);
        $this->autreBoucle = $this->boucle($this->orgA, $this->owner);
        $this->boucleB = $this->boucle($this->orgB, $this->etranger);

        $this->adhesion($this->loop, $this->owner, 'owner');
        $this->adhesion($this->loop, $this->facilitator, 'facilitator');
        $this->adhesion($this->loop, $this->membre, 'member');
        $this->adhesion($this->loop, $this->autreMembre, 'member');
        $this->adhesion($this->loop, $this->ancien, 'member', 'removed');
        $this->adhesion($this->autreBoucle, $this->owner, 'owner');
        $this->adhesion($this->boucleB, $this->etranger, 'owner');

        app()->instance('current_organization', $this->orgA);
    }

    // ── Administration ──────────────────────────────────────────────────────

    public function test_l_index_admin_presente_les_deux_plugins(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-plugins'))
            ->assertOk()
            ->assertSee('data-plugin="multi_ai_assistants"', false)
            ->assertSee('data-plugin="veille"', false)
            ->assertSee(route('admin.loop-plugins.show', 'pour-contre'), false)
            ->assertSee(route('admin.loop-plugins.show', 'veille'), false)
            // L'index n'est plus l'ecran de configuration IA.
            ->assertDontSee('data-section="plugin-models"', false);
    }

    public function test_le_detail_pour_contre_garde_la_configuration_ia(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-plugins.show', 'pour-contre'))
            ->assertOk()
            ->assertSee('data-section="plugin-models"', false)
            ->assertSee('data-assistant-model="aperio"', false)
            ->assertSee('Alpha');
    }

    public function test_le_detail_veille_n_a_aucune_configuration_ia(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-plugins.show', 'veille'))
            ->assertOk()
            ->assertSee('data-plugin="veille"', false)
            ->assertSee('data-plugin-status="experimental"', false)
            ->assertDontSee('data-section="plugin-models"', false)
            ->assertSee('Alpha')
            ->assertSee('Beta');
    }

    public function test_un_slug_inconnu_ou_une_cle_technique_rend_404(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.loop-plugins.show', 'inconnu'))->assertNotFound();
        // Un slug bien forme mais absent du catalogue : 404, jamais une cle devinee.
        $this->actingAs($this->superAdmin)->get('/admin/loop-plugins/multi-ai-assistants')->assertNotFound();
    }

    public function test_le_detail_est_reserve_au_superadmin(): void
    {
        $this->actingAs($this->owner)->get(route('admin.loop-plugins.show', 'veille'))->assertForbidden();
    }

    public function test_le_superadmin_autorise_la_veille_pour_une_organization(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.update', self::PLUGIN), [
                'organization_id' => $this->orgA->id,
                'available' => 1,
            ])
            ->assertRedirect(route('admin.loop-plugins.show', 'veille'));

        $this->assertTrue(app(LoopPluginAvailabilityService::class)->isAvailable(self::PLUGIN, $this->orgA));
        $this->assertFalse(app(LoopPluginAvailabilityService::class)->isAvailable(self::PLUGIN, $this->orgB));
    }

    public function test_les_routes_ia_repondent_404_pour_la_veille_sans_aucun_effet(): void
    {
        Http::fake();
        $this->activer();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.models.update', self::PLUGIN), [
                'assistant_key' => 'aperio', 'model_slug' => 'vendor/x:free',
            ])
            ->assertNotFound();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loop-plugins.models.refresh', self::PLUGIN))
            ->assertNotFound();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.plugins.configure', ['loop' => $this->loop->id, 'plugin' => self::PLUGIN]))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->put(route('organization.loops.plugins.configure.update', [
                'organization' => $this->orgA->slug, 'loop' => $this->loop->id, 'plugin' => self::PLUGIN,
            ]), ['assistants' => ['aperio' => ['instruction' => 'forge', 'enabled' => 1]]])
            ->assertNotFound();

        $this->assertDatabaseCount('loop_plugin_ai_models', 0);
        $this->assertDatabaseCount('loop_ai_assistants', 0);
        Http::assertNothingSent();
    }

    public function test_l_ecran_outils_n_offre_pas_configurer_pour_la_veille(): void
    {
        app(LoopPluginAvailabilityService::class)->setAvailability(self::PLUGIN, $this->orgA, true, $this->superAdmin);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.tools', ['organization' => $this->orgA->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->assertSee('data-plugin="veille"', false)
            ->assertDontSee('/plugins/veille/configurer', false);
    }

    public function test_le_registre_distingue_les_plugins_a_assistants(): void
    {
        $registre = app(LoopPluginRegistry::class);

        $this->assertTrue($registre->hasAssistants('multi_ai_assistants'));
        $this->assertFalse($registre->hasAssistants('veille'));
        $this->assertFalse($registre->hasAssistants('inconnu'));
        $this->assertSame('veille', $registre->keyForAdminSlug('veille'));
        $this->assertSame('multi_ai_assistants', $registre->keyForAdminSlug('pour-contre'));
        $this->assertNull($registre->keyForAdminSlug('multi_ai_assistants'));
    }

    // ── Disponibilite, activation, Card ─────────────────────────────────────

    public function test_le_plugin_et_sa_card_sont_absents_par_defaut(): void
    {
        $this->assertFalse(app(LoopWatchService::class)->isEnabled($this->loop));
        $this->assertNotContains('plugin.veille', app(LoopTypeRegistry::class)->activeCardsFor($this->loop));
        $this->assertFalse(app(LoopWatchService::class)->canView($this->membre, $this->loop));
        $this->assertFalse(app(LoopPermissionResolver::class)->can($this->owner, $this->loop, 'veille.view'));
    }

    public function test_disponible_mais_non_active_dans_la_boucle_reste_ferme(): void
    {
        app(LoopPluginAvailabilityService::class)->setAvailability(self::PLUGIN, $this->orgA, true, $this->superAdmin);

        $this->assertNotContains('plugin.veille', app(LoopTypeRegistry::class)->activeCardsFor($this->loop));
        $this->assertFalse(app(LoopWatchService::class)->canCreate($this->membre, $this->loop));
    }

    public function test_une_organization_non_autorisee_ne_peut_pas_activer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->boucleB, true, $this->etranger);
    }

    public function test_active_la_card_apparait_parmi_les_outils_secondaires(): void
    {
        $this->activer();

        $this->assertContains('plugin.veille', app(LoopTypeRegistry::class)->activeCardsFor($this->loop));
        $this->assertNotContains('plugin.veille', app(LoopCardCompositionService::class)->primaryKeysFor($this->loop));
        $this->assertContains('plugin.veille', app(LoopCardCompositionService::class)->secondaryKeysFor($this->loop));
        // Jamais composable a la main.
        $this->assertNotContains('plugin.veille', app(LoopCardRegistry::class)->manageableKeys());
    }

    public function test_la_card_ne_se_compose_pas_independamment_du_plugin(): void
    {
        $this->expectException(\Throwable::class);

        app(LoopCardCompositionService::class)->enable($this->loop, 'plugin.veille');
    }

    public function test_l_activation_est_vue_dans_la_meme_requete(): void
    {
        $registre = app(LoopTypeRegistry::class);
        app(LoopPluginAvailabilityService::class)->setAvailability(self::PLUGIN, $this->orgA, true, $this->superAdmin);

        // Le memo se remplit « eteint »…
        $this->assertNotContains('plugin.veille', $registre->activeCardsFor($this->loop));

        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->loop, true, $this->owner);
        $this->assertContains('plugin.veille', $registre->activeCardsFor($this->loop));

        app(LoopPluginAvailabilityService::class)->setAvailability(self::PLUGIN, $this->orgA, false, $this->superAdmin);
        $this->assertNotContains('plugin.veille', $registre->activeCardsFor($this->loop));
    }

    public function test_les_resolutions_repetees_ne_relisent_pas_les_plugins(): void
    {
        $this->activer();
        $resolveur = app(LoopPermissionResolver::class);
        $resolveur->can($this->membre, $this->loop, 'veille.view');

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        for ($i = 0; $i < 5; $i++) {
            $resolveur->can($this->membre, $this->loop, 'veille.view');
        }

        $lecturesPlugins = collect(\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'loop_plugins'))
            ->count();

        $this->assertSame(0, $lecturesPlugins);
    }

    public function test_la_card_est_rendue_dans_la_boucle_quand_le_plugin_est_actif(): void
    {
        $this->activer();

        $this->actingAs($this->membre)
            ->get(route('organization.loops.show', [$this->orgA->slug, $this->loop]))
            ->assertOk()
            ->assertSee('data-watch-root', false);
    }

    // ── Droits sur les veilles ──────────────────────────────────────────────

    public function test_un_membre_actif_publie_une_veille(): void
    {
        $this->activer();

        $item = $this->publier($this->membre);

        $this->assertSame($this->loop->id, $item->loop_id);
        $this->assertSame($this->orgA->id, $item->organization_id);
        $this->assertSame($this->membre->id, $item->created_by);
        $this->assertSame($this->categorieParDefaut()->id, $item->category_id);
    }

    public function test_un_membre_non_actif_est_refuse(): void
    {
        $this->activer();

        $this->expectException(WatchException::class);
        $this->publier($this->ancien);
    }

    public function test_un_utilisateur_d_une_autre_organization_est_refuse(): void
    {
        $this->activer();

        $this->expectException(WatchException::class);
        $this->publier($this->etranger);
    }

    public function test_un_superadmin_non_membre_ne_publie_pas(): void
    {
        $this->activer();

        $this->expectException(WatchException::class);
        $this->publier($this->superAdmin);
    }

    public function test_l_auteur_gere_ses_veilles_et_pas_celles_des_autres(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);
        $sienne = $this->publier($this->membre);
        $autre = $this->publier($this->autreMembre);

        $service->update($this->membre, $this->loop, $sienne->id, $this->donnees(['information' => 'Corrigee']));
        $this->assertSame('Corrigee', $sienne->fresh()->information);

        try {
            $service->update($this->membre, $this->loop, $autre->id, $this->donnees(['information' => 'Forge']));
            $this->fail('Un membre ne doit pas modifier la veille d\'un autre.');
        } catch (WatchException) {
        }

        try {
            $service->delete($this->membre, $this->loop, $autre->id);
            $this->fail('Un membre ne doit pas supprimer la veille d\'un autre.');
        } catch (WatchException) {
        }

        $this->assertNotSame('Forge', $autre->fresh()->information);
        $this->assertNotNull(LoopWatchItem::find($autre->id));
    }

    public function test_proprietaire_et_animateur_gerent_toutes_les_veilles(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);
        $item = $this->publier($this->membre);

        $service->update($this->facilitator, $this->loop, $item->id, $this->donnees(['impact' => 'Revu par l\'animateur']));
        $this->assertSame('Revu par l\'animateur', $item->fresh()->impact);
        $this->assertSame($this->facilitator->id, $item->fresh()->updated_by);

        $service->delete($this->owner, $this->loop, $item->id);
        $this->assertNull(LoopWatchItem::find($item->id));
    }

    public function test_la_suppression_est_douce(): void
    {
        $this->activer();
        $item = $this->publier($this->membre);

        app(LoopWatchService::class)->delete($this->membre, $this->loop, $item->id);

        $this->assertSoftDeleted('loop_watch_items', ['id' => $item->id]);
        $this->assertNull(app(LoopWatchService::class)->findItem($this->loop, $item->id));
    }

    public function test_une_veille_d_une_autre_boucle_n_est_pas_atteignable(): void
    {
        $this->activer();
        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->autreBoucle, true, $this->owner);

        $categorieAilleurs = app(LoopWatchService::class)->createCategory($this->owner, $this->autreBoucle, 'Ailleurs');
        $ailleurs = app(LoopWatchService::class)->create($this->owner, $this->autreBoucle, $this->donnees(['category_id' => $categorieAilleurs->id]));

        $this->expectException(WatchException::class);
        app(LoopWatchService::class)->update($this->owner, $this->loop, $ailleurs->id, $this->donnees(['information' => 'Forge']));
    }

    public function test_un_uuid_invalide_est_controle(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);

        $this->assertNull($service->findItem($this->loop, 'pas-un-uuid'));
        $this->assertNull($service->findCategory($this->loop, "1' or '1'='1"));

        try {
            $service->delete($this->owner, $this->loop, 'pas-un-uuid');
            $this->fail('Un identifiant invalide doit etre refuse proprement.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_not_found'), $e->getMessage());
        }

        try {
            $this->publier($this->membre, ['category_id' => 'pas-un-uuid']);
            $this->fail('Une categorie invalide doit etre refusee proprement.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_category_not_found'), $e->getMessage());
        }

        Livewire::actingAs($this->owner)
            ->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->call('openEditForm', 'pas-un-uuid')
            ->assertSet('errorMessage', __('veille.error_not_allowed'))
            ->call('archiveCategory', 'pas-un-uuid')
            ->assertSet('errorMessage', __('veille.error_category_not_found'));
    }

    #[DataProvider('urlsRefusees')]
    public function test_les_sources_non_http_sont_refusees(string $url): void
    {
        $this->activer();

        try {
            $this->publier($this->membre, ['source_url' => $url]);
            $this->fail("La source {$url} aurait du etre refusee.");
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_source_invalid'), $e->getMessage());
        }

        $this->assertDatabaseCount('loop_watch_items', 0);
    }

    public static function urlsRefusees(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'ftp' => ['ftp://exemple.org/fichier'],
            'sans schema' => ['exemple.org/article'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    public function test_les_champs_obligatoires_sont_verifies_cote_serveur(): void
    {
        $this->activer();

        foreach (['information' => '   ', 'impact' => '', 'source_url' => '', 'observed_on' => '2026-02-31'] as $champ => $valeur) {
            try {
                $this->publier($this->membre, [$champ => $valeur]);
                $this->fail("Le champ {$champ} vide ou invalide aurait du etre refuse.");
            } catch (WatchException) {
            }
        }

        $this->assertDatabaseCount('loop_watch_items', 0);
    }

    public function test_une_boucle_archivee_est_en_lecture_seule_meme_pour_le_superadmin(): void
    {
        $this->activer();
        $item = $this->publier($this->membre);

        app(LoopLifecycleService::class)->archive($this->owner, $this->loop);
        $boucle = $this->loop->fresh();
        $service = app(LoopWatchService::class);

        $this->assertTrue($service->canView($this->membre, $boucle));
        $this->assertFalse($service->canCreate($this->owner, $boucle));
        $this->assertFalse($service->canManageCategories($this->owner, $boucle));
        $this->assertFalse($service->canManageItem($this->superAdmin, $item, $boucle));
        $this->assertFalse($service->canManageItem($this->membre, $item, $boucle));

        $this->expectException(WatchException::class);
        $service->delete($this->superAdmin, $boucle, $item->id);
    }

    // ── Categories ──────────────────────────────────────────────────────────

    public function test_seuls_proprietaire_et_animateur_gerent_les_categories(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);

        $service->createCategory($this->owner, $this->loop, 'Reglementation');
        $service->createCategory($this->facilitator, $this->loop, 'Marche');

        $this->expectException(WatchException::class);
        $service->createCategory($this->membre, $this->loop, 'Forge');
    }

    public function test_la_gestion_des_categories_est_verrouillee_contre_une_surcharge(): void
    {
        $this->activer();
        $settings = app(LoopPermissionSettingsService::class);

        $this->assertTrue($settings->isLocked('veille.manage_categories'));
        $this->assertTrue($settings->isLocked('veille.manage'));
        $this->assertFalse(
            app(LoopPermissionResolver::class)->resolveForRole($this->orgA, 'general', 'member', 'veille.manage_categories')
        );
    }

    public function test_un_nom_de_categorie_ne_se_repete_pas_a_la_casse_et_aux_espaces_pres(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);
        $service->createCategory($this->owner, $this->loop, 'Réglementation');

        try {
            $service->createCategory($this->owner, $this->loop, '  RÉGLEMENTATION ');
            $this->fail('Doublon accepte.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_category_name_taken'), $e->getMessage());
        }

        // Une autre Boucle peut porter le meme nom.
        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->autreBoucle, true, $this->owner);
        $service->createCategory($this->owner, $this->autreBoucle, 'Réglementation');

        $this->assertSame(2, LoopWatchCategory::count());
    }

    public function test_une_veille_sans_categorie_est_impossible(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);

        // Aucune categorie dans la Boucle : publication refusee.
        try {
            $this->publier($this->membre, ['category_id' => null]);
            $this->fail('Une veille sans categorie a ete acceptee.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_no_category_yet'), $e->getMessage());
        }

        $categorie = $service->createCategory($this->owner, $this->loop, 'Technique');

        // Une categorie existe : la choisir reste obligatoire.
        try {
            $this->publier($this->membre, ['category_id' => '']);
            $this->fail('La categorie aurait du etre obligatoire.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_category_required'), $e->getMessage());
        }

        $this->assertDatabaseCount('loop_watch_items', 0);

        $item = $this->publier($this->membre, ['category_id' => $categorie->id]);
        $this->assertSame($categorie->id, $item->category_id);
    }

    public function test_la_derniere_categorie_active_ne_s_archive_pas(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);
        $seule = $service->createCategory($this->owner, $this->loop, 'Seule');

        try {
            $service->archiveCategory($this->owner, $this->loop, $seule->id);
            $this->fail('La derniere categorie active a ete archivee.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_last_category'), $e->getMessage());
        }
        $this->assertNull($seule->fresh()->archived_at);

        $service->createCategory($this->owner, $this->loop, 'Autre');
        $service->archiveCategory($this->owner, $this->loop, $seule->id);
        $this->assertNotNull($seule->fresh()->archived_at);
    }

    public function test_sans_categorie_la_card_guide_vers_la_creation(): void
    {
        $this->activer();

        Livewire::actingAs($this->owner)
            ->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertSee(__('veille.needs_category_manager'))
            ->assertDontSee('data-action="add-watch"', false)
            ->call('openCreateForm')
            ->assertSet('showForm', false)
            ->assertSet('showCategories', true)
            ->assertSet('errorMessage', __('veille.error_no_category_yet'));

        Livewire::actingAs($this->membre)
            ->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertSee(__('veille.needs_category_member'))
            ->assertDontSee('data-action="add-watch"', false);
    }

    public function test_une_categorie_d_une_autre_boucle_est_refusee(): void
    {
        $this->activer();
        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->autreBoucle, true, $this->owner);
        $etrangere = app(LoopWatchService::class)->createCategory($this->owner, $this->autreBoucle, 'Ailleurs');

        try {
            $this->publier($this->owner, ['category_id' => $etrangere->id]);
            $this->fail('Categorie etrangere acceptee.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_category_not_found'), $e->getMessage());
        }

        $this->expectException(WatchException::class);
        app(LoopWatchService::class)->renameCategory($this->owner, $this->loop, $etrangere->id, 'Detournee');
    }

    public function test_archiver_une_categorie_preserve_l_historique(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);
        $categorie = $service->createCategory($this->owner, $this->loop, 'Ancienne');
        $service->createCategory($this->owner, $this->loop, 'Restante');
        $item = $this->publier($this->membre, ['category_id' => $categorie->id]);

        $service->archiveCategory($this->owner, $this->loop, $categorie->id);

        $this->assertSame($categorie->id, $item->fresh()->category_id);
        $this->assertNotContains($categorie->id, $service->categoriesFor($this->loop)->pluck('id')->all());

        // Plus selectionnable pour une nouvelle veille…
        try {
            $this->publier($this->membre, ['category_id' => $categorie->id]);
            $this->fail('Categorie archivee selectionnee.');
        } catch (WatchException $e) {
            $this->assertSame(__('veille.error_category_archived'), $e->getMessage());
        }

        // …mais conservable sur la veille qui la portait.
        $service->update($this->membre, $this->loop, $item->id, $this->donnees([
            'category_id' => $categorie->id, 'information' => 'Retouche',
        ]));
        $this->assertSame($categorie->id, $item->fresh()->category_id);

        // Le nom se libere pour une nouvelle categorie active.
        $service->createCategory($this->owner, $this->loop, 'Ancienne');
    }

    public function test_renommer_et_ordonner_les_categories(): void
    {
        $this->activer();
        $service = app(LoopWatchService::class);
        $a = $service->createCategory($this->owner, $this->loop, 'A');
        $b = $service->createCategory($this->owner, $this->loop, 'B');

        $service->moveCategory($this->owner, $this->loop, $b->id, 'up');
        $this->assertSame([$b->id, $a->id], $service->categoriesFor($this->loop)->pluck('id')->all());

        $service->renameCategory($this->owner, $this->loop, $a->id, 'Alpha');
        $this->assertSame('Alpha', $a->fresh()->name);

        $this->expectException(WatchException::class);
        $service->moveCategory($this->membre, $this->loop, $a->id, 'up');
    }

    // ── Card Livewire ───────────────────────────────────────────────────────

    public function test_la_card_publie_liste_et_filtre(): void
    {
        $this->activer();
        $categorie = app(LoopWatchService::class)->createCategory($this->owner, $this->loop, 'Marche');

        Livewire::actingAs($this->membre)
            ->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->assertSet('observedOn', now()->format('Y-m-d'))
            ->set('categoryId', $categorie->id)
            ->set('subject', 'Norme 2027 en une phrase')
            ->set('information', 'Nouvelle norme publiee')
            ->set('impact', 'Revoir nos fiches')
            ->set('sourceUrl', 'https://exemple.org/norme')
            ->call('save')
            ->assertSet('errorMessage', null)
            ->assertSee('Nouvelle norme publiee')
            ->assertSee('Norme 2027 en une phrase')
            ->assertSee('rel="noopener noreferrer nofollow"', false)
            ->assertSee('target="_blank"', false)
            ->set('filterCategory', '00000000-0000-4000-8000-000000000000')
            ->assertDontSee('Nouvelle norme publiee');
    }

    public function test_un_membre_ne_voit_pas_la_gestion_des_categories(): void
    {
        $this->activer();

        Livewire::actingAs($this->membre)
            ->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertDontSee('data-action="toggle-categories"', false)
            ->call('toggleCategories')
            ->set('newCategoryName', 'Forge')
            ->call('createCategory')
            ->assertSet('errorMessage', __('veille.error_not_allowed'));

        $this->assertDatabaseCount('loop_watch_categories', 0);
    }

    public function test_la_card_d_un_plugin_eteint_ne_montre_rien(): void
    {
        Livewire::actingAs($this->membre)
            ->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertSee(__('veille.unavailable'))
            ->call('openCreateForm')
            ->assertSet('showForm', false);
    }

    // ── Conservation ────────────────────────────────────────────────────────

    public function test_desactiver_conserve_les_donnees_et_reactiver_les_retrouve(): void
    {
        $this->activer();
        $categorie = app(LoopWatchService::class)->createCategory($this->owner, $this->loop, 'Gardee');
        $item = $this->publier($this->membre, ['category_id' => $categorie->id]);
        $messages = LoopMessage::where('type', 'watch_item')->count();

        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->loop, false, $this->owner);

        $this->assertFalse(app(LoopWatchService::class)->canView($this->membre, $this->loop));
        $this->assertNotContains('plugin.veille', app(LoopTypeRegistry::class)->activeCardsFor($this->loop));
        try {
            $this->publier($this->membre, ['category_id' => $categorie->id]);
            $this->fail('Ecriture acceptee plugin eteint.');
        } catch (WatchException) {
        }

        app(LoopPluginAvailabilityService::class)->setAvailability(self::PLUGIN, $this->orgA, false, $this->superAdmin);

        $this->assertDatabaseHas('loop_watch_items', ['id' => $item->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('loop_watch_categories', ['id' => $categorie->id]);
        $this->assertSame($messages, LoopMessage::where('type', 'watch_item')->count());

        $this->activer();

        $this->assertSame([$item->id], app(LoopWatchService::class)->itemsFor($this->loop)->pluck('id')->all());
        $this->assertTrue(app(LoopWatchService::class)->canView($this->membre, $this->loop));
    }

    public function test_desactiver_la_veille_ne_touche_pas_pour_contre(): void
    {
        $dispo = app(LoopPluginAvailabilityService::class);
        $dispo->setAvailability('multi_ai_assistants', $this->orgA, true, $this->superAdmin);
        app(LoopPluginActivation::class)->setEnabled('multi_ai_assistants', $this->loop, true, $this->owner);
        $this->activer();

        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->loop, false, $this->owner);
        $dispo->setAvailability(self::PLUGIN, $this->orgA, false, $this->superAdmin);

        $this->assertTrue(app(LoopPluginActivation::class)->isEnabled('multi_ai_assistants', $this->loop));
        $this->assertTrue($dispo->isAvailable('multi_ai_assistants', $this->orgA));
    }

    // ── ChatLoop ────────────────────────────────────────────────────────────

    public function test_une_veille_est_annoncee_avec_des_metadata_minimales(): void
    {
        $this->activer();
        $item = $this->publier($this->membre, ['information' => 'Secret du texte', 'source_url' => 'https://exemple.org/source-privee']);

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'watch_item')->sole();

        $this->assertSame(['event' => 'created', 'watch_item_id' => $item->id], $message->metadata);
        $this->assertStringNotContainsString('Secret du texte', $message->body);
        $this->assertStringNotContainsString('exemple.org', $message->body);
        $this->assertSame($this->orgA->id, $message->organization_id);
    }

    public function test_une_veille_notifie_les_membres_actifs_sauf_l_auteur_sans_email(): void
    {
        Mail::fake();
        $this->activer();

        $this->publier($this->membre);

        $notifications = MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_WATCH_ITEM_CREATED)
            ->get();

        $this->assertEqualsCanonicalizing(
            [$this->owner->id, $this->facilitator->id, $this->autreMembre->id],
            $notifications->pluck('recipient_id')->all(),
            'auteur, ancien membre et autres Boucles exclus',
        );
        $this->assertTrue($notifications->every(fn ($n) => $n->organization_id === $this->orgA->id));
        $this->assertTrue($notifications->every(fn ($n) => $n->object_id === $this->loop->id));

        $this->assertFalse(
            $notifications->flatMap->deliveries->contains('channel', NotificationCatalogue::CHANNEL_EMAIL),
            'aucune livraison e-mail',
        );
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_les_canaux_de_la_veille_sont_in_app_et_web_push_jamais_email(): void
    {
        $cle = NotificationCatalogue::LOOP_WATCH_ITEM_CREATED;

        $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_IN_APP));
        $this->assertFalse(NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_IN_APP));
        $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_WEB_PUSH));
        $this->assertTrue(NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_WEB_PUSH));
        $this->assertNull(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_EMAIL), 'canal e-mail non declare');
        $this->assertSame((string) $this->loop->id, NotificationCatalogue::loopScopeFor($cle, (string) $this->loop->id));
    }

    public function test_la_sourdine_par_boucle_coupe_la_notification_de_veille(): void
    {
        $this->activer();
        $reglage = new LoopNotificationPreference(['collective_enabled' => false]);
        $reglage->organization_id = (string) $this->orgA->id;
        $reglage->loop_id = (string) $this->loop->id;
        $reglage->user_id = (string) $this->owner->id;
        $reglage->save();

        $this->publier($this->membre);

        $this->assertSame(0, MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_WATCH_ITEM_CREATED)
            ->where('recipient_id', $this->owner->id)
            ->count());
    }

    public function test_modifier_ou_supprimer_une_veille_ne_notifie_pas(): void
    {
        $this->activer();
        $item = $this->publier($this->membre);
        $avant = MemberNotification::query()->count();

        $service = app(LoopWatchService::class);
        $service->update($this->membre, $this->loop, $item->id, $this->donnees(['information' => 'Corrigée']));
        $service->delete($this->membre, $this->loop, $item->id);

        $this->assertSame($avant, MemberNotification::query()->count());
    }

    public function test_le_sujet_est_obligatoire_et_borne(): void
    {
        $this->activer();

        foreach (['' => 'veille.error_subject_required', str_repeat('a', 161) => 'veille.error_subject_too_long'] as $sujet => $cle) {
            try {
                $this->publier($this->membre, ['subject' => (string) $sujet]);
                $this->fail('Sujet invalide accepte.');
            } catch (WatchException $e) {
                $this->assertSame(__($cle), $e->getMessage());
            }
        }

        $item = $this->publier($this->membre, ['subject' => '  Resume en une phrase  ']);
        $this->assertSame('Resume en une phrase', $item->subject);
        $this->chat($this->autreMembre)->assertSee('Resume en une phrase');
    }

    public function test_membre_et_animateur_publient_une_veille(): void
    {
        $this->activer();

        foreach ([$this->membre, $this->facilitator, $this->owner] as $auteur) {
            $item = $this->publier($auteur);
            $this->assertSame($auteur->id, $item->created_by);
        }

        $this->assertDatabaseCount('loop_watch_items', 3);
    }

    public function test_la_publication_figure_dans_le_flux_des_seuls_membres_de_la_boucle(): void
    {
        $this->activer();
        $item = $this->publier($this->membre, ['information' => 'Info du flux']);

        $ligne = FeedPost::query()->where('type', FeedEventCatalogue::VEILLE_PUBLIEE)->sole();

        $this->assertSame($item->id, $ligne->object_id);
        $this->assertSame($this->membre->id, $ligne->user_id);
        $this->assertSame(FeedEventCatalogue::VISIBILITE_BOUCLE, $ligne->visibility);
        $this->assertSame($this->loop->id, $ligne->loop_id);
        $this->assertSame($this->orgA->id, $ligne->organization_id);

        $this->assertTrue(FeedPost::query()->visibleTo($this->autreMembre)->whereKey($ligne->id)->exists());
        $this->assertFalse(FeedPost::query()->visibleTo($this->ancien)->whereKey($ligne->id)->exists());
        $this->assertFalse(FeedPost::query()->visibleTo($this->etranger)->whereKey($ligne->id)->exists());

        $this->assertStringContainsString($this->loop->name, (string) FeedEventRenderer::libelle($ligne));
        $this->assertSame('Info du flux', FeedEventRenderer::apercu($ligne, 'alpha')['texte'] ?? null);
    }

    public function test_une_veille_supprimee_n_a_plus_d_apercu_dans_le_flux(): void
    {
        $this->activer();
        $item = $this->publier($this->membre, ['information' => 'Bientot supprimee']);
        app(LoopWatchService::class)->delete($this->membre, $this->loop, $item->id);

        $ligne = FeedPost::query()->where('type', FeedEventCatalogue::VEILLE_PUBLIEE)->sole();
        $ligne->refresh();

        $this->assertNull(FeedEventRenderer::apercu(FeedPost::query()->find($ligne->id), 'alpha'));
    }

    // ── Demander une categorie au proprietaire ──────────────────────────────

    public function test_le_membre_voit_le_lien_pour_demander_une_categorie(): void
    {
        $this->activer();

        Livewire::actingAs($this->membre)->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertSee(__('veille.suggest_category_link'))
            ->assertSee('data-action="suggest-watch-category"', false);

        $this->categorieParDefaut();
        Livewire::actingAs($this->membre)->test(LoopWatchCard::class, ['loop' => $this->loop])
            // Sous le menu des categories (le filtre), demande Cyril.
            ->assertSeeInOrder(['data-watch-filter', 'data-action="suggest-watch-category"'], false);

        foreach ([$this->owner, $this->facilitator] as $gestionnaire) {
            Livewire::actingAs($gestionnaire)->test(LoopWatchCard::class, ['loop' => $this->loop])
                ->assertDontSee('data-action="suggest-watch-category"', false);
        }
    }

    public function test_la_demande_pre_remplit_le_composeur_avec_la_mention_du_proprietaire(): void
    {
        $this->activer();
        $nom = trim($this->owner->publicDisplayName());

        $this->chat($this->membre)
            ->dispatch('veille-suggerer-categorie')
            ->assertSet('body', '@'.$nom.' : '.__('veille.suggest_category_message'))
            ->assertSet('mentionsSelectionnees', [(string) $this->owner->id])
            ->assertDispatched('composer-filled');

        $this->assertSame(0, LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'user')->count(), 'rien n\'est envoye');
    }

    public function test_la_demande_ne_detruit_pas_un_brouillon(): void
    {
        $this->activer();

        $this->chat($this->membre)
            ->set('body', 'Mon brouillon')
            ->dispatch('veille-suggerer-categorie')
            ->assertSet('body', "Mon brouillon\n@".trim($this->owner->publicDisplayName()).' : '.__('veille.suggest_category_message'));
    }

    public function test_la_demande_est_ignoree_plugin_eteint(): void
    {
        $this->chat($this->membre)
            ->dispatch('veille-suggerer-categorie')
            ->assertSet('body', '');
    }

    public function test_le_design_met_l_impact_en_relief_et_replie_un_long_texte(): void
    {
        $this->activer();
        $this->publier($this->membre, ['information' => 'Court']);
        $this->publier($this->membre, ['information' => str_repeat('Une information longue. ', 20)]);

        Livewire::actingAs($this->membre)->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertSee('data-watch-impact', false)
            ->assertSee(__('veille.impact_heading'))
            ->assertSee('data-watch-category-chip', false)
            ->assertSeeHtmlInOrder(['line-clamp-3'])
            ->assertSee(__('veille.read_more'));
    }

    // ── Export ──────────────────────────────────────────────────────────────

    private function urlExport(string $format, array $extra = []): string
    {
        return route('organization.loops.watch.export', array_merge([
            'organization' => $this->orgA->slug, 'loop' => $this->loop->id, 'format' => $format,
        ], $extra));
    }

    public function test_un_membre_exporte_en_csv_excel_et_tableau_imprimable(): void
    {
        $this->activer();
        $this->publier($this->membre, ['subject' => 'Sujet exporte', 'information' => '=HYPERLINK("http://x")']);

        $csv = $this->actingAs($this->autreMembre)->get($this->urlExport('csv'));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $contenu = $csv->streamedContent();
        $this->assertStringContainsString('Sujet exporte', $contenu);
        $this->assertStringContainsString("'=HYPERLINK", $contenu, 'formule neutralisee');

        $xlsx = $this->actingAs($this->autreMembre)->get($this->urlExport('xlsx'));
        $xlsx->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());

        $this->actingAs($this->autreMembre)->get($this->urlExport('imprimer'))
            ->assertOk()
            ->assertSee('data-watch-export-table', false)
            ->assertSee('Sujet exporte');
    }

    public function test_l_export_respecte_le_filtre_de_categorie(): void
    {
        $this->activer();
        $autre = app(LoopWatchService::class)->createCategory($this->owner, $this->loop, 'Filtree');
        $this->publier($this->membre, ['subject' => 'Dans la generale']);
        $this->publier($this->membre, ['subject' => 'Dans la filtree', 'category_id' => $autre->id]);

        $this->actingAs($this->membre)->get($this->urlExport('imprimer', ['categorie' => $autre->id]))
            ->assertOk()
            ->assertSee('Dans la filtree')
            ->assertDontSee('Dans la generale');

        // UUID invalide : ignore, jamais une 500.
        $this->actingAs($this->membre)->get($this->urlExport('imprimer', ['categorie' => 'pas-un-uuid']))->assertOk();
    }

    public function test_l_export_est_refuse_hors_de_la_boucle_ou_plugin_eteint(): void
    {
        $this->actingAs($this->membre)->get($this->urlExport('csv'))->assertForbidden();

        $this->activer();
        $this->actingAs($this->ancien)->get($this->urlExport('csv'))->assertForbidden();
        $this->actingAs($this->etranger)->get($this->urlExport('csv'))->assertNotFound();
        $this->actingAs($this->membre)->get(str_replace('/csv', '/pdf', $this->urlExport('csv')))->assertNotFound();
    }

    public function test_la_card_propose_l_export_quand_il_y_a_des_veilles(): void
    {
        $this->activer();

        Livewire::actingAs($this->membre)->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertDontSee('data-section="watch-export"', false);

        $this->publier($this->membre);

        Livewire::actingAs($this->membre)->test(LoopWatchCard::class, ['loop' => $this->loop])
            ->assertSee('data-export="pdf"', false)
            ->assertSee('data-export="csv"', false)
            ->assertSee('data-export="xlsx"', false)
            ->assertSee('aria-label="'.e(__('veille.export_xlsx_title')).'"', false)
            ->assertDontSee('Exporter :');
    }

    public function test_l_echec_de_l_annonce_ne_perd_pas_la_veille(): void
    {
        $this->activer();
        $this->mock(LoopMessageService::class, function ($mock) {
            $mock->shouldReceive('sendWatchItemMessage')->andThrow(new \RuntimeException('annonce en panne'));
        });

        $item = $this->publier($this->membre);

        $this->assertDatabaseHas('loop_watch_items', ['id' => $item->id]);
        $this->assertSame(0, LoopMessage::where('type', 'watch_item')->count());
    }

    public function test_l_annonce_relit_la_veille_et_ouvre_la_card(): void
    {
        $this->activer();
        $item = $this->publier($this->membre, ['subject' => 'Version initiale']);

        $this->chat($this->autreMembre)
            ->assertSee('data-watch-announcement="available"', false)
            ->assertSee('Version initiale')
            ->assertSee("\$dispatch('bp-open-loop-card', { card: 'plugin.veille' })", false);

        app(LoopWatchService::class)->update($this->membre, $this->loop, $item->id, $this->donnees(['subject' => 'Version corrigee']));

        $this->chat($this->autreMembre)
            ->assertSee('Version corrigee')
            ->assertDontSee('Version initiale');
    }

    public function test_l_annonce_d_une_veille_supprimee_ne_fuit_rien(): void
    {
        $this->activer();
        $item = $this->publier($this->membre, ['subject' => 'Contenu a effacer']);
        app(LoopWatchService::class)->delete($this->membre, $this->loop, $item->id);

        $this->chat($this->autreMembre)
            ->assertSee('data-watch-announcement="unavailable"', false)
            ->assertSee(__('veille.chat_unavailable'))
            ->assertDontSee('Contenu a effacer');
    }

    public function test_l_annonce_plugin_eteint_ne_fuit_rien(): void
    {
        $this->activer();
        $this->publier($this->membre, ['information' => 'Contenu du plugin eteint']);
        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->loop, false, $this->owner);

        $this->chat($this->autreMembre)
            ->assertSee(__('veille.chat_unavailable'))
            ->assertDontSee('Contenu du plugin eteint');
    }

    public function test_le_registre_rgpd_declare_les_colonnes_utilisateur(): void
    {
        $cles = collect(app(UserDataLifecycleRegistry::class)->entries())->pluck('key')->all();

        foreach (['loop_watch_categories_created_by', 'loop_watch_items_created_by', 'loop_watch_items_updated_by'] as $cle) {
            $this->assertContains($cle, $cles);
        }
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    private function activer(): void
    {
        app(LoopPluginAvailabilityService::class)->setAvailability(self::PLUGIN, $this->orgA, true, $this->superAdmin);
        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->loop, true, $this->owner);
    }

    private function donnees(array $surcharges = []): array
    {
        if (! array_key_exists('category_id', $surcharges)) {
            $surcharges['category_id'] = $this->categorieParDefaut()->id;
        }

        return array_merge([
            'observed_on' => '2026-11-20',
            'subject' => 'Un sujet en une phrase',
            'information' => 'Une information reperee',
            'impact' => 'Ce que cela change',
            'source_url' => 'https://exemple.org/article',
        ], $surcharges);
    }

    private function publier(User $auteur, array $surcharges = []): LoopWatchItem
    {
        // Categorie obligatoire (demande Cyril, 22/11) : sans precision, la
        // veille est rangee dans une categorie de test de la Boucle.
        if (! array_key_exists('category_id', $surcharges)) {
            $surcharges['category_id'] = $this->categorieParDefaut()->id;
        }

        return app(LoopWatchService::class)->create($auteur, $this->loop, $this->donnees($surcharges));
    }

    private function categorieParDefaut(): LoopWatchCategory
    {
        return LoopWatchCategory::query()->firstOrCreate(
            ['loop_id' => $this->loop->id, 'name' => 'Generale'],
            ['organization_id' => $this->orgA->id, 'position' => 0, 'created_by' => $this->owner->id],
        );
    }

    private function chat(User $lecteur)
    {
        return Livewire::actingAs($lecteur)->test(LoopChat::class, ['loop' => $this->loop->fresh()]);
    }

    private function personne(Organization $organization, string $nom): User
    {
        return User::factory()->create([
            'is_admin' => false,
            'organization_id' => $organization->id,
            'preferred_locale' => 'fr',
            'name' => $nom.' '.$organization->name,
        ]);
    }

    private function boucle(Organization $organization, User $createur): Loop
    {
        return Loop::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $createur->id,
            'status' => 'active',
            'type' => 'general',
        ]);
    }

    private function adhesion(Loop $loop, User $user, string $role, string $status = 'active'): void
    {
        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'organization_id' => $loop->organization_id,
            'role' => $role,
            'status' => $status,
        ]);
    }
}
