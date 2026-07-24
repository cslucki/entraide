<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class RegistrationEmailFailureTest extends TestCase
{
    private ?MailFactory $originalMailFactory = null;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::where('is_default', true)->update(['is_default' => false]);
    }

    public function test_normal_registration_creates_user_sends_welcome_email_and_authenticates(): void
    {
        Mail::fake();

        $organization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->post(route('register'), [
            'name' => 'Dupont',
            'first_name' => 'Alice',
            'email' => 'alice@example.com',
            'phone' => '+33612345678',
            'country_code' => 'FR',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('loops.index'));

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($organization->id, $user->organization_id);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'reason' => 'welcome_bonus',
            'delta' => 100,
        ]);

        $this->assertAuthenticated();
        $this->assertInstanceOf(User::class, auth()->user());
    }

    public function test_registration_succeeds_even_when_welcome_email_transport_fails(): void
    {
        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('Simulated transport failure — domain not verified'));

        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('mailer')
            ->andReturn($mailerMock);

        $this->app->instance('mail.manager', $factoryMock);

        $organization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
        ]);

        $response = $this->post(route('register'), [
            'name' => 'Martin',
            'first_name' => 'Bob',
            'email' => 'bob@example.com',
            'phone' => '+33698765432',
            'country_code' => 'FR',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect();

        $user = User::where('email', 'bob@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($organization->id, $user->organization_id);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'reason' => 'welcome_bonus',
            'delta' => 100,
        ]);

        $this->assertAuthenticated();
        $this->assertInstanceOf(User::class, auth()->user());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
