<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\AiValidationDatasetSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1201 — logique de composition du dataset, exercée en SQLite
 * `:memory:` via `compose()` (pas `run()`, qui exige la garde
 * `bouclepro_ai_validation` — hors de portée d'une suite SQLite par design).
 *
 * Découverte au passage : `Service`/`ServiceRequest` portent un global scope
 * `BelongsToOrganizationScope` qui renvoie **zéro ligne, sans erreur**, tant
 * qu'aucun `CurrentOrganization` n'est lié (fail-closed documenté dans
 * `CurrentOrganization::get()`). Les requêtes de vérification brutes
 * ci-dessous contournent volontairement ce scope (`withoutGlobalScope`) —
 * c'est le comportement normal de l'app en usage réel, pas un défaut du
 * dataset.
 *
 * Ne prouve pas que `bouclepro_ai_validation` fonctionnera à l'identique
 * sous PostgreSQL (pgvector, contraintes spécifiques) — seulement que la
 * composition Eloquent est correcte et strictement isolée par Organization.
 */
class TASK1201AiValidationDatasetSeederTest extends TestCase
{
    public function test_composes_two_organizations_with_distinct_sentinels(): void
    {
        [$orgA, $orgB] = (new AiValidationDatasetSeeder)->compose();

        $this->assertNotSame($orgA->id, $orgB->id);
        $this->assertSame('ai-validation-org-a', $orgA->slug);
        $this->assertSame('ai-validation-org-b', $orgB->slug);
        $this->assertStringContainsString('SENTINEL-A', $orgA->name);
        $this->assertStringContainsString('SENTINEL-B', $orgB->name);
    }

    public function test_each_organization_has_the_minimum_required_dataset(): void
    {
        [$orgA] = (new AiValidationDatasetSeeder)->compose();

        $this->assertSame(3, User::where('organization_id', $orgA->id)->count(), 'admin + 2 members');
        $this->assertSame(1, User::where('organization_id', $orgA->id)->where('is_admin', true)->count());
        $this->assertSame(2, Loop::where('organization_id', $orgA->id)->count());
        $this->assertGreaterThanOrEqual(2, LoopMessage::whereHas('loop', fn ($q) => $q->where('organization_id', $orgA->id))->count());
        $this->assertSame(1, ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgA->id)->count());
        $this->assertSame(1, Service::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgA->id)->count());
        $this->assertSame(1, Dossier::where('organization_id', $orgA->id)->count());
        $this->assertSame(1, BlogPost::where('organization_id', $orgA->id)->count());
        $this->assertSame(1, MemberAiProfile::where('organization_id', $orgA->id)->count());
    }

    public function test_seeded_users_satisfy_the_profile_completeness_middleware(): void
    {
        (new AiValidationDatasetSeeder)->compose();

        $incomplete = User::query()
            ->where(function ($q) {
                $q->whereNull('first_name')
                    ->orWhereNull('name')
                    ->orWhereNull('phone')
                    ->orWhereNull('city')
                    ->orWhereNull('country_code')
                    ->orWhereNull('bio');
            })
            ->count();

        $this->assertSame(0, $incomplete, 'EnsureProfileComplete would redirect any incomplete seeded user.');
    }

    public function test_login_credentials_are_deterministic_and_documented(): void
    {
        (new AiValidationDatasetSeeder)->compose();

        $admin = User::where('email', 'admin@ai-validation-org-a.ai-validation.test')->firstOrFail();

        $this->assertTrue(Hash::check('password', $admin->password));
    }

    public function test_no_sentinel_b_marker_ever_appears_under_organization_a_scope(): void
    {
        [$orgA] = (new AiValidationDatasetSeeder)->compose();

        $leaks = [
            User::where('organization_id', $orgA->id)->where(function ($q) {
                $q->where('name', 'like', '%SENTINEL-B%')->orWhere('email', 'like', '%org-b%');
            })->count(),
            Loop::where('organization_id', $orgA->id)->where('name', 'like', '%SENTINEL-B%')->count(),
            BlogPost::where('organization_id', $orgA->id)->where('title', 'like', '%SENTINEL-B%')->count(),
            Dossier::where('organization_id', $orgA->id)->where('name', 'like', '%SENTINEL-B%')->count(),
            ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgA->id)->where('title', 'like', '%SENTINEL-B%')->count(),
            Service::withoutGlobalScope(BelongsToOrganizationScope::class)->where('organization_id', $orgA->id)->where('title', 'like', '%SENTINEL-B%')->count(),
        ];

        $this->assertSame(0, array_sum($leaks), 'No SENTINEL-B data must ever be scoped under Organization A.');
    }

    public function test_no_sentinel_a_marker_ever_appears_under_organization_b_scope(): void
    {
        [, $orgB] = (new AiValidationDatasetSeeder)->compose();

        $leaks = [
            User::where('organization_id', $orgB->id)->where(function ($q) {
                $q->where('name', 'like', '%SENTINEL-A%')->orWhere('email', 'like', '%org-a%');
            })->count(),
            Loop::where('organization_id', $orgB->id)->where('name', 'like', '%SENTINEL-A%')->count(),
            BlogPost::where('organization_id', $orgB->id)->where('title', 'like', '%SENTINEL-A%')->count(),
        ];

        $this->assertSame(0, array_sum($leaks), 'No SENTINEL-A data must ever be scoped under Organization B.');
    }

    public function test_loop_members_are_scoped_to_their_own_organization(): void
    {
        [$orgA, $orgB] = (new AiValidationDatasetSeeder)->compose();

        $crossTenantMembers = LoopMember::query()
            ->whereHas('loop', fn ($q) => $q->where('organization_id', $orgA->id))
            ->where('organization_id', '!=', $orgA->id)
            ->count();

        $this->assertSame(0, $crossTenantMembers);
        $this->assertNotSame($orgA->id, $orgB->id);
    }
}
