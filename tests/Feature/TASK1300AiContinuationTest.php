<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Events\LoopMessageCreated;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1300 — continuation par reponse a un message IA (T074.2 / T-4).
 *
 * LA REGLE FONDAMENTALE : la continuation se declenche SI ET SEULEMENT SI un
 * message HUMAIN (type='user'), ecrit depuis le composeur, porte un
 * `reply_to_id` qui pointe un message type='ai' non supprime de la MEME
 * Boucle. Tout le reste reste ce qu'il est.
 *
 * Les proprietes prouvees ici, dans l'ordre du brief :
 *  - reply a un message IA de la meme Boucle => continuation via la chaine
 *    knowledge EXISTANTE (T-1/T-3), reponse liee par reply_to_id ;
 *  - reply a un message humain => AUCUNE IA ; reply photo-sans-texte ou
 *    parent IA supprime => message ordinaire (arbitrage Cyril 24/08) ;
 *  - reply a un message IA d'une AUTRE Boucle => `sendUserMessage()` annule
 *    le reply_to_id, message ordinaire, aucune garde nouvelle ;
 *  - PIEGE 1 : ni type='ai' ni type='member_agent' ne declenchent JAMAIS —
 *    sinon une reponse IA en declenche une autre, indefiniment, chaque tour
 *    coutant de l'argent ; et la reponse d'une continuation n'en declenche
 *    pas une troisieme ;
 *  - PIEGE 2 : Boucle agent => comportement ordinaire conserve (le listener
 *    T-2 fait deja repondre l'agent a tout message) ;
 *  - PIEGE 3 : EXACTEMENT UN message utilisateur ecrit par une continuation
 *    (mecanisme inThreadTrigger de T-3 reutilise, jamais un second) ;
 *  - contexte de fil BORNE : profondeur max 6 messages (3 tours — le
 *    minimum utile du brief tient en 2), caracteres bornes par
 *    `ai.chatloop.max_context_chars` existant, plus ancien coupe d'abord ;
 *  - economie : l'ensemble EXACT du parcours /ia + continuation, epingle par
 *    pluck (capability, status, user_id) — pas un simple compte ;
 *  - sources Loop-scoped (T1294), publiees sans chunk_id ni metadonnee
 *    interne (filtre publicSource de T-1) ;
 *  - echec provider => le message humain est CONSERVE, aucune fausse
 *    reponse ; etranger d'une autre Organization => refus AVANT tout appel ;
 *  - arbitrage Cyril 24/08 : `/ia` envoye EN REPONSE a un message IA = la
 *    branche /ia gagne (une seule invocation) ET le contexte de fil est
 *    construit quand meme — comportement DECIDE, pas incident.
 */
class TASK1300AiContinuationTest extends TestCase
{
    use RefreshDatabase;

    private const CONTINUATION = 'Et quelles seraient les prochaines etapes ?';

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $secondMember;

    private User $stranger;

    private Loop $loop;

    private Dossier $visibleDossier;

    private FakeContinuationSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->secondMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->member, 'Boucle continuation');
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

        $this->search = new FakeContinuationSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // La regle fondamentale : reply a un message IA de la MEME Boucle.
    // =====================================================================

    public function test_a_reply_to_an_ai_message_of_the_same_loop_triggers_a_continuation(): void
    {
        [, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Voici les prochaines etapes [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage')
            ->assertHasNoErrors();

        $continuation = LoopMessage::query()->where('type', 'user')->where('reply_to_id', $answer->id)->sole();
        $this->assertSame(self::CONTINUATION, $continuation->body);
        $this->assertSame($this->member->id, $continuation->sender_id);
        $this->assertTrue((bool) ($continuation->metadata['ai_continuation'] ?? false));

        $aiReply = LoopMessage::query()->where('type', 'ai')->where('reply_to_id', $continuation->id)->sole();
        $this->assertSame('Voici les prochaines etapes [S1].', $aiReply->body);
        $this->assertNull($aiReply->sender_id);
        $this->assertSame('continuation', $aiReply->metadata['action']);
        $this->assertSame(self::CONTINUATION, $aiReply->metadata['question']);
        $this->assertSame($this->member->id, $aiReply->metadata['requested_by']);
        $this->assertSame(AiInteraction::sole()->id, $aiReply->metadata['ai_interaction_id']);

        $this->assertSame(4, LoopMessage::count());
        $this->assertSame(1, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 1);
    }

    public function test_a_reply_to_a_human_message_never_triggers_the_ai(): void
    {
        [$question] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->secondMember);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $question->id)
            ->set('body', 'Je te reponds a toi, pas a la machine.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $reply = LoopMessage::query()->where('reply_to_id', $question->id)->where('type', 'user')->sole();
        $this->assertNull($reply->metadata['ai_continuation'] ?? null);
        $this->assertSame(3, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    public function test_a_reply_to_an_ai_message_of_another_loop_stays_ordinary(): void
    {
        // Le message IA vit dans une AUTRE Boucle du meme membre : la
        // reutilisation voulue par le brief est que `sendUserMessage()`
        // annule ce reply_to_id hors Boucle — aucune garde nouvelle.
        $otherLoop = (new LoopService)->createLoop($this->member, 'Autre Boucle');
        $foreignAnswer = $this->aiMessage($otherLoop, 'Reponse IA d une autre Boucle.');

        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('replyToMessageId', $foreignAnswer->id)
            ->set('body', 'Une reponse egaree.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = LoopMessage::query()->where('loop_id', $this->loop->id)->sole();
        $this->assertNull($message->reply_to_id);
        $this->assertSame('user', $message->type);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    public function test_a_reply_to_a_deleted_ai_message_stays_ordinary(): void
    {
        [, $answer] = $this->seedExchange();
        $answer->forceFill(['deleted_at' => now()])->saveQuietly();

        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', 'Reponse a un message efface.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $reply = LoopMessage::query()->where('reply_to_id', $answer->id)->sole();
        $this->assertSame('user', $reply->type);
        $this->assertNull($reply->metadata['ai_continuation'] ?? null);
        $this->assertSame(3, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    public function test_a_photo_only_reply_to_an_ai_message_stays_ordinary(): void
    {
        [, $answer] = $this->seedExchange();
        Storage::fake('public');

        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('photo', UploadedFile::fake()->image('photo.jpg', 60, 40))
            ->call('sendMessage')
            ->assertHasNoErrors();

        // Pas de texte = pas de question : rien a poser au modele.
        $reply = LoopMessage::query()->where('reply_to_id', $answer->id)->sole();
        $this->assertSame('user', $reply->type);
        $this->assertNotNull($reply->image_path);
        $this->assertNull($reply->metadata['ai_continuation'] ?? null);
        $this->assertSame(3, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // PIEGE 1 — la boucle IA -> IA. La garde est le type de l'EMETTEUR :
    // seul un humain (type='user', via le composeur) declenche. Il existe
    // TROIS types depuis T-2 : ni 'ai' ni 'member_agent' ne declenchent.
    // =====================================================================

    public function test_an_ai_message_replying_to_an_ai_message_triggers_nothing(): void
    {
        [, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        // Le chemin d'ecriture de production d'un message IA : creation
        // directe + LoopMessageCreated (publishExchange). Si quoi que ce
        // soit traitait « nouveau message » comme un declencheur, CE test
        // deviendrait la facture infinie du piege 1.
        $aiOnAi = $this->aiMessage($this->loop, 'Une IA qui repond a une IA.', $answer->id);
        event(new LoopMessageCreated($aiOnAi));

        $this->assertSame(3, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    public function test_a_member_agent_message_replying_to_an_ai_message_triggers_nothing(): void
    {
        [, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        $agentOnAi = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'reply_to_id' => $answer->id,
            'body' => 'Un agent membre qui repond a une IA.',
            'type' => 'member_agent',
            'metadata' => null,
            'organization_id' => $this->loop->organization_id,
        ]);
        event(new LoopMessageCreated($agentOnAi));

        $this->assertSame(3, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    public function test_a_continuation_answer_does_not_trigger_a_third_answer(): void
    {
        [, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Suite [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage')
            ->assertHasNoErrors();

        // La reponse de la continuation porte elle-meme un reply_to_id vers
        // un message... humain. Et meme si elle pointait un message IA, son
        // emetteur n'est pas un humain : UNE reponse, pas une chaine.
        $this->assertSame(2, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame(1, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 1);
        $this->assertDatabaseCount('ai_provider_invocations', 1);
    }

    // =====================================================================
    // PIEGE 2 — les Boucles agent : le listener T-2 fait DEJA repondre
    // l'agent a tout message. Une reponse a un message IA y reste un message
    // ordinaire — un seul acteur IA, une seule depense.
    // =====================================================================

    public function test_a_reply_to_the_agent_in_an_agent_loop_stays_ordinary(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
        ]);
        $this->loop->forceFill([
            'type' => 'ai_agent',
            'member_ai_profile_id' => $profile->id,
        ])->save();

        $agentMessage = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'reply_to_id' => null,
            'body' => 'Reponse de l agent membre.',
            'type' => 'member_agent',
            'metadata' => null,
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');
        Queue::fake();

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $agentMessage->id)
            ->set('body', 'Je poursuis avec l agent.')
            ->call('sendMessage')
            ->assertHasNoErrors();

        // Un seul acteur IA : le job T-2, pousse une fois pour ce message —
        // la chaine knowledge n'est jamais invoquee, aucune double depense.
        $reply = LoopMessage::query()->where('reply_to_id', $agentMessage->id)->sole();
        $this->assertSame('user', $reply->type);
        $this->assertNull($reply->metadata['ai_continuation'] ?? null);
        Queue::assertPushed(GenerateAiAgentResponse::class, 1);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // PIEGE 3 — la double persistance : le mecanisme inThreadTrigger de T-3
    // est REUTILISE, le declencheur est le message deja persiste du fil.
    // =====================================================================

    public function test_a_continuation_writes_exactly_one_user_message(): void
    {
        [, $answer] = $this->seedExchange();
        $usersBefore = LoopMessage::query()->where('type', 'user')->count();

        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Suite [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage')
            ->assertHasNoErrors();

        // La continuation a REELLEMENT eu lieu — sans cette exigence, le
        // test passerait a vide sur une reply ordinaire (assertion creuse).
        $continuation = LoopMessage::query()->where('type', 'user')->where('body', self::CONTINUATION)->sole();
        LoopMessage::query()->where('type', 'ai')->where('reply_to_id', $continuation->id)->sole();

        // Et elle a ecrit EXACTEMENT UN message utilisateur : si
        // publishExchange() re-publiait la question, il y en aurait deux.
        $this->assertSame($usersBefore + 1, LoopMessage::query()->where('type', 'user')->count());
    }

    // =====================================================================
    // Le fil : intact au refresh, ordre correct sur deux continuations.
    // =====================================================================

    public function test_refresh_keeps_the_thread_intact_and_ordered(): void
    {
        [$question, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Voici les prochaines etapes [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage')
            ->assertHasNoErrors();

        // Un composant fraichement monte — le refresh de Roger — remonte le
        // meme fil, complet, dans l'ordre.
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeInOrder([
                $question->body,
                $answer->body,
                self::CONTINUATION,
                'Voici les prochaines etapes [S1].',
            ]);

        $this->assertSame(4, LoopMessage::count());
    }

    public function test_two_successive_continuations_keep_order_and_context(): void
    {
        [$question, $answer] = $this->seedExchange('Premiere question sur l emergence ?', 'Premiere reponse documentee [S1].');
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake([
            $this->textResponse('Deuxieme reponse documentee [S1].'),
            $this->textResponse('Troisieme reponse documentee [S1].'),
        ]);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', 'Premiere continuation ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $secondAnswer = LoopMessage::query()->where('type', 'ai')->where('body', 'Deuxieme reponse documentee [S1].')->sole();

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $secondAnswer->id)
            ->set('body', 'Seconde continuation ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        // Le fil : Q, R1, C1, R2, C2, R3 — six messages, dans l'ordre.
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeInOrder([
                $question->body,
                $answer->body,
                'Premiere continuation ?',
                'Deuxieme reponse documentee [S1].',
                'Seconde continuation ?',
                'Troisieme reponse documentee [S1].',
            ]);

        // Le contexte de la SECONDE continuation contient la chaine entiere
        // (4 messages, sous la borne de profondeur), dans l'ordre
        // chronologique, PUIS la nouvelle question.
        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            if (! str_contains($prompt->prompt, 'Seconde continuation ?')) {
                return false; // l'appel de la premiere continuation
            }

            $positions = [
                strpos($prompt->prompt, 'Premiere question sur l emergence ?'),
                strpos($prompt->prompt, 'Premiere reponse documentee [S1].'),
                strpos($prompt->prompt, 'Premiere continuation ?'),
                strpos($prompt->prompt, 'Deuxieme reponse documentee [S1].'),
                strpos($prompt->prompt, "Question du membre :\nSeconde continuation ?"),
            ];

            foreach ($positions as $index => $position) {
                $this->assertIsInt($position, 'Element '.$index.' absent du prompt de continuation.');
                if ($index > 0) {
                    $this->assertGreaterThan($positions[$index - 1], $position);
                }
            }

            return true;
        });

        $this->assertSame(2, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 2);
    }

    // =====================================================================
    // Le contexte est BORNE : profondeur ET caracteres. Une chaine de 40
    // continuations ne produit pas un prompt de 40 tours.
    // =====================================================================

    public function test_the_continuation_context_is_bounded_in_depth(): void
    {
        // Douze maillons relies par reply_to_id ; la borne de profondeur est
        // de 6 messages (3 tours) : seuls Maillon 07..12 entrent au prompt.
        $last = $this->replyChain(12);
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse bornee [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $last->id)
            ->set('body', 'Une continuation au bout de la chaine ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            foreach (['Maillon 07', 'Maillon 08', 'Maillon 09', 'Maillon 10', 'Maillon 11', 'Maillon 12'] as $kept) {
                $this->assertStringContainsString($kept, $prompt->prompt);
            }
            foreach (['Maillon 01', 'Maillon 02', 'Maillon 03', 'Maillon 04', 'Maillon 05', 'Maillon 06'] as $dropped) {
                $this->assertStringNotContainsString($dropped, $prompt->prompt);
            }

            return true;
        });
    }

    public function test_the_continuation_context_is_bounded_in_chars(): void
    {
        // La borne EXISTANTE ai.chatloop.max_context_chars s'applique au
        // transcript : le plus ancien est coupe d'abord, le parent direct
        // reste.
        config(['ai.chatloop.max_context_chars' => 220]);

        $old = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'reply_to_id' => null,
            'body' => 'ANCIEN '.str_repeat('a', 150),
            'type' => 'user',
            'metadata' => null,
            'organization_id' => $this->loop->organization_id,
        ]);
        $old->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        $parent = $this->aiMessage($this->loop, 'RECENT '.str_repeat('b', 150), $old->id, minutesAgo: 9);

        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse bornee [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $parent->id)
            ->set('body', 'Une continuation sous borne de caracteres ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('RECENT', $prompt->prompt);
            $this->assertStringNotContainsString('ANCIEN', $prompt->prompt);

            return true;
        });
    }

    // =====================================================================
    // Economie : l'ensemble EXACT du parcours /ia + continuation, epingle
    // par pluck — pas un simple compte.
    // =====================================================================

    public function test_the_exact_economic_set_of_the_full_journey(): void
    {
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake([
            $this->textResponse('Reponse initiale [S1].'),
            $this->textResponse('Reponse de continuation [S1].'),
        ]);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '/ia Qu avons-nous appris sur l emergence ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $firstAnswer = LoopMessage::query()->where('type', 'ai')->sole();

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $firstAnswer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage')
            ->assertHasNoErrors();

        // L'ensemble EXACT : deux generations knowledge, reussies, portees
        // par le demandeur — rien d'autre au ledger, dans aucun autre etat.
        $this->assertSame(
            [
                ['loop_knowledge_answer', 'success', $this->member->id],
                ['loop_knowledge_answer', 'success', $this->member->id],
            ],
            AiProviderInvocation::query()
                ->orderBy('created_at')
                ->get()
                ->map(fn (AiProviderInvocation $row): array => [$row->capability, $row->status, $row->user_id])
                ->all(),
        );
        $this->assertSame(2, $this->search->calls);
        $this->assertDatabaseCount('ai_interactions', 2);
        $this->assertSame(2, LoopMessage::query()->where('type', 'user')->count());
        $this->assertSame(2, LoopMessage::query()->where('type', 'ai')->count());
    }

    // =====================================================================
    // Sources : Loop-scoped (T1294), publiees sans fuite (T-1).
    // =====================================================================

    public function test_continuation_sources_stay_loop_scoped_and_leak_nothing(): void
    {
        // Un dossier lisible par le membre mais HORS Boucle : la restriction
        // T1294 doit l'exclure AVANT la recherche.
        Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->secondMember->id,
            'name' => 'Dossier hors Boucle',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);

        [, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Suite sourcee [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame([$this->visibleDossier->id], $this->search->lastCall['dossierIds']);
        $this->assertSame($this->organization->id, $this->search->lastCall['organizationId']);

        $aiReply = LoopMessage::query()->where('type', 'ai')->where('body', 'Suite sourcee [S1].')->sole();
        foreach ($aiReply->metadata['sources'] as $source) {
            $this->assertSame(['ref', 'title', 'dossier_name', 'excerpt', 'url'], array_keys($source));
        }
        $this->assertStringNotContainsString('chunk_id', json_encode($aiReply->metadata));
    }

    // =====================================================================
    // Echecs et refus : le message humain reste, rien de faux n'est publie,
    // l'etranger est refuse AVANT tout appel.
    // =====================================================================

    public function test_a_provider_failure_keeps_the_continuation_message_and_publishes_no_fake_answer(): void
    {
        [, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake(function (): TextResponse {
            throw new \RuntimeException('provider down');
        });

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage')
            ->assertHasErrors(['body']);

        // La continuation humaine est CONSERVEE — elle a ete envoyee, l'IA
        // seule a echoue — et aucune fausse reponse n'apparait.
        $continuation = LoopMessage::query()->where('reply_to_id', $answer->id)->sole();
        $this->assertSame('user', $continuation->type);
        $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame('failed', AiInteraction::sole()->metadata['status']);
    }

    public function test_a_stranger_from_another_organization_cannot_trigger_a_continuation(): void
    {
        [, $answer] = $this->seedExchange();
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->stranger);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', self::CONTINUATION)
            ->call('sendMessage');

        // Refus AVANT tout appel : rien d'ecrit, rien de cherche, rien de
        // depense.
        $this->assertSame(2, LoopMessage::count());
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
    }

    // =====================================================================
    // Arbitrage Cyril 24/08 — /ia envoye EN REPONSE a un message IA : la
    // branche /ia gagne (une seule invocation), le contexte de fil est
    // construit quand meme. DECIDE, pas incident : si l'elseif est refactore
    // dans six mois, ce test dit ce qui avait ete choisi.
    // =====================================================================

    public function test_slash_ia_in_reply_to_an_ai_message_invokes_once_with_thread_context(): void
    {
        [, $answer] = $this->seedExchange('Premiere question sur l emergence ?', 'Premiere reponse documentee [S1].');
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Reponse combinee [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $answer->id)
            ->set('body', '/ia Une nouvelle question dans le meme fil ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        // UNE invocation, marquee /ia (la branche explicite gagne).
        $trigger = LoopMessage::query()->where('reply_to_id', $answer->id)->where('type', 'user')->sole();
        $this->assertSame('/ia Une nouvelle question dans le meme fil ?', $trigger->body);
        $this->assertTrue((bool) ($trigger->metadata['slash_ia'] ?? false));

        $aiReply = LoopMessage::query()->where('type', 'ai')->where('reply_to_id', $trigger->id)->sole();
        $this->assertSame('slash_ia', $aiReply->metadata['action']);

        // Le contexte de fil est construit quand meme : le trigger porte le
        // reply — sur-ensemble inoffensif, donc voulu.
        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('Premiere reponse documentee [S1].', $prompt->prompt);
            $this->assertStringContainsString("Question du membre :\nUne nouvelle question dans le meme fil ?", $prompt->prompt);
            $this->assertStringNotContainsString('/ia', $prompt->prompt);

            return true;
        });

        $this->assertSame(1, $this->search->calls);
        $this->assertDatabaseCount('ai_provider_invocations', 1);
        $this->assertDatabaseCount('ai_interactions', 1);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Un echange deja present dans le fil : la question d'un membre et la
     * reponse IA liee, telles que T-3 les ecrit (metadata comprises),
     * horodatees dans le passe pour un ordre stable.
     *
     * @return array{0: LoopMessage, 1: LoopMessage}
     */
    private function seedExchange(
        string $questionBody = 'Qu avons-nous appris sur l emergence ?',
        string $answerBody = 'Nous avons appris ceci [S1].',
    ): array {
        $question = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'reply_to_id' => null,
            'body' => $questionBody,
            'type' => 'user',
            'metadata' => ['slash_ia' => true],
            'organization_id' => $this->loop->organization_id,
        ]);
        $question->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();

        $answer = $this->aiMessage($this->loop, $answerBody, $question->id, minutesAgo: 29);

        return [$question, $answer];
    }

    private function aiMessage(Loop $loop, string $body, ?string $replyToId = null, int $minutesAgo = 20): LoopMessage
    {
        $message = LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => null,
            'reply_to_id' => $replyToId,
            'body' => $body,
            'type' => 'ai',
            'metadata' => [
                'requested_by' => $this->member->id,
                'action' => 'slash_ia',
                'question' => 'Qu avons-nous appris sur l emergence ?',
                'grounded' => true,
                'sources' => [],
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'ai_interaction_id' => (string) Str::uuid(),
            ],
            'organization_id' => $loop->organization_id,
        ]);
        $message->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

        return $message;
    }

    /**
     * Une chaine de reponses alternant membre et IA, reliee par reply_to_id,
     * du Maillon 01 (humain) au Maillon N (IA pour N pair) : le banc de la
     * borne de profondeur.
     */
    private function replyChain(int $length): LoopMessage
    {
        $previous = null;

        for ($i = 1; $i <= $length; $i++) {
            $body = sprintf('Maillon %02d', $i);

            if ($i % 2 === 1) {
                $message = LoopMessage::create([
                    'loop_id' => $this->loop->id,
                    'sender_id' => $this->member->id,
                    'reply_to_id' => $previous?->id,
                    'body' => $body,
                    'type' => 'user',
                    'metadata' => null,
                    'organization_id' => $this->loop->organization_id,
                ]);
                $message->forceFill(['created_at' => now()->subMinutes(100 - $i)])->saveQuietly();
            } else {
                $message = $this->aiMessage($this->loop, $body, $previous?->id, minutesAgo: 100 - $i);
            }

            $previous = $message;
        }

        return $previous;
    }

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

    private function textResponse(string $text): TextResponse
    {
        return new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([$this->textResponse($text)]);
    }
}

/**
 * Double du moteur pgvector (contrat TASK1213/TASK1297), avec un compteur
 * d'appels : la preuve « une recherche et une seule » des tests economiques.
 */
class FakeContinuationSearch extends DossierSemanticSearchService
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
