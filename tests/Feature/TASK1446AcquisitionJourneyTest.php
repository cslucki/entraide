<?php

namespace Tests\Feature;

use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\User;
use App\Services\Acquisition\AcquisitionJourneyResolver;
use App\Services\Acquisition\AcquisitionJourneyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1446 — AcquisitionJourney foundation (Growth V2 §2, MASTER Q74) : une
 * definition versionnee et tenantee d'un parcours d'acquisition, ecrite par
 * l'OrgAdmin de SON Organization (SuperAdmin transversal), publiee par un
 * geste humain, sans autorite Shell, sans sources publiques, sans CTA libre.
 */
class TASK1446AcquisitionJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    private User $member;

    private User $superAdmin;

    private AcquisitionJourneyService $service;

    private AcquisitionJourneyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create(['slug' => 'org-a-14xx', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-14xx', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->b->update(['admin_id' => $this->adminB->id]);
        $this->member = User::factory()->create(['organization_id' => $this->a->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);
        $this->service = app(AcquisitionJourneyService::class);
        $this->resolver = app(AcquisitionJourneyResolver::class);
    }

    private function draft(Organization $organization, User $actor, array $overrides = []): AcquisitionJourney
    {
        return $this->service->createDraft($organization, $overrides + ['key' => 'ateliers-septembre', 'name' => 'Ateliers de septembre', 'locale' => 'fr', 'conversion_goal' => 'account', 'campaign' => 'sept-2026'], $actor);
    }

    // ── 1. Versioning et resolution ────────────────────────────────────────

    public function test_a_journey_is_versioned_per_organization_and_key_and_only_the_published_version_resolves(): void
    {
        $v1 = $this->draft($this->a, $this->adminA);
        $this->assertSame(1, $v1->version);
        $this->assertTrue($v1->isDraft());
        $this->assertNull($this->resolver->published($this->a->fresh(), 'ateliers-septembre'), 'un brouillon ne se resout jamais');

        $this->service->publish($v1, $this->adminA);
        $this->assertTrue($this->resolver->published($this->a->fresh(), 'ateliers-septembre')->is($v1));
        $this->assertSame($this->adminA->id, $v1->fresh()->published_by);

        $v2 = $this->draft($this->a, $this->adminA, ['name' => 'Ateliers de septembre (v2)']);
        $this->assertSame(2, $v2->version);
        $this->assertTrue($this->resolver->published($this->a->fresh(), 'ateliers-septembre')->is($v1), 'le brouillon v2 n\'est pas servi');

        $this->service->publish($v2, $this->adminA);
        $this->assertTrue($this->resolver->published($this->a->fresh(), 'ateliers-septembre')->is($v2));
        $this->assertNull($this->resolver->published($this->b->fresh(), 'ateliers-septembre'), 'publiee chez A, inexistante chez B : Organization = Tenant');
        $this->assertSame(AcquisitionJourney::STATE_RETIRED, $v1->fresh()->state, 'la precedente publiee est retiree');
        $this->assertSame(1, AcquisitionJourney::query()->forOrganization($this->a)->forKey('ateliers-septembre')->published()->count());

        $this->service->retire($v2, $this->adminA);
        $this->assertNull($this->resolver->published($this->a->fresh(), 'ateliers-septembre'), 'retire = plus rien, jamais un repli vers un brouillon');
        $this->assertNull($this->resolver->published($this->b->fresh(), 'ateliers-septembre'), 'jamais une autre Organization');
    }

    public function test_the_database_refuses_two_published_versions_and_a_published_version_is_immutable(): void
    {
        foreach (['shell_posture', 'shell_mode', 'public_sources', 'cta_url', 'cta_label'] as $column) {
            $this->assertFalse(Schema::hasColumn('acquisition_journeys', $column), "acquisition_journeys.{$column} ne doit pas exister (Q74)");
        }

        $v1 = $this->draft($this->a, $this->adminA);
        $this->service->publish($v1, $this->adminA);
        try {
            $this->service->updateDraft($v1, ['name' => 'x', 'locale' => 'fr', 'conversion_goal' => 'contact'], $this->adminA);
            $this->fail('une version publiee ne s\'edite pas');
        } catch (LogicException) {
        }
        $fresh = $v1->fresh();
        $fresh->name = 'modification silencieuse';
        try {
            $fresh->save();
            $this->fail('le modele refuse');
        } catch (LogicException) {
        }
        $this->assertSame('Ateliers de septembre', $v1->fresh()->name);

        $this->expectException(QueryException::class);
        AcquisitionJourney::query()->forceCreate(['organization_id' => $this->a->id, 'key' => 'ateliers-septembre', 'name' => 'b', 'locale' => 'fr', 'version' => 9, 'state' => AcquisitionJourney::STATE_PUBLISHED, 'conversion_goal' => 'account']);
    }

    // ── 2. Qui ecrit ───────────────────────────────────────────────────────

    public function test_only_the_organization_admin_or_a_platform_admin_writes_and_never_across_organizations(): void
    {
        foreach ([[$this->member, 'membre'], [$this->adminB, 'OrgAdmin d\'ailleurs']] as [$actor, $label]) {
            try {
                $this->draft($this->a, $actor);
                $this->fail("{$label} n'ecrit pas");
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(0, AcquisitionJourney::count());

        $byAdmin = $this->draft($this->a, $this->adminA);
        $bySuper = $this->draft($this->b, $this->superAdmin, ['key' => 'rentree']);
        $this->assertSame($this->a->id, $byAdmin->organization_id);
        $this->assertSame($this->b->id, $bySuper->organization_id);

        try {
            $this->service->publish($byAdmin, $this->adminB);
            $this->fail('un OrgAdmin ne publie pas chez les autres');
        } catch (AuthorizationException) {
        }
        $this->assertTrue($byAdmin->fresh()->isDraft());

        foreach ([
            ['conversion_goal' => 'sell_everything'],
            ['locale' => 'de'],
            ['name' => ''],
            ['usage_reference_surface_key' => 'loop_private'],
            ['campaign' => str_repeat('c', 101)],
        ] as $bad) {
            try {
                $this->draft($this->a, $this->adminA, $bad + ['key' => 'k-'.md5(json_encode($bad))]);
                $this->fail('refuse : '.json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }
    }

    // ── 3. L'ecran OrgAdmin minimal ────────────────────────────────────────

    public function test_the_org_admin_screen_lists_creates_edits_publishes_and_retires_within_its_organization(): void
    {
        $index = route('organization.admin.acquisition', $this->a);
        $this->assertSame('/org/org-a-14xx/admin/acquisition', parse_url($index, PHP_URL_PATH));
        $this->get($index)->assertRedirect();
        $this->actingAs($this->member)->get($index)->assertForbidden();
        $this->actingAs($this->adminB)->get($index)->assertForbidden();

        $html = $this->actingAs($this->adminA)->get($index)->assertOk()->getContent();
        $this->assertStringContainsString('data-acquisition-empty', $html);
        $this->assertStringContainsString('data-acquisition-new', $html);

        $this->actingAs($this->adminA)->post(route('organization.admin.acquisition.store', $this->a), ['key' => 'Ateliers Septembre', 'name' => 'Ateliers de septembre', 'locale' => 'fr', 'conversion_goal' => 'workshop_participation', 'campaign' => 'sept-2026'])->assertRedirect($index);
        $draft = AcquisitionJourney::query()->forOrganization($this->a)->firstOrFail();
        $this->assertSame('ateliers-septembre', $draft->key, 'cle normalisee en slug');
        $this->assertSame('workshop_participation', $draft->conversion_goal, 'une intention, pas une FK');
        $this->assertSame($this->adminA->id, $draft->created_by);

        $this->actingAs($this->adminA)->get(route('organization.admin.acquisition.edit', [$this->a, $draft]))->assertOk()->assertSee('data-acquisition-form', false);
        $this->actingAs($this->adminA)->put(route('organization.admin.acquisition.update', [$this->a, $draft]), ['name' => 'Ateliers de septembre (relu)', 'locale' => 'en', 'conversion_goal' => 'account', 'campaign' => ''])->assertRedirect($index);
        $this->assertSame('en', $draft->fresh()->locale);
        $this->assertNull($draft->fresh()->campaign);

        $this->actingAs($this->adminA)->post(route('organization.admin.acquisition.publish', [$this->a, $draft]))->assertRedirect($index);
        $this->assertTrue($draft->fresh()->isPublished());
        $html = $this->actingAs($this->adminA)->get($index)->assertOk()->getContent();
        $this->assertStringContainsString('data-acquisition-journey="ateliers-septembre" data-acquisition-live-version="1"', $html);
        $this->actingAs($this->adminA)->get(route('organization.admin.acquisition.edit', [$this->a, $draft]))->assertNotFound();

        $this->actingAs($this->adminA)->delete(route('organization.admin.acquisition.retire', [$this->a, $draft]))->assertRedirect($index);
        $this->assertSame(AcquisitionJourney::STATE_RETIRED, $draft->fresh()->state);

        $this->actingAs($this->adminA)->post(route('organization.admin.acquisition.store', $this->a), ['name' => 'x', 'locale' => 'fr', 'conversion_goal' => 'sell'])->assertSessionHasErrors('conversion_goal');
    }

    public function test_a_journey_of_another_organization_does_not_exist_for_an_org_admin_and_the_super_admin_needs_an_explicit_organization(): void
    {
        $foreign = $this->draft($this->b, $this->adminB);
        // Meme en visant SON Organization dans l'URL, la Journey de B n'existe pas (404), jamais 403 ni fuite.
        $this->actingAs($this->adminA)->get(route('organization.admin.acquisition.edit', [$this->a, $foreign]))->assertNotFound();
        $this->actingAs($this->adminA)->post(route('organization.admin.acquisition.publish', [$this->a, $foreign]))->assertNotFound();
        $this->assertTrue($foreign->fresh()->isDraft());

        $html = $this->actingAs($this->superAdmin)->get(route('organization.admin.acquisition', $this->b))->assertOk()->getContent();
        $this->assertStringContainsString('data-acquisition-journey="ateliers-septembre"', $html);
        $this->actingAs($this->superAdmin)->post(route('organization.admin.acquisition.publish', [$this->b, $foreign]))->assertRedirect();
        $this->assertTrue($foreign->fresh()->isPublished());
        $this->assertSame($this->superAdmin->id, $foreign->fresh()->published_by);

        $listA = $this->actingAs($this->superAdmin)->get(route('organization.admin.acquisition', $this->a))->assertOk()->getContent();
        $this->assertStringNotContainsString('ateliers-septembre', $listA, 'la liste de A ne montre pas les Journeys de B');
    }
}
