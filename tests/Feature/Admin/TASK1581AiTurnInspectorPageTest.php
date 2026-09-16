<?php

namespace Tests\Feature\Admin;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1581 — Inspector UI V0 (CDC-02 T1-E) : la vue lecteur d'un tour.
 *
 * Read-only, admin plateforme, memes lecteurs que la CLI. Rien n'est rejoue :
 * les agents sont fakes et `assertNeverPrompted` le prouve sur chaque page.
 */
#[Group('ai')]
class TASK1581AiTurnInspectorPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $admin;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1581', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1581',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle inspectee');
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id, 'owner_id' => $this->membre->id,
            'name' => 'Dossier inspecte', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $this->dossier->id, 'dossier_name' => $this->dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 0, 'content' => 'TEXTE DU CHUNK QUI NE DOIT PAS SORTIR', 'distance' => 0.2,
        ]])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse directe.'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. acces

    public function test_a1_seul_un_admin_plateforme_lit_l_inspector(): void
    {
        $interaction = $this->tourRag();

        $this->assertContains($this->get(route('admin.ai-turns'))->getStatusCode(), [302, 401, 403], 'un visiteur anonyme ne lit rien');
        $this->actingAs($this->membre)->get(route('admin.ai-turns'))->assertForbidden();
        $this->actingAs($this->membre)->get(route('admin.ai-turns.show', $interaction))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.ai-turns'))->assertOk();
    }

    public function test_a2_un_id_inconnu_ou_non_uuid_rend_404_jamais_une_exception(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('admin.ai-turns.show', ['interaction' => (string) Str::uuid()]))->assertNotFound();
        $this->get(route('admin.ai-turns.show', ['interaction' => 'pas-un-uuid']))->assertNotFound();
        $this->get(route('admin.ai-turns.shell', ['shellMessage' => 'pas-un-uuid']))->assertNotFound();
        $this->get(route('admin.ai-turns', ['interaction' => 'pas-un-uuid']))->assertRedirect(route('admin.ai-turns'))->assertSessionHas('inspector_error');
        $this->get(route('admin.ai-turns', ['interaction' => (string) Str::uuid()]))->assertRedirect(route('admin.ai-turns'))->assertSessionHas('inspector_error');
    }

    // ────────────────────────────── B. la page d'un tour

    public function test_b1_un_tour_rag_se_lit_avec_identite_etapes_sources_projection_et_labels(): void
    {
        $interaction = $this->tourRag();
        $interactionsAvant = AiInteraction::query()->count();
        $ledgerAvant = \App\Models\AiProviderInvocation::query()->count();

        $reponse = $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction));

        $reponse->assertOk()
            ->assertSee($interaction->metadata['turn']['id'])
            ->assertSee('org-1581')
            ->assertSee(AiExecutionPath::LOOP_CHAT_DOSSIERS)
            ->assertSee('answered')
            ->assertSee('economic_check')->assertSee('retrieval')->assertSee('provider_call')->assertSee('grounding')
            ->assertSee('MEASURED')->assertSee('DECLARED')->assertSee('DERIVED')
            ->assertSee('Que dit le document ?', false)
            ->assertSee('[S1]', false)
            ->assertSee('Le document dit ceci')
            ->assertSee('data-inspector-projection', false)
            ->assertSee('data-inspector-conversation', false);

        // I9 : jamais le prompt brut. L'extrait rendu est celui de la BULLE
        // (ce que le membre a vu, `publicSource.excerpt`), pas une lecture de
        // `dossier_chunks.content` — la projection ne selectionne jamais cette
        // colonne (T1580).
        $this->assertStringNotContainsString('Tu es', $reponse->getContent(), 'aucun fragment de prompt systeme');
        $this->assertStringNotContainsString(Str::limit((string) $interaction->prompt, 80, ''), $reponse->getContent());
        $reponse->assertSee('« ', false);

        // Zero execution : ni interaction ni ligne de ledger nouvelles.
        $this->assertSame($interactionsAvant, AiInteraction::query()->count());
        $this->assertSame($ledgerAvant, \App\Models\AiProviderInvocation::query()->count());
    }

    public function test_b2_un_tour_cli_sans_bulle_dit_unavailable_et_n_a_pas_de_conversation(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        $interaction = $this->tour(fn () => app(\App\Services\Ai\LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS));

        $reponse = $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction));

        $reponse->assertOk()->assertSee('no_loop_bubble')->assertDontSee('data-inspector-conversation', false);
        $reponse->assertDontSee('Que dit le document ?', false);
    }

    public function test_b3_un_tour_shell_zero_provider_se_lit_depuis_sa_ligne_avec_ses_declins(): void
    {
        $ligne = $this->shell("C'est quoi BouclePro ?");
        $this->assertSame(0, AiInteraction::query()->count());

        $reponse = $this->actingAs($this->admin)->get(route('admin.ai-turns.shell', $ligne));

        $reponse->assertOk()
            ->assertSee(AiExecutionPath::AI_SHELL_SELF_KNOWLEDGE)
            ->assertSee('ai_shell_messages')
            ->assertSee('data-inspector-shell', false)
            ->assertSee('Aucune branche n\'a décliné', false)
            ->assertSee('aucune interaction à projeter', false);

        // Et le lookup par id l'oriente vers la bonne route.
        $this->get(route('admin.ai-turns', ['interaction' => (string) $ligne->id]))->assertRedirect(route('admin.ai-turns.shell', $ligne));
    }

    public function test_b4_l_index_liste_les_derniers_tours_traces_et_le_lookup_ouvre_le_tour(): void
    {
        $interaction = $this->tourRag();

        $this->actingAs($this->admin)->get(route('admin.ai-turns'))
            ->assertOk()
            ->assertSee('data-inspector-recents', false)
            ->assertSee(AiExecutionPath::LOOP_CHAT_DOSSIERS)
            ->assertSee(route('admin.ai-turns.show', $interaction), false);

        $this->get(route('admin.ai-turns', ['interaction' => (string) $interaction->id]))->assertRedirect(route('admin.ai-turns.show', $interaction));
    }

    public function test_b5_la_conversation_relie_les_tours_et_le_tenant_reste_celui_du_tour(): void
    {
        $u1 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Bonjour ?', 'type' => 'user']);
        $b1 = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Bonjour ?', $u1);
        $u2 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Et ensuite ?', 'type' => 'user', 'reply_to_id' => $b1->id]);
        AiTurnLock::forgetRequestState();
        $b2 = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Et ensuite ?', $u2);

        // Un admin d'une AUTRE Organization lit le meme tour : console plateforme,
        // le tenant affiche est celui du TOUR, pas celui de l'admin.
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1581']);
        $adminAilleurs = User::factory()->create(['organization_id' => $ailleurs->id, 'is_admin' => true]);

        $reponse = $this->actingAs($adminAilleurs)->get(route('admin.ai-turns.show', ['interaction' => $b2->metadata['ai_interaction_id']]));

        $reponse->assertOk()
            ->assertSee('org-1581')
            ->assertSee('data-inspector-conversation', false)
            ->assertSee('reply_chain')
            ->assertSee('YES');
    }

    // ────────────────────────────── fixtures

    private function tourRag(): AiInteraction
    {
        return $this->tour(function (): void {
            $this->actingAs($this->membre);
            Livewire::test(LoopChat::class, ['loop' => $this->loop])->call('setComposerMode', 'dossiers')->set('body', 'Que dit le document ?')->call('sendMessage')->assertHasNoErrors();
        });
    }

    private function tour(callable $jouer): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        $jouer();

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    private function shell(string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve($this->membre, $this->organization, AiShellPageContext::KIND_DASHBOARD, null, 'organization.dashboard');
        $this->actingAs($this->membre);
        AiTurnLock::forgetRequestState();

        return app(AiShellResponder::class)->respond($this->organization, $this->membre, $question, $context)['answer'];
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }
}
