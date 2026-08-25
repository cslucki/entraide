<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiInteractionFeedback;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\BlogAiService;
use App\Services\UserDataLifecycleRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1256 — Human Feedback V1 sur l'Article Explorer (les quatre methodes
 * de Roger + le dialogue libre), suite de l'audit T1255.
 *
 *  A. `chat()` renvoie `{text, ai_interaction_id}` (contrat `methodSelection()`) ;
 *  B. `metadata.method_code` sur la trace du dialogue (UNE cle ; `null` pour
 *     le dialogue libre ; absente de la note) — AUCUNE colonne nouvelle sur
 *     `ai_interactions`, ledger `ai_provider_invocations` IDENTIQUE ;
 *  C. creation du feedback : verdict seul suffit, commentaire / meilleure
 *     reponse optionnels, un feedback par (interaction, acteur) mis a jour ;
 *  D. controle d'acces STRICT : tenant, droits Explorer, interaction du bon
 *     tenant / du bon article / du dialogue Explorer — 404 propre sinon,
 *     rien d'ecrit, rien de revele ;
 *  E. retention : le feedback suit l'interaction, l'acteur et le tenant
 *     (CASCADE reels, avant / apres) ;
 *  F. schema FERME : aucune colonne export / training / consent, aucune
 *     copie de prompt / reponse ; registre de cycle de vie = verite.
 */
#[Group('ai')]
class TASK1256ExplorerHumanFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $author;

    private User $coAuthor;

    private User $admin;

    private User $memberWithoutRights;

    private User $outsider;

    private BlogPost $post;

    private BlogPost $otherPost;

    private BlogPost $foreignPost;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openai' => [
                    'gpt-catalogued' => ['input_per_1m' => 1.0, 'output_per_1m' => 4.0],
                ],
            ],
            'ai.default_provider' => 'openai',
            'ai.default_model' => null,
            'ai.openai.api_key' => 'platform-test-key',
            'ai.openai.model' => 'gpt-catalogued',
            'ai.openai.base_url' => 'https://api.openai.test/v1',
            'ai.blog.economic_guard.monthly_budget_usd' => 2.00,
            'ai.blog.economic_guard.monthly_unknown_limit' => 10,
        ]);

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'orga-1256']);
        $this->otherOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'orga-1256-b']);

        $this->author = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->coAuthor = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true, 'preferred_locale' => 'fr']);
        $this->memberWithoutRights = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->outsider = User::factory()->create(['organization_id' => $this->otherOrganization->id, 'preferred_locale' => 'fr']);

        $this->post = $this->makePost($this->author, $this->organization, 'article-task-1256');
        $this->post->coAuthors()->attach($this->coAuthor->id, ['role' => 'coauthor', 'added_by' => $this->author->id]);
        $this->otherPost = $this->makePost($this->author, $this->organization, 'article-task-1256-autre');
        $this->foreignPost = $this->makePost($this->outsider, $this->otherOrganization, 'article-task-1256-etranger');

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        Http::preventStrayRequests();
    }

    private function makePost(User $author, Organization $organization, string $slug): BlogPost
    {
        return BlogPost::create([
            'user_id' => $author->id,
            'organization_id' => $organization->id,
            'title' => 'Article '.$slug,
            'slug' => $slug,
            'content' => '<p>'.str_repeat('Un contenu sauvegarde que Roger aide a questionner. ', 12).'</p>',
            'summary' => 'Resume '.$slug,
            'status' => 'draft',
        ]);
    }

    private function fakeChatCompletion(string $text = 'Un angle : les faits. Quelle donnee soutient votre premiere affirmation ?'): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => $text]]],
                'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 60],
            ]),
        ]);
    }

    private function chat(array $payload = [], ?User $as = null, ?BlogPost $post = null)
    {
        return $this->actingAs($as ?? $this->author)
            ->postJson(route('blog.explorer.chat', $post ?? $this->post), $payload + ['message' => 'Par ou commencer ?']);
    }

    private function feedback(array $payload, ?User $as = null, ?BlogPost $post = null)
    {
        return $this->actingAs($as ?? $this->author)
            ->postJson(route('blog.explorer.feedback.store', $post ?? $this->post), $payload);
    }

    /**
     * Une reponse Explorer REELLE (chemin complet : garde, ledger, trace) pour
     * l'article donne, par l'acteur donne ; renvoie sa trace.
     */
    private function explorerInteraction(?User $as = null, ?BlogPost $post = null, ?string $methodCode = null): AiInteraction
    {
        $post ??= $this->post;
        // Pas de `Http::fake()` nu avant : son stub `*` vide, enregistre en
        // premier, repondrait a la place du stub de contenu.
        $this->fakeChatCompletion();

        if ($post->organization_id !== $this->organization->id) {
            app()->instance('current_organization', $post->organization);
        }

        $response = $this->chat(['method_code' => $methodCode], $as, $post)->assertOk();

        app()->instance('current_organization', $this->organization);

        return AiInteraction::query()->findOrFail($response->json('ai_interaction_id'));
    }

    // =====================================================================
    // A. Contrat : chat() renvoie l'id de sa trace
    // =====================================================================

    public function test_chat_returns_the_text_and_the_id_of_its_trace_like_method_selection_does(): void
    {
        $this->fakeChatCompletion('Reponse de Roger T1256.');

        $response = $this->chat()->assertOk()->assertJsonStructure(['text', 'ai_interaction_id']);

        $interaction = AiInteraction::query()->firstOrFail();
        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame($interaction->id, $response->json('ai_interaction_id'));
        $this->assertSame('Reponse de Roger T1256.', $response->json('text'));
        $this->assertSame('Reponse de Roger T1256.', $interaction->response, 'Le texte affiche EST la trace (aucune copie a faire cote feedback).');
        $this->assertSame('blog_explorer', $interaction->feature);
        $this->assertSame($this->organization->id, $interaction->organization_id, 'Tenant = Organization de l\'article.');
        $this->assertSame($this->author->id, $interaction->user_id);
        $this->assertSame($this->post->id, $interaction->metadata['blog_post_id']);
    }

    public function test_the_organization_route_alias_returns_the_id_too(): void
    {
        $this->fakeChatCompletion();

        $response = $this->actingAs($this->author)
            ->postJson(route('organization.blog.explorer.chat', [
                'organization' => $this->organization->slug,
                'post' => $this->post,
            ]), ['message' => 'Par ou commencer ?', 'method_code' => 'slow_down'])
            ->assertOk()
            ->assertJsonStructure(['text', 'ai_interaction_id']);

        $this->assertSame(AiInteraction::query()->firstOrFail()->id, $response->json('ai_interaction_id'));
    }

    public function test_an_unsaved_article_and_an_economic_refusal_never_carry_an_interaction_id(): void
    {
        Http::fake();

        $empty = $this->makePost($this->author, $this->organization, 'article-task-1256-vide');
        $empty->forceFill(['content' => ''])->saveQuietly();

        $this->chat([], null, $empty)
            ->assertOk()
            ->assertJsonStructure(['text'])
            ->assertJsonMissingPath('ai_interaction_id');

        // Refus economique : aucune cle plateforme => `ai_not_configured`,
        // 429, rien d'ecrit — donc rien a referencer.
        config(['ai.openai.api_key' => '']);
        $this->chat()
            ->assertStatus(429)
            ->assertJsonMissingPath('ai_interaction_id')
            ->assertJsonMissingPath('text');

        Http::assertNothingSent();
        $this->assertSame(0, AiInteraction::query()->count());
    }

    // =====================================================================
    // B. method_code dans la trace (metadata seulement), ledger identique
    // =====================================================================

    public function test_each_method_and_the_free_dialogue_write_a_distinct_correct_method_code_in_the_trace_metadata(): void
    {
        $expected = [];
        foreach (BlogAiService::METHOD_SELECTION_METHODS as $method) {
            $interaction = $this->explorerInteraction(null, null, $method);
            $this->assertArrayHasKey('method_code', $interaction->metadata, "Methode {$method} : la cle est ecrite.");
            $this->assertSame($method, $interaction->metadata['method_code']);
            $this->assertStringContainsString('Quelle donnee', (string) $interaction->response, 'La reponse reelle est tracee, comme avant.');
            $expected[$interaction->id] = $method;
        }

        // Dialogue libre : la cle EXISTE et vaut null — « pas de methode » est
        // une information, pas une absence.
        $free = $this->explorerInteraction();
        $this->assertArrayHasKey('method_code', $free->metadata);
        $this->assertNull($free->metadata['method_code']);
        $expected[$free->id] = null;

        // Relecture SQL a posteriori : chaque reponse est attribuable a sa
        // methode — la limite reelle de l'audit T1255 (section 8) est levee.
        $this->assertSame(5, AiInteraction::query()->count());
        $byId = AiInteraction::query()->get()->mapWithKeys(fn (AiInteraction $i) => [$i->id => array_key_exists('method_code', $i->metadata) ? $i->metadata['method_code'] : 'ABSENT'])->all();
        $this->assertEquals($expected, $byId);
        $this->assertCount(5, array_unique(array_map(fn ($v) => (string) $v, $byId)), 'Quatre methodes + dialogue libre = cinq valeurs distinctes.');

        // Les cles communes de la trace sont intactes.
        foreach (['blog_post_id', 'latency_ms', 'provider', 'credential_source'] as $key) {
            $this->assertArrayHasKey($key, $free->metadata);
        }
        $this->assertSame($this->post->id, $free->metadata['blog_post_id']);
    }

    public function test_the_note_trace_does_not_carry_a_method_code_key(): void
    {
        $this->fakeChatCompletion('<h3>Note</h3><p>'.str_repeat('Une note d\'analyse suffisamment longue pour la validation. ', 6).'</p>');

        $this->actingAs($this->author)
            ->postJson(route('blog.explorer.note.generate', $this->post), [
                'messages' => [
                    ['role' => 'user', 'text' => 'Question'],
                    ['role' => 'assistant', 'text' => 'Reponse'],
                ],
            ])
            ->assertOk()
            ->assertJsonStructure(['note', 'length']);

        $note = AiInteraction::query()->where('feature', 'blog_explorer_note')->firstOrFail();
        $this->assertArrayNotHasKey('method_code', $note->metadata, 'La note n\'est pas un tour de dialogue : pas de methode.');
    }

    public function test_method_code_lives_in_metadata_only_no_new_column_on_ai_interactions_and_the_ledger_is_exactly_identical(): void
    {
        // AUCUNE colonne nouvelle sur la trace : liste fermee.
        $this->assertSame([
            'correlation_id', 'cost_unknown', 'cost_usd', 'created_at', 'feature', 'id', 'input_tokens',
            'metadata', 'model', 'organization_id', 'output_tokens', 'process', 'prompt', 'response', 'user_id',
        ], collect(Schema::getColumnListing('ai_interactions'))->sort()->values()->all());

        // Ledger : une ligne par appel, strictement la meme enveloppe
        // economique pour une methode et pour le dialogue libre (T1249).
        $withMethod = $this->explorerInteraction(null, null, 'clarifier');
        $free = $this->explorerInteraction();

        $this->assertFalse(Schema::hasColumn('ai_provider_invocations', 'method_code'));
        $this->assertFalse(Schema::hasColumn('ai_provider_invocations', 'metadata'));

        $ledger = AiProviderInvocation::query()->orderBy('created_at')->get();
        $this->assertCount(2, $ledger);

        $strip = fn (AiProviderInvocation $row) => collect($row->getAttributes())
            ->except(['id', 'correlation_id', 'created_at', 'updated_at', 'started_at', 'completed_at'])
            ->all();
        $this->assertSame($strip($ledger[0]), $strip($ledger[1]), 'Le ledger ne porte aucune semantique de methode : deux lignes identiques hors identifiants et horodatages.');

        foreach ($ledger as $row) {
            $this->assertSame('blog_explorer', $row->feature);
            $this->assertSame('blog.explorer_dialogue', $row->process);
            $this->assertNull($row->capability);
            $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
            $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
            $this->assertSame($this->organization->id, $row->organization_id);
        }
        $this->assertSame($withMethod->correlation_id, $ledger[0]->correlation_id);
        $this->assertSame($free->correlation_id, $ledger[1]->correlation_id);
    }

    // =====================================================================
    // C. Creation du feedback
    // =====================================================================

    public function test_a_verdict_alone_is_enough_and_copies_the_tenant_of_the_interaction(): void
    {
        $interaction = $this->explorerInteraction(null, null, 'explorer');

        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'])
            ->assertOk()
            ->assertJsonPath('verdict', 'helpful')
            ->assertJsonPath('ai_interaction_id', $interaction->id)
            ->assertJsonStructure(['id', 'message']);

        $this->assertSame(1, AiInteractionFeedback::query()->count());
        $row = AiInteractionFeedback::query()->firstOrFail();
        $this->assertSame($interaction->id, $row->ai_interaction_id);
        $this->assertSame($interaction->organization_id, $row->organization_id, 'organization_id = copie du tenant de l\'interaction.');
        $this->assertSame($this->author->id, $row->user_id);
        $this->assertSame('helpful', $row->verdict);
        $this->assertNull($row->comment);
        $this->assertNull($row->suggested_response);

        // Et la relation depuis la trace (la methode reste lisible par la FK).
        $this->assertSame('explorer', $interaction->feedbacks()->firstOrFail()->interaction->metadata['method_code']);
    }

    public function test_comment_and_suggested_response_are_optional_and_blank_strings_become_null(): void
    {
        $interaction = $this->explorerInteraction();

        $this->feedback([
            'ai_interaction_id' => $interaction->id,
            'verdict' => 'improve',
            'comment' => '  Trop general, ne s\'appuie pas sur mon deuxieme paragraphe.  ',
            'suggested_response' => 'Quel exemple concret du paragraphe 2 illustre votre affirmation ?',
        ])->assertOk();

        $row = AiInteractionFeedback::query()->firstOrFail();
        $this->assertSame('improve', $row->verdict);
        $this->assertSame('Trop general, ne s\'appuie pas sur mon deuxieme paragraphe.', $row->comment);
        $this->assertSame('Quel exemple concret du paragraphe 2 illustre votre affirmation ?', $row->suggested_response);

        $other = $this->explorerInteraction();
        $this->feedback([
            'ai_interaction_id' => $other->id,
            'verdict' => 'helpful',
            'comment' => '   ',
            'suggested_response' => '',
        ])->assertOk();

        $blank = AiInteractionFeedback::query()->where('ai_interaction_id', $other->id)->firstOrFail();
        $this->assertNull($blank->comment);
        $this->assertNull($blank->suggested_response);
    }

    public function test_a_second_feedback_by_the_same_person_on_the_same_response_updates_the_same_row(): void
    {
        $interaction = $this->explorerInteraction();

        $first = $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'])->assertOk()->json('id');

        // Disclosure apres le clic : le MEME enregistrement se complete.
        $second = $this->feedback([
            'ai_interaction_id' => $interaction->id,
            'verdict' => 'helpful',
            'comment' => 'La question m\'a fait relire mon introduction.',
        ])->assertOk()->json('id');

        // Changement d'avis : toujours la meme ligne, dernier envoi complet.
        $third = $this->feedback([
            'ai_interaction_id' => $interaction->id,
            'verdict' => 'improve',
            'comment' => 'Finalement trop vague.',
            'suggested_response' => 'Une question sur le chiffre du paragraphe 3.',
        ])->assertOk()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertSame(1, AiInteractionFeedback::query()->count(), 'Un feedback par (interaction, acteur).');

        $row = AiInteractionFeedback::query()->findOrFail($first);
        $this->assertSame('improve', $row->verdict);
        $this->assertSame('Finalement trop vague.', $row->comment);
        $this->assertSame('Une question sur le chiffre du paragraphe 3.', $row->suggested_response);
    }

    public function test_two_different_people_can_judge_the_same_response_and_the_unique_constraint_is_real(): void
    {
        $interaction = $this->explorerInteraction();

        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'], $this->author)->assertOk();
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'improve'], $this->coAuthor)->assertOk();
        $this->assertSame(2, AiInteractionFeedback::query()->count());

        // La contrainte est dans le schema, pas seulement dans l'upsert.
        $this->expectException(QueryException::class);
        DB::table('ai_interaction_feedbacks')->insert([
            'id' => (string) Str::uuid(),
            'ai_interaction_id' => $interaction->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->author->id,
            'verdict' => 'helpful',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_verdict_is_closed_to_two_values_and_the_id_must_be_a_uuid(): void
    {
        $interaction = $this->explorerInteraction();

        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'approved'])
            ->assertStatus(422)->assertJsonValidationErrors(['verdict']);
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'exportable'])
            ->assertStatus(422)->assertJsonValidationErrors(['verdict']);
        $this->feedback(['ai_interaction_id' => $interaction->id])
            ->assertStatus(422)->assertJsonValidationErrors(['verdict']);
        $this->feedback(['ai_interaction_id' => 'not-a-uuid', 'verdict' => 'helpful'])
            ->assertStatus(422)->assertJsonValidationErrors(['ai_interaction_id']);
        $this->feedback(['verdict' => 'helpful'])
            ->assertStatus(422)->assertJsonValidationErrors(['ai_interaction_id']);

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    // =====================================================================
    // D. Controle d'acces STRICT
    // =====================================================================

    public function test_author_co_author_and_platform_admin_of_the_tenant_can_give_feedback_a_plain_member_cannot(): void
    {
        $interaction = $this->explorerInteraction();

        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'], $this->author)->assertOk();
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'], $this->coAuthor)->assertOk();
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'improve'], $this->admin)->assertOk();

        // Membre du tenant sans droit sur la surface Explorer de l'article :
        // la MEME regle que le dialogue (`canAccessPostExplorer`).
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'], $this->memberWithoutRights)->assertForbidden();

        $this->assertSame(3, AiInteractionFeedback::query()->count());
        $this->assertSame(0, AiInteractionFeedback::query()->where('user_id', $this->memberWithoutRights->id)->count());
    }

    public function test_a_guest_is_refused_and_nothing_is_written(): void
    {
        // Pas d'`actingAs` ici (il persiste pour toute la duree du test) :
        // la trace est ecrite directement.
        $interaction = AiInteraction::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_explorer',
            'model' => 'openai/gpt-catalogued',
            'prompt' => 'Par ou commencer ?',
            'response' => 'Un angle.',
            'metadata' => ['blog_post_id' => $this->post->id, 'method_code' => null],
        ]);

        $this->postJson(route('blog.explorer.feedback.store', $this->post), [
            'ai_interaction_id' => $interaction->id,
            'verdict' => 'helpful',
        ])->assertUnauthorized();

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    public function test_an_interaction_of_another_tenant_is_not_found_from_here_nothing_written_nothing_revealed(): void
    {
        // Reponse Explorer REELLE dans l'AUTRE Organization, par son auteur.
        $foreign = $this->explorerInteraction($this->outsider, $this->foreignPost);
        $this->assertSame($this->otherOrganization->id, $foreign->organization_id);
        $this->assertStringContainsString('Quelle donnee', (string) $foreign->response);

        // Depuis MON tenant, sur MON article, en referencant SON id : 404
        // propre (message traduit), pas 403, pas 422 — l'interaction n'existe
        // pas pour moi. Aucun contenu etranger dans la reponse.
        $response = $this->feedback(['ai_interaction_id' => $foreign->id, 'verdict' => 'helpful'], $this->author);
        $response->assertNotFound()->assertJsonPath('message', __('blog.explorer_feedback_not_found'));
        $response->assertDontSee('Quelle donnee');
        $response->assertDontSee($this->foreignPost->title);
        $response->assertDontSee($this->otherOrganization->id);

        // Un admin plateforme de MON tenant non plus.
        $this->feedback(['ai_interaction_id' => $foreign->id, 'verdict' => 'helpful'], $this->admin)->assertNotFound();

        // Et depuis l'alias Organization de l'AUTRE tenant avec MON compte :
        // l'article etranger n'est pas accessible (403, comme le dialogue).
        $this->actingAs($this->author)->postJson(route('organization.blog.explorer.feedback.store', [
            'organization' => $this->otherOrganization->slug,
            'post' => $this->foreignPost,
        ]), ['ai_interaction_id' => $foreign->id, 'verdict' => 'helpful'])->assertForbidden();

        $this->assertSame(0, AiInteractionFeedback::query()->count());

        // L'ayant droit reel, lui, peut (sanity : la regle n'est pas « personne »).
        app()->instance('current_organization', $this->otherOrganization);
        $this->actingAs($this->outsider)->postJson(route('organization.blog.explorer.feedback.store', [
            'organization' => $this->otherOrganization->slug,
            'post' => $this->foreignPost,
        ]), ['ai_interaction_id' => $foreign->id, 'verdict' => 'helpful'])->assertOk();
        $this->assertSame(1, AiInteractionFeedback::query()->count());
        $this->assertSame($this->otherOrganization->id, AiInteractionFeedback::query()->firstOrFail()->organization_id);
    }

    public function test_an_interaction_of_another_article_or_of_another_surface_is_not_found_even_inside_the_tenant(): void
    {
        // Meme tenant, meme auteur, AUTRE article.
        $otherArticle = $this->explorerInteraction(null, $this->otherPost);
        $this->feedback(['ai_interaction_id' => $otherArticle->id, 'verdict' => 'helpful'])->assertNotFound();

        // Meme tenant, meme acteur, autre surface (pas une reponse du
        // dialogue Explorer) : hors perimetre de cette V1.
        $loopSummary = AiInteraction::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'feature' => 'loop_summary',
            'model' => 'openai/gpt-catalogued',
            'prompt' => 'contexte',
            'response' => 'resume',
            'metadata' => ['blog_post_id' => $this->post->id],
        ]);
        $this->feedback(['ai_interaction_id' => $loopSummary->id, 'verdict' => 'helpful'])->assertNotFound();

        // Une note Explorer n'est pas un tour de dialogue non plus.
        $note = AiInteraction::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'feature' => 'blog_explorer_note',
            'model' => 'openai/gpt-catalogued',
            'prompt' => 'note',
            'response' => '<p>note</p>',
            'metadata' => ['blog_post_id' => $this->post->id],
        ]);
        $this->feedback(['ai_interaction_id' => $note->id, 'verdict' => 'helpful'])->assertNotFound();

        // Id inconnu : 404 aussi, meme forme.
        $this->feedback(['ai_interaction_id' => (string) Str::uuid(), 'verdict' => 'helpful'])
            ->assertNotFound()->assertJsonPath('message', __('blog.explorer_feedback_not_found'));

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    public function test_the_tenant_context_must_match_the_article_before_anything_else(): void
    {
        $interaction = $this->explorerInteraction();

        // Contexte tenant = l'autre Organization, article de la premiere :
        // 404 avant toute lecture d'interaction (meme regle que `chat()`).
        app()->instance('current_organization', $this->otherOrganization);
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'], $this->outsider)->assertNotFound();
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'], $this->author)->assertNotFound();

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    public function test_the_organization_route_alias_stores_the_feedback_by_delegation(): void
    {
        $interaction = $this->explorerInteraction(null, null, 'invent');

        $this->actingAs($this->author)->postJson(route('organization.blog.explorer.feedback.store', [
            'organization' => $this->organization->slug,
            'post' => $this->post,
        ]), ['ai_interaction_id' => $interaction->id, 'verdict' => 'improve', 'comment' => 'Via l\'alias.'])
            ->assertOk()
            ->assertJsonPath('verdict', 'improve');

        $row = AiInteractionFeedback::query()->firstOrFail();
        $this->assertSame('Via l\'alias.', $row->comment);
        $this->assertSame('invent', $row->interaction->metadata['method_code']);
    }

    // =====================================================================
    // E. Retention : le feedback suit l'interaction, l'acteur, le tenant
    // =====================================================================

    public function test_deleting_the_interaction_deletes_the_feedback_never_the_reverse(): void
    {
        $interaction = $this->explorerInteraction();
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful', 'comment' => 'ok'])->assertOk();
        $this->assertSame(1, AiInteractionFeedback::query()->count());

        // Avant : 1 feedback. Suppression de la trace (DELETE SQL brut, comme
        // le ferait la politique de retention) -> apres : 0.
        DB::table('ai_interactions')->where('id', $interaction->id)->delete();
        $this->assertSame(0, AiInteractionFeedback::query()->count());

        // L'inverse : supprimer un feedback laisse la trace intacte.
        $again = $this->explorerInteraction();
        $this->feedback(['ai_interaction_id' => $again->id, 'verdict' => 'improve'])->assertOk();
        AiInteractionFeedback::query()->delete();
        $this->assertDatabaseHas('ai_interactions', ['id' => $again->id]);
    }

    public function test_deleting_the_actor_deletes_the_feedback_and_its_interaction(): void
    {
        $interaction = $this->explorerInteraction($this->coAuthor);
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'], $this->coAuthor)->assertOk();
        // L'auteur aussi juge la meme reponse : son feedback, lui, doit survivre.
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'improve'], $this->author)->assertOk();
        $this->assertSame(2, AiInteractionFeedback::query()->count());

        // Suppression du co-auteur (acteur du feedback ET de l'interaction) :
        // la trace suit la personne (G13), et le feedback suit la trace.
        DB::table('users')->where('id', $this->coAuthor->id)->delete();
        $this->assertDatabaseMissing('ai_interactions', ['id' => $interaction->id]);
        $this->assertSame(0, AiInteractionFeedback::query()->count(), 'Plus d\'interaction => plus aucun feedback dessus, quel qu\'en soit l\'auteur.');

        // Suppression de l'ACTEUR DU FEEDBACK seulement (interaction d'un autre).
        $byAuthor = $this->explorerInteraction($this->author);
        $this->feedback(['ai_interaction_id' => $byAuthor->id, 'verdict' => 'helpful'], $this->admin)->assertOk();
        $this->assertSame(1, AiInteractionFeedback::query()->where('user_id', $this->admin->id)->count());
        DB::table('users')->where('id', $this->admin->id)->delete();
        $this->assertSame(0, AiInteractionFeedback::query()->where('user_id', $this->admin->id)->count());
        // L'interaction de l'auteur survit au depart de l'admin qui l'a jugee.
        $this->assertDatabaseHas('ai_interactions', ['id' => $byAuthor->id]);
    }

    public function test_force_deleting_the_organization_deletes_the_feedback(): void
    {
        $interaction = $this->explorerInteraction();
        $this->feedback(['ai_interaction_id' => $interaction->id, 'verdict' => 'helpful'])->assertOk();
        $this->assertSame(1, AiInteractionFeedback::query()->where('organization_id', $this->organization->id)->count());

        // Le tenant disparait : la FK `organization_id` du feedback cascade
        // (celle de `ai_interactions` met a NULL — R4 de l'audit, pre-existant
        // — mais le feedback, lui, ne survit pas a son tenant).
        DB::table('organizations')->where('id', $this->organization->id)->delete();

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    // =====================================================================
    // F. Schema FERME, registre = verite
    // =====================================================================

    public function test_the_feedback_schema_is_closed_with_no_export_training_or_consent_column_and_no_copy_of_prompt_or_response(): void
    {
        $columns = collect(Schema::getColumnListing('ai_interaction_feedbacks'))->sort()->values()->all();

        // Liste FERMEE : ajouter une colonne ici exige de revenir consciemment.
        $this->assertSame([
            'ai_interaction_id', 'comment', 'created_at', 'id', 'organization_id',
            'suggested_response', 'updated_at', 'user_id', 'verdict',
        ], $columns);

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/export|train|consent|eligib|dataset|approved|label|score|rating/i',
                $column,
                "« Utile » n'est pas un consentement : aucune colonne d'export / entrainement / consentement [{$column}]."
            );
        }
        $this->assertNotContains('prompt', $columns, 'Aucune copie du prompt : il vit dans ai_interactions (FK).');
        $this->assertNotContains('response', $columns, 'Aucune copie de la reponse IA : elle vit dans ai_interactions (FK).');
        $this->assertContains('suggested_response', $columns, 'La meilleure reponse de l\'HUMAIN, elle, a sa place.');

        // Le fillable du modele est exactement le schema (hors id/timestamps) :
        // aucune porte d'entree pour un champ libre.
        $this->assertSame(
            collect($columns)->reject(fn ($c) => in_array($c, ['id', 'created_at', 'updated_at'], true))->values()->all(),
            collect((new AiInteractionFeedback)->getFillable())->sort()->values()->all(),
        );
        $this->assertSame(['helpful', 'improve'], AiInteractionFeedback::VERDICTS);
    }

    public function test_the_lifecycle_registry_declares_the_feedback_deleted_because_the_schema_really_cascades(): void
    {
        $entry = collect(UserDataLifecycleRegistry::entries())->firstWhere('key', 'ai_interaction_feedbacks');

        $this->assertNotNull($entry, 'Toute FK vers users doit etre declaree au registre.');
        $this->assertSame(UserDataLifecycleRegistry::POLICY_DELETE, $entry['policy']);
        $this->assertSame('sql', $entry['type']);
        $this->assertSame('ai_interaction_feedbacks', $entry['table']);
        $this->assertSame('user_id', $entry['column']);
        $this->assertSame('direct', $entry['org_scope']);
        $this->assertStringContainsString('CASCADE', $entry['justification']);
        $this->assertStringContainsString('consent', $entry['justification']);

        foreach (['fr', 'en'] as $locale) {
            $this->assertNotSame('admin.user_data_ai_interaction_feedbacks', __('admin.user_data_ai_interaction_feedbacks', [], $locale), $locale);
        }

        // Les libelles de l'UI existent dans les deux locales.
        foreach ([
            'explorer_feedback_question', 'explorer_feedback_helpful', 'explorer_feedback_improve',
            'explorer_feedback_saved', 'explorer_feedback_comment_label', 'explorer_feedback_suggest_label',
            'explorer_feedback_send', 'explorer_feedback_error', 'explorer_feedback_not_found',
        ] as $key) {
            foreach (['fr', 'en'] as $locale) {
                $this->assertNotSame('blog.'.$key, __('blog.'.$key, [], $locale), "{$locale}: {$key}");
            }
        }
    }
}
