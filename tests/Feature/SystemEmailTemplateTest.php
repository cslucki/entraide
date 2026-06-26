<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Message;
use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AiBudgetExceeded;
use App\Notifications\NewMessageReceived;
use App\Notifications\TransactionStatusChanged;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SystemEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->user = User::factory()->complete()->create([
            'name' => 'Alice Martin',
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
            'city' => 'Paris',
        ]);
    }

    public function test_welcome_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'welcome',
            'name' => 'Welcome',
            'subject' => 'Bienvenue {{ name }} !',
            'content_html' => '<h1>Bienvenue {{ name }} !</h1>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue Alice Martin !',
        ]);
    }

    public function test_welcome_falls_back_when_no_system_template(): void
    {
        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue sur Entraide !',
        ]);
    }

    public function test_welcome_falls_back_when_system_template_disabled(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'welcome',
            'name' => 'Welcome',
            'subject' => 'Bienvenue {{ name }} !',
            'content_html' => '<h1>Bienvenue {{ name }} !</h1>',
            'enabled' => false,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Bienvenue sur Entraide !',
        ]);
    }

    public function test_system_email_view_renders_within_mail_layout(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'welcome',
            'name' => 'Welcome',
            'subject' => 'Bienvenue',
            'content_html' => '<p>Contenu personnalisé</p>',
            'enabled' => true,
        ]);

        $msg = (new WelcomeNotification)->toMail($this->user);
        $this->assertStringContainsString('emails.system-email', $msg->view);
        $this->assertStringContainsString('Contenu personnalisé', $msg->viewData['html']);
    }

    public function test_transaction_status_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'transaction_status_changed',
            'name' => 'Transaction Status',
            'subject' => 'Mise à jour — {{ status_label }}',
            'content_html' => '<p>Statut : {{ status_label }}</p>',
            'enabled' => true,
        ]);

        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);

        $this->user->notify(new TransactionStatusChanged($transaction));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Mise à jour — '.$transaction->status_label,
        ]);
    }

    public function test_transaction_status_falls_back_when_disabled(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'transaction_status_changed',
            'name' => 'Transaction Status',
            'subject' => 'Mise à jour — {{ status_label }}',
            'content_html' => '<p>Statut : {{ status_label }}</p>',
            'enabled' => false,
        ]);

        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);

        $this->user->notify(new TransactionStatusChanged($transaction));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
        ]);
        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertStringContainsString('Mise à jour de votre échange', $log->subject);
    }

    public function test_new_message_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'new_message',
            'name' => 'New Message',
            'subject' => 'Message de {{ sender_name }}',
            'content_html' => '<p>Message de {{ sender_name }} : {{ message_preview }}</p>',
            'enabled' => true,
        ]);

        $transaction = Transaction::factory()->create([
            'buyer_id' => $this->user->id,
        ]);
        $message = Message::factory()->create([
            'transaction_id' => $transaction->id,
        ]);

        $senderName = $message->sender?->name ?? 'Système';

        $this->user->notify(new NewMessageReceived($transaction, $message));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Message de '.$senderName,
        ]);
    }

    public function test_ai_budget_uses_system_template_when_enabled(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'ai_budget_exceeded',
            'name' => 'AI Budget',
            'subject' => 'Budget — {{ scenario_id }}',
            'content_html' => '<p>Coût : {{ current_cost }} / {{ budget_limit }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new AiBudgetExceeded(
            scenarioId: 'scen-42',
            currentCost: 50.0,
            budgetLimit: 100.0,
        ));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Budget — scen-42',
        ]);
    }

    public function test_ai_budget_falls_back_when_no_template(): void
    {
        $this->user->notify(new AiBudgetExceeded(
            scenarioId: 'scen-42',
            currentCost: 50.0,
            budgetLimit: 100.0,
        ));

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Alerte budget IA — scen-42',
        ]);
    }

    public function test_system_template_interpolates_all_core_vars(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'welcome',
            'name' => 'Welcome',
            'subject' => '{{ first_name }} {{ name }} {{ email }} {{ city }}',
            'content_html' => '<p>{{ first_name }} {{ name }} {{ email }} {{ city }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Alice Alice Martin alice@example.com Paris',
        ]);
    }

    public function test_unknown_vars_in_template_are_left_unchanged(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'welcome',
            'name' => 'Welcome',
            'subject' => 'Hello {{ unknown }}',
            'content_html' => '<p>{{ unknown }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $this->user->id,
            'subject' => 'Hello {{ unknown }}',
        ]);
    }

    public function test_html_in_template_content_is_escaped_properly(): void
    {
        SystemEmailTemplate::create([
            'slug' => 'welcome',
            'name' => 'Welcome',
            'subject' => 'Test',
            'content_html' => '<p>{{ first_name }}</p>',
            'enabled' => true,
        ]);

        $this->user->notify(new WelcomeNotification);

        $log = EmailLog::where('user_id', $this->user->id)->first();
        $this->assertSame('Test', $log->subject);
    }
}
