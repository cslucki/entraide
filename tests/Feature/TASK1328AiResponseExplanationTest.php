<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiInteractionFeedback;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChatLoop\AiResponseExplanationService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1328 — « Pourquoi cette réponse ? » (Premium-2 / AI Quality V1).
 *
 * Ce que ces tests protègent :
 *
 *  - la provenance affichée est EXACTEMENT celle que le pipeline a
 *    enregistrée à la génération — jamais une reconstruction : une trace
 *    absente, introuvable ou incohérente donne un gap dit, pas une
 *    interprétation ;
 *  - une réponse LLM pure n'affiche AUCUNE source documentaire fictive ;
 *  - une source citée dont le Dossier n'est plus accessible au spectateur
 *    est masquée (comptée en agrégat), sans titre ni identifiant, sans 500 ;
 *  - le panneau est invisible hors tenant et hors adhésion active — la
 *    revalidation a lieu À L'AFFICHAGE, pas seulement au chargement de la
 *    page ; l'`organization_id` du ledger (sans FK) ne prouve jamais un
 *    droit ;
 *  - `prompt`/`response` bruts du ledger ne sont JAMAIS rendus ;
 *  - une source de contexte refusée à la génération se voit (agrégat),
 *    mais sa raison technique n'est pas rendue ;
 *  - ouvrir le panneau n'écrit RIEN ; le feedback est un verdict humain
 *    explicite (TASK-1256), un par personne, remplacé s'il est redonné.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1328AiResponseExplanationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    /** Même Organization, PAS membre de la Boucle. */
    private User $outsider;

    private User $stranger;

    private Loop $loop;

    private Dossier $rootDossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'LaunchPals', 'slug' => 'launchpals']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Autre Org', 'slug' => 'autre-org']);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->outsider = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->owner, 'Boucle explication');
        $loopService->addMember($this->loop, $this->member, 'member');

        $this->rootDossier = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        config(['ai.chatloop.enabled' => true]);
        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. LLM pur : provenance exacte, aucune source documentaire fictive.
    // =====================================================================

    public function test_llm_bubble_shows_thread_context_and_no_rag_sources(): void
    {
        $context = $this->threadMessages(2);
        $interaction = $this->interaction('loop_ask', [
            'provenance' => ['conversation.thread' => array_map(fn (LoopMessage $m) => (string) $m->id, $context)],
        ]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id, question: 'Que retenir ?');

        $panel = $this->explain($message, $this->member);

        $this->assertNotNull($panel);
        $this->assertSame('loop_ask', $panel['ledger']['capability']);
        $this->assertSame(__('ai.capability_label.loop_ask'), $panel['ledger']['capability_label']);
        $this->assertSame(['used_count' => 2, 'hidden_count' => 0], $panel['ledger']['conversation']);
        // L'AC « LLM sans RAG => pas de fausse source RAG » : la section
        // documentaire dit explicitement qu'elle ne s'applique pas.
        $this->assertSame(['applies' => false], $panel['ledger']['documents']);
        $this->assertSame(0, $panel['ledger']['denied_count']);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.why_action'))
            ->call('showWhy', $message->id)
            ->assertSet('whyMessageId', $message->id)
            ->assertSee(__('loops.why_documents_none'))
            ->assertDontSee(__('loops.why_documents_masked'));
    }

    public function test_the_loop_messages_provenance_form_is_read_too(): void
    {
        // Le second site d'écriture LLM (`generateDirectAnswer`) trace sous
        // `provenance['loop.messages']` + sources_used/sources_denied.
        $context = $this->threadMessages(3);
        $interaction = $this->interaction('loop_answer', [
            'provenance' => ['loop.messages' => array_map(fn (LoopMessage $m) => (string) $m->id, $context)],
            'sources_used' => ['loop.messages'],
            'sources_denied' => [],
        ]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $panel = $this->explain($message, $this->member);

        $this->assertSame(['used_count' => 3, 'hidden_count' => 0], $panel['ledger']['conversation']);
        $this->assertSame(['applies' => false], $panel['ledger']['documents']);
    }

    public function test_a_thread_message_deleted_since_generation_is_counted_not_detailed(): void
    {
        $context = $this->threadMessages(3);
        $interaction = $this->interaction('loop_ask', [
            'provenance' => ['conversation.thread' => array_map(fn (LoopMessage $m) => (string) $m->id, $context)],
        ]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $context[1]->forceFill(['deleted_at' => now()])->saveQuietly();

        $panel = $this->explain($message, $this->member);

        $this->assertSame(['used_count' => 3, 'hidden_count' => 1], $panel['ledger']['conversation']);
    }

    // =====================================================================
    // B. RAG : sources exactes, revalidées à l'affichage.
    // =====================================================================

    public function test_rag_bubble_shows_exactly_the_cited_sources_from_the_ledger(): void
    {
        $interaction = $this->interaction('loop_knowledge_answer', [
            'retrieval' => [
                'consulted' => [
                    $this->retrievalEntry('c1', $this->rootDossier->id),
                    $this->retrievalEntry('c2', $this->rootDossier->id),
                    $this->retrievalEntry('c3', $this->rootDossier->id),
                ],
                'cited' => [
                    $this->retrievalEntry('c1', $this->rootDossier->id),
                    $this->retrievalEntry('c2', $this->rootDossier->id),
                ],
            ],
        ]);
        $message = $this->aiMessage('rag', interactionId: $interaction->id, sources: [
            ['ref' => 'S1', 'title' => 'Compte-rendu de mai', 'dossier_name' => 'Boucle explication', 'excerpt' => null, 'url' => null],
            ['ref' => 'S2', 'title' => 'Charte de la Boucle', 'dossier_name' => 'Boucle explication', 'excerpt' => null, 'url' => null],
        ], contextMessageIds: []);

        $panel = $this->explain($message, $this->member);

        $documents = $panel['ledger']['documents'];
        $this->assertTrue($documents['applies']);
        $this->assertSame(2, $documents['cited_count']);
        $this->assertSame(3, $documents['consulted_count']);
        $this->assertSame(0, $documents['masked_count']);
        $this->assertSame(
            [['ref' => 'S1', 'title' => 'Compte-rendu de mai', 'dossier_name' => 'Boucle explication'],
                ['ref' => 'S2', 'title' => 'Charte de la Boucle', 'dossier_name' => 'Boucle explication']],
            $documents['entries'],
        );
    }

    public function test_a_cited_source_whose_dossier_became_inaccessible_is_masked(): void
    {
        $foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $interaction = $this->interaction('loop_knowledge_answer', [
            'retrieval' => [
                'consulted' => [
                    $this->retrievalEntry('c1', $this->rootDossier->id),
                    $this->retrievalEntry('c2', $foreignDossier->id),
                ],
                'cited' => [
                    $this->retrievalEntry('c1', $this->rootDossier->id),
                    $this->retrievalEntry('c2', $foreignDossier->id),
                ],
            ],
        ]);
        $message = $this->aiMessage('rag', interactionId: $interaction->id, sources: [
            ['ref' => 'S1', 'title' => 'Document encore accessible', 'dossier_name' => 'Boucle explication', 'excerpt' => null, 'url' => null],
            ['ref' => 'S2', 'title' => 'TITRE_HORS_TENANT', 'dossier_name' => 'Ailleurs', 'excerpt' => null, 'url' => null],
        ], contextMessageIds: []);

        $panel = $this->explain($message, $this->member);
        $documents = $panel['ledger']['documents'];

        $this->assertSame(2, $documents['cited_count']);
        $this->assertSame(1, $documents['masked_count']);
        $this->assertSame([['ref' => 'S1', 'title' => 'Document encore accessible', 'dossier_name' => 'Boucle explication']], $documents['entries']);

        // Et dans le PANNEAU rendu, le titre masqué n'apparaît pas : seules
        // les entrées revalidées sont listées, le reste est un agrégat.
        $titles = array_column($documents['entries'], 'title');
        $this->assertNotContains('TITRE_HORS_TENANT', $titles);
    }

    public function test_a_dossier_deleted_since_generation_masks_its_source_without_crashing(): void
    {
        $interaction = $this->interaction('loop_knowledge_answer', [
            'retrieval' => [
                'consulted' => [$this->retrievalEntry('c1', $this->rootDossier->id)],
                'cited' => [$this->retrievalEntry('c1', $this->rootDossier->id)],
            ],
        ]);
        $message = $this->aiMessage('rag', interactionId: $interaction->id, sources: [
            ['ref' => 'S1', 'title' => 'Document du Dossier supprimé', 'dossier_name' => 'Boucle explication', 'excerpt' => null, 'url' => null],
        ], contextMessageIds: []);

        $this->rootDossier->forceFill(['deleted_at' => now()])->saveQuietly();

        $panel = $this->explain($message, $this->member);
        $documents = $panel['ledger']['documents'];

        $this->assertSame(1, $documents['cited_count']);
        $this->assertSame(1, $documents['masked_count']);
        $this->assertSame([], $documents['entries']);
    }

    public function test_an_unpairable_trace_shows_counts_only_never_a_guess(): void
    {
        // Longueurs public/ledger divergentes : l'appariement positionnel
        // n'est plus prouvable, donc AUCUN titre — les comptes du ledger
        // restent, le reste est masqué.
        $interaction = $this->interaction('loop_knowledge_answer', [
            'retrieval' => [
                'consulted' => [$this->retrievalEntry('c1', $this->rootDossier->id)],
                'cited' => [
                    $this->retrievalEntry('c1', $this->rootDossier->id),
                    $this->retrievalEntry('c2', $this->rootDossier->id),
                ],
            ],
        ]);
        $message = $this->aiMessage('rag', interactionId: $interaction->id, sources: [
            ['ref' => 'S1', 'title' => 'Seule entrée publique', 'dossier_name' => null, 'excerpt' => null, 'url' => null],
        ], contextMessageIds: []);

        $panel = $this->explain($message, $this->member);
        $documents = $panel['ledger']['documents'];

        $this->assertSame(2, $documents['cited_count']);
        $this->assertSame([], $documents['entries']);
        $this->assertSame(2, $documents['masked_count']);
    }

    // =====================================================================
    // C. La trace fait foi — jamais une reconstruction.
    // =====================================================================

    public function test_a_bubble_without_ledger_link_shows_an_honest_gap(): void
    {
        $message = $this->aiMessage('llm', question: 'Une bulle d\'avant le ledger ?');

        $panel = $this->explain($message, $this->member);

        $this->assertNotNull($panel);
        $this->assertNull($panel['ledger']);
        $this->assertFalse($panel['can_feedback']);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $message->id)
            ->assertSet('whyMessageId', $message->id)
            ->assertSee(__('loops.why_trace_unavailable'));
    }

    public function test_an_incoherent_trace_is_treated_as_absent(): void
    {
        // Une interaction d'une AUTRE Organization, référencée par une bulle
        // forgée : la jointure existe mais les ancres posées par le pipeline
        // ne collent pas — la trace n'est pas affichée.
        $foreign = AiInteraction::create([
            'user_id' => $this->stranger->id,
            'organization_id' => $this->otherOrganization->id,
            'process' => 'chatloop',
            'feature' => 'chatloop_ai_ask',
            'model' => 'test-model',
            'prompt' => 'SECRET_FOREIGN_PROMPT',
            'response' => 'reponse',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'metadata' => [
                'loop_id' => 'une-autre-boucle',
                'capability' => 'loop_ask',
                'provenance' => ['conversation.thread' => []],
            ],
        ]);
        $message = $this->aiMessage('llm', interactionId: $foreign->id);

        $panel = $this->explain($message, $this->member);

        $this->assertNull($panel['ledger']);

        // Même Organization mais une AUTRE Boucle dans la trace : refusée
        // aussi — la cohérence exige les deux ancres.
        $wrongLoop = $this->interaction('loop_ask', ['provenance' => ['conversation.thread' => []]], loopId: 'ailleurs');
        $message2 = $this->aiMessage('llm', interactionId: $wrongLoop->id);

        $this->assertNull($this->explain($message2, $this->member)['ledger']);
    }

    public function test_an_unknown_capability_form_is_a_gap_not_a_generic_read(): void
    {
        $interaction = $this->interaction('loop_decision_suggestion', [
            'decision_suggestion' => ['provenance' => ['verified' => ['x'], 'ai_wording' => []]],
        ]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $panel = $this->explain($message, $this->member);

        $this->assertNull($panel['ledger'], 'une forme de trace inconnue du lecteur est un gap, jamais une interprétation');
    }

    // =====================================================================
    // D. Cloisonnement : tenant, adhésion, bulle vivante.
    // =====================================================================

    public function test_cross_organization_and_non_members_get_nothing(): void
    {
        $interaction = $this->interaction('loop_ask', ['provenance' => ['conversation.thread' => []]]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $this->assertNull($this->explain($message, $this->stranger), 'autre Organization : rien');
        $this->assertNull($this->explain($message, $this->outsider), 'même Organization mais pas membre : rien');
        $this->assertNotNull($this->explain($message, $this->member));
    }

    public function test_a_deleted_bubble_and_a_human_message_have_no_panel(): void
    {
        $interaction = $this->interaction('loop_ask', ['provenance' => ['conversation.thread' => []]]);
        $deleted = $this->aiMessage('llm', interactionId: $interaction->id);
        $deleted->forceFill(['deleted_at' => now()])->saveQuietly();

        $human = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message humain.',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->assertNull($this->explain($deleted->fresh(), $this->member));
        $this->assertNull($this->explain($human, $this->member));

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $human->id)
            ->assertSet('whyMessageId', null);
    }

    // =====================================================================
    // E. Lignes rouges d'affichage.
    // =====================================================================

    public function test_ledger_prompt_and_response_are_never_rendered(): void
    {
        $context = $this->threadMessages(1);
        $interaction = $this->interaction('loop_ask', [
            'provenance' => ['conversation.thread' => [(string) $context[0]->id]],
        ], prompt: 'SECRET_PROMPT_MARKER_1328', response: 'SECRET_RAW_RESPONSE_1328');
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $message->id)
            ->assertSet('whyMessageId', $message->id)
            ->assertDontSee('SECRET_PROMPT_MARKER_1328')
            ->assertDontSee('SECRET_RAW_RESPONSE_1328');
    }

    public function test_denied_context_sources_are_counted_without_their_technical_reason(): void
    {
        $interaction = $this->interaction('loop_answer', [
            'provenance' => ['loop.messages' => []],
            'sources_used' => [],
            'sources_denied' => ['loop.messages' => 'RAISON_TECHNIQUE_1328'],
        ]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $panel = $this->explain($message, $this->member);
        $this->assertSame(1, $panel['ledger']['denied_count']);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $message->id)
            ->assertSee(trans_choice('loops.why_denied', 1))
            ->assertDontSee('RAISON_TECHNIQUE_1328');
    }

    // =====================================================================
    // F. Aucune action durable à l'ouverture ; feedback = verdict humain.
    // =====================================================================

    public function test_opening_the_panel_writes_nothing(): void
    {
        $interaction = $this->interaction('loop_ask', ['provenance' => ['conversation.thread' => []]]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $interactionsBefore = AiInteraction::query()->count();
        $messagesBefore = LoopMessage::query()->count();

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $message->id)
            ->assertSet('whyMessageId', $message->id)
            ->call('closeWhy')
            ->assertSet('whyMessageId', null);

        $this->assertSame(0, AiInteractionFeedback::query()->count());
        $this->assertSame($interactionsBefore, AiInteraction::query()->count());
        $this->assertSame($messagesBefore, LoopMessage::query()->count());
    }

    public function test_feedback_is_an_explicit_human_verdict_one_per_person(): void
    {
        $interaction = $this->interaction('loop_ask', ['provenance' => ['conversation.thread' => []]]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);

        $this->actingAs($this->member);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $message->id)
            ->call('submitWhyFeedback', 'helpful');

        $feedback = AiInteractionFeedback::query()->sole();
        $this->assertSame($interaction->id, $feedback->ai_interaction_id);
        $this->assertSame($this->member->id, $feedback->user_id);
        $this->assertSame(AiInteractionFeedback::VERDICT_HELPFUL, $feedback->verdict);
        $this->assertSame($this->organization->id, $feedback->organization_id);

        // Redonné : REMPLACÉ, jamais dupliqué.
        $component->call('submitWhyFeedback', 'improve');
        $feedback = AiInteractionFeedback::query()->sole();
        $this->assertSame(AiInteractionFeedback::VERDICT_IMPROVE, $feedback->verdict);

        // Un verdict hors liste n'écrit rien.
        $component->call('submitWhyFeedback', 'training_consent');
        $this->assertSame(1, AiInteractionFeedback::query()->count());
    }

    public function test_feedback_is_refused_without_a_trusted_trace_or_membership(): void
    {
        $service = app(AiResponseExplanationService::class);

        $withoutTrace = $this->aiMessage('llm');
        $this->assertFalse($service->submitFeedback($this->loop, $withoutTrace, $this->member, 'helpful'));

        $interaction = $this->interaction('loop_ask', ['provenance' => ['conversation.thread' => []]]);
        $message = $this->aiMessage('llm', interactionId: $interaction->id);
        $this->assertFalse($service->submitFeedback($this->loop, $message, $this->outsider, 'helpful'));
        $this->assertFalse($service->submitFeedback($this->loop, $message, $this->stranger, 'helpful'));

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    // =====================================================================
    // Helpers.
    // =====================================================================

    private function explain(LoopMessage $message, User $viewer): ?array
    {
        return app(AiResponseExplanationService::class)->explain($this->loop, $message, $viewer);
    }

    /**
     * @return list<LoopMessage>
     */
    private function threadMessages(int $count): array
    {
        $messages = [];

        for ($i = 0; $i < $count; $i++) {
            $messages[] = LoopMessage::create([
                'loop_id' => $this->loop->id,
                'sender_id' => $this->member->id,
                'body' => 'Message de contexte '.$i,
                'type' => 'user',
                'organization_id' => $this->loop->organization_id,
            ]);
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function interaction(string $capability, array $metadata, ?string $loopId = null, string $prompt = 'prompt interne', string $response = 'reponse'): AiInteraction
    {
        return AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'process' => 'chatloop',
            'feature' => $capability,
            'model' => 'test-model',
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => 10,
            'output_tokens' => 10,
            'metadata' => [
                'loop_id' => $loopId ?? (string) $this->loop->id,
                'requested_by' => $this->member->id,
                'capability' => $capability,
                ...$metadata,
            ],
        ]);
    }

    /**
     * @return array{chunk_id: string, dossier_id: string, blog_post_id: null}
     */
    private function retrievalEntry(string $chunkId, string $dossierId): array
    {
        return ['chunk_id' => $chunkId, 'dossier_id' => (string) $dossierId, 'blog_post_id' => null];
    }

    /**
     * @param  list<array<string, mixed>>|null  $sources
     * @param  list<string>|null  $contextMessageIds
     */
    private function aiMessage(
        string $aiMode,
        ?string $interactionId = null,
        ?string $question = null,
        ?array $sources = null,
        ?array $contextMessageIds = null,
    ): LoopMessage {
        return LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Réponse IA à expliquer.',
            'type' => 'ai',
            'metadata' => array_filter([
                'ai_mode' => $aiMode,
                'requested_by' => $this->member->id,
                'question' => $question,
                'sources' => $sources,
                'context_message_ids' => $contextMessageIds,
                'ai_interaction_id' => $interactionId,
            ], static fn ($v): bool => $v !== null),
            'organization_id' => $this->loop->organization_id,
        ]);
    }
}
