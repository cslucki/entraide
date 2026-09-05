<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK1076CatalogPolishTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'loops_enabled' => true,
            'members_can_create_loops' => true,
            'loop_mode' => 'multi',
        ], $overrides));
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    /** The catalog redirects to the single Loop when only one is accessible. */
    private function padCatalog(Organization $organization, int $count = 2): void
    {
        Loop::factory()->count($count)->create(['organization_id' => $organization->id]);
    }

    // ── Placeholder ────────────────────────────────────────────────────────

    public function test_loop_without_cover_uses_the_bouclepro_rings_artwork(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $html = $this->actingAs($user)->get(route('loops.show', $loop))->assertOk()->getContent();

        // The rings artwork is inlined (recoloured per Loop), identified by its
        // source viewBox and its concentric circle geometry.
        $this->assertStringContainsString('viewBox="0 0 640 660"', $html);
        $this->assertStringContainsString('r="286"', $html);
    }

    public function test_placeholder_never_renders_the_code_bracket_glyph(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $html = $this->actingAs($user)->get(route('loops.show', $loop))->assertOk()->getContent();

        // Heroicons "code-bracket" path used before TASK-1076.
        $this->assertStringNotContainsString('M17.25 6.75L22.5 12', $html);
    }

    public function test_real_cover_image_takes_precedence_over_placeholder(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create([
            'organization_id' => $org->id,
            'cover_image_path' => 'loop-covers/abc/cover.jpg',
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $html = $this->actingAs($user)->get(route('loops.show', $loop))->assertOk()->getContent();

        $this->assertStringContainsString('loop-covers/abc/cover.jpg', $html);
        $this->assertStringNotContainsString('viewBox="0 0 640 660"', $html);
    }

    public function test_placeholder_and_real_cover_share_the_same_aspect_ratio_box(): void
    {
        // Regression guard for the bug this task fixes: the ratio used to come
        // from a Tailwind `aspect-[5/2]` class that was missing from the built
        // CSS, so a placeholder fell back to the SVG's own near-square intrinsic
        // size and rendered a much taller card than one with a real image.
        //
        // Arbitrary values are emitted normally in this project (tailwindcss v3
        // via postcss.config.js); the class was missing because the assets had
        // not been rebuilt. The inline `aspect-ratio` is still the right fix, for
        // a different reason: `ratio` is a per-call-site prop and Tailwind scans
        // sources as plain text, so an interpolated class is never seen at all.
        $org = $this->org();
        Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => 'loop-covers/a/c.jpg']);
        Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $html = $this->actingAs($user)->get(route('loops.index'))->assertOk()->getContent();

        // One identical inline aspect-ratio box per card — image or placeholder.
        $this->assertSame(2, substr_count($html, 'aspect-ratio: 5 / 2'));
    }

    public function test_placeholder_variant_is_deterministic_for_a_given_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $first = $this->actingAs($user)->get(route('loops.show', $loop))->getContent();
        $second = $this->actingAs($user)->get(route('loops.show', $loop))->getContent();

        preg_match('/id="ringGrad-([a-f0-9]{8})"/', $first, $m1);
        preg_match('/id="ringGrad-([a-f0-9]{8})"/', $second, $m2);
        preg_match('/<stop offset="0%" stop-color="(#[A-F0-9]{6})"/i', $first, $c1);
        preg_match('/<stop offset="0%" stop-color="(#[A-F0-9]{6})"/i', $second, $c2);

        $this->assertNotEmpty($m1[1] ?? null);
        $this->assertSame($m1[1], $m2[1], 'The gradient id must be stable for a Loop.');
        $this->assertSame($c1[1] ?? 'a', $c2[1] ?? 'b', 'The colour variant must be stable for a Loop.');
    }

    public function test_two_loops_get_independent_gradient_ids_on_the_same_page(): void
    {
        // Duplicated <defs> ids across cards would make every placeholder fall
        // back to the first gradient found in the DOM.
        $org = $this->org();
        Loop::factory()->count(4)->create(['organization_id' => $org->id, 'cover_image_path' => null]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $html = $this->actingAs($user)->get(route('loops.index'))->assertOk()->getContent();

        preg_match_all('/id="ringGrad-([a-f0-9]{8})"/', $html, $matches);
        $ids = $matches[1];

        $this->assertCount(4, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Each card must own a unique gradient id.');
    }

    // ── Edit action on the card ─────────────────────────────────────────────

    public function test_owner_sees_the_edit_action_on_the_card(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'name' => 'Boucle éditable']);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertSee(route('loops.edit', $loop), false);
    }

    public function test_organization_admin_sees_the_edit_action_on_the_card(): void
    {
        $orgAdmin = User::factory()->create();
        $org = $this->org(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $org->id]);
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);

        $this->actingAs($orgAdmin->fresh())
            ->get(route('loops.index'))
            ->assertOk()
            ->assertSee(route('loops.edit', $loop), false);
    }

    public function test_standard_member_does_not_see_the_edit_action(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertDontSee(route('loops.edit', $loop), false);
    }

    public function test_moderator_does_not_see_the_edit_action(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $moderator = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->moderator()->create(['loop_id' => $loop->id, 'user_id' => $moderator->id]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);

        $this->actingAs($moderator)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertDontSee(route('loops.edit', $loop), false);
    }

    public function test_non_member_does_not_see_the_edit_action(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $stranger = User::factory()->create(['organization_id' => $org->id]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);

        $this->actingAs($stranger)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertDontSee(route('loops.edit', $loop), false);
    }

    // ── Redirect back to the catalog ────────────────────────────────────────

    public function test_update_returns_to_the_catalog_and_flags_the_edited_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->put(route('loops.update', $loop), ['name' => 'Nom mis à jour'])
            ->assertRedirect(route('loops.index', ['updated' => $loop->id]))
            ->assertSessionHas('success');

        $this->assertSame('Nom mis à jour', $loop->fresh()->name);
    }

    public function test_update_keeps_the_slug_stable(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'slug' => 'slug-origine']);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->put(route('loops.update', $loop), ['name' => 'Un nom totalement different'])
            ->assertRedirect();

        $this->assertSame('slug-origine', $loop->fresh()->slug);
    }

    public function test_admin_loop_controller_update_still_redirects_to_the_admin_screen(): void
    {
        // The super-admin journey must be untouched by the member-facing change.
        $superAdmin = User::factory()->create(['is_admin' => true]);
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($superAdmin)
            ->put(route('admin.loops.update', $loop), [
                'name' => 'Renommee par le super-admin',
                'visibility' => 'private',
            ])
            ->assertRedirect(route('admin.loops.edit', $loop));
    }

    // ── Card content ────────────────────────────────────────────────────────

    public function test_card_shows_tagline_owner_members_and_access_badge(): void
    {
        $org = $this->org();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $loop = Loop::factory()->requestAccess()->create([
            'organization_id' => $org->id,
            'name' => 'Cours IA générative',
            'tagline' => 'Pour les curieux et les pros',
        ]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);

        $viewer = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($viewer)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertSee('Cours IA générative')
            ->assertSee('Pour les curieux et les pros')
            ->assertSee(__('loops.access_badge_request'))
            ->assertSee($owner->publicDisplayName());
    }

    public function test_card_does_not_print_the_same_sentence_twice(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create([
            'organization_id' => $org->id,
            'tagline' => 'Une seule et meme phrase',
            'description' => 'Une seule et meme phrase',
        ]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);
        $viewer = User::factory()->create(['organization_id' => $org->id]);

        $html = $this->actingAs($viewer)->get(route('loops.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Une seule et meme phrase'));
    }

    public function test_card_falls_back_gracefully_without_tagline_or_description(): void
    {
        $org = $this->org();
        Loop::factory()->create(['organization_id' => $org->id, 'tagline' => null, 'description' => null]);
        $this->padCatalog($org);
        $this->withCurrentOrganization($org);
        $viewer = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($viewer)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertSee(__('loops.card_no_pitch'));
    }

    public function test_member_card_shows_the_role_badge(): void
    {
        $org = $this->org();
        $ownedLoop = Loop::factory()->create(['organization_id' => $org->id]);
        $joinedLoop = Loop::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $ownedLoop->id, 'user_id' => $user->id]);
        LoopMember::factory()->create(['loop_id' => $joinedLoop->id, 'user_id' => $user->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertSee(__('loops.role_owner'))
            ->assertSee(__('loops.role_member'));
    }

    public function test_non_member_card_shows_no_role_badge(): void
    {
        $org = $this->org();
        Loop::factory()->count(2)->create(['organization_id' => $org->id]);
        $stranger = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($stranger)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertDontSee(__('loops.role_owner'))
            ->assertDontSee(__('loops.role_member'));
    }
}
