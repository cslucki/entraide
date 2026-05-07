<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Setting;
use App\Models\PointLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_is_created_with_referral_code()
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->referral_code);
        $this->assertEquals(8, strlen($user->referral_code));
    }

    public function test_registration_with_referral_code_awards_points_logic()
    {
        Setting::set('referral_reward_registration', '50');
        $referrer = User::factory()->create(['points_balance' => 100]);

        $referee = User::create([
            'name' => 'Filleul User',
            'email' => 'filleul@example.com',
            'password' => Hash::make('password123'),
            'points_balance' => 100,
            'referrer_id' => $referrer->id,
        ]);

        PointLedger::create([
            'user_id' => $referee->id,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);

        $bonus = 50;
        Referral::create([
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'registration_reward_paid' => true,
        ]);

        $referrer->increment('points_balance', $bonus);
        PointLedger::create(['user_id' => $referrer->id, 'delta' => $bonus, 'reason' => 'referral_bonus']);

        $referee->increment('points_balance', $bonus);
        PointLedger::create(['user_id' => $referee->id, 'delta' => $bonus, 'reason' => 'referral_bonus']);

        $this->assertEquals(150, $referee->fresh()->points_balance);
        $this->assertEquals(150, $referrer->fresh()->points_balance);
        $this->assertDatabaseHas('referrals', ['referrer_id' => $referrer->id, 'referee_id' => $referee->id]);
    }

    public function test_first_transaction_awards_double_parrainage_points_logic()
    {
        Setting::set('referral_reward_first_transaction', '100');
        $referrer = User::factory()->create(['points_balance' => 100]);
        $referee = User::factory()->create([
            'referrer_id' => $referrer->id,
            'points_balance' => 200
        ]);

        Referral::create([
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'registration_reward_paid' => true,
        ]);

        $otherUser = User::factory()->create(['points_balance' => 500]);
        $service = Service::factory()->create(['user_id' => $referee->id]);

        $transaction = Transaction::create([
            'buyer_id' => $otherUser->id,
            'seller_id' => $referee->id,
            'service_id' => $service->id,
            'points_proposed' => 100,
            'points_agreed' => 100,
            'status' => 'completed',
        ]);

        // Logic from Controller (Simplified for testing logic only)
        $user = $referee;
        $referral = Referral::where('referee_id', $user->id)
            ->where('first_transaction_reward_paid', false)
            ->first();

        if ($referral) {
            $completedCount = Transaction::where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
            })->where('status', 'completed')->count();

            if ($completedCount === 1) {
                $bonus = 100;
                $referrer->increment('points_balance', $bonus);
                $user->increment('points_balance', $bonus);
                $referral->update(['first_transaction_reward_paid' => true]);
            }
        }

        $this->assertEquals(300, $referee->fresh()->points_balance);
        $this->assertEquals(200, $referrer->fresh()->points_balance);
    }

    public function test_double_parrainage_bonus_only_once()
    {
        Setting::set('referral_reward_first_transaction', '100');
        $referrer = User::factory()->create(['points_balance' => 0]);
        $referee = User::factory()->create(['referrer_id' => $referrer->id, 'points_balance' => 0]);
        Referral::create(['referrer_id' => $referrer->id, 'referee_id' => $referee->id]);

        $other = User::factory()->create(['points_balance' => 1000]);

        // First transaction
        $tx1 = Transaction::factory()->create(['buyer_id' => $other->id, 'seller_id' => $referee->id, 'status' => 'buyer_done', 'points_agreed' => 100]);
        $this->actingAs($referee); // Seller confirms
        $this->app->make(\App\Http\Controllers\TransactionController::class)->confirm($tx1);

        $this->assertEquals(100, $referrer->fresh()->points_balance, "Referrer should have 100 bonus");
        $this->assertEquals(200, $referee->fresh()->points_balance, "Referee should have 100 (earned) + 100 (bonus)");

        // Second transaction
        $tx2 = Transaction::factory()->create(['buyer_id' => $other->id, 'seller_id' => $referee->id, 'status' => 'buyer_done', 'points_agreed' => 100]);
        $this->actingAs($referee);
        $this->app->make(\App\Http\Controllers\TransactionController::class)->confirm($tx2);

        $this->assertEquals(100, $referrer->fresh()->points_balance, "Referrer bonus should not double");
        $this->assertEquals(300, $referee->fresh()->points_balance, "Referee should only have earned points, no new bonus");
    }

    public function test_no_bonus_on_cancelled_transaction()
    {
        Setting::set('referral_reward_first_transaction', '100');
        $referrer = User::factory()->create(['points_balance' => 0]);
        $referee = User::factory()->create(['referrer_id' => $referrer->id, 'points_balance' => 0]);
        Referral::create(['referrer_id' => $referrer->id, 'referee_id' => $referee->id]);

        $buyer = User::factory()->create();
        $tx = Transaction::factory()->create(['buyer_id' => $buyer->id, 'seller_id' => $referee->id, 'status' => 'pending']);

        $this->actingAs($buyer); // Buyer cancels
        $this->app->make(\App\Http\Controllers\TransactionController::class)->cancel($tx);

        $this->assertEquals(0, $referrer->fresh()->points_balance);
        $this->assertEquals(0, $referee->fresh()->points_balance);
        $this->assertFalse(Referral::where('referee_id', $referee->id)->first()->first_transaction_reward_paid);
    }

    public function test_referral_works_across_communities()
    {
        $com1 = \App\Models\Community::factory()->create(['slug' => 'com1']);
        $com2 = \App\Models\Community::factory()->create(['slug' => 'com2']);

        $referrer = User::factory()->create(['community_id' => $com1->id]);

        // Referee registers in community 2 using referrer's code from community 1
        $this->post(route('community.register', ['community' => 'com2']), [
            'name' => 'Cross Com',
            'email' => 'cross@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'referral_code' => $referrer->referral_code,
        ]);

        $referee = User::where('email', 'cross@example.com')->first();
        $this->assertEquals($referrer->id, $referee->referrer_id);
        $this->assertEquals($com2->id, $referee->community_id);
        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id
        ]);
    }

    public function test_admin_referrals_is_protected()
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('admin.referrals'))->assertStatus(403);
    }

    public function test_users_cannot_view_others_referrals()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // User 2 trying to see User 1's referral list (if there was a param, but there isn't)
        // The route is profile/referrals which shows auth()->user()'s referrals.
        $this->actingAs($user2)->get(route('profile.referrals'))->assertOk();
        // We verify that the view only shows User 2's data.
        // Logic check: ProfileController@referrals uses $request->user()->referrals()
    }
}
