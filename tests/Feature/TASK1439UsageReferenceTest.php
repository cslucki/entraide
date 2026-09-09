<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Models\Organization;
use App\Models\UsageReference;
use App\Models\User;
use App\Services\GuestShell\GuestPublicContextBuilder;
use App\Services\UsageReference\UsageReferenceResolver;
use App\Services\UsageReference\UsageReferenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1439 — UsageReference V1 (Shell Welcome V3 §9, MASTER Q66/Q67).
 *
 * « A quoi sert cette surface ? » : plateforme-only, versionne, publie par un
 * humain, resolu fail-closed, injecte dans le contexte Guest comme 4e source
 * whitelistee — apres l'identite, avant les Constitutions.
 */
class TASK1439UsageReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $orgAdmin;

    private User $member;

    private Organization $organization;

    private UsageReferenceService $service;

    private UsageReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'fr']);
        $this->organization = Organization::factory()->create(['slug' => 'org-14xx', 'name' => 'Org Quatorze', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'platform_tagline' => 'Une devise publique']);
        $this->orgAdmin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->update(['admin_id' => $this->orgAdmin->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);
        $this->service = app(UsageReferenceService::class);
        $this->resolver = app(UsageReferenceResolver::class);
    }

    private function seedMigration(): object
    {
        return require base_path('database/migrations/2026_09_08_011000_seed_shell_welcome_usage_reference.php');
    }

    // ── 1. La graine ───────────────────────────────────────────────────────

    public function test_the_seed_publishes_shell_welcome_in_both_locales_and_is_idempotent(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $reference = $this->resolver->resolve(UsageReference::SURFACE_SHELL_WELCOME, $locale);
            $this->assertNotNull($reference, $locale);
            $this->assertSame($locale, $reference->locale);
            $this->assertSame(1, $reference->version);
            $this->assertTrue($reference->isPublished());
            $this->assertNull($reference->created_by, 'graine systeme');
            // Fact-check : aucune primitive absente promise.
            $this->assertDoesNotMatchRegularExpression('/atelier|workshop/i', $reference->content);
            $this->assertMatchesRegularExpression('/inscription|registration/i', $reference->content, 'la page d\'inscription existe (/org/{slug}/register)');
        }

        $this->seedMigration()->up();
        $this->assertSame(2, UsageReference::query()->forSurface(UsageReference::SURFACE_SHELL_WELCOME)->count(), 'rejouer la graine ne cree rien');
        $this->assertSame(0, UsageReference::query()->whereNotIn('surface_key', [UsageReference::SURFACE_SHELL_WELCOME])->count(), 'aucun texte Workshop/signup invente');
    }

    // ── 2. Resolution canonique ────────────────────────────────────────────

    public function test_the_latest_published_version_is_selected_and_publishing_retires_the_previous_one(): void
    {
        $v1 = $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'Inscription v1', 'Texte v1', $this->superAdmin);
        $this->assertNull($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'fr'), 'un brouillon ne se resout jamais');

        $this->service->publish($v1, $this->superAdmin);
        $this->assertTrue($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'fr')->is($v1));

        $v2 = $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'Inscription v2', 'Texte v2', $this->superAdmin);
        $this->assertSame(2, $v2->version);
        $this->assertTrue($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'fr')->is($v1), 'le brouillon v2 n\'est pas encore servi');

        $this->service->publish($v2, $this->superAdmin);
        $this->assertTrue($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'fr')->is($v2));
        $this->assertSame(UsageReference::STATE_RETIRED, $v1->fresh()->state, 'la precedente publiee est retiree');
        $this->assertNotNull($v1->fresh()->retired_at);
        $this->assertSame(1, UsageReference::query()->forSurface(UsageReference::SURFACE_SIGNUP)->forLocale('fr')->published()->count());
        $this->assertSame($this->superAdmin->id, $v2->fresh()->published_by);
    }

    public function test_a_draft_or_retired_version_is_never_resolved_and_never_a_fallback(): void
    {
        $v1 = $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'Inscription', 'Texte', $this->superAdmin);
        $this->service->publish($v1, $this->superAdmin);
        $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'Inscription v2', 'Texte v2', $this->superAdmin);
        $this->service->retire($v1, $this->superAdmin);

        $this->assertNull($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'fr'), 'retiree + brouillon = rien (jamais un repli vers un brouillon)');
        $this->assertNull($this->resolver->resolve(UsageReference::SURFACE_WORKSHOP, 'fr'), 'jamais une autre surface');
        $this->assertNull($this->resolver->resolve('loop', 'fr'), 'surface inconnue = rien');
    }

    public function test_the_platform_locale_is_the_only_fallback_and_absence_is_null(): void
    {
        $fr = $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'Inscription', 'Texte FR', $this->superAdmin);
        $this->service->publish($fr, $this->superAdmin);

        $this->assertTrue($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'en')->is($fr), 'EN absent -> locale plateforme (fr)');
        $this->assertTrue($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'FR ')->is($fr), 'normalisation minimale');

        config(['app.locale' => 'en']);
        $this->assertNull($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'en'), 'locale plateforme = en, rien en en -> NULL, jamais fr par hasard');
        $this->assertTrue($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'fr')->is($fr));
    }

    // ── 3. Invariants de stockage ──────────────────────────────────────────

    public function test_the_table_has_no_organization_id_and_the_database_refuses_two_published_versions(): void
    {
        $this->assertFalse(Schema::hasColumn('usage_references', 'organization_id'), 'plateforme-only par construction');
        $this->assertTrue(Schema::hasTable('usage_references'));

        UsageReference::query()->forceCreate(['surface_key' => UsageReference::SURFACE_SIGNUP, 'locale' => 'fr', 'title' => 'a', 'content' => 'a', 'version' => 1, 'state' => UsageReference::STATE_PUBLISHED]);
        $this->expectException(QueryException::class);
        UsageReference::query()->forceCreate(['surface_key' => UsageReference::SURFACE_SIGNUP, 'locale' => 'fr', 'title' => 'b', 'content' => 'b', 'version' => 2, 'state' => UsageReference::STATE_PUBLISHED]);
    }

    public function test_a_published_version_is_immutable_and_each_transition_is_guarded(): void
    {
        $v1 = $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'Inscription', 'Texte', $this->superAdmin);
        $this->service->updateDraft($v1, 'Inscription (relue)', 'Texte relu', $this->superAdmin);
        $this->assertSame('Texte relu', $v1->fresh()->content, 'un brouillon s\'edite');

        try {
            $this->service->retire($v1, $this->superAdmin);
            $this->fail('un brouillon ne se retire pas');
        } catch (LogicException) {
        }

        $this->service->publish($v1, $this->superAdmin);

        try {
            $this->service->updateDraft($v1, 'x', 'y', $this->superAdmin);
            $this->fail('une version publiee ne s\'edite pas');
        } catch (LogicException) {
        }
        try {
            $this->service->publish($v1, $this->superAdmin);
            $this->fail('publier deux fois');
        } catch (LogicException) {
        }

        $fresh = $v1->fresh();
        $fresh->content = 'modification silencieuse';
        try {
            $fresh->save();
            $this->fail('le modele refuse de reecrire une version publiee');
        } catch (LogicException) {
        }
        $this->assertSame('Texte relu', $v1->fresh()->content);
        $this->assertSame(UsageReference::STATE_PUBLISHED, $v1->fresh()->state);
    }

    // ── 4. Qui ecrit ───────────────────────────────────────────────────────

    public function test_writing_is_reserved_to_the_platform_administrator(): void
    {
        foreach ([$this->orgAdmin, $this->member] as $actor) {
            try {
                $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'x', 'y', $actor);
                $this->fail('seul un administrateur plateforme ecrit');
            } catch (AuthorizationException) {
            }
        }

        $index = route('admin.usage-references');
        $this->assertSame('/admin/usage-references', parse_url($index, PHP_URL_PATH));
        $this->get($index)->assertRedirect();
        $this->actingAs($this->orgAdmin)->get($index)->assertForbidden();
        $this->actingAs($this->member)->get($index)->assertForbidden();
        $this->actingAs($this->orgAdmin)->post(route('admin.usage-references.store'), ['surface_key' => 'signup', 'locale' => 'fr', 'title' => 'x', 'content' => 'y'])->assertForbidden();
        $this->assertSame(0, UsageReference::query()->forSurface(UsageReference::SURFACE_SIGNUP)->count());

        $html = $this->actingAs($this->superAdmin)->get($index)->assertOk()->getContent();
        $this->assertStringContainsString('data-usage-reference-surface="shell_welcome"', $html);
        $this->assertStringContainsString('data-usage-reference-live="shell_welcome:fr" data-usage-reference-live-version="1"', $html);
        // TASK-1480 : l'absence de version publiee se dit desormais par un
        // marqueur EXPLICITE plutot que par un attribut vide. C'est une
        // assertion plus forte, pas plus faible : `live-version=""` etait aussi
        // ce qu'aurait rendu un attribut oublie.
        $this->assertStringContainsString('data-usage-reference-none="signup:fr"', $html);
        $this->assertStringNotContainsString('data-usage-reference-live="signup:fr"', $html);
    }

    public function test_the_super_admin_publishes_through_the_admin_screens(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.usage-references.store'), ['surface_key' => 'signup', 'locale' => 'fr', 'title' => 'Inscription', 'content' => "Texte\r\nsur deux lignes"])
            ->assertRedirect(route('admin.usage-references'));
        $draft = UsageReference::query()->forSurface(UsageReference::SURFACE_SIGNUP)->firstOrFail();
        $this->assertTrue($draft->isDraft());
        $this->assertSame("Texte\nsur deux lignes", $draft->content, 'normalisation des fins de ligne');
        $this->assertSame($this->superAdmin->id, $draft->created_by);

        $this->actingAs($this->superAdmin)->get(route('admin.usage-references.edit', $draft))->assertOk()->assertSee('data-usage-reference-form', false);
        $this->actingAs($this->superAdmin)->put(route('admin.usage-references.update', $draft), ['title' => 'Inscription relue', 'content' => 'Texte relu'])->assertRedirect();
        $this->assertSame('Texte relu', $draft->fresh()->content);

        $this->actingAs($this->superAdmin)->post(route('admin.usage-references.publish', $draft))->assertRedirect();
        $this->assertTrue($draft->fresh()->isPublished());
        $html = $this->actingAs($this->superAdmin)->get(route('admin.usage-references'))->assertOk()->getContent();
        $this->assertStringContainsString('data-usage-reference-live="signup:fr" data-usage-reference-live-version="1"', $html);
        $this->actingAs($this->superAdmin)->get(route('admin.usage-references.edit', $draft))->assertNotFound();
        $this->actingAs($this->superAdmin)->put(route('admin.usage-references.update', $draft), ['title' => 'x', 'content' => 'y'])->assertNotFound();
        $this->assertSame('Texte relu', $draft->fresh()->content);

        $this->actingAs($this->superAdmin)->delete(route('admin.usage-references.retire', $draft))->assertRedirect();
        $this->assertSame(UsageReference::STATE_RETIRED, $draft->fresh()->state);
        $this->assertNull($this->resolver->resolve(UsageReference::SURFACE_SIGNUP, 'fr'));
        $this->actingAs($this->superAdmin)->post(route('admin.usage-references.store'), ['surface_key' => 'loop', 'locale' => 'fr', 'title' => 'x', 'content' => 'y'])->assertSessionHasErrors('surface_key');
    }

    public function test_no_route_outside_the_admin_zone_touches_usage_references(): void
    {
        $seen = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'usage-reference')) {
                continue;
            }
            $seen++;
            $this->assertStringStartsWith('admin/', $route->uri(), $route->uri());
            $this->assertContains('admin', $route->gatherMiddleware(), $route->uri());
            $this->assertContains('auth', $route->gatherMiddleware(), $route->uri());
        }
        $this->assertGreaterThanOrEqual(7, $seen, 'index, create, store, edit, update, publish, retire');
    }

    // ── 5. Le contexte Guest ───────────────────────────────────────────────

    public function test_the_guest_context_adds_only_the_reference_of_the_requested_surface_after_identity_and_before_constitutions(): void
    {
        $signup = $this->service->createDraft(UsageReference::SURFACE_SIGNUP, 'fr', 'Inscription', 'SIGNUP-REF texte', $this->superAdmin);
        $this->service->publish($signup, $this->superAdmin);
        $definition = app(CapabilityRegistry::class)->get(CapabilityRegistry::GUEST_SHELL_WELCOME);
        $this->assertTrue($definition->allowsSource(CapabilityRegistry::SOURCE_USAGE_REFERENCE));
        $this->assertSame('usage_reference', CapabilityRegistry::SOURCE_USAGE_REFERENCE);

        $context = app(GuestPublicContextBuilder::class)->build($this->organization->fresh(), UsageReference::SURFACE_SHELL_WELCOME);
        $this->assertNotNull($context);
        $this->assertSame([
            CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY,
            CapabilityRegistry::SOURCE_USAGE_REFERENCE,
            CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION,
        ], $context->sources, 'identite, puis UsageReference, puis Constitutions');
        $seed = $this->resolver->resolve(UsageReference::SURFACE_SHELL_WELCOME, 'fr');
        $this->assertSame($seed->content, $context->blocks[1]['text']);
        $this->assertStringContainsString($seed->title, $context->blocks[1]['label']);
        $this->assertStringNotContainsString('SIGNUP-REF', $context->text(), 'seule la surface demandee');

        $other = app(GuestPublicContextBuilder::class)->build($this->organization->fresh(), UsageReference::SURFACE_SIGNUP);
        $this->assertStringContainsString('SIGNUP-REF', $other->blocks[1]['text']);

        $this->organization->update(['locale' => 'en']);
        $english = app(GuestPublicContextBuilder::class)->build($this->organization->fresh(), UsageReference::SURFACE_SHELL_WELCOME);
        $this->assertSame($this->resolver->resolve(UsageReference::SURFACE_SHELL_WELCOME, 'en')->content, $english->blocks[1]['text'], 'la locale de l\'Organization choisit la reference');
    }

    public function test_the_reference_is_a_whole_block_under_the_capability_budget(): void
    {
        $identity = app(GuestPublicContextBuilder::class)->build($this->organization->fresh(), UsageReference::SURFACE_SHELL_WELCOME)->blocks[0]['text'];
        // L'identite rentre, l'identite + la reference ne rentre plus : la composition s'arrete, sans coupe partielle.
        config(['ai.guest_shell.max_context_chars' => mb_strlen($identity) + 10]);
        app()->forgetInstance(CapabilityRegistry::class);
        app()->forgetInstance(GuestPublicContextBuilder::class);

        $context = app(GuestPublicContextBuilder::class)->build($this->organization->fresh(), UsageReference::SURFACE_SHELL_WELCOME);
        $this->assertSame([CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY], $context->sources);
        $this->assertStringNotContainsString('BouclePro', $context->text(), 'aucun fragment de la reference');
    }

    public function test_without_any_published_reference_the_rest_of_the_public_context_still_builds(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->service->retire($this->resolver->resolve(UsageReference::SURFACE_SHELL_WELCOME, $locale), $this->superAdmin);
        }
        $this->assertNull($this->resolver->resolve(UsageReference::SURFACE_SHELL_WELCOME, 'fr'));

        $context = app(GuestPublicContextBuilder::class)->build($this->organization->fresh(), UsageReference::SURFACE_SHELL_WELCOME);
        $this->assertNotNull($context);
        $this->assertSame([CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY, CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION], $context->sources);
    }
}
