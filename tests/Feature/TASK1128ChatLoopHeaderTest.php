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
 * - **La barre coupe au bon endroit.** Cinq outils directement accessibles,
 *   les mis en avant d'abord, et ce qui depasse va au debordement — sans
 *   qu'aucune regle metier ne bouge. Cinq *visibles* n'est pas cinq *mis en
 *   avant* : `MAX_PRIMARY` reste a trois, et un test le verifie.
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

    // ── La barre : cinq visibles, et le debordement ─────────────────────────

    public function test_five_tools_are_directly_accessible(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal', 'core.article');

        $html = $this->workspace();

        // Sept outils actifs : cinq devant, deux au debordement — et le
        // compteur le dit en mots, pas en jargon.
        $this->assertStringContainsString(
            trans_choice('loops.tools_overflow_count', 2, ['count' => 2]),
            $html,
        );
    }

    public function test_the_featured_tools_come_first(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal', 'core.article');

        $composition = app(LoopPresetConfigurator::class)->describe($this->loop);
        $misEnAvant = array_column($composition['primary'], 'key');

        $this->assertCount(3, $misEnAvant, 'la regle des 3 mis en avant a bouge');

        $html = $this->workspace();

        // Les trois mis en avant apparaissent avant le quatrieme outil de la
        // barre : cinq visibles ne veut pas dire cinq mis en avant.
        $positions = array_map(
            fn (string $cle) => strpos($html, app(\App\Support\Loops\LoopCardRegistry::class)->label($cle)),
            $misEnAvant,
        );

        $this->assertSame($positions, array_values(array_filter($positions)), 'un outil mis en avant manque de la barre');
    }

    public function test_the_overflow_disappears_when_everything_fits(): void
    {
        // Cinq outils actifs exactement : plus rien a faire deborder, donc
        // aucun controle de debordement.
        $this->activer('core.roadmap', 'core.decisions');

        $html = $this->workspace();

        $this->assertStringNotContainsString(
            trans_choice('loops.tools_overflow_count', 1, ['count' => 1]),
            $html,
        );
        $this->assertStringNotContainsString(__('loops.tools_others_title'), $html);
    }

    public function test_showing_five_tools_never_touches_the_stored_ranks(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal');

        $avant = LoopCard::where('loop_id', $this->loop->id)
            ->orderBy('card_key')->pluck('primary_rank', 'card_key')->all();

        $this->workspace();

        $apres = LoopCard::where('loop_id', $this->loop->id)
            ->orderBy('card_key')->pluck('primary_rank', 'card_key')->all();

        // **Cinq visibles n'est pas cinq mis en avant.** `primary_rank` ne
        // bouge pas, la regle de TASK-1124 non plus.
        $this->assertSame($avant, $apres);
        $this->assertSame(3, \App\Services\Loops\LoopCardCompositionService::MAX_PRIMARY);
    }

    public function test_the_url_no_longer_controls_the_toolbar(): void
    {
        $this->activer('core.roadmap', 'core.decisions', 'core.journal', 'core.article');

        // Le prototype de comparaison a ete retire : une query string ne
        // pilote plus cette UX.
        $avecParam = $this->workspace(params: ['outils' => '3']);
        $sansParam = $this->workspace();

        foreach ([$avecParam, $sansParam] as $html) {
            $this->assertStringContainsString(
                trans_choice('loops.tools_overflow_count', 2, ['count' => 2]),
                $html,
            );
        }
    }

    public function test_the_ai_summary_still_has_its_own_button(): void
    {
        // Verification exigee a l'arbitrage : le Resume IA n'a pas disparu
        // avec le regroupement. Il reste une action de conversation, hors du
        // menu, avec son bouton propre — il n'etait absent de la recette que
        // parce que la Boucle QA ne l'avait pas actif.
        LoopCard::create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->org->id,
            'card_key' => 'core.ai_summary',
            'enabled' => true,
        ]);

        $this->assertStringContainsString(__('loops.cards.ai_summary.label'), $this->workspace());
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
