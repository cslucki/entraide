<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1451 — B4-A Workshop Sessions foundation (Growth V3 §7, MASTER Q78) :
 * les sessions d'un atelier, Organization-scoped et coherentes avec leur
 * Workshop, publiables et visibles sur la page publique de l'atelier ;
 * capacite INFORMATIVE ; aucune inscription, aucun interet Guest, aucune
 * meeting_url.
 *
 * Preuves :
 *  1. cycle OrgAdmin : brouillon (saisie dans le fuseau, stockage UTC) →
 *     edition → publication → page publique (date locale, fuseau, lieu,
 *     capacite indicative, aucune CTA d'inscription) → annulation → disparue ;
 *  2. la page publique ne montre que les sessions PUBLIEES A VENIR d'un
 *     atelier PUBLIE ; brouillon / annulee / passee invisibles ; atelier retire
 *     = 404 malgre ses sessions ; rien de secret ;
 *  3. tenant : la session herite de l'Organization du Workshop ; atelier
 *     d'ailleurs = 404 sur les routes ; session d'un autre atelier = 404 ;
 *     admin d'ailleurs / membre refuses ; SuperAdmin passe ;
 *  4. regles : fuseau IANA valide, fin apres debut, capacite bornee, annulee
 *     non editable, seule un brouillon se publie ; `workshop_session` n'a
 *     toujours aucune route publique (PageContext NULL, pas de faux contexte).
 */
class TASK1451WorkshopSessionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    private User $member;

    private User $superAdmin;

    private Workshop $workshopA;

    private Workshop $workshopB;

    private WorkshopSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');

        $this->a = Organization::factory()->create(['slug' => 'org-a-1451', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1451', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->b->update(['admin_id' => $this->adminB->id]);
        $this->member = User::factory()->create(['organization_id' => $this->a->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);

        $workshops = app(WorkshopService::class);
        $this->workshopA = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'duration_minutes' => 90, 'locale' => 'fr'], $this->adminA);
        $workshops->publish($this->workshopA, $this->adminA);
        $this->workshopB = $workshops->create($this->b, ['title' => 'Atelier B', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $this->adminB);
        $this->service = app(WorkshopSessionService::class);
    }

    private function makeSession(Workshop $workshop, User $actor, array $overrides = []): WorkshopSession
    {
        return $this->service->create($workshop, $overrides + ['starts_at' => '2026-10-01 18:30', 'ends_at' => '2026-10-01 20:00', 'timezone' => 'Europe/Paris', 'capacity' => 12, 'location' => 'En ligne (lien envoyé aux inscrits)'], $actor);
    }

    private function publicUrl(Organization $organization, string $slug = 'ia-90'): string
    {
        return route('organization.workshop.show', ['organization' => $organization->slug, 'workshop' => $slug]);
    }

    // ── 1. Le cycle OrgAdmin ───────────────────────────────────────────────

    public function test_the_org_admin_drafts_edits_publishes_and_cancels_a_session_and_the_public_page_follows(): void
    {
        $sessionsUrl = route('organization.admin.workshops.sessions', [$this->a, $this->workshopA]);
        $this->actingAs($this->adminA)->get($sessionsUrl)->assertOk()->assertSee('data-session-empty', false);
        $this->assertStringContainsString('data-workshop-sessions="'.$this->workshopA->id.'"', $this->actingAs($this->adminA)->get(route('organization.admin.workshops', $this->a))->getContent());

        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.sessions.store', [$this->a, $this->workshopA]), [
            'starts_at' => '2026-10-01T18:30', 'ends_at' => '2026-10-01T20:00', 'timezone' => 'Europe/Paris', 'capacity' => 12, 'location' => 'Marseille, La Coque',
        ])->assertSessionHasNoErrors()->assertRedirect($sessionsUrl);

        $session = WorkshopSession::query()->sole();
        $this->assertTrue($session->isDraft());
        $this->assertSame($this->a->id, $session->organization_id, 'la session herite de l\'Organization du Workshop');
        $this->assertSame($this->workshopA->id, $session->workshop_id);
        $this->assertSame('2026-10-01 16:30:00', $session->starts_at->toDateTimeString(), 'saisie 18h30 Paris (UTC+2) = 16h30 UTC');
        $this->assertSame('2026-10-01 18:00:00', $session->ends_at->toDateTimeString());
        $this->assertSame('Europe/Paris', $session->timezone);
        $this->assertSame('2026-10-01 18:30', $session->localStartsAt()->format('Y-m-d H:i'));
        $this->assertSame($this->adminA->id, $session->created_by);
        $this->assertStringNotContainsString('data-workshop-session=', $this->get($this->publicUrl($this->a))->assertOk()->getContent(), 'un brouillon n\'est pas public');

        // Edition dans un autre fuseau : la meme heure locale change l'instant UTC.
        $this->actingAs($this->adminA)->put(route('organization.admin.workshops.sessions.update', [$this->a, $this->workshopA, $session]), [
            'starts_at' => '2026-10-01T18:30', 'ends_at' => '2026-10-01T20:00', 'timezone' => 'America/Toronto', 'capacity' => 20, 'location' => 'Montréal',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $session->refresh();
        $this->assertSame('2026-10-01 22:30:00', $session->starts_at->toDateTimeString(), '18h30 Toronto (UTC-4) = 22h30 UTC');
        $this->assertSame(['America/Toronto', 20, 'Montréal'], [$session->timezone, $session->capacity, $session->location]);

        // Publication : la page publique la montre, en heure locale, sans inscription.
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.sessions.publish', [$this->a, $this->workshopA, $session]))->assertRedirect();
        $session->refresh();
        $this->assertTrue($session->isPublished());
        $this->assertNotNull($session->published_at);
        $html = $this->get($this->publicUrl($this->a))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-session="'.$session->id.'"', $html);
        $this->assertStringContainsString('18h30', $html, 'l\'heure LOCALE de la session, pas l\'UTC');
        $this->assertStringContainsString('America/Toronto', $html);
        $this->assertStringContainsString('Montréal', $html);
        $this->assertStringContainsString('data-workshop-session-capacity', $html);
        $this->assertStringNotContainsString('data-workshop-guest-register-hint', $html, 'TASK-1462 (audit F4) : un membre connecte n\'a plus la promesse perimee ni le conseil Guest');
        $this->assertStringNotContainsString('data-workshop-sessions-soon', $html);
        $this->assertStringNotContainsString('meeting', $html);
        $this->assertStringNotContainsString($this->adminA->email, $html);
        // Le layout porte ses propres formulaires (langue, deconnexion...) : la mesure porte sur l'article de l'atelier.
        // TASK-1452/1453 ont livre les gestes : ici le lecteur est un MEMBRE verifie de l'Organization (actingAs adminA) —
        // il voit le geste membre (confirmer sa participation), jamais le geste Guest.
        $article = substr($html, strpos($html, '<article'), strpos($html, '</article>') - strpos($html, '<article'));
        $this->assertStringNotContainsString('data-workshop-interest', $article, 'un membre connecte n\'a pas le geste Guest');
        $this->assertStringContainsString('data-workshop-register="'.$session->id.'"', $article, 'le geste membre (TASK-1453) est le seul formulaire de l\'article');
        $this->assertSame(1, substr_count($article, '<form'), 'un seul formulaire dans l\'article : celui du membre');
        // Le meme atelier vu par un VISITEUR (l'admin reste connecte entre deux requetes : on le deconnecte) : le geste Guest
        // et le conseil honnete « compte + email verifie, puis retour ici » (TASK-1462, audit F4) — jamais une promesse perimee.
        auth()->logout();
        $this->flushSession();
        $guestHtml = $this->get($this->publicUrl($this->a))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-interest', $guestHtml);
        $this->assertStringContainsString('data-workshop-guest-register-hint', $guestHtml);
        $this->assertStringNotContainsString('prochainement', $guestHtml, 'aucune promesse perimee');

        // Publier deux fois : 404 ; annuler : disparue de la page publique ; annulee non editable.
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.sessions.publish', [$this->a, $this->workshopA, $session]))->assertNotFound();
        $this->actingAs($this->adminA)->delete(route('organization.admin.workshops.sessions.cancel', [$this->a, $this->workshopA, $session]))->assertRedirect();
        $this->assertTrue($session->fresh()->isCancelled());
        $this->assertStringNotContainsString('data-workshop-session="', $this->get($this->publicUrl($this->a))->assertOk()->getContent());
        $this->actingAs($this->adminA)->delete(route('organization.admin.workshops.sessions.cancel', [$this->a, $this->workshopA, $session]))->assertNotFound();
        // Annulee = figee : une edition par ailleurs VALIDE (debut avant la fin existante, meme fuseau) est refusee, rien ne bouge.
        $frozenStart = $session->fresh()->starts_at->toDateTimeString();
        $this->actingAs($this->adminA)->put(route('organization.admin.workshops.sessions.update', [$this->a, $this->workshopA, $session]), ['starts_at' => '2026-10-01T17:00', 'ends_at' => '2026-10-01T20:00', 'timezone' => 'America/Toronto'])->assertSessionHasErrors('starts_at');
        $this->assertSame($frozenStart, $session->fresh()->starts_at->toDateTimeString());
        try {
            $this->service->update($session->fresh(), ['starts_at' => '2026-10-01 17:00', 'ends_at' => '2026-10-01 20:00', 'timezone' => 'America/Toronto'], $this->adminA);
            $this->fail('une session annulee ne s\'edite pas');
        } catch (LogicException) {
        }
    }

    // ── 2. La page publique, fail-closed ───────────────────────────────────

    public function test_the_public_page_lists_only_published_upcoming_sessions_of_a_published_workshop(): void
    {
        $upcoming = $this->makeSession($this->workshopA, $this->adminA);
        $this->service->publish($upcoming, $this->adminA);
        $draft = $this->makeSession($this->workshopA, $this->adminA, ['starts_at' => '2026-10-05 18:30', 'ends_at' => null]);
        $cancelled = $this->makeSession($this->workshopA, $this->adminA, ['starts_at' => '2026-10-06 18:30', 'ends_at' => null]);
        $this->service->publish($cancelled, $this->adminA);
        $this->service->cancel($cancelled, $this->adminA);
        $past = $this->makeSession($this->workshopA, $this->adminA, ['starts_at' => '2026-09-01 18:30', 'ends_at' => '2026-09-01 20:00']);
        $this->service->publish($past, $this->adminA);
        $later = $this->makeSession($this->workshopA, $this->adminA, ['starts_at' => '2026-11-01 09:00', 'ends_at' => null]);
        $this->service->publish($later, $this->adminA);

        $html = $this->get($this->publicUrl($this->a))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-session="'.$upcoming->id.'"', $html);
        $this->assertStringContainsString('data-workshop-session="'.$later->id.'"', $html);
        foreach ([$draft, $cancelled, $past] as $hidden) {
            $this->assertStringNotContainsString('data-workshop-session="'.$hidden->id.'"', $html);
        }
        $this->assertLessThan(strpos($html, 'data-workshop-session="'.$later->id.'"'), strpos($html, 'data-workshop-session="'.$upcoming->id.'"'), 'la plus proche d\'abord');
        $this->assertSame([$upcoming->id, $later->id], $this->workshopA->publicUpcomingSessions()->pluck('id')->all());

        // Un atelier retire : 404, sessions publiees ou non.
        app(WorkshopService::class)->retire($this->workshopA, $this->adminA);
        $this->get($this->publicUrl($this->a))->assertNotFound();

        // Le meme slug chez B (brouillon) : 404 aussi, ses sessions n'existent pas publiquement.
        $sb = $this->makeSession($this->workshopB, $this->adminB);
        $this->service->publish($sb, $this->adminB);
        $this->get($this->publicUrl($this->b))->assertNotFound();
    }

    // ── 3. Tenant ──────────────────────────────────────────────────────────

    public function test_sessions_are_tenant_scoped_through_their_workshop_actors_and_routes(): void
    {
        $sa = $this->makeSession($this->workshopA, $this->adminA);
        $sb = $this->makeSession($this->workshopB, $this->adminB);
        $this->assertSame($this->b->id, $sb->organization_id);

        // Acteurs.
        try {
            $this->makeSession($this->workshopA, $this->member);
            $this->fail('un membre n\'ecrit pas');
        } catch (AuthorizationException) {
        }
        try {
            $this->service->publish($sa, $this->adminB);
            $this->fail('l\'admin d\'une autre Organization n\'ecrit pas');
        } catch (AuthorizationException) {
        }
        $this->assertTrue($this->service->publish($sa, $this->superAdmin)->isPublished());
        $bySuper = $this->makeSession($this->workshopA, $this->superAdmin, ['starts_at' => '2026-10-03 18:30', 'ends_at' => null]);
        $this->assertSame($this->a->id, $bySuper->organization_id, 'l\'Organization vient du Workshop, jamais de l\'acteur (SuperAdmin de B)');
        $this->assertSame($this->superAdmin->id, $bySuper->created_by);

        // Routes : l'atelier de A n'existe pas sous B ; la session de B n'existe pas sous l'atelier de A ; A n'est pas visible par l'admin de B.
        $this->actingAs($this->adminB)->get(route('organization.admin.workshops.sessions', [$this->b, $this->workshopA]))->assertNotFound();
        $this->actingAs($this->adminA)->get(route('organization.admin.workshops.sessions.edit', [$this->a, $this->workshopA, $sb]))->assertNotFound();
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.sessions.publish', [$this->a, $this->workshopA, $sb]))->assertNotFound();
        $this->actingAs($this->adminA)->delete(route('organization.admin.workshops.sessions.cancel', [$this->a, $this->workshopA, $sb]))->assertNotFound();
        $this->actingAs($this->adminB)->get(route('organization.admin.workshops.sessions', [$this->a, $this->workshopA]))->assertForbidden();
        $this->actingAs($this->member)->get(route('organization.admin.workshops.sessions', [$this->a, $this->workshopA]))->assertForbidden();
        $this->assertSame(3, WorkshopSession::count());
        $this->assertTrue($sb->fresh()->isDraft(), 'rien n\'a bouge chez B');

        $listA = $this->actingAs($this->adminA)->get(route('organization.admin.workshops.sessions', [$this->a, $this->workshopA]))->assertOk()->getContent();
        $this->assertStringContainsString('data-session-row="'.$sa->id.'"', $listA);
        $this->assertStringNotContainsString('data-session-row="'.$sb->id.'"', $listA);
    }

    // ── 4. Les regles et l'absence de fausse surface ───────────────────────

    public function test_session_rules_are_enforced_and_no_session_page_or_context_is_invented(): void
    {
        foreach ([
            ['timezone' => 'Mars/Olympus'],
            ['timezone' => '+02:00'],
            ['timezone' => 'CET'],
            ['starts_at' => ''],
            ['starts_at' => 'pas une date'],
            ['ends_at' => '2026-10-01 18:00'],
            ['capacity' => 0],
            ['capacity' => 10001],
        ] as $bad) {
            try {
                $this->makeSession($this->workshopA, $this->adminA, $bad);
                $this->fail('regle non appliquee : '.json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, WorkshopSession::count());
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.sessions.store', [$this->a, $this->workshopA]), ['starts_at' => '2026-10-01T20:00', 'ends_at' => '2026-10-01T18:00', 'timezone' => 'Europe/Paris'])->assertSessionHasErrors('starts_at');

        $open = $this->makeSession($this->workshopA, $this->adminA, ['ends_at' => null, 'capacity' => null, 'location' => null]);
        $this->assertNull($open->ends_at);
        $this->assertNull($open->capacity);
        try {
            $this->service->cancel($open, $this->adminA);
            $this->service->publish($open, $this->adminA);
            $this->fail('une session annulee ne se publie pas');
        } catch (LogicException) {
        }

        // Aucune page de session, donc aucun PageContext `workshop_session` (pas de faux contexte).
        $this->assertNull(Route::getRoutes()->getByName('organization.workshop.session.show'));
        $this->assertFalse(Schema::hasColumn('workshop_sessions', 'meeting_url'), 'aucune donnee secrete avant son consommateur (MASTER Q78)');
    }
}
