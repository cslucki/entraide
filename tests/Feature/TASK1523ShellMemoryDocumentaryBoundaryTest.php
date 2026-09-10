<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TASK-1523 — La memoire du Shell ne fait jamais source pour un autre Dossier.
 *
 * ## Le defaut, mesure sur deux Dossiers reels d'un meme membre
 *
 * Le fil du Shell est (organization, user) : il suit la personne de page en
 * page, et c'est voulu. Mais un fait repondu sur le Dossier A (« une boucle
 * principale par organisation », present dans 3 chunks de A, dans AUCUN des
 * 30 chunks de B) etait restitue sur le Dossier B AVEC des citations [S1][S2]
 * dont les sources etaient B. Le prompt le montrait : 0 occurrence dans les
 * SOURCES, 2 dans le bloc CONVERSATION — malgre l'etiquette « contexte, jamais
 * une source : ne la cite pas ». L'instruction ne tient pas.
 *
 * ## Le correctif, structurel
 *
 * Chaque message porte deja `metadata.page_context.object_id`, ecrit par le
 * serveur a chaque tour. La memoire DOCUMENTAIRE (tours Dossier et Article) ne
 * retient que les tours du MEME objet ; la memoire globale de `generate()` est
 * inchangee. Aucun second store, aucune migration. Mesure apres, sur un fil de
 * 40 tours PLAN et 0 tour B : « Les sources ne precisent pas ... », bloc
 * conversation absent, 0 occurrence du fait dans le prompt.
 *
 * Ces tests mesurent le PROMPT envoye au fournisseur, jamais la reponse.
 */
class TASK1523ShellMemoryDocumentaryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Dossier $a;

    private Dossier $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);

        $this->a = Dossier::create(['organization_id' => $this->organization->id, 'owner_id' => $this->member->id, 'name' => 'Dossier A', 'visibility' => 'private']);
        $this->b = Dossier::create(['organization_id' => $this->organization->id, 'owner_id' => $this->member->id, 'name' => 'Dossier B', 'visibility' => 'private']);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1523',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();

        // La recherche rend une source du Dossier COURANT, quel qu'il soit :
        // ce qui est mesure est la memoire, pas le retrieval.
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturnUsing(function (string $orgId, array $dossierIds): array {
            $dossier = Dossier::findOrFail($dossierIds[0]);

            return [[
                'chunk_id' => (string) Str::uuid(), 'dossier_id' => $dossier->id, 'dossier_name' => $dossier->name,
                'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
                'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'chunk_index' => 1, 'content' => 'Contenu du '.$dossier->name.'.', 'distance' => 0.2,
            ]];
        })->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse('Une reponse. [S1]', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')));

        $structured = [
            'title' => 'Titre', 'clarified_request' => 'Demande clarifiee.', 'help_type' => 'information',
            'suggested_loop_id' => '', 'suggested_category_id' => '', 'suggestion_reason' => '',
            'questions_for_user' => [], 'confidence' => 0.9, 'needs_human_review' => false,
        ];
        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured, json_encode($structured, JSON_UNESCAPED_UNICODE), new Usage(120, 80), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    /**
     * Le contexte est construit par le MEME resolveur que le chemin Livewire,
     * donc avec les MEMES gardes — et chaque message stocke porte la page que
     * le serveur ecrit lui-meme.
     */
    private function sendOn(Dossier $dossier, string $question): void
    {
        $context = app(AiShellPageContext::class)->resolve($this->member, $this->organization, AiShellPageContext::KIND_DOSSIER, $dossier->id);

        $this->actingAs($this->member);
        app(AiShellResponder::class)->respond($this->organization, $this->member, $question, $context);
    }

    private function lastDocumentaryPrompt(): string
    {
        $interaction = AiInteraction::query()->where('feature', 'loop_knowledge_answer')
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->assertNotNull($interaction, 'aucun appel documentaire trace');

        return (string) $interaction->prompt;
    }

    private function globalMemory(): string
    {
        $method = new ReflectionMethod(AiShellResponder::class, 'conversationMemory');

        return (string) $method->invoke(app(AiShellResponder::class), $this->organization, $this->member);
    }

    // ── La garde ────────────────────────────────────────────────────────────

    /**
     * LE test de cette TASK. Un tour tenu sur A n'entre pas dans le prompt
     * documentaire d'un tour sur B.
     *
     * Sabotage : ne plus filtrer sur l'objet de page → rouge.
     */
    public function test_a_turn_on_dossier_a_never_enters_the_documentary_prompt_of_dossier_b(): void
    {
        $this->sendOn($this->a, 'Combien de boucles principales dans A ?');
        $this->sendOn($this->b, 'Et dans ce Dossier-ci ?');

        $prompt = $this->lastDocumentaryPrompt();

        $this->assertStringNotContainsString('Combien de boucles principales dans A', $prompt,
            'la question posee sur A ne doit pas devenir du contexte sur B');
        $this->assertStringNotContainsString('CONVERSATION EN COURS', $prompt,
            'aucun tour de B avant celui-ci : le bloc conversation doit etre absent, pas rempli avec A');
    }

    /**
     * Non-regression T1519 : sur le MEME Dossier, le fil entre toujours dans
     * le prompt — c'est ce qui rend « Et les participants ? » comprehensible.
     */
    public function test_a_follow_up_on_the_same_dossier_still_receives_the_thread(): void
    {
        $this->sendOn($this->a, 'Combien de boucles principales dans A ?');
        $this->sendOn($this->a, 'Et les participants ?');

        $prompt = $this->lastDocumentaryPrompt();

        $this->assertStringContainsString('CONVERSATION EN COURS', $prompt);
        $this->assertStringContainsString('Membre : Combien de boucles principales dans A', $prompt,
            'le tour precedent, tenu sur le meme Dossier, doit y figurer');
    }

    /**
     * Le fil GLOBAL, lui, suit la personne de page en page : c'est la promesse
     * produit, et `generate()` la garde. Seule la memoire DOCUMENTAIRE est
     * bornee.
     */
    public function test_the_global_thread_still_follows_the_person_across_dossiers(): void
    {
        $this->sendOn($this->a, 'Question sur A.');
        $this->sendOn($this->b, 'Question sur B.');

        $memory = $this->globalMemory();

        $this->assertStringContainsString('Membre : Question sur A.', $memory);
        $this->assertStringContainsString('Membre : Question sur B.', $memory);
    }

    /**
     * Revenir sur A apres un detour par B : les tours de A reviennent, ceux
     * de B restent dehors. La frontiere est par objet, pas par ordre.
     */
    public function test_returning_to_dossier_a_recovers_a_and_still_excludes_b(): void
    {
        $this->sendOn($this->a, 'Premiere question sur A.');
        $this->sendOn($this->b, 'Question sur B.');
        $this->sendOn($this->a, 'Retour sur A.');

        $prompt = $this->lastDocumentaryPrompt();

        $this->assertStringContainsString('Membre : Premiere question sur A.', $prompt);
        $this->assertStringNotContainsString('Question sur B.', $prompt);
    }
}
