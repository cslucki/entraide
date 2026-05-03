<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\Transaction;
use App\Models\User;

class BadgeService
{
    public function checkAndAward(User $user): void
    {
        $earned = $user->badges()->pluck('key')->all();

        $completedCount = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'completed')->count();

        $this->maybeAward($user, $earned, 'first_exchange', $completedCount >= 1);
        $this->maybeAward($user, $earned, 'five_exchanges', $completedCount >= 5);
        $this->maybeAward($user, $earned, 'ten_exchanges', $completedCount >= 10);

        $serviceCount = $user->services()->count();
        $this->maybeAward($user, $earned, 'first_service', $serviceCount >= 1);
        $this->maybeAward($user, $earned, 'five_services', $serviceCount >= 5);

        $reviewCount = $user->reviewsReceived()->count();
        $rating = $user->rating !== null ? (float) $user->rating : 0.0;
        $this->maybeAward($user, $earned, 'top_rated', $reviewCount >= 3 && $rating >= 4.5);

        $positiveReviewsGiven = $user->reviewsGiven()->where('rating', '>=', 4)->count();
        $this->maybeAward($user, $earned, 'generous', $positiveReviewsGiven >= 3);
    }

    private function maybeAward(User $user, array $earned, string $key, bool $condition): void
    {
        if (!$condition || in_array($key, $earned)) {
            return;
        }

        $badge = Badge::where('key', $key)->first();
        if ($badge) {
            $user->badges()->attach($badge->id, ['earned_at' => now()]);
        }
    }
}
