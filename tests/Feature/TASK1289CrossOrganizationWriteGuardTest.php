<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1289 — meme regle que T1288, trois ecritures de plus : on n'ecrit pas
 * dans l'Organization d'un autre.
 *
 * Sur la surface courte, `ResolveUrlOrganization` lie l'Organization PAR
 * DEFAUT a toute requete `/messages/...`, `/services`, `/transactions`, quel
 * que soit l'utilisateur connecte. Trois ecritures ne verifiaient pas que
 * l'acteur appartient a cette Organization resolue (audit T1287) :
 *
 *  1. `GET /messages/with/{user}` : un GET qui ecrit (Transaction + Message).
 *     Une garde existait mais comparait les DEUX UTILISATEURS entre eux,
 *     jamais a l'Organization resolue : deux membres d'une meme AUTRE
 *     Organization la franchissaient et ecrivaient chez l'org par defaut.
 *  2. `POST /services` : aucun controle d'appartenance de l'acteur.
 *  3. `POST /transactions` : la CIBLE (service / demande) etait verifiee
 *     contre l'Organization resolue (T07515), l'ACTEUR jamais.
 *
 * Preuves : l'etranger est refuse dans la forme naturelle de chaque
 * controleur (redirection + message pour messages/with, 404 pour services et
 * transactions — la forme de tenant deja en place dans ces deux controleurs)
 * et le refus n'ecrit RIEN ; le membre legitime passe toujours et rien ne
 * change pour lui. Sur messages/with, la garde utilisateur-contre-utilisateur
 * existante garde son sens propre et son comportement (redirection muette).
 */
#[Group('sensitive')]
class TASK1289CrossOrganizationWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrganization;

    private Organization $otherOrganization;

    private User $member;

    private User $otherMember;

    private User $stranger;

    private User $otherStranger;

    protected function setUp(): void
    {
        parent::setUp();

        // L'Organization PAR DEFAUT : celle que `ResolveUrlOrganization` lie a
        // toute requete de la surface courte (is_default = true).
        $this->defaultOrganization = Organization::factory()->create([
            'name' => 'BouclePro 1289',
            'slug' => 'bouclepro-1289',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->otherOrganization = Organization::factory()->create([
            'name' => 'Autre Organization 1289',
            'slug' => 'autre-org-1289',
            'is_active' => true,
            'is_default' => false,
        ]);

        // Profils COMPLETS (middleware `profile.complete` sur POST /services)
        // et verifies : les seuls middlewares des routes sont franchis, la
        // garde du controleur est la seule barriere.
        $this->member = $this->userOf($this->defaultOrganization);
        $this->otherMember = $this->userOf($this->defaultOrganization);
        $this->stranger = $this->userOf($this->otherOrganization);
        $this->otherStranger = $this->userOf($this->otherOrganization);
    }

    private function userOf(Organization $organization): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $organization->id,
            'is_admin' => false,
            'preferred_locale' => 'fr',
            'points_balance' => 200,
        ]);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count(), 'Un refus ne cree aucune transaction.');
        $this->assertSame(0, Message::query()->count(), 'Un refus ne cree aucun message.');
        $this->assertSame(0, Service::withoutGlobalScopes()->count(), 'Un refus ne cree aucun service.');
    }

    private function validServiceData(): array
    {
        $category = Category::factory()->create(['organization_id' => $this->defaultOrganization->id]);

        return [
            'title' => 'Service de test pour TASK-1289',
            'description' => str_repeat('Description longue du service de test pour la garde d\'appartenance. ', 3),
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 50,
        ];
    }

    private function serviceOfOtherMember(): Service
    {
        return Service::factory()->forUser($this->otherMember)->create([
            'organization_id' => $this->defaultOrganization->id,
        ]);
    }

    private function requestOfOtherMember(): ServiceRequest
    {
        return ServiceRequest::factory()->forUser($this->otherMember)->create([
            'organization_id' => $this->defaultOrganization->id,
        ]);
    }

    // =====================================================================
    // 1. GET /messages/with/{user}
    // =====================================================================

    /**
     * Le cas que la garde existante laissait passer : deux membres d'une meme
     * AUTRE Organization. Sans la comparaison a l'Organization resolue, la
     * Transaction et le Message etaient crees dans l'org par defaut.
     */
    public function test_messages_with_refuses_two_members_of_another_organization_and_writes_nothing(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('messages.with', $this->otherStranger))
            ->assertRedirect(route('messages.index'))
            ->assertSessionHas('error', trans('messages.cross_org', [], 'fr'));

        $this->assertNothingWritten();
    }

    /**
     * La garde utilisateur-contre-utilisateur existante garde son sens propre
     * (on ne demarre pas une conversation avec quelqu'un d'une autre
     * Organization) ET son comportement : redirection muette, rien d'ecrit.
     */
    public function test_messages_with_still_refuses_a_conversation_across_organizations_as_before(): void
    {
        $this->actingAs($this->member)
            ->get(route('messages.with', $this->stranger))
            ->assertRedirect(route('messages.index'))
            ->assertSessionMissing('error');

        $this->actingAs($this->stranger)
            ->get(route('messages.with', $this->member))
            ->assertRedirect(route('messages.index'));

        $this->assertNothingWritten();
    }

    public function test_messages_with_still_starts_a_direct_conversation_between_two_members(): void
    {
        $response = $this->actingAs($this->member)->get(route('messages.with', $this->otherMember));

        $transaction = Transaction::withoutGlobalScopes()->first();

        $this->assertNotNull($transaction);
        $this->assertTrue($transaction->isDirectConversation());
        $this->assertSame($this->defaultOrganization->id, $transaction->organization_id);
        $this->assertSame($this->member->id, $transaction->buyer_id);
        $this->assertSame($this->otherMember->id, $transaction->seller_id);

        $response->assertRedirect(route('messages.show', $transaction))
            ->assertSessionMissing('error');

        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('messages', [
            'transaction_id' => $transaction->id,
            'type' => 'system',
            'organization_id' => $this->defaultOrganization->id,
        ]);
    }

    // =====================================================================
    // 2. POST /services
    // =====================================================================

    /**
     * Forme du refus : `abort(404)`, la forme de tenant deja en place dans
     * ServiceController (show/edit/update/destroy et le `! $organization` de
     * `store` lui-meme) et dans ProfileController / ReportController.
     */
    public function test_services_store_refuses_a_member_of_another_organization_and_writes_nothing(): void
    {
        $this->actingAs($this->stranger)
            ->post(route('services.store'), $this->validServiceData())
            ->assertNotFound();

        $this->assertNothingWritten();
    }

    public function test_services_store_still_creates_the_service_of_a_member_in_the_default_organization(): void
    {
        $this->actingAs($this->member)
            ->post(route('services.store'), $this->validServiceData())
            ->assertRedirect(route('organization.dashboard.services', [$this->defaultOrganization]))
            ->assertSessionHas('success');

        $this->assertSame(1, Service::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('services', [
            'user_id' => $this->member->id,
            'organization_id' => $this->defaultOrganization->id,
            'status' => 'active',
        ]);
    }

    // =====================================================================
    // 3. POST /transactions
    // =====================================================================

    /**
     * Branche service : la cible est dans l'Organization resolue (T07515
     * l'exigeait deja), mais l'ACTEUR — futur buyer — n'y est pas.
     */
    public function test_transactions_store_refuses_a_foreign_buyer_on_a_service_of_the_default_organization(): void
    {
        $service = $this->serviceOfOtherMember();

        $this->actingAs($this->stranger)
            ->post(route('transactions.store'), [
                'service_id' => $service->id,
                'points_proposed' => 50,
            ])
            ->assertNotFound();

        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::query()->count());
    }

    /**
     * Branche demande : l'acteur devient le SELLER d'un membre de l'org par
     * defaut. Meme regle, meme forme.
     */
    public function test_transactions_store_refuses_a_foreign_seller_on_a_request_of_the_default_organization(): void
    {
        $serviceRequest = $this->requestOfOtherMember();

        $this->actingAs($this->stranger)
            ->post(route('transactions.store'), [
                'request_id' => $serviceRequest->id,
                'points_proposed' => 50,
            ])
            ->assertNotFound();

        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::query()->count());
        $this->assertSame('open', $serviceRequest->fresh()->status);
    }

    public function test_transactions_store_still_creates_the_exchange_of_a_member_on_a_service(): void
    {
        $service = $this->serviceOfOtherMember();

        $this->actingAs($this->member)
            ->post(route('transactions.store'), [
                'service_id' => $service->id,
                'points_proposed' => 50,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('transactions', [
            'service_id' => $service->id,
            'buyer_id' => $this->member->id,
            'seller_id' => $this->otherMember->id,
            'organization_id' => $this->defaultOrganization->id,
            'status' => 'pending',
        ]);
        $this->assertSame(1, Message::query()->where('type', 'system')->count());
    }

    public function test_transactions_store_still_creates_the_exchange_of_a_member_on_a_request(): void
    {
        $serviceRequest = $this->requestOfOtherMember();

        $this->actingAs($this->member)
            ->post(route('transactions.store'), [
                'request_id' => $serviceRequest->id,
                'points_proposed' => 50,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('transactions', [
            'request_id' => $serviceRequest->id,
            'buyer_id' => $this->otherMember->id,
            'seller_id' => $this->member->id,
            'organization_id' => $this->defaultOrganization->id,
            'status' => 'pending',
        ]);
        $this->assertSame('in_progress', $serviceRequest->fresh()->status);
    }
}
