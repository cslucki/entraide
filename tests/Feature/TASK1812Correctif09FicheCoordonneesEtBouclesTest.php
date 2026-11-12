<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1812, correctif 09 — la Fiche (`/admin/users`) manquait trois choses.
 *
 * Demande de Cyril : « il faudrait ajouter les boucles rejointes par les
 * utilisateurs, là on a que les boucles créées. Il faut aussi ajouter email
 * (avec lien mailto:) et téléphone portable (avec lien tel:). »
 *
 * Les Boucles créées et un simple COMPTAGE de Boucles rejointes existaient
 * deja (TASK-1640) ; ce qui manquait, c'etaient les NOMS des Boucles
 * rejointes, et des liens cliquables pour l'email (deja affiche en texte) et
 * le telephone (absent).
 */
class TASK1812Correctif09FicheCoordonneesEtBouclesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->organization)->create(['is_admin' => true]);
        $this->target = User::factory()->for($this->organization)->create([
            'first_name' => 'Jean',
            'name' => 'Dupont',
            'email' => 'jean.dupont@example.test',
            'phone' => '06 12 34 56 78',
        ]);
    }

    private function fiche(): array
    {
        return $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.profile-summary', $this->target))
            ->assertOk()
            ->json();
    }

    public function test_le_telephone_est_porte_par_la_fiche(): void
    {
        $this->assertSame('06 12 34 56 78', $this->fiche()['identite']['telephone']);
    }

    public function test_sans_telephone_la_cle_reste_nulle(): void
    {
        $this->target->update(['phone' => null]);

        $this->assertNull($this->fiche()['identite']['telephone']);
    }

    public function test_les_boucles_rejointes_sont_nommees(): void
    {
        $boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
            'name' => 'Boucle des jardiniers',
        ]);
        LoopMember::factory()->create([
            'loop_id' => $boucle->id,
            'user_id' => $this->target->id,
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        $this->assertSame(['Boucle des jardiniers'], $this->fiche()['boucles_rejointes']);
    }

    /** 🔴 Une adhesion revoquee, ou une Boucle archivee, ne doit pas nommer la Boucle. */
    public function test_une_adhesion_inactive_ou_une_boucle_archivee_n_apparait_pas(): void
    {
        $boucleActive = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
            'name' => 'Boucle visible',
        ]);
        LoopMember::factory()->create([
            'loop_id' => $boucleActive->id,
            'user_id' => $this->target->id,
            'organization_id' => $this->organization->id,
            'status' => 'removed',
        ]);

        $boucleArchivee = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'archived',
            'name' => 'Boucle archivee',
        ]);
        LoopMember::factory()->create([
            'loop_id' => $boucleArchivee->id,
            'user_id' => $this->target->id,
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        $this->assertSame([], $this->fiche()['boucles_rejointes']);
    }

    public function test_sans_aucune_boucle_rejointe_la_liste_est_vide(): void
    {
        $this->assertSame([], $this->fiche()['boucles_rejointes']);
    }

    public function test_la_modal_rend_des_liens_mailto_et_tel(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("'mailto:' + profile.identite.email", $html);
        $this->assertStringContainsString("'tel:' + profile.identite.telephone", $html);
    }
}
