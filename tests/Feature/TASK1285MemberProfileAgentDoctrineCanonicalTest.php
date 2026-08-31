<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\NervousSystemCoverage;
use App\Ai\PromptRepository;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\AiAgentChat;
use App\Models\AdminAiInteraction;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\SupervisionEconomicScope;
use App\Support\Ai\AiProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1285 (BLOC E) — Agent de profil sous doctrine, moitie DOCTRINE.
 *
 * Les deux surfaces de REPONSE de `MemberProfileAgentResponder` (reponse
 * automatique dans une Boucle agent via `GenerateAiAgentResponse`, chat
 * visiteur via `AiAgentChat`) deviennent des capabilities canoniques
 * (`member_profile_agent_loop_reply`, `member_profile_agent_visitor_chat`,
 * ids = les features historiques du ledger) : prompt compose par
 * `PromptRepository::compose()` (Constitution -> doctrine de l'Organization
 * de RECORD, celle du scope pose par l'appelant -> instruction = prompt
 * admin/fallback historique, aucun texte perdu), materiau via
 * `ContextBuilder` (source `member.profile`), capability portee au ledger
 * par `SupervisionEconomicAuthority::attempt()` (regle ecrite TASK-1253).
 *
 * Ce que cette TASK ne change PAS, et que ce fichier prouve aussi :
 * - le repli rule-based sans provider actif : AUCUN evenement economique,
 *   comportement produit identique (et pas de composition non plus) ;
 * - le refus de la garde : rien d'envoye, rien d'ecrit, doctrine ou pas ;
 * - la garde et le ledger (ordre, nombre d'appels, process, credential
 *   PLATEFORME — bascule BYOK = decision produit, hors TASK) ;
 * - la troisieme surface (configuration conversationnelle,
 *   `chatWithSetupPrompt`) : HERITEE, declaree `member_profile_agent_setup`
 *   dans l'inventaire (HARD GATE documente dans le TASK file).
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1285MemberProfileAgentDoctrineCanonicalTest extends TestCase
{
    use RefreshDatabase;

    /** Organization du PROFIL membre : le tenant de record. */
    private Organization $tenant;

    /** Une autre Organization : celle de la REQUETE dans le test de doctrine. */
    private Organization $elsewhere;

    private User $owner;

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

        Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1285', 'ai_profiles_enabled' => true]);
        $this->tenant = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-1285', 'ai_profiles_enabled' => true]);
        $this->elsewhere = Organization::factory()->create(['is_active' => true, 'slug' => 'elsewhere-1285', 'ai_profiles_enabled' => true]);

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
    // Fixtures (motifs TASK-1251 / TASK-1252 / TASK-1284)
    // ------------------------------------------------------------------

    private function aiAgentLoop(): Loop
    {
        $this->actingAs($this->visitor)
            ->post(route('agent-ia.conversation.start', $this->owner))
            ->assertRedirect();

        return Loop::query()->where('type', 'ai_agent')->firstOrFail();
    }

    private function visitorMessage(Loop $loop, string $body = 'Quelles sont vos competences en SEO ?'): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $this->visitor->id,
            'body' => $body,
            'type' => 'user',
            'organization_id' => $loop->organization_id,
        ]);
    }

    /** Frontiere du worker : le job repose lui-meme tout son contexte. */
    private function runJob(Loop $loop, LoopMessage $message): GenerateAiAgentResponse
    {
        $job = new GenerateAiAgentResponse($loop, $message);

        Facade::clearResolvedInstance(ContextRepository::class);
        app()->forgetScopedInstances();
        $job->handle(app(MemberProfileAgentResponder::class));

        return $job;
    }

    private function fakeOpenRouterAnswer(string $text = 'Maya accompagne les audits SEO locaux.'): void
    {
        Http::fake([
            'openrouter.test/*' => Http::response([
                'model' => 'router/catalogued',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 150],
            ]),
        ]);
    }

    /**
     * Les messages system/user REELLEMENT envoyes au provider.
     *
     * @return array{system: string, user: string}
     */
    private function sentMessages(): array
    {
        $captured = null;

        Http::assertSent(function ($request) use (&$captured): bool {
            $captured = $request->data()['messages'] ?? null;

            return true;
        });

        $this->assertIsArray($captured, 'La requete provider porte des messages.');
        $this->assertSame('system', $captured[0]['role']);
        $this->assertSame('user', $captured[1]['role']);

        return ['system' => $captured[0]['content'], 'user' => $captured[1]['content']];
    }

    /**
     * Chaque aiguille apparait, et dans cet ordre.
     *
     * @param  list<string>  $needles
     */
    private function assertOrderedInString(array $needles, string $haystack, string $message): void
    {
        $cursor = 0;

        foreach ($needles as $needle) {
            $position = mb_strpos($haystack, $needle, $cursor);
            $this->assertNotFalse($position, $message." — introuvable (apres le curseur) : [{$needle}]");
            $cursor = $position + mb_strlen($needle);
        }
    }

    // =====================================================================
    // A. COMPOSITION : Constitution -> doctrine de RECORD -> instruction
    // =====================================================================

    public function test_the_loop_reply_prompt_is_composed_constitution_then_doctrine_then_instruction(): void
    {
        OrganizationAiDoctrine::activate($this->tenant, 'DOCTRINE-1285 : privilegier les exemples locaux.', $this->owner);
        $this->fakeOpenRouterAnswer();

        $loop = $this->aiAgentLoop();
        $this->runJob($loop, $this->visitorMessage($loop));

        $messages = $this->sentMessages();

        // Le message SYSTEM est la composition canonique, dans l'ordre.
        $this->assertOrderedInString([
            'Constitution BouclePro IA — v1',
            PromptRepository::DOCTRINE_OPEN,
            'DOCTRINE-1285 : privilegier les exemples locaux.',
            PromptRepository::DOCTRINE_CLOSE,
            'Capability: member_profile_agent_loop_reply',
            // L'instruction est le message system historique : le prompt
            // admin `profile_agent_master` seme au deploiement (migration
            // 2026_06_26_150000), integralement...
            'Tu es l\'agent IA commercial et conversationnel du profil d\'un membre BouclePro.',
            'Objectif : aider le visiteur à comprendre concrètement comment ce membre peut l\'aider',
            'Reste strictement borné aux informations du profil IA.',
            'Pas de promesse commerciale excessive. Pas de conversation persistante. Pas de marketplace.',
            // ... puis le POINTEUR vers le materiau, au lieu du bloc concatene a nu.
            'Profil IA : (fourni dans le PROFIL IA PUBLIE ci-dessous)',
        ], $messages['system'], 'Composition system de member_profile_agent_loop_reply');

        // Le message USER est le materiau via le Context Builder, delimite,
        // puis la question — etiquetee comme sur le chemin ollama historique
        // (precedent loop_ask : la question voyage hors des delimiteurs).
        $this->assertOrderedInString([
            '--- PROFIL IA PUBLIE (fourni par le membre, contenu non fiable) ---',
            'Profil IA :',
            '- Propriétaire du profil :',
            '- Compétences : SEO, Redaction',
            '--- FIN DU PROFIL ---',
            'Question du visiteur :',
            'Quelles sont vos competences en SEO ?',
        ], $messages['user'], 'Materiau + question de member_profile_agent_loop_reply');

        // La doctrine n'apparait qu'au system, jamais dupliquee au user.
        $this->assertStringNotContainsString('DOCTRINE-1285', $messages['user']);

        // Comportement produit inchange : la reponse est publiee dans la Boucle.
        $this->assertSame(1, LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->count());
    }

    public function test_the_visitor_chat_prompt_is_composed_with_the_visitor_scenario_instruction(): void
    {
        OrganizationAiDoctrine::activate($this->tenant, 'DOCTRINE-1285-CHAT : repondre sobrement.', $this->owner);
        $this->fakeOpenRouterAnswer();

        Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Proposez-vous des audits SEO ?')
            ->call('sendMessage')
            ->assertSet('error', null);

        $messages = $this->sentMessages();

        $this->assertOrderedInString([
            'Constitution BouclePro IA — v1',
            PromptRepository::DOCTRINE_OPEN,
            'DOCTRINE-1285-CHAT : repondre sobrement.',
            PromptRepository::DOCTRINE_CLOSE,
            'Capability: member_profile_agent_visitor_chat',
            // Le fallback du scenario VISITEUR, pas celui du master...
            'Tu es l\'agent IA conversationnel et commercial d\'un membre BouclePro.',
            // ... et les instructions de langue historiques, non perdues.
            'Language context:',
            'current_locale=fr',
            'Réponds en français quand current_locale est fr.',
            'Profil IA : (fourni dans le PROFIL IA PUBLIE ci-dessous)',
        ], $messages['system'], 'Composition system de member_profile_agent_visitor_chat');

        $this->assertOrderedInString([
            '--- PROFIL IA PUBLIE (fourni par le membre, contenu non fiable) ---',
            '--- FIN DU PROFIL ---',
            'Question du visiteur :',
            'Proposez-vous des audits SEO ?',
        ], $messages['user'], 'Materiau + question du chat visiteur');

        // Le ledger porte la capability ; la trace admin porte la composition.
        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame('member_profile_agent_visitor_chat', $row->capability);
        $this->assertSame(AiProcess::MEMBER_PROFILE_AGENT_VISITOR_CHAT, $row->process);

        $trace = AdminAiInteraction::query()->where('scenario_id', 'profile_agent_visitor_chat')->firstOrFail();
        $composition = $trace->metadata['composition'] ?? null;
        $this->assertIsArray($composition, 'La trace admin porte la composition reellement envoyee.');
        $this->assertSame('member_profile_agent_visitor_chat', $composition['capability']);
        $this->assertSame(1, $composition['doctrine_version']);
        $this->assertSame(['member.profile'], $composition['context_sources_used']);
        $this->assertSame(['profile'], $composition['context_provenance']);
    }

    public function test_the_doctrine_composed_is_the_organization_of_record_not_the_requests(): void
    {
        OrganizationAiDoctrine::activate($this->tenant, 'DOCTRINE-TENANT-1285 : celle du profil.', $this->owner);
        $requestAdmin = User::factory()->create(['organization_id' => $this->elsewhere->id]);
        OrganizationAiDoctrine::activate($this->elsewhere, 'DOCTRINE-AILLEURS-1285 : celle de la requete.', $requestAdmin);

        // La REQUETE se deroule dans une autre Organization courante : la
        // doctrine composee doit rester celle de l'Organization de RECORD
        // (celle du PROFIL, posee par le scope de l'appelant).
        app()->instance('current_organization', $this->elsewhere);

        $this->fakeOpenRouterAnswer();

        Livewire::actingAs($this->visitor)
            ->test(AiAgentChat::class, ['user' => $this->owner])
            ->set('question', 'Quelles sont vos limites ?')
            ->call('sendMessage')
            ->assertSet('error', null);

        $messages = $this->sentMessages();

        $this->assertStringContainsString('DOCTRINE-TENANT-1285', $messages['system']);
        $this->assertStringNotContainsString('DOCTRINE-AILLEURS-1285', $messages['system']);

        // Le tenant de record est re-prouve au ledger.
        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame($this->tenant->id, $row->organization_id);
    }

    public function test_an_active_admin_prompt_becomes_the_instruction_not_the_whole_prompt(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'profile_agent_master',
            'name' => 'Master 1285',
            'prompt_text' => 'PROMPT ADMIN 1285 UNIQUE — presente le membre avec sobriete.',
            'version' => 7,
            'is_active' => true,
        ]);
        $this->fakeOpenRouterAnswer();

        $loop = $this->aiAgentLoop();
        $this->runJob($loop, $this->visitorMessage($loop));

        $messages = $this->sentMessages();

        // La Constitution est la TETE du prompt : le prompt admin n'est pas
        // devenu le prompt entier, il est l'INSTRUCTION composee dessous.
        // TASK-1348 : presente, plus forcement en tete — un socle de code
        // immuable precede desormais tout texte administrable.
        $this->assertStringContainsString(app(Constitution::class)->text(), $messages['system']);
        $this->assertOrderedInString([
            'Capability: member_profile_agent_loop_reply',
            'Instructions capability (profile_agent_master):',
            'PROMPT ADMIN 1285 UNIQUE — presente le membre avec sobriete.',
            'Profil IA : (fourni dans le PROFIL IA PUBLIE ci-dessous)',
        ], $messages['system'], 'Le prompt admin est l\'instruction');

        // Le prompt admin actif remplace le fallback en dur...
        $this->assertStringNotContainsString('Tu es l\'agent IA commercial et conversationnel', $messages['system']);
        // ... et sans doctrine active, aucun bloc doctrine n'est compose.
        $this->assertStringNotContainsString(PromptRepository::DOCTRINE_OPEN, $messages['system']);
    }

    // =====================================================================
    // B. INVARIANTS ECONOMIQUES : rien ne bouge
    // =====================================================================

    public function test_rule_based_fallback_without_provider_still_writes_nothing_and_composes_nothing(): void
    {
        config([
            'ai.openrouter.enabled' => false,
            'ai.default_provider' => null,
        ]);
        OrganizationAiDoctrine::activate($this->tenant, 'DOCTRINE-1285 : jamais consultee sans provider.', $this->owner);
        Http::fake();

        $loop = $this->aiAgentLoop();
        $this->runJob($loop, $this->visitorMessage($loop, 'Quelles competences proposez-vous ?'));

        // Comportement produit identique : le repli rule-based est publie.
        $reply = LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->firstOrFail();
        $this->assertStringContainsString('SEO', $reply->body);

        // AUCUN evenement economique, aucun appel, aucune ligne de ledger.
        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(0, AiInteraction::query()->where('process', AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY)->count());

        // Et rien n'a ete compose : la trace ne porte AUCUNE composition.
        $trace = MemberAiProfileInteraction::query()->firstOrFail();
        $this->assertSame('rule_based', $trace->provider);
        $this->assertArrayNotHasKey('composition', $trace->metadata ?? []);
    }

    public function test_an_economic_refusal_with_active_doctrine_sends_nothing_and_writes_nothing(): void
    {
        OrganizationAiDoctrine::activate($this->tenant, 'DOCTRINE-1285 : composee mais jamais partie.', $this->owner);

        // Budget de l'Organization de record atteint : plafond minuscule +
        // une depense connue ce mois-ci (autre chemin).
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->tenant->id,
            'provider' => 'openrouter',
            'model' => 'router/catalogued',
            'api_key' => 'sk-tenant',
            'monthly_budget_usd' => 0.001,
        ]);
        AiInteraction::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->tenant->id,
            'process' => 'blog.article_generate',
            'feature' => 'blog_generate',
            'model' => 'openrouter/router/catalogued',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'cost_usd' => 0.5,
            'cost_unknown' => false,
            'metadata' => [],
        ]);

        Http::fake();

        $loop = $this->aiAgentLoop();
        $this->runJob($loop, $this->visitorMessage($loop));

        // Rien d'envoye, rien d'ecrit : ni ledger, ni message de l'agent —
        // la composition, faite avant la garde, n'a produit aucun evenement.
        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(0, LoopMessage::query()->where('loop_id', $loop->id)->where('sender_id', $this->owner->id)->count());
        $this->assertSame(1, MemberAiProfileInteraction::query()->where('status', MemberAiProfileInteraction::STATUS_REFUSED)->count());
    }

    public function test_an_unknown_scenario_has_no_canonical_path_and_writes_nothing(): void
    {
        Http::fake();

        $this->expectException(\DomainException::class);

        try {
            app(MemberProfileAgentResponder::class)->answerUnderEconomicAuthority(
                $this->profile,
                'Question hors scenario',
                new SupervisionEconomicScope(
                    organization: $this->tenant,
                    actor: $this->visitor,
                    creditUser: $this->visitor,
                    feature: 'member_profile_agent_loop_reply',
                ),
                AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY,
                'scenario_invente',
            );
        } finally {
            // Fail-closed AVANT credential, garde et provider : rien ne part,
            // rien ne s'ecrit (T1283 : pas de chemin non gouverne par construction).
            Http::assertNothingSent();
            $this->assertSame(0, AiProviderInvocation::query()->count());
        }
    }

    public function test_the_ledger_and_the_trace_share_the_operation_correlation_and_the_composition(): void
    {
        $this->fakeOpenRouterAnswer();

        $loop = $this->aiAgentLoop();
        $job = $this->runJob($loop, $this->visitorMessage($loop));

        $row = AiProviderInvocation::query()->firstOrFail();
        $trace = MemberAiProfileInteraction::query()->firstOrFail();

        // Une operation = une correlation, partagee ledger <-> trace (T1131).
        $this->assertSame($job->correlationId, $row->correlation_id);
        $this->assertSame($job->correlationId, $trace->correlation_id);

        // Ledger : capability portee, process/feature/credential INCHANGES.
        $this->assertSame('member_profile_agent_loop_reply', $row->capability);
        $this->assertSame(AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY, $row->process);
        $this->assertSame(GenerateAiAgentResponse::FEATURE, $row->feature);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);

        // Trace : la composition reellement envoyee (sans doctrine active,
        // version NULL — dite telle quelle, jamais reconstituee).
        $composition = $trace->metadata['composition'] ?? null;
        $this->assertIsArray($composition);
        $this->assertSame('member_profile_agent_loop_reply', $composition['capability']);
        $this->assertNull($composition['doctrine_version']);
        $this->assertSame(['member.profile'], $composition['context_sources_used']);
        $this->assertSame(['profile'], $composition['context_provenance']);
    }

    // =====================================================================
    // C. Couverture : registre, labels, et la verite sur la 3e surface
    // =====================================================================

    public function test_the_capabilities_are_registered_and_coverage_tells_the_truth(): void
    {
        $registry = app(CapabilityRegistry::class);
        $coverage = app(NervousSystemCoverage::class);

        foreach ([
            CapabilityRegistry::MEMBER_PROFILE_AGENT_LOOP_REPLY => [AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY, 'profile_agent_master', true],
            CapabilityRegistry::MEMBER_PROFILE_AGENT_VISITOR_CHAT => [AiProcess::MEMBER_PROFILE_AGENT_VISITOR_CHAT, 'profile_agent_visitor_chat', false],
        ] as $capability => [$process, $promptKey, $canWrite]) {
            $this->assertTrue($registry->has($capability));
            $definition = $registry->get($capability);
            $this->assertSame($process, $definition->process, 'Process INCHANGE : la garde releve la meme cle qu\'avant.');
            $this->assertSame($promptKey, $definition->promptKey);
            $this->assertSame($canWrite, $definition->canWrite);
            $this->assertSame([CapabilityRegistry::SCOPE_ORGANIZATION], $definition->allowedScopes);
            $this->assertSame([CapabilityRegistry::SOURCE_MEMBER_PROFILE], $definition->allowedSources);
            $this->assertNotSame('ai.capability_label.'.$capability, __('ai.capability_label.'.$capability, [], 'fr'));
            $this->assertNotSame('ai.capability_label.'.$capability, __('ai.capability_label.'.$capability, [], 'en'));
        }

        // Les deux surfaces de reponse sont migrees : la cle globale sort...
        $this->assertNotContains('member_profile_agent', $coverage->inherited());
        // ... et la dette RESTANTE (configuration conversationnelle) est
        // declaree sous sa propre cle : l'Admin n'est jamais trompe.
        $this->assertContains('member_profile_agent_setup', $coverage->inherited());
        $this->assertFalse($registry->has('member_profile_agent_setup'));
        $this->assertNotSame('ai.inherited_label.member_profile_agent_setup', __('ai.inherited_label.member_profile_agent_setup', [], 'fr'));
        $this->assertNotSame('ai.inherited_label.member_profile_agent_setup', __('ai.inherited_label.member_profile_agent_setup', [], 'en'));

        // TASK-1309 : + `loop_hybrid_answer` (mode IA + Dossiers) = 10.
        // TASK-1327 : + `loop_decision_suggestion` (Decision Memory) = 11.
        $this->assertSame(11, $coverage->coveredCount());
        $this->assertSame(15, $coverage->totalCount());
    }
}
