<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\Loops\PresetException;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les presets deviennent configurables, et le workspace s'allege.
 *
 * Trois choses sont defendues ici plus que le reste : **la grille n'accepte que
 * trois Cards**, **rien n'est jamais supprime**, et **une requete forgee ne
 * contourne ni les dependances ni les droits**.
 */
class TASK1090PresetConfiguratorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    private User $superAdmin;

    private User $owner;

    private User $facilitator;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->org->id,
        ]);
        $this->owner = $this->userInOrg();
        $this->facilitator = $this->userInOrg();
        $this->member = $this->userInOrg();

        $this->loop = $this->makeLoop('project');

        $this->join($this->loop, $this->owner, 'owner');
        $this->join($this->loop, $this->facilitator, 'facilitator');
        $this->join($this->loop, $this->member, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function userInOrg(): User
    {
        return User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeLoop(string $type = 'general'): Loop
    {
        $loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle '.uniqid(),
            'slug' => 'boucle-'.uniqid(),
            'type' => $type,
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $this->orgAdmin->id,
        ]);

        app(LoopTypeRegistry::class)->applyPreset($loop);

        return $loop;
    }

    private function join(Loop $loop, User $user, string $role): void
    {
        LoopMember::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function configurator(): LoopPresetConfigurator
    {
        return app(LoopPresetConfigurator::class);
    }

    private function registry(): LoopCardRegistry
    {
        return app(LoopCardRegistry::class);
    }

    /**
     * Declarer une dependance dans le catalogue, pour un test.
     *
     * Passe par le tableau complet et non par `config('...core.polls.requires')` :
     * la notation a points couperait sur le point de la cle de Card, et
     * ecrirait dans un `core` imaginaire. C'est le meme piege que celui
     * documente dans le registre.
     *
     * @param  array<int, string>  $requires
     */
    private function declareRequirement(string $key, array $requires): void
    {
        $cards = config('loop_cards.cards');
        $cards[$key]['requires'] = $requires;
        config(['loop_cards.cards' => $cards]);
    }

    // ── Placement et plafond ────────────────────────────────────────────────

    public function test_the_frame_the_grid_and_the_chat_actions_are_distinct(): void
    {
        $registry = $this->registry();

        $this->assertSame(['core.manifesto', 'core.members'], $registry->frameKeys());
        $this->assertSame(['core.ai_summary'], $registry->chatActionKeys());
        $this->assertContains('core.roadmap', $registry->gridKeys());
        $this->assertContains('core.polls', $registry->gridKeys());
        $this->assertContains('core.events', $registry->gridKeys());

        // Aucune Card ne peut etre a deux endroits.
        $all = array_merge($registry->frameKeys(), $registry->gridKeys(), $registry->chatActionKeys());
        $this->assertSame(count($all), count(array_unique($all)));
    }

    public function test_the_grid_never_shows_more_than_three_cards(): void
    {
        // Le socle « project » en porte quatre, dont deux de cadre : la grille
        // n'en garde donc que ce qui lui revient, plafonne a trois.
        $grid = $this->registry()->workspaceCardsFor($this->loop->fresh(), $this->member);

        $this->assertLessThanOrEqual($this->registry()->gridSlots(), $grid->count());

        foreach ($grid as $card) {
            $this->assertSame(
                LoopCardRegistry::PLACEMENT_GRID,
                $this->registry()->placementOf($card['key']),
            );
        }
    }

    public function test_manifesto_and_members_leave_the_grid_without_leaving_the_registry(): void
    {
        $loop = $this->loop->fresh();
        $registry = $this->registry();

        $gridKeys = $registry->workspaceCardsFor($loop, $this->member)->pluck('key');
        $frameKeys = $registry->frameCardsFor($loop, $this->member)->pluck('key');

        $this->assertNotContains('core.manifesto', $gridKeys);
        $this->assertNotContains('core.members', $gridKeys);
        $this->assertContains('core.manifesto', $frameKeys);
        $this->assertContains('core.members', $frameKeys);

        // Toujours declarees, toujours administrables, Membres toujours requise.
        $this->assertTrue($registry->exists('core.members'));
        $this->assertTrue($registry->isRequired('core.members'));
        $this->assertContains('core.members', $registry->manageableKeys());
    }

    public function test_the_ai_summary_leaves_the_grid_for_the_chat_actions(): void
    {
        $loop = $this->loop->fresh();
        $registry = $this->registry();

        $this->assertNotContains(
            'core.ai_summary',
            $registry->workspaceCardsFor($loop, $this->member)->pluck('key'),
        );
        $this->assertContains(
            'core.ai_summary',
            $registry->chatActionCardsFor($loop, $this->member)->pluck('key'),
        );
    }

    public function test_a_required_card_cannot_be_switched_off(): void
    {
        $this->expectException(PresetException::class);
        $this->configurator()->disable($this->superAdmin, $this->loop, 'core.members');
    }

    // ── Qui a le droit ──────────────────────────────────────────────────────

    public function test_the_super_admin_and_the_organization_admin_configure(): void
    {
        $this->assertTrue($this->configurator()->canConfigure($this->superAdmin, $this->loop));
        $this->assertTrue($this->configurator()->canConfigure($this->orgAdmin, $this->loop));
    }

    public function test_the_owner_configures_only_if_the_organization_allows_it(): void
    {
        // Verrouille par defaut : c'est le comportement livre jusqu'ici.
        $this->assertFalse($this->configurator()->canConfigure($this->owner, $this->loop));

        $this->org->update(['loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED]);

        $this->assertTrue($this->configurator()->canConfigure($this->owner, $this->loop->fresh()));
    }

    public function test_a_facilitator_never_configures_even_when_owners_may(): void
    {
        $this->org->update(['loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED]);

        // Un animateur anime, il ne recompose pas.
        $this->assertFalse($this->configurator()->canConfigure($this->facilitator, $this->loop->fresh()));
        $this->assertFalse($this->configurator()->canConfigure($this->member, $this->loop->fresh()));
    }

    public function test_someone_from_another_organization_never_configures(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->assertFalse($this->configurator()->canConfigure($stranger, $this->loop));

        $this->expectException(PresetException::class);
        $this->configurator()->enable($stranger, $this->loop, 'core.polls');
    }

    public function test_an_archived_loop_is_not_recomposed(): void
    {
        app(LoopLifecycleService::class)->archive($this->owner, $this->loop);

        $this->expectException(PresetException::class);
        $this->configurator()->enable($this->superAdmin, $this->loop->fresh(), 'core.polls');
    }

    // ── Dependances ─────────────────────────────────────────────────────────

    public function test_a_declared_dependency_blocks_and_says_why(): void
    {
        // Une dependance declaree dans le catalogue, pas deduite du type.
        $this->declareRequirement('core.polls', ['core.roadmap']);

        $loop = $this->makeLoop('general');
        $blockers = $this->registry()->blockersFor('core.polls', ['core.members']);

        $this->assertSame(['core.roadmap'], $blockers['missing']);

        try {
            $this->configurator()->enable($this->superAdmin, $loop, 'core.polls');
            $this->fail('L’activation aurait dû être refusée.');
        } catch (PresetException $e) {
            // Le refus nomme ce qui manque.
            $this->assertStringContainsString($this->registry()->label('core.roadmap'), $e->getMessage());
        }
    }

    public function test_a_forged_request_cannot_bypass_a_dependency(): void
    {
        // Une Card absente du socle Communaute, dependante d'une autre qui
        // l'est aussi : rien ne l'a donc activee a la creation.
        $this->declareRequirement('core.roadmap', ['core.ai_summary']);

        $loop = $this->makeLoop('general');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.compose', $loop), [
                'action' => 'enable', 'card_key' => 'core.roadmap',
            ])
            ->assertRedirect();

        // La verification est dans le service, pas dans la vue.
        $this->assertDatabaseMissing('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap', 'enabled' => true,
        ]);
    }

    public function test_a_card_that_others_depend_on_is_not_switched_off(): void
    {
        $this->declareRequirement('core.polls', ['core.roadmap']);

        $loop = $this->makeLoop('project');
        app(LoopCardCompositionService::class)->enable($loop, 'core.polls');

        try {
            $this->configurator()->disable($this->superAdmin, $loop->fresh(), 'core.roadmap');
            $this->fail('La désactivation aurait dû être refusée.');
        } catch (PresetException $e) {
            $this->assertStringContainsString($this->registry()->label('core.polls'), $e->getMessage());
        }
    }

    public function test_an_unknown_card_key_is_refused(): void
    {
        $this->expectException(PresetException::class);
        $this->configurator()->enable($this->superAdmin, $this->loop, 'core.does_not_exist');
    }

    public function test_the_grid_refuses_a_fourth_card(): void
    {
        $loop = $this->makeLoop('general');

        // Le nombre d'emplacements se lit dans le registre et la grille se
        // remplit jusqu'a saturation : epingler « le socle en prend deux » a
        // casse des qu'une Card est entree dans le preset (TASK-1091).
        $slots = $this->registry()->gridSlots();
        $composition = app(LoopCardCompositionService::class);

        $gridOf = fn ($loop) => collect(app(LoopTypeRegistry::class)->activeCardsFor($loop->fresh()))
            ->filter(fn ($k) => $this->registry()->placementOf($k) === LoopCardRegistry::PLACEMENT_GRID)
            ->values();

        foreach ($this->registry()->gridKeys() as $key) {
            if ($gridOf($loop)->count() >= $slots) {
                break;
            }
            $composition->enable($loop, $key);
        }

        $this->assertCount($slots, $gridOf($loop));

        // La grille est pleine : une Card de grille de plus est refusee.
        $remaining = collect($this->registry()->gridKeys())
            ->reject(fn ($k) => $gridOf($loop)->contains($k))
            ->first();

        if ($remaining !== null) {
            try {
                $this->configurator()->enable($this->superAdmin, $loop->fresh(), $remaining);
                $this->fail('Une quatrieme Card de grille aurait du etre refusee.');
            } catch (PresetException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        // Le Resume IA n'est pas une Card de grille : il ne consomme pas
        // d'emplacement et reste activable.
        $this->configurator()->enable($this->superAdmin, $loop->fresh(), 'core.ai_summary');
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.ai_summary', 'enabled' => true,
        ]);
    }

    // ── Changement de preset ────────────────────────────────────────────────

    public function test_the_preview_says_what_would_change_without_changing_it(): void
    {
        $loop = $this->makeLoop('general');
        $before = LoopCard::where('loop_id', $loop->id)->get()->toArray();

        $preview = $this->configurator()->previewPresetChange($loop, 'project');

        $this->assertSame('general', $preview['from']);
        $this->assertSame('project', $preview['to']);
        $this->assertContains('core.roadmap', $preview['added']);
        $this->assertContains('core.members', $preview['kept']);
        // Aucune ecriture.
        $this->assertEquals($before, LoopCard::where('loop_id', $loop->id)->get()->toArray());
    }

    public function test_applying_a_preset_adds_without_removing_by_default(): void
    {
        $loop = $this->makeLoop('general');

        $result = $this->configurator()->applyPreset($this->superAdmin, $loop, 'project');

        $this->assertContains('core.roadmap', $result['added']);
        $this->assertSame([], $result['deactivated']);
        // Les Cards de l'ancien socle restent allumees.
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.polls', 'enabled' => true,
        ]);
        $this->assertSame('project', $loop->fresh()->type);
    }

    public function test_deactivating_absent_cards_is_explicit_and_deletes_nothing(): void
    {
        $loop = $this->makeLoop('general');

        $this->configurator()->applyPreset($this->superAdmin, $loop, 'project', deactivateAbsent: true);

        // Eteinte, pas supprimee : la ligne reste, avec son origine.
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.polls', 'enabled' => false,
        ]);
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.members', 'enabled' => true,
        ]);
    }

    public function test_a_required_card_survives_a_preset_change(): void
    {
        $loop = $this->makeLoop('project');

        $this->configurator()->applyPreset($this->superAdmin, $loop, 'general', deactivateAbsent: true);

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.members', 'enabled' => true,
        ]);
    }

    public function test_an_unknown_preset_is_refused(): void
    {
        $this->expectException(PresetException::class);
        $this->configurator()->applyPreset($this->superAdmin, $this->loop, 'inexistant');
    }

    public function test_restoring_the_preset_switches_back_on_what_it_prescribes(): void
    {
        $loop = $this->makeLoop('project');
        app(LoopCardCompositionService::class)->disable($loop, 'core.roadmap');

        $restored = $this->configurator()->restorePreset($this->superAdmin, $loop->fresh());

        $this->assertContains('core.roadmap', $restored);
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap', 'enabled' => true,
        ]);
    }

    public function test_restoring_never_removes_a_locally_added_card(): void
    {
        $loop = $this->makeLoop('general');
        app(LoopCardCompositionService::class)->enable($loop, 'core.roadmap');

        $this->configurator()->restorePreset($this->superAdmin, $loop->fresh());

        // « Restaurer » remet ce qui devrait etre la ; il n'efface pas ce qu'on
        // a ajoute.
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap', 'enabled' => true,
        ]);
    }

    // ── Donnees conservees ──────────────────────────────────────────────────

    public function test_switching_a_card_off_and_on_finds_its_data_again(): void
    {
        $loop = $this->makeLoop('project');

        // Une donnee reelle portee par la Card.
        \App\Models\LoopRoadmapItem::create([
            'organization_id' => $this->org->id,
            'loop_id' => $loop->id,
            'title' => 'Une action',
            'status' => \App\Models\LoopRoadmapItem::STATUS_TODO,
            'created_by' => $this->owner->id,
        ]);

        $this->configurator()->disable($this->superAdmin, $loop, 'core.roadmap');
        $this->assertDatabaseHas('loop_roadmap_items', ['loop_id' => $loop->id]);

        $this->configurator()->enable($this->superAdmin, $loop->fresh(), 'core.roadmap');
        $this->assertSame(1, \App\Models\LoopRoadmapItem::where('loop_id', $loop->id)->count());
    }

    public function test_the_description_counts_the_data_a_card_already_holds(): void
    {
        $loop = $this->makeLoop('project');

        \App\Models\LoopRoadmapItem::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            'title' => 'Une action', 'status' => \App\Models\LoopRoadmapItem::STATUS_TODO,
            'created_by' => $this->owner->id,
        ]);

        $roadmap = collect($this->configurator()->describe($loop->fresh())['grid'])
            ->firstWhere('key', 'core.roadmap');

        $this->assertNotNull($roadmap);
        $this->assertSame(1, $roadmap['data_count']);
    }

    // ── Les ecrans ──────────────────────────────────────────────────────────

    public function test_the_platform_configurator_opens_for_a_super_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.preset_frame_title'))
            ->assertSee(__('loops.preset_grid_title'));
    }

    public function test_the_organization_configurator_opens_for_its_admin(): void
    {
        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops.configure', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->assertSee(__('loops.preset_grid_title'));
    }

    public function test_the_configurator_refuses_a_member(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.admin.loops.configure', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]))
            ->assertForbidden();
    }

    public function test_the_configurator_refuses_another_organization(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        // 403 et non 404 : le middleware du prefixe admin d'Organization refuse
        // avant meme d'atteindre le controleur, et c'est le comportement de tous
        // les ecrans de cette zone. Le controleur porte un second verrou, qui ne
        // sert qu'a ne pas dependre d'une couche qu'il ne controle pas.
        $this->actingAs($stranger)
            ->get(route('organization.admin.loops.configure', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]))
            ->assertForbidden();
    }

    public function test_the_composition_policy_is_saved_by_the_organization_admin(): void
    {
        $this->actingAs($this->orgAdmin)
            ->patch(route('organization.admin.composition-policy.update', [
                'organization' => $this->org->slug,
            ]), ['policy' => Organization::COMPOSITION_OWNER_ALLOWED])
            ->assertRedirect();

        $this->assertSame(
            Organization::COMPOSITION_OWNER_ALLOWED,
            $this->org->fresh()->loop_composition_policy,
        );
    }

    public function test_an_unknown_policy_is_refused(): void
    {
        $this->actingAs($this->orgAdmin)
            ->patch(route('organization.admin.composition-policy.update', [
                'organization' => $this->org->slug,
            ]), ['policy' => 'tout_le_monde'])
            ->assertSessionHasErrors('policy');
    }

    // ── Preset Communaute ───────────────────────────────────────────────────

    public function test_the_general_preset_is_labelled_community(): void
    {
        // La cle technique ne bouge pas : une cle `community` ferait deux
        // presets la ou il n'y en a qu'un.
        $this->assertTrue(app(LoopTypeRegistry::class)->exists('general'));
        $this->assertFalse(app(LoopTypeRegistry::class)->exists('community'));
        $this->assertSame('Communauté', __('loops.types.general.label', [], 'fr'));
        $this->assertSame('Community', __('loops.types.general.label', [], 'en'));
    }

    public function test_the_community_preset_carries_its_frame_and_its_cards(): void
    {
        $preset = app(LoopTypeRegistry::class)->cardsFor('general');

        $this->assertContains('core.manifesto', $preset);
        $this->assertContains('core.members', $preset);
        $this->assertContains('core.polls', $preset);
        $this->assertContains('core.events', $preset);
        // La Roadmap a quitte ce socle : elle reste activable localement.
        $this->assertNotContains('core.roadmap', $preset);
    }

    public function test_roadmap_stays_disablable_without_losing_its_data(): void
    {
        $loop = $this->makeLoop('general');
        app(LoopCardCompositionService::class)->enable($loop, 'core.roadmap');

        \App\Models\LoopRoadmapItem::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            'title' => 'Une action', 'status' => \App\Models\LoopRoadmapItem::STATUS_TODO,
            'created_by' => $this->owner->id,
        ]);

        $this->configurator()->disable($this->superAdmin, $loop->fresh(), 'core.roadmap');

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap', 'enabled' => false,
        ]);
        $this->assertSame(1, \App\Models\LoopRoadmapItem::where('loop_id', $loop->id)->count());
    }

    // ── Doctrine ────────────────────────────────────────────────────────────

    public function test_no_business_condition_on_the_loop_type(): void
    {
        // La regle de TASK-1080, verifiee sur le service ajoute par cette tache.
        $source = file_get_contents(app_path('Services/Loops/LoopPresetConfigurator.php'));

        $this->assertStringNotContainsString("loop->type ===", $source);
        $this->assertStringNotContainsString("loop->type ==", $source);
    }

    public function test_reading_the_configurator_writes_nothing(): void
    {
        $loop = $this->makeLoop('project');
        $before = LoopCard::where('loop_id', $loop->id)->count();

        $this->configurator()->describe($loop);
        $this->configurator()->previewPresetChange($loop, 'general');

        $this->assertSame($before, LoopCard::where('loop_id', $loop->id)->count());
    }
}
