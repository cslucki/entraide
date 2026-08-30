<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two Loop administration screens: global and Organization-scoped.
 *
 * Beyond the columns themselves, two things are under test here — that changing
 * a type never destroys anything, and that the lists count in SQL instead of
 * hydrating every member of every Loop.
 */
class TASK1079LoopAdminTest extends TestCase
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
    }

    private function loop(array $overrides = []): Loop
    {
        return Loop::factory()->create(array_merge([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
        ], $overrides));
    }

    private function registry(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    // ── Admin global ────────────────────────────────────────────────────────

    public function test_the_global_list_shows_type_members_invitations_and_cards(): void
    {
        $loop = $this->loop(['name' => 'Boucle Admin 1079']);
        $this->registry()->applyPreset($loop);
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id, 'status' => 'active']);
        LoopInvitation::factory()->count(2)->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk();

        $response->assertSee('Boucle Admin 1079');
        $response->assertSee($this->registry()->label('general'));
        $response->assertSee($this->org->name);

        $listed = $response->viewData('loops')->firstWhere('id', $loop->id);
        $this->assertSame(1, $listed->active_members_count);
        $this->assertSame(2, $listed->invitations_count);
        $this->assertSame(2, $listed->pending_invitations_count);
        $this->assertSame(count($this->registry()->cardsFor('general')), $listed->enabled_cards_count);
    }

    public function test_the_pending_count_is_distinct_from_the_total(): void
    {
        $loop = $this->loop();
        LoopInvitation::factory()->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);
        LoopInvitation::factory()->revoked()->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);

        $listed = $this->actingAs($this->superAdmin)->get(route('admin.loops'))
            ->viewData('loops')->firstWhere('id', $loop->id);

        $this->assertSame(2, $listed->invitations_count);
        $this->assertSame(1, $listed->pending_invitations_count);
    }

    public function test_the_global_list_does_not_issue_a_query_per_loop(): void
    {
        foreach (range(1, 6) as $i) {
            $loop = $this->loop(['name' => "Boucle {$i}"]);
            $this->registry()->applyPreset($loop);
            LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id]);
            LoopInvitation::factory()->create([
                'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Counts are aggregated in the main query and cards eager-loaded once;
        // a per-row pattern would grow with the number of Loops.
        $this->assertLessThan(30, $count, "Query count {$count} suggests an N+1 on the Loops admin list");
    }

    public function test_a_super_admin_can_change_the_type(): void
    {
        $loop = $this->loop(['type' => 'general']);
        $this->registry()->applyPreset($loop);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.type.update', $loop), ['type' => 'project'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('project', $loop->fresh()->type);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $loop = $this->loop(['type' => 'general']);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.type.update', $loop), ['type' => 'wizardry'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('general', $loop->fresh()->type);
    }

    // ── Admin Organization ──────────────────────────────────────────────────

    public function test_the_org_admin_can_edit_name_description_and_type(): void
    {
        $loop = $this->loop(['name' => 'Avant', 'description' => 'Ancienne', 'type' => 'general']);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $loop->id]), [
                'name' => 'Après',
                'description' => 'Nouvelle description',
                'type' => 'training',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $loop->fresh();
        $this->assertSame('Après', $fresh->name);
        $this->assertSame('Nouvelle description', $fresh->description);
        $this->assertSame('training', $fresh->type);
    }

    public function test_the_org_admin_list_exposes_the_new_counters(): void
    {
        $loop = $this->loop();
        $this->registry()->applyPreset($loop);
        LoopMember::factory()->count(3)->create(['loop_id' => $loop->id, 'status' => 'active']);
        LoopInvitation::factory()->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);

        $listed = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops', ['organization' => $this->org->slug]))
            ->assertOk()
            ->viewData('loops')->firstWhere('id', $loop->id);

        $this->assertSame(3, $listed->active_members_count);
        $this->assertSame(1, $listed->invitations_count);
        // The listing is a listing: member management moved to the dedicated edit
        // page, so no member is hydrated here.
        $this->assertFalse($listed->relationLoaded('activeMembers'));
    }

    // ── Page d'édition dédiée (CRUD) ────────────────────────────────────────

    public function test_the_edit_page_gathers_identity_type_cards_and_members(): void
    {
        $loop = $this->loop(['name' => 'Boucle Editable', 'type' => 'project']);
        $this->registry()->applyPreset($loop);
        $member = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Membre Actif']);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id, 'status' => 'active']);
        $candidate = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Candidat Libre']);

        $response = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops.edit', ['organization' => $this->org->slug, 'loop' => $loop->id]))
            ->assertOk();

        $response->assertSee('Boucle Editable');

        // **Les types assignables**, et non tous les types. Depuis TASK-1108 le
        // serveur refuse un type retire des choix : le proposer quand meme
        // faisait un cul-de-sac — on cliquait, et on recevait une erreur. Un
        // choix absent vaut mieux, et l'ecran plateforme fait deja ainsi.
        //
        // La regle n'a pas change : l'ecran offre ce qu'on peut assigner, plus
        // celui que la Boucle porte deja. C'est l'ensemble qui a retreci, parce
        // que deux types sont fermes.
        foreach (array_keys($this->registry()->selectableFor($loop->type)) as $type) {
            $response->assertSee($this->registry()->label($type));
        }

        foreach ($this->registry()->keys() as $type) {
            if (! $this->registry()->isAvailable($type) && $type !== $loop->type) {
                $response->assertDontSee('value="'.$type.'"', false);
            }
        }
        $this->assertSame('project', $response->viewData('currentType'));
        // Cards, members and candidates all resolved server-side.
        $response->assertSee(__(config('loop_cards.cards.core.manifesto.label_key')));
        $response->assertSee('Membre Actif');
        $response->assertSee('Candidat Libre');
    }

    public function test_the_edit_page_marks_which_cards_come_from_the_type(): void
    {
        $loop = $this->loop(['type' => 'peer_support']);
        $this->registry()->applyPreset($loop);
        LoopCard::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            // `core.events` : depuis TASK-1105 la Roadmap fait partie du preset
            // Pair-aidance sous le nom « Engagements ». Meme regle, autre exemple.
            'card_key' => 'core.events', 'enabled' => true, 'added_by_preset' => null,
        ]);

        $preset = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops.edit', ['organization' => $this->org->slug, 'loop' => $loop->id]))
            ->assertOk()
            ->viewData('presetCards');

        // TASK-1332 : le Resume IA a rejoint le socle Pair-aidance, le
        // Manifeste l'a quitte (il reste activable, mais n'est plus impose).
        $this->assertContains('core.ai_summary', $preset);
        $this->assertNotContains('core.events', $preset, 'A human-added card must not read as part of the type baseline');
    }

    public function test_the_edit_page_only_offers_candidates_of_this_organization(): void
    {
        $loop = $this->loop();
        $otherOrg = Organization::factory()->create();
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Etranger Total']);
        $alreadyIn = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Deja Membre']);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $alreadyIn->id, 'status' => 'active']);

        $candidates = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops.edit', ['organization' => $this->org->slug, 'loop' => $loop->id]))
            ->assertOk()
            ->viewData('candidates')->pluck('id');

        $this->assertNotContains($foreigner->id, $candidates);
        $this->assertNotContains($alreadyIn->id, $candidates);
    }

    public function test_the_edit_page_of_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops.edit', ['organization' => $this->org->slug, 'loop' => $foreignLoop->id]))
            ->assertNotFound();
    }

    public function test_saving_returns_to_the_edit_page(): void
    {
        $loop = $this->loop(['type' => 'general']);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $loop->id]), [
                'name' => 'Renommée', 'description' => 'Desc', 'type' => 'project',
            ])
            ->assertRedirect(route('organization.admin.loops.edit', ['organization' => $this->org->slug, 'loop' => $loop->id]));
    }

    public function test_the_listing_no_longer_carries_edit_forms(): void
    {
        $loop = $this->loop();
        LoopMember::factory()->create(['loop_id' => $loop->id, 'status' => 'active']);

        $html = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        // Member management and editing live on the dedicated page now.
        $this->assertStringNotContainsString('loops.members.add', $html);
        $this->assertStringContainsString(
            route('organization.admin.loops.edit', ['organization' => $this->org->slug, 'loop' => $loop->id]),
            $html,
        );
    }

    public function test_an_org_admin_cannot_touch_a_loop_of_another_organization(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Etrangere']);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $foreignLoop->id]), [
                'name' => 'Detournee', 'type' => 'project',
            ])
            ->assertNotFound();

        $this->assertSame('Etrangere', $foreignLoop->fresh()->name);
    }

    public function test_a_standard_member_reaches_neither_admin_screen(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $loop = $this->loop();

        $this->actingAs($member)->get(route('admin.loops'))->assertForbidden();
        $this->actingAs($member)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $loop->id]), [
                'name' => 'Pirate', 'type' => 'project',
            ])
            ->assertForbidden();
    }

    // ── Page Vue et URL de workspace ────────────────────────────────────────

    public function test_the_admin_overview_shows_everything_including_the_manifesto(): void
    {
        $loop = $this->loop(['name' => 'Boucle Vue']);
        $this->registry()->applyPreset($loop);
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id, 'joined_at' => now()]);

        $manifesto = BlogPost::create([
            'user_id' => $owner->id, 'organization_id' => $this->org->id,
            'title' => 'Manifeste', 'slug' => 'manifeste-vue-'.uniqid(),
            'content' => '<p>NOTRE-RAISON-DETRE</p>', 'status' => 'draft',
        ]);
        $loop->forceFill(['manifesto_blog_post_id' => $manifesto->id])->save();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $loop))
            ->assertOk()
            ->assertSee('Boucle Vue')
            ->assertSee('NOTRE-RAISON-DETRE')
            ->assertSee(__('loops.governance_title'))
            ->assertSee(__('loops.cards_linked'));
    }

    public function test_the_overview_sanitises_the_manifesto(): void
    {
        $loop = $this->loop();
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $manifesto = BlogPost::create([
            'user_id' => $owner->id, 'organization_id' => $this->org->id,
            'title' => 'Manifeste', 'slug' => 'manifeste-xss-'.uniqid(),
            'content' => '<p>Texte</p><script>alert(1)</script>', 'status' => 'draft',
        ]);
        $loop->forceFill(['manifesto_blog_post_id' => $manifesto->id])->save();

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops.show', $loop))->assertOk()->getContent();

        $this->assertStringContainsString('Texte', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_the_overview_is_read_only(): void
    {
        $loop = $this->loop();
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id, 'status' => 'active']);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops.show', $loop))->assertOk()->getContent();

        // Governance is displayed but nothing can be changed from here.
        $this->assertStringNotContainsString(__('loops.governance_promote_owner'), $html);
    }

    public function test_the_workspace_url_is_scoped_to_the_loops_own_organization(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true, 'slug' => 'ailleurs']);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'status' => 'active']);

        // route('loops.show') would resolve against the *current* context, which
        // is the admin's own Organization — the bug this fixes.
        $this->assertStringContainsString('/org/ailleurs/loops/', $foreignLoop->workspaceUrl());
        $this->assertStringContainsString($foreignLoop->id, $foreignLoop->workspaceUrl());
    }

    public function test_the_listing_opens_the_overview_from_the_loop_name(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true, 'slug' => 'ailleurs2']);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'status' => 'active']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => 'all']))
            ->assertOk()
            ->assertSee(route('admin.loops.show', $foreignLoop), false)
            // The workspace needs an active membership and has no super-admin
            // bypass, so offering it from the listing only produced a 404.
            ->assertDontSee($foreignLoop->workspaceUrl(), false);
    }

    public function test_the_overview_offers_the_workspace_only_to_a_member(): void
    {
        $loop = $this->loop(['name' => 'Boucle Passage']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $loop))
            ->assertOk()
            ->assertDontSee($loop->workspaceUrl(), false);

        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->superAdmin->id, 'joined_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $loop))
            ->assertOk()
            ->assertSee($loop->workspaceUrl(), false);
    }

    public function test_the_overview_lists_the_cards_of_a_loop_that_predates_the_cards_table(): void
    {
        // No row in loop_cards: the Loop falls back to its type preset, and the
        // overview used to read the raw relation and show nothing at all while
        // the workspace rendered four cards.
        $loop = $this->loop(['type' => 'project']);
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $member->id, 'joined_at' => now()]);

        $this->assertSame(0, $loop->cards()->count());

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops.show', $loop))->assertOk()->getContent();

        foreach ($this->registry()->cardsFor('project') as $key) {
            $this->assertStringContainsString(__(config('loop_cards.cards')[$key]['label_key']), $html);
        }

        // And a glance at what they hold, not just their names.
        $this->assertStringContainsString('1 membre', $html);
    }

    // ── Changement de type : rien n'est jamais perdu ────────────────────────

    public function test_the_full_type_rotation_through_the_admin_loses_no_card_or_content(): void
    {
        // TASK-1332 : core.ai_summary a rejoint tous les presets, il n'est
        // donc plus "genuinely outside" d'aucun type traverse ici. Le
        // Manifeste, lui, ne l'est plus jamais par defaut : il reste le
        // candidat sur pour verifier qu'un ajout humain survit a chaque
        // rotation, quel que soit le type.
        $loop = $this->loop(['type' => 'peer_support']);
        $this->registry()->applyPreset($loop);
        LoopCard::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            'card_key' => 'core.manifesto', 'enabled' => true, 'added_by_preset' => null,
        ]);
        $before = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all();

        foreach (['project', 'training', 'peer_support', 'general'] as $type) {
            $this->actingAs($this->superAdmin)
                ->put(route('admin.loops.type.update', $loop), ['type' => $type])
                ->assertSessionHasNoErrors();
        }

        $after = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all();

        foreach ($before as $card) {
            $this->assertContains($card, $after, "Card {$card} lost during the type rotation");
        }
        $this->assertSame(count($after), count(array_unique($after)), 'A card was duplicated');
        // The card a human added is still there, still flagged as theirs.
        $this->assertNull(
            LoopCard::where('loop_id', $loop->id)->where('card_key', 'core.manifesto')->value('added_by_preset'),
        );
    }

    public function test_a_newly_created_loop_gets_its_type_preset(): void
    {
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['members_can_create_loops' => true, 'loop_mode' => 'multi']);
        app()->instance('current_organization', $this->org);

        $this->actingAs($creator)->post(route('loops.store'), ['name' => 'Née typée'])->assertRedirect();

        $loop = Loop::where('name', 'Née typée')->sole();
        $this->assertSame('general', $loop->type);
        $this->assertEqualsCanonicalizing(
            $this->registry()->cardsFor('general'),
            LoopCard::where('loop_id', $loop->id)->pluck('card_key')->all(),
        );
    }
}
