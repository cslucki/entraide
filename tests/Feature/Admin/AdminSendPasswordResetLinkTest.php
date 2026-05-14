<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminSendPasswordResetLinkTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_can_send_password_reset_link(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.send-password-reset', $user))
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_non_admin_receives_403(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.users.send-password-reset', $target))
            ->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $user = User::factory()->create();

        $this->post(route('admin.users.send-password-reset', $user))
            ->assertRedirect(route('login'));
    }

    public function test_token_is_created_in_password_reset_tokens(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.send-password-reset', $user))
            ->assertRedirect();

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);
    }

    public function test_response_does_not_contain_token(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.users.send-password-reset', $user));

        $response->assertRedirect();
        $response->assertSessionMissing('token');

        $content = $response->baseResponse->getSession()->all();
        $this->assertArrayNotHasKey('token', $content);

        $this->assertStringNotContainsString(
            'reset-password/',
            $response->baseResponse->getContent() ?: ''
        );
    }

    public function test_reset_password_notification_is_sent(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.send-password-reset', $user))
            ->assertRedirect();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function ($notification, $channels) {
                return in_array('mail', $channels);
            }
        );
    }
}
