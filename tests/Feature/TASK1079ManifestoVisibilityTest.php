<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopManifestoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exposure of the Manifesto on a Loop presentation page.
 *
 * The rule these tests defend: the Loop's confidentiality always wins. A
 * Manifesto may hold hypotheses, sensitive material or in-flight project
 * information, so it is surfaced only when it is *both* explicitly published
 * *and* on a Loop that is not private — and never as a stand-in for a missing
 * description.
 */
class TASK1079ManifestoVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'HYPOTHESE-CONFIDENTIELLE-1079';

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        app()->instance('current_organization', $this->org);
    }

    private function loopWithManifesto(string $visibility, string $status): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'visibility' => $visibility,
            'description' => 'Description publique de la Boucle.',
        ]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $this->owner->id]);

        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Manifeste',
            'slug' => 'manifeste-'.uniqid(),
            'content' => self::SECRET.' — notre raison d\'être.',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
        $loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();

        return $loop->fresh();
    }

    private function outsider(): User
    {
        // In the Organization but not a member of the Loop: this is who the
        // presentation page is for.
        return User::factory()->create(['organization_id' => $this->org->id]);
    }

    public function test_public_loop_with_a_published_manifesto_shows_it(): void
    {
        $loop = $this->loopWithManifesto('public', 'published');

        $this->actingAs($this->outsider())
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee(__('loops.manifesto_public_title'))
            ->assertSee(self::SECRET);
    }

    public function test_public_loop_with_a_draft_manifesto_shows_nothing(): void
    {
        $loop = $this->loopWithManifesto('public', 'draft');

        $response = $this->actingAs($this->outsider())->get(route('loops.show', $loop))->assertOk();

        $response->assertDontSee(self::SECRET);
        $response->assertDontSee(__('loops.manifesto_public_title'));
    }

    public function test_private_loop_never_exposes_its_manifesto_to_a_non_member(): void
    {
        $loop = $this->loopWithManifesto('private', 'published');

        $response = $this->actingAs($this->outsider())->get(route('loops.show', $loop))->assertOk();

        // Published, yet private: confidentiality wins.
        $response->assertDontSee(self::SECRET);
        $response->assertDontSee(__('loops.manifesto_public_title'));
    }

    public function test_nothing_private_leaks_into_the_dom_of_a_private_loop(): void
    {
        $loop = $this->loopWithManifesto('private', 'published');

        $html = $this->actingAs($this->outsider())->get(route('loops.show', $loop))->getContent();

        // Not as markup, not in a meta tag, not in an attribute — nowhere.
        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringNotContainsString($loop->manifesto->slug, $html);
    }

    public function test_a_member_of_a_private_loop_can_read_the_manifesto(): void
    {
        $loop = $this->loopWithManifesto('private', 'published');
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id, 'status' => 'active']);

        // Members get the workspace, where the Manifesto card applies the
        // internal permissions rather than the public rule — and the article
        // itself stays readable to them.
        $this->actingAs($member)->get(route('loops.show', $loop))->assertOk();
        $this->actingAs($member)->get(route('blog.show', $loop->manifesto->slug))->assertOk()->assertSee(self::SECRET);
    }

    public function test_the_manifesto_is_never_a_fallback_for_a_missing_description(): void
    {
        $loop = $this->loopWithManifesto('public', 'draft');
        $loop->update(['description' => null, 'tagline' => null]);

        $this->actingAs($this->outsider())
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertDontSee(self::SECRET);
    }

    public function test_a_forged_direct_access_to_a_private_manifesto_is_refused(): void
    {
        $loop = $this->loopWithManifesto('private', 'published');
        $slug = $loop->manifesto->slug;

        // Reaching the article directly must not be a way around the Loop's
        // confidentiality; whatever the Blog policy answers, it must not be 200
        // with the secret in it for an outsider.
        $response = $this->actingAs($this->outsider())->get(route('blog.show', $slug));

        if ($response->status() === 200) {
            $response->assertDontSee(self::SECRET);
        } else {
            $this->assertContains($response->status(), [403, 404]);
        }
    }

    public function test_a_user_of_another_organization_gets_nothing(): void
    {
        $loop = $this->loopWithManifesto('public', 'published');
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id]);

        $response = $this->actingAs($foreigner)->get(route('loops.show', $loop));

        $this->assertContains($response->status(), [403, 404]);
        $response->assertDontSee(self::SECRET);
    }

    public function test_unpublishing_removes_it_from_the_presentation_at_once(): void
    {
        $loop = $this->loopWithManifesto('public', 'published');

        $this->actingAs($this->outsider())->get(route('loops.show', $loop))->assertSee(self::SECRET);

        app(LoopManifestoService::class)->unpublish($loop->fresh());

        $this->actingAs($this->outsider())->get(route('loops.show', $loop))->assertDontSee(self::SECRET);
    }
}
