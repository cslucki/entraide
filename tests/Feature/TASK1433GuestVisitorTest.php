<?php

namespace Tests\Feature;

use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1433 — SW-3 : le visiteur du Shell Welcome (Addendum V2 §6).
 *
 * Ce qui est mesure :
 * 1. consulter n'ecrit rien ; le premier geste cree le visiteur et pose un
 *    cookie first-party OPAQUE (HttpOnly, SameSite=Lax) dont seule
 *    l'empreinte est stockee — jamais l'IP, jamais la cle en clair ;
 * 2. une cle = un visiteur PAR Organization, idempotent, `last_seen_at` et
 *    `expires_at` suivent la retention de la politique de l'Organization ;
 * 3. un cookie malforme est ignore et une cle neuve est posee ;
 * 4. un visiteur expire n'existe plus et n'est jamais ressuscite ;
 * 5. ce que le visiteur DECLARE est stocke borne, rien n'est extrait ;
 * 6. la purge planifiee ne supprime que les expires (dry-run : rien).
 */
class TASK1433GuestVisitorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1433', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1433', 'is_active' => true, 'is_public' => true, 'locale' => 'en']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    private function request(?string $cookie = null): Request
    {
        return Request::create('/', 'GET', cookies: $cookie === null ? [] : [GuestVisitorResolver::COOKIE => $cookie]);
    }

    private function resolver(): GuestVisitorResolver
    {
        return app()->make(GuestVisitorResolver::class);
    }

    // ── 1. Rien a la visite ; premier geste = visiteur + cookie opaque ─────

    public function test_a_visit_writes_nothing_and_the_first_gesture_creates_the_visitor_with_an_opaque_cookie(): void
    {
        $this->assertNull($this->resolver()->find($this->request(), $this->orgA));
        $this->assertSame(0, GuestVisitor::count());
        $this->assertFalse(Cookie::hasQueued(GuestVisitorResolver::COOKIE));

        $visitor = $this->resolver()->ensure($this->request(), $this->orgA, ['locale' => 'fr', 'referrer' => 'https://example.org/atelier', 'utm_source' => 'linkedin', 'utm_campaign' => 'septembre', 'shortcut' => 'atelier-ia']);

        $this->assertSame(1, GuestVisitor::count());
        $this->assertSame($this->orgA->id, $visitor->organization_id);
        $this->assertSame('fr', $visitor->locale);
        $this->assertSame('linkedin', $visitor->utm_source);
        $this->assertSame('atelier-ia', $visitor->shortcut);
        $this->assertNull($visitor->claimed_user_id);
        $this->assertTrue($visitor->first_seen_at->equalTo($visitor->last_seen_at));
        $this->assertSame(now()->addDays(90)->toDateString(), $visitor->expires_at->toDateString(), 'retention par defaut = 90 jours');

        $cookie = Cookie::queued(GuestVisitorResolver::COOKIE);
        $this->assertNotNull($cookie, 'le cookie first-party doit etre pose');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame(64, strlen($cookie->getValue()));
        $this->assertTrue(ctype_alnum($cookie->getValue()));
        $this->assertGreaterThan(now()->addDays(364)->getTimestamp(), $cookie->getExpiresTime(), 'le cookie vit aussi longtemps que la retention maximale');

        // Seule l'EMPREINTE est en base : la cle n'apparait dans aucune colonne.
        $this->assertSame(hash('sha256', $cookie->getValue()), $visitor->visitor_key_hash);
        $this->assertStringNotContainsString($cookie->getValue(), json_encode($visitor->getAttributes()));
        foreach (['ip', 'ip_address', 'user_agent', 'fingerprint', 'visitor_key'] as $forbidden) {
            $this->assertFalse(Schema::hasColumn('guest_visitors', $forbidden), "colonne interdite : {$forbidden}");
        }
    }

    // ── 2. Une cle = un visiteur PAR Organization, idempotent ──────────────

    public function test_the_same_cookie_is_one_visitor_per_organization_and_follows_each_retention(): void
    {
        app(GuestShellPolicyService::class)->update($this->orgB, ['retention_days' => 30]);
        $key = Str::random(64);

        $first = $this->resolver()->ensure($this->request($key), $this->orgA);
        $again = $this->resolver()->ensure($this->request($key), $this->orgA);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, GuestVisitor::count());
        $this->assertFalse(Cookie::hasQueued(GuestVisitorResolver::COOKIE), 'un cookie valide n\'est pas re-pose');

        $this->travel(10)->days();
        $later = $this->resolver()->ensure($this->request($key), $this->orgA);
        $this->assertSame($first->id, $later->id);
        $this->assertTrue($later->first_seen_at->equalTo($first->first_seen_at));
        $this->assertSame(now()->toDateString(), $later->last_seen_at->toDateString());
        $this->assertSame(now()->addDays(90)->toDateString(), $later->expires_at->toDateString(), 'la retention repart du dernier geste');

        $inB = $this->resolver()->ensure($this->request($key), $this->orgB, ['locale' => 'en']);
        $this->assertNotSame($first->id, $inB->id);
        $this->assertSame($first->visitor_key_hash, $inB->visitor_key_hash);
        $this->assertSame(2, GuestVisitor::count());
        $this->assertSame(now()->addDays(30)->toDateString(), $inB->expires_at->toDateString(), 'retention de l\'Organization B');

        $this->assertSame($first->id, $this->resolver()->find($this->request($key), $this->orgA)?->id);
        $this->assertSame($inB->id, $this->resolver()->find($this->request($key), $this->orgB)?->id);
    }

    // ── 3. Cookie malforme = absent ─────────────────────────────────────────

    public function test_a_malformed_cookie_is_ignored_and_a_fresh_key_is_issued(): void
    {
        foreach (['court', str_repeat('a', 63), str_repeat('a', 64).'b', 'x'.str_repeat('-', 63), "'; DROP TABLE guest_visitors; --".str_repeat('a', 33)] as $bad) {
            $this->assertNull($this->resolver()->find($this->request($bad), $this->orgA), $bad);
        }

        $visitor = $this->resolver()->ensure($this->request(str_repeat('a', 63)), $this->orgA);
        $issued = Cookie::queued(GuestVisitorResolver::COOKIE)?->getValue();
        $this->assertNotNull($issued);
        $this->assertNotSame(str_repeat('a', 63), $issued);
        $this->assertSame(hash('sha256', $issued), $visitor->visitor_key_hash);
    }

    // ── 4. Expire = disparu, jamais ressuscite ─────────────────────────────

    public function test_an_expired_visitor_is_gone_and_never_resurrected(): void
    {
        $key = Str::random(64);
        $old = $this->resolver()->ensure($this->request($key), $this->orgA);

        $this->travel(91)->days();
        $this->assertNull($this->resolver()->find($this->request($key), $this->orgA));

        $fresh = $this->resolver()->ensure($this->request($key), $this->orgA);
        $this->assertNotSame($old->id, $fresh->id, 'une ligne expiree n\'est pas reprise');
        $this->assertSame(1, GuestVisitor::count());
        $this->assertNull(GuestVisitor::find($old->id));
        $this->assertSame(now()->toDateString(), $fresh->first_seen_at->toDateString());
    }

    // ── 5. Declare, borne, jamais extrait ──────────────────────────────────

    public function test_declared_facts_are_stored_bounded_and_nothing_is_extracted(): void
    {
        $visitor = $this->resolver()->ensure($this->request(), $this->orgA);
        $this->assertNull($visitor->declared_first_name);

        $this->resolver()->declare($visitor, '  Léa  ', 'freelance', str_repeat('i', 300));
        $visitor->refresh();
        $this->assertSame('Léa', $visitor->declared_first_name);
        $this->assertSame('freelance', $visitor->declared_role);
        $this->assertSame(255, mb_strlen($visitor->declared_interest));

        // Une declaration vide ne gomme pas ce qui a ete dit.
        $this->resolver()->declare($visitor, '', null, '   ');
        $visitor->refresh();
        $this->assertSame('Léa', $visitor->declared_first_name);
        $this->assertSame('freelance', $visitor->declared_role);
    }

    // ── 6. Purge planifiee ─────────────────────────────────────────────────

    public function test_the_purge_command_removes_only_expired_visitors_and_is_scheduled_daily(): void
    {
        $keep = $this->resolver()->ensure($this->request(Str::random(64)), $this->orgA);
        $this->travel(-100)->days();
        $expired = $this->resolver()->ensure($this->request(Str::random(64)), $this->orgB);
        $this->travelBack();
        $this->assertTrue($expired->fresh()->isExpired());
        $this->assertFalse($keep->fresh()->isExpired());

        $this->artisan('guest:purge-expired', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, GuestVisitor::count());

        $this->artisan('guest:purge-expired')->assertSuccessful();
        $this->assertSame(1, GuestVisitor::count());
        $this->assertNotNull(GuestVisitor::find($keep->id));

        $scheduled = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'guest:purge-expired'));
        $this->assertNotNull($scheduled, 'la purge doit etre planifiee');
        $this->assertSame('0 0 * * *', $scheduled->expression);
    }
}
