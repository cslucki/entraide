<?php

namespace Tests\Feature\Dossiers;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DossierArticleIndexingDispatchTest extends TestCase
{
    public function refreshDatabase()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException('DossierArticleIndexingDispatchTest requires safe-test sqlite :memory:.');
        }

        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function test_dispatcher_pushes_uuid_only_job_marked_after_commit(): void
    {
        [$organization, , $dossier, $post] = $this->fixture(attached: false);
        Queue::fake();

        app(DossierArticleIndexingDispatcher::class)->dispatch($organization->id, $dossier->id, $post->id);

        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_dossier_article_attach_existing_article_does_not_dispatch_duplicate(): void
    {
        [$organization, $user, $dossier, $post] = $this->fixture();
        $this->actAsOrganizationUser($organization, $user);
        Queue::fake();

        $this->post(route('organization.dossiers.articles.store', [$organization, $dossier]), [
            'blog_post_id' => $post->id,
        ])->assertSessionHasErrors('blog_post_id');

        Queue::assertNothingPushed();
    }

    public function test_dossier_article_attach_cross_tenant_article_is_refused_without_dispatch(): void
    {
        [$organization, $user, $dossier] = $this->fixture(attached: false);
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherPost = BlogPost::create([
            'organization_id' => $otherOrganization->id,
            'user_id' => $otherUser->id,
            'title' => 'Other tenant article',
            'slug' => 'other-tenant-article-'.Str::uuid(),
            'content' => '<p>Other tenant content</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        $this->actAsOrganizationUser($organization, $user);
        Queue::fake();

        $this->post(route('organization.dossiers.articles.store', [$organization, $dossier]), [
            'blog_post_id' => $otherPost->id,
        ])->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_dossier_article_attach_dispatches_after_real_attachment(): void
    {
        [$organization, $user, $dossier, $post] = $this->fixture(attached: false);
        $this->actAsOrganizationUser($organization, $user);
        Queue::fake();

        $this->post(route('organization.dossiers.articles.store', [$organization, $dossier]), [
            'blog_post_id' => $post->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('dossier_blog_posts', [
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
        ]);
        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_blog_dossier_detach_dispatches_captured_triplet_after_real_detach(): void
    {
        [$organization, $user, $dossier, $post] = $this->fixture();
        $this->actAsOrganizationUser($organization, $user);
        Queue::fake();

        $this->deleteJson(route('organization.blog.dossier.detach', [$organization, $post]))
            ->assertOk();

        $this->assertDatabaseMissing('dossier_blog_posts', [
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
        ]);
        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_blog_dossier_detach_nonexistent_attachment_does_not_dispatch(): void
    {
        [$organization, $user, , $post] = $this->fixture(attached: false);
        $this->actAsOrganizationUser($organization, $user);
        Queue::fake();

        $this->deleteJson(route('organization.blog.dossier.detach', [$organization, $post]))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_blog_post_content_update_dispatches(): void
    {
        [$organization, , $dossier, $post] = $this->fixture();
        Queue::fake();

        $post->update(['content' => '<p>updated searchable content</p>']);

        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_non_attached_blog_post_update_does_not_dispatch(): void
    {
        [, , , $post] = $this->fixture(attached: false);
        Queue::fake();

        $post->update(['content' => '<p>updated searchable content</p>']);

        Queue::assertNothingPushed();
    }

    public function test_blog_post_title_update_does_not_dispatch(): void
    {
        [, , , $post] = $this->fixture();
        Queue::fake();

        $post->update(['title' => 'Display-only title change']);

        Queue::assertNothingPushed();
    }

    public function test_blog_post_status_update_dispatches(): void
    {
        [$organization, , $dossier, $post] = $this->fixture();
        Queue::fake();

        $post->update(['status' => 'draft']);

        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_blog_post_published_at_update_dispatches(): void
    {
        [$organization, , $dossier, $post] = $this->fixture();
        Queue::fake();

        $post->update(['published_at' => now()->addMinute()]);

        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_blog_post_soft_delete_and_restore_dispatch(): void
    {
        [$organization, , $dossier, $post] = $this->fixture();
        Queue::fake();

        $post->delete();

        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);

        Queue::fake();

        $post->restore();

        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_blog_post_force_delete_does_not_dispatch(): void
    {
        [, , , $post] = $this->fixture();
        Queue::fake();

        $post->forceDelete();

        Queue::assertNothingPushed();
    }

    public function test_dossier_destroy_controller_dispatches_captured_articles_after_pivots_are_deleted(): void
    {
        [$organization, $user, $dossier, $post] = $this->fixture();
        $this->actAsOrganizationUser($organization, $user);
        Queue::fake();

        $this->delete(route('organization.dossiers.destroy', [$organization, $dossier]))
            ->assertRedirect();

        $this->assertSoftDeleted('dossiers', ['id' => $dossier->id]);
        $this->assertDatabaseMissing('dossier_blog_posts', [
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
        ]);
        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    public function test_dossier_destroy_controller_without_articles_does_not_dispatch(): void
    {
        [$organization, $user, $dossier] = $this->fixture(attached: false);
        $this->actAsOrganizationUser($organization, $user);
        Queue::fake();

        $this->delete(route('organization.dossiers.destroy', [$organization, $dossier]))
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_dossier_force_delete_does_not_dispatch(): void
    {
        [, , $dossier] = $this->fixture();
        Queue::fake();

        $dossier->forceDelete();

        Queue::assertNothingPushed();
    }

    public function test_dossier_direct_restore_dispatches_for_existing_attached_articles(): void
    {
        [$organization, , $dossier, $post] = $this->fixture();
        $dossier->delete();
        Queue::fake();

        $dossier->restore();

        $this->assertIndexJobPushed($organization->id, $dossier->id, $post->id);
    }

    /**
     * @param  array<string, mixed>  $postAttributes
     * @return array{0: Organization, 1: User, 2: Dossier, 3: BlogPost}
     */
    private function fixture(bool $attached = true, array $postAttributes = []): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $user->id,
            'name' => 'Indexed dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $post = BlogPost::create(array_merge([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'title' => 'Indexed article',
            'slug' => 'indexed-article-'.Str::uuid(),
            'content' => '<p>searchable article content</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ], $postAttributes));

        if ($attached) {
            DossierBlogPost::create([
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id,
                'added_by' => $user->id,
                'position' => 1,
            ]);
        }

        return [$organization, $user, $dossier, $post];
    }

    private function actAsOrganizationUser(Organization $organization, User $user): void
    {
        app()->instance('current_organization', $organization);
        Gate::before(fn (): bool => true);
        $this->actingAs($user);
    }

    private function assertIndexJobPushed(string $organizationId, string $dossierId, string $blogPostId): void
    {
        Queue::assertPushed(IndexDossierArticleChunks::class, function (IndexDossierArticleChunks $job) use ($organizationId, $dossierId, $blogPostId): bool {
            return $job->organizationId === $organizationId
                && $job->dossierId === $dossierId
                && $job->blogPostId === $blogPostId
                && $job->afterCommit === true;
        });

        Queue::assertPushed(IndexDossierArticleChunks::class, 1);
    }
}
