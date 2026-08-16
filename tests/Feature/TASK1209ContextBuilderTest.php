<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopSummaryAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\ContexteBorne;
use App\Ai\Context\LoopMessagesSource;
use App\Ai\Context\SourceDenied;
use App\Ai\Context\SourceFragment;
use App\Ai\Context\UserLoopsSource;
use App\Ai\ContexteIa;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Context Builder minimal (TASK-1209 / IA P3).
 *
 * Deux sources, `loop.messages` et `user.loops`, et une regle : la capability
 * decide de ce qu'elle a le droit de lire.
 */
class TASK1209ContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        // TASK-1212 : l'IA transverse est configuree par Organization.
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini']);
        OrganizationAiSetting::factory()->create(['organization_id' => $this->otherOrganization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini']);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = (new LoopService)->createLoop($this->member, 'Context loop');
    }

    // =====================================================================
    // A. loop.messages
    // =====================================================================

    public function test_loop_messages_are_collected_in_chronological_order(): void
    {
        $this->message('Premier message', now()->subMinutes(3));
        $this->message('Deuxième message', now()->subMinutes(2));
        $this->message('Troisième message', now()->subMinute());

        $borne = $this->build();

        $this->assertStringContainsString('Premier message', $borne->text);
        $this->assertLessThan(
            strpos($borne->text, 'Deuxième message'),
            strpos($borne->text, 'Premier message'),
        );
        $this->assertLessThan(
            strpos($borne->text, 'Troisième message'),
            strpos($borne->text, 'Deuxième message'),
        );
    }

    /**
     * Regression TASK-1218 : a `created_at` strictement egaux — cas reel,
     * deux messages postes dans la meme seconde — l'ordre ne doit plus
     * dependre du hasard du moteur. `id` (UUID v7, ordonnable) departage
     * selon l'ordre de creation effectif.
     */
    public function test_messages_sharing_the_same_timestamp_keep_a_deterministic_order(): void
    {
        $moment = now()->subMinutes(5);
        $this->message('Message alpha', $moment);
        $this->message('Message beta', $moment);
        $this->message('Message gamma', $moment);

        $borne = $this->build();

        $this->assertLessThan(
            strpos($borne->text, 'Message beta'),
            strpos($borne->text, 'Message alpha'),
        );
        $this->assertLessThan(
            strpos($borne->text, 'Message gamma'),
            strpos($borne->text, 'Message beta'),
        );
    }

    public function test_deleted_messages_are_excluded(): void
    {
        $this->message('Message visible', now()->subMinutes(2));
        $supprime = $this->message('Message supprimé', now()->subMinute());
        $supprime->update(['deleted_at' => now(), 'deleted_by' => $this->member->id]);

        $borne = $this->build();

        $this->assertStringContainsString('Message visible', $borne->text);
        $this->assertStringNotContainsString('Message supprimé', $borne->text);
        $this->assertCount(1, $borne->provenanceFor(LoopMessagesSource::NAME));
    }

    public function test_the_context_is_wrapped_as_untrusted_content(): void
    {
        $this->message('Contenu de la Boucle');

        $borne = $this->build();

        $this->assertStringContainsString('--- CONTEXTE (contenu non fiable) ---', $borne->text);
        $this->assertStringContainsString('--- FIN DU CONTEXTE ---', $borne->text);
        $this->assertStringContainsString('IMPORTANT', $borne->text);
    }

    public function test_the_char_budget_is_respected_but_never_yields_an_empty_context(): void
    {
        foreach (range(1, 5) as $i) {
            $this->message(str_repeat("ligne{$i} ", 60), now()->subMinutes(10 - $i));
        }

        $borne = $this->build($this->capability(budget: 200));

        $this->assertSame(200, $borne->charBudget);
        $this->assertNotSame('', trim($borne->text));
        // La premiere ligne passe toujours ; les suivantes seulement si elles
        // tiennent. Toutes ne peuvent donc pas tenir dans 200 caracteres.
        $this->assertLessThan(5, count($borne->provenanceFor(LoopMessagesSource::NAME)));
    }

    public function test_provenance_identifies_the_included_messages(): void
    {
        $message = $this->message('Un contenu identifiable');

        $borne = $this->build();
        $provenance = $borne->provenanceFor(LoopMessagesSource::NAME);

        $this->assertCount(1, $provenance);
        $this->assertSame($message->id, $provenance[0]['id']);
        $this->assertSame('direct', $provenance[0]['type']);
        $this->assertSame(LoopMessagesSource::NAME, $provenance[0]['source']);
        $this->assertStringContainsString('Un contenu identifiable', $provenance[0]['extrait']);
    }

    public function test_a_loop_of_another_organization_is_denied_without_confirming_it_exists(): void
    {
        $otherOwner = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Loop ailleurs');
        LoopMessage::create([
            'loop_id' => $otherLoop->id,
            'sender_id' => $otherOwner->id,
            'body' => 'Secret d’une autre Organization',
            'type' => 'user',
            'organization_id' => $otherLoop->organization_id,
        ]);

        // Contexte de MON Organization, pointant la Loop de l'autre.
        $contexte = $this->contexte(loopId: $otherLoop->id);
        $borne = app(ContextBuilder::class)->build($contexte, $this->capability());

        $this->assertSame('', $borne->text);
        $this->assertSame([], $borne->sourcesUsed);
        $this->assertSame(
            SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION,
            $borne->sourcesDenied[LoopMessagesSource::NAME],
        );
        // La raison ne doit rien laisser fuir du contenu refusé.
        $this->assertStringNotContainsString('Secret', json_encode($borne->sourcesDenied));
    }

    public function test_a_context_without_loop_denies_the_loop_messages_source(): void
    {
        $borne = app(ContextBuilder::class)->build(
            $this->contexte(loopId: null),
            $this->capability(),
        );

        $this->assertSame(
            SourceDenied::REASON_NO_LOOP_IN_CONTEXT,
            $borne->sourcesDenied[LoopMessagesSource::NAME],
        );
    }

    // =====================================================================
    // B. user.loops
    // =====================================================================

    public function test_user_loops_returns_only_loops_the_member_actually_belongs_to(): void
    {
        $loops = new LoopService;
        $autre = $loops->createLoop($this->member, 'Ma seconde Boucle');

        // Boucle de la meme Organization, mais dont le membre n'est PAS membre.
        $tiers = User::factory()->create(['organization_id' => $this->organization->id]);
        $loops->createLoop($tiers, 'Boucle du catalogue');

        $borne = $this->build($this->capability(sources: [UserLoopsSource::NAME]));
        $ids = array_column($borne->provenanceFor(UserLoopsSource::NAME), 'id');

        $this->assertContains($this->loop->id, $ids);
        $this->assertContains($autre->id, $ids);
        $this->assertCount(2, $ids);
        $this->assertStringNotContainsString('Boucle du catalogue', $borne->text);
    }

    /**
     * `LoopService::addMember()` refuse deja une adhesion inter-Organization :
     * le scenario est donc impossible par le chemin legitime. On insere la
     * ligne DIRECTEMENT en base pour prouver la garde propre a la source —
     * defense en profondeur, independante du service. Une donnee heritee ou
     * corrompue ne doit pas suffire a faire fuir une Boucle hors tenant.
     */
    public function test_user_loops_never_crosses_the_organization_boundary(): void
    {
        $otherOwner = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Boucle hors tenant');

        LoopMember::create([
            'loop_id' => $otherLoop->id,
            'user_id' => $this->member->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $otherLoop->id,
            'user_id' => $this->member->id,
            'status' => 'active',
        ]);

        $borne = $this->build($this->capability(sources: [UserLoopsSource::NAME]));
        $ids = array_column($borne->provenanceFor(UserLoopsSource::NAME), 'id');

        $this->assertNotContains($otherLoop->id, $ids);
        $this->assertStringNotContainsString('Boucle hors tenant', $borne->text);
    }

    public function test_user_loops_exposes_identifier_name_and_light_description_only(): void
    {
        $this->loop->update(['tagline' => 'Une accroche courte', 'description' => 'Un manifeste très long']);

        $borne = $this->build($this->capability(sources: [UserLoopsSource::NAME]));

        $this->assertStringContainsString($this->loop->id, $borne->text);
        $this->assertStringContainsString('Context loop', $borne->text);
        $this->assertStringContainsString('Une accroche courte', $borne->text);
        // `description` est un text potentiellement volumineux : hors contexte.
        $this->assertStringNotContainsString('Un manifeste très long', $borne->text);
    }

    public function test_an_archived_loop_is_not_offered(): void
    {
        $this->loop->update(['status' => 'archived']);

        $borne = $this->build($this->capability(sources: [UserLoopsSource::NAME]));

        $this->assertSame([], $borne->provenanceFor(UserLoopsSource::NAME));
    }

    public function test_a_context_without_user_denies_the_user_loops_source(): void
    {
        $borne = app(ContextBuilder::class)->build(
            $this->contexte(userId: null),
            $this->capability(sources: [UserLoopsSource::NAME]),
        );

        $this->assertSame(
            SourceDenied::REASON_NO_USER_IN_CONTEXT,
            $borne->sourcesDenied[UserLoopsSource::NAME],
        );
    }

    // =====================================================================
    // C. allowedSources fait autorité
    // =====================================================================

    public function test_a_source_the_capability_does_not_declare_is_never_used(): void
    {
        $this->message('Contenu de la Boucle');

        // La capability ne déclare QUE loop.messages, alors que le membre a
        // bien des Boucles et que la source user.loops est disponible.
        $borne = $this->build($this->capability(sources: [LoopMessagesSource::NAME]));

        $this->assertSame([LoopMessagesSource::NAME], $borne->sourcesUsed);
        $this->assertSame([], $borne->provenanceFor(UserLoopsSource::NAME));
        $this->assertStringNotContainsString('BOUCLES AUTORISÉES', $borne->text);
        $this->assertArrayNotHasKey(UserLoopsSource::NAME, $borne->sourcesDenied);
    }

    public function test_both_declared_sources_are_composed(): void
    {
        $this->message('Contenu de la Boucle');

        $borne = $this->build($this->capability(
            sources: [LoopMessagesSource::NAME, UserLoopsSource::NAME],
        ));

        $this->assertSame([LoopMessagesSource::NAME, UserLoopsSource::NAME], $borne->sourcesUsed);
        $this->assertStringContainsString('--- CONTEXTE (contenu non fiable) ---', $borne->text);
        $this->assertStringContainsString('BOUCLES AUTORISÉES', $borne->text);
    }

    public function test_a_declared_but_unimplemented_source_is_reported_not_ignored(): void
    {
        $borne = $this->build($this->capability(sources: ['dossier.chunks']));

        $this->assertSame([], $borne->sourcesUsed);
        $this->assertSame('source_not_implemented', $borne->sourcesDenied['dossier.chunks']);
    }

    public function test_the_registry_declares_loop_summary_with_loop_messages_only(): void
    {
        $definition = app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_SUMMARY);

        $this->assertTrue($definition->allowsSource(CapabilityRegistry::SOURCE_LOOP_MESSAGES));
        $this->assertFalse($definition->allowsSource(CapabilityRegistry::SOURCE_USER_LOOPS));
        $this->assertSame(
            (int) config('ai.chatloop.max_context_chars', 12000),
            $definition->contextCharBudget,
        );
    }

    // =====================================================================
    // D. loop_summary est le premier consommateur
    // =====================================================================

    /**
     * Preuve que le contexte vient bien du Context Builder et non plus d'une
     * construction ad hoc : on remplace la source dans le conteneur par une
     * doublure reconnaissable. Si `summarize()` construisait encore son
     * contexte lui-meme, ce marqueur n'apparaitrait jamais dans le prompt.
     */
    public function test_loop_summary_takes_its_context_from_the_context_builder(): void
    {
        $this->configureSummary();
        $this->message('Nous avons décidé de livrer vendredi.');

        $this->swap(LoopMessagesSource::class, new class extends LoopMessagesSource
        {
            public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
            {
                return new SourceFragment(
                    'MARQUEUR-CONTEXT-BUILDER-1209',
                    [['source' => self::NAME, 'id' => 'x', 'type' => 'direct', 'extrait' => 'x']],
                );
            }
        });

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);

        LoopSummaryAgent::assertPrompted(
            fn ($prompt): bool => str_contains($prompt->prompt, 'MARQUEUR-CONTEXT-BUILDER-1209')
        );
    }

    public function test_loop_summary_behaviour_is_unchanged_end_to_end(): void
    {
        $this->configureSummary();
        $this->message('Nous avons décidé de livrer vendredi.');

        $summary = app(ChatLoopAiService::class)->summarize($this->loop, $this->member);

        // Contexte metier toujours delimite comme contenu non fiable.
        LoopSummaryAgent::assertPrompted(function ($prompt): bool {
            $this->assertStringContainsString('--- CONTEXTE (contenu non fiable) ---', $prompt->prompt);
            $this->assertStringContainsString('Nous avons décidé de livrer vendredi.', $prompt->prompt);
            $this->assertStringContainsString('Constitution BouclePro IA', (string) $prompt->agent->instructions());

            return true;
        });

        // Une seule trace, can_write=false, correlation != invocationId.
        $this->assertSame(1, AiInteraction::count());
        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('chatloop.summarize', $interaction->process);
        $this->assertSame($this->organization->id, $interaction->organization_id);
        $this->assertNotSame(
            $interaction->correlation_id,
            $interaction->metadata['sdk_invocation_id'] ?? null,
        );

        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $this->loop->id,
            'type' => 'ai',
        ]);

        $this->assertSame('Synthèse de la Boucle.', $summary->body);
        $this->assertNotNull(app(ChatLoopAiService::class)->latestSummary($this->loop));

        Http::assertNothingSent();
    }

    public function test_loop_summary_never_receives_the_user_loops_source(): void
    {
        $this->configureSummary();
        $this->message('Un contenu suffisant.');

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);

        // `loop_summary` ne declare pas `user.loops` : le prompt ne doit
        // contenir aucune liste de Boucles.
        LoopSummaryAgent::assertPrompted(
            fn ($prompt): bool => ! str_contains($prompt->prompt, 'BOUCLES AUTORISÉES')
        );
    }

    private function configureSummary(): void
    {
        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'test-key',
            'ai.chatloop.min_summary_words' => 0,
        ]);

        LoopSummaryAgent::fake(['Synthèse de la Boucle.']);
        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function build(?CapabilityDefinition $capability = null): ContexteBorne
    {
        return app(ContextBuilder::class)->build(
            $this->contexte(),
            $capability ?? $this->capability(),
        );
    }

    private function contexte(mixed $loopId = false, mixed $userId = false): ContexteIa
    {
        return new ContexteIa(
            organizationId: $this->organization->id,
            userId: $userId === false ? $this->member->id : $userId,
            loopId: $loopId === false ? $this->loop->id : $loopId,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_SUMMARY,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
        );
    }

    /**
     * @param  list<string>|null  $sources
     */
    private function capability(?array $sources = null, int $budget = 12000): CapabilityDefinition
    {
        return new CapabilityDefinition(
            id: 'loop_summary',
            process: 'chatloop.summarize',
            requiresHumanConfirmation: false,
            canWrite: false,
            allowedScopes: ['organization', 'loop'],
            allowedSources: $sources ?? [LoopMessagesSource::NAME],
            maxOutput: 8000,
            promptKey: 'chatloop_ai_summarize',
            contextCharBudget: $budget,
        );
    }

    /**
     * `created_at` n'est PAS dans `LoopMessage::$fillable` : passe a `create()`
     * il etait ignore en silence, et les trois messages du test recevaient le
     * meme horodatage d'insertion. `selectMessages()` triant sur ce seul
     * champ, l'ordre rendu devenait indetermine — d'ou un echec aleatoire en
     * CI (TASK-1218).
     *
     * On force donc l'horodatage APRES creation, sans rendre `created_at`
     * mass-assignable pour toute l'application : le besoin est local au test.
     */
    private function message(string $body, ?Carbon $at = null): LoopMessage
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => $body,
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        $message->forceFill(['created_at' => $at ?? now()])->saveQuietly();

        return $message->refresh();
    }
}
