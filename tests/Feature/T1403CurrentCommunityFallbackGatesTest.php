<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveOrganization;
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
        $organization = Organization::factory()->create();

        app()->instance('current_organization', $org);
        app()->instance('current_community', $organization);

        $this->assertSame($org, CurrentOrganization::get());
        $this->assertNotEquals($organization->id, CurrentOrganization::id());
    }

    // ─────────────────────────────────────────────────────────────
    // Gate 2: current_community fallback is removed
    // ─────────────────────────────────────────────────────────────

    public function test_current_community_fallback_no_longer_works(): void
    {
        $organization = Organization::factory()->create();

        app()->instance('current_community', $organization);

        $this->assertNull(CurrentOrganization::get());
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
    // Gate 4: ResolveCommunity route middleware binds current_organization
    // ─────────────────────────────────────────────────────────────

    public function test_resolve_community_binds_current_organization(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 't1403-gate-4',
            'is_active' => true,
        ]);

        Route::get('/t1403/gate4/{community}', function () {
            return response()->json([
                'community_bound' => app()->bound('current_community'),
                'organization_bound' => app()->bound('current_organization'),
                'organization_id' => app('current_organization')->id,
            ]);
        })->middleware(ResolveOrganization::class);

        $response = $this->get('/t1403/gate4/t1403-gate-4');

        $response->assertOk();
        $response->assertJson([
            'community_bound' => false,
            'organization_bound' => true,
            'organization_id' => $organization->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Gate 5: UI Blade ne crash pas avec $currentCommunity legacy
    // ─────────────────────────────────────────────────────────────

    public function test_navigation_renders_with_legacy_current_community_fallback(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 't1403-gate-5',
            'name' => 'T140.3 Gate Five',
            'is_active' => true,
        ]);

        View::share('currentCommunity', $organization);
        View::share('currentOrganization', $organization);
        app()->instance('current_community', $organization);
        app()->instance('current_organization', $organization);

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
            'app/Http/Middleware/ResolveUrlOrganization.php',
            'app/Http/Middleware/ResolveApiOrganization.php',
            'app/Http/Middleware/ResolveOrganization.php',
            'app/Support/Tenancy/CurrentOrganization.php',
            'app/Models/Traits/HasOrganizationId.php',
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
            'Nouveaux usages runtime de current_community détectés en dehors de l\'allowlist: '.
                implode(', ', $candidates)
        );
    }
}
