<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AcquisitionJourney;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\Acquisition\GuestAttribution;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1447 — OrganizationShortcut (Growth V2 §5, MASTER Q75) : `/s/{code}` →
 * 302 canonique avec attribution transparente ; au premier geste Guest, le
 * Shortcut est RELU en base (Journey exacte, campagne), jamais cru sur parole ;
 * first touch wins ; SuperAdmin-managed.
 */
class TASK1447OrganizationShortcutTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $superAdmin;

    private User $adminA;

    private AcquisitionJourney $journeyV1;

    private OrganizationShortcut $demo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $policies = app(GuestShellPolicyService::class);
        foreach (['a', 'b'] as $key) {
            $org = Organization::factory()->create(['slug' => "org-{$key}-14xx", 'name' => strtoupper($key).' Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
            $policies->update($org, ['enabled' => true, 'max_messages' => 5]);
            OrganizationAiSetting::create(['organization_id' => $org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
            $this->{$key} = $org;
        }
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journeyV1 = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => 'account'], $this->adminA);
        $journeys->publish($this->journeyV1, $this->adminA);

        $this->demo = OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'demo', 'destination' => 'organization_home', 'acquisition_journey_key' => 'rentree', 'campaign' => 'sept-2026', 'active' => true, 'created_by' => $this->superAdmin->id]);
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    private function asBrowser(string $raw): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw));
    }

    private function message(Organization $organization, array $attribution, ?string $raw = null): TestResponse
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $client = $raw === null ? $this : $this->asBrowser($raw);

        return $client->postJson(route('organization.shell.message', ['organization' => $organization->slug]), ['message' => 'Bonjour', 'attribution' => $attribution])->assertOk()->assertJsonPath('turn', 'answered');
    }

    // ── 1. La redirection canonique ────────────────────────────────────────

    public function test_the_shortcut_redirects_to_a_canonical_destination_with_a_transparent_query_and_writes_nothing(): void
    {
        $url = route('shortcut', ['code' => 'demo']);
        $this->assertSame('/s/demo', parse_url($url, PHP_URL_PATH));

        $response = $this->get($url.'?utm_source=linkedin&utm_medium=<script>&journey=autre-chose&campaign=hack')->assertStatus(302)->assertCookieMissing(GuestVisitorResolver::COOKIE);
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith(route('organization.home', ['organization' => 'org-a-14xx']).'?', $target);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $this->assertSame('demo', $query['shortcut']);
        $this->assertSame('rentree', $query['journey'], 'la Journey vient du Shortcut, pas de ?journey=');
        $this->assertSame('sept-2026', $query['campaign'], 'la campagne vient du Shortcut, pas de ?campaign=');
        $this->assertSame('linkedin', $query['utm_source']);
        $this->assertSame('script', $query['utm_medium'], 'UTM borne, rien d\'executable');
        $this->assertSame(0, GuestVisitor::count(), 'un clic n\'ecrit rien');

        $signup = OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'inscription', 'destination' => 'signup', 'active' => true]);
        $this->assertStringStartsWith(route('organization.register', ['organization' => 'org-a-14xx']).'?shortcut=inscription', $this->get(route('shortcut', ['code' => 'inscription']))->assertStatus(302)->headers->get('Location'));

        $middleware = Route::getRoutes()->getByName('shortcut')->gatherMiddleware();
        $this->assertNotEmpty(array_filter($middleware, fn ($m) => str_starts_with((string) $m, 'throttle:')));
    }

    public function test_an_inactive_unknown_or_closed_shortcut_is_a_404_and_never_an_open_redirect(): void
    {
        $this->get(route('shortcut', ['code' => 'inconnu']))->assertNotFound();
        $this->demo->forceFill(['active' => false])->save();
        $this->get(route('shortcut', ['code' => 'demo']))->assertNotFound();
        $this->demo->forceFill(['active' => true])->save();
        $this->a->update(['is_public' => false]);
        $this->get(route('shortcut', ['code' => 'demo']))->assertNotFound();
        $this->a->update(['is_public' => true, 'is_active' => false]);
        $this->get(route('shortcut', ['code' => 'demo']))->assertNotFound();

        foreach (['org', 'admin', 'login', 'register', 'api', 'ab', 'Demo', 'a b', str_repeat('x', 33)] as $bad) {
            $this->assertFalse(OrganizationShortcut::isValidCode($bad), $bad);
        }
        $this->assertFalse(Schema::hasColumn('organization_shortcuts', 'destination_url'), 'aucune URL libre');
        $this->assertFalse(Schema::hasColumn('organization_shortcuts', 'url'));
    }

    // ── 2. L'attribution canonique au premier geste ────────────────────────

    public function test_the_first_guest_gesture_freezes_the_canonical_attribution_from_the_shortcut_never_from_the_browser(): void
    {
        // Un `journey` libre dans le payload est REJETE (422, voir test 6) ; ici, seul l'utm_campaign externe tente de primer.
        $this->message($this->a, ['shortcut' => 'demo', 'utm_source' => 'linkedin', 'utm_medium' => 'post', 'utm_campaign' => 'hack']);
        $visitor = GuestVisitor::firstOrFail();
        $this->assertSame('demo', $visitor->shortcut);
        $this->assertSame($this->journeyV1->id, $visitor->acquisition_journey_id, 'la version publiee EXACTE au moment du geste');
        $this->assertSame('sept-2026', $visitor->utm_campaign, 'la campagne canonique du Shortcut prime');
        $this->assertSame('linkedin', $visitor->utm_source);
        $this->assertSame('post', $visitor->utm_medium);
        $this->assertTrue($visitor->acquisitionJourney->is($this->journeyV1));

        // Meme appele directement, le resolver n'accorde JAMAIS une Journey ou une campagne declaree par le navigateur sans Shortcut.
        $direct = app(GuestAttribution::class)->resolve($this->a->fresh(), ['journey' => 'rentree', 'campaign' => 'hack', 'utm_source' => 'x']);
        $this->assertNull($direct['acquisition_journey_id'], 'aucune Journey sans Shortcut, quoi que declare le navigateur');
        $this->assertNull($direct['shortcut']);
        $this->assertNull($direct['utm_campaign']);
        $this->assertSame('x', $direct['utm_source']);
    }

    public function test_a_shortcut_of_another_organization_or_an_inactive_one_gives_no_attribution(): void
    {
        $foreign = OrganizationShortcut::query()->create(['organization_id' => $this->b->id, 'code' => 'bside', 'destination' => 'organization_home', 'acquisition_journey_key' => 'rentree', 'campaign' => 'b-camp', 'active' => true]);
        $this->message($this->a, ['shortcut' => 'bside', 'utm_source' => 'x']);
        $visitor = GuestVisitor::forOrganization($this->a)->firstOrFail();
        $this->assertNull($visitor->shortcut, 'le Shortcut de B ne vaut rien chez A');
        $this->assertNull($visitor->acquisition_journey_id);
        $this->assertNull($visitor->utm_campaign);
        $this->assertSame('x', $visitor->utm_source, 'les UTM externes bornes restent');

        $this->demo->forceFill(['active' => false])->save();
        $this->message($this->a, ['shortcut' => 'demo'], Str::random(64));
        $this->assertNull(GuestVisitor::forOrganization($this->a)->orderByDesc('first_seen_at')->orderByDesc('id')->firstOrFail()->shortcut, 'inactif = rien');
        $this->assertSame(2, GuestVisitor::count());
    }

    public function test_first_touch_wins_and_a_retired_journey_version_stays_frozen_on_the_visitor(): void
    {
        $raw = Str::random(64);
        $this->message($this->a, ['shortcut' => 'demo'], $raw);
        $visitor = GuestVisitor::firstOrFail();
        $this->assertSame($this->journeyV1->id, $visitor->acquisition_journey_id);

        // Une v2 est publiee ensuite : l'histoire ne se reecrit pas.
        $journeys = app(AcquisitionJourneyService::class);
        $v2 = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée v2', 'locale' => 'fr', 'conversion_goal' => 'contact'], $this->adminA);
        $journeys->publish($v2, $this->adminA);
        $this->assertSame(AcquisitionJourney::STATE_RETIRED, $this->journeyV1->fresh()->state);

        $other = OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'autre', 'destination' => 'signup', 'acquisition_journey_key' => 'rentree', 'campaign' => 'autre-camp', 'active' => true]);
        $this->message($this->a, ['shortcut' => 'autre', 'utm_source' => 'twitter'], $raw);
        $fresh = $visitor->fresh();
        $this->assertSame('demo', $fresh->shortcut, 'first touch wins');
        $this->assertSame($this->journeyV1->id, $fresh->acquisition_journey_id, 'la v1 figee, pas la v2');
        $this->assertSame('sept-2026', $fresh->utm_campaign);
        $this->assertSame(1, GuestVisitor::count());

        // Un NOUVEAU visiteur, lui, entre par la v2.
        $this->message($this->a, ['shortcut' => 'autre'], Str::random(64));
        $this->assertSame($v2->id, GuestVisitor::forOrganization($this->a)->where('shortcut', 'autre')->firstOrFail()->acquisition_journey_id);
    }

    public function test_a_tampered_or_oversized_attribution_payload_is_bounded_or_rejected(): void
    {
        $this->postJson(route('organization.shell.message', ['organization' => $this->a->slug]), ['message' => 'Bonjour', 'attribution' => ['shortcut' => str_repeat('d', 33)]])->assertUnprocessable();
        $this->postJson(route('organization.shell.message', ['organization' => $this->a->slug]), ['message' => 'Bonjour', 'attribution' => ['journey' => 'x', 'shortcut' => 'demo']])->assertUnprocessable();
        $this->assertSame(0, GuestVisitor::count());

        $this->message($this->a, ['shortcut' => 'DEMO', 'utm_source' => str_repeat('s', 150).'<b>']);
        $visitor = GuestVisitor::firstOrFail();
        $this->assertNull($visitor->shortcut, 'un code invalide (majuscules) ne resout rien');
        $this->assertSame(100, mb_strlen((string) $visitor->utm_source), 'UTM borne a 100');
        $this->assertStringNotContainsString('<', (string) $visitor->utm_source);
    }

    // ── 3. Gouvernance SuperAdmin ──────────────────────────────────────────

    public function test_only_the_platform_admin_manages_shortcuts(): void
    {
        $index = route('admin.shortcuts');
        $this->assertSame('/admin/shortcuts', parse_url($index, PHP_URL_PATH));
        $this->get($index)->assertRedirect();
        $this->actingAs($this->adminA)->get($index)->assertForbidden();
        $this->actingAs($this->adminA)->post(route('admin.shortcuts.store'), ['organization_id' => $this->a->id, 'code' => 'mine', 'destination' => 'signup'])->assertForbidden();

        $html = $this->actingAs($this->superAdmin)->get($index)->assertOk()->getContent();
        $this->assertStringContainsString('data-shortcut-row="demo" data-shortcut-active="1"', $html);
        $this->assertStringContainsString(route('shortcut', ['code' => 'demo']), $html);

        $this->actingAs($this->superAdmin)->post(route('admin.shortcuts.store'), ['organization_id' => $this->a->id, 'code' => 'admin', 'destination' => 'signup'])->assertSessionHasErrors('code');
        $this->actingAs($this->superAdmin)->post(route('admin.shortcuts.store'), ['organization_id' => $this->a->id, 'code' => 'demo', 'destination' => 'signup'])->assertSessionHasErrors('code');
        $this->actingAs($this->superAdmin)->post(route('admin.shortcuts.store'), ['organization_id' => $this->a->id, 'code' => 'ok-code', 'destination' => 'https://evil.example'])->assertSessionHasErrors('destination');
        $this->actingAs($this->superAdmin)->post(route('admin.shortcuts.store'), ['organization_id' => $this->a->id, 'code' => 'ok-code', 'destination' => 'signup', 'acquisition_journey_key' => 'inconnue'])->assertSessionHasErrors('acquisition_journey_key');
        $this->actingAs($this->superAdmin)->post(route('admin.shortcuts.store'), ['organization_id' => $this->b->id, 'code' => 'ok-code', 'destination' => 'signup', 'acquisition_journey_key' => 'rentree'])->assertSessionHasErrors('acquisition_journey_key', 'la cle de Journey est celle de l\'Organization visee');
        $this->actingAs($this->superAdmin)->post(route('admin.shortcuts.store'), ['organization_id' => $this->a->id, 'code' => 'ok-code', 'destination' => 'signup', 'acquisition_journey_key' => 'rentree', 'campaign' => 'ok'])->assertRedirect($index)->assertSessionHasNoErrors();
        $created = OrganizationShortcut::query()->where('code', 'ok-code')->firstOrFail();
        $this->assertSame($this->superAdmin->id, $created->created_by);

        $this->actingAs($this->superAdmin)->patch(route('admin.shortcuts.toggle', $created))->assertRedirect($index);
        $this->assertFalse($created->fresh()->active);
        $this->get(route('shortcut', ['code' => 'ok-code']))->assertNotFound();
    }
}
