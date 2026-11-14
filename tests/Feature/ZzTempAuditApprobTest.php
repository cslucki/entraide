<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** FICHIER TEMPORAIRE D'AUDIT — a supprimer. */
class ZzTempAuditApprobTest extends TestCase
{
    use RefreshDatabase;

    public function test_asymetrie_non_approuve(): void
    {
        $org = Organization::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'requires_account_approval' => true,
        ]);

        $user = User::factory()->complete()->pendingApproval()->create([
            'organization_id' => $org->id,
            'password' => Hash::make('ancien-secret-1234'),
        ]);

        $user = $user->fresh();
        dump('approved_at='.var_export($user->approved_at, true));
        dump('org.requires_account_approval='.var_export($user->organization->requires_account_approval, true));
        dump('attendApprobation='.var_export($user->attendApprobation(), true));

        $page = $this->actingAs($user)->get("/org/{$org->slug}/profile/edit");
        dump('PAGE statut='.$page->getStatusCode().' redirection='.($page->headers->get('Location') ?? 'aucune'));

        if ($page->getStatusCode() === 200) {
            dump('PAGE formulaire_mdp='.var_export(str_contains($page->getContent(), 'name="current_password"'), true));
        }

        $put = $this->actingAs($user)
            ->from("/org/{$org->slug}/profile/edit")
            ->put('/password', [
                'current_password' => 'ancien-secret-1234',
                'password' => 'nouveau-secret-9876',
                'password_confirmation' => 'nouveau-secret-9876',
            ]);
        dump('PUT statut='.$put->getStatusCode().' redirection='.($put->headers->get('Location') ?? 'aucune'));
        dump('PUT hash_change='.var_export(Hash::check('nouveau-secret-9876', $user->fresh()->password), true));

        $this->assertTrue(true);
    }

    public function test_asymetrie_non_verifie(): void
    {
        $org = Organization::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'requires_account_approval' => false,
        ]);

        $user = User::factory()->complete()->unverified()->create([
            'organization_id' => $org->id,
            'password' => Hash::make('ancien-secret-1234'),
        ]);

        $page = $this->actingAs($user)->get("/org/{$org->slug}/profile/edit");
        dump('NV PAGE statut='.$page->getStatusCode());
        dump('NV PAGE formulaire_mdp='.var_export($page->getStatusCode() === 200 && str_contains($page->getContent(), 'name="current_password"'), true));

        $put = $this->actingAs($user)
            ->from("/org/{$org->slug}/profile/edit")
            ->put('/password', [
                'current_password' => 'ancien-secret-1234',
                'password' => 'nouveau-secret-9876',
                'password_confirmation' => 'nouveau-secret-9876',
            ]);
        dump('NV PUT statut='.$put->getStatusCode().' redirection='.($put->headers->get('Location') ?? 'aucune'));
        dump('NV PUT hash_change='.var_export(Hash::check('nouveau-secret-9876', $user->fresh()->password), true));
        dump('NV PUT erreurs_affichables='.json_encode(session()->get('errors') ? 'presentes' : 'aucune'));

        $this->assertTrue(true);
    }
}
