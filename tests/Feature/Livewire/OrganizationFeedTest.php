<?php

namespace Tests\Feature\Livewire;

use App\Livewire\CreateFeedPost;
use App\Livewire\EditFeedPost;
use App\Livewire\MyFeedPosts;
use App\Livewire\OrganizationFeed;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Tests\TestCase;

class OrganizationFeedTest extends TestCase
{
    private Organization $organization;

    private Organization $otherOrganization;

    private User $admin;

    private User $member;

    private User $otherMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherMember = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->organization->update(['admin_id' => $this->admin->id]);

        app()->instance('current_organization', $this->organization);
    }

    public function test_admin_can_create_announcement_with_organization_id(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('title', 'Annonce importante')
            ->set('content', 'Réunion demain matin')
            ->call('submit')
            ->assertRedirect(route('organization.flux', ['organization' => $this->organization->slug]));

        $this->assertDatabaseHas('feed_posts', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_PUBLISHED,
            'title' => 'Annonce importante',
        ]);
    }

    public function test_feed_posts_table_has_media_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('feed_posts', 'image_path'));
        $this->assertTrue(Schema::hasColumn('feed_posts', 'url_preview'));
    }

    public function test_admin_can_publish_with_image(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Annonce illustrée')
            ->set('image', UploadedFile::fake()->image('annonce.jpg', 900, 500))
            ->call('submit');

        $post = FeedPost::firstOrFail();

        $this->assertNotNull($post->image_path);
        Storage::disk('public')->assertExists($post->image_path);
    }

    public function test_admin_can_publish_with_url_preview(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html><head><title>Example title</title><meta property="og:description" content="Example description"></head></html>'),
        ]);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'À lire https://example.com/article')
            ->call('submit');

        $post = FeedPost::firstOrFail();

        $this->assertSame('Example title', $post->url_preview['title']);
        $this->assertSame('example.com', $post->url_preview['domain']);
    }

    public function test_admin_can_broadcast_to_specific_loop(): void
    {
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Diffusion ciblée')
            ->set('selectedLoops', [$loop->id])
            ->call('submit');

        $post = FeedPost::firstOrFail();

        $this->assertDatabaseHas('feed_post_loop', [
            'feed_post_id' => $post->id,
            'loop_id' => $loop->id,
        ]);
        $message = LoopMessage::where('loop_id', $loop->id)
            ->where('sender_id', $this->admin->id)
            ->firstOrFail();

        $this->assertSame($this->organization->id, $message->organization_id);
        $this->assertStringContainsString('Diffusion ciblée', $message->body);
        $this->assertStringContainsString('[Voir l\'annonce]', $message->body);
        $this->assertStringContainsString($post->announcementUrl(), $message->body);

        if (Schema::hasColumn('feed_post_loop', 'id')) {
            $this->assertNotNull(
                DB::table('feed_post_loop')
                    ->where('feed_post_id', $post->id)
                    ->where('loop_id', $loop->id)
                    ->value('id'),
            );
        }
    }

    public function test_loop_diffusion_names_are_visible_and_scoped(): void
    {
        $loop = $this->loopForOrganization($this->organization);
        $loop->update([
            'name' => 'Boucle entraide locale',
            'slug' => 'entraide-locale',
            'description' => 'Coordination courte entre membres actifs.',
        ]);

        $otherLoop = $this->loopForOrganization($this->otherOrganization, $this->otherMember);
        $otherLoop->update(['name' => 'Boucle autre organisation']);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->assertSee('Boucle entraide locale')
            ->assertSee('entraide-locale')
            ->assertSee('Coordination courte entre membres actifs.')
            ->assertDontSee('Boucle autre organisation');
    }

    public function test_admin_can_broadcast_to_all_organization_loops(): void
    {
        $firstLoop = $this->loopForOrganization($this->organization);
        $secondLoop = $this->loopForOrganization($this->organization);
        $otherLoop = $this->loopForOrganization($this->otherOrganization, $this->otherMember);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Diffusion générale')
            ->set('allLoops', true)
            ->call('submit');

        $post = FeedPost::where('content', 'Diffusion générale')->firstOrFail();

        $this->assertDatabaseHas('loop_messages', ['loop_id' => $firstLoop->id]);
        $this->assertDatabaseHas('loop_messages', ['loop_id' => $secondLoop->id]);
        $this->assertDatabaseMissing('loop_messages', ['loop_id' => $otherLoop->id]);

        foreach ([$firstLoop, $secondLoop] as $loop) {
            $message = LoopMessage::where('loop_id', $loop->id)->firstOrFail();
            $this->assertStringContainsString('Diffusion générale', $message->body);
            $this->assertStringContainsString($post->announcementUrl(), $message->body);
        }
    }

    public function test_cross_tenant_loop_broadcast_is_ignored(): void
    {
        $otherLoop = $this->loopForOrganization($this->otherOrganization, $this->otherMember);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Tentative cross tenant')
            ->set('selectedLoops', [$otherLoop->id])
            ->call('submit');

        $post = FeedPost::firstOrFail();

        $this->assertDatabaseMissing('feed_post_loop', [
            'feed_post_id' => $post->id,
            'loop_id' => $otherLoop->id,
        ]);
        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $otherLoop->id,
            'body' => 'Tentative cross tenant',
        ]);
    }

    public function test_member_sees_only_organization_announcements(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce visible',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);
        FeedPost::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherMember->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce cachée',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertSee('Annonce visible')
            ->assertDontSee('Annonce cachée');
    }

    public function test_member_can_comment_visible_announcement(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->set('commentForms.'.$post->id, 'Commentaire utile')
            ->call('addComment', $post->id)
            ->assertSet('commentForms.'.$post->id, '');

        $this->assertDatabaseHas('feed_post_comments', [
            'feed_post_id' => $post->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'content' => 'Commentaire utile',
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertSee('Commentaire utile');
    }

    public function test_member_cannot_comment_other_organization_announcement(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherMember->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce autre org',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->set('commentForms.'.$post->id, 'Cross tenant')
            ->call('addComment', $post->id);

        $this->assertDatabaseMissing('feed_post_comments', [
            'feed_post_id' => $post->id,
            'content' => 'Cross tenant',
        ]);
    }

    public function test_non_authorized_user_cannot_publish(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.flux.create', ['organization' => $this->organization->slug]))
            ->assertForbidden();
    }

    public function test_member_can_publish_when_organization_allows_members(): void
    {
        $this->organization->update(['feed_post_publish_mode' => 'members']);

        Livewire::actingAs($this->member)
            ->test(CreateFeedPost::class)
            ->set('title', 'Annonce membre')
            ->set('content', 'Publication ouverte aux membres')
            ->call('submit')
            ->assertRedirect(route('organization.flux', ['organization' => $this->organization->slug]));

        $this->assertDatabaseHas('feed_posts', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'title' => 'Annonce membre',
        ]);
    }

    public function test_organization_flux_route_works(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.flux', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertSee('Flux');
    }

    public function test_my_feed_posts_page_is_user_and_organization_scoped(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'title' => 'Mon annonce visible',
            'content' => 'Contenu visible',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'title' => 'Annonce autre utilisateur',
            'content' => 'Contenu même organisation',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);
        FeedPost::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->member->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'title' => 'Annonce autre organisation',
            'content' => 'Contenu cross tenant',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);

        Livewire::actingAs($this->member)
            ->test(MyFeedPosts::class)
            ->assertSee('Mon annonce visible')
            ->assertDontSee('Annonce autre utilisateur')
            ->assertDontSee('Annonce autre organisation');
    }

    public function test_my_feed_posts_route_works(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.flux.my', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertSee('Mes annonces');
    }

    public function test_root_flux_route_works_for_default_organization(): void
    {
        $this->organization->update(['is_default' => true]);
        app()->forgetInstance('current_organization');

        $this->actingAs($this->member)
            ->get('/flux')
            ->assertOk()
            ->assertSee('Flux');
    }

    public function test_root_flux_create_route_works_for_default_organization_admin(): void
    {
        $this->organization->update(['is_default' => true]);
        app()->forgetInstance('current_organization');

        $this->actingAs($this->admin)
            ->get('/flux/creer')
            ->assertOk()
            ->assertSee('Nouvelle annonce');
    }

    public function test_root_my_feed_posts_route_works_for_default_organization(): void
    {
        $this->organization->update(['is_default' => true]);
        app()->forgetInstance('current_organization');

        $this->actingAs($this->member)
            ->get('/flux/mes-annonces')
            ->assertOk()
            ->assertSee('Mes annonces');
    }

    public function test_org_main_flux_create_route_remains_compatible(): void
    {
        $this->organization->update(['is_default' => true, 'slug' => 'main']);
        app()->forgetInstance('current_organization');

        $this->actingAs($this->admin)
            ->get(route('organization.flux.create', ['organization' => 'main']))
            ->assertOk()
            ->assertSee('Nouvelle annonce');
    }

    public function test_other_organization_create_route_forbidden_for_foreign_admin(): void
    {
        app()->forgetInstance('current_organization');

        $this->actingAs($this->admin)
            ->get(route('organization.flux.create', ['organization' => $this->otherOrganization->slug]))
            ->assertForbidden();
    }

    public function test_other_organization_create_route_allowed_for_its_admin(): void
    {
        $otherAdmin = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'is_admin' => true,
        ]);
        $this->otherOrganization->update(['admin_id' => $otherAdmin->id]);
        app()->forgetInstance('current_organization');

        $this->actingAs($otherAdmin)
            ->get(route('organization.flux.create', ['organization' => $this->otherOrganization->slug]))
            ->assertOk()
            ->assertSee('Nouvelle annonce');
    }

    public function test_navigation_contains_flux_links(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertSee('Flux')
            ->assertSee(route('organization.flux', ['organization' => $this->organization->slug]), false);
    }

    public function test_member_can_add_replace_and_remove_reaction(): void
    {
        $post = $this->feedPost();

        $component = Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class);

        $component->call('toggleReaction', $post->id, 'thumbs_up');
        $this->assertDatabaseHas('reactions', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'reactionable_id' => $post->id,
            'reactionable_type' => FeedPost::class,
            'reaction_type' => 'thumbs_up',
        ]);

        $component->call('toggleReaction', $post->id, 'heart');
        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $post->id,
            'reaction_type' => 'heart',
        ]);
        $this->assertDatabaseMissing('reactions', [
            'reactionable_id' => $post->id,
            'reaction_type' => 'thumbs_up',
        ]);

        $component->call('toggleReaction', $post->id, 'heart');
        $this->assertDatabaseMissing('reactions', [
            'reactionable_id' => $post->id,
            'reaction_type' => 'heart',
        ]);
    }

    public function test_reaction_cross_tenant_is_refused(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherMember->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Autre org',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->call('toggleReaction', $post->id, 'thumbs_up');

        $this->assertDatabaseMissing('reactions', [
            'reactionable_id' => $post->id,
            'reactionable_type' => FeedPost::class,
        ]);
    }

    public function test_owner_can_update_post(): void
    {
        $post = $this->feedPost();
        $this->assertTrue($this->admin->can('update', $post));
    }

    public function test_non_owner_cannot_update_post(): void
    {
        $post = $this->feedPost();
        $this->assertFalse($this->member->can('update', $post));
    }

    public function test_admin_can_update_any_post(): void
    {
        $post = $this->feedPost($this->member);
        $this->assertTrue($this->admin->can('update', $post));
    }

    public function test_owner_can_delete_post(): void
    {
        $post = $this->feedPost();
        $this->assertTrue($this->admin->can('delete', $post));
    }

    public function test_non_owner_cannot_delete_post(): void
    {
        $post = $this->feedPost();
        $this->assertFalse($this->member->can('delete', $post));
    }

    public function test_admin_can_delete_any_post(): void
    {
        $post = $this->feedPost($this->member);
        $this->assertTrue($this->admin->can('delete', $post));
    }

    public function test_cross_org_update_and_delete_denied(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherMember->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Cross org post',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);
        $this->assertFalse($this->admin->can('update', $post));
        $this->assertFalse($this->admin->can('delete', $post));
    }

    public function test_owner_can_edit_post_via_component(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->admin)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->assertSet('title', '')
            ->assertSet('content', 'Annonce test')
            ->set('title', 'Titre modifié')
            ->set('content', 'Contenu modifié')
            ->call('submit')
            ->assertRedirect(route('organization.flux.my', ['organization' => $this->organization->slug]));

        $this->assertDatabaseHas('feed_posts', [
            'id' => $post->id,
            'title' => 'Titre modifié',
            'content' => 'Contenu modifié',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_edit_post_preserves_organization_id(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->admin)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->set('content', 'Nouveau contenu')
            ->call('submit');

        $this->assertDatabaseHas('feed_posts', [
            'id' => $post->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_edit_post_draft_mode_sets_status(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->admin)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->set('mode', 'draft')
            ->call('submit')
            ->assertRedirect();

        $this->assertDatabaseHas('feed_posts', [
            'id' => $post->id,
            'status' => FeedPost::STATUS_DRAFT,
        ]);
        $this->assertNull($post->fresh()->published_at);
        $this->assertNull($post->fresh()->scheduled_at);
    }

    public function test_edit_post_schedule_requires_future_date(): void
    {
        $post = $this->feedPost();
        $pastDate = Carbon::now('Europe/Paris')->subHour()->format('Y-m-d\TH:i');

        Livewire::actingAs($this->admin)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->set('mode', 'schedule')
            ->set('scheduledAt', $pastDate)
            ->call('submit')
            ->assertHasErrors('scheduledAt');
    }

    public function test_edit_post_schedule_accepts_future_date(): void
    {
        $post = $this->feedPost();
        $futureDate = Carbon::now('Europe/Paris')->addHours(2)->format('Y-m-d\TH:i');

        Livewire::actingAs($this->admin)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->set('mode', 'schedule')
            ->set('scheduledAt', $futureDate)
            ->call('submit')
            ->assertRedirect();

        $this->assertDatabaseHas('feed_posts', [
            'id' => $post->id,
            'status' => FeedPost::STATUS_SCHEDULED,
        ]);
    }

    public function test_non_owner_cannot_access_edit_form(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->member)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->assertForbidden();
    }

    public function test_cross_tenant_edit_post_denied(): void
    {
        $post = $this->feedPost();

        app()->instance('current_organization', $this->otherOrganization);

        Livewire::actingAs($this->otherMember)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->assertForbidden();
    }

    public function test_edit_does_not_create_loop_messages(): void
    {
        $post = $this->feedPost();
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->set('content', 'Contenu modifié sans broadcast')
            ->call('submit')
            ->assertRedirect();

        $this->assertDatabaseMissing('loop_messages', [
            'body' => 'Contenu modifié sans broadcast',
        ]);
    }

    public function test_owner_can_delete_post_via_component(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->call('delete', $post->id);

        $this->assertSoftDeleted('feed_posts', [
            'id' => $post->id,
        ]);
    }

    public function test_deleted_post_not_visible_in_feed(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->call('delete', $post->id);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertDontSee('Annonce test');
    }

    public function test_deleted_post_not_visible_in_my_feed_posts(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->call('delete', $post->id);

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->assertDontSee('Annonce test');
    }

    public function test_non_owner_cannot_delete_post_via_component(): void
    {
        $post = $this->feedPost();

        Livewire::actingAs($this->member)
            ->test(MyFeedPosts::class)
            ->call('delete', $post->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted('feed_posts', [
            'id' => $post->id,
        ]);
    }

    public function test_cross_tenant_delete_post_denied(): void
    {
        $post = $this->feedPost();

        app()->instance('current_organization', $this->otherOrganization);

        Livewire::actingAs($this->otherMember)
            ->test(MyFeedPosts::class)
            ->call('delete', $post->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted('feed_posts', [
            'id' => $post->id,
        ]);
    }

    private function feedPost(?User $user = null): FeedPost
    {
        $user ??= $this->admin;

        return FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce test',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);
    }

    public function test_member_cannot_pin_announcement(): void
    {
        $this->organization->update(['feed_post_publish_mode' => 'members']);

        Livewire::actingAs($this->member)
            ->test(CreateFeedPost::class)
            ->set('content', 'Tentative de pin')
            ->set('pin', true)
            ->call('submit')
            ->assertForbidden();
    }

    public function test_published_post_visible_in_feed(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce publiée visible',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertSee('Annonce publiée visible');
    }

    public function test_published_post_without_published_at_still_visible(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Legacy published sans published_at',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => null,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertSee('Legacy published sans published_at');
    }

    public function test_draft_post_not_visible_in_feed(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Brouillon caché',
            'status' => FeedPost::STATUS_DRAFT,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertDontSee('Brouillon caché');
    }

    public function test_scheduled_future_post_not_visible_in_feed(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Programmée future cachée',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        $this->assertDatabaseHas('feed_posts', [
            'content' => 'Programmée future cachée',
            'status' => FeedPost::STATUS_SCHEDULED,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertDontSee('Programmée future cachée');
    }

    public function test_scheduled_past_post_found_by_due_for_publication_scope(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Programmée passée due',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->subHour(),
        ]);

        $duePosts = FeedPost::dueForPublication()->get();

        $this->assertCount(1, $duePosts);
        $this->assertEquals('Programmée passée due', $duePosts->first()->content);
    }

    public function test_lifecycle_columns_store_and_cast_correctly(): void
    {
        $scheduledAt = now()->addDays(3);
        $publishedAt = now();

        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Test stockage lifecycle',
            'status' => FeedPost::STATUS_PUBLISHED,
            'scheduled_at' => $scheduledAt,
            'published_at' => $publishedAt,
            'loop_message' => 'Message personnalisé pour la boucle',
        ]);

        $this->assertInstanceOf(Carbon::class, $post->scheduled_at);
        $this->assertInstanceOf(Carbon::class, $post->published_at);
        $this->assertEquals($scheduledAt->toDateTimeString(), $post->scheduled_at->toDateTimeString());
        $this->assertEquals($publishedAt->toDateTimeString(), $post->published_at->toDateTimeString());
        $this->assertEquals('Message personnalisé pour la boucle', $post->loop_message);
    }

    public function test_cross_tenant_post_still_invisible(): void
    {
        FeedPost::create([
            'organization_id' => $this->otherOrganization->id,
            'user_id' => $this->otherMember->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Cross tenant caché',
            'status' => FeedPost::STATUS_PUBLISHED,
        ]);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertDontSee('Cross tenant caché');
    }

    public function test_create_post_draft_sets_correct_status(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('title', 'Draft title')
            ->set('content', 'Draft content')
            ->set('mode', 'draft')
            ->call('submit')
            ->assertRedirect();

        $post = FeedPost::where('title', 'Draft title')->firstOrFail();

        $this->assertSame(FeedPost::STATUS_DRAFT, $post->status);
        $this->assertNull($post->published_at);
        $this->assertNull($post->scheduled_at);
    }

    public function test_create_post_scheduled_sets_correct_status(): void
    {
        $futureDate = Carbon::now('Europe/Paris')->addHours(2)->format('Y-m-d\TH:i');

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Scheduled content')
            ->set('mode', 'schedule')
            ->set('scheduledAt', $futureDate)
            ->call('submit')
            ->assertRedirect();

        $post = FeedPost::where('content', 'Scheduled content')->firstOrFail();

        $this->assertSame(FeedPost::STATUS_SCHEDULED, $post->status);
        $this->assertNull($post->published_at);
        $this->assertNotNull($post->scheduled_at);
    }

    public function test_create_post_scheduled_rejects_past_date(): void
    {
        $pastDate = Carbon::now('Europe/Paris')->subHour()->format('Y-m-d\TH:i');

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Past scheduled content')
            ->set('mode', 'schedule')
            ->set('scheduledAt', $pastDate)
            ->call('submit')
            ->assertHasErrors('scheduledAt');
    }

    public function test_loop_message_stored_via_form(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Post with loop message')
            ->set('loopMessage', 'Message personnalisé pour la boucle')
            ->call('submit')
            ->assertRedirect();

        $this->assertDatabaseHas('feed_posts', [
            'content' => 'Post with loop message',
            'loop_message' => 'Message personnalisé pour la boucle',
        ]);
    }

    public function test_draft_does_not_broadcast_to_loops(): void
    {
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Draft sans broadcast')
            ->set('mode', 'draft')
            ->set('selectedLoops', [$loop->id])
            ->call('submit')
            ->assertRedirect();

        $post = FeedPost::where('content', 'Draft sans broadcast')->firstOrFail();

        $this->assertDatabaseHas('feed_post_loop', [
            'feed_post_id' => $post->id,
        ]);
        $this->assertDatabaseMissing('loop_messages', [
            'body' => 'Draft sans broadcast',
        ]);
    }

    public function test_scheduled_does_not_broadcast_to_loops(): void
    {
        $futureDate = Carbon::now('Europe/Paris')->addHours(3)->format('Y-m-d\TH:i');
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Scheduled sans broadcast')
            ->set('mode', 'schedule')
            ->set('scheduledAt', $futureDate)
            ->set('selectedLoops', [$loop->id])
            ->call('submit')
            ->assertRedirect();

        $post = FeedPost::where('content', 'Scheduled sans broadcast')->firstOrFail();

        $this->assertDatabaseHas('feed_post_loop', [
            'feed_post_id' => $post->id,
        ]);
        $this->assertDatabaseMissing('loop_messages', [
            'body' => 'Scheduled sans broadcast',
        ]);
    }

    public function test_loop_message_field_used_as_loop_body_when_set(): void
    {
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Contenu standard de l\'annonce')
            ->set('loopMessage', 'Message personnalisé pour les boucles')
            ->set('selectedLoops', [$loop->id])
            ->call('submit');

        $post = FeedPost::where('content', 'Contenu standard de l\'annonce')->firstOrFail();

        $message = LoopMessage::where('loop_id', $loop->id)->firstOrFail();

        $this->assertStringContainsString('Message personnalisé pour les boucles', $message->body);
        $this->assertStringNotContainsString('Contenu standard de l\'annonce', $message->body);
        $this->assertStringContainsString('[Voir l\'annonce]', $message->body);
        $this->assertStringContainsString($post->announcementUrl(), $message->body);
    }

    public function test_loop_message_falls_back_to_title_and_content_when_loop_message_empty(): void
    {
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('title', 'Titre de l\'annonce')
            ->set('content', 'Contenu de l\'annonce')
            ->set('selectedLoops', [$loop->id])
            ->call('submit');

        $post = FeedPost::where('content', 'Contenu de l\'annonce')->firstOrFail();

        $message = LoopMessage::where('loop_id', $loop->id)->firstOrFail();

        $this->assertStringContainsString('Titre de l\'annonce', $message->body);
        $this->assertStringContainsString('Contenu de l\'annonce', $message->body);
        $this->assertStringContainsString('[Voir l\'annonce]', $message->body);
        $this->assertStringContainsString($post->announcementUrl(), $message->body);
    }

    public function test_pinned_announcement_broadcasts_pinned_loop_message(): void
    {
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Annonce épinglée')
            ->set('pin', true)
            ->set('selectedLoops', [$loop->id])
            ->call('submit');

        $post = FeedPost::where('content', 'Annonce épinglée')->firstOrFail();

        $this->assertTrue($post->isPinned());

        $message = LoopMessage::where('loop_id', $loop->id)->firstOrFail();
        $this->assertNotNull($message->pinned_at);
        $this->assertSame($this->admin->id, $message->pinned_by_id);
    }

    public function test_non_pinned_announcement_broadcasts_unpinned_loop_message(): void
    {
        $loop = $this->loopForOrganization($this->organization);

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Annonce non épinglée')
            ->set('pin', false)
            ->set('selectedLoops', [$loop->id])
            ->call('submit');

        $message = LoopMessage::where('loop_id', $loop->id)->firstOrFail();
        $this->assertNull($message->pinned_at);
        $this->assertNull($message->pinned_by_id);
    }

    public function test_feed_post_card_has_anchor_id(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_PUBLISHED,
            'content' => 'Annonce avec ancre',
        ]);

        $this->actingAs($this->admin);

        $response = $this->get(route('flux'));
        $response->assertOk();
        $response->assertSee('id="feed-post-'.$post->id.'"', false);
    }

    public function test_create_scheduled_paris_time_stored_as_utc(): void
    {
        $parisFuture = Carbon::now('Europe/Paris')->addHours(3);
        $input = $parisFuture->format('Y-m-d\TH:i');

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Timezone conversion test')
            ->set('mode', 'schedule')
            ->set('scheduledAt', $input)
            ->call('submit')
            ->assertRedirect();

        $post = FeedPost::where('content', 'Timezone conversion test')->firstOrFail();

        $expectedUtc = $parisFuture->utc()->format('Y-m-d\TH:i');
        $this->assertSame(
            $expectedUtc,
            $post->scheduled_at?->format('Y-m-d\TH:i'),
            "Expected {$expectedUtc} UTC, got {$post->scheduled_at?->format('Y-m-d\TH:i')}"
        );
    }

    public function test_edit_displays_scheduled_at_in_paris_timezone(): void
    {
        $utcTime = Carbon::now('UTC')->addDays(2);
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Timezone prefill test',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => $utcTime,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditFeedPost::class, ['feedPost' => $post])
            ->assertSet('scheduledAt', $utcTime->setTimezone('Europe/Paris')->format('Y-m-d\TH:i'));
    }

    public function test_scheduled_paris_timezone_publishes_via_command(): void
    {
        $parisFuture = Carbon::now('Europe/Paris')->addHours(2);
        $input = $parisFuture->format('Y-m-d\TH:i');

        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->set('content', 'Timezone end-to-end publication')
            ->set('mode', 'schedule')
            ->set('scheduledAt', $input)
            ->call('submit')
            ->assertRedirect();

        $post = FeedPost::where('content', 'Timezone end-to-end publication')->firstOrFail();
        $publicationMoment = $parisFuture->utc()->addMinute()->startOfMinute();

        $this->travelTo($publicationMoment);

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(CommandAlias::SUCCESS);

        $this->assertEquals(FeedPost::STATUS_PUBLISHED, $post->fresh()->status);
        $this->assertNotNull($post->fresh()->published_at);
    }

    public function test_authorized_user_sees_my_posts_link_on_feed(): void
    {
        Livewire::actingAs($this->admin)
            ->test(OrganizationFeed::class)
            ->assertSee('Mes annonces');
    }

    public function test_unauthorized_user_does_not_see_my_posts_link_on_feed(): void
    {
        $this->organization->update(['feed_post_publish_mode' => 'admin']);

        Livewire::actingAs($this->member)
            ->test(OrganizationFeed::class)
            ->assertDontSee('Mes annonces');
    }

    public function test_my_posts_link_visible_on_create_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateFeedPost::class)
            ->assertSee('Mes annonces');
    }

    public function test_scheduled_post_shows_publish_now_button(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce planifiée à publier',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->assertSee('Publier maintenant');
    }

    public function test_published_post_hides_publish_now_button(): void
    {
        $post = $this->feedPost();

        FeedPost::where('id', $post->id)->update(['status' => FeedPost::STATUS_PUBLISHED]);

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->assertDontSee('Publier maintenant');
    }

    public function test_draft_post_hides_publish_now_button(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Brouillon sans bouton',
            'status' => FeedPost::STATUS_DRAFT,
        ]);

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->assertDontSee('Publier maintenant');
    }

    public function test_publish_now_changes_status_to_published(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Publier maintenant test',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->call('publishNow', $post->id);

        $post->refresh();

        $this->assertSame(FeedPost::STATUS_PUBLISHED, $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_publish_now_clears_scheduled_at(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Publier maintenant clear scheduled',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(MyFeedPosts::class)
            ->call('publishNow', $post->id);

        $this->assertNull($post->fresh()->scheduled_at);
    }

    public function test_non_owner_cannot_publish_now(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Non owner publish now',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        Livewire::actingAs($this->member)
            ->test(MyFeedPosts::class)
            ->call('publishNow', $post->id)
            ->assertForbidden();

        $this->assertSame(FeedPost::STATUS_SCHEDULED, $post->fresh()->status);
    }

    public function test_cross_tenant_publish_now_denied(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->admin->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Cross tenant publish now',
            'status' => FeedPost::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        app()->instance('current_organization', $this->otherOrganization);

        Livewire::actingAs($this->otherMember)
            ->test(MyFeedPosts::class)
            ->call('publishNow', $post->id)
            ->assertForbidden();

        $this->assertSame(FeedPost::STATUS_SCHEDULED, $post->fresh()->status);
    }

    private function loopForOrganization(Organization $organization, ?User $member = null): Loop
    {
        $member ??= $this->admin;

        $loop = Loop::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $member->id,
        ]);

        LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);

        return $loop;
    }
}
