<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestShellLimitReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1434 — SW-4 : GuestConversation + GuestMessage, la memoire produit du
 * Shell Welcome (Addendum V2 §7-8, cadre Cyril 21h50 §4, MASTER Q54/Q55).
 *
 * Ce qui est mesure :
 * 1. reprise de la conversation la plus recente de CE visiteur dans CETTE
 *    Organization, jamais celle d'une autre Organization ni d'un autre visiteur ;
 * 2. `message_count` = messages `role=user` acceptes, jamais les reponses ;
 * 3. `max_messages` (politique de l'Organization) s'applique AVANT tout appel :
 *    le message N+1 est refuse, non enregistre, la conversation passe en
 *    `limit_reached` et reste lisible ; une nouvelle conversation reste possible ;
 * 4. borne d'entree = l'autorite `ai.shell.max_input_chars`, cote serveur ;
 * 5. les messages sont append-only ; un message assistant pointe vers son
 *    invocation provider (autorite economique), rien dans `ai_interactions` ;
 * 6. le compteur transverse du visiteur (toutes conversations) existe pour SW-6 ;
 * 7. supprimer le visiteur (retention) emporte conversations et messages ;
 *    la FK `claimed_user_id` est classee dans le registre de cycle de vie.
 */
class TASK1434GuestConversationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private GuestVisitor $visitorA;

    private GuestVisitor $visitorA2;

    private GuestVisitor $visitorB;

    private GuestConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1434', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1434', 'is_active' => true, 'is_public' => true, 'locale' => 'en']);
        $key = Str::random(64);
        $resolver = app()->make(GuestVisitorResolver::class);
        $this->visitorA = $resolver->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $key]), $this->orgA, ['locale' => 'fr']);
        $this->visitorB = $resolver->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $key]), $this->orgB, ['locale' => 'en']);
        $this->visitorA2 = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET'), $this->orgA);
        $this->conversations = app(GuestConversationService::class);
        app(GuestShellPolicyService::class)->update($this->orgA, ['enabled' => true, 'max_messages' => 3]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    // ── 1. Reprise : la plus recente, ce visiteur, cette Organization ───────

    public function test_resume_returns_the_most_recent_conversation_of_this_visitor_in_this_organization_only(): void
    {
        $this->assertNull($this->conversations->resume($this->visitorA));

        $first = $this->conversations->start($this->visitorA, ['source' => 'shortcut', 'source_ref' => 'atelier-ia']);
        $this->assertSame($this->orgA->id, $first->organization_id);
        $this->assertSame('fr', $first->locale);
        $this->assertSame('shortcut', $first->source);
        $this->assertSame(GuestConversation::STATUS_ACTIVE, $first->status);
        $this->assertSame(0, $first->message_count);

        $this->conversations->acceptUserMessage($first, 'Bonjour');
        $this->travel(1)->hour();
        $second = $this->conversations->start($this->visitorA);
        $this->conversations->acceptUserMessage($second, 'Re-bonjour');

        $this->assertSame($second->id, $this->conversations->resume($this->visitorA)?->id, 'la plus recente par dernier message');
        $this->assertSame($second->id, $this->conversations->resumeOrStart($this->visitorA)->id);

        // Meme cookie, autre Organization : rien a reprendre — jamais la conversation de A.
        $this->assertNull($this->conversations->resume($this->visitorB));
        $inB = $this->conversations->resumeOrStart($this->visitorB);
        $this->assertSame($this->orgB->id, $inB->organization_id);
        $this->assertNotSame($second->id, $inB->id);
        // Autre visiteur de la meme Organization : rien a reprendre non plus.
        $this->assertNull($this->conversations->resume($this->visitorA2));

        // Une conversation fermee ne se reprend pas, et n'accepte plus rien — meme sous la limite.
        $this->conversations->close($second);
        $this->assertSame($first->id, $this->conversations->resume($this->visitorA)?->id);
        try {
            $this->conversations->acceptUserMessage($second->fresh(), 'Encore ?');
            $this->fail('une conversation fermee ne doit rien accepter');
        } catch (GuestShellLimitReached $e) {
            $this->assertSame('conversation_not_active', $e->reason);
        }
        $this->assertSame(1, $second->fresh()->message_count);
    }

    // ── 2 & 3. Limite AVANT tout appel, N+1 refuse, conversation preservee ──

    public function test_max_messages_counts_accepted_user_messages_only_and_refuses_the_next_one_before_any_call(): void
    {
        $conversation = $this->conversations->start($this->visitorA);

        $this->conversations->acceptUserMessage($conversation, 'Un');
        $this->conversations->recordAssistantMessage($conversation, 'Reponse un');
        $this->conversations->recordAssistantMessage($conversation, 'Note de service', null, GuestMessage::ROLE_SYSTEM);
        $this->conversations->acceptUserMessage($conversation, 'Deux');
        $this->assertSame(2, $conversation->fresh()->message_count, 'les reponses ne comptent pas');
        $this->assertSame(1, $this->conversations->remainingUserMessages($conversation->fresh()));
        $this->assertSame(GuestConversation::STATUS_ACTIVE, $conversation->fresh()->status);

        $this->conversations->acceptUserMessage($conversation, 'Trois');
        $this->assertSame(3, $conversation->fresh()->message_count);
        $this->assertSame(GuestConversation::STATUS_LIMIT_REACHED, $conversation->fresh()->status, 'a la limite, la conversation le dit');
        $this->assertSame(0, $this->conversations->remainingUserMessages($conversation->fresh()));

        try {
            $this->conversations->acceptUserMessage($conversation->fresh(), 'Quatre');
            $this->fail('le message N+1 doit etre refuse');
        } catch (GuestShellLimitReached $e) {
            $this->assertSame($conversation->id, $e->conversation->id);
        }

        $fresh = $conversation->fresh();
        $this->assertSame(3, $fresh->message_count);
        $this->assertSame(GuestConversation::STATUS_LIMIT_REACHED, $fresh->status);
        $this->assertSame(5, $fresh->messages()->count(), 'rien n\'est perdu, rien n\'est ajoute');
        $this->assertSame(0, GuestMessage::where('body', 'Quatre')->count());
        $this->assertSame(0, AiProviderInvocation::count(), 'aucun appel provider n\'a jamais ete tente');

        // Une NOUVELLE conversation reste possible (MASTER Q54) ; le reset economique est l'affaire de SW-6.
        $next = $this->conversations->start($this->visitorA);
        $this->conversations->acceptUserMessage($next, 'Nouveau depart');
        $this->assertSame(1, $next->fresh()->message_count);
        $this->assertSame(4, $this->conversations->userMessagesThisMonth($this->visitorA), 'le compteur transverse ne repart pas de zero');
        $this->assertSame(0, $this->conversations->userMessagesThisMonth($this->visitorB));
    }

    public function test_the_limit_follows_the_organization_policy_and_a_new_policy_applies_to_the_next_message(): void
    {
        $conversation = $this->conversations->start($this->visitorA);
        $this->conversations->acceptUserMessage($conversation, 'Un');
        $this->conversations->acceptUserMessage($conversation, 'Deux');

        app(GuestShellPolicyService::class)->update($this->orgA, ['max_messages' => 2]);
        $this->assertSame(0, $this->conversations->remainingUserMessages($conversation->fresh()));
        $this->expectException(GuestShellLimitReached::class);
        $this->conversations->acceptUserMessage($conversation->fresh(), 'Trois');
    }

    // ── 4. Borne d'entree : l'autorite du Shell, cote serveur ───────────────

    public function test_the_input_bound_is_the_shell_authority_and_is_enforced_server_side(): void
    {
        config(['ai.shell.max_input_chars' => 50]);
        $this->assertSame(50, GuestMessage::maxUserBodyLength());
        $conversation = $this->conversations->start($this->visitorA);

        $this->conversations->acceptUserMessage($conversation, str_repeat('a', 50));
        $this->assertSame(1, $conversation->fresh()->message_count);

        foreach (['', '   ', str_repeat('b', 51)] as $bad) {
            try {
                $this->conversations->acceptUserMessage($conversation->fresh(), $bad);
                $this->fail('corps hors borne accepte : ['.mb_strlen($bad).']');
            } catch (LogicException $e) {
                $this->assertStringContainsString('1 to 50', $e->getMessage());
            }
        }
        $this->assertSame(1, $conversation->fresh()->message_count);
        $this->assertSame(1, $conversation->fresh()->messages()->count());
    }

    // ── 5. Append-only ; l'invocation provider est la reference economique ───

    public function test_messages_are_append_only_and_an_assistant_message_points_to_its_provider_invocation(): void
    {
        $conversation = $this->conversations->start($this->visitorA);
        $user = $this->conversations->acceptUserMessage($conversation, 'Bonjour');

        $invocation = AiProviderInvocation::create([
            'organization_id' => $this->orgA->id,
            'process' => 'guest_shell',
            'operation' => 'generation',
            'provider' => 'openrouter',
            'model' => 'gpt-4o-mini',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'provider_cost' => 0.0001,
            'currency' => 'USD',
        ]);
        $answer = $this->conversations->recordAssistantMessage($conversation, 'Bienvenue !', $invocation->id);

        $this->assertSame($invocation->id, $answer->fresh()->ai_provider_invocation_id);
        $this->assertSame($invocation->id, $answer->invocation?->id);
        $this->assertNull($user->ai_provider_invocation_id);
        $this->assertSame(['user', 'assistant'], $conversation->fresh()->messages->pluck('role')->all());
        $this->assertFalse(Schema::hasColumn('guest_messages', 'ai_interaction_id'), 'le Guest n\'ecrit pas dans ai_interactions');

        $this->expectException(LogicException::class);
        $user->update(['body' => 'reecrit']);
    }

    // ── 7. La retention emporte tout ; la FK vers users est classee ─────────

    public function test_deleting_the_visitor_cascades_to_its_conversations_and_messages_and_the_claim_link_is_registered(): void
    {
        $conversation = $this->conversations->start($this->visitorA);
        $this->conversations->acceptUserMessage($conversation, 'Bonjour');
        $other = $this->conversations->start($this->visitorA2);
        $this->conversations->acceptUserMessage($other, 'Autre');

        $this->visitorA->delete();

        $this->assertNull(GuestConversation::find($conversation->id));
        $this->assertSame(0, GuestMessage::where('guest_conversation_id', $conversation->id)->count());
        $this->assertNotNull(GuestConversation::find($other->id), 'l\'autre visiteur garde sa conversation');

        $registry = file_get_contents(base_path('app/Services/UserDataLifecycleRegistry.php'));
        $this->assertStringContainsString("'table' => 'guest_conversations', 'column' => 'claimed_user_id'", $registry);
    }
}
