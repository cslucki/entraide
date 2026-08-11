<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopPresetConfigurator;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le header de ChatLoop, et l'acces aux outils.
 *
 * Ce que ces tests gardent :
 *
 * - **« Lancer » ne revient pas.** C'etait une legende de barre — un `<span>`
 *   sans handler — qui occupait la place d'un outil.
 * - **Le menu « Gerer » ne ment pas.** Il ne montre que ce que la personne
 *   peut reellement faire, avec les gardes qui existaient deja. Un menu qui
 *   proposerait « Modifier » a qui ne le peut pas serait pire que quatre
 *   boutons.
 * - **La barre coupe au bon endroit.** 3 par defaut, 5 avec le prototype, et
 *   ce qui depasse va au debordement — sans qu'aucune regle metier ne bouge.
 */
class TASK1128ChatLoopHeaderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        // Organization non-default : rien ici ne doit dependre de `main`.
        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'loops_enabled' => true,
            'loop_composition_policy' => 'owner_allowed',
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->owner->id, 'visibility' => 'private',
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);
        $this->membre($this->owner, 'owner');

        app()->instance('current_organization', $this->org);
    }

    private function membre(User $user, string $role = 'member'): LoopMember
    {
        return LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function workspace(?User $user = null, array $params = []): string
    {
        return $this->actingAs($user ?? $this->owner)
            ->get(route('organization.loops.show', array_merge(
                ['organization' => $this->org->slug, 'loop' => $this->loop->id],
                $params,
            )))
            ->assertOk()
            ->getContent();
    }

    /** Rend n outils actifs en plus du preset, et renvoie leurs cles. */
    private function activer(string ...$cles): void
    {
        $configurator = app(LoopPresetConfigurator::class);

        foreach ($cles as $cle) {
            $configurator->enable($this->owner, $this->loop, $cle);
        }
    }

    // ── « Lancer » ──────────────────────────────────────────────────────────

    public function test_the_launch_label_is_gone_for_good(): void
    {
        // La cle elle-meme a disparu des deux langues : impossible de la
        // reafficher par accident depuis une vue.
        $this->assertSame('loops.cards_bar_launch', __('loops.cards_bar_launch'));
        $this->assertSame('loops.cards_bar_hint', __('loops.cards_bar_hint'));

        // Et la barre ne dit plus « Lancer ».
        $visible = preg_replace('/<[^>]+>/', ' ', $this->workspace());
        $this->assertStringNotContainsString('Lancer', $visible);
    }

    // ── Le menu « Gerer » ───────────────────────────────────────────────────

    public function test_the_manage_menu_gathers_the_gestures_of_an_owner(): void
    {
        $html = $this->workspace();

        $this->assertStringContainsString(__('loops.manage_loop'), $html);

        // Les quatre gestes y sont, et le lien vers les outils pointe vers
        // l'ecran de TASK-1127.
        $this->assertStringContainsString(__('loops.owner_tools_action'), $html);
        $this->assertStringContainsString(__('loops.edit'), $html);
        $this->assertStringContainsString(__('loops.archive_action'), $html);
        $this->assertStringContainsString(
            route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
            $html,
        );
    }

    public function test_the_menu_never_offers_what_a_plain_member_cannot_do(): void
    {
        $simple = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre($simple);

        $html = $this->workspace($simple);
        $visible = preg_replace('/<[^>]+>/', ' ', $html);

        // Un membre simple ne compose pas, ne modifie pas, n'archive pas.
        $this->assertStringNotContainsString(__('loops.owner_tools_action'), $visible);
        $this->assertStringNotContainsString(__('loops.archive_action'), $visible);
        $this->assertStringNotContainsString(
            route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
            $html,
        );
    }

    public function test_a_locked_organization_closes_the_tools_entry(): void
    {
        // La porte de TASK-1125 : verrouillee, le proprietaire ne compose pas,
        // et le menu ne doit pas le lui proposer.
        $this->org->update(['loop_composition_policy' => 'locked']);

        $html = $this->workspace();

        $this->assertStringNotContainsString(
            route('organization.loops.tools', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
            $html,
        );

        // Le menu reste, parce que Modifier et Archiver, eux, restent ouverts.
        $this->assertStringContainsString(__('loops.manage_loop'), $html);
    }

    // ── La barre : 3, 5, et le debordement ──────────────────────────────────

    public function test_the_bar_shows_three_tools_by_default(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal', 'core.article');

        $html = $this->workspace();

        // Sept outils actifs, trois devant : le compteur du debordement dit
        // exactement ce qui reste.
        $this->assertStringContainsString(__('loops.tools_others_title'), $html);
        $this->assertStringContainsString('>4</span>', $html);
    }

    public function test_the_prototype_shows_five_tools_and_shrinks_the_overflow(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal', 'core.article');

        $html = $this->workspace(params: ['outils' => 5]);

        // Cinq devant, deux derriere. Le meme ensemble, coupe ailleurs.
        $this->assertStringContainsString(__('loops.tools_others_title'), $html);
        $this->assertStringContainsString('>2</span>', $html);
    }

    public function test_the_overflow_disappears_when_everything_fits(): void
    {
        // Cinq outils actifs exactement, variante a cinq : « Autres outils »
        // n'a plus de raison d'etre.
        $this->activer('core.roadmap', 'core.decisions');

        $html = $this->workspace(params: ['outils' => 5]);

        $this->assertStringNotContainsString(__('loops.tools_others_title'), $html);
    }

    public function test_the_prototype_never_writes_anything(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal');

        $avant = LoopCard::where('loop_id', $this->loop->id)
            ->orderBy('card_key')->pluck('primary_rank', 'card_key')->all();

        $this->workspace(params: ['outils' => 5]);

        $apres = LoopCard::where('loop_id', $this->loop->id)
            ->orderBy('card_key')->pluck('primary_rank', 'card_key')->all();

        // Le prototype est un choix d'affichage. `primary_rank` ne bouge pas,
        // la regle des 3 mis en avant non plus.
        $this->assertSame($avant, $apres);
    }

    public function test_an_unknown_value_falls_back_to_three(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal', 'core.article');

        // `?outils=99` ne doit pas ouvrir un reglage libre.
        $html = $this->workspace(params: ['outils' => '99']);

        $this->assertStringContainsString('>4</span>', $html);
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_the_workspace_of_another_organization_stays_out_of_reach(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $autreLoop = Loop::factory()->create([
            'organization_id' => $autreOrg->id, 'status' => 'active', 'type' => 'general',
            'visibility' => 'private',
        ]);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [
                'organization' => $this->org->slug, 'loop' => $autreLoop->id,
            ]))
            ->assertNotFound();
    }
}
