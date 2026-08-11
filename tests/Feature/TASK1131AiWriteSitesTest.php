<?php

namespace Tests\Feature;

use App\Livewire\BoundedMemberAgent;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\BlogAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1131 / IA P1-1 — sites d'écriture restants.
 *
 * Les autres fichiers de test couvrent la persistence partagée, ChatLoop,
 * l'agent inline et le job asynchrone. Celui-ci verrouille les quatre sites
 * qui restaient sans garde : BlogAiService, les deux écritures de
 * BlogExplorerController, BoundedMemberAgent et le testeur LLM d'administration.
 */
class TASK1131AiWriteSitesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $author;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.default_provider' => 'openai',
            'ai.openai.api_key' => 'test-key',
            'ai.openai.model' => 'gpt-test',
        ]);

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'ai_profiles_enabled' => true,
        ]);

        $this->author = User::factory()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article TASK-1131',
            'slug' => 'article-task-1131',
            'content' => '<p>'.str_repeat('Un contenu sauvegarde suffisamment long pour etre explore. ', 12).'</p>',
            'summary' => 'Resume',
            'status' => 'draft',
        ]);

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => str_repeat('Reponse generee par le fake. ', 20)]]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ]),
        ]);
    }

    private function assertTraced(AiInteraction|AdminAiInteraction $row, string $expectedProcess): void
    {
        $this->assertNotNull($row->correlation_id, 'Every AI write must carry a correlation.');
        $this->assertTrue(Str::isUuid($row->correlation_id));
        $this->assertSame($expectedProcess, $row->process);
    }

    /**
     * Site : app/Services/BlogAiService.php — generate().
     */
    public function test_blog_ai_service_generate_is_traced(): void
    {
        app(BlogAiService::class)->generate($this->post, $this->author);

        $this->assertTraced(
            AiInteraction::query()->where('feature', 'blog_generate')->firstOrFail(),
            'blog.article_generate'
        );
    }

    /**
     * Site : app/Services/BlogAiService.php — correct().
     */
    public function test_blog_ai_service_correct_is_traced(): void
    {
        app(BlogAiService::class)->correct($this->post, $this->author);

        $this->assertTraced(
            AiInteraction::query()->where('feature', 'blog_correct')->firstOrFail(),
            'blog.article_correct'
        );
    }

    /**
     * Site : app/Services/BlogAiService.php — methodSelection().
     * Le `feature` porte la méthode et la locale : le `process`, lui, reste
     * stable.
     */
    public function test_blog_ai_service_method_selection_is_traced_with_a_locale_free_process(): void
    {
        app(BlogAiService::class)->methodSelection(
            $this->post,
            $this->author,
            'clarifier',
            'Un passage selectionne.',
        );

        $interaction = AiInteraction::query()->firstOrFail();

        $this->assertStringStartsWith('blog_method_selection_', $interaction->feature);
        $this->assertTraced($interaction, 'blog.method_selection');
    }

    /**
     * Site : app/Http/Controllers/BlogExplorerController.php — dialogue.
     */
    public function test_blog_explorer_dialogue_write_is_traced(): void
    {
        $this->actingAs($this->author)
            ->postJson(route('blog.explorer.chat', $this->post), [
                'message' => 'Que dit cet article ?',
            ])
            ->assertOk();

        $this->assertTraced(
            AiInteraction::query()->where('feature', 'blog_explorer')->firstOrFail(),
            'blog.explorer_dialogue'
        );
    }

    /**
     * Site : app/Http/Controllers/BlogExplorerController.php — note générée.
     */
    public function test_blog_explorer_note_write_is_traced(): void
    {
        $this->actingAs($this->author)
            ->postJson(route('blog.explorer.note.generate', $this->post), [
                'messages' => [['role' => 'user', 'text' => 'Resume moi cet article.']],
            ]);

        $this->assertTraced(
            AiInteraction::query()->where('feature', 'blog_explorer_note')->firstOrFail(),
            'blog.explorer_note'
        );
    }

    /**
     * Site : app/Livewire/BoundedMemberAgent.php.
     */
    public function test_bounded_member_agent_write_is_traced(): void
    {
        $member = User::factory()->create(['organization_id' => $this->organization->id]);
        $visitor = User::factory()->create(['organization_id' => $this->organization->id]);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $member->id,
            'skills' => ['SEO', 'Redaction'],
        ]);

        Livewire::actingAs($visitor)
            ->test(BoundedMemberAgent::class, ['user' => $member])
            ->set('question', 'Quelles competences ?')
            ->call('askQuestion');

        $this->assertTraced(
            AdminAiInteraction::query()->where('scenario_id', 'bounded_member_presentation')->firstOrFail(),
            'member_profile.bounded_presentation'
        );
    }

    /**
     * Site : app/Http/Controllers/Admin/AdminMemberAiProfileController.php.
     */
    public function test_admin_member_ai_profile_llm_test_write_is_traced(): void
    {
        config([
            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'test-key',
            'ai.openrouter.model' => 'test/model',
        ]);

        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $member = User::factory()->create(['organization_id' => $this->organization->id]);

        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $member->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.member-ai-profiles.test-llm', $profile), [
                'provider' => 'openrouter',
                'model' => 'test/model',
                'question' => 'Que propose ce membre ?',
            ]);

        $this->assertTraced(
            AdminAiInteraction::query()->where('scenario_id', 'member_ai_profile_llm_test')->firstOrFail(),
            'member_profile.admin_llm_test'
        );
    }
}
