<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1868 — trois correctifs du parcours d'invitation en masse
 * (`/admin/users/bulk-create`), remontes par Cyril sur un compte reel :
 *
 * 1. identite du compte cree par `OrganizationInvitationService::accept()` —
 *    `name` (Nom) ne doit plus porter le nom complet, seulement le nom de
 *    famille, sans quoi `User::getFullNameAttribute()` duplique le prenom ;
 * 2. l'ecran de premiere definition du mot de passe doit presenter l'email,
 *    pour que le gestionnaire de mots de passe du navigateur associe
 *    correctement adresse et mot de passe ;
 * 3. la duree de validite d'une invitation Organization passe de 48h a 7
 *    jours, creation et relance.
 *
 * Perimetre deja couvert par `TASK1659OrganizationInvitationTest` (cas
 * refuses, Boucle cible, etc.) : non duplique ici.
 */
class TASK1868BulkInvitationFixesTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Organization
    {
        return Organization::factory()->create($overrides);
    }

    // ── 1. Identite du compte cree ────────────────────────────────────────

    public function test_accepting_an_invitation_splits_first_name_and_name_correctly(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'jean.dupont@example.test',
            'recipient_first_name' => 'Jean',
            'recipient_name' => 'Dupont',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect();

        $user = User::where('email', 'jean.dupont@example.test')->firstOrFail();

        $this->assertSame('Jean', $user->first_name);
        $this->assertSame('Dupont', $user->name);
        $this->assertSame('Jean Dupont', $user->full_name);
        $this->assertSame('Jean Dupont', $user->publicDisplayName());
    }
}
