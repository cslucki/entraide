<?php

namespace Tests\Feature;

use App\Jobs\NotifyFeedPostMentions;
use App\Livewire\CreateFeedPost;
use App\Livewire\EditFeedPost;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Feed\FeedMentionMailer;
use App\Support\Notifications\NotificationCatalogue;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1880 (demande Cyril, 22/11) — mentions dans les annonces du Flux.
 *
 * Un seul mode par message : 1 a 5 personnes, OU `@tous` (Boucles choisies dont
 * l'auteur est proprietaire), OU `@membres` (administrateur de l'Organization).
 * Chaque mention -> application + courriel + Web Push, via un job de file.
 */
class TASK1880FeedMentionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $auteur;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['feed_post_publish_mode' => 'members']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Ada']);
        $this->organization->update(['admin_id' => $this->admin->id]);
        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Paul']);

        app()->instance('current_organization', $this->organization);

        $this->boucle = $this->boucle($this->auteur, 'member');
    }

    private function boucle(User $membre, string $role): Loop
    {
        $boucle = Loop::factory()->create(['organization_id' => $this->organization->id, 'created_by' => $membre->id]);
        LoopMember::factory()->create(['loop_id' => $boucle->id, 'user_id' => $membre->id, 'role' => $role]);

        return $boucle;
    }

    private function membre(Loop $boucle): User
    {
        $u = User::factory()->create(['organization_id' => $this->organization->id]);
        LoopMember::factory()->create(['loop_id' => $boucle->id, 'user_id' => $u->id]);

        return $u;
    }

    private function publier(User $auteur, string $html, array $boucles)
    {
        return Livewire::actingAs($auteur)
            ->test(CreateFeedPost::class)
            ->set('selectedLoops', array_map(fn (Loop $b) => (string) $b->id, $boucles))
            ->set('content', $html)
            ->call('submit');
    }

    private function notifies(): array
    {
        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::FEED_POST_MENTION_CREATED)
            ->pluck('recipient_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    private static function ids(User ...$users): array
    {
        return collect($users)->map(fn (User $u) => (string) $u->id)->sort()->values()->all();
    }

    public function test_named_mentions_notify_each_person_on_three_channels(): void
    {
        $alice = $this->membre($this->boucle);
        $bob = $this->membre($this->boucle);
        $silencieux = $this->membre($this->boucle);

        $this->publier($this->auteur, '<p>Merci @'.$alice->publicDisplayName().' et @'.$bob->publicDisplayName().' !</p>', [$this->boucle])
            ->assertHasNoErrors();

        $this->assertSame(self::ids($alice, $bob), $this->notifies());

        $notification = MemberNotification::query()->where('recipient_id', $alice->id)->firstOrFail();
        $this->assertSame((string) $this->auteur->id, (string) $notification->actor_id);
        $this->assertSame(
            [NotificationCatalogue::CHANNEL_EMAIL, NotificationCatalogue::CHANNEL_WEB_PUSH],
            DB::table('member_notification_deliveries')->where('notification_id', $notification->id)->pluck('channel')->sort()->values()->all(),
        );
        $this->assertNotContains((string) $silencieux->id, $this->notifies());
    }

    public function test_a_person_outside_the_chosen_loops_is_not_notified(): void
    {
        $ailleurs = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->publier($this->auteur, '<p>@'.$ailleurs->publicDisplayName().' regarde</p>', [$this->boucle])->assertHasNoErrors();

        $this->assertSame([], $this->notifies());
    }

    public function test_more_than_five_people_is_refused(): void
    {
        $noms = collect(range(1, 6))->map(fn () => '@'.$this->membre($this->boucle)->publicDisplayName())->implode(' ');

        $this->publier($this->auteur, "<p>{$noms}</p>", [$this->boucle])->assertHasErrors('content');

        $this->assertSame(0, FeedPost::count());
    }

    public function test_tous_is_refused_to_a_simple_member(): void
    {
        $this->membre($this->boucle);

        $this->publier($this->auteur, '<p>@tous rendez-vous jeudi</p>', [$this->boucle])->assertHasErrors('content');

        $this->assertSame(0, FeedPost::count());
    }

    public function test_tous_reaches_members_of_every_chosen_owned_loop_once(): void
    {
        $proprio = User::factory()->create(['organization_id' => $this->organization->id]);
        $b1 = $this->boucle($proprio, 'owner');
        $b2 = $this->boucle($proprio, 'owner');
        $b3 = $this->boucle($proprio, 'owner');
        $a = $this->membre($b1);
        $b = $this->membre($b2);
        LoopMember::factory()->create(['loop_id' => $b2->id, 'user_id' => $a->id]);
        $horsChoix = $this->membre($b3);

        $this->publier($proprio, '<p>@tous rendez-vous jeudi</p>', [$b1, $b2])->assertHasNoErrors();

        $this->assertSame(self::ids($a, $b), $this->notifies());
        $this->assertNotContains((string) $horsChoix->id, $this->notifies());
    }

    public function test_named_mentions_and_tous_cannot_be_combined(): void
    {
        $proprio = User::factory()->create(['organization_id' => $this->organization->id]);
        $b1 = $this->boucle($proprio, 'owner');
        $a = $this->membre($b1);

        $composant = $this->publier($proprio, '<p>@tous et @'.$a->publicDisplayName().'</p>', [$b1])->assertHasErrors('content');
        $this->assertSame(__('feed-post.mentions_one_mode'), $composant->errors()->first('content'));
    }

    public function test_membres_is_reserved_to_the_organization_admin_and_reaches_everyone(): void
    {
        $this->membre($this->boucle);
        $this->publier($this->auteur, '<p>@membres coucou</p>', [$this->boucle])->assertHasErrors('content');

        $autreOrg = Organization::factory()->create();
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        Livewire::actingAs($this->admin)->test(CreateFeedPost::class)
            ->set('content', '<p>@membres assemblée générale</p>')
            ->call('submit')
            ->assertHasNoErrors();

        $attendus = User::query()->where('organization_id', $this->organization->id)->whereKeyNot($this->admin->id)->pluck('id')
            ->map(fn ($id) => (string) $id)->sort()->values()->all();
        $this->assertSame($attendus, $this->notifies());
        $this->assertNotContains((string) $etranger->id, $this->notifies());
    }

    public function test_tous_and_membres_cannot_be_combined(): void
    {
        $b1 = $this->boucle($this->admin, 'owner');

        $composant = Livewire::actingAs($this->admin)->test(CreateFeedPost::class)
            ->set('selectedLoops', [(string) $b1->id])
            ->set('content', '<p>@tous et @membres</p>')
            ->call('submit')
            ->assertHasErrors('content');
        $this->assertSame(__('feed-post.mentions_one_mode'), $composant->errors()->first('content'));
    }

    public function test_editing_notifies_only_newly_mentioned_people(): void
    {
        $alice = $this->membre($this->boucle);
        $bob = $this->membre($this->boucle);

        $this->publier($this->auteur, '<p>@'.$alice->publicDisplayName().'</p>', [$this->boucle])->assertHasNoErrors();
        $post = FeedPost::firstOrFail();

        Livewire::actingAs($this->auteur)->test(EditFeedPost::class, ['feedPost' => $post])
            ->set('content', '<p>@'.$alice->publicDisplayName().' et @'.$bob->publicDisplayName().'</p>')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(self::ids($alice, $bob), $this->notifies());
        $this->assertSame(2, MemberNotification::query()->where('notification_key', NotificationCatalogue::FEED_POST_MENTION_CREATED)->count());
    }

    public function test_draft_does_not_notify_and_job_follows_the_managed_queue(): void
    {
        Queue::fake();
        $alice = $this->membre($this->boucle);

        Livewire::actingAs($this->auteur)->test(CreateFeedPost::class)
            ->set('selectedLoops', [(string) $this->boucle->id])
            ->set('mode', 'draft')
            ->set('content', '<p>@'.$alice->publicDisplayName().'</p>')
            ->call('submit');
        Queue::assertNotPushed(NotifyFeedPostMentions::class);

        config(['queue.notifications_managed_queue_connection' => 'cloud', 'queue.notifications_managed_queue_name' => 'notifications']);
        $this->publier($this->auteur, '<p>@'.$alice->publicDisplayName().'</p>', [$this->boucle]);

        Queue::assertPushed(NotifyFeedPostMentions::class, fn ($job) => $job->connection === 'cloud' && $job->queue === 'notifications');
    }

    public function test_email_carries_the_full_message_and_the_log_copy_is_redacted(): void
    {
        $this->seed(SystemEmailTemplateSeeder::class);
        $alice = $this->membre($this->boucle);
        $long = str_repeat('Une phrase complète de l’annonce. ', 40);

        $post = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => '<p>@'.$alice->publicDisplayName().'</p><p>'.$long.'</p><p>Fin du message.</p>',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $reel = app(FeedMentionMailer::class)->compose($post, $alice, $this->auteur, 'https://exemple.test/a', 'fr');
        $this->assertSame('Paul vous a identifié sur le Flux', $reel['subject']);
        $this->assertStringContainsString('Fin du message.', $reel['body']);
        $this->assertStringContainsString(e(trim($long)), $reel['body']);

        $en = app(FeedMentionMailer::class)->compose($post, $alice, $this->auteur, 'https://exemple.test/a', 'en');
        $this->assertSame('Paul tagged you on the Feed', $en['subject']);

        $journal = app(FeedMentionMailer::class)->compose($post, $alice, $this->auteur, '[url]', 'fr', pourJournal: true);
        $this->assertStringContainsString(FeedMentionMailer::MESSAGE_EXPURGE, $journal['body']);
        $this->assertStringNotContainsString('Fin du message.', $journal['body']);
    }
}
