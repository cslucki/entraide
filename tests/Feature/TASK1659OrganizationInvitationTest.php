<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * TASK-1659 — "Création de comptes en masse", SuperAdmin-only, and the
 * public accept path it feeds: click -> account created -> e-mail verified
 * -> logged in -> Organization cible, no registration form, no password
 * ever transmitted.
 */
class TASK1659OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Organization
    {
        return Organization::factory()->create($overrides);
    }

    private function sandbox(): Organization
    {
        $organization = $this->org();
        $organization->forceFill(['scenario_sandbox_created_at' => now()])->save();

        return $organization->fresh();
    }

    /**
     * The message actually built and handed to the transport. NOT
     * `Mail::fake()`: the mailer calls `Mail::html()`, which instantiates no
     * Mailable — a fake would see nothing pass. The `array` transport
     * (MAIL_MAILER=array in phpunit.xml / phpunit.pgsql.xml) keeps the
     * message as it was actually rendered, the same pattern as
     * LoopInvitationMailerTest.
     */
    private function lastEmail(): Email
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'Aucun courriel n\'est parti.');

        return $messages->last()->getOriginalMessage();
    }

    private function sentHtml(): string
    {
        return (string) $this->lastEmail()->getHtmlBody();
    }

    /** The full opening `<a ...>` tag whose href is exactly $url, wherever the class attribute falls relative to href. */
    private function navAnchorTag(string $html, string $url): string
    {
        $hrefPos = strpos($html, 'href="'.$url.'"');
        $this->assertIsInt($hrefPos, "No <a> found with href={$url}");

        $tagStart = strrpos(substr($html, 0, $hrefPos), '<a ');
        $tagEnd = strpos($html, '>', $hrefPos);

        return substr($html, $tagStart, $tagEnd - $tagStart + 1);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function orgAdmin(Organization $organization): User
    {
        $admin = User::factory()->create(['organization_id' => $organization->id, 'is_admin' => false]);
        $organization->update(['admin_id' => $admin->id]);

        return $admin;
    }

    // ── Autorisation de la surface SuperAdmin ─────────────────────────────

    public function test_super_admin_can_open_the_bulk_create_tool(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.users.bulk-create'))
            ->assertOk();
    }

    /**
     * Regression: 'admin.users.bulk-create' is nested under the
     * 'admin.users.' prefix (route name chosen by MASTER), so the sidebar's
     * generic wildcard active-match (route.'.*') lit up BOTH "Utilisateurs"
     * and "Création de comptes en masse" at once. Caught visually by Cyril,
     * fixed in resources/views/layouts/admin.blade.php with a narrow
     * exclusion scoped to that one nav item.
     */
    public function test_sidebar_highlights_only_the_bulk_create_item_not_also_users(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.users.bulk-create'));

        $response->assertOk();
        $content = $response->getContent();

        $usersTag = $this->navAnchorTag($content, route('admin.users'));
        $bulkCreateTag = $this->navAnchorTag($content, route('admin.users.bulk-create'));

        $this->assertStringNotContainsString('bg-indigo-600', $usersTag, '"Utilisateurs" must not be highlighted on the bulk-create page.');
        $this->assertStringContainsString('bg-indigo-600', $bulkCreateTag, '"Création de comptes en masse" must be highlighted on its own page.');
    }

    public function test_standard_user_is_refused(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('admin.users.bulk-create'))
            ->assertForbidden();
    }

    public function test_org_admin_without_is_admin_is_refused_on_this_superadmin_surface(): void
    {
        $org = $this->org();
        $admin = $this->orgAdmin($org);

        $this->actingAs($admin)
            ->get(route('admin.users.bulk-create'))
            ->assertForbidden();
    }

    // ── Garde sandbox ──────────────────────────────────────────────────────

    public function test_sandbox_organization_is_not_offered_as_a_target(): void
    {
        $sandbox = $this->sandbox();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.users.bulk-create'));

        $response->assertOk();
        // Assert on the UUID, never the fake-generated name: French Faker
        // company names are built from the same surname lexicon as
        // fake()->lastName(), so a name-substring check can collide with an
        // unrelated user's name rendered elsewhere on the page.
        $response->assertDontSee($sandbox->id, false);
    }

    public function test_store_refuses_a_sandbox_organization_even_if_submitted(): void
    {
        Mail::fake();
        $sandbox = $this->sandbox();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.bulk-create.invitations.store'), [
                'organization_id' => $sandbox->id,
                'people' => [
                    ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'jean@example.test'],
                ],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'jean@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'jean@example.test']);
        Mail::assertNothingSent();
    }

    // ── Création — Cas A/B/C ─────────────────────────────────────────────

    public function test_unknown_email_creates_an_invitation_and_sends_a_mail(): void
    {
        $org = $this->org(['name' => 'Entraide Locale']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.bulk-create.invitations.store'), [
                'organization_id' => $org->id,
                'people' => [
                    ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'jean@example.test'],
                ],
            ])
            ->assertRedirect();

        $invitation = OrganizationInvitation::where('recipient_email', 'jean@example.test')->firstOrFail();
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->status);
        $this->assertDatabaseHas('email_logs', ['to_email' => 'jean@example.test', 'status' => 'sent']);

        // Regression: the Blade fallback's CTA button once read literally
        // "Rejoindre :organization" — the translation placeholder was never
        // substituted because the view called __() without its replacement
        // array. Caught by Cyril from the actual MailHog output.
        $html = $this->sentHtml();
        $this->assertStringNotContainsString(':organization', $html, 'A translation placeholder was left unsubstituted.');
        $this->assertStringContainsString('Entraide Locale', $html);

        // 48h, not the 30 days inherited from loop_invitations (Cyril, 30/09).
        $this->assertTrue($invitation->expires_at->between(now()->addHours(47), now()->addHours(49)));
    }

    /**
     * The e-mail language is CHOSEN on the form (French by default), and
     * deliberately not taken from `app()->getLocale()` — the SuperAdmin's
     * own session language, which says nothing about what the invited
     * person reads.
     */
    public function test_invitation_email_uses_the_language_chosen_on_the_form(): void
    {
        $org = $this->org(['name' => 'LaunchPals']);
        // The request locale is decided by the SetLocale middleware, not by
        // anything this test sets beforehand — so pin it through the one
        // input that middleware reads first for an authenticated admin.
        // Admin reads French, the invitation is sent in English: the two
        // must differ, or the test could not tell a leak from a correct
        // restore.
        $admin = User::factory()->create(['is_admin' => true, 'preferred_locale' => 'fr']);

        $this->actingAs($admin)->post(route('admin.users.bulk-create.invitations.store'), [
            'organization_id' => $org->id,
            'locale' => 'en',
            'people' => [
                ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'anglophone@example.test'],
            ],
        ]);

        $html = $this->sentHtml();
        $this->assertStringContainsString('You are invited to join', $html);
        $this->assertStringNotContainsString('Vous êtes invité', $html);
        // Same fix covers the date: isoFormat() alone follows Carbon's
        // global locale, not the Organization's.
        $this->assertMatchesRegularExpression('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/', $html);

        // The SuperAdmin's own session locale must be restored, not left on 'en'.
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_french_is_the_default_when_no_language_is_chosen(): void
    {
        $org = $this->org();
        $admin = User::factory()->create(['is_admin' => true, 'preferred_locale' => 'en']);

        $this->actingAs($admin)->post(route('admin.users.bulk-create.invitations.store'), [
            'organization_id' => $org->id,
            // no 'locale' key at all
            'people' => [
                ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'defaut@example.test'],
            ],
        ]);

        $invitation = OrganizationInvitation::where('recipient_email', 'defaut@example.test')->firstOrFail();
        $this->assertSame('fr', $invitation->locale);
        $this->assertStringContainsString('Vous êtes invité', $this->sentHtml());
    }

    public function test_invitation_email_prefers_an_enabled_system_template_in_the_chosen_locale(): void
    {
        $org = $this->org();
        SystemEmailTemplate::create([
            'organization_id' => $org->id,
            'locale' => 'en',
            'slug' => 'organization_invitation',
            'name' => 'Org invitation',
            'subject' => 'Custom subject for {{ organization_name }}',
            'content_html' => '<p>Custom body, link: {{ invitation_url }}</p>',
            'variables' => [],
            'enabled' => true,
        ]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.users.bulk-create.invitations.store'), [
            'organization_id' => $org->id,
            'locale' => 'en',
            'people' => [
                ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'template@example.test'],
            ],
        ]);

        $this->assertStringContainsString('Custom body, link:', $this->sentHtml());
        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'template@example.test',
            'status' => 'sent',
        ]);
        $log = \App\Models\EmailLog::where('to_email', 'template@example.test')->firstOrFail();
        $this->assertSame('system_email_template', $log->data['template_used']);
    }

    public function test_email_already_member_of_same_organization_is_blocked_no_duplicate(): void
    {
        Mail::fake();
        $org = $this->org();
        $existing = User::factory()->create(['organization_id' => $org->id, 'email' => 'deja@example.test']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.users.bulk-create.invitations.store'), [
            'organization_id' => $org->id,
            'people' => [
                ['first_name' => 'Deja', 'last_name' => 'Membre', 'email' => 'deja@example.test'],
            ],
        ]);

        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'deja@example.test']);
        $this->assertSame(1, User::where('email', 'deja@example.test')->count());
        $this->assertDatabaseMissing('email_logs', ['to_email' => 'deja@example.test']);
    }

    public function test_email_used_in_another_organization_is_blocked_no_email_no_mutation(): void
    {
        Mail::fake();
        $orgA = $this->org();
        $orgB = $this->org();
        $existing = User::factory()->create(['organization_id' => $orgA->id, 'email' => 'ailleurs@example.test']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.users.bulk-create.invitations.store'), [
            'organization_id' => $orgB->id,
            'people' => [
                ['first_name' => 'Ailleurs', 'last_name' => 'Deja', 'email' => 'ailleurs@example.test'],
            ],
        ]);

        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'ailleurs@example.test']);
        $this->assertDatabaseMissing('email_logs', ['to_email' => 'ailleurs@example.test']);
        $this->assertSame($orgA->id, $existing->fresh()->organization_id, 'The existing user must never be relocated.');
    }

    // ── Acceptation publique ──────────────────────────────────────────────

    public function test_accept_unknown_token_is_refused(): void
    {
        $this->post(route('organization-invitations.accept', 'not-a-real-token'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_accept_expired_token_is_refused(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->expired()->create(['organization_id' => $org->id]);

        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect(route('organization-invitations.show', $invitation->token));

        $this->assertGuest();
        $this->assertSame(OrganizationInvitation::STATUS_EXPIRED, $invitation->fresh()->status);
    }

    public function test_accept_revoked_token_is_refused(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->revoked()->create(['organization_id' => $org->id]);

        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect(route('organization-invitations.show', $invitation->token));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $invitation->recipient_email]);
    }

    public function test_accept_valid_token_creates_the_account_verifies_email_and_logs_in(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'nouvelle@example.test',
            'recipient_first_name' => 'Nouvelle',
            'recipient_name' => 'Personne',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect();

        $user = User::where('email', 'nouvelle@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($org->id, $user->organization_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);

        $invitation->refresh();
        $this->assertTrue($invitation->isAccepted());
        $this->assertSame($user->id, $invitation->accepted_by_user_id);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'reason' => 'welcome_bonus',
            'delta' => 100,
        ]);
    }

    public function test_reclicking_an_already_accepted_link_is_idempotent(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'idempotent@example.test',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token));
        $firstUserId = User::where('email', 'idempotent@example.test')->first()?->id;

        auth()->logout();

        $this->post(route('organization-invitations.accept', $invitation->token))->assertRedirect();

        $this->assertSame(1, User::where('email', 'idempotent@example.test')->count(), 'A reclick must never create a second account.');
        $this->assertAuthenticatedAs(User::find($firstUserId));
    }

    public function test_no_secret_is_exposed_after_account_creation(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'secret@example.test',
        ]);

        $response = $this->post(route('organization-invitations.accept', $invitation->token));

        $response->assertRedirect();
        $response->assertSessionMissing('password');
        $user = User::where('email', 'secret@example.test')->first();
        $this->assertNotSame('', $user->password);
        $this->assertStringStartsWith('$2y$', $user->password, 'The stored password must be a bcrypt hash, never plaintext.');
    }

    // ── Relance / révocation ───────────────────────────────────────────────

    public function test_super_admin_can_revoke_a_pending_invitation(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $org->id]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.bulk-create.invitations.revoke', $invitation))
            ->assertRedirect();

        $this->assertSame(OrganizationInvitation::STATUS_REVOKED, $invitation->fresh()->status);

        auth()->logout();

        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect(route('organization-invitations.show', $invitation->token));
        $this->assertGuest();
    }

    public function test_super_admin_can_resend_a_pending_invitation_without_duplicating_it(): void
    {
        Mail::fake();
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'relance@example.test',
            'expires_at' => now()->addHours(2), // about to expire
        ]);
        $originalToken = $invitation->token;
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.bulk-create.invitations.resend', $invitation))
            ->assertRedirect();

        $this->assertSame(1, OrganizationInvitation::where('recipient_email', 'relance@example.test')->count());
        $fresh = $invitation->fresh();
        $this->assertSame($originalToken, $fresh->token, 'A resend must keep the token already in the recipient\'s mailbox valid.');
        // "Relancer" must push the deadline back out to a fresh 48h window
        // (Cyril, 30/09), not just resend the same link with its original
        // clock still running.
        $this->assertTrue($fresh->expires_at->between(now()->addHours(47), now()->addHours(49)));
    }
}
