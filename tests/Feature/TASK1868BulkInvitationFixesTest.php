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

    // ── 2. Premiere definition du mot de passe ────────────────────────────

    public function test_the_password_step_shows_the_account_email_readonly_for_autocomplete(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'autocomplete@example.test',
        ]);
        $this->post(route('organization-invitations.accept', $invitation->token));

        $response = $this->get(route('invitation.password.create'));

        $response->assertOk();
        $response->assertSee('autocomplete@example.test', false);
        $response->assertSee('autocomplete="username"', false);
        $response->assertSee('readonly', false);
    }

    /**
     * The field is rendered for the browser's password manager only — the
     * controller must keep pulling identity from the session, never from
     * whatever a request happens to carry in `email`.
     */
    public function test_a_tampered_email_field_on_the_password_step_changes_nothing(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'vraie-adresse@example.test',
        ]);
        $this->post(route('organization-invitations.accept', $invitation->token));

        $this->post(route('invitation.password.store'), [
            'email' => 'autre-adresse@example.test',
            'password' => 'un-mot-de-passe-solide-42',
            'password_confirmation' => 'un-mot-de-passe-solide-42',
        ])->assertRedirect();

        $user = User::where('email', 'vraie-adresse@example.test')->firstOrFail();
        $this->assertFalse($user->must_set_password);
        $this->assertTrue(Hash::check('un-mot-de-passe-solide-42', $user->password));
        $this->assertNull(User::where('email', 'autre-adresse@example.test')->first());
    }

    // ── 3. Duree de validite : 48h -> 7 jours ─────────────────────────────

    public function test_a_freshly_created_invitation_is_valid_for_seven_days_not_forty_eight_hours(): void
    {
        $org = $this->org();

        $invitation = app(\App\Services\OrganizationInvitationService::class)->invite(
            $org,
            User::factory()->create(['organization_id' => $org->id]),
            'nouvelle.validite@example.test',
            'Jean',
            'Dupont',
        )['invitation'];

        $this->assertSame(168, OrganizationInvitation::VALIDITY_HOURS);
        $this->assertTrue($invitation->expires_at->between(
            now()->addHours(167),
            now()->addHours(169),
        ));
    }

    public function test_resending_an_invitation_extends_its_validity_to_a_fresh_seven_days(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'expires_at' => now()->addHours(2),
        ]);

        app(\App\Services\OrganizationInvitationService::class)->invite(
            $org,
            User::factory()->create(['organization_id' => $org->id]),
            $invitation->recipient_email,
            $invitation->recipient_first_name,
            $invitation->recipient_name,
        );

        $fresh = $invitation->fresh();
        $this->assertTrue($fresh->expires_at->between(
            now()->addHours(167),
            now()->addHours(169),
        ));
    }

    /** An invitation issued before this TASK keeps its own expiry unless resent. */
    public function test_an_already_issued_invitation_keeps_its_original_expiry_until_resent(): void
    {
        $org = $this->org();
        $originalExpiry = now()->addHours(30); // ancienne fenetre 48h, deja entamee
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'expires_at' => $originalExpiry,
        ]);

        // Comparaison a la seconde pres : `expires_at` est ecrit en base sans
        // les microsecondes (meme troncature que les autres timestamps
        // Eloquent de ce projet).
        $this->assertSame(
            $originalExpiry->copy()->startOfSecond()->toDateTimeString(),
            $invitation->fresh()->expires_at->toDateTimeString(),
        );
    }
}
