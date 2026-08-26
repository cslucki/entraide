<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiConversationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Revue TASK-1308 (BLOCKER 2) — couverture directe de
 * `AiConversationContextBuilder`, l'autorite PARTAGEE par les deux moteurs
 * (IA direct et Dossiers) qui a remplace `LoopKnowledgeAnswerService::threadContext()`
 * (TASK-1300). Les proprietes du brief T-4 (bornes de profondeur/caracteres,
 * type non conversationnel, maillon supprime) restent protegees ; generalisees
 * ici a un parent `user` OU `ai` (T-1300 n'acceptait qu'un parent `ai`) et
 * completees par la fuite inter-Boucle qu'un trigger mal forme pourrait
 * emprunter.
 */
#[Group('ai')]
#[Group('sensitive')]
class AiConversationContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private AiConversationContextBuilder $builder;

    private Organization $organization;

    private Loop $loop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new AiConversationContextBuilder;
        $this->organization = Organization::factory()->create();
        $this->loop = Loop::factory()->create(['organization_id' => $this->organization->id]);
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    // =====================================================================
    // A. Profondeur — MAX_THREAD_DEPTH = 6.
    // =====================================================================

    public function test_only_the_six_authorized_messages_enter_the_context(): void
    {
        // Chaine de 10 maillons (au-dela de la borne) : Maillon 01 (humain,
        // racine) ... Maillon 10 (le trigger, non compris dans son propre
        // contexte). Le trigger repond a Maillon 09 ; MAX_THREAD_DEPTH=6
        // remonte donc Maillon 09..Maillon 04 — Maillon 03 et plus anciens
        // n'entrent JAMAIS dans le texte, meme sous le budget caracteres.
        $chain = $this->replyChain(9);
        $trigger = $this->message('user', $chain, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertStringContainsString('Maillon 09', $context->text);
        $this->assertStringContainsString('Maillon 04', $context->text);
        $this->assertStringNotContainsString('Maillon 03', $context->text);
        $this->assertStringNotContainsString('Maillon 02', $context->text);
        $this->assertStringNotContainsString('Maillon 01', $context->text);
        $this->assertCount(6, $context->messageIds);
    }

    public function test_a_short_chain_under_the_depth_bound_is_kept_entirely(): void
    {
        $chain = $this->replyChain(3);
        $trigger = $this->message('user', $chain, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertStringContainsString('Maillon 01', $context->text);
        $this->assertStringContainsString('Maillon 03', $context->text);
        $this->assertCount(3, $context->messageIds);
    }

    // =====================================================================
    // B. Borne caracteres — `ai.chatloop.max_context_chars`.
    // =====================================================================

    public function test_the_character_budget_keeps_the_direct_parent_and_drops_older_messages(): void
    {
        config(['ai.chatloop.max_context_chars' => 40]);

        $long = str_repeat('X', 60);
        $root = $this->message('user', null, $long);
        $parent = $this->message('ai', $root, 'Reponse courte.');
        $trigger = $this->message('user', $parent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        // Le parent DIRECT est TOUJOURS conserve, tronque si besoin — meme
        // seul, meme si son propre contenu depasse le budget.
        $this->assertStringContainsString('Reponse courte.', $context->text);
        $this->assertStringNotContainsString($long, $context->text);
        $this->assertSame([$parent->id], $context->messageIds);
    }

    public function test_the_character_budget_truncates_the_direct_parent_when_it_alone_exceeds_it(): void
    {
        config(['ai.chatloop.max_context_chars' => 20]);

        $parent = $this->message('ai', null, str_repeat('Y', 100));
        $trigger = $this->message('user', $parent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $expectedLine = mb_substr('Assistant : '.str_repeat('Y', 100), 0, 20);
        $this->assertSame("Echange precedent dans la Boucle :\n".$expectedLine, $context->text);
        $this->assertSame(20, mb_strlen($expectedLine));
    }

    // =====================================================================
    // C. Reply etranger — jamais suivi hors de la Boucle du declencheur.
    // =====================================================================

    public function test_a_foreign_loop_parent_never_enters_the_context(): void
    {
        $otherLoop = Loop::factory()->create(['organization_id' => $this->organization->id]);
        $foreignParent = $this->message('ai', null, 'Reponse confidentielle d une AUTRE Boucle.', $otherLoop);

        // Trigger mal forme CONSTRUIT DIRECTEMENT (contournant
        // LoopMessageService::sendUserMessage(), qui aurait deja annule ce
        // reply_to_id etranger) : la defense en profondeur du Context
        // Builder lui-meme doit refuser de suivre la chaine.
        $trigger = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->user->id,
            'reply_to_id' => $foreignParent->id,
            'body' => 'Question posee depuis une autre Boucle.',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);
        $trigger->load('replyTo');

        $context = $this->builder->build($trigger);

        $this->assertSame('', $context->text);
        $this->assertSame([], $context->messageIds);
        $this->assertStringNotContainsString('confidentielle', $context->text);
    }

    public function test_a_foreign_loop_message_mid_chain_stops_the_climb(): void
    {
        // Maillon 01 (Boucle etrangere) <- Maillon 02 (CETTE Boucle) <- trigger.
        $otherLoop = Loop::factory()->create(['organization_id' => $this->organization->id]);
        $foreignRoot = $this->message('user', null, 'Racine d une AUTRE Boucle.', $otherLoop);

        $ownParent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'reply_to_id' => $foreignRoot->id,
            'body' => 'Reponse dans CETTE Boucle, repond pourtant a une racine etrangere.',
            'type' => 'ai',
            'organization_id' => $this->loop->organization_id,
        ]);
        $ownParent->load('replyTo');

        $trigger = $this->message('user', $ownParent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertStringContainsString('repond pourtant', $context->text);
        $this->assertStringNotContainsString('Racine d une AUTRE Boucle', $context->text);
        $this->assertSame([$ownParent->id], $context->messageIds);
    }

    // =====================================================================
    // D. Message supprime — son contenu n'entre jamais au transcript.
    // =====================================================================

    public function test_a_deleted_direct_parent_yields_an_empty_context(): void
    {
        $parent = $this->message('ai', null, 'Reponse ensuite supprimee.');
        $parent->forceFill(['deleted_at' => now()])->saveQuietly();
        $trigger = $this->message('user', $parent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertSame('', $context->text);
        $this->assertSame([], $context->messageIds);
    }

    public function test_a_deleted_message_further_up_the_chain_is_skipped_but_the_climb_continues(): void
    {
        $root = $this->message('user', null, 'Question originelle jamais supprimee.');
        $middle = $this->message('ai', $root, 'Reponse intermediaire supprimee.');
        $middle->forceFill(['deleted_at' => now()])->saveQuietly();
        $middle->load('replyTo');
        $parent = $this->message('user', $middle, 'Reply au message supprime.');
        $trigger = $this->message('ai', $parent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertStringContainsString('Reply au message supprime.', $context->text);
        $this->assertStringContainsString('Question originelle jamais supprimee.', $context->text);
        $this->assertStringNotContainsString('Reponse intermediaire supprimee.', $context->text);
        // Le maillon supprime consomme tout de meme un slot de profondeur :
        // seuls DEUX messages (non supprimes) apparaissent en provenance.
        $this->assertCount(2, $context->messageIds);
    }

    // =====================================================================
    // E. Type non conversationnel — n'entre jamais silencieusement au contexte.
    // =====================================================================

    public function test_a_help_request_parent_never_enters_the_context(): void
    {
        $parent = $this->message('help_request', null, 'Demande d aide, pas une question conversationnelle.');
        $trigger = $this->message('user', $parent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertSame('', $context->text);
        $this->assertSame([], $context->messageIds);
    }

    public function test_a_non_conversational_type_mid_chain_stops_the_climb(): void
    {
        $root = $this->message('user', null, 'Question originelle.');
        $pollEvent = $this->message('poll_event', $root, 'Un sondage a ete pose.');
        $parent = $this->message('ai', $pollEvent, 'Reponse au-dela du sondage.');
        $trigger = $this->message('user', $parent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertStringContainsString('Reponse au-dela du sondage.', $context->text);
        $this->assertStringNotContainsString('Un sondage a ete pose.', $context->text);
        $this->assertStringNotContainsString('Question originelle.', $context->text);
        $this->assertSame([$parent->id], $context->messageIds);
    }

    public function test_a_member_agent_parent_never_enters_the_context(): void
    {
        $parent = $this->message('member_agent', null, 'Reponse de l agent du membre.');
        $trigger = $this->message('user', $parent, 'Question du trigger.');

        $context = $this->builder->build($trigger);

        $this->assertSame('', $context->text);
        $this->assertSame([], $context->messageIds);
    }

    // =====================================================================
    // Contexte vide — pas de reply, ou reply a un message deja hors-borne.
    // =====================================================================

    public function test_a_trigger_without_a_reply_yields_an_empty_context(): void
    {
        $trigger = $this->message('user', null, 'Question sans reply.');

        $context = $this->builder->build($trigger);

        $this->assertSame('', $context->text);
        $this->assertSame([], $context->messageIds);
    }

    public function test_a_null_trigger_yields_an_empty_context(): void
    {
        $context = $this->builder->build(null);

        $this->assertSame('', $context->text);
        $this->assertSame([], $context->messageIds);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function message(string $type, ?LoopMessage $replyTo, string $body, ?Loop $loop = null): LoopMessage
    {
        $loop ??= $this->loop;

        $message = LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => in_array($type, ['ai', 'member_agent'], true) ? null : $this->user->id,
            'reply_to_id' => $replyTo?->id,
            'body' => $body,
            'type' => $type,
            'organization_id' => $loop->organization_id,
        ]);

        return $message->load('replyTo');
    }

    /**
     * Une chaine de `$length` maillons alternant humain (impair) et IA
     * (pair), reliee par `reply_to_id`, « Maillon 01 » (racine, sans
     * reply_to) a « Maillon NN » (retourne, tete de chaine).
     */
    private function replyChain(int $length): LoopMessage
    {
        $previous = null;

        for ($i = 1; $i <= $length; $i++) {
            $type = $i % 2 === 1 ? 'user' : 'ai';
            $previous = $this->message($type, $previous, sprintf('Maillon %02d', $i));
        }

        return $previous;
    }
}
