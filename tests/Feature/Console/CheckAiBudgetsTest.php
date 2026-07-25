<?php

namespace Tests\Feature\Console;

use App\Models\AdminAiInteraction;
use App\Models\User;
use App\Notifications\AiBudgetExceeded;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class CheckAiBudgetsTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeInteraction(array $overrides = []): AdminAiInteraction
    {
        return AdminAiInteraction::create(array_merge([
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'success',
            'input_excerpt' => 'Test excerpt',
            'input_hash' => hash('sha256', 'Test excerpt'),
            'input_length' => 12,
            'result_summary' => 'Test summary',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 1200,
            'cost_usd' => 0.0015,
        ], $overrides));
    }

    public function test_under_threshold_no_alert(): void
    {
        Notification::fake();
        $this->makeAdmin();

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_over_threshold_sends_alert(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 6.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutputToContain('supervision_content');

        Notification::assertSentTo(
            $admin,
            AiBudgetExceeded::class,
            function (AiBudgetExceeded $notification) {
                return $notification->scenarioId === 'supervision_content'
                    && $notification->currentCost === 6.00
                    && $notification->budgetLimit === 5.00;
            }
        );
    }

    public function test_no_duplicate_alert_same_month(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $cacheKey = 'ai_budget_alert_supervision_content_'.now()->format('Y_m');
        Cache::put($cacheKey, true, now()->endOfMonth());

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_multiple_scenarios(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();

        $this->makeInteraction(['scenario_id' => 'supervision_content', 'cost_usd' => 10.00]);
        $this->makeInteraction(['scenario_id' => 'clarify_help_request', 'cost_usd' => 5.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        Notification::assertSentTo(
            $admin,
            AiBudgetExceeded::class,
            2
        );
    }

    public function test_no_alert_when_no_admins(): void
    {
        Notification::fake();
        User::factory()->create(['is_admin' => false]);

        $this->makeInteraction(['cost_usd' => 10.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_skips_scenarios_with_zero_budget(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $this->makeInteraction(['scenario_id' => 'supervision_content', 'cost_usd' => 10.00]);

        config(['ai.budget_alerts' => []]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_sum_respects_current_month_only(): void
    {
        Notification::fake();
        $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 3.00, 'created_at' => now()->subMonth()]);
        $this->makeInteraction(['cost_usd' => 1.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_ignores_non_success_interactions(): void
    {
        Notification::fake();
        $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 10.00, 'status' => 'failed']);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }

    public function test_alert_sent_to_all_admins(): void
    {
        Notification::fake();
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();

        $this->makeInteraction(['cost_usd' => 6.00]);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        Notification::assertSentTo(
            [$admin1, $admin2],
            AiBudgetExceeded::class
        );
    }

    public function test_command_continues_when_first_admin_notification_fails(): void
    {
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $sendCount = 0;

        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')->andReturnUsing(function () use (&$sendCount) {
            $sendCount++;
            if ($sendCount === 1) {
                throw new TransportException('SMTP connection refused');
            }

            return null;
        });

        $mailFactoryMock = Mockery::mock(MailFactory::class);
        $mailFactoryMock->shouldReceive('mailer')->andReturn($mailerMock);
        Mail::swap($mailFactoryMock);

        $cacheKey = 'ai_budget_alert_supervision_content_'.now()->format('Y_m');

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_subsequent_admin_still_notified_when_first_fails(): void
    {
        $admin1 = $this->makeAdmin();
        $admin2 = $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $sendCount = 0;
        $notifiedUserIds = [];

        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')->andReturnUsing(function ($view, $data) use (&$sendCount, &$notifiedUserIds) {
            $sendCount++;
            if ($sendCount === 1) {
                throw new TransportException('SMTP connection refused');
            }
            $notifiedUserIds[] = $data['message']->getTo()[0]->getAddress();

            return null;
        });

        $mailFactoryMock = Mockery::mock(MailFactory::class);
        $mailFactoryMock->shouldReceive('mailer')->andReturn($mailerMock);
        Mail::swap($mailFactoryMock);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        $this->assertSame(2, $sendCount);
    }

    public function test_command_ends_with_success_despite_smtp_failure(): void
    {
        $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')->andThrow(new TransportException('SMTP connection refused'));

        $mailFactoryMock = Mockery::mock(MailFactory::class);
        $mailFactoryMock->shouldReceive('mailer')->andReturn($mailerMock);
        Mail::swap($mailFactoryMock);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();
    }

    public function test_logs_error_on_notification_failure(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        Log::spy();

        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')->andThrow(new TransportException('SMTP connection refused'));

        $mailFactoryMock = Mockery::mock(MailFactory::class);
        $mailFactoryMock->shouldReceive('mailer')->andReturn($mailerMock);
        Mail::swap($mailFactoryMock);

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        Log::shouldHaveReceived('error')
            ->with('ai_budget_alert_notification_failed', Mockery::on(function (array $context) use ($admin) {
                return $context['event'] === 'ai_budget_alert_notification_failed'
                    && $context['scenario_id'] === 'supervision_content'
                    && $context['admin_id'] === $admin->id
                    && $context['organization_id'] === $admin->organization_id
                    && $context['exception_class'] === TransportException::class;
            }));
    }

    public function test_log_context_contains_no_sensitive_data(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $loggedContext = null;

        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')->andThrow(new TransportException('SMTP connection refused'));

        $mailFactoryMock = Mockery::mock(MailFactory::class);
        $mailFactoryMock->shouldReceive('mailer')->andReturn($mailerMock);
        Mail::swap($mailFactoryMock);

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedContext) {
            if ($event->message === 'ai_budget_alert_notification_failed') {
                $loggedContext = $event->context;
            }
        });

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        $this->assertNotNull($loggedContext);

        $forbidden = ['email', 'name', 'password', 'api_key', 'token', 'secret', 'key', 'auth', 'credential', 'smtp', 'prompt', 'response'];
        foreach ($forbidden as $field) {
            $serialized = json_encode($loggedContext);
            $this->assertStringNotContainsStringIgnoringCase($field, (string) $serialized, "Sensitive field '{$field}' found in log context");
        }
    }

    public function test_cache_still_set_after_partial_notification_failure(): void
    {
        $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $sendCount = 0;

        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')->andReturnUsing(function () use (&$sendCount) {
            $sendCount++;
            if ($sendCount === 1) {
                throw new TransportException('SMTP connection refused');
            }

            return null;
        });

        $mailFactoryMock = Mockery::mock(MailFactory::class);
        $mailFactoryMock->shouldReceive('mailer')->andReturn($mailerMock);
        Mail::swap($mailFactoryMock);

        $cacheKey = 'ai_budget_alert_supervision_content_'.now()->format('Y_m');

        $this->assertFalse(Cache::has($cacheKey));

        $this->artisan('ai:check-budgets')
            ->assertSuccessful();

        $this->assertTrue(Cache::has($cacheKey), 'Cache should be set even after notification failure');
    }

    public function test_cached_alert_still_skipped_with_rescue_wrapper(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $this->makeInteraction(['cost_usd' => 6.00]);

        $cacheKey = 'ai_budget_alert_supervision_content_'.now()->format('Y_m');
        Cache::put($cacheKey, true, now()->endOfMonth());

        $this->artisan('ai:check-budgets')
            ->assertSuccessful()
            ->expectsOutput('All AI budgets are within limits.');

        Notification::assertNothingSent();
    }
}
