<?php

namespace Tests\Feature\Commands;

use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FeedPublishScheduledTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_publishes_scheduled_post_whose_date_has_passed(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_SCHEDULED,
            'title' => 'À publier',
            'content' => 'Contenu',
            'scheduled_at' => Carbon::now()->subHour(),
        ]);

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0);

        $post->refresh();

        $this->assertSame(FeedPost::STATUS_PUBLISHED, $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_sets_published_at_to_current_timestamp(): void
    {
        $now = Carbon::now();

        Carbon::setTestNow($now);

        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_SCHEDULED,
            'title' => 'À publier',
            'content' => 'Contenu',
            'scheduled_at' => Carbon::now()->subMinute(),
        ]);

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0);

        $post->refresh();

        $this->assertTrue($post->published_at->isSameSecond($now));
    }

    public function test_does_not_publish_future_scheduled_post(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_SCHEDULED,
            'title' => 'Futur',
            'content' => 'Contenu',
            'scheduled_at' => Carbon::now()->addDay(),
        ]);

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0);

        $post->refresh();

        $this->assertSame(FeedPost::STATUS_SCHEDULED, $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_does_not_modify_draft(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_DRAFT,
            'title' => 'Brouillon',
            'content' => 'Contenu',
        ]);

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0);

        $post->refresh();

        $this->assertSame(FeedPost::STATUS_DRAFT, $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_does_not_modify_already_published_post(): void
    {
        $publishedAt = Carbon::now()->subDay();

        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_PUBLISHED,
            'title' => 'Déjà publié',
            'content' => 'Contenu',
            'published_at' => $publishedAt,
        ]);

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0);

        $post->refresh();

        $this->assertSame(FeedPost::STATUS_PUBLISHED, $post->status);
        $this->assertTrue($post->published_at->isSameSecond($publishedAt));
    }

    public function test_ignores_soft_deleted_scheduled_post(): void
    {
        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_SCHEDULED,
            'title' => 'Supprimée',
            'content' => 'Contenu',
            'scheduled_at' => Carbon::now()->subHour(),
        ]);

        $post->delete();

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0);

        $post->refresh();

        $this->assertSame(FeedPost::STATUS_SCHEDULED, $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_returns_success_when_no_posts_to_publish(): void
    {
        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0)
            ->expectsOutput('No scheduled announcements due for publication.');
    }

    public function test_reports_count_of_published_posts(): void
    {
        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_SCHEDULED,
            'title' => 'Première',
            'content' => 'Contenu',
            'scheduled_at' => Carbon::now()->subHour(),
        ]);

        FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'status' => FeedPost::STATUS_SCHEDULED,
            'title' => 'Deuxième',
            'content' => 'Contenu',
            'scheduled_at' => Carbon::now()->subMinutes(30),
        ]);

        $this->artisan('feed:publish-scheduled')
            ->assertExitCode(0)
            ->expectsOutput('Published 2 scheduled announcement(s).');
    }
}
