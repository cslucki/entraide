<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BadgeService;
use Tests\TestCase;

class BadgeServiceTest extends TestCase
{
    private BadgeService $badgeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->badgeService = app(BadgeService::class);
        $this->seedBadges();
    }

    private function seedBadges(): void
    {
        $badges = [
            ['key' => 'first_exchange', 'name' => 'Premier échange', 'description' => '1 échange complété', 'icon' => '🤝', 'color' => '#6366f1'],
            ['key' => 'five_exchanges', 'name' => '5 échanges', 'description' => '5 échanges complétés', 'icon' => '⭐', 'color' => '#f59e0b'],
            ['key' => 'ten_exchanges', 'name' => '10 échanges', 'description' => '10 échanges complétés', 'icon' => '🏆', 'color' => '#ef4444'],
            ['key' => 'first_service', 'name' => 'Premier service', 'description' => 'Service créé', 'icon' => '📋', 'color' => '#10b981'],
            ['key' => 'five_services', 'name' => '5 services', 'description' => '5 services créés', 'icon' => '🎯', 'color' => '#3b82f6'],
            ['key' => 'top_rated', 'name' => 'Top évaluations', 'description' => 'Note ≥ 4.5 sur 3+ avis', 'icon' => '💎', 'color' => '#8b5cf6'],
            ['key' => 'generous', 'name' => 'Généreux', 'description' => '3+ avis positifs donnés', 'icon' => '💬', 'color' => '#ec4899'],
        ];

        foreach ($badges as $data) {
            Badge::firstOrCreate(['key' => $data['key']], $data);
        }
    }

    public function test_no_badge_awarded_for_new_user(): void
    {
        $user = User::factory()->create();

        $this->badgeService->checkAndAward($user);

        $this->assertCount(0, $user->fresh()->badges);
    }

    public function test_first_exchange_badge_awarded_after_one_completed_transaction(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Transaction::factory()->create([
            'buyer_id' => $user->id,
            'seller_id' => $other->id,
            'status' => 'completed',
        ]);

        $this->badgeService->checkAndAward($user);

        $badges = $user->fresh()->badges->pluck('key')->all();
        $this->assertContains('first_exchange', $badges);
    }

    public function test_five_exchanges_badge_not_awarded_before_threshold(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Transaction::factory()->count(3)->create([
            'buyer_id' => $user->id,
            'seller_id' => $other->id,
            'status' => 'completed',
        ]);

        $this->badgeService->checkAndAward($user);

        $badges = $user->fresh()->badges->pluck('key')->all();
        $this->assertNotContains('five_exchanges', $badges);
    }

    public function test_five_and_ten_exchange_badges_awarded_at_thresholds(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Transaction::factory()->count(10)->create([
            'seller_id' => $user->id,
            'buyer_id' => $other->id,
            'status' => 'completed',
        ]);

        $this->badgeService->checkAndAward($user);

        $badges = $user->fresh()->badges->pluck('key')->all();
        $this->assertContains('first_exchange', $badges);
        $this->assertContains('five_exchanges', $badges);
        $this->assertContains('ten_exchanges', $badges);
    }

    public function test_first_service_badge_awarded_when_service_created(): void
    {
        $user = User::factory()->create();
        Service::factory()->forUser($user)->create();

        $this->badgeService->checkAndAward($user);

        $this->assertContains('first_service', $user->fresh()->badges->pluck('key')->all());
    }

    public function test_five_services_badge_awarded_at_threshold(): void
    {
        $user = User::factory()->create();
        Service::factory()->count(5)->forUser($user)->create();

        $this->badgeService->checkAndAward($user);

        $badges = $user->fresh()->badges->pluck('key')->all();
        $this->assertContains('five_services', $badges);
    }

    private function makeReview(User $reviewer, User $reviewed, int $rating): void
    {
        $tx = Transaction::factory()->create([
            'buyer_id' => $reviewer->id,
            'seller_id' => $reviewed->id,
            'status' => 'completed',
        ]);
        Review::factory()->create([
            'transaction_id' => $tx->id,
            'reviewer_id' => $reviewer->id,
            'reviewed_id' => $reviewed->id,
            'rating' => $rating,
        ]);
    }

    public function test_top_rated_badge_requires_rating_and_minimum_reviews(): void
    {
        $user = User::factory()->create(['rating' => 4.7]);
        $reviewers = User::factory()->count(3)->create();

        // Only 2 reviews — not enough
        $this->makeReview($reviewers[0], $user, 5);
        $this->makeReview($reviewers[1], $user, 5);
        $this->badgeService->checkAndAward($user);
        $this->assertNotContains('top_rated', $user->fresh()->badges->pluck('key')->all());

        // Third review tips it over
        $this->makeReview($reviewers[2], $user, 5);
        $this->badgeService->checkAndAward($user);
        $this->assertContains('top_rated', $user->fresh()->badges->pluck('key')->all());
    }

    public function test_top_rated_badge_not_awarded_below_rating_threshold(): void
    {
        $user = User::factory()->create(['rating' => 3.5]);
        $reviewers = User::factory()->count(5)->create();

        foreach ($reviewers as $reviewer) {
            $this->makeReview($reviewer, $user, 4);
        }

        $this->badgeService->checkAndAward($user);

        $this->assertNotContains('top_rated', $user->fresh()->badges->pluck('key')->all());
    }

    public function test_generous_badge_awarded_after_three_positive_reviews_given(): void
    {
        $user = User::factory()->create();
        $others = User::factory()->count(3)->create();

        foreach ($others as $other) {
            $this->makeReview($user, $other, 5);
        }

        $this->badgeService->checkAndAward($user);

        $this->assertContains('generous', $user->fresh()->badges->pluck('key')->all());
    }

    public function test_checkAndAward_is_idempotent(): void
    {
        $user = User::factory()->create();
        Service::factory()->forUser($user)->create();

        $this->badgeService->checkAndAward($user);
        $this->badgeService->checkAndAward($user);
        $this->badgeService->checkAndAward($user);

        $this->assertCount(1, $user->fresh()->badges);
    }

    public function test_observer_awards_badge_when_service_created(): void
    {
        $user = User::factory()->create();

        // Creating via factory triggers observer
        Service::factory()->forUser($user)->create();

        $this->assertContains('first_service', $user->fresh()->badges->pluck('key')->all());
    }

    public function test_observer_awards_badge_when_transaction_completed(): void
    {
        $seller = User::factory()->create(['points_balance' => 100]);
        $buyer = User::factory()->create(['points_balance' => 300]);

        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'buyer_done',
            'points_agreed' => 50,
        ]);

        // Simulate the confirm action that sets status to completed
        $tx->update(['status' => 'completed', 'completed_at' => now()]);

        $this->assertContains('first_exchange', $buyer->fresh()->badges->pluck('key')->all());
        $this->assertContains('first_exchange', $seller->fresh()->badges->pluck('key')->all());
    }
}
