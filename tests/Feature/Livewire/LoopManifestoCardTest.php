<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopManifestoCard;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoopManifestoCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $moderator;

    private User $nonMember;

    private User $crossUser;

    private Loop $loop;

    private Loop $otherLoop;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->moderator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $this->service = new LoopService;

        // A Projets Loop. Since TASK-1332 the Manifesto is no longer imposed
        // by any type's default preset — enabled explicitly here so
        // `manifesto.*` permissions (gated on the card being active,
        // `LoopPermissionResolver::cardIsAvailable()`) actually apply.
        $this->loop = $this->service->createLoop($this->owner, 'Manifesto Loop', type: 'project');
        app(LoopCardCompositionService::class)->enable($this->loop, 'core.manifesto');
        $this->service->addMember($this->loop, $this->member, 'member');
        $this->service->addMember($this->loop, $this->moderator, 'moderator');

        $this->otherLoop = $this->service->createLoop($this->crossUser, 'Other Loop', type: 'project');
        app(LoopCardCompositionService::class)->enable($this->otherLoop, 'core.manifesto');
    }

    private function linkedPost(string $title = 'Doc'): BlogPost
    {
        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'title' => $title.' '.uniqid(),
            'content' => 'Body',
            'status' => 'draft',
        ]);
        $post->loops()->attach($this->loop->id);

        return $post;
    }

    public function test_every_loop_has_a_root_document_so_the_empty_state_is_gone(): void
    {
        // Since TASK-1082 a Loop is created with its root document. The
        // "no Manifesto yet" state this test used to assert is no longer
        // reachable through the product — it survives only as a resilience
        // guard for historically broken data.
        $this->actingAs($this->member);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertDontSee(__('loops.manifesto_pitch'));

        $this->assertNotNull(
            Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'),
        );
    }

    public function test_a_legacy_moderator_designates_as_a_facilitator(): void
    {
        $post = $this->linkedPost('Manifeste');
        $this->actingAs($this->moderator);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        // Third state of this assertion, and the reasoning is worth recording.
        // Originally a moderator could designate. CP5bis narrowed editing to
        // owners because "moderation" was too wide a justification for touching
        // the founding text. CP5ter introduces `facilitator` as the canonical
        // role for exactly that responsibility, and `moderator` is now a legacy
        // alias of it — so this capability returns, but named correctly and
        // resolved centrally. Publishing remains a separate permission the
        // facilitator does not hold by default.
        $this->assertSame($post->id, $this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_regular_member_cannot_designate(): void
    {
        // Every Loop now has a root document from creation, so "no designation"
        // is no longer the shape of a refusal. What a refusal means today is
        // that the root document is **unchanged**.
        $before = Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id');
        $post = $this->linkedPost();
        $this->actingAs($this->member);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        $this->assertSame($before, Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'));
    }

    public function test_cannot_designate_article_of_another_organization(): void
    {
        // Every Loop now has a root document from creation, so "no designation"
        // is no longer the shape of a refusal. What a refusal means today is
        // that the root document is **unchanged**.
        $before = Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id');
        $foreign = BlogPost::create([
            'user_id' => $this->crossUser->id,
            'organization_id' => $this->otherOrganization->id,
            'title' => 'Foreign '.uniqid(),
            'content' => 'x',
            'status' => 'draft',
        ]);
        $foreign->loops()->attach($this->otherLoop->id);

        $this->actingAs($this->owner);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $foreign->id)
            ->assertSet('errorMessage', __('loops.manifesto_invalid_article'));

        $this->assertSame($before, Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'));
    }

    public function test_an_article_of_the_organization_can_now_become_the_root_document(): void
    {
        // Reversed on purpose. The picker used to offer only articles already
        // attached to the Loop, which made it useless: you could only choose
        // something you had already linked. The scope is now the Organization.
        $unlinked = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'title' => 'Unlinked '.uniqid(),
            'content' => 'x',
            'status' => 'draft',
        ]);

        $this->actingAs($this->owner);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $unlinked->id)
            ->assertSet('errorMessage', null);

        $this->assertSame($unlinked->id, Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'));
        // Moved into the root Dossier, out of the Blog listing.
        $this->assertFalse((bool) $unlinked->fresh()->listed_in_blog);
        $this->assertSame('loop', $unlinked->fresh()->audience);

        // The legacy column is kept in step while it survives, never diverging.
        $this->assertSame($unlinked->id, $this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_replacing_keeps_the_previous_document_and_never_empties_the_card(): void
    {
        // "Remove" is gone: a Loop always has a root document. Replacement is
        // the only path, and the former document stays in the root Dossier as
        // an ordinary article — never deleted, never detached.
        $former = Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id');
        $this->assertNotNull($former);

        $replacement = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'title' => 'Remplacant '.uniqid(),
            'content' => 'x',
            'status' => 'draft',
        ]);

        $this->actingAs($this->owner);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $replacement->id);

        $this->assertSame($replacement->id, Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'));
        $this->assertDatabaseHas('blog_posts', ['id' => $former]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $former]);
    }

    public function test_the_root_document_already_exists_and_opening_it_redirects(): void
    {
        // The document is created with the Loop now, so this action opens the
        // existing one rather than creating a draft. It is published from the
        // start: a Loop's reference text needs no second "Publish" step.
        $this->actingAs($this->owner);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('createManifesto')
            ->assertRedirect();

        $this->loop->refresh();
        $post = BlogPost::find(Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'));

        $this->assertNotNull($post);
        $this->assertSame('published', $post->status);
        $this->assertSame('loop', $post->audience);
        $this->assertFalse((bool) $post->listed_in_blog);
        $this->assertSame($this->organization->id, $post->organization_id);
    }

    public function test_non_member_cannot_manage(): void
    {
        // Every Loop now has a root document from creation, so "no designation"
        // is no longer the shape of a refusal. What a refusal means today is
        // that the root document is **unchanged**.
        $before = Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id');
        $post = $this->linkedPost();
        $this->actingAs($this->nonMember);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        $this->assertSame($before, Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'));
    }

    public function test_deactivated_member_cannot_manage(): void
    {
        // Every Loop now has a root document from creation, so "no designation"
        // is no longer the shape of a refusal. What a refusal means today is
        // that the root document is **unchanged**.
        $before = Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id');
        $this->moderator->update(['banned_at' => now()]);
        $post = $this->linkedPost();
        $this->actingAs($this->moderator);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        $this->assertSame($before, Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'));
    }

    public function test_a_soft_deleted_root_document_degrades_without_breaking(): void
    {
        // Resilience guard: the only way to reach an empty card today is a
        // broken historical row. It must degrade, never raise.
        $dossier = Dossier::where('loop_id', $this->loop->id)->first();
        BlogPost::find($dossier->root_blog_post_id)?->delete();

        $this->actingAs($this->member);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertOk();
    }
}
