<?php

namespace Tests\Unit;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailerServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmailerService::class);
    }

    public function test_available_variables_returns_expected_keys()
    {
        $user = User::factory()->create([
            'first_name' => 'Jean',
            'name' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'city' => 'Paris',
        ]);

        $vars = $this->service->availableVariables($user);

        $this->assertEquals('Jean', $vars['first_name']);
        $this->assertEquals('Jean Dupont', $vars['name']);
        $this->assertEquals('jean@example.com', $vars['email']);
        $this->assertEquals('Paris', $vars['city']);
        $this->assertArrayHasKey('organization', $vars);
    }

    public function test_interpolates_all_allowed_variables()
    {
        $content = 'Bonjour {{ first_name }} ({{ name }}), votre email est {{ email }}. Ville: {{ city }}, Org: {{ organization }}.';
        $vars = [
            'first_name' => 'Marie',
            'name' => 'Marie Curie',
            'email' => 'marie@example.com',
            'city' => 'Varsovie',
            'organization' => 'Labo',
        ];

        $result = $this->service->interpolate($content, $vars);

        $this->assertStringContainsString('Bonjour Marie (Marie Curie)', $result);
        $this->assertStringContainsString('marie@example.com', $result);
        $this->assertStringContainsString('Varsovie', $result);
        $this->assertStringContainsString('Labo', $result);
    }

    public function test_unknown_variable_is_left_unchanged()
    {
        $content = 'Bonjour {{ first_name }}, votre {{ unknown_var }} est intéressant.';
        $vars = ['first_name' => 'Paul'];

        $result = $this->service->interpolate($content, $vars);

        $this->assertStringContainsString('Bonjour Paul', $result);
        $this->assertStringContainsString('{{ unknown_var }}', $result);
    }

    public function test_user_values_are_html_escaped()
    {
        $content = 'Nom: {{ name }}';
        $vars = ['name' => '<script>alert("xss")</script>'];

        $result = $this->service->interpolate($content, $vars);

        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function test_interpolate_subject_returns_plain_text()
    {
        $subject = 'Bonjour {{ first_name }}';
        $vars = ['first_name' => '<b>Test</b>'];

        $result = $this->service->interpolateSubject($subject, $vars);

        $this->assertEquals('Bonjour Test', $result);
    }

    public function test_send_from_template_creates_email_log_with_sent_status()
    {
        Mail::fake();

        $template = EmailTemplate::factory()->create([
            'subject' => 'Bonjour {{ first_name }}',
            'content_html' => '<p>Bonjour {{ first_name }}, bienvenue !</p>',
        ]);
        $user = User::factory()->create([
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
        ]);

        $log = $this->service->sendFromTemplate($template, $user);

        $this->assertDatabaseHas('email_logs', [
            'id' => $log->id,
            'template_id' => $template->id,
            'user_id' => $user->id,
            'to_email' => 'alice@example.com',
            'subject' => 'Bonjour Alice',
            'status' => 'sent',
        ]);
        $this->assertNull($log->error_message);
    }

    public function test_send_failure_creates_email_log_with_failed_status()
    {
        Mail::shouldReceive('html')->andThrow(new \Exception('SMTP error'));

        $template = EmailTemplate::factory()->create([
            'subject' => 'Test',
            'content_html' => '<p>Test</p>',
        ]);
        $user = User::factory()->create(['email' => 'bob@example.com']);

        $log = $this->service->sendFromTemplate($template, $user);

        $this->assertEquals('failed', $log->status);
        $this->assertEquals('SMTP error', $log->error_message);
    }
}
