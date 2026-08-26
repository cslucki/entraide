<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
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
use Tests\TestCase;

/**
 * TASK-1308 — ChatLoop hybride : le composeur unique choisit, a CHAQUE tour,
 * le moteur (IA direct / Dossiers documentaires), independamment du fil de
 * reponse (`reply_to_id`). Remplace l'auto-detection retiree de TASK-1299
 * (`/ia`) et TASK-1300 (`continuationParent()` force RAG) : le moteur est
 * desormais un choix EXPLICITE du composeur (`LoopChat::$composerMode`),
 * seulement PRESELECTIONNE par le type du message auquel on repond.
 *
 * Invariants prouves ici, dans l'ordre du brief :
 *  - mode normal => jamais d'appel IA, meme apres une reponse IA anterieure ;
 *  - mode IA => LoopDirectAnswerAgent, metadata ai_mode=llm, aucune source ;
 *  - mode Dossiers => LoopKnowledgeAnswerService (RAG loop-scoped, T1294),
 *    metadata ai_mode=rag, sources publiques ;
 *  - LLM -> LLM, RAG -> RAG, RAG -> LLM, LLM -> RAG, et un enchainement a
 *    trois tours : le moteur n'est JAMAIS fige au niveau de la conversation ;
 *  - le contexte AIDE (AiConversationContextBuilder, partage par les deux
 *    moteurs) mais n'est JAMAIS une source : une affirmation LLM ne devient
 *    jamais une reference [M]/[S] ;
 *  - identite de bulle tenant-generique : `{Organization.name} · IA` /
 *    `{Organization.name} · Dossiers` — jamais le slug, jamais "BouclePro" ;
 *  - `/ia` n'est plus une commande : en mode normal, le texte est un message
 *    humain ordinaire ;
 *  - Boucle agent : les deux moteurs restent neutralises cote serveur, meme
 *    si un mode a ete selectionne avant que le composant ne le redecouvre.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1308HybridAiConversationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $member;

    private Loop $loop;

    private Dossier $visibleDossier;

    private FakeHybridSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'LaunchPals',
            'slug' => 'launchpals',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);
        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->owner, 'Boucle hybride');
        $loopService->addMember($this->loop, $this->member, 'member');

        $this->visibleDossier = Dossier::factory()->create([
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

        $this->search = new FakeHybridSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // Le message normal n'appelle jamais l'IA (section 41).
    // =====================================================================

    public function test_normal_message_never_calls_the_ai(): void
    {
        $this->fakeDirect('ne doit jamais etre appele');
        $this->fakeKnowledge('ne doit jamais etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSet('composerMode', 'normal')
            ->set('body', 'Un message humain tout simple.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = LoopMessage::query()->sole();
        $this->assertSame('user', $message->type);
        $this->assertSame('Un message humain tout simple.', $message->body);
        LoopDirectAnswerAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    /**
     * Ancien mecanisme special retire (TASK-1299/1308, section 5/55) : le
     * texte `/ia ...` en mode normal est un message humain comme un autre.
     */
    public function test_slash_ia_text_is_no_longer_a_special_command(): void
    {
        $this->fakeKnowledge('ne doit jamais etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia bonjour')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = LoopMessage::query()->sole();
        $this->assertSame('user', $message->type);
        $this->assertSame('/ia bonjour', $message->body);
        $this->assertNull($message->metadata['slash_ia'] ?? null);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Mode IA direct — LoopDirectAnswerAgent, aucune source.
    // =====================================================================

    public function test_ia_mode_answers_directly_with_organization_identity_and_no_sources(): void
    {
        $this->fakeDirect('Paris.');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Quelle est la capitale de la France ?')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('LaunchPals · '.__('loops.ia_mode_label'))
            ->assertDontSee('launchpals · '.__('loops.ia_mode_label'))
            ->assertDontSee('BouclePro');

        $question = LoopMessage::query()->where('type', 'user')->sole();
        $this->assertSame('ia', $question->metadata['requested_mode'] ?? null);

        $answer = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame('Paris.', $answer->body);
        $this->assertSame($question->id, $answer->reply_to_id);
        $this->assertSame('llm', $answer->metadata['ai_mode']);
        $this->assertSame('ia', $answer->metadata['action']);
        $this->assertArrayNotHasKey('sources', $answer->metadata);
        $this->assertSame(0, $this->search->calls, 'le mode IA n\'interroge jamais les Dossiers');
        $this->assertDatabaseCount('ai_provider_invocations', 1);
    }

    // =====================================================================
    // Mode Dossiers — LoopKnowledgeAnswerService (RAG loop-scoped, T1294).
    // =====================================================================

    public function test_dossiers_mode_answers_grounded_with_sources(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Le manifeste dit ceci [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que dit le manifeste sur le role de l IA ?')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('LaunchPals · '.__('loops.dossiers_mode_label'))
            ->assertSee(__('loops.knowledge_sources_title'));

        $answer = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame('rag', $answer->metadata['ai_mode']);
        $this->assertSame('dossiers', $answer->metadata['action']);
        $this->assertNotEmpty($answer->metadata['sources']);
        $this->assertSame(1, $this->search->calls);
    }

    public function test_dossiers_mode_without_sources_publishes_nothing_and_keeps_the_question(): void
    {
        // Aucune provenance du tout : ni manifest ni retrieval — le Dossier
        // explicite ET le document racine de la Boucle (TASK-1307) sont
        // retires, sinon le manifest a lui seul fournirait une entree [M1].
        $this->visibleDossier->delete();
        $this->draftRootDocument($this->loop);
        $this->search->rows = [];
        $this->fakeKnowledge('ne doit jamais etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Question sans aucune source.')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $message = LoopMessage::query()->sole();
        $this->assertSame('user', $message->type);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // LLM -> LLM : le contexte du reply porte le sujet ("Paris").
    // =====================================================================

    public function test_llm_to_llm_continuation_carries_context(): void
    {
        $this->fakeDirect('Paris.');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Quelle est la capitale de la France ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $firstAnswer = LoopMessage::query()->where('type', 'ai')->sole();

        $this->fakeDirect('Environ deux millions d\'habitants intra-muros.');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $firstAnswer->id)
            ->assertSet('composerMode', 'ia')
            ->set('body', 'Combien d\'habitants ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            if (! str_contains($prompt->prompt, 'Combien d\'habitants ?')) {
                return false;
            }

            $this->assertStringContainsString('Paris.', $prompt->prompt);

            return true;
        });

        $secondAnswer = LoopMessage::query()->where('type', 'ai')->where('body', 'Environ deux millions d\'habitants intra-muros.')->sole();
        $this->assertSame('llm', $secondAnswer->metadata['ai_mode']);
    }

    // =====================================================================
    // Dossiers -> Dossiers : chaque tour REGROUNDE (nouvelle recherche).
    // =====================================================================

    public function test_dossiers_to_dossiers_continuation_regrounds_each_turn(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Le manifeste parle du role de l IA [S1].');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que dit le manifeste sur le role de l IA ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $firstAnswer = LoopMessage::query()->where('type', 'ai')->sole();

        $this->search->rows = [$this->row('B')];
        $this->fakeKnowledge('Le manifeste parle aussi du role des humains [S1].');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $firstAnswer->id)
            ->assertSet('composerMode', 'dossiers')
            ->set('body', 'Et sur le role des humains ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame(2, $this->search->calls, 'chaque tour Dossiers doit relancer une recherche reelle');

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            if (! str_contains($prompt->prompt, 'Et sur le role des humains ?')) {
                return false;
            }

            $this->assertStringContainsString('Le manifeste parle du role de l IA', $prompt->prompt);

            return true;
        });

        $secondAnswer = LoopMessage::query()->where('type', 'ai')->where('body', 'Le manifeste parle aussi du role des humains [S1].')->sole();
        $this->assertSame('rag', $secondAnswer->metadata['ai_mode']);
        $this->assertNotEmpty($secondAnswer->metadata['sources']);
    }

    // =====================================================================
    // Dossiers -> IA : le LLM recoit le contexte, sans source auto-ajoutee.
    // =====================================================================

    public function test_dossiers_to_ia_switch_carries_context_without_auto_sources(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Les documents distinguent Boucle et simple chat [S1].');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que disent les documents sur la difference entre une Boucle et un simple chat ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $ragAnswer = LoopMessage::query()->where('type', 'ai')->sole();

        $this->fakeDirect('Par exemple, dix personnes qui partagent un objectif commun.');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $ragAnswer->id)
            ->call('setComposerMode', 'ia')
            ->set('body', 'Donne-moi un exemple concret dans une equipe de 10 personnes.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            if (! str_contains($prompt->prompt, 'Donne-moi un exemple concret')) {
                return false;
            }

            $this->assertStringContainsString('distinguent Boucle et simple chat', $prompt->prompt);

            return true;
        });

        $llmAnswer = LoopMessage::query()->where('type', 'ai')->where('body', 'Par exemple, dix personnes qui partagent un objectif commun.')->sole();
        $this->assertSame('llm', $llmAnswer->metadata['ai_mode']);
        $this->assertArrayNotHasKey('sources', $llmAnswer->metadata);
        $this->assertSame(1, $this->search->calls, 'le tour IA ne relance aucune recherche');
    }

    // =====================================================================
    // IA -> Dossiers : la reponse finale reste groundee UNIQUEMENT par [M]/[S]
    // — l'affirmation LLM precedente n'est jamais une source (section 58).
    // =====================================================================

    public function test_ia_to_dossiers_switch_grounds_only_from_real_documents(): void
    {
        $this->fakeDirect('Le dossier secret contient XYZ.');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Le dossier secret contient-il XYZ ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $llmAnswer = LoopMessage::query()->where('type', 'ai')->sole();

        // Rien dans les vraies connaissances accessibles : le moteur Dossiers
        // doit refuser d'inventer, MEME si le tour precedent l'affirmait. Le
        // Dossier explicite ET le document racine sont retires le temps de
        // cette phase, sinon le manifest a lui seul fournirait une [M1].
        $this->visibleDossier->delete();
        $this->draftRootDocument($this->loop);
        $this->search->rows = [];
        $this->fakeKnowledge('ne doit jamais etre appele');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $llmAnswer->id)
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Confirme.')
            ->call('sendMessage')
            ->assertHasErrors('body');

        // Le refus RAG (zero source) ne doit invoquer aucun modele : seule
        // l'invocation LLM du premier tour compte.
        $this->assertDatabaseCount('ai_provider_invocations', 1);

        // Avec une vraie source, le moteur Dossiers regronde reellement — et
        // ne cite jamais "XYZ" comme si l'affirmation LLM en etait la preuve.
        $this->visibleDossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Non, rien dans nos sources ne mentionne XYZ [S1].');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $llmAnswer->id)
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Confirme.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $ragAnswer = LoopMessage::query()->where('type', 'ai')->where('body', 'Non, rien dans nos sources ne mentionne XYZ [S1].')->sole();
        $this->assertSame('rag', $ragAnswer->metadata['ai_mode']);
        foreach ($ragAnswer->metadata['sources'] as $source) {
            $this->assertStringNotContainsString('XYZ', (string) ($source['title'] ?? '').(string) ($source['excerpt'] ?? ''));
        }
    }

    // =====================================================================
    // Enchainement a trois tours : le moteur n'est jamais fige (section 53).
    // =====================================================================

    public function test_three_turn_switch_rag_llm_rag(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Le manifeste parle du role de l IA [S1].');
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que dit le manifeste sur le role de l IA ?')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $turn1 = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame('rag', $turn1->metadata['ai_mode']);

        $this->fakeDirect('Un exemple concret serait...');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $turn1->id)
            ->call('setComposerMode', 'ia')
            ->set('body', 'Donne un exemple concret.')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $turn2 = LoopMessage::query()->where('type', 'ai')->where('body', 'Un exemple concret serait...')->sole();
        $this->assertSame('llm', $turn2->metadata['ai_mode']);

        $this->search->rows = [$this->row('B')];
        $this->fakeKnowledge('Nos documents confirment cet exemple [S1].');
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $turn2->id)
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Est-ce que nos documents confirment cet exemple ?')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $turn3 = LoopMessage::query()->where('type', 'ai')->where('body', 'Nos documents confirment cet exemple [S1].')->sole();
        $this->assertSame('rag', $turn3->metadata['ai_mode']);

        $this->assertSame(2, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 3);
    }

    // =====================================================================
    // Repondre a un message HUMAIN : normal par defaut, mais un mode reste
    // selectionnable explicitement (section 16).
    // =====================================================================

    public function test_reply_to_a_human_message_defaults_to_normal_but_dossiers_stays_selectable(): void
    {
        $humanQuestion = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Comment devrions-nous presenter cette partie du projet ?',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->member);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $humanQuestion->id)
            ->assertSet('composerMode', 'normal');

        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Oui, nos documents apportent des elements [S1].');

        $component
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Est-ce que nos documents apportent des elements ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            if (! str_contains($prompt->prompt, 'apportent des elements')) {
                return false;
            }

            $this->assertStringContainsString('presenter cette partie du projet', $prompt->prompt);

            return true;
        });

        $answer = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertSame($humanQuestion->id, $answer->metadata['context_message_ids'][0] ?? null);
    }

    // =====================================================================
    // Boucle agent : les deux moteurs restent neutralises cote serveur.
    // =====================================================================

    public function test_agent_loop_never_invokes_either_engine_even_if_a_mode_was_selected(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
        ]);
        $this->loop->forceFill([
            'type' => 'ai_agent',
            'member_ai_profile_id' => $profile->id,
        ])->save();

        $this->fakeDirect('ne doit jamais etre appele');
        $this->fakeKnowledge('ne doit jamais etre appele');
        // L'agent T-2 repond deja a chaque message de cette Boucle (listener
        // sur LoopMessageCreated) : sans le mock de la file, sa reponse
        // ajouterait un second LoopMessage sans rapport avec ce que ce test
        // prouve (les DEUX moteurs du composeur unifie restent neutralises).
        Queue::fake();

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Question dans une Boucle agent.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = LoopMessage::query()->where('type', 'user')->sole();
        $this->assertSame('user', $message->type);
        LoopDirectAnswerAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Reply etranger cross-Organization — jamais attache, jamais dans le
    // contexte (revue TASK-1308, BLOCKER 2C).
    // =====================================================================

    public function test_replying_to_a_message_of_another_organization_never_attaches_and_never_leaks(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherOwner = User::factory()->create(['organization_id' => $otherOrganization->id]);
        app()->instance('current_organization', $otherOrganization);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Boucle etrangere');
        $foreignMessage = LoopMessage::create([
            'loop_id' => $otherLoop->id,
            'sender_id' => null,
            'body' => 'SECRET-DE-B ne doit jamais fuiter.',
            'type' => 'ai',
            'metadata' => ['ai_mode' => 'llm', 'action' => 'ia'],
            'organization_id' => $otherLoop->organization_id,
        ]);
        app()->instance('current_organization', $this->organization);

        $this->fakeDirect('Reponse sans contexte etranger.');
        $this->actingAs($this->member);

        // Chemin 1 : replyTo() cherche le message DANS la Boucle courante —
        // un message d'une autre Boucle/Organization n'est jamais trouve,
        // aucun reply n'est attache.
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $foreignMessage->id)
            ->assertSet('replyToMessageId', null)
            ->assertSet('composerMode', 'normal');

        // Chemin 2 : meme si `replyToMessageId` est force directement (bypass
        // de replyTo(), simule un evenement front perime ou manipule) —
        // LoopMessageService::sendUserMessage() annule le reply_to_id
        // etranger AVANT toute persistance : AiConversationContextBuilder ne
        // voit donc jamais ce parent.
        $component
            ->call('setComposerMode', 'ia')
            ->set('replyToMessageId', $foreignMessage->id)
            ->set('body', 'Question posee depuis Organization A.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $question = LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'user')->sole();
        $this->assertNull($question->reply_to_id, 'le reply_to_id etranger doit avoir ete annule a la persistance');

        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringNotContainsString('SECRET-DE-B', $prompt->prompt);

            return true;
        });

        $answer = LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->sole();
        $this->assertSame([], $answer->metadata['context_message_ids']);
    }

    // =====================================================================
    // Echec provider — message humain CONSERVE, aucune fausse reponse
    // (revue TASK-1308, BLOCKER 2F).
    // =====================================================================

    public function test_ia_mode_provider_failure_preserves_the_human_message_and_publishes_nothing(): void
    {
        LoopDirectAnswerAgent::fake(function (): never {
            throw new \RuntimeException('provider down');
        });

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Question qui echoue.')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $message = LoopMessage::query()->sole();
        $this->assertSame('user', $message->type);
        $this->assertSame('Question qui echoue.', $message->body);
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame('failed', AiInteraction::sole()->metadata['status']);
    }

    public function test_dossiers_mode_provider_failure_preserves_the_human_message_and_publishes_nothing(): void
    {
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake(function (): never {
            throw new \RuntimeException('provider down');
        });

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Question documentaire qui echoue.')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $message = LoopMessage::query()->sole();
        $this->assertSame('user', $message->type);
        $this->assertSame('Question documentaire qui echoue.', $message->body);
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame('failed', AiInteraction::sole()->metadata['status']);
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
     * TASK-1307 : chaque Boucle cree son propre Dossier RACINE (root
     * document) des `createLoop()` — un manifest a lui seul suffit a
     * fournir une provenance [Mn]. Un scenario "zero source" doit aussi
     * depublier ce document racine, pas seulement retirer le Dossier
     * explicite du test.
     */
    private function draftRootDocument(Loop $loop): void
    {
        BlogPost::whereKey(Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'))
            ->update(['status' => 'draft']);
    }

    /** @return array<string, mixed> */
    private function row(string $label): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->visibleDossier->id,
            'dossier_name' => $this->visibleDossier->name,
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
 * Double du moteur pgvector (contrat TASK1213/TASK1297), avec un compteur
 * d'appels — la preuve qu'un tour Dossiers regronde reellement, ou au
 * contraire n'est jamais invoque par un tour IA.
 */
class FakeHybridSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $calls = 0;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null): array
    {
        $this->calls++;

        return array_slice($this->rows, 0, $candidateLimit ?? $limit);
    }
}
