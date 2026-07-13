<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Models\BlogAnalysisNote;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class T1000BlogExplorerTest extends TestCase
{
    private Organization $mainOrg;

    private Organization $launchpalsOrg;

    private User $owner;

    private User $admin;

    private User $coAuthor;

    private User $outsideUser;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.default_provider' => 'openai',
            'ai.default_model' => 'gpt-test',
            'ai.openai.api_key' => 'test-key',
            'ai.openai.base_url' => 'https://api.openai.test/v1',
            'ai.openai.model' => 'gpt-test',
            'ai.openai.timeout' => 5,
        ]);

        $this->mainOrg = Organization::factory()->create(['slug' => 'main']);
        $this->launchpalsOrg = Organization::factory()->create(['slug' => 'launchpals']);

        $this->owner = User::factory()->create(['organization_id' => $this->mainOrg->id]);
        $this->admin = User::factory()->create(['organization_id' => $this->mainOrg->id, 'is_admin' => true]);
        $this->coAuthor = User::factory()->create(['organization_id' => $this->mainOrg->id]);
        $this->outsideUser = User::factory()->create(['organization_id' => $this->launchpalsOrg->id]);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->mainOrg->id,
            'title' => 'Article T1000 Explorer',
            'summary' => 'Résumé sauvegardé pour Explorer.',
            'content' => '<p>Texte sauvegardé de l’article pour questionner la clarté, la structure et la profondeur.</p>',
            'status' => 'draft',
        ]);

        $this->post->coAuthors()->attach($this->coAuthor->id, ['role' => 'coauthor', 'added_by' => $this->owner->id]);

        app()->instance('current_organization', $this->mainOrg);
    }

    public function test_t1000_owner_can_dialogue_generate_save_edit_and_delete_questioning_without_annotation(): void
    {
        $this->actingAs($this->owner)->get(route('blog.edit', $this->post))
            ->assertOk()
            ->assertSee(__('blog.editor_explorer'));

        Http::fake([
            'api.openai.test/v1/chat/completions' => Http::sequence()
                ->push($this->openAiResponse('Réponse Explorer réelle appuyée sur Article T1000 Explorer.'), 200)
                ->push($this->openAiResponse('<h3>Questionnement</h3><p><em>Note issue du dialogue.</em></p><h4>Points à conserver</h4><ul><li>Le texte sauvegardé donne une base claire pour approfondir la structure et l’impact.</li><li>La perspective Explorer permet de formuler des questions utiles sans réécrire l’article.</li></ul><h4>Questions à creuser</h4><ul><li>Quel passage mérite davantage de nuance pour renforcer la profondeur éditoriale ?</li></ul>'), 200),
        ]);

        $chat = $this->actingAs($this->owner)->postJson(route('blog.explorer.chat', $this->post), [
            'message' => 'Que faut-il questionner ?',
            'messages' => [],
        ]);

        $chat->assertOk()->assertJsonPath('text', 'Réponse Explorer réelle appuyée sur Article T1000 Explorer.');

        $note = $this->actingAs($this->owner)->postJson(route('blog.explorer.note.generate', $this->post), [
            'messages' => [
                ['role' => 'user', 'text' => 'Que faut-il questionner ?'],
                ['role' => 'assistant', 'text' => 'Travaillons la structure.'],
            ],
        ]);

        $note->assertOk()
            ->assertJsonPath('note', fn (string $html) => str_contains($html, '<h3>Questionnement</h3>'));

        $store = $this->actingAs($this->owner)->postJson(route('blog.explorer.notes.store', $this->post), [
            'note_content' => $note->json('note'),
        ]);

        $store->assertOk()->assertJsonPath('message', __('blog.explorer_note_saved'));

        $analysisNote = BlogAnalysisNote::query()->firstOrFail();
        $this->assertSame('explorer', $analysisNote->method);
        $this->assertSame($this->mainOrg->id, $analysisNote->organization_id);
        $this->assertDatabaseCount('blog_post_annotations', 0);

        $updatedHtml = '<h3>Questionnement</h3><p>Version éditée avec assez de contenu visible pour valider la sauvegarde du questionnement, sans annotation IA, sans insertion dans le contenu TipTap de l’article.</p><ul><li>Une piste éditoriale claire est conservée.</li></ul>';

        $update = $this->actingAs($this->owner)->putJson(route('blog.explorer.notes.update', [$this->post, $analysisNote]), [
            'note_content' => $updatedHtml,
        ]);

        $update->assertOk()->assertJsonPath('note_content', $updatedHtml);

        $this->assertDatabaseHas('blog_analysis_notes', [
            'id' => $analysisNote->id,
            'note_content' => $updatedHtml,
        ]);
        $this->assertDatabaseCount('blog_post_annotations', 0);

        $delete = $this->actingAs($this->owner)->deleteJson(route('blog.explorer.notes.destroy', [$this->post, $analysisNote]));

        $delete->assertOk()->assertJsonPath('message', __('blog.explorer_note_deleted'));
        $this->assertDatabaseMissing('blog_analysis_notes', ['id' => $analysisNote->id]);
    }

    public function test_t1000_owner_admin_and_coauthor_can_list_notes_but_outside_user_is_refused(): void
    {
        $analysisNote = BlogAnalysisNote::create([
            'blog_post_id' => $this->post->id,
            'user_id' => $this->owner->id,
            'organization_id' => $this->mainOrg->id,
            'method' => 'explorer',
            'note_content' => '<h3>Questionnement</h3><p>Questionnement suffisamment long pour valider la lecture par les utilisateurs autorisés.</p>',
        ]);

        $this->actingAs($this->owner)->getJson(route('blog.explorer.notes.index', $this->post))->assertOk()->assertJsonCount(1, 'notes');
        $this->actingAs($this->admin)->getJson(route('blog.explorer.notes.index', $this->post))->assertOk()->assertJsonCount(1, 'notes');
        $this->actingAs($this->coAuthor)->getJson(route('blog.explorer.notes.index', $this->post))->assertOk()->assertJsonCount(1, 'notes');

        $this->actingAs($this->outsideUser)->getJson(route('blog.explorer.notes.index', $this->post))->assertForbidden();
        $this->actingAs($this->outsideUser)->putJson(route('blog.explorer.notes.update', [$this->post, $analysisNote]), [
            'note_content' => '<p>Refus cross-org avec contenu long pour passer la validation de forme mais pas l’autorisation.</p>',
        ])->assertForbidden();
        $this->actingAs($this->outsideUser)->deleteJson(route('blog.explorer.notes.destroy', [$this->post, $analysisNote]))->assertForbidden();
    }

    public function test_t1000_cross_organization_context_returns_404_without_content_leak(): void
    {
        app()->instance('current_organization', $this->launchpalsOrg);

        $response = $this->actingAs($this->outsideUser)->getJson(route('blog.explorer.notes.index', $this->post));

        $response->assertNotFound();
        $response->assertDontSee('Questionnement');
        $response->assertDontSee('Article T1000 Explorer');

        $launchAdmin = User::factory()->create(['organization_id' => $this->launchpalsOrg->id, 'is_admin' => true]);
        $launchPost = BlogPost::create([
            'user_id' => $launchAdmin->id,
            'organization_id' => $this->launchpalsOrg->id,
            'title' => 'LaunchPals T1000 Explorer',
            'summary' => 'Résumé LaunchPals confidentiel.',
            'content' => '<p>Contenu LaunchPals sauvegardé pour vérifier l’isolation Organization.</p>',
            'status' => 'draft',
        ]);
        $launchNote = BlogAnalysisNote::create([
            'blog_post_id' => $launchPost->id,
            'user_id' => $launchAdmin->id,
            'organization_id' => $this->launchpalsOrg->id,
            'method' => 'explorer',
            'note_content' => '<h3>Secret LaunchPals T1000</h3><p>Questionnement LaunchPals suffisamment long pour tester le refus d’un admin externe.</p>',
        ]);

        $list = $this->actingAs($this->admin)->getJson(route('organization.blog.explorer.notes.index', [
            'organization' => $this->launchpalsOrg->slug,
            'post' => $launchPost,
        ]));
        $list->assertForbidden();
        $list->assertDontSee('Secret LaunchPals T1000');

        $this->actingAs($this->admin)->putJson(route('organization.blog.explorer.notes.update', [
            'organization' => $this->launchpalsOrg->slug,
            'post' => $launchPost,
            'note' => $launchNote,
        ]), [
            'note_content' => '<p>Modification cross-org interdite avec assez de texte visible pour passer la validation de forme.</p>',
        ])->assertForbidden();

        $this->actingAs($this->admin)->deleteJson(route('organization.blog.explorer.notes.destroy', [
            'organization' => $this->launchpalsOrg->slug,
            'post' => $launchPost,
            'note' => $launchNote,
        ]))->assertForbidden();

        $this->actingAs($launchAdmin)->getJson(route('organization.blog.explorer.notes.index', [
            'organization' => $this->launchpalsOrg->slug,
            'post' => $launchPost,
        ]))->assertOk()->assertSee('Secret LaunchPals T1000');
    }

    public function test_t1000_prompts_are_locale_scoped_and_inactive_locale_falls_back_without_demo_mode(): void
    {
        $scenarioIds = AdminAiPrompt::query()
            ->whereIn('scenario_id', [
                'blog_explorer_dialogue_fr',
                'blog_explorer_dialogue_en',
                'blog_explorer_note_fr',
                'blog_explorer_note_en',
            ])
            ->pluck('scenario_id')
            ->all();

        $this->assertEqualsCanonicalizing([
            'blog_explorer_dialogue_fr',
            'blog_explorer_dialogue_en',
            'blog_explorer_note_fr',
            'blog_explorer_note_en',
        ], $scenarioIds);

        AdminAiPrompt::where('scenario_id', 'blog_explorer_dialogue_en')->update(['is_active' => false]);
        app()->setLocale('en');

        Http::fake([
            'api.openai.test/v1/chat/completions' => Http::response($this->openAiResponse('Fallback FR utilisé sans mode démo.'), 200),
        ]);

        $this->actingAs($this->owner)->postJson(route('blog.explorer.chat', $this->post), [
            'message' => 'Use fallback?',
            'messages' => [],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $system = $payload['messages'][0]['content'] ?? '';

            return str_contains($system, 'ARTICLE SAUVEGARDÉ À ANALYSER')
                && str_contains($system, 'Article T1000 Explorer')
                && ! str_contains(strtolower($system), 'demo mode')
                && ! str_contains(strtolower($system), 'mock');
        });
    }

    public function test_t1000_deep_chat_payload_limit_is_50_messages(): void
    {
        $messages = [];
        for ($i = 0; $i < 50; $i++) {
            $messages[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'text' => 'Message '.$i];
        }

        Http::fake([
            'api.openai.test/v1/chat/completions' => Http::response($this->openAiResponse('Limite cinquante acceptée.'), 200),
        ]);

        $this->actingAs($this->owner)->postJson(route('blog.explorer.chat', $this->post), [
            'message' => 'Message final',
            'messages' => $messages,
        ])->assertOk();

        $messages[] = ['role' => 'user', 'text' => 'Message 51'];

        $this->actingAs($this->owner)->postJson(route('blog.explorer.chat', $this->post), [
            'message' => 'Trop de messages',
            'messages' => $messages,
        ])->assertUnprocessable();
    }

    private function openAiResponse(string $content): array
    {
        return [
            'choices' => [
                ['message' => ['content' => $content]],
            ],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 24,
            ],
        ];
    }
}
