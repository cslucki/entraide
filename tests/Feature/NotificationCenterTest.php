<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Report;
use App\Models\Service;
use App\Models\User;
use App\Models\Community;
use App\Notifications\BadgeEarned;
use App\Notifications\NewMessageReceived;
use App\Notifications\ReportTreated;
use App\Notifications\TransactionStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_receives_notification_on_new_message()
    {
        Notification::fake();

        $community = Community::factory()->create();
        $sender = User::factory()->create(['community_id' => $community->id]);
        $recipient = User::factory()->create(['community_id' => $community->id]);
        $transaction = \App\Models\Transaction::factory()->create([
            'buyer_id' => $recipient->id,
            'seller_id' => $sender->id,
            'community_id' => $community->id,
        ]);
        $message = \App\Models\Message::factory()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $sender->id,
        ]);

        $recipient->notify(new NewMessageReceived($transaction, $message));

        Notification::assertSentTo($recipient, NewMessageReceived::class, function ($notification) use ($transaction) {
            $data = $notification->toDatabase($transaction->buyer);
            return $data['type'] === 'message' && $data['transaction_id'] === $transaction->id && $data['community_id'] === $transaction->community_id;
        });
    }

    public function test_user_receives_notification_on_transaction_update()
    {
        Notification::fake();

        $community = Community::factory()->create();
        $service = Service::factory()->create(['community_id' => $community->id]);
        $transaction = \App\Models\Transaction::factory()->create(['service_id' => $service->id, 'community_id' => $community->id]);
        $buyer = $transaction->buyer;

        $buyer->notify(new TransactionStatusChanged($transaction));

        Notification::assertSentTo($buyer, TransactionStatusChanged::class, function ($notification) use ($community) {
            $data = $notification->toDatabase(User::first());
            return $data['type'] === 'transaction' && $data['community_id'] === $community->id;
        });
    }

    public function test_user_receives_notification_on_badge_earned()
    {
        Notification::fake();

        $community = Community::factory()->create();
        $user = User::factory()->create(['community_id' => $community->id]);
        $badge = Badge::factory()->create(['name' => 'Test Badge']);

        $user->notify(new BadgeEarned($badge));

        Notification::assertSentTo($user, BadgeEarned::class, function ($notification) use ($badge, $community) {
            $data = $notification->toDatabase(User::first());
            return $data['type'] === 'badge' && $data['badge_id'] === $badge->id && $data['community_id'] === $community->id;
        });
    }

    public function test_reporter_receives_notification_on_report_treated()
    {
        Notification::fake();

        $community = Community::factory()->create();
        $reporter = User::factory()->create(['community_id' => $community->id]);
        $report = Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => Service::class,
            'reportable_id' => \Illuminate\Support\Str::uuid(),
            'reason' => 'spam',
            'status' => 'reviewed'
        ]);

        $reporter->notify(new ReportTreated($report));

        Notification::assertSentTo($reporter, ReportTreated::class, function ($notification) use ($report, $community) {
            $data = $notification->toDatabase(User::first());
            return $data['type'] === 'report' && $data['report_id'] === $report->id && $data['community_id'] === $community->id;
        });
    }

    public function test_notifications_page_is_accessible()
    {
        $community = Community::factory()->create();
        $user = User::factory()->create(['community_id' => $community->id]);

        $response = $this->actingAs($user)
            ->withSession(['community_id' => $community->id, 'community_slug' => $community->slug])
            ->get(route('community.notifications.index', $community->slug));

        $response->assertStatus(200);
        $response->assertSee('Mes Notifications');
    }

    public function test_user_can_mark_notification_as_read()
    {
        $community = Community::factory()->create();
        $user = User::factory()->create(['community_id' => $community->id]);
        $user->notify(new BadgeEarned(Badge::factory()->create()));

        $notification = $user->unreadNotifications->first();
        $this->assertNotNull($notification);

        $response = $this->actingAs($user)
            ->withSession(['community_id' => $community->id, 'community_slug' => $community->slug])
            ->post(route('community.notifications.mark-read', [$community->slug, $notification->id]));

        $response->assertRedirect();
        $this->assertEquals(0, $user->fresh()->unreadNotifications->count());
    }

    public function test_user_can_mark_all_notifications_as_read()
    {
        $community = Community::factory()->create();
        $user = User::factory()->create(['community_id' => $community->id]);

        // Use community scope for notifications
        $badge = Badge::factory()->create();
        $user->notify(new BadgeEarned($badge));
        $user->notify(new BadgeEarned($badge));

        $this->assertEquals(2, $user->unreadNotifications()->where('data->community_id', $community->id)->count());

        $response = $this->actingAs($user)
            ->withSession(['community_id' => $community->id, 'community_slug' => $community->slug])
            ->post(route('community.notifications.mark-all-read', $community->slug));

        $response->assertRedirect();
        $this->assertEquals(0, $user->fresh()->unreadNotifications()->where('data->community_id', $community->id)->count());
    }

    public function test_user_cannot_mark_other_user_notification_as_read()
    {
        $community = Community::factory()->create();
        $userA = User::factory()->create(['community_id' => $community->id]);
        $userB = User::factory()->create(['community_id' => $community->id]);

        $userB->notify(new BadgeEarned(Badge::factory()->create()));
        $notification = $userB->unreadNotifications->first();

        // Should return 404 because userA cannot find userB's notification in their notifications scope
        $response = $this->actingAs($userA)
            ->withSession(['community_id' => $community->id, 'community_slug' => $community->slug])
            ->post(route('community.notifications.mark-read', [$community->slug, $notification->id]));

        $response->assertStatus(404);
        $this->assertEquals(1, $userB->fresh()->unreadNotifications->count());
    }

    public function test_notifications_are_paginated()
    {
        $community = Community::factory()->create();
        $user = User::factory()->create(['community_id' => $community->id]);

        for ($i = 0; $i < 25; $i++) {
            $user->notify(new BadgeEarned(Badge::factory()->create()));
        }

        $response = $this->actingAs($user)
            ->withSession(['community_id' => $community->id, 'community_slug' => $community->slug])
            ->get(route('community.notifications.index', $community->slug));

        $response->assertStatus(200);
        // Default pagination is 20, so we should see 20 notifications and pagination links
        $this->assertCount(20, $response->viewData('notifications'));
    }

    public function test_notifications_are_scoped_to_community()
    {
        $communityA = Community::factory()->create();
        $communityB = Community::factory()->create();
        $user = User::factory()->create(['community_id' => $communityA->id]);

        // Notify in Community A context
        $user->community_id = $communityA->id;
        $user->notify(new BadgeEarned(Badge::factory()->create()));

        // Notify in Community B context (manually overriding for test)
        $badge = Badge::factory()->create();
        $user->community_id = $communityB->id;
        $user->notify(new BadgeEarned($badge));

        // Check Community A notifications
        $responseA = $this->actingAs($user)
            ->withSession(['community_id' => $communityA->id, 'community_slug' => $communityA->slug])
            ->get(route('community.notifications.index', $communityA->slug));

        $responseA->assertStatus(200);
        $this->assertCount(1, $responseA->viewData('notifications'));

        // Check Community B notifications
        $responseB = $this->actingAs($user)
            ->withSession(['community_id' => $communityB->id, 'community_slug' => $communityB->slug])
            ->get(route('community.notifications.index', $communityB->slug));

        $responseB->assertStatus(200);
        $this->assertCount(1, $responseB->viewData('notifications'));
    }
}
