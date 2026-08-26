<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1309 — sous-objectif B : le quatrieme mode « IA + Dossiers ».
 *
 * ChatLoop possede desormais quatre etats conceptuels — NORMAL, IA, DOSSIERS,
 * IA + DOSSIERS — et le principe de TASK-1308 tient toujours : la CONVERSATION
 * n'est pas le MOTEUR DU PROCHAIN TOUR. Chaque message rechoisit son moteur,
 * quel que soit celui du parent.
 *
 * Invariants prouves ici :
 *  - le quatrieme etat s'atteint en combinant les DEUX actions existantes,
 *    et se defait de la meme facon ;
 *  - un tour hybride = UN appel de generation, jamais « IA puis Dossiers » ;
 *  - avec des sources : citations [Mn]/[Sn] validees, comme le mode Dossiers ;
 *  - sans aucune source : le mode repond quand meme, et ne fabrique AUCUNE
 *    citation — la connaissance generale n'est jamais habillee en source ;
 *  - « Sources utilisees » = sources reellement citees, dans les deux modes ;
 *  - identite de bulle `{Organization.name} · IA + Dossiers` — jamais le slug,
 *    jamais « BouclePro » ;
 *  - les quatre transitions de mode, et un fil de quatre tours ;
 *  - NORMAL reste un message humain : zero provider, zero embedding ;
 *  - panne provider : le message humain est conserve, rien n'est publie ;
 *  - tenant et Boucle : la sentinelle SECRET-T1309-OTHER-ORG ne franchit
 *    jamais la frontiere, meme en mode hybride ;
 *  - permissions : un non-membre n'obtient ni reponse ni source, meme en
 *    forgeant l'appel.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1309HybridIaDossiersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $stranger;

    private Loop $loop;

    private Dossier $dossier;

    private FakeHybridModeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'LaunchPals', 'slug' => 'launchpals']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Autre Org', 'slug' => 'autre-org']);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->owner, 'Boucle hybride');
        $loopService->addMember($this->loop, $this->member, 'member');

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
            'ai.chatloop.enabled' => true,
            'ai.chatloop.min_summary_words' => 0,
        ]);

        $this->search = new FakeHybridModeSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. Le quatrieme etat du composeur, atteint par les DEUX actions.
    // =====================================================================

    public function test_the_two_existing_actions_combine_into_the_four_states(): void
    {
        $this->actingAs($this->member);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSet('composerMode', 'normal');

        $component->call('toggleComposerEngine', 'ia')->assertSet('composerMode', 'ia');
        $component->call('toggleComposerEngine', 'dossiers')->assertSet('composerMode', 'ia_dossiers');

        // Eteindre l'un laisse l'autre allume — c'est ce qui rend les quatre
        // etats reellement atteignables, dans les deux sens.
        $component->call('toggleComposerEngine', 'ia')->assertSet('composerMode', 'dossiers');
        $component->call('toggleComposerEngine', 'dossiers')->assertSet('composerMode', 'normal');

        // Dans l'autre ordre : Dossiers d'abord.
        $component->call('toggleComposerEngine', 'dossiers')->assertSet('composerMode', 'dossiers');
        $component->call('toggleComposerEngine', 'ia')->assertSet('composerMode', 'ia_dossiers');

        // Une valeur inconnue ne casse rien et ne change rien.
        $component->call('toggleComposerEngine', 'rag')->assertSet('composerMode', 'ia_dossiers');
        $component->call('setComposerMode', 'vector')->assertSet('composerMode', 'ia_dossiers');
    }

    /**
     * Section 9 du brief : l'utilisateur ne doit JAMAIS lire le vocabulaire
     * d'implementation.
     */
    public function test_the_interface_never_shows_implementation_vocabulary(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->assertSee(__('loops.hybrid_mode_label'))
            ->assertDontSee('RAG')
            ->assertDontSee('Hybrid')
            ->assertDontSee('llm_rag')
            ->assertDontSee('Retrieval');
    }

    // =====================================================================
    // B. Le tour hybride avec des sources documentaires.
    // =====================================================================

    public function test_hybrid_mode_answers_with_validated_citations_and_organization_identity(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('D\'après vos Dossiers, la Boucle distingue conversation et decision [S1]. En complément, ce type d\'espace se rapproche de ce qu\'on appelle ailleurs un canal projet.');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Que disent nos documents, et que sais-tu par ailleurs ?')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('LaunchPals · '.__('loops.hybrid_mode_label'))
            ->assertDontSee('launchpals · '.__('loops.hybrid_mode_label'))
            ->assertDontSee('BouclePro')
            ->assertSee(__('loops.hybrid_bubble_subtitle'))
            ->assertSee(__('loops.knowledge_sources_title'));

        $question = LoopMessage::query()->where('type', 'user')->sole();
        $this->assertSame('ia_dossiers', $question->metadata['requested_mode'] ?? null);

        $answer = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame($question->id, $answer->reply_to_id);
        $this->assertSame('llm_rag', $answer->metadata['ai_mode']);
        $this->assertSame('ia_dossiers', $answer->metadata['action']);
        $this->assertTrue($answer->metadata['grounded']);
        $this->assertSame(['S1'], array_column($answer->metadata['sources'], 'ref'));

        // UN seul tour de generation : « IA + Dossiers » n'est pas deux
        // reponses ni deux depenses.
        $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertDatabaseCount('ai_provider_invocations', 1);
        $this->assertSame(1, $this->search->calls);
    }

    /**
     * La capability tracee est EXPLICITEMENT celle du mode ; l'acte
     * economique, lui, reste celui de la famille documentaire (aucune
     * economie parallele).
     */
    public function test_the_hybrid_capability_is_traced_while_the_economic_family_stays_the_same(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Réponse croisée [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Une question croisée.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $interaction = AiInteraction::sole();
        $this->assertSame(CapabilityRegistry::LOOP_HYBRID_ANSWER, $interaction->feature);
        $this->assertSame(CapabilityRegistry::LOOP_HYBRID_ANSWER, $interaction->metadata['capability']);
        $this->assertSame('loop_knowledge.answer', $interaction->process);

        $ledger = AiProviderInvocation::sole();
        $this->assertSame(CapabilityRegistry::LOOP_HYBRID_ANSWER, $ledger->capability);
        $this->assertSame('loop_knowledge.answer', $ledger->process);
    }

    // =====================================================================
    // C. Le tour hybride SANS aucune source documentaire.
    // =====================================================================

    /**
     * La difference de fond avec le mode Dossiers : sans provenance, celui-ci
     * refuse et ne publie rien ; le mode hybride repond depuis la
     * connaissance generale — et le prompt lui DIT que les Dossiers n'ont
     * rien fourni, pour qu'il puisse le dire a son tour.
     */
    public function test_hybrid_mode_answers_without_any_documentary_source_and_says_so(): void
    {
        $this->emptyTheLoopKnowledge();
        $this->fakeKnowledge('Les Dossiers accessibles de cette Boucle n\'apportent rien sur ce point. De maniere generale, une retrospective se tient apres chaque cycle.');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Comment organiser une rétrospective ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            // Le constat du silence documentaire est EXPLICITE dans le prompt.
            $this->assertStringContainsString('Aucun element des Dossiers accessibles', $prompt->prompt);
            $this->assertStringContainsString('Comment organiser une rétrospective ?', $prompt->prompt);

            return true;
        });

        $answer = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame('llm_rag', $answer->metadata['ai_mode']);
        $this->assertFalse($answer->metadata['grounded']);
        $this->assertSame([], $answer->metadata['sources'], 'une reponse de connaissance generale n\'a AUCUNE source documentaire');
    }

    /**
     * Le garde-fou de securite : meme si le modele fabrique une reference,
     * elle ne devient jamais une source — parce qu'elle ne correspond a
     * aucune provenance reellement fournie.
     */
    public function test_general_knowledge_never_becomes_a_documentary_citation(): void
    {
        $this->emptyTheLoopKnowledge();
        $this->fakeKnowledge('Une rétrospective se tient apres chaque cycle [S1], et vos documents le confirment [M4].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Comment organiser une rétrospective ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $answer = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame([], $answer->metadata['sources']);
        $this->assertFalse($answer->metadata['grounded']);

        // Et rien n'est affiche : une bulle sans source ne rend aucun bloc.
        $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop]))
            ->assertOk()
            ->assertDontSee('data-message-sources', false);
    }

    // =====================================================================
    // D. Les quatre transitions de mode dans un meme fil.
    // =====================================================================

    public function test_dossiers_to_hybrid_carries_the_thread_and_regrounds(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Nos documents parlent de Paris [S1].');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que disent nos documents sur Paris ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $ragAnswer = LoopMessage::query()->where('type', 'ai')->sole();

        $this->search->rows = [$this->row('B')];
        $this->fakeKnowledge('D\'après vos Dossiers, Paris [S1]. En complément, Marseille differe sur ce point.');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $ragAnswer->id)
            ->assertSet('composerMode', 'dossiers')
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'ia_dossiers')
            ->set('body', 'Compare avec Marseille.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            if (! str_contains($prompt->prompt, 'Compare avec Marseille.')) {
                return false;
            }

            $this->assertStringContainsString('Nos documents parlent de Paris', $prompt->prompt);

            return true;
        });

        $this->assertSame(2, $this->search->calls, 'chaque tour documentaire regronde reellement');
    }

    public function test_ia_to_hybrid_never_turns_the_previous_llm_claim_into_a_source(): void
    {
        $this->fakeDirect('Le dossier secret contient XYZ.');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Le dossier secret contient-il XYZ ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $llmAnswer = LoopMessage::query()->where('type', 'ai')->sole();

        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Vos Dossiers ne mentionnent pas XYZ [S1]. En complément, ce genre de document est rarement public.');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $llmAnswer->id)
            ->assertSet('composerMode', 'ia')
            ->call('toggleComposerEngine', 'dossiers')
            ->assertSet('composerMode', 'ia_dossiers')
            ->set('body', 'Confirme.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $hybrid = LoopMessage::query()->where('type', 'ai')->where('metadata->ai_mode', 'llm_rag')->sole();

        foreach ($hybrid->metadata['sources'] as $source) {
            $this->assertStringNotContainsString('XYZ', (string) ($source['title'] ?? '').(string) ($source['excerpt'] ?? ''));
        }
    }

    public function test_hybrid_to_ia_and_hybrid_to_dossiers_are_both_preselected_and_freely_changed(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Réponse croisée [S1].');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Une question croisée.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $hybrid = LoopMessage::query()->where('type', 'ai')->sole();

        // Repondre a une bulle hybride PRESELECTIONNE le mode hybride...
        $this->fakeDirect('Réponse purement générale.');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $hybrid->id)
            ->assertSet('composerMode', 'ia_dossiers')
            // ... mais ne le verrouille jamais : on repart en IA seule.
            ->call('toggleComposerEngine', 'dossiers')
            ->assertSet('composerMode', 'ia')
            ->set('body', 'Et de manière générale ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $llm = LoopMessage::query()->where('type', 'ai')->where('body', 'Réponse purement générale.')->sole();
        $this->assertSame('llm', $llm->metadata['ai_mode']);
        $this->assertArrayNotHasKey('sources', $llm->metadata);

        // ... ou en Dossiers seuls.
        $this->search->rows = [$this->row('B')];
        $this->fakeKnowledge('Strictement d\'après nos documents [S1].');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $hybrid->id)
            ->assertSet('composerMode', 'ia_dossiers')
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'dossiers')
            ->set('body', 'Nos documents le confirment-ils ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $rag = LoopMessage::query()->where('type', 'ai')->where('body', 'Strictement d\'après nos documents [S1].')->sole();
        $this->assertSame('rag', $rag->metadata['ai_mode']);
    }

    /**
     * Le test canonique du brief (section 18) : quatre tours, quatre moteurs,
     * un seul fil de reponse.
     */
    public function test_a_four_turn_thread_switches_engine_at_every_turn(): void
    {
        $this->actingAs($this->member);

        // Tour 1 — DOSSIERS
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Nos documents parlent de Paris [S1].');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que disent nos documents sur Paris ?')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $turn1 = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame('rag', $turn1->metadata['ai_mode']);

        // Tour 2 — IA
        $this->fakeDirect('Marseille est la deuxième ville de France.');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $turn1->id)
            ->call('setComposerMode', 'ia')
            ->set('body', 'Compare cela avec Marseille.')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $turn2 = LoopMessage::query()->where('type', 'ai')->where('body', 'Marseille est la deuxième ville de France.')->sole();
        $this->assertSame('llm', $turn2->metadata['ai_mode']);

        // Tour 3 — IA + DOSSIERS
        $this->search->rows = [$this->row('B')];
        $this->fakeKnowledge('D\'après vos Dossiers, Paris [S1]. En complément, l\'IA rapproche cela de Marseille.');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $turn2->id)
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Fais une synthèse en distinguant nos documents de ta connaissance générale.')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $turn3 = LoopMessage::query()->where('type', 'ai')->where('metadata->ai_mode', 'llm_rag')->sole();

        // Tour 4 — DOSSIERS
        $this->search->rows = [$this->row('C')];
        $this->fakeKnowledge('Nos documents ne confirment pas cette différence [S1].');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $turn3->id)
            ->assertSet('composerMode', 'ia_dossiers')
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'dossiers')
            ->set('body', 'Nos documents confirment-ils réellement cette différence ?')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $turn4 = LoopMessage::query()->where('type', 'ai')->where('body', 'Nos documents ne confirment pas cette différence [S1].')->sole();
        $this->assertSame('rag', $turn4->metadata['ai_mode']);

        // Un seul fil : chaque reponse repond au message humain qui la
        // declenche, lui-meme rattache au tour precedent.
        $this->assertSame(
            $turn3->id,
            LoopMessage::query()->whereKey($turn4->reply_to_id)->value('reply_to_id'),
            'les quatre tours appartiennent au meme fil de reponse',
        );

        // Quatre tours = quatre generations, et seulement trois recherches
        // documentaires (le tour IA n'en declenche aucune).
        $this->assertDatabaseCount('ai_provider_invocations', 4);
        $this->assertSame(3, $this->search->calls);
    }

    // =====================================================================
    // E. NORMAL reste NORMAL, meme en repondant a une bulle hybride.
    // =====================================================================

    public function test_a_normal_reply_to_a_hybrid_answer_costs_nothing(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Réponse croisée [S1].');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Une question croisée.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $hybrid = LoopMessage::query()->where('type', 'ai')->sole();
        $invocationsBefore = AiProviderInvocation::count();
        $searchCallsBefore = $this->search->calls;

        $this->fakeDirect('ne doit jamais etre appele');
        $this->fakeKnowledge('ne doit jamais etre appele');

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $hybrid->id)
            ->assertSet('composerMode', 'ia_dossiers')
            ->call('setComposerMode', 'normal')
            ->set('body', 'Merci, je regarde ça.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $human = LoopMessage::query()->where('body', 'Merci, je regarde ça.')->sole();
        $this->assertSame('user', $human->type);
        $this->assertSame($hybrid->id, $human->reply_to_id);
        $this->assertSame($invocationsBefore, AiProviderInvocation::count(), 'un message normal ne coute rien');
        $this->assertSame($searchCallsBefore, $this->search->calls, 'un message normal n\'embarque aucun embedding');
        LoopDirectAnswerAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    // =====================================================================
    // F. Panne provider : le message humain survit, rien n'est publie.
    // =====================================================================

    public function test_hybrid_provider_failure_preserves_the_human_message_and_publishes_nothing(): void
    {
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake(function (): never {
            throw new RuntimeException('provider down');
        });

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Question croisée qui échoue.')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $message = LoopMessage::query()->sole();
        $this->assertSame('user', $message->type);
        $this->assertSame('Question croisée qui échoue.', $message->body);
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());

        $interaction = AiInteraction::sole();
        $this->assertSame('failed', $interaction->metadata['status']);
        $this->assertSame(CapabilityRegistry::LOOP_HYBRID_ANSWER, $interaction->feature);
        // Une seule ligne de ledger : pas de double depense sur un echec.
        $this->assertSame(1, AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_GENERATION)->count());
    }

    // =====================================================================
    // G. Tenant, Boucle, permissions.
    // =====================================================================

    public function test_the_hybrid_mode_never_reaches_another_organization(): void
    {
        // La sentinelle vit dans une autre Organization : le moteur ne la
        // cherche meme pas — le perimetre est celui de la Boucle courante.
        $foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => $this->stranger->id,
            'name' => 'SECRET-T1309-OTHER-ORG',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);

        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Réponse croisée [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Que sait-on ici ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertNotNull($this->search->lastCall);
        $this->assertSame($this->organization->id, $this->search->lastCall['organizationId']);
        $this->assertNotContains($foreignDossier->id, $this->search->lastCall['dossierIds']);

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringNotContainsString('SECRET-T1309-OTHER-ORG', $prompt->prompt);

            return true;
        });
    }

    public function test_a_non_member_gets_no_hybrid_answer_and_no_source_even_by_forging_the_call(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('ne doit jamais etre appele');

        // L'UI n'est pas la barriere : l'appel de service est force
        // directement, avec l'identifiant reel de la Boucle.
        $this->expectException(RuntimeException::class);

        try {
            app(LoopKnowledgeAnswerService::class)->answerHybrid($this->loop, $this->stranger, 'Que contiennent les dossiers ?');
        } finally {
            LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
            $this->assertDatabaseCount('ai_provider_invocations', 0);
            $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());
        }
    }

    public function test_a_member_of_the_organization_who_is_not_in_the_loop_is_refused_too(): void
    {
        $outsider = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('ne doit jamais etre appele');

        $this->expectException(RuntimeException::class);

        try {
            app(LoopKnowledgeAnswerService::class)->answerHybrid($this->loop, $outsider, 'Que contiennent les dossiers ?');
        } finally {
            LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
            $this->assertDatabaseCount('ai_provider_invocations', 0);
        }
    }

    public function test_an_agent_loop_never_runs_the_hybrid_engine_even_if_the_mode_was_selected(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
        ]);
        $this->loop->forceFill([
            'type' => 'ai_agent',
            'member_ai_profile_id' => $profile->id,
        ])->save();

        $this->fakeKnowledge('ne doit jamais etre appele');
        Queue::fake();

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Question dans une Boucle agent.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function fakeDirect(string $text): void
    {
        LoopDirectAnswerAgent::fake([$text]);
    }

    private function fakeKnowledge(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * Aucune provenance du tout : ni retrieval, ni manifest. Le Dossier
     * explicite est retire ET le document racine de la Boucle depublie
     * (TASK-1307 : une Boucle en porte toujours un, qui suffirait a fournir
     * une [M1]).
     */
    private function emptyTheLoopKnowledge(): void
    {
        $this->dossier->delete();
        BlogPost::whereKey(Dossier::where('loop_id', $this->loop->id)->value('root_blog_post_id'))
            ->update(['status' => 'draft']);
        $this->search->rows = [];
    }

    /** @return array<string, mixed> */
    private function row(string $label): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article '.$label,
            'slug' => 'article-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'content' => "Contenu de l'article {$label}.",
            'distance' => 0.2,
        ];
    }
}

/**
 * Double du moteur pgvector, avec compteur d'appels et dernier perimetre
 * demande — la preuve qu'un tour hybride regronde reellement, et dans la
 * bonne Organization.
 */
class FakeHybridModeSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $calls = 0;

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null): array
    {
        $this->calls++;
        $this->lastCall = compact('organizationId', 'dossierIds', 'query', 'embeddingInstance', 'limit', 'traceMetadata', 'candidateLimit');

        return array_slice($this->rows, 0, $candidateLimit ?? $limit);
    }
}
