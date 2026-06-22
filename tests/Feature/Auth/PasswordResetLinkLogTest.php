<?php

namespace Tests\Feature\Auth;

use App\Models\EmailLog;
use App\Models\User;
use Tests\TestCase;

class PasswordResetLinkLogTest extends TestCase
{
    public function test_forgot_password_with_existing_email_creates_email_log(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('email_logs', [
            'to_email' => $user->email,
            'subject' => 'Réinitialisation de votre mot de passe',
            'status' => 'sent',
            'user_id' => $user->id,
            'template_id' => null,
        ]);
    }

    public function test_forgot_password_log_data_contains_public_source_and_no_admin_id(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        $log = EmailLog::where('to_email', $user->email)->first();
        $this->assertNotNull($log);

        $data = $log->data;
        $this->assertSame('public-password-reset', $data['source']);
        $this->assertSame('users', $data['broker']);
        $this->assertArrayNotHasKey('admin_id', $data);
    }

    public function test_forgot_password_log_data_does_not_contain_token_url_or_body(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        $log = EmailLog::where('to_email', $user->email)->first();
        $this->assertNotNull($log);

        $data = $log->data;
        $dataJson = json_encode($data);
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('url', $data);
        $this->assertArrayNotHasKey('body', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('admin_id', $data);
        $this->assertStringNotContainsString('reset-password', $dataJson);
    }

    public function test_forgot_password_with_unknown_email_does_not_create_log(): void
    {
        $this->post(route('password.email'), ['email' => 'unknown@example.com']);

        $this->assertDatabaseMissing('email_logs', [
            'to_email' => 'unknown@example.com',
        ]);
    }
}
