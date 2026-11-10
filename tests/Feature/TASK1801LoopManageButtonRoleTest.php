<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationLoopPlugin;
use App\Models\User;
use App\Services\Loops\LoopAiAssistants;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1801 correctif 01 — le bouton « Gérer la Boucle ».
 *
 * Avant ce correctif, le declencheur du menu affichait toujours le texte
 * « Gérer la Boucle » au bureau, quel que soit le role : un membre simple,
 * qui ne voit dans ce menu que « Membres », se voyait proposer le meme
 * libelle qu'un proprietaire capable d'archiver ou de modifier la Boucle.
 *
 * Ce que ces tests gardent :
 *
 * - **Proprietaire et animateur** (celui-ci via la configuration des
 *   assistants IA, sa seule prise sur ce menu) gardent le texte, au bureau
 *   comme au telephone.
 * - **Un membre simple** ne voit plus que le ⋮ nu — deja utilise sous
 *   768 px — meme au bureau. Le CONTENU du menu ne change pas : « Membres »
 *   y reste, rien n'est retire ni ajoute.
 */
class TASK1801LoopManageButtonRoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function workspace(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('organization.loops.show', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->getContent();
    }

    public function test_the_owner_keeps_the_manage_label(): void
    {
        $html = $this->workspace($this->owner);

        $this->assertStringContainsString(__('loops.manage_loop'), $html);
        $this->assertStringContainsString('data-loop-menu-variant="gestionnaire"', $html);
        $this->assertStringNotContainsString('data-loop-menu-variant="membre"', $html);
    }

    public function test_a_facilitator_who_can_configure_the_ai_assistants_keeps_the_manage_label(): void
    {
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->org->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );
        OrganizationLoopPlugin::query()->create([
            'organization_id' => $this->org->id,
            'plugin_key' => LoopAiAssistants::PLUGIN,
            'available' => true,
        ]);

        $facilitateur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre($facilitateur, 'facilitator');

        $html = $this->workspace($facilitateur);

        $this->assertStringContainsString(__('loops.manage_loop'), $html);
        $this->assertStringContainsString('data-loop-menu-variant="gestionnaire"', $html);
    }

    public function test_a_plain_member_gets_the_bare_dots_not_the_manage_label(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre($membre, 'member');

        $html = $this->workspace($membre);

        // Le declencheur lui-meme, et rien d'autre : le panneau qui le suit
        // porte sa propre section « Gérer la Boucle » (TASK-1769,
        // `loops.menu_manage_section`) au-dessus de « Membres » sous 768 px —
        // un intitule different, hors perimetre de ce correctif, qui ne
        // pretend rien de nouveau et que ce test laisse donc en l'etat.
        $triggerStart = strrpos(substr($html, 0, strpos($html, 'data-loop-menu-trigger')), '<button');
        $triggerEnd = strpos($html, '</button>', $triggerStart);
        $trigger = substr($html, $triggerStart, $triggerEnd - $triggerStart);
        $triggerVisible = preg_replace('/<[^>]+>/', ' ', $trigger);

        $this->assertStringNotContainsString(__('loops.manage_loop'), $triggerVisible);
        $this->assertStringContainsString('data-loop-menu-variant="membre"', $html);
        $this->assertStringNotContainsString('data-loop-menu-variant="gestionnaire"', $html);

        // Le menu existe toujours, et son seul geste pour un membre simple
        // reste « Membres » : ce correctif change le declencheur, pas le
        // contenu.
        $this->assertStringContainsString('data-loop-menu-trigger', $html);
        $this->assertStringContainsString(__('loops.menu_manage_section'), $html);
    }
}
