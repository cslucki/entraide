<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\LoopMessagesSource;
use App\Ai\ContexteIa;
use App\Events\LoopMessageCreated;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1298 — trois acteurs explicites sur `loop_messages.type` :
 * `user` (un humain), `ai` (BouclePro), `member_agent` (l'agent d'un membre).
 *
 * Le point qui decide de la TASK : le contexte transmis au modele. Avant ce
 * correctif, la reponse d'un agent etait ecrite `type=user` avec
 * `sender_id = user_id du membre` — le modele apprenait donc que le MEMBRE
 * avait parle, alors que c'etait sa machine. `authorOf()` teste `sender`
 * AVANT son test binaire `ai`/systeme : changer le type ne suffit pas, il
 * faut nommer le troisieme acteur.
 *
 * Deux decisions d'orchestration (24/08) gouvernent ces assertions :
 *  - le LIBELLE exact du troisieme acteur est DECISION_REQUIRED_CYRIL : les
 *    tests prouvent la DISTINCTION (ni le nom nu du membre, ni « BouclePro »,
 *    ni « systeme »), jamais la formulation ;
 *  - `isEditableBy()` reste CONSERVATEUR : un membre pouvait editer le
 *    message de son agent quand il portait `type=user`, ce droit est
 *    PRESERVE pour `member_agent` ; le resserrement eventuel est consigne
 *    DECISION_REQUIRED_CYRIL, il n'est pas pris ici.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1298ChatLoopThreeActorsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    /** Cree la Boucle. */
    private User $owner;

    /** Membre dont « l'agent » parle dans le fil. */
    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini']);

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Theo', 'name' => 'Dupont']);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Maya', 'name' => 'Martin']);

        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->owner, 'Boucle trois acteurs');
        $loopService->addMember($this->loop, $this->member, 'member');

        app()->instance('current_organization', $this->organization);

        config(['ai.openai.api_key' => 'test-key']);
        config(['ai.providers.openai.driver' => 'openai']);
        config(['ai.providers.openai.key' => 'test-key']);
        config(['ai.chatloop.min_summary_words' => 0]);

        LoopDirectAnswerAgent::fake(['Réponse de l\'IA.']);
        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. Le contexte transmis au modele (LoopMessagesSource::authorOf)
    // =====================================================================

    public function test_a_member_agent_message_is_labeled_as_a_distinct_actor_in_the_model_context(): void
    {
        $agentMessage = $this->message('member_agent', $this->member, 'Je peux auditer votre SEO local.');
        $humanMessage = $this->message('user', $this->member, 'Je cherche un audit SEO.');

        $source = new LoopMessagesSource;
        $agentLabel = $source->authorOf($agentMessage);

        $this->assertNotSame('', trim($agentLabel));
        $this->assertNotSame(
            $this->member->publicDisplayName(),
            $agentLabel,
            'Le modele ne doit plus croire que le MEMBRE a parle quand c\'est son agent.',
        );
        $this->assertNotSame('BouclePro', $agentLabel, 'L\'agent d\'un membre n\'est pas l\'IA BouclePro.');
        $this->assertNotSame(__('loops.type_system'), $agentLabel, 'L\'agent d\'un membre n\'est pas le systeme.');

        // Meme expediteur, acteurs differents : les etiquettes doivent differer.
        $this->assertNotSame(
            $source->authorOf($humanMessage),
            $agentLabel,
            'Un humain et son agent ne peuvent pas porter la meme etiquette dans le prompt.',
        );
    }

    public function test_the_collected_context_does_not_impersonate_the_member_for_an_agent_message(): void
    {
        $body = 'Reponse produite par l\'agent du membre.';
        $this->message('member_agent', $this->member, $body);

        $fragment = (new LoopMessagesSource)->collect($this->contexte(), 12000);

        $line = collect(explode("\n", $fragment->text))
            ->first(fn (string $candidate) => str_contains($candidate, $body));

        // Le message de l'agent continue d'entrer dans le contexte…
        $this->assertNotNull($line, 'Un message member_agent doit rester visible du modele.');

        // …mais plus sous le nom nu du membre, ni comme BouclePro, ni comme systeme.
        $this->assertFalse(
            str_starts_with($line, $this->member->publicDisplayName().' : '),
            'La ligne de contexte attribue encore la parole de l\'agent au membre humain.',
        );
        $this->assertFalse(str_starts_with($line, 'BouclePro : '));
        $this->assertFalse(str_starts_with($line, __('loops.type_system').' : '));
    }

    /** Gel : les etiquettes des acteurs historiques ne bougent pas d'un octet. */
    public function test_existing_author_labels_are_unchanged(): void
    {
        $source = new LoopMessagesSource;

        $human = $this->message('user', $this->member, 'Message humain.');
        $this->assertSame($this->member->publicDisplayName(), $source->authorOf($human));

        $ai = $this->message('ai', null, 'Message BouclePro.');
        $this->assertSame('BouclePro', $source->authorOf($ai));

        $event = $this->message('loop_event', null, 'Evenement de Boucle.');
        $this->assertSame(__('loops.type_system'), $source->authorOf($event));
    }

    // =====================================================================
    // B. L'ecriture cible : la reponse d'agent porte son propre type
    // =====================================================================

    public function test_the_agent_job_writes_its_reply_as_member_agent(): void
    {
        [$agentLoop, $trigger, $profileOwner] = $this->agentLoopWithTrigger();

        Http::fake([
            'openrouter.test/*' => Http::response([
                'model' => 'router/catalogued',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Maya accompagne les audits SEO locaux.']]],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 150],
            ]),
        ]);

        $job = new GenerateAiAgentResponse($agentLoop, $trigger);
        Facade::clearResolvedInstance(ContextRepository::class);
        app()->forgetScopedInstances();
        $job->handle(app(MemberProfileAgentResponder::class));

        $reply = LoopMessage::query()
            ->where('loop_id', $agentLoop->id)
            ->where('sender_id', $profileOwner->id)
            ->firstOrFail();

        $this->assertSame(
            'member_agent',
            $reply->type,
            'La reponse automatique d\'un agent de membre doit porter son propre acteur, pas `user`.',
        );
        // Non-regression : le marqueur metadata historique reste pose.
        $this->assertTrue((bool) ($reply->metadata['ai_generated'] ?? false));
    }

    // =====================================================================
    // C. Editabilite — arbitrage CONSERVATEUR du 24/08
    // =====================================================================

    public function test_a_member_agent_reply_stays_editable_by_its_member(): void
    {
        $agentMessage = $this->message('member_agent', $this->member, 'Reponse de mon agent.');

        $this->assertTrue(
            $agentMessage->isEditableBy($this->member),
            'Un membre pouvait editer le message de son agent (type=user) : ce droit est PRESERVE.',
        );
        $this->assertFalse(
            $agentMessage->isEditableBy($this->owner),
            'Le droit d\'edition reste celui de l\'expediteur, jamais d\'un autre membre.',
        );
    }

    /** Gel : l'editabilite des acteurs historiques ne bouge pas. */
    public function test_user_and_ai_editability_is_unchanged(): void
    {
        $human = $this->message('user', $this->member, 'Message humain.');
        $this->assertTrue($human->isEditableBy($this->member));
        $this->assertFalse($human->isEditableBy($this->owner));

        $ai = $this->message('ai', null, 'Message BouclePro.');
        $this->assertFalse($ai->isEditableBy($this->member));
        $this->assertFalse($ai->isEditableBy($this->owner));
    }

    // =====================================================================
    // D. Gels des lecteurs NON modifies (verts avant comme apres)
    // =====================================================================

    /**
     * Le compteur de la Card `core.ai_summary` compte les interventions de
     * BouclePro. Les messages d'agent n'y ont JAMAIS figure (ils portaient
     * `type=user`) : l'exclusion de `member_agent` est une non-regression au
     * bit pres, pas une decision produit (arbitrage C du 24/08).
     */
    public function test_the_ai_summary_counter_counts_only_bouclepro_interventions(): void
    {
        $this->message('ai', null, 'Premiere synthese.');
        $this->message('ai', null, 'Deuxieme synthese.');
        $this->message('user', $this->member, 'Message humain.');
        $this->message('member_agent', $this->member, 'Message d\'agent.');

        $composition = app(LoopCardCompositionService::class)->compositionFor($this->loop);
        $aiSummary = collect($composition)->firstWhere('key', 'core.ai_summary');

        $this->assertNotNull($aiSummary);
        $this->assertSame(2, $aiSummary['data_count']);
    }

    /**
     * Rendu identique en T-2 (arbitrage D du 24/08) : une bulle de membre au
     * nom de l'expediteur, ni badge « Facilitateur IA », ni sous-titre
     * « a la demande de » — ces deux-la restent reserves aux messages `ai`.
     */
    public function test_a_member_agent_bubble_renders_like_a_member_message(): void
    {
        $this->message('member_agent', $this->member, 'Reponse agent visible dans le fil.', [
            'ai_generated' => true,
            'requested_by' => $this->owner->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Reponse agent visible dans le fil.')
            ->assertSee($this->member->publicDisplayName())
            ->assertDontSee(__('loops.ai_facilitator'))
            ->assertDontSee(__('loops.ai_requested_by', ['name' => $this->owner->publicDisplayName()]));
    }

    /** La diffusion temps reel transporte le type tel quel, sans traduction. */
    public function test_the_broadcast_payload_carries_the_member_agent_type(): void
    {
        $agentMessage = $this->message('member_agent', $this->member, 'Reponse diffusee.');

        $payload = (new LoopMessageCreated($agentMessage))->broadcastWith();

        $this->assertSame('member_agent', $payload['type']);
    }

    /**
     * `triggerMessageId()` retient le dernier message non-`ai` du contexte :
     * un message d'agent y etait DEJA eligible quand il portait `type=user`,
     * il le reste avec `member_agent`.
     */
    public function test_a_member_agent_message_stays_trigger_eligible_for_answer(): void
    {
        $this->message('user', $this->member, 'Question initiale du membre.', at: now()->subMinutes(2));
        $agentMessage = $this->message('member_agent', $this->member, 'Precision apportee par l\'agent.', at: now()->subMinute());

        $answer = app(\App\Services\ChatLoop\ChatLoopAiService::class)->answer($this->loop, $this->member);

        $this->assertSame((string) $agentMessage->id, (string) $answer->reply_to_id);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /** @param array<string, mixed>|null $metadata */
    private function message(string $type, ?User $sender, string $body, ?array $metadata = null, ?Carbon $at = null): LoopMessage
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $sender?->id,
            'body' => $body,
            'type' => $type,
            'metadata' => $metadata,
            'organization_id' => $this->loop->organization_id,
        ]);

        $message->forceFill(['created_at' => $at ?? now()])->saveQuietly();

        return $message->refresh();
    }

    private function contexte(): ContexteIa
    {
        return new ContexteIa(
            organizationId: $this->organization->id,
            userId: $this->member->id,
            loopId: $this->loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_SUMMARY,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
        );
    }

    /**
     * Une Boucle agent comme en production (cf. TASK1251) : profil publie,
     * conversation ouverte par un visiteur, message declencheur humain.
     *
     * @return array{0: Loop, 1: LoopMessage, 2: User}
     */
    private function agentLoopWithTrigger(): array
    {
        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openrouter' => [
                    'router/catalogued' => ['input_per_1m' => 2.0, 'output_per_1m' => 2.0],
                ],
            ],
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

        Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1298', 'ai_profiles_enabled' => true]);
        $tenant = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-1298', 'ai_profiles_enabled' => true]);

        $profileOwner = User::factory()->create(['organization_id' => $tenant->id, 'first_name' => 'Maya', 'preferred_locale' => 'fr']);
        $visitor = User::factory()->create(['organization_id' => $tenant->id, 'first_name' => 'Theo', 'preferred_locale' => 'fr']);

        app()->instance('current_organization', $tenant);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $tenant->id,
            'user_id' => $profileOwner->id,
            'skills' => ['SEO'],
            'service_scope' => 'Audit SEO local',
            'member_profile_summary' => 'Consultante SEO',
        ]);

        $this->actingAs($visitor)
            ->post(route('agent-ia.conversation.start', $profileOwner))
            ->assertRedirect();

        $agentLoop = Loop::query()->where('type', 'ai_agent')->firstOrFail();

        $trigger = LoopMessage::create([
            'loop_id' => $agentLoop->id,
            'sender_id' => $visitor->id,
            'body' => 'Quelles sont vos competences en SEO ?',
            'type' => 'user',
            'organization_id' => $agentLoop->organization_id,
        ]);

        return [$agentLoop, $trigger, $profileOwner];
    }
}
