<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The full opening `<button ...>` tag of a sidebar group header. Located
     * by its localStorage key rather than its label: the label text also
     * appears elsewhere on the page, the key does not.
     */
    private function navGroupButton(string $html, string $storageKey): string
    {
        $pos = strpos($html, "setItem('{$storageKey}'");
        $this->assertIsInt($pos, "No nav group button toggling {$storageKey}");

        $tagStart = strrpos(substr($html, 0, $pos), '<button ');
        $tagEnd = strpos($html, '>', $pos);

        return substr($html, $tagStart, $tagEnd - $tagStart + 1);
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
     * fixed in resources/views/layouts/admin.blade.php.
     *
     * The same collision reaches the GROUP header: once the item moved to
     * the "Outils" section, "Organisations" would still have lit up through
     * its own 'admin.users' entry. Both levels are asserted here, because
     * the item-level fix alone left the group wrong.
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

        // Group headers: "Outils" carries the entry now, "Organisations"
        // must not claim it. `text-indigo-400` is the active group colour.
        $outilsHeader = $this->navGroupButton($content, 'sidebar_outils_open');
        $organisationsHeader = $this->navGroupButton($content, 'sidebar_org_open');
        $this->assertStringContainsString('text-indigo-400', $outilsHeader, 'The "Outils" group must be marked active.');
        $this->assertStringNotContainsString('text-indigo-400', $organisationsHeader, 'The "Organisations" group must not be marked active.');
    }

    /**
     * Le sens INVERSE du test precedent : sur « Utilisateurs », c'est lui et
     * son groupe qui s'allument, et surtout PAS l'entree imbriquee ni la
     * section « Outils ».
     *
     * Sans ce miroir, une correction qui eteindrait `admin.users` partout
     * resterait verte — on ne prouverait que la moitie de la regle.
     */
    public function test_sidebar_highlights_users_and_its_group_on_the_users_page(): void
    {
        $content = $this->actingAs($this->superAdmin())
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bg-indigo-600', $this->navAnchorTag($content, route('admin.users')));
        $this->assertStringNotContainsString('bg-indigo-600', $this->navAnchorTag($content, route('admin.users.bulk-create')));

        $this->assertStringContainsString('text-indigo-400', $this->navGroupButton($content, 'sidebar_org_open'));
        $this->assertStringNotContainsString('text-indigo-400', $this->navGroupButton($content, 'sidebar_outils_open'));
    }

    /**
     * La garantie de NON-REGRESSION du predicat « le plus specifique gagne ».
     *
     * `admin.users.create` est bien un descendant de `admin.users` mais n'a
     * aucune entree de nav a lui : « Utilisateurs » doit donc continuer a
     * s'allumer dessus, exactement comme avant l'harmonisation. C'est ce qui
     * distingue la regle d'une simple correspondance exacte, qui aurait
     * eteint le parent sur toutes ses pages filles.
     */
    public function test_a_parent_entry_still_claims_descendants_that_have_no_entry_of_their_own(): void
    {
        $content = $this->actingAs($this->superAdmin())
            ->get(route('admin.users.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bg-indigo-600', $this->navAnchorTag($content, route('admin.users')));
        $this->assertStringContainsString('text-indigo-400', $this->navGroupButton($content, 'sidebar_org_open'));
        $this->assertStringNotContainsString('bg-indigo-600', $this->navAnchorTag($content, route('admin.users.bulk-create')));
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

    /**
     * Regression trouvee par Cyril le 01/10 dans MailHog : les courriels
     * partaient SANS mise en forme.
     *
     * Cause : le gabarit `organization_invitation` seme en base prend le pas
     * sur le repli Blade, et je l'avais ecrit en HTML nu. J'avais verifie
     * `template_used = system_email_template` et conclu au succes — le
     * MECANISME, jamais le RESULTAT.
     *
     * Ce test regarde ce qui part vraiment : le gabarit administrable doit
     * produire un CTA stylé, comme le repli qu'il remplace. Les styles sont
     * INLINE a dessein, les clients de messagerie ignorant `<style>`.
     */
    public function test_the_seeded_template_produces_a_styled_email_not_bare_html(): void
    {
        $org = $this->org();
        $this->seed(\Database\Seeders\SystemEmailTemplateSeeder::class);

        $this->inviteWithHost($org, null, 'miseenforme@example.test')->assertRedirect();

        $log = \App\Models\EmailLog::where('to_email', 'miseenforme@example.test')->firstOrFail();
        $this->assertSame('system_email_template', $log->data['template_used'], 'Ce test doit porter sur le gabarit administrable, pas sur le repli.');

        $html = $this->sentHtml();
        $this->assertStringContainsString('background: #4f46e5', $html, 'Le CTA doit etre un bouton, pas un lien nu.');
        $this->assertStringContainsString('font-family', $html);
    }

    // ── « Host de test » (local/testing uniquement) ───────────────────────

    /** L'exemple fourni par MASTER, tunnel Cloudflare. */
    private const HOST_TUNNEL = 'https://mariah-voted-groups-pst.trycloudflare.com/';

    private function inviteWithHost(Organization $org, ?string $host, string $email): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->superAdmin())
            ->post(route('admin.users.bulk-create.invitations.store'), array_filter([
                'organization_id' => $org->id,
                'host_override' => $host,
                'people' => [
                    ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => $email],
                ],
            ], fn ($v) => $v !== null));
    }

    public function test_without_a_test_host_the_invitation_url_is_unchanged(): void
    {
        $org = $this->org();
        $this->inviteWithHost($org, null, 'canonique@example.test')->assertRedirect();

        $invitation = OrganizationInvitation::where('recipient_email', 'canonique@example.test')->firstOrFail();
        $this->assertNull($invitation->host_override);
        $this->assertStringContainsString(
            route('organization-invitations.show', $invitation->token),
            $this->sentHtml(),
        );
    }

    public function test_a_valid_https_host_replaces_only_the_base_of_the_url(): void
    {
        $org = $this->org();
        $this->inviteWithHost($org, self::HOST_TUNNEL, 'tunnel@example.test')->assertRedirect();

        $invitation = OrganizationInvitation::where('recipient_email', 'tunnel@example.test')->firstOrFail();

        // Slash final normalise a l'enregistrement.
        $this->assertSame('https://mariah-voted-groups-pst.trycloudflare.com', $invitation->host_override);

        $attendue = 'https://mariah-voted-groups-pst.trycloudflare.com/organization-invitations/'.$invitation->token;
        $html = $this->sentHtml();

        $this->assertStringContainsString($attendue, $html);
        // Pas de double slash, et l'origin canonique a disparu du CTA.
        $this->assertStringNotContainsString('trycloudflare.com//', $html);
        $this->assertStringNotContainsString(route('organization-invitations.show', $invitation->token), $html);
    }

    /**
     * Le chemin et le jeton restent ceux de BouclePro : seule la base bouge.
     * C'est la garantie que le host ne fabrique jamais le jeton.
     */
    public function test_the_path_and_token_still_come_from_the_application(): void
    {
        $org = $this->org();
        $this->inviteWithHost($org, self::HOST_TUNNEL, 'chemin@example.test')->assertRedirect();

        $invitation = OrganizationInvitation::where('recipient_email', 'chemin@example.test')->firstOrFail();
        $cheminCanonique = route('organization-invitations.show', $invitation->token, absolute: false);

        $this->assertSame('/organization-invitations/'.$invitation->token, $cheminCanonique);
        $this->assertStringContainsString($invitation->host_override.$cheminCanonique, $this->sentHtml());
        $this->assertSame(64, strlen($invitation->token));
    }

    public function test_resending_keeps_the_test_host_instead_of_falling_back(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'relance-host@example.test',
            'host_override' => 'https://mariah-voted-groups-pst.trycloudflare.com',
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.bulk-create.invitations.resend', $invitation))
            ->assertRedirect();

        $fresh = $invitation->fresh();
        $this->assertSame('https://mariah-voted-groups-pst.trycloudflare.com', $fresh->host_override);
        $this->assertStringContainsString(
            'https://mariah-voted-groups-pst.trycloudflare.com/organization-invitations/'.$fresh->token,
            $this->sentHtml(),
        );
    }

    #[DataProvider('hostsRefuses')]
    public function test_a_malformed_test_host_is_refused(string $host, string $pourquoi): void
    {
        $org = $this->org();

        $this->inviteWithHost($org, $host, 'refuse@example.test')
            ->assertSessionHasErrors('host_override');

        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'refuse@example.test']);
        $this->assertNull(OrganizationInvitation::normalizeHostOverride($host), $pourquoi);
    }

    public static function hostsRefuses(): array
    {
        return [
            'pas une URL absolue' => ['mariah-voted-groups-pst.trycloudflare.com', 'sans schema'],
            'schema inconnu' => ['ftp://exemple.test', 'ni http ni https'],
            'identifiants dans l URL' => ['https://user:secret@exemple.test', 'partiraient dans chaque courriel'],
            'query string' => ['https://exemple.test?a=b', 'casserait l URL finale'],
            'fragment' => ['https://exemple.test#ancre', 'casserait l URL finale'],
            'path applicatif' => ['https://exemple.test/une/app', 'le chemin appartient a BouclePro'],
            'http hors machine locale' => ['http://exemple.test', 'un jeton ne voyage pas en clair'],
        ];
    }

    public function test_http_is_tolerated_on_the_local_machine_only(): void
    {
        $this->assertSame('http://localhost:8000', OrganizationInvitation::normalizeHostOverride('http://localhost:8000/'));
        $this->assertSame('http://127.0.0.1', OrganizationInvitation::normalizeHostOverride('http://127.0.0.1'));
        $this->assertNull(OrganizationInvitation::normalizeHostOverride('http://exemple.test'));
    }

    /**
     * La garde de fond : en production le champ n'existe pas, mais un POST
     * direct l'ignorerait. Le refus est donc cote SERVEUR.
     */
    public function test_in_production_the_test_host_is_refused_server_side_and_hidden(): void
    {
        $org = $this->org();
        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse(OrganizationInvitation::hostOverrideAllowed());

        $this->inviteWithHost($org, self::HOST_TUNNEL, 'prod@example.test')
            ->assertSessionHasErrors('host_override');

        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'prod@example.test']);

        // Et le champ n'est pas rendu.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.users.bulk-create'))
            ->assertDontSee('name="host_override"', false);
    }

    /**
     * Meme une ligne DEJA en base ne doit pas produire un lien de tunnel si
     * l'environnement ne l'autorise plus.
     */
    public function test_a_stored_host_is_ignored_when_the_environment_no_longer_allows_it(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'stocke@example.test',
            'host_override' => 'https://mariah-voted-groups-pst.trycloudflare.com',
        ]);

        app()->detectEnvironment(fn () => 'production');
        app(\App\Services\OrganizationInvitationMailer::class)->send($invitation);

        $html = $this->sentHtml();
        $this->assertStringNotContainsString('trycloudflare.com', $html);
        $this->assertStringContainsString(route('organization-invitations.show', $invitation->token), $html);
    }

    public function test_the_test_host_never_mutates_global_configuration(): void
    {
        $org = $this->org();
        $appUrlAvant = config('app.url');
        $rootAvant = url('/');

        $this->inviteWithHost($org, self::HOST_TUNNEL, 'global@example.test')->assertRedirect();

        $this->assertSame($appUrlAvant, config('app.url'), 'APP_URL ne doit pas bouger.');
        $this->assertSame($rootAvant, url('/'), 'La racine des URL ne doit pas bouger.');
        // Une URL generee APRES l'envoi reste canonique.
        $this->assertStringStartsWith($rootAvant, route('admin.users.bulk-create'));
    }

    public function test_a_token_never_authenticates_a_pre_existing_account(): void
    {
        $org = $this->org();
        // 1. Une invitation part vers une adresse encore inconnue.
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'alice@example.test',
        ]);

        // 2. Avant de cliquer, Alice s'inscrit normalement et pose SON mot de passe.
        $alice = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'alice@example.test',
            'password' => Hash::make('le-mot-de-passe-prive-d-alice'),
        ]);

        // 3. Quiconque detient le courriel clique.
        $this->post(route('organization-invitations.accept', $invitation->token));

        // Le jeton ne doit PAS ouvrir la session du compte d'Alice.
        // `assertGuest()` attend un nom de GUARD, pas un message : on verifie
        // l'absence de session explicitement.
        $this->assertFalse(auth()->check(), 'Un jeton d\'invitation ne doit pas authentifier un compte preexistant.');
        // Et le mot de passe prive d'Alice reste le sien.
        $this->assertTrue(Hash::check('le-mot-de-passe-prive-d-alice', $alice->fresh()->password));
    }

    public function test_case_c_is_not_bypassable_by_email_casing(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        // Compte existant enregistre avec une majuscule (possible : /admin/users n'impose pas `lowercase`).
        User::factory()->create(['organization_id' => $orgB->id, 'email' => 'Bob@example.test']);

        $this->actingAs($this->superAdmin())->post(route('admin.users.bulk-create.invitations.store'), [
            'organization_id' => $orgA->id,
            'people' => [['first_name' => 'Bob', 'last_name' => 'X', 'email' => 'bob@example.test']],
        ]);

        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'bob@example.test']);
    }


    /** Constat 4 de la revue 1 : une Organization DESACTIVEE n'accepte plus. */
    public function test_a_deactivated_organization_no_longer_accepts_an_invitation(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'inactive@example.test',
        ]);
        $org->update(['is_active' => false]);

        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect(route('organization-invitations.show', $invitation->token));

        $this->assertFalse(auth()->check());
        $this->assertDatabaseMissing('users', ['email' => 'inactive@example.test']);
    }

    /**
     * Constat 5 de la revue 1 : PHP range l'antislash dans l'hote, un
     * navigateur le lit comme « / ». La promesse « origin seulement » doit
     * tenir pour les deux.
     */
    public function test_a_backslash_cannot_smuggle_a_path_into_the_test_host(): void
    {
        $this->assertNull(OrganizationInvitation::normalizeHostOverride('https://tunnel.example\\collect'));
        $this->assertNull(OrganizationInvitation::normalizeHostOverride('https://tunnel.example\\'));
    }

    // ── Boucle cible ──────────────────────────────────────────────────────

    /**
     * Une Boucle PRIVEE : c'est le cas qui porte la valeur. L'invitation
     * emise par un SuperAdmin vaut autorisation d'y entrer — pas de demande
     * d'adhesion separee.
     */
    public function test_accepting_joins_the_target_loop_even_when_it_is_private(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create([
            'organization_id' => $org->id,
            'status' => 'active',
            'visibility' => 'private',
        ]);
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'loop_id' => $loop->id,
            'recipient_email' => 'boucle@example.test',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token));

        $user = User::where('email', 'boucle@example.test')->firstOrFail();
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_the_password_step_then_lands_on_the_target_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'status' => 'active', 'visibility' => 'private']);
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'loop_id' => $loop->id,
            'recipient_email' => 'atterrissage@example.test',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token));

        $this->post(route('invitation.password.store'), [
            'password' => 'un-mot-de-passe-solide-42',
            'password_confirmation' => 'un-mot-de-passe-solide-42',
        ])->assertRedirect(route('organization.loops.show', ['organization' => $org->slug, 'loop' => $loop]));
    }

    public function test_without_a_target_loop_the_password_step_lands_on_the_organization(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'loop_id' => null,
            'recipient_email' => 'sansboucle@example.test',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token));
        $user = User::where('email', 'sansboucle@example.test')->firstOrFail();

        $this->post(route('invitation.password.store'), [
            'password' => 'un-mot-de-passe-solide-42',
            'password_confirmation' => 'un-mot-de-passe-solide-42',
        ])->assertRedirect($user->getLoginRedirectTarget());
    }

    /**
     * Frontiere de tenant : une Boucle d'une AUTRE Organization ne peut pas
     * devenir la cible, meme soumise directement dans la requete.
     */
    public function test_a_loop_from_another_organization_is_refused_as_a_target(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $loopChezB = Loop::factory()->create(['organization_id' => $orgB->id, 'status' => 'active']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.bulk-create.invitations.store'), [
                'organization_id' => $orgA->id,
                'loop_id' => $loopChezB->id,
                'people' => [
                    ['first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'horstenant@example.test'],
                ],
            ])
            ->assertSessionHasErrors('loop_id');

        $this->assertDatabaseMissing('organization_invitations', ['recipient_email' => 'horstenant@example.test']);
    }

    // ── Suivi : filtrer par etat ──────────────────────────────────────────

    /**
     * "Expiree" ne se lit pas dans la seule colonne `status` : une ligne
     * reste `pending` en base jusqu'a ce qu'un passage la perime. Le filtre
     * doit donc voir la meme chose que le badge du tableau, sinon l'onglet
     * « Expirées » afficherait vide alors que la liste montre « Expirée ».
     */
    public function test_the_status_tabs_filter_on_the_same_definition_the_table_displays(): void
    {
        $org = $this->org();
        $enAttente = OrganizationInvitation::factory()->create(['organization_id' => $org->id, 'recipient_email' => 'attente@example.test']);
        $perimee = OrganizationInvitation::factory()->expired()->create(['organization_id' => $org->id, 'recipient_email' => 'perimee@example.test']);
        $revoquee = OrganizationInvitation::factory()->revoked()->create(['organization_id' => $org->id, 'recipient_email' => 'revoquee@example.test']);
        $activee = OrganizationInvitation::factory()->accepted()->create(['organization_id' => $org->id, 'recipient_email' => 'activee@example.test']);

        $admin = $this->superAdmin();

        $pending = $this->actingAs($admin)->get(route('admin.users.bulk-create', ['status' => 'pending']));
        $pending->assertSee('attente@example.test');
        $pending->assertDontSee('perimee@example.test');
        $pending->assertDontSee('activee@example.test');

        // La ligne perimee est encore `pending` en base : c'est la date qui
        // la classe, et c'est tout l'interet de ce test.
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $perimee->fresh()->status);
        $expired = $this->actingAs($admin)->get(route('admin.users.bulk-create', ['status' => 'expired']));
        $expired->assertSee('perimee@example.test');
        $expired->assertDontSee('attente@example.test');

        $accepted = $this->actingAs($admin)->get(route('admin.users.bulk-create', ['status' => 'accepted']));
        $accepted->assertSee('activee@example.test');
        $accepted->assertDontSee('revoquee@example.test');

        $revoked = $this->actingAs($admin)->get(route('admin.users.bulk-create', ['status' => 'revoked']));
        $revoked->assertSee('revoquee@example.test');
        $revoked->assertDontSee('activee@example.test');
    }

    public function test_an_activated_invitation_offers_login_as_on_the_account_it_created(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'connecte@example.test',
        ]);
        $this->post(route('organization-invitations.accept', $invitation->token));
        $created = User::where('email', 'connecte@example.test')->firstOrFail();

        auth()->logout();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.users.bulk-create'))
            ->assertSee(route('admin.users.login-as', $created), false);

        // Et le mecanisme existant fonctionne bien depuis cet ecran.
        $this->actingAs($admin)
            ->post(route('admin.users.login-as', $created))
            ->assertRedirect();
        $this->assertAuthenticatedAs($created);
    }

    // ── Liens sortants : arriver sur le bon perimetre ────────────────────

    public function test_the_email_template_link_lands_on_this_features_templates_only(): void
    {
        $org = $this->org();
        foreach (['organization_invitation', 'loop_invitation'] as $slug) {
            SystemEmailTemplate::create([
                'organization_id' => $org->id,
                'locale' => 'fr',
                'slug' => $slug,
                'name' => 'Gabarit '.$slug,
                'subject' => 'Sujet '.$slug,
                'content_html' => '<p>corps</p>',
                'variables' => [],
                'enabled' => true,
            ]);
        }

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.system-email-templates', ['slug' => 'organization_invitation']));

        $response->assertOk();
        $response->assertSee('Gabarit organization_invitation');
        $response->assertDontSee('Gabarit loop_invitation');
    }

    public function test_the_email_history_link_lands_on_invitation_emails_only(): void
    {
        $org = $this->org();
        \App\Models\EmailLog::create([
            'organization_id' => $org->id,
            'to_email' => 'invitation@example.test',
            'subject' => 'Sujet invitation',
            'status' => 'sent',
            'data' => ['source' => 'organization-invitation'],
        ]);
        \App\Models\EmailLog::create([
            'organization_id' => $org->id,
            'to_email' => 'autrechose@example.test',
            'subject' => 'Sujet hors perimetre',
            'status' => 'sent',
            'data' => ['source' => 'loop-invitation'],
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.email-logs', ['source' => 'organization-invitation']));

        $response->assertOk();
        $response->assertSee('invitation@example.test');
        $response->assertDontSee('autrechose@example.test');
    }

    /**
     * The search box must not escape the source filter: an ungrouped
     * `orWhere` would have made "any subject matching X" win over "only
     * invitation e-mails".
     */
    public function test_searching_within_the_invitation_history_stays_inside_that_perimeter(): void
    {
        $org = $this->org();
        \App\Models\EmailLog::create([
            'organization_id' => $org->id,
            'to_email' => 'dedans@example.test',
            'subject' => 'Bienvenue',
            'status' => 'sent',
            'data' => ['source' => 'organization-invitation'],
        ]);
        \App\Models\EmailLog::create([
            'organization_id' => $org->id,
            'to_email' => 'dehors@example.test',
            'subject' => 'Bienvenue',
            'status' => 'sent',
            'data' => ['source' => 'loop-invitation'],
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.email-logs', ['source' => 'organization-invitation', 'search' => 'Bienvenue']));

        $response->assertOk();
        $response->assertSee('dedans@example.test');
        $response->assertDontSee('dehors@example.test');
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

    // ── Deuxieme etape : definir son mot de passe ─────────────────────────

    public function test_accepting_lands_on_the_password_step_not_the_dashboard(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'motdepasse@example.test',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect(route('invitation.password.create'));

        $user = User::where('email', 'motdepasse@example.test')->firstOrFail();
        $this->assertTrue($user->must_set_password);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * The point of the step: it must not be skippable by simply typing
     * another URL — otherwise the person keeps an account whose password
     * nobody knows, and would need "forgot password" to ever return.
     */
    public function test_the_password_step_cannot_be_skipped_by_navigating_elsewhere(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $org->id]);
        $this->post(route('organization-invitations.accept', $invitation->token));

        $this->get(route('dashboard'))->assertRedirect(route('invitation.password.create'));
        $this->get(route('profile.edit'))->assertRedirect(route('invitation.password.create'));
    }

    public function test_setting_the_password_releases_the_account_and_actually_works(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'libere@example.test',
        ]);
        $this->post(route('organization-invitations.accept', $invitation->token));

        $this->post(route('invitation.password.store'), [
            'password' => 'un-mot-de-passe-solide-42',
            'password_confirmation' => 'un-mot-de-passe-solide-42',
        ])->assertRedirect();

        $user = User::where('email', 'libere@example.test')->firstOrFail();
        $this->assertFalse($user->must_set_password);
        // The chosen password is the one that now opens the account.
        $this->assertTrue(Hash::check('un-mot-de-passe-solide-42', $user->password));

        // And the step stops standing in the way.
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_weak_or_unconfirmed_password_is_refused_and_the_step_stays(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $org->id]);
        $this->post(route('organization-invitations.accept', $invitation->token));
        $user = User::where('email', $invitation->recipient_email)->firstOrFail();

        $this->post(route('invitation.password.store'), [
            'password' => 'court',
            'password_confirmation' => 'pas-le-meme',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_set_password);
    }

    public function test_an_ordinary_account_is_never_sent_to_the_password_step(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('invitation.password.create'))->assertRedirect();
    }

    /**
     * DEFAUT DE SECURITE trouve par Cyril en recette, le 30/09.
     *
     * Ce test affirmait l'inverse : il exigeait qu'un second clic RECONNECTE
     * la personne, et il etait vert. Il encodait donc la faille comme
     * comportement attendu — un jeton envoye par courriel (donc recopie dans
     * une boite mail, un historique, une capture d'ecran) devenait un mot de
     * passe PERMANENT pour ce compte.
     *
     * Un jeton a usage unique est consomme : il n'authentifie plus personne.
     */
    public function test_a_consumed_invitation_link_never_authenticates_again(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create([
            'organization_id' => $org->id,
            'recipient_email' => 'consomme@example.test',
        ]);

        $this->post(route('organization-invitations.accept', $invitation->token));
        $this->assertAuthenticated();

        auth()->logout();

        // Quiconque detient le lien le rejoue : il ne doit rien ouvrir.
        $this->post(route('organization-invitations.accept', $invitation->token))
            ->assertRedirect(route('organization-invitations.show', $invitation->token));

        $this->assertGuest();
        $this->assertSame(1, User::where('email', 'consomme@example.test')->count(), 'A reclick must never create a second account either.');
    }

    public function test_the_landing_page_of_a_consumed_invitation_offers_sign_in_not_a_session(): void
    {
        $org = $this->org();
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $org->id]);
        $this->post(route('organization-invitations.accept', $invitation->token));
        auth()->logout();

        $response = $this->get(route('organization-invitations.show', $invitation->token));

        $response->assertOk();
        // Plus aucun formulaire qui rejoue l'acceptation depuis cet ecran.
        $response->assertDontSee(route('organization-invitations.accept', $invitation->token), false);
        $this->assertGuest();
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
