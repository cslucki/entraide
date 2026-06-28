<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AiBudgetExceeded;
use App\Notifications\NewMessageReceived;
use App\Notifications\TransactionStatusChanged;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailNotificationLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->user = User::factory()->complete()->create();
    }

    public function test_welcome_notification_creates_email_log(): void
    {
        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'to_email' => $this->user->email,
            'subject' => 'Bienvenue sur Entraide !',
            'status' => 'sent',
        ]);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertSame('WelcomeNotification', $log->data['source']);
    }

    public function test_transaction_status_changed_creates_email_log(): void
    {
        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);

        $this->user->notify(new TransactionStatusChanged($transaction));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'to_email' => $this->user->email,
            'status' => 'sent',
        ]);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertSame('TransactionStatusChanged', $log->data['source']);
    }

    public function test_new_message_received_creates_email_log(): void
    {
        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);
        $message = Message::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->user->notify(new NewMessageReceived($transaction, $message));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'to_email' => $this->user->email,
            'status' => 'sent',
        ]);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertSame('NewMessageReceived', $log->data['source']);
    }

    public function test_ai_budget_exceeded_creates_email_log(): void
    {
        $this->user->notify(new AiBudgetExceeded(
            scenarioId: 'test-scenario',
            currentCost: 50.0,
            budgetLimit: 100.0,
        ));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'to_email' => $this->user->email,
            'status' => 'sent',
        ]);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertSame('AiBudgetExceeded', $log->data['source']);
    }

    public function test_framework_notification_is_not_logged(): void
    {
        $token = Str::random(60);

        $this->user->notify(new ResetPassword($token));

        $this->assertDatabaseMissing('email_logs', [
            'user_id' => $this->user->id,
        ]);
    }

    public function test_non_user_notifiable_is_not_logged(): void
    {
        $notifiable = new AnonymousNotifiable;
        $notifiable->route('mail', 'test@example.com');

        $notifiable->notify(new WelcomeNotification);

        $this->assertDatabaseMissing('email_logs', [
            'to_email' => 'test@example.com',
        ]);
    }

    public function test_logging_failure_does_not_throw(): void
    {
        $user = $this->user;
        $user->email = null;

        // Should not throw despite missing email
        $user->notify(new WelcomeNotification);

        $this->assertTrue(true);
    }
}
