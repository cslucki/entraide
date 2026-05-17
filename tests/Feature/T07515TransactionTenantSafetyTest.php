<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
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
            'community_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create(['community_id' => $organizationB->id]);
        $service = Service::factory()->forUser($seller)->create([
            'community_id' => $organizationB->id,
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
            'community_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create();

        // Création explicite d'un Service tenantless (community_id null)
        // pour simuler une faille fixture/legacy. Bypass du sync trait.
        $service = Service::factory()->forUser($seller)->create();
        $service->forceFill(['community_id' => null, 'organization_id' => null])->saveQuietly();

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
            'community_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $seller = User::factory()->create(['community_id' => $this->testOrganization->id]);
        $service = Service::factory()->forUser($seller)->create([
            'community_id' => $this->testOrganization->id,
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
            'community_id' => $this->testOrganization->id,
            'status' => 'pending',
        ]);

        $transaction = Transaction::where('buyer_id', $buyer->id)->first();
        $this->assertNotNull($transaction);
        $this->assertNotNull($transaction->community_id);
    }

    public function test_web_transaction_store_rejects_service_request_outside_resolved_organization(): void
    {
        $organizationB = Organization::factory()->create(['is_active' => true]);

        $seller = User::factory()->create([
            'community_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $requester = User::factory()->create(['community_id' => $organizationB->id]);
        $serviceRequest = ServiceRequest::factory()->forUser($requester)->create([
            'community_id' => $organizationB->id,
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
            'community_id' => $this->testOrganization->id,
            'points_balance' => 200,
        ]);
        $requester = User::factory()->create();

        // ServiceRequest explicitement tenantless pour simuler une faille fixture/legacy.
        // Bypass du sync trait via forceFill + saveQuietly.
        $serviceRequest = ServiceRequest::factory()->forUser($requester)->create();
        $serviceRequest->forceFill(['community_id' => null, 'organization_id' => null])->saveQuietly();

        $response = $this->actingAs($seller)->post(route('transactions.store'), [
            'request_id' => $serviceRequest->id,
            'points_proposed' => 50,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('transactions', [
            'request_id' => $serviceRequest->id,
        ]);
    }
}
