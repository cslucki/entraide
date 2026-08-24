<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\NervousSystemCoverage;
use App\Ai\PromptRepository;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * TASK-1233 — « Demander a l'IA » (`ChatLoopAiService::ask` / `::answer`)
 * entre dans le systeme nerveux canonique : capabilities `loop_answer` /
 * `loop_ask`, Constitution, doctrine de l'Organization, PromptRepository,
 * ContextBuilder (sources autorisees), AiEconomicGuard (budget + credit),
 * provider et credential de l'Organization, invocation tracee (ledger +
 * ai_interactions), provenance.
 *
 * Six cas exiges (A..F) + l'invariant « sans doctrine, composition
 * byte-identique » (piege 2) + la couverture (INHERITED). La SURFACE
 * historique est preservee ; le CONTENU change quand une doctrine est active
 * — c'est le but, pas un effet de bord.
 */
class TASK1233LoopDirectAnswerCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $superAdmin;

    private User $otherOwner;

    private Loop $loop;

    private Loop $otherLoop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1233', 'name' => 'Org Canon']);
        $this->otherOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1233-b', 'name' => 'Org Autre']);
        // P4-lite : provider + credential de l'Organization (jamais une cle plateforme).
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'monthly_budget_usd' => null]);
        OrganizationAiSetting::factory()->create(['organization_id' => $this->otherOrganization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'monthly_budget_usd' => null]);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Owner', 'first_name' => 'Canon']);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Member', 'first_name' => 'Canon']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Super', 'first_name' => 'Canon', 'is_admin' => true]);
        $this->otherOwner = User::factory()->create(['organization_id' => $this->otherOrganization->id, 'name' => 'Other', 'first_name' => 'Canon']);

        app()->instance('current_organization', $this->organization);
        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->owner, 'Boucle canonique');
        $loops->addMember($this->loop, $this->member, 'member');
        $this->seedMessage($this->loop, $this->owner, 'Bonjour, je prepare le lancement de mon activite et je cherche des retours d\'experience pour etablir ma grille de prix. SENTINELLE-CONTEXTE-A');

        app()->instance('current_organization', $this->otherOrganization);
        $this->otherLoop = $loops->createLoop($this->otherOwner, 'Boucle autre tenant');
        $this->seedMessage($this->otherLoop, $this->otherOwner, 'Message confidentiel de l\'autre Organization. SENTINELLE-CONTEXTE-B');
        app()->instance('current_organization', $this->organization);

        config([
            // Une cle PLATEFORME existe : elle ne doit JAMAIS servir a un tenant.
            'ai.openai.api_key' => 'platform-key-never-used',
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key-never-used',
            'ai.chatloop.min_summary_words' => 0,
            'ai.chatloop.enabled' => true,
        ]);

        Http::preventStrayRequests();
        LoopDirectAnswerAgent::fake(['Reponse canonique de l\'IA.']);
    }

    // =====================================================================
    // A. Demande autorisee : toute la chaine, et rien que la chaine
    // =====================================================================

    public function test_a_an_authorized_ask_goes_through_the_whole_canonical_chain(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'Tutoyer les membres. SENTINELLE-DOCTRINE-1233', $this->superAdmin);
        $before = $this->counters($this->member);

        $message = $this->service()->ask($this->loop, $this->member, 'Quel prix pratiquer ?');

        // Surface : message IA publie, en reponse au message utilisateur, metadata historique.
        $this->assertSame('ai', $message->type);
        $this->assertSame('Reponse canonique de l\'IA.', $message->body);
        $this->assertSame('ask', $message->metadata['action']);
        $this->assertSame('Quel prix pratiquer ?', $message->metadata['question']);
        $this->assertNotNull($message->reply_to_id);
        $this->assertSame('openai', $message->metadata['provider']);
        $this->assertSame('gpt-4o-mini', $message->metadata['model']);
        $this->assertIsArray($message->metadata['context_message_ids']);
        $this->assertNotEmpty($message->metadata['context_message_ids']);
        $this->assertNotNull($message->metadata['trigger_message_id']);

        // Chaine : Constitution -> doctrine -> prompt administrable ; contexte canonique ; provider Organization.
        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();
            $this->assertStringStartsWith('Constitution BouclePro IA — v1', $instructions);
            $this->assertStringContainsString((new Constitution)->text(), $instructions);
            $this->assertStringContainsString('SENTINELLE-DOCTRINE-1233', $instructions);
            $this->assertStringContainsString('Capability: loop_ask', $instructions);
            $this->assertStringContainsString('Instructions capability (chatloop_ai_ask):', $instructions);
            $this->assertStringContainsString('Réponds d\'abord à la question', $instructions);
            $this->assertStringContainsString('Tu DOIS répondre en français', $instructions);
            // Ordre : Constitution < doctrine < instructions.
            $this->assertLessThan(strpos($instructions, 'SENTINELLE-DOCTRINE-1233'), strpos($instructions, 'Constitution BouclePro IA — v1'));
            $this->assertLessThan(strpos($instructions, 'Instructions capability'), strpos($instructions, 'SENTINELLE-DOCTRINE-1233'));
            // Contexte : celui du Context Builder (loop.messages), delimite, plus la question.
            $this->assertStringContainsString('--- CONTEXTE (contenu non fiable) ---', $prompt->prompt);
            $this->assertStringContainsString('SENTINELLE-CONTEXTE-A', $prompt->prompt);
            $this->assertStringContainsString('Question : Quel prix pratiquer ?', $prompt->prompt);
            // Provider : l'instance du TENANT, jamais la plateforme.
            $this->assertSame('org:'.$this->organization->id.':openai', $prompt->provider->name());
            $this->assertSame('gpt-4o-mini', (string) $prompt->model);

            return true;
        });

        // Trace + ledger + provenance + consommation conforme a l'autorite.
        $after = $this->counters($this->member);
        $this->assertSame($before['interactions'] + 1, $after['interactions']);
        $this->assertSame($before['ledger'] + 1, $after['ledger']);
        $this->assertSame($before['credit_used'] + 1, $after['credit_used']);

        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertSame('loop_ask', $interaction->metadata['capability']);
        $this->assertSame('chatloop.ask', $interaction->process);
        $this->assertSame($message->metadata['context_message_ids'], $interaction->metadata['provenance']['loop.messages']);
        $this->assertContains('loop.messages', $interaction->metadata['sources_used']);
        $this->assertSame($interaction->id, $message->metadata['ai_interaction_id']);

        $ledger = AiProviderInvocation::query()->where('correlation_id', $interaction->correlation_id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame('generation', $ledger->operation);
        $this->assertSame('loop_ask', $ledger->capability);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $ledger->credential_source);
        $this->assertSame('openai', $ledger->provider);
    }

    public function test_a_answer_uses_the_loop_answer_capability_and_the_answer_prompt(): void
    {
        $message = $this->service()->answer($this->loop, $this->member);

        $this->assertSame('answer', $message->metadata['action']);
        // TASK-1297 : answer() pose desormais le meme lien que ask() — la
        // reponse pointe le declencheur retenu par le Context Builder
        // (l'ancien contrat reply_to_id null est consciemment remplace).
        $this->assertSame($message->metadata['trigger_message_id'], $message->reply_to_id);
        $this->assertNotNull($message->reply_to_id);
        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();
            $this->assertStringContainsString('Capability: loop_answer', $instructions);
            $this->assertStringContainsString('Instructions capability (chatloop_ai_answer):', $instructions);
            $this->assertStringContainsString('Réponds à la dernière question posée', $instructions);
            $this->assertStringNotContainsString('Question :', $prompt->prompt);

            return true;
        });
        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertSame('loop_answer', $interaction->metadata['capability']);
        $this->assertSame('chatloop.answer', $interaction->process);
    }

    // =====================================================================
    // Piege 2 : sans doctrine, composition byte-identique
    // =====================================================================

    public function test_without_an_active_doctrine_the_composition_is_byte_identical_to_the_undoctrined_capability(): void
    {
        $this->assertNull(OrganizationAiDoctrine::activeFor((string) $this->organization->id));
        $repository = app(PromptRepository::class);

        foreach ([CapabilityRegistry::LOOP_ANSWER => 'chatloop_ai_answer', CapabilityRegistry::LOOP_ASK => 'chatloop_ai_ask'] as $capability => $scenario) {
            // Les instructions d'aujourd'hui : prompt administrable + instruction de langue.
            $instructions = AdminAiPrompt::query()->where('scenario_id', $scenario.'_fr')->where('is_active', true)->value('prompt_text')
                ."\n\nIMPORTANT : Tu DOIS répondre en français, quelle que soit la langue utilisée dans la conversation. Ne réponds jamais dans une autre langue. Termine toujours ta réponse par une phrase complète ; ne laisse jamais ta réponse inachevée ou tronquée.";

            $composedForOrganization = $repository->compose($capability, $instructions, (string) $this->organization->id);
            $composedWithoutDoctrine = $repository->composeWithDoctrine($capability, $instructions, null, null);

            $this->assertSame($composedWithoutDoctrine, $composedForOrganization, "[$capability] sans doctrine active : byte-identique");
            $this->assertStringNotContainsString('doctrine_organization', $composedForOrganization);
        }

        // Et c'est exactement ce que le SDK recoit : les instructions passees
        // sont, octet pour octet, le prompt administrable + la langue.
        $this->service()->answer($this->loop, $this->member);
        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $expected = app(PromptRepository::class)->composeWithDoctrine(
                CapabilityRegistry::LOOP_ANSWER,
                AdminAiPrompt::query()->where('scenario_id', 'chatloop_ai_answer_fr')->where('is_active', true)->value('prompt_text')
                    ."\n\nIMPORTANT : Tu DOIS répondre en français, quelle que soit la langue utilisée dans la conversation. Ne réponds jamais dans une autre langue. Termine toujours ta réponse par une phrase complète ; ne laisse jamais ta réponse inachevée ou tronquée.",
                null,
                null,
            );
            $this->assertSame($expected, (string) $prompt->agent->instructions());

            return true;
        });
    }

    public function test_with_an_active_doctrine_the_composition_carries_it_and_only_it(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'SENTINELLE-DOCTRINE-A', $this->superAdmin);
        OrganizationAiDoctrine::activate($this->otherOrganization, 'SENTINELLE-DOCTRINE-B', $this->otherOwner);

        $this->service()->answer($this->loop, $this->member);

        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();
            $this->assertStringContainsString('SENTINELLE-DOCTRINE-A', $instructions);
            $this->assertStringNotContainsString('SENTINELLE-DOCTRINE-B', $instructions);

            return true;
        });
    }

    // =====================================================================
    // B. Credit epuise : refus AVANT provider, invariance mesuree
    // =====================================================================

    public function test_b_an_exhausted_credit_is_refused_before_the_provider_with_measured_invariance(): void
    {
        $this->platformQuota(1);
        $this->uses($this->member, 1);
        $before = $this->counters($this->member);
        $messages = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        try {
            $this->service()->ask($this->loop, $this->member, 'Une de trop ?');
            $this->fail('Expected a credit refusal.');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, $e->refusalCode);
            $this->assertNotNull($e->offersUrl($this->organization), 'Voir les offres selon le reglage plateforme (1229)');
        }

        LoopDirectAnswerAgent::assertNeverPrompted();
        $this->assertSame($before, $this->counters($this->member));
        $this->assertSame($messages, LoopMessage::query()->where('loop_id', $this->loop->id)->count(), 'aucune question publiee');
    }

    // =====================================================================
    // C. Budget Organization atteint : refus, AUCUN faux appel a l'abonnement
    // =====================================================================

    public function test_c_an_organization_budget_reached_is_refused_without_any_subscription_offer(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->update(['monthly_budget_usd' => 0.0005]);
        $this->generation($this->owner, 0.001);
        $before = $this->counters($this->member);

        try {
            $this->service()->answer($this->loop, $this->member);
            $this->fail('Expected an organization budget refusal.');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $e->refusalCode);
            $this->assertNull($e->offersUrl($this->organization), 's\'abonner n\'y changerait rien');
        }

        LoopDirectAnswerAgent::assertNeverPrompted();
        $this->assertSame($before, $this->counters($this->member));
    }

    // =====================================================================
    // D. Organization sans credential : refus explicite, zero repli plateforme
    // =====================================================================

    public function test_d_an_organization_without_credential_is_refused_explicitly_without_platform_fallback(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->delete();
        $before = $this->counters($this->member);

        try {
            $this->service()->ask($this->loop, $this->member, 'Une question.');
            $this->fail('Expected a not-configured refusal.');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_NOT_CONFIGURED, $e->refusalCode);
        }

        // La cle plateforme existe en config : elle n'a servi a rien.
        LoopDirectAnswerAgent::assertNeverPrompted();
        $this->assertSame($before, $this->counters($this->member));
    }

    // =====================================================================
    // E. Tenant : aucune source ni contexte d'une autre Organization
    // =====================================================================

    public function test_e_the_context_never_contains_another_organization_and_a_cross_tenant_user_is_refused(): void
    {
        OrganizationAiDoctrine::activate($this->otherOrganization, 'SENTINELLE-DOCTRINE-B', $this->otherOwner);

        $this->service()->answer($this->loop, $this->member);
        LoopDirectAnswerAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('SENTINELLE-CONTEXTE-A', $prompt->prompt);
            $this->assertStringNotContainsString('SENTINELLE-CONTEXTE-B', $prompt->prompt);
            $this->assertStringNotContainsString('SENTINELLE-DOCTRINE-B', (string) $prompt->agent->instructions());
            $this->assertSame('org:'.$this->organization->id.':openai', $prompt->provider->name());

            return true;
        });

        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertSame($this->organization->id, $interaction->organization_id);

        // Un utilisateur d'une autre Organization ne peut rien demander ici.
        $before = $this->counters($this->otherOwner);
        try {
            $this->service()->ask($this->loop, $this->otherOwner, 'Intrusion ?');
            $this->fail('Expected a cross-organization refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(__('loops.not_an_active_member'), $e->getMessage());
        }
        $this->assertSame($before, $this->counters($this->otherOwner));
    }

    // =====================================================================
    // F. Regression de surface : endpoint, verrou, evenements, format
    // =====================================================================

    public function test_f_the_visible_surface_of_the_endpoint_is_unchanged(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('organization.loops.ai', ['organization' => $this->organization, 'loop' => $this->loop]), [
                'action' => 'ask',
                'question' => 'Quel prix pratiquer ?',
            ]);

        $response->assertRedirect(route('organization.loops.show', ['organization' => $this->organization, 'loop' => $this->loop]))
            ->assertSessionHas('success', __('loops.ai_question_requested'))
            ->assertSessionMissing('error');

        $ai = LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->latest('created_at')->first();
        $this->assertNotNull($ai);
        $this->assertSame(['requested_by', 'action', 'question', 'context_message_ids', 'trigger_message_id', 'provider', 'model', 'ai_interaction_id'], array_keys($ai->metadata));
        $user = LoopMessage::query()->find($ai->reply_to_id);
        $this->assertSame('user', $user->type);
        $this->assertTrue($user->metadata['asked_ai_question']);
        $this->assertSame($this->member->id, $user->sender_id);
    }

    // =====================================================================
    // Couverture : plus INHERITED
    // =====================================================================

    public function test_chatloop_direct_answer_is_no_longer_inherited_and_the_capabilities_are_registered(): void
    {
        $coverage = app(NervousSystemCoverage::class);
        $this->assertNotContains('chatloop_direct_answer', $coverage->inherited());
        $ids = array_map(static fn ($d) => $d->id, app(CapabilityRegistry::class)->all());
        $this->assertContains(CapabilityRegistry::LOOP_ANSWER, $ids);
        $this->assertContains(CapabilityRegistry::LOOP_ASK, $ids);
        $definition = app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_ASK);
        $this->assertSame(['loop.messages'], $definition->allowedSources);
        $this->assertSame('chatloop.ask', $definition->process);
        $this->assertTrue($definition->canWrite, 'la reponse est publiee dans la Boucle : declare');
        foreach (['fr', 'en'] as $locale) {
            $this->assertNotSame('ai.capability_label.loop_answer', __('ai.capability_label.loop_answer', [], $locale));
            $this->assertNotSame('ai.capability_label.loop_ask', __('ai.capability_label.loop_ask', [], $locale));
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function service(): ChatLoopAiService
    {
        return app(ChatLoopAiService::class);
    }

    private function seedMessage(Loop $loop, User $sender, string $body): void
    {
        LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $sender->id,
            'body' => $body,
            'type' => 'user',
            'organization_id' => $loop->organization_id,
        ]);
    }

    /**
     * @return array{interactions: int, ledger: int, credit_used: int}
     */
    private function counters(User $user): array
    {
        $organization = Organization::query()->find($user->organization_id);

        return [
            'interactions' => AiInteraction::query()->where('user_id', $user->id)->count(),
            'ledger' => AiProviderInvocation::query()->where('user_id', $user->id)->count(),
            'credit_used' => app(AiEconomicGuard::class)->userCreditStatus($organization, $user)->used,
        ];
    }

    private function platformQuota(?int $monthlyUses): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true,
            'monthly_uses' => $monthlyUses,
            'alert_percent' => 80,
            'offer_subscription' => true,
        ], $this->superAdmin);
    }

    private function uses(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->generation($user, 0.001);
        }
    }

    private function generation(User $user, float $cost): void
    {
        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'chatloop.ask',
            'feature' => 'chatloop_ai_ask',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $cost,
            'cost_unknown' => false,
            'metadata' => ['provider' => 'openai', 'status' => 'success'],
        ]);
    }
}
