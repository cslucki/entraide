<?php

namespace Tests\Feature;

use App\Console\Commands\DemoGooglePlayCommand;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Users\LoginAccountResolver;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TASK-1900 — organisation fictive de demonstration pour la revue Google Play.
 */
class TASK1900DemoGooglePlayCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TESTEUR = 'testeur-play@exemple-cyril.test';

    private function demo(): ?Organization
    {
        return Organization::withTrashed()->where('slug', DemoGooglePlayCommand::SLUG)->first();
    }

    public function test_la_simulation_n_ecrit_rien_et_l_adresse_du_testeur_est_obligatoire(): void
    {
        $this->artisan('demo:google-play')->assertFailed();
        $this->artisan('demo:google-play', ['--reviewer-email' => self::TESTEUR, '--dry-run' => true])->assertSuccessful();

        $this->assertNull($this->demo());
        $this->assertFalse(User::withoutGlobalScopes()->where('email', self::TESTEUR)->exists());
    }

    public function test_cree_une_organisation_fictive_fermee_sans_notification_et_un_testeur_membre_simple(): void
    {
        Mail::fake();
        Notification::fake();

        $this->artisan('demo:google-play', ['--reviewer-email' => self::TESTEUR])->assertSuccessful();

        $org = $this->demo();
        $this->assertNotNull($org);
        $this->assertFalse((bool) $org->is_public);
        $this->assertTrue((bool) $org->registration_disabled);
        $this->assertNull($org->admin_id);

        $comptes = User::withoutGlobalScopes()->where('organization_id', $org->id)->get();
        $this->assertCount(7, $comptes);
        // Aucune adresse reelle hors celle du testeur : domaine `.invalid`.
        $this->assertSame(6, $comptes->filter(fn ($u) => str_ends_with($u->email, '@'.DemoGooglePlayCommand::DOMAINE))->count());

        $testeur = $comptes->firstWhere('email', self::TESTEUR);
        $this->assertFalse((bool) $testeur->is_admin);
        $this->assertNull($testeur->login_blocked_at);
        $this->assertSame(0, LoopMember::query()->where('user_id', $testeur->id)->where('role', '!=', 'member')->count(), 'Membre simple dans chaque Boucle.');

        $this->assertSame(3, Loop::withoutGlobalScopes()->where('organization_id', $org->id)->count());
        $this->assertSame(2, ServiceRequest::withoutGlobalScopes()->where('organization_id', $org->id)->count());
        // Aucune categorie globale en PROD : la demo porte la sienne.
        $this->assertSame(0, Category::query()->whereNull('organization_id')->count());
        $this->assertSame($org->id, Category::query()->where('slug', DemoGooglePlayCommand::SLUG.'-entraide')->value('organization_id'));
        $this->assertSame(2, Service::withoutGlobalScopes()->where('organization_id', $org->id)->count());

        // Ecriture directe : ni email, ni notification.
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        $this->assertSame(0, MemberNotification::query()->count());

        // Les membres fictifs ne recoivent aucun email ; le testeur garde les siens.
        $fictif = $comptes->firstWhere('email', 'julien@'.DemoGooglePlayCommand::DOMAINE);
        $resolveur = app(NotificationPreferenceResolver::class);
        $this->assertGreaterThan(0, MemberNotificationPreference::query()->where('user_id', $fictif->id)->where('enabled', false)->count());
        $this->assertFalse($resolveur->allows($fictif, NotificationCatalogue::LOOP_MENTION_CREATED, NotificationCatalogue::CHANNEL_EMAIL));
        $this->assertTrue($resolveur->allows($testeur, NotificationCatalogue::LOOP_MENTION_CREATED, NotificationCatalogue::CHANNEL_EMAIL));
        $this->assertSame(0, MemberNotificationPreference::query()->where('user_id', $testeur->id)->count());
    }

    public function test_le_testeur_n_entre_dans_aucune_autre_organisation(): void
    {
        $autre = Organization::factory()->create(['slug' => 'autre-asso', 'is_active' => true]);
        $this->artisan('demo:google-play', ['--reviewer-email' => self::TESTEUR])->assertSuccessful();
        $testeur = User::withoutGlobalScopes()->where('email', self::TESTEUR)->firstOrFail();

        $this->actingAs($testeur)->get('/org/'.DemoGooglePlayCommand::SLUG.'/flux')->assertOk();
        $this->assertNotSame(200, $this->actingAs($testeur)->get("/org/{$autre->slug}/flux")->getStatusCode());
        $this->assertNotSame(200, $this->actingAs($testeur)->get('/admin')->getStatusCode());
    }

    public function test_rejouer_ne_change_rien_et_le_retrait_bloque_les_comptes(): void
    {
        $this->artisan('demo:google-play', ['--reviewer-email' => self::TESTEUR])->assertSuccessful();
        $this->artisan('demo:google-play', ['--reviewer-email' => self::TESTEUR])->assertSuccessful();
        $this->assertSame(1, Organization::withTrashed()->where('slug', DemoGooglePlayCommand::SLUG)->count());

        $this->artisan('demo:google-play', ['--purge' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($this->demo()->deleted_at);
        $this->assertFalse((bool) $this->demo()->is_public, 'Non publique par defaut.');

        $this->artisan('demo:google-play', ['--purge' => true])->assertSuccessful();
        $org = $this->demo();
        $this->assertNotNull($org->deleted_at);
        $this->assertSame(0, User::withoutGlobalScopes()->where('organization_id', $org->id)->whereNull('login_blocked_at')->count());
    }

    public function test_le_retrait_refuse_une_organisation_sans_marqueur(): void
    {
        $intruse = Organization::factory()->create(['slug' => DemoGooglePlayCommand::SLUG, 'description' => 'Vraie association']);

        $this->artisan('demo:google-play', ['--purge' => true])->assertFailed();
        $this->assertNull($intruse->fresh()->deleted_at);
    }

    public function test_l_option_public_la_rend_choisissable_a_la_connexion(): void
    {
        $this->artisan('demo:google-play', ['--reviewer-email' => self::TESTEUR, '--public' => true])->assertSuccessful();

        $this->assertTrue((bool) $this->demo()->is_public);
        $this->assertTrue(app(LoginAccountResolver::class)->organizationsProposables()->pluck('slug')->contains(DemoGooglePlayCommand::SLUG));
    }
}
