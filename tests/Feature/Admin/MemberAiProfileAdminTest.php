<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiInteraction;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemberAiProfileAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $organization = Organization::factory()->create(['is_active' => true]);

        return User::factory()->create([
            'is_admin' => true,
            'organization_id' => $organization->id,
        ]);
    }

    private function makeProfile(array $attributes = []): MemberAiProfile
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        return MemberAiProfile::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => MemberAiProfile::STATUS_PENDING_VALIDATION,
        ], $attributes));
    }

    public function test_guest_is_redirected_from_member_ai_profiles_admin(): void
    {
        $this->get(route('admin.member-ai-profiles'))
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_member_ai_profiles_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.member-ai-profiles'))
            ->assertForbidden();
    }

    public function test_admin_can_view_member_ai_profiles(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile(['member_profile_summary' => 'Aide sur le SEO local']);

        $this->actingAs($admin)
            ->get(route('admin.member-ai-profiles'))
            ->assertOk()
            ->assertSee('Agents profil IA')
            ->assertSee($profile->user->name)
            ->assertSee('Aide sur le SEO local');
    }

    public function test_admin_can_update_member_ai_profile_content(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile();

        $this->actingAs($admin)
            ->put(route('admin.member-ai-profiles.update', $profile), [
                'status' => MemberAiProfile::STATUS_PENDING_VALIDATION,
                'member_profile_summary' => 'Résumé modéré',
                'service_scope' => 'Aide sur la stratégie éditoriale',
                'experience_context' => '10 ans de contenu B2B',
                'preferred_contact_action' => 'envoyer_message',
                'tone' => 'sobre',
                'generated_summary' => 'Résumé généré relu',
                'skills' => "SEO\nRédaction",
                'help_types' => "relire_document\npartager_methode",
                'boundaries' => "pas_urgence\npas_hors_domaine",
                'good_request_examples' => 'Peux-tu relire ma page service ?',
                'bad_request_examples' => 'Peux-tu faire tout mon site gratuitement ?',
            ])
            ->assertRedirect(route('admin.member-ai-profiles.edit', $profile))
            ->assertSessionHas('success');

        $profile->refresh();

        $this->assertSame('Résumé modéré', $profile->member_profile_summary);
        $this->assertSame(['SEO', 'Rédaction'], $profile->skills);
        $this->assertSame(['pas_urgence', 'pas_hors_domaine'], $profile->boundaries);
    }

    public function test_admin_can_publish_pending_member_ai_profile(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile(['status' => MemberAiProfile::STATUS_PENDING_VALIDATION]);

        $this->actingAs($admin)
            ->patch(route('admin.member-ai-profiles.publish', $profile))
            ->assertSessionHas('success');

        $profile->refresh();

        $this->assertSame(MemberAiProfile::STATUS_PUBLISHED, $profile->status);
        $this->assertNotNull($profile->validated_at);
        $this->assertNotNull($profile->published_at);
        $this->assertNull($profile->disabled_at);
    }

    public function test_admin_can_disable_published_member_ai_profile(): void
    {
        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.member-ai-profiles.disable', $profile))
            ->assertSessionHas('success');

        $profile->refresh();

        $this->assertSame(MemberAiProfile::STATUS_DISABLED, $profile->status);
        $this->assertNotNull($profile->disabled_at);
    }

    public function test_admin_can_test_member_ai_profile_with_ollama(): void
    {
        config([
            'ai.ollama.enabled' => true,
            'ai.ollama.base_url' => 'http://ollama.test',
            'ai.ollama.model' => 'ministral-3:3b',
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => false,
        ]);

        Http::fake([
            'http://ollama.test/api/generate' => Http::response([
                'model' => 'ministral-3:3b',
                'response' => 'Je peux présenter une prestation SEO bornée au profil publié.',
            ]),
        ]);

        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'service_scope' => 'Audit SEO local',
            'member_profile_summary' => 'Consultant SEO',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.member-ai-profiles.test-llm', $profile), [
                'provider' => 'ollama',
                'model' => 'ministral-3:3b',
                'question' => "C'est quoi ta prestation ?",
            ])
            ->assertOk()
            ->assertSee('Je peux présenter une prestation SEO bornée au profil publié.')
            ->assertSee('ministral-3:3b');

        $this->assertDatabaseHas('admin_ai_interactions', [
            'scenario_id' => 'member_ai_profile_llm_test',
            'provider' => 'ollama',
            'model' => 'ministral-3:3b',
            'status' => 'success',
        ]);

        $this->assertSame(1, AdminAiInteraction::count());
    }

    public function test_admin_can_test_member_ai_profile_with_openrouter(): void
    {
        config([
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'test-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.openrouter.model' => 'deepseek/deepseek-chat-v3-0324',
            'ai.openai.supervision_enabled' => false,
        ]);

        Http::fake([
            'https://openrouter.test/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Cette prestation porte sur la stratégie éditoriale.']],
                ],
            ]),
        ]);

        $admin = $this->makeAdmin();
        $profile = $this->makeProfile([
            'service_scope' => 'Stratégie éditoriale B2B',
            'member_profile_summary' => 'Consultant contenu',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.member-ai-profiles.test-llm', $profile), [
                'provider' => 'openrouter',
                'model' => 'deepseek/deepseek-chat-v3-0324',
                'question' => "C'est quoi ta prestation ?",
            ])
            ->assertOk()
            ->assertSee('Cette prestation porte sur la stratégie éditoriale.')
            ->assertSee('deepseek/deepseek-chat-v3-0324');

        $this->assertDatabaseHas('admin_ai_interactions', [
            'scenario_id' => 'member_ai_profile_llm_test',
            'provider' => 'openrouter',
            'model' => 'deepseek/deepseek-chat-v3-0324',
            'status' => 'success',
        ]);
    }
}
