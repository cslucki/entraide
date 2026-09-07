<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * TASK-1412 — fondation du compte VERIFIE (B1 de la campagne Growth).
 *
 * Avant : `User` heritait du TRAIT `MustVerifyEmail` mais n'implementait pas
 * l'INTERFACE. Consequences mesurees : `Registered` n'envoyait aucun email,
 * et le middleware `verified` deja pose sur la redaction du blog
 * (`routes/web.php:144`) et sur les Dossiers / likes org (`:865`) etait un
 * no-op silencieux.
 *
 * Apres : l'interface est implementee, donc (1) un nouveau compte recoit un
 * email de verification, (2) `verified` devient EFFECTIF, (3) les comptes nes
 * avant la verification sont grandfathered a l'instant du cutover par une
 * migration de donnees — sans elle, 45 des 57 comptes du banc local (des
 * membres reels) auraient ete exclus du blog et des Dossiers a l'instant du
 * deploiement.
 *
 * Doctrine du CDC : « participation CONFIRMEE exige compte + email + password
 * + email verifie ». C'est le socle de tout le funnel Ateliers.
 */
class TASK1412EmailVerificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        app()->instance('current_organization', $this->organization);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    // ── 1. L'interface est bien celle que le framework teste ────────────────

    public function test_the_user_model_implements_the_interface_not_only_the_trait(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
    }

    // ── 2. Un nouveau compte recoit l'email et n'est pas verifie ────────────

    public function test_a_new_registration_sends_the_verification_email_and_stays_unverified(): void
    {
        Notification::fake();

        $this->post(route('register'), $this->payload('nouveau@t1412.test'))->assertRedirect();

        $user = User::where('email', 'nouveau@t1412.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);

        // L'inscription connecte toujours : la verification GATE la
        // participation, pas la session.
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_organization_scoped_registration_sends_the_verification_email_too(): void
    {
        Notification::fake();

        $this->post(route('organization.register', ['organization' => $this->organization->slug]), $this->payload('scoped@t1412.test'))
            ->assertRedirect();

        $user = User::where('email', 'scoped@t1412.test')->firstOrFail();
        $this->assertSame($this->organization->id, $user->organization_id);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * Doctrine T1043, etendue a la verification : une panne de transport mail
     * ne fait JAMAIS echouer une inscription. Sans la surcharge de
     * `sendEmailVerificationNotification()`, l'exception du listener framework
     * remonterait AVANT le `rescue()` du welcome.
     */
    public function test_registration_survives_a_verification_email_transport_failure(): void
    {
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'))->byDefault();
        Mail::shouldReceive('failures')->andReturn([])->byDefault();

        $this->post(route('register'), $this->payload('resilient@t1412.test'))->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'resilient@t1412.test']);
    }

    // ── 3. Le middleware `verified` est devenu effectif ─────────────────────

    public function test_an_unverified_member_is_sent_to_the_notice_on_a_verified_route(): void
    {
        $membre = User::factory()->unverified()->create(['organization_id' => $this->organization->id]);

        // Surface courte (routes/web.php:144)
        $this->actingAs($membre)->get(route('blog.create'))
            ->assertRedirect(route('verification.notice'));

        // Surface org (routes/web.php:865)
        $this->actingAs($membre)->get(route('organization.dossiers.index', ['organization' => $this->organization->slug]))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_member_passes_the_verified_routes(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($membre)->get(route('blog.create'))->assertOk();
        $this->actingAs($membre)->get(route('organization.dossiers.index', ['organization' => $this->organization->slug]))->assertOk();
    }

    public function test_the_notice_page_is_served_to_an_unverified_member(): void
    {
        $membre = User::factory()->unverified()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($membre)->get(route('verification.notice'))->assertOk();
    }

    // ── 4. Le lien signe verifie, un lien falsifie non ──────────────────────

    public function test_a_signed_link_marks_the_email_as_verified(): void
    {
        Event::fake([Verified::class]);
        $membre = User::factory()->unverified()->create(['organization_id' => $this->organization->id]);

        $lien = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $membre->id,
            'hash' => sha1($membre->email),
        ]);

        $this->actingAs($membre)->get($lien)->assertRedirect();

        $this->assertTrue($membre->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_a_tampered_hash_does_not_verify(): void
    {
        $membre = User::factory()->unverified()->create(['organization_id' => $this->organization->id]);

        $lien = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $membre->id,
            'hash' => sha1('autre@adresse.test'),
        ]);

        $this->actingAs($membre)->get($lien)->assertForbidden();
        $this->assertFalse($membre->fresh()->hasVerifiedEmail());
    }

    public function test_a_member_can_ask_the_email_again(): void
    {
        Notification::fake();
        $membre = User::factory()->unverified()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($membre)->post(route('verification.send'))->assertRedirect();

        Notification::assertSentTo($membre, VerifyEmail::class);
    }

    // ── 5. Tenant : le lien signe est platform-global et ne change rien ─────

    /**
     * La route de verification est platform-global (`ResolveUrlOrganization`).
     * Un membre de l'Organization A qui clique son lien alors que la
     * navigation resout l'Organization B est verifie quand meme — et son
     * `organization_id` ne bouge pas d'un pouce.
     */
    public function test_verification_is_organization_agnostic_and_never_moves_the_member(): void
    {
        $autre = Organization::factory()->create(['is_active' => true]);
        $membre = User::factory()->unverified()->create(['organization_id' => $autre->id]);
        app()->instance('current_organization', $this->organization);

        $lien = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $membre->id,
            'hash' => sha1($membre->email),
        ]);

        $this->actingAs($membre)->get($lien)->assertRedirect();

        $frais = $membre->fresh();
        $this->assertTrue($frais->hasVerifiedEmail());
        $this->assertSame($autre->id, $frais->organization_id);
    }

    // ── 6. Le cutover : les comptes legacy sont grandfathered ───────────────

    /**
     * Doctrine MASTER (07/09 17h08) : « legacy accounts trusted during
     * verification cutover ». La date posee est celle du CUTOVER, jamais
     * `created_at` — ce serait une fausse preuve historique.
     */
    public function test_the_cutover_grandfathers_legacy_accounts_at_the_migration_moment_not_at_creation(): void
    {
        $creation = Carbon::parse('2026-06-01 10:00:00');
        $legacy = User::factory()->unverified()->create(['organization_id' => $this->organization->id]);
        DB::table('users')->where('id', $legacy->id)->update(['created_at' => $creation]);

        $dejaVerifie = User::factory()->create(['organization_id' => $this->organization->id]);
        $dateVerif = $dejaVerifie->email_verified_at->copy();

        $this->assertNull($legacy->fresh()->email_verified_at);

        $avant = now()->subSecond();
        (require database_path('migrations/2026_09_07_170000_backfill_email_verified_at_for_pre_verification_accounts.php'))->up();
        $apres = now()->addSecond();

        $pose = $legacy->fresh()->email_verified_at;
        $this->assertNotNull($pose);
        $this->assertTrue($pose->between($avant, $apres), 'la date posee est celle du cutover');
        $this->assertFalse($pose->equalTo($creation), 'jamais created_at comme pseudo-preuve');
        // Un compte deja verifie garde SA date : le cutover ne remplit que des NULL.
        $this->assertTrue($dateVerif->equalTo($dejaVerifie->fresh()->email_verified_at));
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    /**
     * Sans lui, un middleware `verified` reste no-op et une route qui aurait
     * cesse d'exister feraient passer des gardes negatives par accident.
     */
    public function test_the_probe_really_distinguishes_verified_from_unverified(): void
    {
        $verifie = User::factory()->create(['organization_id' => $this->organization->id]);
        $nonVerifie = User::factory()->unverified()->create(['organization_id' => $this->organization->id]);

        $this->assertTrue($verifie->hasVerifiedEmail());
        $this->assertFalse($nonVerifie->hasVerifiedEmail());
        $this->actingAs($verifie)->get(route('blog.create'))->assertOk();
        $this->actingAs($nonVerifie)->get(route('blog.create'))->assertRedirect(route('verification.notice'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function payload(string $email): array
    {
        return [
            'name' => 'Dupont',
            'first_name' => 'Camille',
            'email' => $email,
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'Un-mot-de-passe-solide-1412!',
            'password_confirmation' => 'Un-mot-de-passe-solide-1412!',
        ];
    }
}
