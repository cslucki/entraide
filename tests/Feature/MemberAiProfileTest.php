<?php

namespace Tests\Feature;

use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\TestCase;

class MemberAiProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_draft_profile(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $profile = MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($profile->isDraft());
        $this->assertFalse($profile->isPublished());
        $this->assertEquals('draft', $profile->status);
        $this->assertEquals('fr', $profile->locale);
    }

    public function test_unique_constraint_per_organization_and_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_same_user_can_have_profiles_in_different_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();

        $profileA = MemberAiProfile::factory()->create([
            'organization_id' => $orgA->id,
            'user_id' => $user->id,
        ]);

        $profileB = MemberAiProfile::factory()->create([
            'organization_id' => $orgB->id,
            'user_id' => $user->id,
        ]);

        $this->assertNotNull($profileA);
        $this->assertNotNull($profileB);
        $this->assertNotEquals($profileA->id, $profileB->id);
    }

    public function test_organization_isolation_scope(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();

        MemberAiProfile::factory()->create([
            'organization_id' => $orgA->id,
            'user_id' => $user->id,
        ]);

        MemberAiProfile::factory()->create([
            'organization_id' => $orgB->id,
            'user_id' => $user->id,
        ]);

        $orgAProfiles = MemberAiProfile::forOrganization($orgA)->get();
        $orgBProfiles = MemberAiProfile::forOrganization($orgB)->get();

        $this->assertCount(1, $orgAProfiles);
        $this->assertCount(1, $orgBProfiles);
    }

    public function test_user_ownership_scope(): void
    {
        $org = Organization::factory()->create();
        $userA = User::factory()->create(['organization_id' => $org->id]);
        $userB = User::factory()->create(['organization_id' => $org->id]);

        MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $userA->id,
        ]);

        MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $userB->id,
        ]);

        $userAProfiles = MemberAiProfile::forUser($userA)->get();
        $userBProfiles = MemberAiProfile::forUser($userB)->get();

        $this->assertCount(1, $userAProfiles);
        $this->assertCount(1, $userBProfiles);
        $this->assertEquals($userA->id, $userAProfiles->first()->user_id);
    }

    public function test_status_transitions(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $profile = MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($profile->isDraft());

        $profile->update(['status' => MemberAiProfile::STATUS_READY_FOR_GENERATION]);
        $profile->refresh();
        $this->assertEquals('ready_for_generation', $profile->status);

        $profile->update(['status' => MemberAiProfile::STATUS_GENERATED]);
        $profile->refresh();
        $this->assertEquals('generated', $profile->status);

        $profile->update([
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'validated_at' => now(),
        ]);
        $profile->refresh();
        $this->assertTrue($profile->isPublished());
        $this->assertNotNull($profile->validated_at);

        $profile->update(['status' => MemberAiProfile::STATUS_DISABLED]);
        $profile->refresh();
        $this->assertEquals('disabled', $profile->status);
    }

    public function test_json_fields_are_cast_to_arrays(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $skills = ['php', 'laravel', 'vuejs'];
        $helpTypes = ['avis_rapide', 'repondre_question'];

        $profile = MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'skills' => $skills,
            'help_types' => $helpTypes,
            'boundaries' => ['pas_urgence'],
        ]);

        $this->assertIsArray($profile->skills);
        $this->assertCount(3, $profile->skills);
        $this->assertEquals('laravel', $profile->skills[1]);

        $this->assertIsArray($profile->help_types);
        $this->assertCount(2, $profile->help_types);
    }

    public function test_published_scope(): void
    {
        $org = Organization::factory()->create();

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $org->id,
            'user_id' => User::factory()->create(['organization_id' => $org->id])->id,
        ]);

        MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => User::factory()->create(['organization_id' => $org->id])->id,
        ]);

        $published = MemberAiProfile::published()->get();
        $this->assertCount(1, $published);
    }

    public function test_belongs_to_organization_and_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $profile = MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($profile->organization->is($org));
        $this->assertTrue($profile->user->is($user));
    }

    public function test_cascade_delete_with_organization(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $profile = MemberAiProfile::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'member_profile_summary' => 'test',
        ]);

        $org->forceDelete();

        $this->assertDatabaseMissing('member_ai_profiles', ['id' => $profile->id]);
    }

    public function test_cascade_delete_with_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        MemberAiProfile::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $user->delete();

        $this->assertDatabaseMissing('member_ai_profiles', ['organization_id' => $org->id]);
    }
}
