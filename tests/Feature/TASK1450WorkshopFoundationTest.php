<?php

namespace Tests\Feature;

use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Models\Workshop;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\GuestShell\GuestPageContextResolver;
use App\Services\GuestShell\GuestShellDisplayModeResolver;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\Workshops\WorkshopService;
use App\Support\GuestShell\GuestPageContext;
use App\Support\GuestShell\GuestShellDisplay;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1450 — Workshop domain foundation (Growth Workshops Acquisition V3 §7,
 * §8, §13 ; MASTER Q76/Q77 ; precheck : LoopEvent Loop-first, domaine DEDIE).
 *
 * Preuves :
 *  1. cycle OrgAdmin : brouillon (slug derive du titre) → edition → publication →
 *     page publique → retrait → 404 ; slug fige des la publication ;
 *  2. page publique fail-closed : brouillon/retire = 404, autre Organization =
 *     404, Organization fermee ou privee = 404 ; expose titre/promesse/
 *     description/format/duree, aucune session, aucune inscription inventee,
 *     aucun Shell Welcome ;
 *  3. tenant : slug unique PAR Organization (reutilisable ailleurs), OrgAdmin
 *     d'une autre Organization = 404 / refus, Journey d'ailleurs refusee,
 *     membre = refus ; SuperAdmin passe par l'Organization explicite ;
 *  4. PageContext `workshop_page` resolvable sur la route reelle (identifiant
 *     public = slug, libelle = titre, aucune CTA), mais le Shell n'y est PAS
 *     eligible ; `workshop_session` reste sans route.
 */
class TASK1450WorkshopFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    private User $member;

    private User $superAdmin;

    private WorkshopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create(['slug' => 'org-a-1450', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1450', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->b->update(['admin_id' => $this->adminB->id]);
        $this->member = User::factory()->create(['organization_id' => $this->a->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);
        $this->service = app(WorkshopService::class);
    }

    private function workshop(Organization $organization, User $actor, array $overrides = []): Workshop
    {
        return $this->service->create($organization, $overrides + ['title' => 'Découvrir l\'IA en 90 minutes', 'promise' => 'Repartir avec un premier usage concret.', 'description' => "Un atelier pratique.\nOn manipule, on questionne.", 'format' => Workshop::FORMAT_ONLINE, 'duration_minutes' => 90, 'locale' => 'fr'], $actor);
    }

    private function publicUrl(Organization $organization, string $slug): string
    {
        return route('organization.workshop.show', ['organization' => $organization->slug, 'workshop' => $slug]);
    }

    // ── 1. Le cycle OrgAdmin ───────────────────────────────────────────────

    public function test_the_org_admin_drafts_edits_publishes_and_retires_a_workshop_through_the_minimal_screen(): void
    {
        $this->actingAs($this->adminA)->get(route('organization.admin.workshops', $this->a))->assertOk()->assertSee('data-workshop-empty', false);

        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.store', $this->a), [
            'title' => 'Découvrir l\'IA en 90 minutes', 'slug' => '', 'promise' => 'Repartir avec un premier usage concret.', 'description' => 'Un atelier pratique.', 'format' => 'online', 'duration_minutes' => 90, 'locale' => 'fr',
        ])->assertSessionHasNoErrors()->assertRedirect(route('organization.admin.workshops', $this->a));

        $workshop = Workshop::query()->sole();
        $this->assertSame('decouvrir-lia-en-90-minutes', $workshop->slug, 'slug derive du titre (Str::slug)');
        $this->assertTrue($workshop->isDraft());
        $this->assertSame($this->adminA->id, $workshop->created_by);
        $this->assertSame($this->a->id, $workshop->organization_id);
        $this->get($this->publicUrl($this->a, $workshop->slug))->assertNotFound();

        // Edition du brouillon, slug compris.
        $this->actingAs($this->adminA)->put(route('organization.admin.workshops.update', [$this->a, $workshop]), [
            'title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'promise' => 'Un premier usage concret.', 'description' => 'Un atelier pratique.', 'format' => 'hybrid', 'duration_minutes' => 120, 'locale' => 'fr',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['Découvrir l\'IA', 'ia-90', 'hybrid', 120], [$workshop->fresh()->title, $workshop->fresh()->slug, $workshop->fresh()->format, $workshop->fresh()->duration_minutes]);

        // Publication : la page publique existe.
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.publish', [$this->a, $workshop]))->assertRedirect();
        $workshop->refresh();
        $this->assertTrue($workshop->isPublished());
        $this->assertSame($this->adminA->id, $workshop->published_by);
        $this->assertNotNull($workshop->published_at);
        $html = $this->get($this->publicUrl($this->a, 'ia-90'))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-page="ia-90"', $html);
        $this->assertStringContainsString('Découvrir l&#039;IA', $html);
        $this->assertStringContainsString('Un premier usage concret.', $html);
        $this->assertStringContainsString('data-workshop-duration', $html);
        $this->assertStringContainsString('data-workshop-sessions-soon', $html, 'aucune session ni inscription inventee : une annonce honnete');
        $this->assertStringNotContainsString('id="bp-guest-shell"', $html, 'le Shell Welcome n\'est pas affiche sur la page d\'atelier (MASTER Q76)');
        $this->assertStringNotContainsString('meeting', $html);
        $this->assertStringNotContainsString($this->adminA->email, $html);
        $this->assertStringContainsString('data-workshop-public="'.$workshop->id.'"', $this->actingAs($this->adminA)->get(route('organization.admin.workshops', $this->a))->assertOk()->getContent());

        // Le slug est fige des la publication (URL publique) ; le reste s'edite.
        $this->actingAs($this->adminA)->put(route('organization.admin.workshops.update', [$this->a, $workshop]), [
            'title' => 'Découvrir l\'IA (v2)', 'slug' => 'autre-slug', 'format' => 'online', 'locale' => 'fr',
        ])->assertSessionHasErrors('slug');
        $this->assertSame('ia-90', $workshop->fresh()->slug);
        $this->actingAs($this->adminA)->put(route('organization.admin.workshops.update', [$this->a, $workshop]), [
            'title' => 'Découvrir l\'IA (v2)', 'format' => 'online', 'locale' => 'fr',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Découvrir l\'IA (v2)', 'ia-90'], [$workshop->fresh()->title, $workshop->fresh()->slug]);
        $this->assertStringContainsString('data-workshop-slug-frozen', $this->actingAs($this->adminA)->get(route('organization.admin.workshops.edit', [$this->a, $workshop]))->assertOk()->getContent());

        // Retrait : 404 a nouveau ; republication possible.
        $this->actingAs($this->adminA)->delete(route('organization.admin.workshops.retire', [$this->a, $workshop]))->assertRedirect();
        $this->assertTrue($workshop->fresh()->isRetired());
        $this->get($this->publicUrl($this->a, 'ia-90'))->assertNotFound();
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.publish', [$this->a, $workshop]))->assertRedirect();
        $this->assertTrue($workshop->fresh()->isPublished());
        $this->get($this->publicUrl($this->a, 'ia-90'))->assertOk();
    }

    // ── 2. La page publique, fail-closed ───────────────────────────────────

    public function test_the_public_page_exists_only_for_a_published_workshop_of_an_active_public_organization(): void
    {
        $workshop = $this->workshop($this->a, $this->adminA, ['slug' => 'ia-90']);
        $this->service->publish($workshop, $this->adminA);
        $this->get($this->publicUrl($this->a, 'ia-90'))->assertOk();

        // Le meme slug sous une autre Organization : n'existe pas.
        $this->get($this->publicUrl($this->b, 'ia-90'))->assertNotFound();
        // Un slug hors motif ou inconnu : 404, jamais 500.
        $this->get(route('organization.home', ['organization' => $this->a->slug]).'/ateliers/inconnu')->assertNotFound();
        $this->get(route('organization.home', ['organization' => $this->a->slug]).'/ateliers/AB')->assertNotFound();

        // Organization privee : publiquement, rien.
        $this->a->update(['is_public' => false]);
        $this->get($this->publicUrl($this->a, 'ia-90'))->assertNotFound();
        $this->a->update(['is_public' => true, 'is_active' => false]);
        $this->get($this->publicUrl($this->a, 'ia-90'))->assertNotFound();
        $this->a->update(['is_active' => true]);
        $this->get($this->publicUrl($this->a, 'ia-90'))->assertOk();

        // Un membre connecte voit la page aussi (publique = pour tous), sans donnee privee.
        $html = $this->actingAs($this->member)->get($this->publicUrl($this->a, 'ia-90'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-workshop-retire', $html);
    }

    // ── 3. Tenant ──────────────────────────────────────────────────────────

    public function test_workshops_are_tenant_scoped_for_slugs_actors_journeys_and_admin_routes(): void
    {
        $journeys = app(AcquisitionJourneyService::class);
        $journeyA = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION], $this->adminA);
        $journeys->publish($journeyA, $this->adminA);
        $journeyB = $journeys->createDraft($this->b, ['key' => 'rentree', 'name' => 'Rentrée B', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION], $this->adminB);
        $journeys->publish($journeyB, $this->adminB);

        $wa = $this->workshop($this->a, $this->adminA, ['slug' => 'ia-90', 'acquisition_journey_id' => $journeyA->id]);
        $this->assertSame($journeyA->id, $wa->acquisition_journey_id);

        // Slug unique PAR Organization : pris chez A, libre chez B.
        try {
            $this->workshop($this->a, $this->adminA, ['slug' => 'ia-90']);
            $this->fail('slug deja pris dans cette Organization');
        } catch (InvalidArgumentException) {
        }
        $wb = $this->workshop($this->b, $this->adminB, ['slug' => 'ia-90']);
        $this->assertSame($this->b->id, $wb->organization_id);

        // La Journey d'une autre Organization n'existe pas ici.
        try {
            $this->workshop($this->a, $this->adminA, ['slug' => 'ia-2', 'acquisition_journey_id' => $journeyB->id]);
            $this->fail('Journey d\'ailleurs');
        } catch (InvalidArgumentException) {
        }
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.store', $this->a), ['title' => 'X', 'format' => 'online', 'locale' => 'fr', 'acquisition_journey_id' => $journeyB->id])->assertSessionHasErrors('slug');
        $this->assertSame(2, Workshop::count());

        // Acteurs : membre refuse, admin d'ailleurs refuse, SuperAdmin passe (Organization explicite).
        try {
            $this->workshop($this->a, $this->member, ['slug' => 'ia-3']);
            $this->fail('un membre n\'ecrit pas');
        } catch (AuthorizationException) {
        }
        try {
            $this->service->publish($wa, $this->adminB);
            $this->fail('l\'admin d\'une autre Organization n\'ecrit pas');
        } catch (AuthorizationException) {
        }
        $this->assertTrue($this->service->publish($wa, $this->superAdmin)->isPublished());

        // Routes OrgAdmin : l'atelier de A n'existe pas sous B, meme pour l'admin de B ; l'admin de B ne voit pas A.
        $this->actingAs($this->adminB)->get(route('organization.admin.workshops.edit', [$this->b, $wa]))->assertNotFound();
        $this->actingAs($this->adminB)->post(route('organization.admin.workshops.publish', [$this->b, $wa]))->assertNotFound();
        $this->actingAs($this->adminB)->get(route('organization.admin.workshops', [$this->a]))->assertForbidden();
        $this->actingAs($this->member)->get(route('organization.admin.workshops', [$this->a]))->assertForbidden();
        $listB = $this->actingAs($this->adminB)->get(route('organization.admin.workshops', $this->b))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-row="'.$wb->id.'"', $listB);
        $this->assertStringNotContainsString('data-workshop-row="'.$wa->id.'"', $listB);

        // Publier un atelier deja publie / retirer un brouillon : 404 (pas de transition inventee).
        $this->actingAs($this->adminA)->post(route('organization.admin.workshops.publish', [$this->a, $wa]))->assertNotFound();
        $draft = $this->workshop($this->a, $this->adminA, ['slug' => 'ia-4']);
        $this->actingAs($this->adminA)->delete(route('organization.admin.workshops.retire', [$this->a, $draft]))->assertNotFound();
        try {
            $this->service->retire($draft, $this->adminA);
            $this->fail('seul un atelier publie se retire');
        } catch (LogicException) {
        }
    }

    // ── 4. PageContext : resolvable, Shell non eligible ────────────────────

    public function test_the_workshop_page_context_is_real_but_the_shell_is_not_eligible_there(): void
    {
        $workshop = $this->workshop($this->a, $this->adminA, ['slug' => 'ia-90']);
        $resolver = app(GuestPageContextResolver::class);
        $route = Route::getRoutes()->getByName('organization.workshop.show');
        $this->assertNotNull($route, 'la route publique existe reellement');

        // La route REELLE, liee a une requete reelle (parametres de route, jamais un parsing d'URL maison).
        $bind = fn (string $slug, Organization $organization) => Route::getRoutes()->match(Request::create($this->publicUrl($organization, $slug)));

        // Brouillon : aucun contexte (jamais un faux contexte).
        $this->assertNull($resolver->fromRoute($this->a, $bind('ia-90', $this->a)));

        $this->service->publish($workshop, $this->adminA);
        $page = $resolver->fromRoute($this->a->fresh(), $bind('ia-90', $this->a));
        $this->assertInstanceOf(GuestPageContext::class, $page);
        $this->assertSame(GuestPageContext::KIND_WORKSHOP_PAGE, $page->kind);
        $this->assertSame('ia-90', $page->publicId);
        $this->assertSame('Découvrir l\'IA en 90 minutes', $page->publicLabel);
        $this->assertNull($page->publicCta, 'aucune CTA inventee avant les sessions (B4)');
        $this->assertSame('organization.workshop.show', $page->routeName);

        // Autre Organization sur la meme route : NULL (fail-closed) ; slug inconnu : NULL.
        $this->assertNull($resolver->fromRoute($this->b, $bind('ia-90', $this->b)));
        $this->assertNull($resolver->fromRoute($this->a->fresh(), $bind('inconnu', $this->a)));

        // Le Shell n'est PAS eligible sur cette page, meme avec une politique PRETE (active, credential, plafond) — MASTER Q76.
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0]);
        app(GuestShellPolicyService::class)->update($this->a, ['enabled' => true]);
        OrganizationAiSetting::create(['organization_id' => $this->a->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
        $this->assertSame('ACTIVE', app(GuestShellPolicyService::class)->state($this->a->fresh())->status, 'la politique est prete : seule la page explique le OFF');
        $display = app(GuestShellDisplayModeResolver::class)->resolve($this->a->fresh(), $page);
        $this->assertSame(GuestShellDisplay::REASON_PAGE_NOT_ELIGIBLE, $display->reason);
        $this->assertFalse($display->isVisible());
        $this->assertSame([GuestPageContext::KIND_ORGANIZATION_HOME], GuestShellDisplayModeResolver::ELIGIBLE_KINDS);

        // `workshop_session` n'a toujours aucune route.
        $this->assertNull(Route::getRoutes()->getByName('organization.workshop.session.show'));
        $this->assertNotContains(GuestPageContext::KIND_WORKSHOP_SESSION, [$page->kind]);
    }
}
