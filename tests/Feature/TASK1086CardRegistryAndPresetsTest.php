<?php

namespace Tests\Feature;

use App\Http\Controllers\LoopController;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopPresetSyncService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une seule declaration des Cards, et une synchronisation des socles qui
 * n'efface rien.
 *
 * Deux choses sont defendues ici : plus aucune liste concurrente des Cards
 * rendues ne subsiste, et une composition locale — Card eteinte a la main, Card
 * ajoutee a la main — survit a toutes les synchronisations.
 */
class TASK1086CardRegistryAndPresetsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true, 'admin_id' => $this->admin->id,
        ]);
        $this->admin->update(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function registry(): LoopCardRegistry
    {
        return app(LoopCardRegistry::class);
    }

    private function loop(string $type = 'project', bool $preset = true): Loop
    {
        $loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle '.uniqid(),
            'slug' => 'boucle-'.uniqid(),
            'type' => $type,
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $this->admin->id,
        ]);

        if ($preset) {
            app(LoopTypeRegistry::class)->applyPreset($loop);
        }

        return $loop;
    }

    // ── Le registre est la seule declaration ────────────────────────────────

    public function test_the_registry_renders_every_declared_card_in_order(): void
    {
        // Ce test ne recopie PAS la liste des Cards. Il l'a fait deux fois, et
        // il a fallu le corriger a chaque Card metier sans jamais rien
        // apprendre. Il fige desormais ce qui doit rester vrai quoi qu'on
        // ajoute : les quatre Cards fondatrices sont la, tout ce qui est
        // declare rendu sort dans l'ordre du catalogue, et cet ordre est
        // strictement croissant.
        $keys = $this->registry()->renderableKeys();

        foreach (['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.members'] as $founding) {
            $this->assertContains($founding, $keys);
        }

        $orders = array_map(fn ($key) => $this->registry()->get($key)['order'], $keys);

        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders, 'Les Cards doivent sortir dans l\'ordre du catalogue.');
        $this->assertSame(count($orders), count(array_unique($orders)), 'Deux Cards ne peuvent pas partager un ordre.');
    }

    public function test_no_divergent_list_of_rendered_cards_survives(): void
    {
        // Les trois declarations concurrentes d'avant : la cle `rendered` de la
        // configuration et la constante du controleur ont disparu.
        $this->assertNull(config('loop_cards.rendered'));
        $this->assertFalse(
            defined(LoopController::class.'::RENDERED_CARDS'),
        );

        // Et la chaine de conditions sur une cle de Card a quitte le Blade.
        $blade = file_get_contents(resource_path('views/loops/show.blade.php'));
        $this->assertStringNotContainsString("\$card['key'] === 'core.", $blade);
    }

    public function test_every_renderable_card_names_a_component_or_a_view(): void
    {
        foreach ($this->registry()->renderableKeys() as $key) {
            $this->assertTrue(
                $this->registry()->componentFor($key) !== null
                    || $this->registry()->viewFor($key) !== null,
                "La Card « {$key} » est déclarée rendue sans rien pour la rendre.",
            );
        }
    }

    public function test_an_unknown_key_renders_nothing_and_raises_nothing(): void
    {
        $registry = $this->registry();

        $this->assertFalse($registry->exists('core.sondage'));
        $this->assertFalse($registry->isRenderable('core.sondage'));
        $this->assertNull($registry->componentFor('core.sondage'));
        $this->assertNull($registry->viewFor('core.sondage'));
        // Le libelle retombe sur la cle : lisible, jamais une exception.
        $this->assertSame('core.sondage', $registry->label('core.sondage'));
    }

    public function test_an_unknown_key_cannot_be_activated(): void
    {
        $loop = $this->loop();

        $this->expectException(\RuntimeException::class);
        app(LoopCardCompositionService::class)->enable($loop, 'core.sondage');
    }

    public function test_an_unknown_key_in_the_composition_is_not_rendered(): void
    {
        $loop = $this->loop();

        // Ecrite directement en base, comme le ferait une cle retiree du
        // catalogue apres coup.
        LoopCard::create([
            'organization_id' => $this->org->id,
            'loop_id' => $loop->id,
            'card_key' => 'core.sondage',
            'enabled' => true,
            'added_by_preset' => null,
        ]);

        $keys = $this->registry()->workspaceCardsFor($loop->fresh(), $this->admin)
            ->pluck('key')->all();

        $this->assertNotContains('core.sondage', $keys);
    }

    public function test_the_administration_and_the_workspace_share_one_catalogue(): void
    {
        $this->assertSame(
            $this->registry()->renderableKeys(),
            array_keys($this->registry()->manageableCatalogue()),
        );

        $this->assertSame(
            $this->registry()->manageableKeys(),
            app(LoopCardCompositionService::class)->manageableKeys(),
        );
    }

    public function test_chatloop_is_not_a_card(): void
    {
        $this->assertFalse($this->registry()->exists('core.chatloop'));
        $this->assertNotContains('core.chatloop', $this->registry()->renderableKeys());
    }

    public function test_a_required_card_cannot_be_switched_off(): void
    {
        $loop = $this->loop();

        $this->assertTrue($this->registry()->isRequired('core.members'));

        $this->expectException(\RuntimeException::class);
        app(LoopCardCompositionService::class)->disable($loop, 'core.members');
    }

    // ── Synchronisation des socles ──────────────────────────────────────────

    public function test_a_dry_run_writes_nothing(): void
    {
        $loop = $this->loop('project', preset: false);
        $before = LoopCard::where('loop_id', $loop->id)->count();

        $preview = app(LoopPresetSyncService::class)->preview('project');

        $this->assertSame($before, LoopCard::where('loop_id', $loop->id)->count());
        $this->assertGreaterThan(0, $preview['loops_affected']);
        // TASK-1332 : le Manifeste a quitte le socle Projet, le Resume IA l'a
        // rejoint — c'est desormais lui que le preset ajouterait.
        $this->assertArrayHasKey('core.ai_summary', $preview['cards_to_add']);
    }

    public function test_the_command_writes_nothing_in_dry_run(): void
    {
        $loop = $this->loop('project', preset: false);

        $this->artisan('loops:sync-presets', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, LoopCard::where('loop_id', $loop->id)->count());
    }

    public function test_the_command_filters_by_type(): void
    {
        $project = $this->loop('project', preset: false);
        $general = $this->loop('general', preset: false);

        $this->artisan('loops:sync-presets', ['--type' => 'project'])->assertSuccessful();

        $this->assertGreaterThan(0, LoopCard::where('loop_id', $project->id)->count());
        $this->assertSame(0, LoopCard::where('loop_id', $general->id)->count());
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->artisan('loops:sync-presets', ['--type' => 'inexistant'])->assertFailed();
    }

    public function test_the_synchronisation_is_additive_and_idempotent(): void
    {
        $loop = $this->loop('project', preset: false);
        $sync = app(LoopPresetSyncService::class);

        $first = $sync->sync('project');
        $countAfterFirst = LoopCard::where('loop_id', $loop->id)->count();

        $second = $sync->sync('project');

        $this->assertGreaterThan(0, $first['loops_affected']);
        $this->assertSame(0, $second['loops_affected']);
        $this->assertSame($countAfterFirst, LoopCard::where('loop_id', $loop->id)->count());
    }

    public function test_a_locally_disabled_card_is_never_relit(): void
    {
        $loop = $this->loop('project');
        app(LoopCardCompositionService::class)->disable($loop, 'core.roadmap');

        app(LoopPresetSyncService::class)->sync('project');

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap', 'enabled' => false,
        ]);
    }

    public function test_a_locally_added_card_is_never_removed(): void
    {
        // Une Boucle « general » dont le socle ne prescrit que les membres, avec
        // une Roadmap ajoutee a la main.
        $loop = $this->loop('general');
        app(LoopCardCompositionService::class)->enable($loop, 'core.roadmap');

        app(LoopPresetSyncService::class)->sync('general');

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap',
            'enabled' => true, 'added_by_preset' => null,
        ]);
    }

    public function test_removing_a_card_from_a_preset_removes_it_from_nobody(): void
    {
        $loop = $this->loop('project');

        $this->actingAs($this->admin)
            ->put(route('admin.loop-types.update', ['type' => 'project']), [
                'cards' => ['core.members'],
                'available' => 1,
            ])
            ->assertRedirect();

        // La Boucle garde tout ce qu'elle avait.
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap', 'enabled' => true,
        ]);
        // TASK-1332 : core.ai_summary a rejoint le socle Projet (le Manifeste
        // l'a quitte) — c'est desormais lui que la Boucle a recu a l'origine.
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.ai_summary', 'enabled' => true,
        ]);
    }

    public function test_adding_a_card_to_a_preset_reaches_existing_loops(): void
    {
        // Une Boucle « general » : socle a une seule Card.
        $loop = $this->loop('general');
        $this->assertDatabaseMissing('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.loop-types.update', ['type' => 'general']), [
                'cards' => ['core.members', 'core.roadmap'],
                'available' => 1,
            ])
            ->assertRedirect();

        // Le defaut d'avant : le socle changeait, aucune Boucle ne bougeait.
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $loop->id, 'card_key' => 'core.roadmap', 'enabled' => true,
        ]);
    }

    public function test_reading_a_workspace_writes_no_card(): void
    {
        $loop = $this->loop('project', preset: false);

        LoopMember::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            'user_id' => $this->admin->id, 'role' => 'owner', 'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->get(route('organization.loops.show', [
                'organization' => $this->org->slug, 'loop' => $loop->id,
            ]))
            ->assertOk();

        // Aucun write-on-read : la Boucle n'a toujours aucune ligne.
        $this->assertSame(0, LoopCard::where('loop_id', $loop->id)->count());
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_the_synchronisation_does_not_leak_across_organizations(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $foreign = Loop::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Etrangere', 'slug' => 'etrangere-'.uniqid(),
            'type' => 'project', 'status' => 'active', 'visibility' => 'private',
            'created_by' => $otherUser->id,
        ]);

        app(LoopPresetSyncService::class)->sync('project');

        // Les Cards creees pour cette Boucle portent bien SON Organization, pas
        // celle qui a lance la commande.
        foreach (LoopCard::where('loop_id', $foreign->id)->get() as $card) {
            $this->assertSame($otherOrg->id, $card->organization_id);
        }
    }

    public function test_a_stranger_cannot_configure_a_type(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($stranger)
            ->put(route('admin.loop-types.update', ['type' => 'project']), [
                'cards' => ['core.members'], 'available' => 1,
            ])
            ->assertForbidden();
    }
}
