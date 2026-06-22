<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmailLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_can_view_email_logs_list()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.email-logs.index');
        $response->assertViewHas('stats');
    }

    public function test_non_admin_cannot_view_email_logs_list()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('admin.email-logs'));

        $response->assertStatus(403);
    }

    public function test_admin_can_view_email_log_details()
    {
        $log = EmailLog::factory()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs.show', $log));

        $response->assertStatus(200);
        $response->assertViewHas('emailLog', $log);
    }

    public function test_admin_can_filter_logs_by_status()
    {
        EmailLog::factory()->create(['status' => 'sent']);
        EmailLog::factory()->create(['status' => 'failed']);
        EmailLog::factory()->create(['status' => 'sent']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs', ['status' => 'sent']));

        $response->assertStatus(200);
        $logs = $response->viewData('logs');
        $this->assertCount(2, $logs);
        $logs->each(fn ($log) => $this->assertEquals('sent', $log->status));
    }

    public function test_admin_can_filter_logs_by_search()
    {
        EmailLog::factory()->create(['to_email' => 'john@example.com']);
        EmailLog::factory()->create(['to_email' => 'jane@example.com']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs', ['search' => 'john']));

        $response->assertStatus(200);
        $logs = $response->viewData('logs');
        $this->assertCount(1, $logs);
        $this->assertEquals('john@example.com', $logs->first()->to_email);
    }

    public function test_stats_are_calculated_correctly()
    {
        EmailLog::factory()->count(5)->create(['status' => 'sent']);
        EmailLog::factory()->count(2)->create(['status' => 'failed']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs'));

        $response->assertStatus(200);
        $stats = $response->viewData('stats');
        $this->assertEquals(7, $stats['total']);
        $this->assertEquals(5, $stats['sent']);
        $this->assertEquals(2, $stats['failed']);
    }

    public function test_email_log_belongs_to_template()
    {
        $template = EmailTemplate::factory()->create();
        $log = EmailLog::factory()->create(['template_id' => $template->id]);

        $this->assertInstanceOf(EmailTemplate::class, $log->template);
        $this->assertEquals($template->id, $log->template->id);
    }

    public function test_email_log_belongs_to_user()
    {
        $user = User::factory()->create();
        $log = EmailLog::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $log->user);
        $this->assertEquals($user->id, $log->user->id);
    }

    public function test_logs_are_ordered_by_created_at_desc()
    {
        $log1 = EmailLog::factory()->create(['created_at' => now()->subHours(2)]);
        $log2 = EmailLog::factory()->create(['created_at' => now()->subHours(1)]);
        $log3 = EmailLog::factory()->create(['created_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs'));

        $logs = $response->viewData('logs');
        $this->assertEquals($log3->id, $logs->first()->id);
        $this->assertEquals($log1->id, $logs->last()->id);
    }

    public function test_logs_are_paginated()
    {
        EmailLog::factory()->count(35)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs'));

        $logs = $response->viewData('logs');
        $this->assertCount(30, $logs);
        $this->assertTrue($logs->hasMorePages());
    }

    public function test_email_log_with_error_message_displays_correctly()
    {
        $log = EmailLog::factory()->create([
            'status' => 'failed',
            'error_message' => 'SMTP connection failed',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.email-logs.show', $log));

        $response->assertStatus(200);
        $response->assertSee('SMTP connection failed');
    }
}
