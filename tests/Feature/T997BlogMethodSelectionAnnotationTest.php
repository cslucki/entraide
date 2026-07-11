<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\BlogPostAnnotation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class T997BlogMethodSelectionAnnotationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $coAuthor;

    private User $otherUser;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.default_provider' => 'openai',
            'ai.openai.api_key' => 'test-key',
            'ai.openai.model' => 'gpt-test',
        ]);

        $this->org = Organization::factory()->create(['is_active' => true]);

        $this->owner = User::factory()->create([
            'organization_id' => $this->org->id,
            'preferred_locale' => 'fr',
        ]);
        $this->coAuthor = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->org->id]);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Article méthode IA',
            'slug' => 'article-methode-ia',
            'content' => '<p>Un passage intéressant à discuter avec précision.</p>',
            'summary' => 'Résumé',
            'status' => 'draft',
        ]);

        $this->post->coAuthors()->attach($this->coAuthor->id, ['added_by' => $this->owner->id]);

        app()['current_organization'] = $this->org;
        app()->setLocale('fr');
    }

    public function test_method_selection_requires_auth(): void
    {
        $this->postJson(route('blog.ai-method-selection'), [
            'post_id' => $this->post->id,
            'method' => 'clarifier',
            'selected_text' => 'Un passage sélectionné.',
        ])->assertUnauthorized();
    }

    public function test_method_selection_returns_single_suggestion_and_logs_interaction_without_creating_annotation(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "## Observation\n*LaunchPals* mérite précision.\n\n---\n\n<script>alert('x')</script>**Question** : quel effet social ?\n\n**Piste** : nommer l'accélération structurelle.",
                    ],
                ]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 18],
            ]),
        ]);

        $response = $this->actingAs($this->owner)->postJson(route('blog.ai-method-selection'), [
            'post_id' => $this->post->id,
            'method' => 'clarifier',
            'selected_text' => 'Un passage sélectionné à analyser.',
            'start_offset' => 4,
            'end_offset' => 42,
            'context_before' => 'Avant',
            'context_after' => 'Après',
        ]);

        $response->assertOk()
            ->assertJsonPath('method', 'clarifier')
            ->assertJsonPath('method_name', 'Clarifier')
            ->assertJsonPath('scope', 'selection')
            ->assertJsonStructure(['content', 'provider', 'model', 'method', 'method_name', 'scope', 'ai_interaction_id']);

        $content = $response->json('content');
        $this->assertLessThanOrEqual(650, mb_strlen($content));
        $this->assertStringContainsString('Observation', $content);
        $this->assertStringContainsString('LaunchPals', $content);
        $this->assertStringContainsString('accélération structurelle', $content);
        $this->assertStringContainsString('Piste', $content);
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('**', $content);
        $this->assertStringNotContainsString('*LaunchPals*', $content);
        $this->assertStringNotContainsString('##', $content);
        $this->assertStringNotContainsString('---', $content);

        $this->assertDatabaseHas('ai_interactions', [
            'id' => $response->json('ai_interaction_id'),
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'feature' => 'blog_method_selection_clarifier_fr',
        ]);
        $this->assertDatabaseCount('blog_post_annotations', 0);
    }

    public function test_method_selection_is_selection_only_and_validates_method(): void
    {
        $this->actingAs($this->owner)->postJson(route('blog.ai-method-selection'), [
            'post_id' => $this->post->id,
            'method' => 'clarifier',
            'selected_text' => '',
        ])->assertUnprocessable();

        $this->actingAs($this->owner)->postJson(route('blog.ai-method-selection'), [
            'post_id' => $this->post->id,
            'method' => 'chat',
            'selected_text' => 'Un passage sélectionné.',
        ])->assertUnprocessable();
    }

    public function test_non_coauthor_cannot_run_method_selection(): void
    {
        $this->actingAs($this->otherUser)->postJson(route('blog.ai-method-selection'), [
            'post_id' => $this->post->id,
            'method' => 'explorer',
            'selected_text' => 'Un passage sélectionné.',
        ])->assertForbidden();
    }

    public function test_ai_method_annotation_keeps_human_user_and_origin_metadata(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'feature' => 'blog_method_selection_slow_down_fr',
            'model' => 'openai/gpt-test',
            'prompt' => 'Prompt',
            'response' => 'Réponse',
            'input_tokens' => 1,
            'output_tokens' => 2,
            'cost_usd' => 0,
            'metadata' => ['blog_post_id' => $this->post->id],
        ]);

        $response = $this->actingAs($this->owner)->postJson(route('blog.annotations.store', $this->post), [
            'selected_text' => '<b>Passage</b> ciblé',
            'content' => '<script>alert(1)</script>Suggestion **utile** avec *LaunchPals* {{ danger }}',
            'start_offset' => 10,
            'end_offset' => 40,
            'origin' => 'ai_method',
            'method_key' => 'slow_down',
            'ai_interaction_id' => $interaction->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('annotation.origin', 'ai_method')
            ->assertJsonPath('annotation.method_key', 'slow_down')
            ->assertJsonPath('annotation.method_label', 'Ralentir')
            ->assertJsonPath('annotation.source_label', 'Questionnement')
            ->assertJsonPath('annotation.requested_by_label', 'demandé par '.$this->owner->fullName)
            ->assertJsonPath('annotation.ai_interaction_id', $interaction->id)
            ->assertJsonPath('annotation.author_name', $this->owner->fullName);

        $annotation = BlogPostAnnotation::firstOrFail();
        $this->assertSame($this->owner->id, $annotation->user_id);
        $this->assertSame('ai_method', $annotation->origin);
        $this->assertSame('slow_down', $annotation->method_key);
        $this->assertSame($interaction->id, $annotation->ai_interaction_id);
        $this->assertStringNotContainsString('<script', $annotation->content);
        $this->assertStringNotContainsString('{{', $annotation->content);
        $this->assertStringContainsString('LaunchPals', $annotation->content);
        $this->assertStringNotContainsString('*LaunchPals*', $annotation->content);
        $this->assertStringNotContainsString('**', $annotation->content);
    }

    public function test_human_annotation_defaults_to_human_origin(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('blog.annotations.store', $this->post), [
            'selected_text' => 'Passage humain',
            'content' => 'Annotation humaine simple',
            'start_offset' => 10,
            'end_offset' => 40,
        ]);

        $response->assertCreated()
            ->assertJsonPath('annotation.origin', 'human')
            ->assertJsonPath('annotation.source_label', 'Humains')
            ->assertJsonPath('annotation.method_key', null);

        $annotation = BlogPostAnnotation::firstOrFail();
        $this->assertSame('human', $annotation->origin);
        $this->assertNull($annotation->method_key);
    }

    public function test_edit_page_contains_selection_tool_and_source_filters_without_deferred_scope(): void
    {
        $response = $this->actingAs($this->owner)->get(route('blog.edit', $this->post));

        $response->assertOk()
            ->assertSee('Questionner le texte')
            ->assertSee('Choisissez une méthode pour examiner le passage sélectionné.')
            ->assertSee("Sélectionnez un passage de l\xe2\x80\x99article pour lancer une méthode IA.", false)
            ->assertSee('Toutes')
            ->assertSee('Humains')
            ->assertSee('Questionnement')
            ->assertSee('sourceFilter')
            ->assertSee('bp-btn-method')
            ->assertSee('bp-annotation-mark-method')
            ->assertSee('Créer une annotation')
            ->assertDontSee('Analyser l\u2019article entier', false)
            ->assertDontSee('Réécrire le passage', false)
            ->assertDontSee('method_selection_add_task', false);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('open-method-selection-card', $script);
        $this->assertStringContainsString('blog-editor-selection-updated', $script);
        $this->assertStringNotContainsString('open-method-selection-modal', $script);
        $this->assertStringNotContainsString('requestRewrite()', $script);
        $this->assertStringNotContainsString('addTask()', $script);
        $this->assertStringNotContainsString('userMessageCount()', $script);
        $this->assertStringNotContainsString('open-blog-todo-card', $script);
        $this->assertStringContainsString('annotation-selected', $script);
        $this->assertStringContainsString('openForAnnotation', $script);
        $this->assertStringContainsString('sourceFilter = \'ai_method\'', $script);
        $this->assertStringContainsString('data-annotation-origin', file_get_contents(resource_path('js/tiptap/annotation-mark.js')));
    }

    public function test_method_selection_truncation_respects_sentence_boundary(): void
    {
        $longContent = 'Observation : Ce passage développe une idée centrale sur la politique et ses dynamiques. '
            .'**Question** : Quel est le lien entre la politique et les structures de pouvoir ? '
            .'La réponse se trouve dans une analyse approfondie des mécanismes institutionnels. '
            .'Piste : Explorer les connexions entre pouvoir politique et légitimité démocratique '
            .'en examinant les fondements théoriques de la pensée politique contemporaine. '
            .'Cette analyse doit intégrer les dimensions sociologiques et philosophiques '
            .'qui sous-tendent les rapports de force dans les sociétés modernes. '
            .'Il convient également de considérer les apports de la théorie critique '
            .'et les perspectives émergentes sur la gouvernance participative.';

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => $longContent,
                    ],
                ]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 50],
            ]),
        ]);

        $response = $this->actingAs($this->owner)->postJson(route('blog.ai-method-selection'), [
            'post_id' => $this->post->id,
            'method' => 'explorer',
            'selected_text' => 'Un passage à explorer en détail.',
        ]);

        $response->assertOk();

        $content = $response->json('content');

        $this->assertLessThanOrEqual(650, mb_strlen($content));
        $this->assertGreaterThan(0, mb_strlen($content));

        $this->assertMatchesRegularExpression('/[.!?…]$/', $content, 'Content must end at a sentence boundary');

        $this->assertStringNotContainsString('**', $content);
        $this->assertStringNotContainsString('##', $content);
        $this->assertStringNotContainsString('<script', $content);

        $lastWord = preg_split('/\s+/', trim($content));
        $lastWord = end($lastWord);
        $this->assertGreaterThan(3, mb_strlen($lastWord), 'Last word must not be a truncated fragment (min 4 chars)');
    }
}
