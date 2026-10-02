<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1634 — la suppression SuperAdmin d'une Organization est ATOMIQUE.
 *
 * `AdminOrganizationController::destroy()` detache quatre relations
 * (`users`, `services`, `service_requests`, `transactions`) puis supprime
 * physiquement l'Organization. Sans transaction, ces cinq ecritures sont
 * cinq autocommits : une panne entre le dernier UPDATE et le DELETE laisse
 * l'Organization en place avec ses donnees deja detachees — du legacy
 * silencieux qu'aucun ecran ne signale.
 *
 * L'echec est provoque par un listener `deleting` sur le modele. Ce n'est pas
 * une doublure du controleur : `Model::delete()` emet `deleting` APRES les
 * quatre UPDATE et AVANT le `DELETE` SQL, exactement dans la fenetre de risque
 * auditee. La transaction n'est ni simulee ni contournee — le rollback qui
 * rend ces tests verts est celui du moteur.
 */
class TASK1634OrganizationDeleteTransactionTest extends TestCase
{
    private const FAILURE_MESSAGE = 'TASK-1634 panne simulee entre les UPDATE et le DELETE';

    /**
     * Peuple une Organization avec une ligne dans chacune des quatre tables
     * que `destroy()` detache, et rend les identifiants.
     *
     * @return array{organization: Organization, user: User, service: Service, serviceRequest: ServiceRequest, transaction: Transaction}
     */
    private function organizationWithAllFourRelations(): array
    {
        $organization = Organization::factory()->create();

        return [
            'organization' => $organization,
            'user' => User::factory()->create(['organization_id' => $organization->id]),
            'service' => Service::factory()->create(['organization_id' => $organization->id]),
            'serviceRequest' => ServiceRequest::factory()->create(['organization_id' => $organization->id]),
            'transaction' => Transaction::factory()->create(['organization_id' => $organization->id]),
        ];
    }

    /**
     * Asserte que les quatre relations pointent toujours vers l'Organization.
     *
     * @param  array{organization: Organization, user: User, service: Service, serviceRequest: ServiceRequest, transaction: Transaction}  $fixture
     */
    private function assertStillAttached(array $fixture): void
    {
        $organizationId = $fixture['organization']->id;

        $this->assertDatabaseHas('users', ['id' => $fixture['user']->id, 'organization_id' => $organizationId]);
        $this->assertDatabaseHas('services', ['id' => $fixture['service']->id, 'organization_id' => $organizationId]);
        $this->assertDatabaseHas('service_requests', ['id' => $fixture['serviceRequest']->id, 'organization_id' => $organizationId]);
        $this->assertDatabaseHas('transactions', ['id' => $fixture['transaction']->id, 'organization_id' => $organizationId]);
    }

    public function test_une_panne_avant_le_delete_ne_laisse_aucune_relation_detachee(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $fixture = $this->organizationWithAllFourRelations();

        // Fenetre de risque : `deleting` est emis apres les quatre UPDATE,
        // avant le DELETE SQL.
        Organization::deleting(function (): void {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)
                ->delete(route('admin.organizations.destroy', $fixture['organization']));

            $this->fail('La panne simulee aurait du interrompre la suppression.');
        } catch (RuntimeException $exception) {
            $this->assertSame(self::FAILURE_MESSAGE, $exception->getMessage());
        }

        // Contrat d'atomicite : aucun etat intermediaire observable.
        $this->assertDatabaseHas('organizations', ['id' => $fixture['organization']->id]);
        $this->assertStillAttached($fixture);
    }

    public function test_une_panne_avant_le_delete_laisse_la_transaction_ouverte_intacte(): void
    {
        // Garde de forme : le rollback doit rendre la connexion a un niveau de
        // transaction inchange. Une transaction jamais refermee empoisonnerait
        // la requete suivante.
        $admin = User::factory()->create(['is_admin' => true]);
        $fixture = $this->organizationWithAllFourRelations();

        $levelBefore = DB::transactionLevel();

        Organization::deleting(function (): void {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)
                ->delete(route('admin.organizations.destroy', $fixture['organization']));
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertSame($levelBefore, DB::transactionLevel());
    }

    public function test_la_suppression_nominale_detache_les_quatre_relations_et_supprime_l_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $fixture = $this->organizationWithAllFourRelations();

        $this->actingAs($admin)
            ->delete(route('admin.organizations.destroy', $fixture['organization']))
            ->assertRedirect();

        $this->assertDatabaseMissing('organizations', ['id' => $fixture['organization']->id]);

        $this->assertDatabaseHas('users', ['id' => $fixture['user']->id, 'organization_id' => null]);
        $this->assertDatabaseHas('services', ['id' => $fixture['service']->id, 'organization_id' => null]);
        $this->assertDatabaseHas('service_requests', ['id' => $fixture['serviceRequest']->id, 'organization_id' => null]);
        $this->assertDatabaseHas('transactions', ['id' => $fixture['transaction']->id, 'organization_id' => null]);
    }

    public function test_supprimer_une_organization_ne_touche_pas_les_donnees_d_un_autre_tenant(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $tenantA = $this->organizationWithAllFourRelations();
        $tenantB = $this->organizationWithAllFourRelations();

        $this->actingAs($admin)
            ->delete(route('admin.organizations.destroy', $tenantA['organization']))
            ->assertRedirect();

        $this->assertDatabaseMissing('organizations', ['id' => $tenantA['organization']->id]);
        $this->assertDatabaseHas('organizations', ['id' => $tenantB['organization']->id]);

        // Ligne a ligne : aucune donnee du tenant B n'a bouge.
        $this->assertStillAttached($tenantB);
    }

    public function test_une_panne_sur_un_tenant_ne_touche_pas_l_autre(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $tenantA = $this->organizationWithAllFourRelations();
        $tenantB = $this->organizationWithAllFourRelations();

        Organization::deleting(function (): void {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)
                ->delete(route('admin.organizations.destroy', $tenantA['organization']));
        } catch (RuntimeException) {
            // attendu
        }

        $this->assertStillAttached($tenantA);
        $this->assertStillAttached($tenantB);
    }
}
