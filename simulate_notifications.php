<?php

use App\Models\User;
use App\Models\Transaction;
use App\Models\Message;
use App\Models\Badge;
use App\Models\Report;
use App\Models\Community;
use App\Notifications\NewMessageReceived;
use App\Notifications\TransactionStatusChanged;
use App\Notifications\BadgeEarned;
use App\Notifications\ReportTreated;
use Illuminate\Support\Facades\Notification;

// Load the app
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::where('email', 'test@example.com')->first();
if (!$user) {
    echo "User not found. Run migrate:seed first.\n";
    exit(1);
}

$community = Community::where('slug', 'cpme')->first();
session(['community_id' => $community->id, 'community_slug' => $community->slug]);

// Clear old notifications
$user->notifications()->delete();

// 1. New Message
$transaction = Transaction::where('community_id', $community->id)->first();
$otherUser = User::where('id', '!=', $user->id)->first();
$message = new Message([
    'sender_id' => $otherUser->id,
    'content' => 'Salut ! Est-ce que tu es disponible pour le service demain ?',
]);
$message->sender = $otherUser;
$user->notify(new NewMessageReceived($transaction, $message));

// 2. Transaction Status Changed
$transaction->status = 'accepted';
$user->notify(new TransactionStatusChanged($transaction));

// 3. Badge Earned
$badge = Badge::first() ?? Badge::create(['name' => 'Premier échange', 'key' => 'first_exchange', 'description' => 'Bravo !']);
$user->notify(new BadgeEarned($badge));

// 4. Report Treated
$report = Report::create([
    'reporter_id' => $user->id,
    'reported_id' => $otherUser->id,
    'reportable_id' => $otherUser->id,
    'reportable_type' => User::class,
    'reason' => 'Comportement inapproprié',
    'status' => 'reviewed',
    'community_id' => $community->id,
]);
$user->notify(new ReportTreated($report));

echo "Simulated notifications created successfully.\n";
