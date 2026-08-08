<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopTypeSettingsService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The card composition of Loop types, and where that composition is chosen.
 *
 * Two properties are defended beyond access control: a saved preset is never
 * retroactive — no Loop loses a card it already has — and a type withdrawn from
 * the offer can neither be assigned nor picked at creation, while the Loops
 * already carrying it keep it.
 */
class TASK1079LoopTypeAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'loops_enabled' => true, 'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);
        $this->superAdmin->update(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function settings(): LoopTypeSettingsService
    {
        return app(LoopTypeSettingsService::class);
    }

    private function registry(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    // ── Accès ───────────────────────────────────────────────────────────────

    public function test_the_super_admin_reaches_the_screen(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types'))
            ->assertOk()
            ->assertSee(__('loops.types.general.label'))
            ->assertSee(__('loops.types.project.label'))
            ->assertSee(__('loops.types_admin_unavailable'));
    }

    public function test_an_organization_admin_is_refused(): void
    {
        // Types are a platform matter. An Organization does not redefine what a
        // Loop type is — it only overrides permissions.
        $this->actingAs($this->orgAdmin)->get(route('admin.loop-types'))->assertForbidden();
        $this->actingAs($this->orgAdmin)
            ->put(route('admin.loop-types.update', 'general'), ['cards' => ['core.members'], 'available' => 1])
            ->assertForbidden();
    }

    public function test_the_nav_offers_the_screen(): void
    {
        // The whole /admin prefix already requires is_admin, so an Organization
        // administrator never renders this layout. The nav still filters the
        // two platform-level tools defensively; what is pinned here is that the
        // entry exists for the one person who may use it.
        $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()
            ->assertSee(route('admin.loop-types'), false);

        $this->actingAs($this->orgAdmin)->get(route('admin.loops'))->assertForbidden();
    }

    // ── Composition ─────────────────────────────────────────────────────────

    public function test_the_two_defined_types_ship_the_specified_cards(): void
    {
        // Le socle Dialogue s'enrichit a chaque Card metier — Sondage en
        // TASK-1087, Evenements en TASK-1088. On fige ce qui doit rester vrai :
        // les membres y sont toujours, et le socle Projets ne bouge pas.
        $this->assertContains('core.members', $this->settings()->cardsFor('general'));
        // Le socle Projet porte desormais ses trois Cards distinctives —
        // « Roadmap · Decisions · Dossiers » — les Decisions avec TASK-1106,
        // les Dossiers avec TASK-1110. La liste reste **figee a la main** :
        // c'est ce qui fait qu'un changement de preset ne passe jamais
        // inaperçu.
        $this->assertEqualsCanonicalizing(
            ['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.decisions', 'core.dossiers', 'core.members'],
            $this->settings()->cardsFor('project'),
        );
    }

    public function test_saving_a_preset_changes_what_new_loops_are_built_with(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'general'), [
                'cards' => ['core.members', 'core.manifesto'],
                'available' => 1,
            ])
            ->assertRedirect(route('admin.loop-types'));

        $this->assertEqualsCanonicalizing(
            ['core.members', 'core.manifesto'],
            $this->registry()->cardsFor('general'),
        );
    }

    public function test_a_preset_equal_to_the_default_stores_no_override(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'general'), [
                // Le socle configure, quel qu'il soit : enregistrer le defaut
                // ne doit rien stocker.
                'cards' => config('loop_types.types.general.cards'), 'available' => 1,
            ]);

        // Sparse storage: saving the default is not a customisation, and a
        // later change to config/loop_types.php must still flow through.
        $this->assertDatabaseCount('loop_type_settings', 0);
        $this->assertFalse($this->settings()->isCustomised('general'));
    }

    public function test_an_unknown_card_key_cannot_be_smuggled_into_a_preset(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'general'), [
                'cards' => ['core.members', 'core.does_not_exist'],
                'available' => 1,
            ]);

        // Seule la cle inconnue est retiree ; le reste du socle est celui qu'on
        // a poste, pas le defaut.
        $this->assertSame(['core.members'], $this->registry()->cardsFor('general'));
    }

    public function test_a_payload_edited_by_hand_in_the_database_is_still_filtered(): void
    {
        LoopTypeSetting::create([
            'loop_type' => 'general',
            'cards' => ['core.members', 'core.invented'],
            'available' => true,
        ]);

        // Normalised on read as well as on write: the database is not trusted
        // more than the form.
        $this->assertSame(['core.members'], $this->settings()->cardsFor('general'));
    }

    public function test_an_available_type_cannot_compose_nothing(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'general'), ['cards' => [], 'available' => 1])
            ->assertSessionHasErrors('cards');

        // Refuse : le socle configure est intact.
        $this->assertEqualsCanonicalizing(
            config('loop_types.types.general.cards'),
            $this->registry()->cardsFor('general'),
        );
    }

    public function test_returning_to_defaults_drops_the_override(): void
    {
        $this->settings()->save('general', ['core.members', 'core.roadmap'], true);
        $this->assertTrue($this->settings()->isCustomised('general'));

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loop-types.reset', 'general'))
            ->assertRedirect(route('admin.loop-types'));

        $this->assertFalse($this->settings()->isCustomised('general'));
        $this->assertEqualsCanonicalizing(
            config('loop_types.types.general.cards'),
            $this->registry()->cardsFor('general'),
        );
    }

    // ── Rien n'est rétroactif ───────────────────────────────────────────────

    public function test_narrowing_a_preset_takes_nothing_from_an_existing_loop(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'project',
        ]);
        $this->registry()->applyPreset($loop);
        $this->assertContains('core.roadmap', $this->registry()->activeCardsFor($loop->fresh()));

        $this->settings()->save('project', ['core.members'], true);

        // The Loop keeps its composition, content included. Only Loops created
        // from now on, and an explicit type change, see the new preset.
        $this->assertContains('core.roadmap', $this->registry()->activeCardsFor($loop->fresh()));
    }

    // ── Disponibilité ───────────────────────────────────────────────────────

    public function test_an_unavailable_type_cannot_be_assigned_to_a_loop(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
        ]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.type.update', $loop), ['type' => 'peer_support'])
            ->assertSessionHas('error');

        $this->assertSame('general', $loop->fresh()->type);
    }

    public function test_a_loop_already_on_an_unavailable_type_may_be_saved_unchanged(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'peer_support',
        ]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.type.update', $loop), ['type' => 'peer_support'])
            ->assertSessionMissing('error');

        $this->assertSame('peer_support', $loop->fresh()->type);
    }

    public function test_the_listing_offers_only_the_available_types(): void
    {
        Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general']);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="project"', $html);
        // `training` a rejoint les types offerts avec TASK-1101 ; `peer_support`
        // reste retenu, faute de Cards de pair-aidance.
        $this->assertStringContainsString('value="training"', $html);
        $this->assertStringNotContainsString('value="peer_support"', $html);
    }

    // ── Choix du type à la création ─────────────────────────────────────────

    public function test_the_admin_creation_form_offers_the_type(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.create'))
            ->assertOk()
            ->assertSee(__('loops.type_choose_label'))
            ->assertSee('value="project"', false)
            ->assertSee('value="training"', false)
            ->assertDontSee('value="peer_support"', false);
    }

    public function test_an_admin_creates_a_loop_of_the_chosen_type_with_its_cards(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->superAdmin)->post(route('admin.loops.store'), [
            'name' => 'Boucle Projets', 'visibility' => 'private',
            'owner_id' => $owner->id, 'organization_id' => $this->org->id,
            'type' => 'project',
        ])->assertRedirect();

        $loop = Loop::where('name', 'Boucle Projets')->firstOrFail();

        $this->assertSame('project', $loop->type);
        // createLoopForOrg() never applied the preset — a Loop created from the
        // admin came out with no cards at all and relied on the fallback.
        $this->assertEqualsCanonicalizing(
            $this->registry()->cardsFor('project'),
            $loop->cards()->pluck('card_key')->all(),
        );
    }

    public function test_a_member_creates_a_loop_of_the_chosen_type(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Ma Boucle Dialogue', 'type' => 'general',
        ])->assertRedirect();

        $loop = Loop::where('name', 'Ma Boucle Dialogue')->firstOrFail();

        $this->assertSame('general', $loop->type);
        // Une Boucle neuve recoit exactement le socle de son type.
        $this->assertEqualsCanonicalizing(
            config('loop_types.types.general.cards'),
            $loop->cards()->pluck('card_key')->all(),
        );
    }

    public function test_an_unavailable_type_is_refused_at_creation(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Tentative', 'type' => 'peer_support',
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('loops', ['name' => 'Tentative']);
    }
}
