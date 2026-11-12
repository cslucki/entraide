<?php

namespace Tests\Feature;

use App\Livewire\LoopMembersCard;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1802 (demande Cyril, 10/11) — « il faut afficher et envoyer des
 * emails uniquement aux membres de la Boucle qui sont dans l'Organization
 * de la Boucle. »
 *
 * Mesure en recette (jumeau PROD, `boucle-daccueil`) : une adhesion
 * `loop_members` ACTIVE peut survivre au depart de son titulaire vers une
 * AUTRE Organization (ligne non nettoyee). Avant ce correctif, le
 * trombinoscope de la Card Membres l'affichait comme un membre a part
 * entiere d'une Boucle qu'il a quittee.
 */
class TASK1802LoopMembersDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_membre_dont_l_organization_a_change_n_apparait_plus_dans_le_trombinoscope(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $sain = User::factory()->create(['organization_id' => $organization->id]);
        $parti = User::factory()->create(['organization_id' => Organization::factory()->create(['is_active' => true])->id]);

        $loop = Loop::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $owner->id,
            'status' => 'active',
        ]);

        foreach ([[$owner, 'owner'], [$sain, 'member'], [$parti, 'member']] as [$user, $role]) {
            LoopMember::create([
                'loop_id' => $loop->id,
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'role' => $role,
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app()->instance('current_organization', $organization);

        $membres = Livewire::actingAs($owner)
            ->test(LoopMembersCard::class, ['loop' => $loop])
            ->instance()
            ->members();

        $this->assertCount(2, $membres, 'seuls les membres toujours dans la bonne Organization doivent apparaitre');
        $this->assertTrue($membres->contains('user_id', $owner->id));
        $this->assertTrue($membres->contains('user_id', $sain->id));
        $this->assertFalse(
            $membres->contains('user_id', $parti->id),
            "le membre parti vers une autre Organization ne doit plus apparaitre dans le trombinoscope",
        );
    }
}
