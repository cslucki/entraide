<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
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
use Tests\TestCase;

/**
 * TASK-1299 — `/ia`, invocation explicite dans le ChatLoop (T074.2 / T-3).
 *
 * Le SEUL point d'entree est `LoopChat::sendMessage()` : un corps qui commence
 * par `/ia` déclenche la chaine knowledge EXISTANTE (`loop_knowledge_answer`,
 * RAG Loop-scoped de TASK-1294, garde economique et filtrage des sources de
 * TASK-1297) sur le message qui vient d'etre persiste par `sendUserMessage()`.
 *
 * Les proprietes prouvees ici, dans l'ordre du brief :
 *  - un message ordinaire ne declenche JAMAIS l'IA (fil a dix personnes) ;
 *  - EXACTEMENT UN message utilisateur par `/ia` (piege de la double
 *    persistance : `publishExchange()` ne doit pas re-publier la question) ;
 *  - le corps est persiste TEL QUE TAPE, prefixe compris ; le prompt modele
 *    est debarrasse du prefixe ; la provenance reste tracable en metadata ;
 *  - sources de CETTE Boucle seulement (T1294), publiees sans chunk_id ni
 *    metadonnee interne (filtre `KnowledgeAnswer::publicSource()` de T-1) ;
 *  - exactement UNE sequence economique par `/ia` : 1 recherche + 1 ligne de
 *    generation au ledger — l'embedding de la recherche REELLE ecrit sa
 *    propre ligne (constat T1296 : « 1 question = 2 invocations »), mais le
 *    moteur est double ici, donc la ligne comptee est la generation et la
 *    recherche est comptee sur le double ;
 *  - `/ia` vide : aide locale deterministe, rien persiste, zero cout ;
 *  - echec provider / zero source : le message humain est CONSERVE, aucune
 *    fausse reponse IA ;
 *  - Boucle agent : `/ia` reste un message ordinaire (le listener T-2 fait
 *    deja repondre l'agent a tout message — deux IA seraient deux depenses) ;
 *  - le chemin null de `answer()` (modal knowledge) est inchange, flag
 *    `ai.knowledge.publish_question` toujours gouvernant (exigence Cowork,
 *    en complement de la suite TASK1297 qui reste la preuve complete).
 */
class TASK1299SlashIaInvocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $secondMember;

    private User $stranger;

    private Loop $loop;

    private Dossier $visibleDossier;

    private FakeSlashIaSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->secondMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->member, 'Boucle slash ia');
        $loopService->addMember($this->loop, $this->secondMember, 'member');

        app()->instance('current_organization', $this->organization);

        $this->visibleDossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        $this->search = new FakeSlashIaSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // La propriete qui rend la fonction utilisable dans un fil a dix
    // personnes : un message ordinaire ne declenche JAMAIS l'IA.
    // =====================================================================

    public function test_an_ordinary_message_never_triggers_the_ai(): void
    {
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Bonjour tout le monde')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = LoopMessage::sole();
        $this->assertSame('Bonjour tout le monde', $message->body);
        $this->assertSame('user', $message->type);

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    public function test_a_body_merely_containing_or_extending_the_prefix_stays_ordinary(): void
    {
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->member);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop]);

        foreach (['Regarde /ia ici', '/iat quelque chose', '//ia comme ceci'] as $body) {
            $component->set('body', $body)->call('sendMessage')->assertHasNoErrors();
        }

        $this->assertSame(3, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Le piege central (brief §2) : EXACTEMENT UN message utilisateur.
    // =====================================================================

    public function test_slash_ia_persists_exactly_one_user_message_with_the_body_as_typed(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Voici la synthese [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/IA  Quelle synthese des echanges ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        // UN seul message utilisateur — la question n'est pas re-publiee par
        // publishExchange(), quoi qu'ait fait la chaine knowledge derriere.
        $userMessage = LoopMessage::query()->where('type', 'user')->sole();

        // Tel que tape, octet pour octet : casse et double espace compris. On
        // n'invente pas un message que l'utilisateur n'a pas ecrit.
        $this->assertSame('/IA  Quelle synthese des echanges ?', $userMessage->body);
        $this->assertSame($this->member->id, $userMessage->sender_id);
        $this->assertTrue((bool) ($userMessage->metadata['slash_ia'] ?? false));
    }

    public function test_slash_ia_publishes_an_ai_reply_linked_to_the_human_message(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Voici la synthese [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Quelle synthese des echanges ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $userMessage = LoopMessage::query()->where('type', 'user')->sole();
        $aiMessage = LoopMessage::query()->where('type', 'ai')->sole();

        $this->assertNull($aiMessage->sender_id);
        $this->assertSame($userMessage->id, $aiMessage->reply_to_id);
        $this->assertSame('slash_ia', $aiMessage->metadata['action']);
        $this->assertSame('Quelle synthese des echanges ?', $aiMessage->metadata['question']);
        $this->assertSame($this->member->id, $aiMessage->metadata['requested_by']);
        $this->assertSame(AiInteraction::sole()->id, $aiMessage->metadata['ai_interaction_id']);
        $this->assertStringContainsString('Voici la synthese', $aiMessage->body);
    }

    public function test_the_model_prompt_is_stripped_of_the_prefix(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Quelle synthese des echanges ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString("Question du membre :\nQuelle synthese des echanges ?", $prompt->prompt);
            $this->assertStringNotContainsString('/ia', $prompt->prompt);

            return true;
        });
    }

    // =====================================================================
    // Sources : de CETTE Boucle seulement (T1294), publiees sans fuite (T-1).
    // =====================================================================

    public function test_the_search_is_scoped_to_this_loop_and_carries_its_context(): void
    {
        // Un dossier de l'Organization, parfaitement lisible par le membre,
        // mais HORS de la Boucle : la restriction T1294 doit l'exclure AVANT
        // la recherche — sinon le test ne prouverait qu'une permission.
        Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->secondMember->id,
            'name' => 'Dossier hors Boucle',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);

        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Que disent les dossiers ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame([$this->visibleDossier->id], $this->search->lastCall['dossierIds']);
        $this->assertSame($this->organization->id, $this->search->lastCall['organizationId']);
        $this->assertSame($this->loop->id, $this->search->lastCall['traceMetadata']['loop_id']);
        $this->assertSame('loop_knowledge_answer', $this->search->lastCall['traceMetadata']['capability']);
    }

    public function test_published_sources_leak_no_chunk_id_nor_internal_metadata(): void
    {
        $row = $this->row('A');
        $this->search->rows = [$row];
        $this->fakeAgent('Reponse sourcee [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Que disent les dossiers ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $aiMessage = LoopMessage::query()->where('type', 'ai')->sole();
        $sources = $aiMessage->metadata['sources'];

        $this->assertNotSame([], $sources);

        // La forme publique de T-1 (KnowledgeAnswer::publicSource), reutilisee
        // telle quelle : cinq cles pour le lecteur, rien pour la machine.
        foreach ($sources as $source) {
            $this->assertSame(['ref', 'title', 'dossier_name', 'excerpt', 'url'], array_keys($source));
        }

        $serialized = json_encode($aiMessage->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('chunk_id', $serialized);
        $this->assertStringNotContainsString($row['chunk_id'], $serialized);
        $this->assertStringNotContainsString('distance', $serialized);
    }

    public function test_the_exchange_survives_a_page_refresh_for_every_member(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Synthese persistee [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Quelle synthese ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $page = $this->actingAs($this->secondMember)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop]));

        $page->assertOk()
            ->assertSee('/ia Quelle synthese ?', false)
            ->assertSee('Synthese persistee', false);
    }

    // =====================================================================
    // Economie : exactement UNE sequence par /ia, jamais deux.
    // =====================================================================

    public function test_exactly_one_economic_sequence_per_slash_ia(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Une seule depense ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        // L'ensemble EXACT : une recherche (dont l'embedding, ecrit par le
        // moteur reel double ici — T1296 : « 1 question = 2 invocations »),
        // une generation au ledger, une interaction. Rien de plus.
        $this->assertSame(1, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 1);
        $this->assertDatabaseCount('ai_interactions', 1);
    }

    public function test_a_double_submit_from_the_same_composer_cannot_double_spend(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse [S1].');

        $this->actingAs($this->member);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Une question ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        // Le second envoi du meme composeur (double clic mis en file par
        // Livewire) repart sur l'etat rendu par le premier : corps vide, donc
        // validation — aucun second message, aucune seconde depense.
        $component->call('sendMessage')->assertHasErrors(['body']);

        $this->assertSame(1, LoopMessage::query()->where('type', 'user')->count());
        $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame(1, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 1);
    }

    // =====================================================================
    // /ia vide : aide locale deterministe, rien persiste, zero cout.
    // =====================================================================

    public function test_an_empty_slash_ia_is_local_help_only(): void
    {
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->member);

        foreach (['/ia', '/ia   '] as $body) {
            Livewire::test(LoopChat::class, ['loop' => $this->loop])
                ->set('body', $body)
                ->call('sendMessage')
                ->assertHasErrors(['body'])
                ->assertSee(__('loops.slash_ia_help'))
                // La saisie est conservee : l'auteur complete sa question au
                // lieu de la retaper.
                ->assertSet('body', $body);
        }

        $this->assertSame(0, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Refus et echecs : le message humain reste, aucune fausse reponse.
    // =====================================================================

    public function test_a_stranger_cannot_invoke_and_nothing_is_written(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->stranger);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Une question depuis une autre Organization ?')
            ->call('sendMessage');

        $this->assertSame(0, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    public function test_a_provider_failure_keeps_the_human_message_and_publishes_no_fake_answer(): void
    {
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake(function (): TextResponse {
            throw new \RuntimeException('provider down');
        });

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Une question ?')
            ->call('sendMessage')
            ->assertHasErrors(['body']);

        // Le message humain est CONSERVE — il a ete envoye, l'IA seule a
        // echoue — et aucune fausse reponse n'apparait. La trace `failed`
        // du chemin knowledge, elle, existe (T-1, inchange).
        $userMessage = LoopMessage::sole();
        $this->assertSame('/ia Une question ?', $userMessage->body);
        $this->assertSame('user', $userMessage->type);
        $this->assertSame('failed', AiInteraction::sole()->metadata['status']);
    }

    public function test_no_relevant_source_keeps_the_human_message_and_publishes_nothing(): void
    {
        $this->search->rows = [];
        $this->fakeAgent('ne doit pas etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Une question sans source ?')
            ->call('sendMessage')
            ->assertHasErrors(['body'])
            ->assertSee(__('loops.knowledge_no_sources'));

        // Meme principe que T-1 : rien n'est publie quand rien n'a coute.
        // Le message humain, lui, a ete envoye et reste.
        $userMessage = LoopMessage::sole();
        $this->assertSame('user', $userMessage->type);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Boucle agent : /ia reste un message ordinaire (DECISION_REQUIRED_CYRIL
    // consignee au TASK file — l'agent T-2 repond deja a tout message, deux
    // IA seraient deux depenses).
    // =====================================================================

    public function test_slash_ia_stays_an_ordinary_message_in_agent_loops(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
        ]);
        $this->loop->forceFill([
            'type' => 'ai_agent',
            'member_ai_profile_id' => $profile->id,
        ])->save();

        $this->fakeAgent('ne doit jamais etre appele');
        Queue::fake();

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Une question a l agent ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        // Un seul acteur IA : l'agent membre (job T-2), pas la chaine
        // knowledge — et le message reste un message ordinaire, sans marque.
        $userMessage = LoopMessage::sole();
        $this->assertSame('/ia Une question a l agent ?', $userMessage->body);
        $this->assertNull($userMessage->metadata['slash_ia'] ?? null);
        Queue::assertPushed(GenerateAiAgentResponse::class, 1);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Le chemin null de answer() est inchange (exigence Cowork) : la suite
    // TASK1297 entiere reste la preuve principale ; cette sentinelle borne le
    // flag publish_question sur la route modal APRES le changement T-3.
    // =====================================================================

    public function test_the_null_path_of_the_knowledge_action_still_obeys_publish_question(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse du modal [S1].');

        $this->actingAs($this->member)
            ->postJson(
                route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]),
                ['question' => 'Une question via le modal ?'],
            )
            ->assertOk();

        // Le comportement T-1 tel que merge : la question EST publiee (flag a
        // true par defaut), la reponse liee — deux messages, pas un.
        $question = LoopMessage::query()->where('type', 'user')->sole();
        $answer = LoopMessage::query()->where('type', 'ai')->sole();
        $this->assertTrue((bool) ($question->metadata['asked_knowledge_question'] ?? false));
        $this->assertSame($question->id, $answer->reply_to_id);
        $this->assertSame('knowledge', $answer->metadata['action']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
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

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }
}

/**
 * Double du moteur pgvector (contrat TASK1213/TASK1297), avec un compteur
 * d'appels : la preuve « une recherche et une seule » du test economique.
 */
class FakeSlashIaSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    public int $calls = 0;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = []): array
    {
        $this->calls++;
        $this->lastCall = compact('organizationId', 'dossierIds', 'query', 'embeddingInstance', 'limit', 'traceMetadata');

        return array_slice($this->rows, 0, $limit);
    }
}
