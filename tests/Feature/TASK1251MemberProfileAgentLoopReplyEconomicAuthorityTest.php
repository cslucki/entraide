<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiAgentResponse;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\LoopMessageService;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1251 — fermer l'autorite economique de la reponse AUTOMATIQUE de
 * l'agent de profil dans une Boucle agent (gap #14 du gap analysis T1246,
 * G2 CRITICAL + G10 HIGH) :
 *
 *   listener `LoopMessageCreated` -> job `GenerateAiAgentResponse::handle()`
 *     -> `MemberProfileAgentResponder::answerUnderEconomicAuthority()`
 *
 * Garde `AiEconomicGuard` DANS le job, juste avant l'appel provider (rien ne
 * part, rien ne s'ecrit sur refus, pas de crash, pas de faux message) ;
 * credential PROUVE `platform` ; ledger canonique `ai_provider_invocations`
 * sur chaque tentative (succes ET echec, usage observe, cout catalogue ou
 * NULL, jamais 0 invente) ; identite economique EXPLICITE : tenant =
 * Organization du PROFIL (jamais celle d'un visiteur), acteur = creditUser =
 * expediteur du message.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1251MemberProfileAgentLoopReplyEconomicAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** Organization plateforme (`is_default`) — n'est jamais le tenant de ce chemin. */
    private Organization $platform;

    /** Organization du PROFIL membre : le tenant de record. */
    private Organization $tenant;

    /** Une autre Organization : celle d'un « visiteur d'ailleurs » (defaut de donnees simule). */
    private Organization $elsewhere;

    /** Proprietaire du profil (l'agent repond en son nom). */
    private User $owner;

    /** Membre de la meme Organization qui ecrit a l'agent : acteur ET porteur du credit. */
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

        $this->platform = Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1251', 'ai_profiles_enabled' => true]);
        $this->tenant = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-1251', 'ai_profiles_enabled' => true]);
        $this->elsewhere = Organization::factory()->create(['is_active' => true, 'slug' => 'elsewhere-1251', 'ai_profiles_enabled' => true]);

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

    /** Une Boucle agent ouverte comme en production : par le visiteur, sur le profil du membre. */
    private function aiAgentLoop(): Loop
    {
        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->owner))
            ->assertRedirect();

        return Loop::query()->where('type', 'ai_agent')->firstOrFail();
    }

    private function visitorMessage(Loop $loop, ?User $sender = null, string $body = 'Quelles sont vos competences en SEO ?'): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => ($sender ?? $this->visitor)->id,
            'body' => $body,
            'type' => 'user',
            'organization_id' => $loop->organization_id,
        ]);
    }

    /**
     * Frontiere du worker (cf. TASK1131AiCorrelationAsyncTest) : liaisons
     * scoped oubliees, contexte rehydrate — le job doit tout reposer lui-meme.
     */
    private function enterWorkerScope(): void
    {
        Facade::clearResolvedInstance(ContextRepository::class);
        app()->forgetScopedInstances();
    }

    private function runJob(Loop $loop, LoopMessage $message): GenerateAiAgentResponse
    {
        $job = new GenerateAiAgentResponse($loop, $message);

        $this->enterWorkerScope();
        $job->handle(app(MemberProfileAgentResponder::class));

        return $job;
    }

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
     * des process converges (`member_profile.loop_agent_reply` /
     * `member_profile.agent_visitor_chat`). La garde par process ne lit plus
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

    /** Le seul message de la Boucle est celui du visiteur : l'agent n'a rien publie. */
    private function assertNoAgentReply(Loop $loop): void
    {
        $this->assertSame(1, LoopMessage::query()->where('loop_id', $loop->id)->count(), 'Aucun message de l\'agent : ni faux assistant, ni repli.');
        $this->assertSame(0, LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->count());
    }

    private function assertNothingEconomicWritten(): void
    {
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Un refus n\'ecrit aucune ligne de ledger.');
        $this->assertSame(0, AiInteraction::query()->where('process', 'member_profile.loop_agent_reply')->count());
        $this->assertSame(0, AdminAiInteraction::query()->count());
        Http::assertNothingSent();
    }

    // =====================================================================
    // A. Succes : ledger + trace honnetes, identite explicite
    // =====================================================================

    public function test_a_reply_writes_one_platform_ledger_line_on_the_profile_organization_with_observed_usage(): void
    {
        $loop = $this->aiAgentLoop();
        $message = $this->visitorMessage($loop);
        $this->fakeOpenRouterAnswer();

        $job = $this->runJob($loop, $message);

        Http::assertSentCount(1);

        $reply = LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->firstOrFail();
        $this->assertSame('Maya accompagne les audits SEO locaux.', $reply->body);
        $this->assertTrue((bool) ($reply->metadata['ai_generated'] ?? false));

        $ledger = AiProviderInvocation::query()->get();
        $this->assertCount(1, $ledger, 'Un appel = une ligne de ledger, jamais deux.');
        $row = $ledger->first();
        $this->assertSame($this->tenant->id, $row->organization_id, 'Tenant = Organization du PROFIL.');
        $this->assertSame($this->visitor->id, $row->user_id, 'Acteur = l\'expediteur du message, pas le proprietaire.');
        // TASK-1285 : le chemin est entre au registre — le writer porte la
        // capability (regle ecrite de TASK-1253), id = la feature historique.
        $this->assertSame('member_profile_agent_loop_reply', $row->capability);
        $this->assertSame(GenerateAiAgentResponse::FEATURE, $row->feature);
        $this->assertSame('member_profile_agent_loop_reply', $row->feature);
        $this->assertSame('member_profile.loop_agent_reply', $row->process, 'Le process de la trace operationnelle.');
        $this->assertSame(AiProviderInvocation::OPERATION_GENERATION, $row->operation);
        $this->assertSame('openrouter', $row->provider);
        $this->assertSame('router/catalogued', $row->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source, 'Cle plateforme DECLAREE, jamais deduite.');
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        $this->assertSame(300, $row->input_tokens);
        $this->assertSame(150, $row->output_tokens);
        $this->assertSame(450, $row->total_tokens);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        $this->assertEqualsWithDelta(0.0009, (float) $row->provider_cost, 1e-9, '(300 + 150) tokens x 2 $/M.');
        $this->assertSame($job->correlationId, $row->correlation_id, 'La correlation du DISPATCH (TASK-1131), pas une correlation neuve.');

        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame(1, MemberAiProfileInteraction::query()->count());
        $this->assertSame('success', $trace->status);
        $this->assertSame($this->tenant->id, $trace->organization_id);
        $this->assertSame($this->owner->id, $trace->profile_owner_user_id);
        $this->assertSame($this->visitor->id, $trace->visitor_user_id);
        $this->assertSame('member_profile.loop_agent_reply', $trace->process);
        $this->assertSame($row->correlation_id, $trace->correlation_id, 'Meme correlation sur le ledger et la trace.');
        $this->assertSame(300, $trace->input_tokens);
        $this->assertSame(150, $trace->output_tokens);
        $this->assertFalse((bool) $trace->cost_unknown, 'Usage observe : le cout n\'est plus « inconnu par construction ».');
        $this->assertEqualsWithDelta(0.0009, (float) $trace->cost_usd, 1e-9);
        // TASK-1285 : la trace porte la composition reellement envoyee
        // (chemin canonique) — c'est la SEULE metadata d'un succes.
        $this->assertSame(['composition'], array_keys($trace->metadata ?? []));
        $this->assertSame('member_profile_agent_loop_reply', $trace->metadata['composition']['capability']);

        $this->assertSame(0, AiInteraction::query()->count(), 'Aucune trace produit : zero double comptage.');
        $this->assertSame(0, AdminAiInteraction::query()->count());
    }

    public function test_a_reply_without_usage_block_stays_unknown_never_zero(): void
    {
        $loop = $this->aiAgentLoop();
        $this->fakeOpenRouterAnswer(usage: null);

        $this->runJob($loop, $this->visitorMessage($loop));

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);

        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertTrue((bool) $trace->cost_unknown);
        $this->assertNull($trace->cost_usd);
    }

    public function test_an_ollama_reply_is_keyless_with_a_true_zero_and_observed_output_only(): void
    {
        config([
            'ai.default_provider' => 'ollama',
            'ai.ollama.enabled' => true,
            'ai.ollama.base_url' => 'http://ollama.test',
            'ai.ollama.model' => 'llama-test',
            'ai.ollama.timeout' => 15,
        ]);
        Http::fake(['ollama.test/*' => Http::response(['response' => 'Reponse locale.', 'eval_count' => 42])]);

        $loop = $this->aiAgentLoop();
        $this->runJob($loop, $this->visitorMessage($loop));

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame('ollama', $row->provider);
        $this->assertSame('llama-test', $row->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_NONE, $row->credential_source);
        $this->assertNull($row->input_tokens, 'Ollama ne rapporte pas l\'entree : NULL, pas 0.');
        $this->assertSame(42, $row->output_tokens);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        $this->assertSame(0.0, (float) $row->provider_cost, 'Le zero est une affirmation du catalogue.');
    }

    // =====================================================================
    // B. Refus : dans le job, avant tout appel, sans crash ni faux message
    // =====================================================================

    public function test_a_refusal_on_the_profile_organization_budget_sends_nothing_writes_no_ledger_and_no_reply(): void
    {
        Log::spy();
        $loop = $this->aiAgentLoop();
        $message = $this->visitorMessage($loop);
        $touchedAt = $loop->fresh()->updated_at;
        $this->reachOrganizationBudget($this->tenant, $this->owner);
        $this->fakeOpenRouterAnswer();

        $this->runJob($loop, $message);

        $this->assertNothingEconomicWritten();
        $this->assertNoAgentReply($loop);
        $this->assertTrue($loop->fresh()->updated_at->equalTo($touchedAt), 'La Boucle n\'est pas touchee : rien ne s\'est passe pour le visiteur.');

        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame(GenerateAiAgentResponse::INTERACTION_STATUS_REFUSED, $trace->status);
        $this->assertNull($trace->response);
        $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $trace->metadata['economic_refusal']['code'] ?? null);
        $this->assertSame('openrouter', $trace->provider, 'Le provider qui AURAIT ete appele.');
        $this->assertSame('router/catalogued', $trace->model);
        $this->assertNull($trace->cost_usd);
        $this->assertNull($trace->cost_unknown, 'Rien de parti : rien a evaluer (NULL/NULL), jamais 0.');
        $this->assertNull($trace->input_tokens);
        $this->assertSame($this->tenant->id, $trace->organization_id);
        $this->assertSame($this->visitor->id, $trace->visitor_user_id);
        $this->assertSame(AiCorrelation::id(), $trace->correlation_id);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'refused by the economic guard')
                && ($context['code'] ?? null) === AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED
                && ($context['loop_id'] ?? null) === $loop->id
                && ($context['organization_id'] ?? null) === $this->tenant->id
                && ($context['sender_id'] ?? null) === $this->visitor->id
        );
    }

    public function test_the_guard_runs_in_the_job_not_at_dispatch_time(): void
    {
        $loop = $this->aiAgentLoop();
        Queue::fake();

        // Au dispatch, tout va bien : le job part.
        app(LoopMessageService::class)->sendUserMessage($loop, $this->visitor, 'Bonjour, que proposez-vous ?');
        Queue::assertPushed(GenerateAiAgentResponse::class, 1);
        $pushed = null;
        Queue::assertPushed(GenerateAiAgentResponse::class, function (GenerateAiAgentResponse $job) use (&$pushed) {
            $pushed = $job;

            return true;
        });

        // Entre le dispatch et l'execution, le budget de l'Organization est atteint.
        $this->reachOrganizationBudget($this->tenant, $this->owner);
        $this->fakeOpenRouterAnswer();

        $this->enterWorkerScope();
        $pushed->handle(app(MemberProfileAgentResponder::class));

        $this->assertNothingEconomicWritten();
        $this->assertNoAgentReply($loop);
        $this->assertSame(GenerateAiAgentResponse::INTERACTION_STATUS_REFUSED, MemberAiProfileInteraction::query()->value('status'));
    }

    public function test_the_process_budget_of_the_supervision_resolver_family_is_wired_per_process(): void
    {
        config(['ai.supervision_resolver.economic_guard.monthly_budget_usd' => 0.001]);
        $loop = $this->aiAgentLoop();
        $this->fakeOpenRouterAnswer();

        // Une depense connue sur un AUTRE process : sans effet. TASK-1303 :
        // `service_offer.master` a converge au cutover du 25/08 (T1291) — la
        // depense doit etre au LEDGER pour etre reellement VUE de la garde
        // (une trace registre post-cutover ne compterait nulle part et la
        // sentinelle serait creuse, meme piege que T1252/T1286).
        $this->knownLedgerSpend($this->tenant, $this->owner, 'service_offer.master');
        $this->runJob($loop, $this->visitorMessage($loop, body: 'Premiere question.'));
        $this->assertSame(2, AiProviderInvocation::query()->count(), '1 fixture autre process + 1 succes.');

        // La meme depense sur LE process de ce chemin : refus. T1286 : ce
        // process a converge vers l'autorite ledger — la depense qui compte
        // est une ligne LEDGER a cout connu, plus une trace registre.
        $this->knownLedgerSpend($this->tenant, $this->owner, 'member_profile.loop_agent_reply');
        $this->runJob($loop, $this->visitorMessage($loop, body: 'Deuxieme question.'));

        $this->assertSame(3, AiProviderInvocation::query()->count(), 'Le refus n\'a rien ecrit de plus (2 fixtures + 1 succes).');
        Http::assertSentCount(1);
        $this->assertSame(
            [AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED],
            MemberAiProfileInteraction::query()->where('status', GenerateAiAgentResponse::INTERACTION_STATUS_REFUSED)->get()->map(fn ($t) => $t->metadata['economic_refusal']['code'])->all(),
        );
    }

    public function test_a_missing_platform_key_is_refused_as_not_configured_before_any_call(): void
    {
        config(['ai.openrouter.api_key' => '']);
        $loop = $this->aiAgentLoop();
        $this->fakeOpenRouterAnswer();

        $this->runJob($loop, $this->visitorMessage($loop));

        $this->assertNothingEconomicWritten();
        $this->assertNoAgentReply($loop);
        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame(GenerateAiAgentResponse::INTERACTION_STATUS_REFUSED, $trace->status);
        $this->assertSame(AiRefusedException::CODE_NOT_CONFIGURED, $trace->metadata['economic_refusal']['code']);
    }

    public function test_no_active_provider_answers_rule_based_without_any_economic_event(): void
    {
        config(['ai.openrouter.enabled' => false, 'ai.default_provider' => null]);
        $loop = $this->aiAgentLoop();

        $this->runJob($loop, $this->visitorMessage($loop, body: 'Quelles competences ?'));

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Aucun appel provider : aucune ligne de ledger.');
        $reply = LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->firstOrFail();
        $this->assertStringContainsString('SEO', $reply->body);
        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame('rule_based', $trace->provider);
        $this->assertSame('success', $trace->status);
        $this->assertFalse((bool) $trace->cost_unknown);
        $this->assertSame(0.0, (float) $trace->cost_usd);
    }

    // =====================================================================
    // C. Echec provider : tentative reelle au ledger, repli produit honnete
    // =====================================================================

    public function test_a_provider_failure_writes_a_failed_ledger_line_with_null_cost_then_falls_back_rule_based(): void
    {
        $loop = $this->aiAgentLoop();
        $this->fakeOpenRouterFailure();

        $this->runJob($loop, $this->visitorMessage($loop, body: 'Quelles competences ?'));

        Http::assertSentCount(1);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(\RuntimeException::class, $row->failure_reason);
        // TASK-1285 : la capability est portee sur CHAQUE tentative, echec compris.
        $this->assertSame('member_profile_agent_loop_reply', $row->capability);
        $this->assertSame($this->tenant->id, $row->organization_id);
        $this->assertSame($this->visitor->id, $row->user_id);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->provider_cost, 'Cout NULL sur echec, jamais 0 invente.');
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);

        // Comportement produit inchange : repli rule-based publie dans la Boucle…
        $reply = LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->firstOrFail();
        $this->assertStringContainsString('SEO', $reply->body);

        // … et la trace dit la verite.
        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame('rule_based', $trace->provider);
        $this->assertSame('success', $trace->status);
        $this->assertSame(
            ['provider' => 'openrouter', 'model' => 'router/catalogued', 'failure' => \RuntimeException::class],
            $trace->metadata['fallback_after_provider_failure'] ?? null,
        );
        $this->assertSame(0.0, (float) $trace->cost_usd, 'Le repli rule-based ne coute rien, et c\'est CONNU.');
        $this->assertFalse((bool) $trace->cost_unknown);
    }

    // =====================================================================
    // D. Identite economique (G10) : tenant = Organization du profil, jamais
    //    celle d'un visiteur ; credit = l'expediteur, pas le proprietaire
    // =====================================================================

    public function test_the_tenant_is_the_profile_organization_never_the_visitors(): void
    {
        $loop = $this->aiAgentLoop();
        $stranger = User::factory()->create(['organization_id' => $this->elsewhere->id, 'first_name' => 'Zoe']);
        LoopMember::create(['loop_id' => $loop->id, 'user_id' => $stranger->id, 'role' => 'member', 'status' => 'active', 'joined_at' => now(), 'organization_id' => $loop->organization_id]);

        // Le budget de l'Organization DU VISITEUR est atteint : sans effet.
        $this->reachOrganizationBudget($this->elsewhere, $stranger);
        $this->fakeOpenRouterAnswer();

        $this->runJob($loop, $this->visitorMessage($loop, $stranger, 'Premiere question.'));

        Http::assertSentCount(1);
        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame($this->tenant->id, $row->organization_id, 'Ledger impute a l\'Organization du PROFIL…');
        $this->assertNotSame($this->elsewhere->id, $row->organization_id, '… jamais a celle du visiteur.');
        $this->assertSame($stranger->id, $row->user_id, 'L\'acteur reste l\'expediteur.');
        $this->assertSame($this->tenant->id, MemberAiProfileInteraction::query()->value('organization_id'));

        // Le budget de l'Organization DU PROFIL est atteint : refus.
        $this->reachOrganizationBudget($this->tenant, $this->owner);
        $this->runJob($loop, $this->visitorMessage($loop, $stranger, 'Deuxieme question.'));

        Http::assertSentCount(1);
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame(
            AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED,
            MemberAiProfileInteraction::query()->where('status', GenerateAiAgentResponse::INTERACTION_STATUS_REFUSED)->firstOrFail()->metadata['economic_refusal']['code'],
        );
    }

    public function test_the_credit_evaluated_is_the_senders_not_the_owners(): void
    {
        app(AiUserCreditSettings::class)->updateOrganization($this->tenant, OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM, 1, null);
        $loop = $this->aiAgentLoop();
        $this->fakeOpenRouterAnswer();

        // Le PROPRIETAIRE a epuise son credit : sans effet sur l'agent qui repond au visiteur.
        $this->knownSpend($this->tenant, $this->owner, 'chatloop.ask', 'chatloop_ai_ask', 0.0001);
        $this->runJob($loop, $this->visitorMessage($loop, body: 'Premiere question.'));
        $this->assertSame(1, AiProviderInvocation::query()->count());

        // L'EXPEDITEUR a epuise le sien : refus, code credit.
        $this->knownSpend($this->tenant, $this->visitor, 'chatloop.ask', 'chatloop_ai_ask', 0.0001);
        $this->runJob($loop, $this->visitorMessage($loop, body: 'Deuxieme question.'));

        Http::assertSentCount(1);
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $refused = MemberAiProfileInteraction::query()->where('status', GenerateAiAgentResponse::INTERACTION_STATUS_REFUSED)->firstOrFail();
        $this->assertSame(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, $refused->metadata['economic_refusal']['code']);
        $this->assertNoAgentReplyAfter($loop, 1);
    }

    public function test_a_loop_outside_the_profile_organization_is_skipped_without_guessing_a_tenant(): void
    {
        Log::spy();
        $loop = $this->aiAgentLoop();
        // Defaut de donnees simule : la Boucle change d'Organization.
        $loop->forceFill(['organization_id' => $this->elsewhere->id])->saveQuietly();
        $this->fakeOpenRouterAnswer();

        $this->runJob($loop->fresh(), $this->visitorMessage($loop->fresh()));

        $this->assertNothingEconomicWritten();
        $this->assertSame(0, MemberAiProfileInteraction::query()->count(), 'Pas meme une trace : on ne sait pas a qui l\'imputer.');
        $this->assertNoAgentReply($loop);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'do not share an Organization')
                && ($context['loop_organization_id'] ?? null) === $this->elsewhere->id
                && ($context['profile_organization_id'] ?? null) === $this->tenant->id
        );
    }

    // =====================================================================
    // E. Etat visible du refus pour le proprietaire du profil
    // =====================================================================

    public function test_the_owner_sees_the_refused_exchange_as_not_generated(): void
    {
        $loop = $this->aiAgentLoop();
        $this->reachOrganizationBudget($this->tenant, $this->owner);
        $this->fakeOpenRouterAnswer();
        $this->runJob($loop, $this->visitorMessage($loop, body: 'Question restee sans reponse.'));

        $response = $this->actingAs($this->owner)->get(route('agent-ia.interactions'));

        $response->assertOk()
            ->assertSee('Question restee sans reponse.')
            ->assertSee('data-economic-refusal="'.AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED.'"', false)
            ->assertSee(__('ai.interaction_refused_badge'))
            ->assertSee(__('ai.interaction_refused_body', ['code' => AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED]))
            ->assertDontSee(__('ai.no_response'));
    }

    // =====================================================================
    // F. (TASK-1252) `answerWithDefaultProvider()` n'existe plus : le chat
    //    visiteur (#15) est passe sous la meme autorite — voir
    //    TASK1252VisitorChatEconomicAuthorityTest. Les deux tests de contrat
    //    « inchange pour #15/#16 » n'ont donc plus d'objet.
    // =====================================================================

    private function assertNoAgentReplyAfter(Loop $loop, int $expectedAgentReplies): void
    {
        $this->assertSame($expectedAgentReplies, LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->count());
    }
}
