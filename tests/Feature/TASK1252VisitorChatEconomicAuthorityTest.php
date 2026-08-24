<?php

namespace Tests\Feature;

use App\Livewire\AiAgentChat;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ProfileAgentConversation;
use App\Models\ProfileAgentMessage;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1252 — fermer l'autorite economique du chat visiteur PUBLIC de l'agent
 * de profil (gap #15 du gap analysis T1246, G1 CRITICAL) :
 *
 *   route `/profile/{user}/agent-ia` (HORS `auth`) -> Livewire `AiAgentChat::sendMessage()`
 *
 * Decision produit : AUCUN appel provider anonyme paye en silence par la
 * plateforme. Visiteur NON authentifie = refus V1 explicite et humain (aucun
 * appel, aucun ledger, aucune conversation) ; visiteur AUTHENTIFIE = sous
 * autorite economique (`answerUnderEconomicAuthority()`, T1251) avec identite
 * EXPLICITE : tenant = Organization du PROFIL (jamais celle du visiteur,
 * jamais `current_organization`), acteur = credit = le visiteur.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1252VisitorChatEconomicAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** Organization plateforme (`is_default`) — n'est jamais le tenant de ce chemin. */
    private Organization $platform;

    /** Organization du PROFIL : le tenant de record. */
    private Organization $tenant;

    /** Une autre Organization : celle d'un visiteur « d'ailleurs ». */
    private Organization $elsewhere;

    private User $owner;

    /** Membre de la meme Organization que le profil : acteur ET porteur du credit. */
    private User $visitor;

    private MemberAiProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openrouter' => [
                    'router/catalogued' => ['input_per_1m' => 2.0, 'output_per_1m' => 2.0],
                ],
                'ollama' => [
                    '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
                ],
                'rule_based' => [
                    '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
                ],
            ],
            'ai.supervision_resolver.economic_guard.monthly_budget_usd' => 2.00,
            'ai.supervision_resolver.economic_guard.monthly_unknown_limit' => 10,

            'ai.default_provider' => 'openrouter',
            'ai.default_model' => null,

            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'platform-openrouter-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.openrouter.model' => 'router/catalogued',
            'ai.openrouter.timeout' => 15,
            'ai.openrouter.max_output_tokens' => 650,

            'ai.openai.supervision_enabled' => false,
            'ai.ollama.enabled' => false,
        ]);

        $this->platform = Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1252', 'ai_profiles_enabled' => true]);
        $this->tenant = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-1252', 'ai_profiles_enabled' => true]);
        $this->elsewhere = Organization::factory()->create(['is_active' => true, 'slug' => 'elsewhere-1252', 'ai_profiles_enabled' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->tenant->id, 'first_name' => 'Maya', 'preferred_locale' => 'fr']);
        $this->visitor = User::factory()->create(['organization_id' => $this->tenant->id, 'first_name' => 'Theo', 'preferred_locale' => 'fr']);

        app()->instance('current_organization', $this->tenant);
        app()->setLocale('fr');

        $this->profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'skills' => ['SEO', 'Redaction'],
            'service_scope' => 'Audit SEO local',
            'member_profile_summary' => 'Consultante SEO',
        ]);

        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function fakeOpenRouterAnswer(string $text = 'Maya accompagne les audits SEO locaux.', ?array $usage = ['prompt_tokens' => 300, 'completion_tokens' => 150]): void
    {
        Http::fake([
            'openrouter.test/*' => Http::response(array_filter([
                'model' => 'router/catalogued',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
                'usage' => $usage,
            ])),
        ]);
    }

    private function fakeOpenRouterFailure(int $status = 500): void
    {
        Http::fake(['openrouter.test/*' => Http::response(['error' => 'upstream down'], $status)]);
    }

    /** Budget Organization atteint : plafond minuscule + une depense connue ce mois-ci (autre chemin). */
    private function reachOrganizationBudget(Organization $organization, User $spender): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'router/catalogued',
            'api_key' => 'sk-tenant',
            'monthly_budget_usd' => 0.001,
        ]);
        $this->knownSpend($organization, $spender, 'blog.article_generate', 'blog_generate');
    }

    /**
     * T1286 : depense connue au LEDGER canonique — l'autorite de generation
     * des process converges. La garde par process ne lit plus
     * `ai_interactions` pour eux.
     */
    private function knownLedgerSpend(Organization $organization, User $user, string $process, float $cost = 0.5): void
    {
        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'router/catalogued',
            'credential_source' => AiProviderInvocation::CREDENTIAL_PLATFORM,
            'provider_cost' => $cost,
            'currency' => 'USD',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'cost_source' => 'catalog_estimated',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }

    private function knownSpend(Organization $organization, User $user, string $process, string $feature, float $cost = 0.5): void
    {
        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'process' => $process,
            'feature' => $feature,
            'model' => 'openrouter/router/catalogued',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'cost_usd' => $cost,
            'cost_unknown' => false,
            'metadata' => [],
        ]);
    }

    private function assertNothingEconomicWritten(): void
    {
        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Un refus n\'ecrit aucune ligne de ledger.');
        $this->assertSame(0, AdminAiInteraction::query()->count(), 'Un refus n\'ecrit aucune trace admin.');
        $this->assertSame(0, AiInteraction::query()->where('process', 'member_profile.agent_visitor_chat')->count());
    }

    private function conversationOf(User $visitor): ?ProfileAgentConversation
    {
        return ProfileAgentConversation::query()
            ->where('member_ai_profile_id', $this->profile->id)
            ->where('visitor_user_id', $visitor->id)
            ->first();
    }

    // =====================================================================
    // A. Visiteur ANONYME : refus V1 explicite, humain, sans rien d'economique
    // =====================================================================

    public function test_a_guest_is_refused_humanly_without_any_provider_call_ledger_line_or_conversation(): void
    {
        $this->fakeOpenRouterAnswer();

        $component = Livewire::test(AiAgentChat::class, ['user' => $this->owner])
            ->assertSet('authRequired', true)
            ->assertSet('guestRefused', false)
            ->assertSee(__('ai.visitor_chat_guest_notice'))
            ->assertSee(route('login'));

        $this->assertSame(0, ProfileAgentConversation::query()->count(), 'Aucune conversation creee pour un invite au montage.');

        $component->set('question', 'Quelles sont vos competences en SEO ?')
            ->call('sendMessage')
            ->assertSet('error', null)
            ->assertSet('errorCode', null)
            ->assertSet('isTyping', false)
            ->assertSet('guestRefused', true)
            ->assertSet('authRequired', true)
            ->assertSet('visitorTurnCount', 0)
            ->assertSet('question', '')
            ->assertSee(__('ai.visitor_chat_guest_composer_disabled'));

        $messages = $component->get('messages');
        $this->assertCount(3, $messages, 'Accueil + question du visiteur + refus humain de l\'agent.');
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('Quelles sont vos competences en SEO ?', $messages[1]['text']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame(__('ai.visitor_chat_guest_refusal_message', ['member_name' => $this->owner->name]), $messages[2]['text']);
        $this->assertStringNotContainsString('SEO', $messages[2]['text'], 'Pas de reponse rule-based de substitution : l\'agent ne repond pas a un anonyme.');

        $this->assertNothingEconomicWritten();
        $this->assertSame(0, ProfileAgentConversation::query()->count(), 'Rien n\'est persiste pour le visiteur : ni conversation…');
        $this->assertSame(0, ProfileAgentMessage::query()->count(), '… ni message.');

        // Etat visible du proprietaire : UNE trace `refused`, guest, cout non evalue.
        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame(1, MemberAiProfileInteraction::query()->count());
        $this->assertSame(MemberAiProfileInteraction::STATUS_REFUSED, $trace->status);
        $this->assertSame(MemberAiProfileInteraction::VISITOR_TYPE_GUEST, $trace->visitor_type);
        $this->assertNull($trace->visitor_user_id);
        $this->assertSame($this->tenant->id, $trace->organization_id, 'Tenant = Organization du PROFIL.');
        $this->assertSame($this->profile->id, $trace->member_ai_profile_id);
        $this->assertSame($this->owner->id, $trace->profile_owner_user_id);
        $this->assertSame('member_profile.agent_visitor_chat', $trace->process);
        $this->assertSame('Quelles sont vos competences en SEO ?', $trace->question);
        $this->assertNull($trace->response);
        $this->assertNull($trace->provider, 'Aucun provider choisi pour un anonyme : NULL, pas un rule_based fabrique.');
        $this->assertNull($trace->model);
        $this->assertSame(AiRefusedException::CODE_AUTHENTICATION_REQUIRED, $trace->metadata['economic_refusal']['code'] ?? null);
        $this->assertSame(AiAgentChat::FEATURE, $trace->metadata['economic_refusal']['feature'] ?? null);
        $this->assertNull($trace->cost_usd);
        $this->assertNull($trace->cost_unknown, 'Rien de parti : rien a evaluer (NULL/NULL), jamais 0.');
        $this->assertNull($trace->input_tokens);
    }

    public function test_a_guest_refusal_trace_is_bounded_to_one_per_session_and_profile(): void
    {
        $this->fakeOpenRouterAnswer();

        $component = Livewire::test(AiAgentChat::class, ['user' => $this->owner]);

        $component->set('question', 'Premiere question.')->call('sendMessage');
        $component->set('question', 'Deuxieme question.')->call('sendMessage');
        $component->set('question', 'Troisieme question.')->call('sendMessage');

        $this->assertSame(1, MemberAiProfileInteraction::query()->count(), 'Au plus UNE trace de refus par session invite et par profil.');
        $this->assertSame('Premiere question.', MemberAiProfileInteraction::query()->value('question'));
        $this->assertCount(7, $component->get('messages'), 'Chaque message recoit son refus humain, meme sans nouvelle trace.');
        $this->assertNothingEconomicWritten();

        // Un autre profil dans la meme session : sa propre trace (la borne est par profil).
        $otherOwner = User::factory()->create(['organization_id' => $this->tenant->id, 'first_name' => 'Nour']);
        MemberAiProfile::factory()->published()->create(['organization_id' => $this->tenant->id, 'user_id' => $otherOwner->id]);
        Livewire::test(AiAgentChat::class, ['user' => $otherOwner])->set('question', 'Bonjour.')->call('sendMessage');
        $this->assertSame(2, MemberAiProfileInteraction::query()->count());
    }

    public function test_a_guest_reset_writes_nothing_and_keeps_the_lock(): void
    {
        $component = Livewire::test(AiAgentChat::class, ['user' => $this->owner]);
        $component->set('question', 'Bonjour.')->call('sendMessage')->assertSet('guestRefused', true);

        $component->call('resetConversation')
            ->assertSet('guestRefused', true)
            ->assertSet('authRequired', true);

        $this->assertCount(1, $component->get('messages'));
        $this->assertSame(0, ProfileAgentConversation::query()->count());
        $this->assertSame(0, ProfileAgentMessage::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_guest_refusal_does_not_depend_on_the_economic_state_and_the_public_page_still_renders(): void
    {
        // Meme avec un budget atteint, le code du refus est « pas de compte »,
        // pas un code budgetaire : on ne revele rien de l'economie du tenant a un anonyme.
        $this->reachOrganizationBudget($this->tenant, $this->owner);
        $this->fakeOpenRouterAnswer();

        Livewire::test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Bonjour.')
            ->call('sendMessage')
            ->assertSet('error', null);

        $this->assertSame(AiRefusedException::CODE_AUTHENTICATION_REQUIRED, MemberAiProfileInteraction::query()->firstOrFail()->metadata['economic_refusal']['code']);
        $this->assertNothingEconomicWritten();

        // La page publique (hors `auth`) reste servie, avec l'encart d'invitation — jamais une erreur.
        $this->owner->forceFill(['organization_id' => $this->platform->id])->saveQuietly();
        $this->profile->forceFill(['organization_id' => $this->platform->id])->saveQuietly();
        app()->forgetInstance('current_organization');

        $this->get(route('agent-ia.profile.chat', $this->owner))
            ->assertOk()
            ->assertSeeText(__('ai.visitor_chat_guest_notice'))
            ->assertSee(route('login'));
    }

    public function test_the_owner_sees_the_refused_guest_exchange_with_the_amber_badge(): void
    {
        Livewire::test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Pouvez-vous auditer mon site ?')
            ->call('sendMessage');

        $this->actingAs($this->owner)
            ->get(route('agent-ia.interactions'))
            ->assertOk()
            ->assertSeeText(__('ai.interaction_refused_badge'))
            ->assertSeeText(__('ai.interaction_refused_guest_body'))
            ->assertSeeText(__('ai.visitor_anonymous'))
            ->assertSeeText('Pouvez-vous auditer mon site ?')
            ->assertSee('data-economic-refusal="'.AiRefusedException::CODE_AUTHENTICATION_REQUIRED.'"', false)
            ->assertDontSeeText('rule_based');
    }

    // =====================================================================
    // B. Visiteur AUTHENTIFIE : succes sous autorite, identite explicite
    // =====================================================================

    public function test_an_authenticated_visitor_answer_writes_one_platform_ledger_line_on_the_profile_organization(): void
    {
        $this->fakeOpenRouterAnswer();

        $component = Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->owner])
            ->assertSet('authRequired', false)
            ->assertDontSee(__('ai.visitor_chat_guest_notice'))
            ->set('question', 'Quelles sont vos competences en SEO ?')
            ->call('sendMessage')
            ->assertSet('error', null)
            ->assertSet('errorCode', null)
            ->assertSet('isTyping', false)
            ->assertSet('visitorTurnCount', 1)
            ->assertSet('guestRefused', false);

        Http::assertSentCount(1);

        $messages = $component->get('messages');
        $this->assertSame('Maya accompagne les audits SEO locaux.', end($messages)['text']);

        $ledger = AiProviderInvocation::query()->get();
        $this->assertCount(1, $ledger, 'Un appel = une ligne de ledger, jamais deux.');
        $row = $ledger->first();
        $this->assertSame($this->tenant->id, $row->organization_id, 'Tenant = Organization du PROFIL.');
        $this->assertSame($this->visitor->id, $row->user_id, 'Acteur = le visiteur qui pose la question.');
        // TASK-1285 : le chemin est entre au registre — le writer porte la
        // capability (regle ecrite de TASK-1253), id = la feature historique.
        $this->assertSame('member_profile_agent_visitor_chat', $row->capability);
        $this->assertSame(AiAgentChat::FEATURE, $row->feature);
        $this->assertSame('member_profile_agent_visitor_chat', $row->feature);
        $this->assertSame('member_profile.agent_visitor_chat', $row->process, 'Le process de la trace operationnelle.');
        $this->assertSame(AiProviderInvocation::OPERATION_GENERATION, $row->operation);
        $this->assertSame('openrouter', $row->provider);
        $this->assertSame('router/catalogued', $row->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source, 'Cle plateforme DECLAREE, jamais deduite.');
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        $this->assertSame(300, $row->input_tokens);
        $this->assertSame(150, $row->output_tokens);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        $this->assertEqualsWithDelta(0.0009, (float) $row->provider_cost, 1e-9, '(300 + 150) tokens x 2 $/M.');

        // Trace operationnelle : tenant EXPLICITE, usage OBSERVE (plus « inconnu par construction »).
        $trace = AdminAiInteraction::query()->where('scenario_id', 'profile_agent_visitor_chat')->firstOrFail();
        $this->assertSame(1, AdminAiInteraction::query()->count());
        $this->assertSame($this->tenant->id, $trace->organization_id);
        $this->assertSame($this->visitor->id, $trace->user_id);
        $this->assertSame('member_profile.agent_visitor_chat', $trace->process);
        $this->assertSame($row->correlation_id, $trace->correlation_id, 'Meme correlation sur le ledger et la trace.');
        $this->assertSame(300, (int) $trace->input_tokens);
        $this->assertSame(150, (int) $trace->output_tokens);
        $this->assertFalse((bool) $trace->cost_unknown);
        $this->assertEqualsWithDelta(0.0009, (float) $trace->cost_usd, 1e-9);

        // Conversation + messages : inchanges.
        $conversation = $this->conversationOf($this->visitor);
        $this->assertNotNull($conversation);
        $this->assertSame($this->tenant->id, $conversation->organization_id);
        $stored = ProfileAgentMessage::query()->where('conversation_id', $conversation->id)->orderBy('created_at')->get();
        $this->assertCount(2, $stored);
        $this->assertSame('assistant', $stored[1]->role);
        $this->assertSame('openrouter', $stored[1]->metadata['provider']);
        $this->assertArrayNotHasKey('fallback_after_provider_failure', $stored[1]->metadata);

        $this->assertSame(0, AiInteraction::query()->count(), 'Aucune trace produit : zero double comptage.');
        $this->assertSame(0, MemberAiProfileInteraction::query()->count(), 'Une reponse du chat visiteur vit dans la conversation, pas dans « Echanges ».');
    }

    public function test_an_answer_without_usage_block_stays_unknown_never_zero(): void
    {
        $this->fakeOpenRouterAnswer(usage: null);

        Livewire::actingAs($this->visitor)->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Bonjour.')->call('sendMessage');

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);

        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertTrue((bool) $trace->cost_unknown);
        $this->assertNull($trace->cost_usd);
    }

    public function test_the_tenant_is_the_profile_organization_never_the_visitors_nor_the_current_one(): void
    {
        // Un compte d'une AUTRE Organization visite le profil ; et la requete
        // Livewire se croit dans SON Organization (`current_organization`).
        $stranger = User::factory()->create(['organization_id' => $this->elsewhere->id, 'first_name' => 'Zoe', 'preferred_locale' => 'fr']);
        app()->instance('current_organization', $this->elsewhere);

        // Le budget de l'Organization DU VISITEUR est atteint : sans effet.
        $this->reachOrganizationBudget($this->elsewhere, $stranger);
        $this->fakeOpenRouterAnswer();

        $component = Livewire::actingAs($stranger)->test(AiAgentChat::class, ['user' => $this->owner]);
        $component->set('question', 'Premiere question.')->call('sendMessage')->assertSet('errorCode', null);

        Http::assertSentCount(1);
        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame($this->tenant->id, $row->organization_id, 'Ledger impute a l\'Organization du PROFIL…');
        $this->assertNotSame($this->elsewhere->id, $row->organization_id, '… jamais a celle du visiteur ni a l\'Organization courante.');
        $this->assertSame($stranger->id, $row->user_id, 'L\'acteur reste le visiteur.');
        $this->assertSame($this->tenant->id, AdminAiInteraction::query()->value('organization_id'), 'La trace admin aussi : tenant explicite, pas `current_organization`.');
        $this->assertSame(1, ProfileAgentConversation::query()->count(), 'Une seule conversation…');
        $this->assertSame($this->tenant->id, $this->conversationOf($stranger)?->organization_id, '… rattachee a l\'Organization du PROFIL (la ou le proprietaire la relit), pas a `current_organization`.');

        // Le budget de l'Organization DU PROFIL est atteint : refus.
        $this->reachOrganizationBudget($this->tenant, $this->owner);
        $component->set('question', 'Deuxieme question.')->call('sendMessage')
            ->assertSet('errorCode', AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED);

        Http::assertSentCount(1);
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $refused = MemberAiProfileInteraction::query()->where('status', MemberAiProfileInteraction::STATUS_REFUSED)->firstOrFail();
        $this->assertSame($this->tenant->id, $refused->organization_id);
        $this->assertSame($stranger->id, $refused->visitor_user_id);
        $this->assertSame(MemberAiProfileInteraction::VISITOR_TYPE_USER, $refused->visitor_type);
    }

    public function test_the_credit_evaluated_is_the_visitors_not_the_owners(): void
    {
        app(AiUserCreditSettings::class)->updateOrganization($this->tenant, OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM, 1, null);
        $this->fakeOpenRouterAnswer();

        // Le PROPRIETAIRE a epuise son credit : sans effet sur l'agent qui repond au visiteur.
        $this->knownSpend($this->tenant, $this->owner, 'chatloop.ask', 'chatloop_ai_ask', 0.0001);
        $component = Livewire::actingAs($this->visitor)->test(AiAgentChat::class, ['user' => $this->owner]);
        $component->set('question', 'Premiere question.')->call('sendMessage')->assertSet('errorCode', null);
        $this->assertSame(1, AiProviderInvocation::query()->count());

        // Le VISITEUR a epuise le sien : refus, code credit, lien vers les offres.
        $this->knownSpend($this->tenant, $this->visitor, 'chatloop.ask', 'chatloop_ai_ask', 0.0001);
        $component->set('question', 'Deuxieme question.')->call('sendMessage')
            ->assertSet('errorCode', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
            ->assertSet('visitorTurnCount', 1)
            ->assertSee('data-ai-refusal-code="'.AiRefusedException::CODE_USER_CREDIT_EXHAUSTED.'"', false)
            ->assertSee(__('ai.credit_see_offers'));

        $this->assertNotNull($component->get('offersUrl'));
        Http::assertSentCount(1);
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame(
            AiRefusedException::CODE_USER_CREDIT_EXHAUSTED,
            MemberAiProfileInteraction::query()->where('status', MemberAiProfileInteraction::STATUS_REFUSED)->firstOrFail()->metadata['economic_refusal']['code'],
        );
    }

    public function test_the_owner_testing_their_own_agent_is_their_own_visitor(): void
    {
        $this->fakeOpenRouterAnswer();

        Livewire::actingAs($this->owner)->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Que dis-tu de moi ?')->call('sendMessage')->assertSet('errorCode', null);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame($this->tenant->id, $row->organization_id);
        $this->assertSame($this->owner->id, $row->user_id, 'Le proprietaire qui teste son agent (/agent-ia/test) porte son propre credit.');
    }

    public function test_no_active_provider_answers_rule_based_without_any_economic_event(): void
    {
        config(['ai.openrouter.enabled' => false, 'ai.default_provider' => null]);

        $component = Livewire::actingAs($this->visitor)->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Quelles competences ?')->call('sendMessage')->assertSet('error', null);

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Aucun appel provider : aucune ligne de ledger.');
        $messages = $component->get('messages');
        $this->assertStringContainsString('SEO', end($messages)['text']);
        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame('rule_based', $trace->provider);
        $this->assertSame($this->tenant->id, $trace->organization_id);
        $this->assertFalse((bool) $trace->cost_unknown);
        $this->assertSame(0.0, (float) $trace->cost_usd);
    }

    // =====================================================================
    // C. Visiteur AUTHENTIFIE : refus avant tout appel, echec provider honnete
    // =====================================================================

    public function test_a_refusal_on_the_profile_organization_budget_sends_nothing_and_gives_no_substitute_answer(): void
    {
        $this->reachOrganizationBudget($this->tenant, $this->owner);
        $this->fakeOpenRouterAnswer();

        $component = Livewire::actingAs($this->visitor)->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Quelles sont vos competences en SEO ?')
            ->call('sendMessage')
            ->assertSet('errorCode', AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED)
            ->assertSet('error', __('loops.ai_summary_monthly_budget_reached'))
            ->assertSet('offersUrl', null)
            ->assertSet('isTyping', false)
            ->assertSet('visitorTurnCount', 0)
            ->assertSet('maxTurnsReached', false);

        $this->assertNothingEconomicWritten();

        $messages = $component->get('messages');
        $this->assertCount(2, $messages, 'Accueil + question : AUCUNE reponse de substitution.');
        $this->assertSame('user', end($messages)['role']);

        $conversation = $this->conversationOf($this->visitor);
        $stored = ProfileAgentMessage::query()->where('conversation_id', $conversation->id)->get();
        $this->assertCount(1, $stored, 'La question du visiteur reste dans la conversation, sans reponse.');
        $this->assertSame('user', $stored[0]->role);

        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame(MemberAiProfileInteraction::STATUS_REFUSED, $trace->status);
        $this->assertSame($this->visitor->id, $trace->visitor_user_id);
        $this->assertSame(MemberAiProfileInteraction::VISITOR_TYPE_USER, $trace->visitor_type);
        $this->assertSame('openrouter', $trace->provider, 'Le provider qui AURAIT ete appele.');
        $this->assertSame('router/catalogued', $trace->model);
        $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $trace->metadata['economic_refusal']['code']);
        $this->assertNull($trace->cost_usd);
        $this->assertNull($trace->cost_unknown);

        // Le proprietaire voit l'etat de l'echange.
        $this->actingAs($this->owner)->get(route('agent-ia.interactions'))
            ->assertOk()
            ->assertSeeText(__('ai.interaction_refused_badge'))
            ->assertSeeText($this->visitor->full_name);
    }

    public function test_the_process_budget_of_the_supervision_resolver_family_is_wired_per_process(): void
    {
        config(['ai.supervision_resolver.economic_guard.monthly_budget_usd' => 0.001]);
        $this->fakeOpenRouterAnswer();
        $component = Livewire::actingAs($this->visitor)->test(AiAgentChat::class, ['user' => $this->owner]);

        // Une depense connue sur un AUTRE process : sans effet. T1286 : ce
        // process-la a converge lui aussi — la depense doit etre au LEDGER
        // pour etre reellement VUE de la garde (une trace registre post-
        // cutover ne compterait nulle part et l'assertion serait creuse).
        $this->knownLedgerSpend($this->tenant, $this->owner, 'member_profile.loop_agent_reply');
        $component->set('question', 'Premiere question.')->call('sendMessage')->assertSet('errorCode', null);
        $this->assertSame(2, AiProviderInvocation::query()->count(), '1 fixture autre process + 1 succes.');

        // La meme depense sur LE process de ce chemin : refus. T1286 : ce
        // process a converge vers l'autorite ledger — la depense qui compte
        // est une ligne LEDGER a cout connu, plus une trace registre.
        $this->knownLedgerSpend($this->tenant, $this->owner, 'member_profile.agent_visitor_chat');
        $component->set('question', 'Deuxieme question.')->call('sendMessage')
            ->assertSet('errorCode', AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED);

        Http::assertSentCount(1);
        $this->assertSame(3, AiProviderInvocation::query()->count(), 'Le refus n\'a rien ecrit de plus (2 fixtures + 1 succes).');
    }

    public function test_a_missing_platform_key_is_refused_as_not_configured_before_any_call(): void
    {
        config(['ai.openrouter.api_key' => '']);
        $this->fakeOpenRouterAnswer();

        Livewire::actingAs($this->visitor)->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Bonjour.')->call('sendMessage')
            ->assertSet('errorCode', AiRefusedException::CODE_NOT_CONFIGURED);

        $this->assertNothingEconomicWritten();
        $this->assertSame(AiRefusedException::CODE_NOT_CONFIGURED, MemberAiProfileInteraction::query()->firstOrFail()->metadata['economic_refusal']['code']);
    }

    public function test_a_provider_failure_writes_a_failed_ledger_line_with_null_cost_then_falls_back_honestly(): void
    {
        $this->fakeOpenRouterFailure();

        $component = Livewire::actingAs($this->visitor)->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Quelles competences ?')->call('sendMessage')
            ->assertSet('error', null)
            ->assertSet('visitorTurnCount', 1);

        Http::assertSentCount(1);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame('openrouter', $row->provider);
        $this->assertSame($this->tenant->id, $row->organization_id);
        $this->assertSame($this->visitor->id, $row->user_id);
        $this->assertNull($row->provider_cost, 'Un echec a un cout NULL, jamais 0.');
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertNotNull($row->failure_reason);

        // Repli rule-based publie, dit tel quel partout.
        $messages = $component->get('messages');
        $this->assertStringContainsString('SEO', end($messages)['text']);

        $conversation = $this->conversationOf($this->visitor);
        $assistant = ProfileAgentMessage::query()->where('conversation_id', $conversation->id)->where('role', 'assistant')->firstOrFail();
        $this->assertSame('rule_based', $assistant->metadata['provider']);
        $this->assertSame('openrouter', $assistant->metadata['fallback_after_provider_failure']['provider'] ?? null);

        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame('rule_based', $trace->provider);
        $this->assertSame('openrouter', $trace->metadata['fallback_after_provider_failure']['provider'] ?? null);
        $this->assertFalse((bool) $trace->cost_unknown, 'Le repli rule-based a un cout nul et CONNU.');
    }
}
