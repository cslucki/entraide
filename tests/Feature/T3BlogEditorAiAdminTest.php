<?php

namespace Tests\Feature;

use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class T3BlogEditorAiAdminTest extends TestCase
{
    private Organization $organization;

    private User $admin;

    private User $user;

    private BlogPost $post;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.openai.api_key' => 'test-key']);

        $this->organization = Organization::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);

        $this->category = Category::create([
            'name_b2c' => 'Test Cat',
            'name_b2b' => 'Test Cat B2B',
            'slug' => 'test-cat-'.uniqid(),
            'color' => '#6366f1',
            'organization_id' => $this->organization->id,
        ]);

        $this->post = BlogPost::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article test',
            'slug' => 'article-test',
            'content' => '<p>'.str_repeat('Contenu de test. ', 10).'</p>',
            'status' => 'draft',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // HTML sanitization
    // ─────────────────────────────────────────────────────────────

    public function test_html_sanitization_removes_script_tags(): void
    {
        $this->actingAs($this->user)
            ->post(route('blog.store'), [
                'title' => 'XSS test',
                'content' => '<h2>Bonjour</h2><script>alert("xss")</script><p>Texte normal</p>',
                'status' => 'draft',
                'category_id' => $this->category->id,
            ])
            ->assertSessionHas('success');

        $post = BlogPost::where('title', 'XSS test')->first();
        $this->assertNotNull($post);
        $this->assertStringContainsString('<h2>Bonjour</h2>', $post->content);
        $this->assertStringNotContainsString('<script>', $post->content);
        $this->assertStringContainsString('<p>Texte normal</p>', $post->content);
    }

    public function test_html_sanitization_removes_event_handlers(): void
    {
        $content = '<h2>Titre</h2><p onclick="alert(1)">Texte</p><img onerror="evil()">';

        $this->actingAs($this->user)
            ->post(route('blog.store'), [
                'title' => 'Event handler test',
                'content' => $content,
                'status' => 'draft',
                'category_id' => $this->category->id,
            ])
            ->assertSessionHas('success');

        $post = BlogPost::where('title', 'Event handler test')->first();
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('onclick', $post->content);
        $this->assertStringNotContainsString('onerror', $post->content);
    }

    // ─────────────────────────────────────────────────────────────
    // Image upload
    // ─────────────────────────────────────────────────────────────

    public function test_upload_image_requires_auth(): void
    {
        $this->post(route('blog.upload-image'))
            ->assertRedirect(route('login'));
    }

    public function test_upload_image_accepts_valid_image(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $response = $this->actingAs($this->user)
            ->post(route('blog.upload-image'), ['image' => $file]);

        $response->assertOk()
            ->assertJsonStructure(['url']);

        $url = $response->json('url');
        $this->assertStringContainsString('/storage/blog/images/', $url);
    }

    public function test_upload_image_rejects_invalid_file(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->actingAs($this->user)
            ->post(route('blog.upload-image'), ['image' => $file])
            ->assertSessionHasErrors('image');
    }

    // ─────────────────────────────────────────────────────────────
    // AI features
    // ─────────────────────────────────────────────────────────────

    public function test_ai_remaining_requires_auth(): void
    {
        $this->post(route('blog.ai-remaining'), ['post_id' => $this->post->id])
            ->assertRedirect(route('login'));
    }

    public function test_ai_remaining_returns_counts(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('blog.ai-remaining'), ['post_id' => $this->post->id]);

        $response->assertOk()
            ->assertJsonStructure(['generate', 'correct']);
    }

    public function test_ai_remaining_returns_3_for_new_post(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('blog.ai-remaining'), ['post_id' => $this->post->id]);

        $this->assertEquals(3, $response->json('generate'));
        $this->assertEquals(3, $response->json('correct'));
    }

    public function test_ai_remaining_admin_returns_same_as_user(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('blog.ai-remaining'), ['post_id' => $this->post->id]);

        $this->assertEquals(
            3,
            $response->json('generate')
        );
    }

    public function test_ai_remaining_blocks_cross_organization(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);

        $otherPost = BlogPost::create([
            'user_id' => $this->user->id,
            'organization_id' => $otherOrg->id,
            'title' => 'Autre org',
            'content' => '<p>Contenu</p>',
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->post(route('blog.ai-remaining'), ['post_id' => $otherPost->id])
            ->assertNotFound();
    }

    public function test_ai_generate_cross_organization_is_blocked(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);

        $otherPost = BlogPost::create([
            'user_id' => $this->user->id,
            'organization_id' => $otherOrg->id,
            'title' => 'Autre org',
            'content' => '<p>Contenu</p>',
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), ['post_id' => $otherPost->id])
            ->assertNotFound();
    }

    public function test_ai_generate_requires_title_and_summary_when_no_post_id(): void
    {
        $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), [])
            ->assertStatus(422)
            ->assertJson(['error' => 'Ajoutez un titre et un résumé avant de générer l\'article.']);
    }

    public function test_ai_generate_without_post_id_uses_title_and_summary(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '<h2>Article généré</h2><p>Contenu de test.</p>']]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), [
                'title' => 'Mon titre',
                'summary' => 'Mon résumé',
                'category_id' => $this->category->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['content', 'remaining']);
    }

    public function test_t1010_ai_generate_removes_explanatory_preface_and_markdown_fences(): void
    {
        $rawContent = "Voici un article structuré en HTML avec un contenu clair et concis sur les défis écologiques futurs, respectant vos consignes :\n\n```html\n<h2>Article généré</h2><p>Contenu de test.</p>\n```\n\nJ'espère que cet article vous convient.";
        $expectedContent = '<h2>Article généré</h2><p>Contenu de test.</p>';

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => $rawContent]]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), [
                'title' => 'Article T1010 fences',
                'summary' => 'Résumé T1010',
                'category_id' => $this->category->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('content', $expectedContent);

        $this->assertStringNotContainsString('Voici un article', $response->json('content'));
        $this->assertStringNotContainsString('```', $response->json('content'));
        $this->assertStringNotContainsString("J'espère", $response->json('content'));

        $post = BlogPost::where('title', 'Article T1010 fences')->first();
        $this->assertNotNull($post);
        $this->assertSame($expectedContent, $post->content);

        $interaction = AiInteraction::where('feature', 'blog_generate')
            ->where('metadata->blog_post_id', $post->id)
            ->first();
        $this->assertNotNull($interaction);
        $this->assertStringContainsString('Voici un article', $interaction->response);
        $this->assertStringContainsString('```html', $interaction->response);
    }

    public function test_t1010_ai_generate_removes_preface_and_trailing_text_without_fences(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => "Voici une proposition.\n<h2>Article généré</h2><p>Contenu de test.</p>\nTexte parasite final."]]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), [
                'title' => 'Article T1010 sans fences',
                'summary' => 'Résumé T1010',
                'category_id' => $this->category->id,
            ])
            ->assertOk()
            ->assertJsonPath('content', '<h2>Article généré</h2><p>Contenu de test.</p>');
    }

    public function test_t1010_ai_generate_uses_current_english_locale_for_article_language(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '<h2>Generated article</h2><p>English content.</p>']]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $this->actingAs($this->user)
            ->withSession(['locale' => 'en'])
            ->post(route('blog.ai-generate'), [
                'title' => 'T1010 English article',
                'summary' => 'English summary.',
                'category_id' => $this->category->id,
            ])
            ->assertOk()
            ->assertJsonPath('content', '<h2>Generated article</h2><p>English content.</p>');

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            $prompt = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            return str_contains($prompt, 'Mandatory language: write the generated article in English')
                && ! str_contains($prompt, 'Langue obligatoire : rédige');
        });
    }

    public function test_t1010_ai_generate_strips_title_and_summary_from_content(): void
    {
        $rawContent = '<h1>Mon titre</h1><p>Mon résumé</p><h2>Introduction</h2><p>Le vrai contenu commence ici.</p>';

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => $rawContent]]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), [
                'title' => 'Mon titre',
                'summary' => 'Mon résumé',
                'category_id' => $this->category->id,
            ]);

        $response->assertOk();

        $content = $response->json('content');
        $this->assertStringNotContainsString('Mon titre', $content);
        $this->assertStringNotContainsString('Mon résumé', $content);
        $this->assertStringContainsString('<h2>Introduction</h2>', $content);
        $this->assertStringContainsString('Le vrai contenu', $content);
    }

    public function test_t1010_ai_generate_normalizes_h3_to_h2_after_title_strip(): void
    {
        $rawContent = '<h2>Mon titre</h2><p>Mon résumé.</p><h3>Sous-titre 1</h3><p>Paragraphe 1.</p><h3>Sous-titre 2</h3><p>Paragraphe 2.</p>';

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => $rawContent]]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), [
                'title' => 'Mon titre',
                'summary' => 'Mon résumé.',
                'category_id' => $this->category->id,
            ]);

        $response->assertOk();

        $content = $response->json('content');
        $this->assertStringNotContainsString('Mon titre', $content);
        $this->assertStringNotContainsString('Mon résumé.', $content);
        $this->assertStringContainsString('<h2>Sous-titre 1</h2>', $content);
        $this->assertStringContainsString('<h2>Sous-titre 2</h2>', $content);
        $this->assertStringNotContainsString('<h3>', $content);
    }

    public function test_ai_correct_requires_content_when_no_post_id(): void
    {
        $this->actingAs($this->user)
            ->post(route('blog.ai-correct'), ['content' => ''], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_ai_correct_without_post_id_uses_content(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '<p>Contenu corrigé.</p>']]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $this->actingAs($this->user)
            ->post(route('blog.ai-correct'), ['content' => '<p>Du contenu à corriger.</p>'], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonStructure(['content', 'provider', 'model', 'limit', 'remaining']);
    }

    public function test_ai_generate_limit_reached_returns_429(): void
    {
        // Create 3 existing interactions to exhaust the limit
        for ($i = 0; $i < 3; $i++) {
            AiInteraction::create([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'feature' => 'blog_generate',
                'model' => 'ollama/ministral-3:3b',
                'prompt' => 'Generate prompt '.$i,
                'response' => 'Generated content '.$i,
                'input_tokens' => 10,
                'output_tokens' => 20,
                'cost_usd' => 0,
                'metadata' => ['blog_post_id' => $this->post->id],
            ]);
        }

        $this->actingAs($this->user)
            ->post(route('blog.ai-generate'), ['post_id' => $this->post->id])
            ->assertStatus(429)
            ->assertJson(['error' => 'Limite de 3 utilisations atteinte pour cet article.']);
    }

    // ─────────────────────────────────────────────────────────────
    // Admin IA Usage dashboard
    // ─────────────────────────────────────────────────────────────

    public function test_admin_ia_usage_page_requires_admin(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.ia-usage'))
            ->assertForbidden();
    }

    public function test_admin_ia_usage_page_renders(): void
    {
        AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Test prompt',
            'response' => 'Test response',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cost_usd' => 0.0001,
            'metadata' => ['blog_post_id' => $this->post->id],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.ia-usage'));

        $response->assertOk();
        $response->assertSee('Utilisation IA');
    }

    public function test_admin_ia_usage_shows_blog_interactions(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Génère un article',
            'response' => 'Article généré',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cost_usd' => 0.0001,
            'metadata' => ['blog_post_id' => $this->post->id],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.ia-usage'));

        $response->assertOk()
            ->assertSee('Génère un article');
    }

    public function test_admin_ia_usage_shows_admin_interactions(): void
    {
        AdminAiInteraction::create([
            'user_id' => $this->admin->id,
            'scenario_id' => 'test-scenario',
            'input_excerpt' => 'Requête admin test',
            'result_summary' => 'Résultat admin test',
            'input_tokens' => 50,
            'output_tokens' => 100,
            'cost_usd' => 0.002,
            'model' => 'gpt-4o',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.ia-usage'));

        $response->assertOk()
            ->assertSee('Requête admin test');
    }

    public function test_admin_ia_usage_filters_by_feature(): void
    {
        AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Génération',
            'response' => 'Réponse',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cost_usd' => 0.0001,
            'metadata' => ['blog_post_id' => $this->post->id],
        ]);

        AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_correct',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Correction',
            'response' => 'Corrigé',
            'input_tokens' => 5,
            'output_tokens' => 10,
            'cost_usd' => 0.00005,
            'metadata' => ['blog_post_id' => $this->post->id],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.ia-usage', ['feature' => 'blog_generate']));

        $response->assertOk()
            ->assertSee('Génération')
            ->assertDontSee('Correction');
    }

    public function test_admin_ia_usage_detail_page_renders(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Prompt détaillé pour test',
            'response' => 'Réponse détaillée',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cost_usd' => 0.0001,
            'metadata' => ['blog_post_id' => $this->post->id, 'latency_ms' => 500],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.ia-usage.show', $interaction));

        $response->assertOk()
            ->assertSee('Prompt détaillé pour test')
            ->assertSee('Réponse détaillée');
    }

    // ─────────────────────────────────────────────────────────────
    // AiInteraction model
    // ─────────────────────────────────────────────────────────────

    public function test_ai_interaction_uses_uuids(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Test',
            'response' => 'Test',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
        ]);

        $this->assertTrue(strlen($interaction->id) === 36);
        $this->assertDatabaseHas('ai_interactions', ['id' => $interaction->id]);
    }

    public function test_ai_interaction_belongs_to_user(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Test',
            'response' => 'Test',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
        ]);

        $this->assertTrue($interaction->user->is($this->user));
    }

    public function test_ai_interaction_belongs_to_organization(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Test',
            'response' => 'Test',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
        ]);

        $this->assertTrue($interaction->organization->is($this->organization));
    }

    public function test_ai_interaction_does_not_have_updated_at(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'Test',
            'response' => 'Test',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
        ]);

        $this->assertNull($interaction->updated_at);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);
        parent::tearDown();
    }
}
