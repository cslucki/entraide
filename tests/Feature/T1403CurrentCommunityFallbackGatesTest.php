<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveCommunity;
use App\Models\Community;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * T140.3 — Gates de caractérisation du fallback current_community
 *
 * Ces tests documentent le comportement ACTUEL du fallback legacy.
 * Ils ne présagent pas du comportement cible final.
 * Ils servent de gates de sécurité avant toute suppression future.
 */
class T1403CurrentCommunityFallbackGatesTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────
    // Gate 1: current_organization doit être prioritaire
    // ─────────────────────────────────────────────────────────────

    public function test_current_organization_takes_priority_over_current_community(): void
    {
        $org = Organization::factory()->create();
        $community = Organization::factory()->create();

        app()->instance('current_organization', $org);
        app()->instance('current_community', $community);

        $this->assertSame($org, CurrentOrganization::get());
        $this->assertNotEquals($community->id, CurrentOrganization::id());
    }

    // ─────────────────────────────────────────────────────────────
    // Gate 2: current_community fallback legacy (caractérisation)
    // ─────────────────────────────────────────────────────────────

    public function test_current_community_fallback_still_works_when_current_organization_missing(): void
    {
        $community = Organization::factory()->create();

        app()->instance('current_community', $community);

        $result = CurrentOrganization::get();

        $this->assertNotNull(
            $result,
            'Le fallback current_community doit fonctionner (comportement legacy actuel)'
        );
        $this->assertEquals($community->id, $result->id);
    }

    // ─────────────────────────────────────────────────────────────
    // Gate 3: Null safety quand aucun binding
    // ─────────────────────────────────────────────────────────────

    public function test_current_organization_returns_null_when_no_binding_exists(): void
    {
        $this->assertNull(CurrentOrganization::get());
        $this->assertNull(CurrentOrganization::id());
        $this->assertNull(currentOrganization());
    }

    // ─────────────────────────────────────────────────────────────
    // Gate 4: ResolveCommunity route middleware binde les deux
    // ─────────────────────────────────────────────────────────────

    public function test_resolve_community_binds_both_legacy_and_current_names(): void
    {
        $community = Organization::factory()->create([
            'slug' => 't1403-gate-4',
            'is_active' => true,
        ]);

        Route::get('/t1403/gate4/{community}', function () {
            return response()->json([
                'community_bound' => app()->bound('current_community'),
                'organization_bound' => app()->bound('current_organization'),
                'organization_id' => app('current_community')->id,
                'organization_id' => app('current_organization')->id,
                'same_instance' => app('current_community') === app('current_organization'),
            ]);
        })->middleware(ResolveCommunity::class);

        $response = $this->get('/t1403/gate4/t1403-gate-4');

        $response->assertOk();
        $response->assertJson([
            'community_bound' => true,
            'organization_bound' => true,
            'organization_id' => $community->id,
            'organization_id' => $community->id,
            'same_instance' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Gate 5: UI Blade ne crash pas avec $currentCommunity legacy
    // ─────────────────────────────────────────────────────────────

    public function test_navigation_renders_with_legacy_current_community_fallback(): void
    {
        $community = Organization::factory()->create([
            'slug' => 't1403-gate-5',
            'name' => 'T140.3 Gate Five',
            'is_active' => true,
        ]);

        View::share('currentCommunity', $community);
        View::share('currentOrganization', $community);
        app()->instance('current_community', $community);
        app()->instance('current_organization', $community);

        $this->assertTrue(View::shared('currentCommunity') !== null);
        $this->assertTrue(View::shared('currentOrganization') !== null);

        $rendered = view('layouts.navigation')->render();
        $this->assertStringContainsString('T140.3 Gate Five', $rendered);
    }

    // ─────────────────────────────────────────────────────────────
    // Gate 6: Allowlist statique — pas de nouveau usage runtime
    // ─────────────────────────────────────────────────────────────

    public function test_no_new_current_community_runtime_usage_outside_allowlist(): void
    {
        $allowlist = [
            'app/Http/Middleware/ResolveCommunity.php',
            'app/Http/Middleware/ResolveOrganization.php',
            'app/Http/Middleware/ResolveUrlOrganization.php',
            'app/Support/Tenancy/CurrentOrganization.php',
            'tests/Feature/T1403CurrentCommunityFallbackGatesTest.php',
            'tests/Feature/CurrentOrganizationTest.php',
            'tests/Feature/T1392LegacyCharacterizationTest.php',
            'tests/Feature/T1392KnownRisksTest.php',
            'tests/Feature/OrganizationCompatibilityTest.php',
            'tests/Feature/OrganizationRouteCompatibilityTest.php',
            'tests/Feature/ResolveUrlOrganizationTest.php',
            'tests/Feature/BelongsToTenantScopeTest.php',
            'tests/Feature/LoopMemberInvariantTest.php',
            'tests/Feature/T0757ProfileOrganizationScopingTest.php',
            'tests/Feature/Api/ApiTenantScopingTest.php',
            'app/Http/Middleware/ResolveApiOrganization.php',
        ];

        $candidates = [];
        $pattern = '/current_community|currentCommunity/i';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
                if (in_array($relative, $allowlist, true)) {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if (preg_match($pattern, $content)) {
                    $candidates[] = $relative;
                }
            }
        }

        $this->assertEmpty(
            $candidates,
            'Nouveaux usages runtime de current_community détectés en dehors de l\'allowlist: ' .
                implode(', ', $candidates)
        );
    }
}
