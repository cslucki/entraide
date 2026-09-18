<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\Crm\ShellCrmBridge;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1458 — Shell → CRM (Shell Welcome V3 §23 SW-12, Mini-CRM V2 §9, Growth
 * V3 §16) : au CLAIM (email verifie dans le navigateur du visiteur, meme
 * Organization), le Contact du membre est retrouve/cree/lie — `source =
 * shell_welcome`, `source_ref = guest_visitor_id`, provenance FIRST TOUCH
 * conservee, fait `shell_claimed` (une fois par contact + visiteur),
 * `crm_contact_linked` au journal. Jamais de Contact a chaque message ; le
 * transcript reste dans le cockpit Guest ; un CRM en panne ne casse rien.
 */
class TASK1458ShellCrmBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $superAdmin;

    private AcquisitionJourney $journey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $policies = app(GuestShellPolicyService::class);
        foreach (['a', 'b'] as $key) {
            $org = Organization::factory()->create(['slug' => "org-{$key}-1458", 'name' => strtoupper($key).' Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
            $policies->update($org, ['enabled' => true, 'max_messages' => 5]);
            OrganizationAiSetting::create(['organization_id' => $org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
            $this->{$key} = $org;
        }
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_ACCOUNT], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);
        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'demo', 'destination' => 'organization_home', 'acquisition_journey_key' => 'rentree', 'campaign' => 'sept-2026', 'active' => true, 'created_by' => $this->superAdmin->id]);
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    private function asBrowser(string $raw): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw));
    }

    private function message(string $raw, Organization $organization, string $text = 'Bonjour, je cherche un atelier'): TestResponse
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue parmi nous.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->forgetAuth();

        return $this->asBrowser($raw)->postJson(route('organization.shell.message', ['organization' => $organization->slug]), ['message' => $text, 'attribution' => ['shortcut' => 'demo', 'utm_campaign' => 'sept-2026']])->assertOk()->assertJsonPath('turn', 'answered');
    }

    private function visitorOf(string $raw, Organization $organization): GuestVisitor
    {
        return GuestVisitor::forOrganization($organization)->where('visitor_key_hash', GuestVisitorResolver::hash($raw))->firstOrFail();
    }

    private function forgetAuth(): void
    {
        auth()->logout();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function register(string $email, string $raw, Organization $organization): User
    {
        $this->forgetAuth();
        $this->asBrowser($raw)->post(route('organization.register', ['organization' => $organization->slug]), [
            'name' => 'Nouvelle Membre', 'first_name' => 'Nouvelle', 'email' => $email, 'phone' => '+33600000000', 'country_code' => 'FR',
            'password' => 'password-solide-1458', 'password_confirmation' => 'password-solide-1458',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return User::where('email', $email)->firstOrFail();
    }

    private function verify(User $user, string $raw): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
        $this->actingAs($user)->asBrowser($raw)->get($url)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_claim_finds_or_creates_and_links_the_contact_with_its_first_touch_provenance_once_and_never_per_message(): void
    {
        $raw = Str::random(64);
        $this->message($raw, $this->a);
        $this->message($raw, $this->a, 'Et un atelier le soir ?');
        $this->assertSame(0, CrmContact::count(), 'jamais de Contact a chaque message (SW-12)');

        $visitor = $this->visitorOf($raw, $this->a); // avant le claim : un claim reussi fait tourner la cle du cookie (TASK-1445)
        $user = $this->register('claim@example.test', $raw, $this->a);
        $this->assertSame(0, CrmContact::count(), 'l\'inscription seule ne cree pas de Contact commercial : le claim est le moment pertinent');
        $this->verify($user, $raw);
        $visitor = $visitor->fresh();
        $this->assertSame($user->id, $visitor->claimed_user_id);

        $contact = CrmContact::query()->sole();
        $this->assertSame([$this->a->id, $user->id, 'claim@example.test', CrmContact::SOURCE_SHELL_WELCOME, $visitor->id], [$contact->organization_id, $contact->user_id, $contact->email, $contact->source, $contact->source_ref]);
        $fact = $contact->events()->where('type', CrmContactEvent::TYPE_SHELL_CLAIMED)->get();
        $this->assertCount(1, $fact);
        $this->assertSame($contact->id.':shell_claimed:'.$visitor->id, $fact->first()->dedupe_key);
        $this->assertSame([$this->journey->id, 'sept-2026', 'demo', 1], [$fact->first()->payload['acquisition_journey_id'], $fact->first()->payload['utm_campaign'], $fact->first()->payload['shortcut'], $fact->first()->payload['conversations']], 'provenance FIRST TOUCH + compteur de CONVERSATIONS (deux messages = une conversation reprise), pas le transcript');
        $this->assertNull($fact->first()->author_user_id, 'fait systeme');
        $json = json_encode($fact->first()->payload);
        $this->assertStringNotContainsString('Bonjour', $json);
        $this->assertStringNotContainsString('Bienvenue', $json, 'le transcript reste dans le cockpit Guest');
        // MASTER #49 : identite CRM, jamais consentement — aucun champ de contactabilite touche, aucun mot « consent », cle technique jamais affichee.
        $this->assertNull($contact->do_not_contact_at, 'contactabilite jamais touchee par le bridge (ni opt-in ni opt-out)');
        $this->assertStringNotContainsString('consent', json_encode($contact->getAttributes()).json_encode($fact->first()->payload));
        $page = $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', [$this->a, $contact]))->assertOk()->getContent();
        $this->assertStringContainsString('data-crm-event="shell_claimed"', $page);
        $this->assertStringContainsString(__('crm.event.shell_claimed'), $page);
        $this->assertStringNotContainsString($fact->first()->dedupe_key, $page);
        $this->assertStringNotContainsString('Bienvenue', $page, 'aucun transcript sur la fiche');
        $linked = AcquisitionEvent::query()->where('event', AcquisitionEvent::CRM_CONTACT_LINKED)->sole();
        $this->assertSame([$user->id, $visitor->id, $this->journey->id], [$linked->user_id, $linked->guest_visitor_id, $linked->acquisition_journey_id]);

        // Rejeu de Verified (double clic sur le lien) : rien de plus.
        event(new Verified($user->fresh()));
        $this->assertSame(1, CrmContact::count());
        $this->assertSame(1, $contact->events()->where('type', CrmContactEvent::TYPE_SHELL_CLAIMED)->count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::CRM_CONTACT_LINKED)->count());
    }

    public function test_the_bridge_reuses_an_existing_contact_never_steals_and_never_crosses_tenants(): void
    {
        // Contact saisi a la main avant l'arrivee du visiteur : lie, jamais duplique, jamais ecrase.
        $existing = CrmContact::query()->create(['organization_id' => $this->a->id, 'email' => 'saisi@example.test', 'first_name' => 'Saisi', 'source' => CrmContact::SOURCE_MANUAL, 'source_ref' => 'carnet']);
        $raw = Str::random(64);
        $this->message($raw, $this->a);
        $user = $this->register('saisi@example.test', $raw, $this->a);
        $this->verify($user, $raw);
        $this->assertSame(1, CrmContact::count());
        $this->assertSame([$user->id, 'Saisi', CrmContact::SOURCE_MANUAL, 'carnet'], [$existing->fresh()->user_id, $existing->fresh()->first_name, $existing->fresh()->source, $existing->fresh()->source_ref], 'lie, jamais duplique, jamais ecrase');
        $this->assertSame(1, $existing->events()->where('type', CrmContactEvent::TYPE_SHELL_CLAIMED)->count());

        // Contact deja lie a un AUTRE membre : jamais vole ; la verification et le claim tiennent.
        $other = User::factory()->create(['organization_id' => $this->a->id, 'email_verified_at' => now()]);
        $stolen = CrmContact::query()->create(['organization_id' => $this->a->id, 'email' => 'vole@example.test', 'user_id' => $other->id, 'source' => CrmContact::SOURCE_MANUAL]);
        $raw2 = Str::random(64);
        $this->message($raw2, $this->a);
        $visitor2 = $this->visitorOf($raw2, $this->a);
        $user2 = $this->register('vole@example.test', $raw2, $this->a);
        $this->verify($user2, $raw2);
        $this->assertSame($user2->id, $visitor2->fresh()->claimed_user_id, 'le claim ne depend jamais du CRM');
        $this->assertSame($other->id, $stolen->fresh()->user_id);
        $this->assertSame(0, $stolen->events()->where('type', CrmContactEvent::TYPE_SHELL_CLAIMED)->count());

        // Cookie d'une AUTRE Organization : aucun claim, aucun Contact nulle part.
        $rawB = Str::random(64);
        $this->message($rawB, $this->b);
        $user3 = $this->register('ailleurs@example.test', $rawB, $this->a);
        $this->verify($user3, $rawB);
        $this->assertNull($this->visitorOf($rawB, $this->b)->claimed_user_id);
        $this->assertSame(0, CrmContact::query()->where('email', 'ailleurs@example.test')->count());
        $this->assertSame(0, CrmContact::query()->where('organization_id', $this->b->id)->count());

        // Le bridge appele DIRECTEMENT avec un visiteur d'une autre Organization, ou non rattache a CE membre : rien, nulle part (S1).
        $visitorB = $this->visitorOf($rawB, $this->b);
        $this->assertNull(app(ShellCrmBridge::class)->onClaim($visitorB, $user3), 'visiteur de B, membre de A');
        $visitorB->forceFill(['claimed_user_id' => User::factory()->create(['organization_id' => $this->b->id])->id, 'claimed_at' => now()])->save();
        $this->assertNull(app(ShellCrmBridge::class)->onClaim($visitorB->fresh(), User::factory()->create(['organization_id' => $this->b->id])), 'visiteur rattache a un AUTRE membre de B');
        $this->assertSame(0, CrmContact::query()->where('organization_id', $this->b->id)->count());
        $this->assertSame(0, CrmContact::query()->where('email', 'ailleurs@example.test')->count());
    }

    public function test_a_broken_crm_bridge_never_breaks_the_verification_nor_the_claim(): void
    {
        $this->app->bind(ShellCrmBridge::class, fn () => new class extends ShellCrmBridge
        {
            public function __construct() {}

            public function onClaim(GuestVisitor $visitor, User $user): ?CrmContact
            {
                throw new RuntimeException('CRM down');
            }
        });

        $raw = Str::random(64);
        $this->message($raw, $this->a);
        $visitor = $this->visitorOf($raw, $this->a);
        $user = $this->register('panne@example.test', $raw, $this->a);
        $this->verify($user, $raw);
        $this->assertSame($user->id, $visitor->fresh()->claimed_user_id);
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::EMAIL_VERIFIED)->count(), 'le fait produit est journalise meme si le CRM tombe');
        $this->assertSame(0, CrmContact::count());
    }
}
