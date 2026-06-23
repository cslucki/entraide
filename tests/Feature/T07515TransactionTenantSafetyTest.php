<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

/**
 * T075.15 — Transaction tenant safety guard
 *
 * Vérifie que TransactionController::store refuse les cibles
 * tenantless ou appartenant à une autre Organization que celle résolue.
 */
class T07515TransactionTenantSafetyTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_web_transaction_store_rejects_service_outside_resolved_organization(): void
    {
        $organizationB = Organization::factory()->create(['is_active' => true]);

        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create(['organization_id' => $organizationB->id]);
        $service = Service::factory()->forUser($seller)->create([
            'organization_id' => $organizationB->id,
        ]);

        $response = $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('transactions', [
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
        ]);
    }

    public function test_web_transaction_store_rejects_tenantless_service(): void
    {
        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create();

        // Création explicite d'un Service tenantless (community_id null)
        // pour simuler une faille fixture/legacy. Bypass du sync trait.
        $service = Service::factory()->forUser($seller)->create();
        $service->forceFill(['organization_id' => null, 'organization_id' => null])->saveQuietly();

        $response = $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('transactions', [
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
        ]);
    }

    public function test_web_transaction_store_creates_transaction_when_service_matches_resolved_organization(): void
    {
        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $service = Service::factory()->forUser($seller)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $response = $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('transactions', [
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'organization_id' => $this->testOrganization->id,
            'status' => 'pending',
        ]);

        $transaction = Transaction::where('buyer_id', $buyer->id)->first();
        $this->assertNotNull($transaction);
        $this->assertNotNull($transaction->organization_id);
    }

    public function test_web_transaction_store_rejects_service_request_outside_resolved_organization(): void
    {
        $organizationB = Organization::factory()->create(['is_active' => true]);

        $seller = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $requester = User::factory()->create(['organization_id' => $organizationB->id]);
        $serviceRequest = ServiceRequest::factory()->forUser($requester)->create([
            'organization_id' => $organizationB->id,
        ]);

        $response = $this->actingAs($seller)->post(route('transactions.store'), [
            'request_id' => $serviceRequest->id,
            'points_proposed' => 50,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('transactions', [
            'request_id' => $serviceRequest->id,
        ]);
    }

    public function test_web_transaction_store_rejects_tenantless_service_request(): void
    {
        $seller = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $requester = User::factory()->create();

        // ServiceRequest explicitement tenantless pour simuler une faille fixture/legacy.
        // Bypass du sync trait via forceFill + saveQuietly.
        $serviceRequest = ServiceRequest::factory()->forUser($requester)->create();
        $serviceRequest->forceFill(['organization_id' => null, 'organization_id' => null])->saveQuietly();

        $response = $this->actingAs($seller)->post(route('transactions.store'), [
            'request_id' => $serviceRequest->id,
            'points_proposed' => 50,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('transactions', [
            'request_id' => $serviceRequest->id,
        ]);
    }

    public function test_organization_transaction_store_redirects_to_organization_messages(): void
    {
        $buyer = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $service = Service::factory()->forUser($seller)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $response = $this->actingAs($buyer)->post(route('organization.transactions.store', [
            'organization' => $this->testOrganization->slug,
        ]), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $transaction = Transaction::where('buyer_id', $buyer->id)->firstOrFail();

        $response->assertRedirect(route('organization.messages.show', [
            'organization' => $this->testOrganization->slug,
            'transaction' => $transaction,
        ]));

        $this->assertSame($this->testOrganization->id, $transaction->organization_id);
    }

    public function test_organization_messages_index_filters_transactions_to_current_organization(): void
    {
        $organizationB = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $otherA = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $otherB = User::factory()->create(['organization_id' => $organizationB->id]);
        $serviceA = Service::factory()->forUser($otherA)->create([
            'organization_id' => $this->testOrganization->id,
            'title' => 'Visible org A service',
        ]);
        $serviceB = Service::factory()->forUser($otherB)->create([
            'organization_id' => $organizationB->id,
            'title' => 'Hidden org B service',
        ]);

        Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $serviceA->id,
            'buyer_id' => $user->id,
            'seller_id' => $otherA->id,
            'points_proposed' => 10,
            'status' => 'pending',
        ]);
        Transaction::withoutGlobalScopes()->create([
            'organization_id' => $organizationB->id,
            'service_id' => $serviceB->id,
            'buyer_id' => $user->id,
            'seller_id' => $otherB->id,
            'points_proposed' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('organization.messages.index', [
            'organization' => $this->testOrganization->slug,
        ]));

        $response->assertOk();
        $response->assertSee('Visible org A service');
        $response->assertDontSee('Hidden org B service');
    }

    public function test_message_thread_livewire_refresh_restores_organization_context(): void
    {
        $buyer = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $seller = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $service = Service::factory()->forUser($seller)->create([
            'organization_id' => $this->testOrganization->id,
        ]);
        $transaction = Transaction::create([
            'organization_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 10,
            'status' => 'pending',
        ]);

        Message::create([
            'organization_id' => $this->testOrganization->id,
            'transaction_id' => $transaction->id,
            'sender_id' => $seller->id,
            'body' => 'Message visible dans la boucle',
            'type' => 'user',
        ]);

        app()->forgetInstance('current_organization');

        Livewire::actingAs($buyer)
            ->test(MessageThread::class, ['transaction' => $transaction])
            ->assertOk()
            ->assertSee('Message visible dans la boucle')
            ->call('$refresh')
            ->assertOk()
            ->assertSee('Message visible dans la boucle');
    }

    public function test_organization_report_request_stores_report_in_current_organization(): void
    {
        $reporter = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $requester = User::factory()->create(['organization_id' => $this->testOrganization->id]);
        $serviceRequest = ServiceRequest::factory()->forUser($requester)->create([
            'organization_id' => $this->testOrganization->id,
        ]);

        $response = $this->actingAs($reporter)->post(route('organization.reports.request', [
            'organization' => $this->testOrganization->slug,
            'serviceRequest' => $serviceRequest,
        ]), [
            'reason' => 'Spam',
            'details' => 'Signalement de test',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('reports', [
            'reporter_id' => $reporter->id,
            'reportable_type' => ServiceRequest::class,
            'reportable_id' => $serviceRequest->id,
            'organization_id' => $this->testOrganization->id,
        ]);
        $this->assertSame(1, Report::count());
    }
}
