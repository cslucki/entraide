<?php

namespace Tests\Feature;

use App\Models\PointLedger;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_buyer_can_create_transaction_on_service(): void
    {
        $seller = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $service = Service::factory()->forUser($seller)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $response = $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertSessionHasNoErrors('error');
        $this->assertDatabaseHas('transactions', [
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'pending',
        ]);
    }

    public function test_cannot_create_transaction_with_insufficient_points(): void
    {
        $seller = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 10,
        ]);
        $service = Service::factory()->forUser($seller)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $response = $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertSessionHas('error');
    }

    public function test_cannot_create_transaction_with_yourself(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $response = $this->actingAs($user)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertSessionHas('error');
    }

    public function test_seller_can_approve_transaction(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($seller)->patch(route('transactions.approve', $transaction));
        $response->assertRedirect();
        $this->assertEquals('accepted', $transaction->fresh()->status);
    }

    public function test_seller_can_refuse_transaction(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($seller)->patch(route('transactions.refuse', $transaction));
        $this->assertEquals('refused', $transaction->fresh()->status);
    }

    public function test_buyer_can_complete_transaction(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'accepted',
            'points_agreed' => 50,
        ]);

        $response = $this->actingAs($buyer)->patch(route('transactions.complete', $transaction));
        $this->assertEquals('buyer_done', $transaction->fresh()->status);
    }

    public function test_cannot_approve_if_not_seller(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($buyer)->patch(route('transactions.approve', $transaction));
        $response->assertForbidden();
    }

    // --- Email failure resilience tests (TASK-1045) ---

    private function mockMailTransportFailure(int $times = 1): void
    {
        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')
            ->times($times)
            ->andThrow(new TransportException('Simulated transport failure'));

        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('mailer')
            ->andReturn($mailerMock);

        $this->app->instance('mail.manager', $factoryMock);
    }

    public function test_approve_succeeds_even_when_notification_fails(): void
    {
        Log::spy();

        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $this->mockMailTransportFailure();

        $response = $this->actingAs($seller)->patch(route('transactions.approve', $transaction));
        $response->assertRedirect();
        $this->assertEquals('accepted', $transaction->fresh()->status);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($transaction, $buyer) {
                return $message === 'Email notification failed after successful business action'
                    && $context['event'] === 'transaction_approved'
                    && $context['transaction_id'] === $transaction->id
                    && $context['notifiable_id'] === $buyer->id
                    && $context['exception_class'] === TransportException::class;
            });
    }

    public function test_refuse_succeeds_even_when_notification_fails(): void
    {
        Log::spy();

        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $this->mockMailTransportFailure();

        $this->actingAs($seller)->patch(route('transactions.refuse', $transaction));
        $this->assertEquals('refused', $transaction->fresh()->status);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($transaction, $buyer) {
                return $message === 'Email notification failed after successful business action'
                    && $context['event'] === 'transaction_refused'
                    && $context['transaction_id'] === $transaction->id
                    && $context['notifiable_id'] === $buyer->id
                    && $context['exception_class'] === TransportException::class;
            });
    }

    public function test_complete_succeeds_even_when_notification_fails(): void
    {
        Log::spy();

        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'accepted',
            'points_agreed' => 50,
        ]);

        $this->mockMailTransportFailure();

        $this->actingAs($buyer)->patch(route('transactions.complete', $transaction));
        $this->assertEquals('buyer_done', $transaction->fresh()->status);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($transaction, $seller) {
                return $message === 'Email notification failed after successful business action'
                    && $context['event'] === 'transaction_completed_by_buyer'
                    && $context['transaction_id'] === $transaction->id
                    && $context['notifiable_id'] === $seller->id
                    && $context['exception_class'] === TransportException::class;
            });
    }

    public function test_confirm_succeeds_even_when_buyer_notification_fails(): void
    {
        Log::spy();

        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 100,
        ]);

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'buyer_done',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now(),
        ]);

        // First send (buyer) fails, second send (seller) succeeds
        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('Simulated transport failure for buyer'));
        $mailerMock->shouldReceive('send')
            ->once()
            ->andReturn(null); // seller succeeds

        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('mailer')
            ->andReturn($mailerMock);

        $this->app->instance('mail.manager', $factoryMock);

        $response = $this->actingAs($seller)->patch(route('transactions.confirm', $transaction));
        $response->assertRedirect();
        $this->assertEquals('completed', $transaction->fresh()->status);

        $this->assertDatabaseHas('point_ledger', [
            'transaction_id' => $transaction->id,
            'user_id' => $buyer->id,
            'delta' => -50,
        ]);
        $this->assertDatabaseHas('point_ledger', [
            'transaction_id' => $transaction->id,
            'user_id' => $seller->id,
            'delta' => 50,
        ]);

        $this->assertEquals(150, $buyer->fresh()->points_balance);
        $this->assertEquals(150, $seller->fresh()->points_balance);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($transaction, $buyer) {
                return $message === 'Email notification failed after successful business action'
                    && $context['event'] === 'transaction_confirmed_buyer_notification'
                    && $context['transaction_id'] === $transaction->id
                    && $context['notifiable_id'] === $buyer->id
                    && $context['exception_class'] === TransportException::class;
            });
    }

    public function test_confirm_succeeds_even_when_seller_notification_fails(): void
    {
        Log::spy();

        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 100,
        ]);

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'buyer_done',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now(),
        ]);

        // Fail on second send (seller), not first (buyer)
        $mailerMock = Mockery::mock(MailerContract::class);
        $mailerMock->shouldReceive('send')
            ->once()
            ->andReturn(null); // buyer succeeds
        $mailerMock->shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('Simulated transport failure for seller'));

        $factoryMock = Mockery::mock(MailFactory::class);
        $factoryMock->shouldReceive('mailer')
            ->andReturn($mailerMock);

        $this->app->instance('mail.manager', $factoryMock);

        $response = $this->actingAs($seller)->patch(route('transactions.confirm', $transaction));
        $response->assertRedirect();
        $this->assertEquals('completed', $transaction->fresh()->status);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($transaction, $seller) {
                return $message === 'Email notification failed after successful business action'
                    && $context['event'] === 'transaction_confirmed_seller_notification'
                    && $context['transaction_id'] === $transaction->id
                    && $context['notifiable_id'] === $seller->id
                    && $context['exception_class'] === TransportException::class;
            });
    }

    public function test_confirm_succeeds_even_when_both_notifications_fail(): void
    {
        Log::spy();

        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 100,
        ]);

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'buyer_done',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now(),
        ]);

        $this->mockMailTransportFailure(2);

        $response = $this->actingAs($seller)->patch(route('transactions.confirm', $transaction));
        $response->assertRedirect();
        $this->assertEquals('completed', $transaction->fresh()->status);

        Log::shouldHaveReceived('error')
            ->twice()
            ->withArgs(function (string $message, array $context) use ($transaction, $buyer, $seller) {
                return $message === 'Email notification failed after successful business action'
                    && in_array($context['event'], ['transaction_confirmed_buyer_notification', 'transaction_confirmed_seller_notification'])
                    && $context['transaction_id'] === $transaction->id
                    && in_array($context['notifiable_id'], [$buyer->id, $seller->id])
                    && $context['exception_class'] === TransportException::class;
            });
    }

    public function test_confirm_does_not_create_duplicate_ledger_on_notification_failure(): void
    {
        Log::spy();

        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 100,
        ]);

        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'buyer_done',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now(),
        ]);

        $this->mockMailTransportFailure(2);

        $this->actingAs($seller)->patch(route('transactions.confirm', $transaction));

        $buyerLedgers = PointLedger::where('transaction_id', $transaction->id)
            ->where('user_id', $buyer->id)
            ->count();
        $sellerLedgers = PointLedger::where('transaction_id', $transaction->id)
            ->where('user_id', $seller->id)
            ->count();

        $this->assertEquals(1, $buyerLedgers, 'Buyer should have exactly 1 PointLedger entry');
        $this->assertEquals(1, $sellerLedgers, 'Seller should have exactly 1 PointLedger entry');
    }
}
