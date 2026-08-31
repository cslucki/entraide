<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\LoopService;
use App\Support\Ai\AiCapabilityCatalogue;
use App\Support\Ai\AiSelfKnowledge;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnCards;
use App\Support\Loops\HelpRequestHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1350 — Shell honnete : conversation globale, non-Interaction et
 * self-knowledge.
 *
 * Le probleme de depart tient en une phrase : le Shell transformait TOUT en
 * demande d'aide. « Merci beaucoup », « c'est quoi une Boucle ? », « je peux
 * aider sur Laravel » — tout revenait en brouillon de demande, avec titre et
 * description. Cette suite prouve que le Shell sait desormais dire non a
 * cette transformation, sans jamais devenir indisponible.
 *
 * Contrats prouves ici :
 *
 *  A. SELF-KNOWLEDGE — les quatre questions V1 sont repondues SANS provider,
 *     et la reconnaissance est un FULL MATCH normalise : ni `contains`, ni
 *     prefixe. Un faux positif serait une reponse a cote ; un faux negatif
 *     n'est qu'un tour de plus chez le provider.
 *  B. VERSION DU PROMPT — `interaction_fit` n'a d'autorite qu'a partir de la
 *     v3. Sous v2, un `false` explicite ne change RIEN (fail-open).
 *  C. NON_INTERACTION — metadata minimale, aucun titre a l'ecran,
 *     `forDisplay()` vide, `prepareRequest()` impossible.
 *  D. OFFRE — « je peux aider sur X » reste une Interaction valide, mais le
 *     CTA devient « Proposer de l'aide », et aucune PersonCard n'apparait.
 *  E. APPELANTS PARTAGES — le service de clarification n'a pas bouge :
 *     `RequestController::formulate()` reste inchange.
 *  F. CATALOGUE — tenant-aware, drapeaux ON/OFF.
 *  G. COUT — self-knowledge : -1 appel provider. Partout ailleurs : delta 0.
 *  H. LEGACY — un tour ANSWERED ecrit avant TASK-1350 se relit a l'identique.
 */
class TASK1350AiShellHonestConversationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-honest',
            'name' => 'Org Honest',
            'loops_enabled' => true,
            'members_can_create_loops' => true,
            'ai_profiles_enabled' => true,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1350-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Ada',
            'name' => 'Honest',
        ]);

        app()->instance('current_organization', $this->organization);

        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle Honest');

        // Une personne ELIGIBLE au sens People-1/2 : membre active de la
        // Boucle, profil publie portant la competence appariee. Elle sert a
        // prouver qu'une PersonCard apparaitrait — et qu'une OFFRE l'enleve.
        $this->candidate = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Carla',
            'name' => 'Honest',
        ]);

        LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->candidate->id,
            'organization_id' => $this->organization->id,
        ]);

        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->candidate->id,
            'skills' => ['Laravel'],
        ]);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.shell.max_context_chars' => 4000,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Self-knowledge
    // =====================================================================

    /**
     * 1. Les quatre questions V1 repondent, et AUCUNE n'atteint un provider.
     *
     * Aucun fake n'est installe volontairement : si un de ces tours partait
     * chez le provider, l'appel echouerait et laisserait une `AiInteraction`
     * en `failed`. Zero interaction est donc la preuve, pas une commodite.
     */
    public function test_the_four_self_knowledge_questions_answer_without_any_provider_call(): void
    {
        $questions = [
            "C'est quoi BouclePro ?",
            "C'est quoi une Boucle ?",
            "Comment demander de l'aide ?",
            'Que puis-je faire ici ?',
        ];

        $shell = Livewire::actingAs($this->member)->test(AiShell::class);

        foreach ($questions as $question) {
            $shell->set('draft', $question)->call('send');
        }

        $answers = $this->answers();

        $this->assertCount(4, $answers, 'Chaque question doit produire un tour.');

        foreach ($answers as $answer) {
            $this->assertNotSame('', trim((string) $answer->content));
            $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $answer->metadata['status']);
            $this->assertSame(AiSelfKnowledge::PRODUCER, $answer->metadata['producer']);
        }

        // G. Le delta provider : -1 appel par question interceptee.
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    /** 2. Chaque sujet repond avec SA matiere, pas avec une reponse generique. */
    public function test_each_self_knowledge_topic_answers_from_its_own_canonical_source(): void
    {
        $selfKnowledge = app(AiSelfKnowledge::class);

        // TASK-1350 : la reponse plateforme part de la DOCTRINE, pas de la page
        // d'accueil. Deux phrases, et la premiere est la phrase fondatrice de
        // la Constitution.
        $platform = $selfKnowledge->answer(AiSelfKnowledge::TOPIC_PLATFORM, $this->organization, $this->member);
        $this->assertStringContainsString('plateforme de pédagogie par l\'entraide', $platform);
        $this->assertStringNotContainsString(__('about.s7_punch'), $platform);
        $this->assertStringNotContainsString(__('about.s2_text'), $platform);
        $this->assertLessThan(320, mb_strlen($platform), 'La reponse plateforme doit rester courte.');

        $loop = $selfKnowledge->answer(AiSelfKnowledge::TOPIC_LOOP, $this->organization, $this->member);
        $this->assertStringContainsString(__('about.s3_text'), $loop);

        $askHelp = $selfKnowledge->answer(AiSelfKnowledge::TOPIC_ASK_HELP, $this->organization, $this->member);
        $this->assertStringContainsString(__('marketplace.request_intro_body'), $askHelp);

        $capabilities = $selfKnowledge->answer(AiSelfKnowledge::TOPIC_CAPABILITIES, $this->organization, $this->member);
        $this->assertStringContainsString(__('ai.self_knowledge_capability_ask_help'), $capabilities);
    }

    /**
     * 3. FULL MATCH : une question VOISINE n'est pas interceptee.
     *
     * C'est le test qui protege le produit. « c'est quoi BouclePro pour une
     * association de quartier ? » est une vraie question contextuelle : la
     * voler serait repondre a cote. Une interception par `contains` echouerait
     * ici, et c'est exactement ce qu'on veut.
     */
    public function test_a_neighbouring_question_is_never_intercepted(): void
    {
        $selfKnowledge = app(AiSelfKnowledge::class);

        $nearMisses = [
            "C'est quoi BouclePro pour une association de quartier ?",
            "Explique-moi c'est quoi BouclePro",
            'Une boucle de rétroaction, c\'est quoi ?',
            "Comment demander de l'aide sur le dossier Erasmus ?",
            'Que puis-je faire ici avec mes points ?',
            'bouclepro',
            'boucle',
        ];

        foreach ($nearMisses as $prompt) {
            $this->assertNull(
                $selfKnowledge->topicFor($prompt),
                "« {$prompt} » ne doit PAS etre intercepte : c'est une question contextuelle.",
            );
        }
    }

    /**
     * 4. La normalisation : apostrophes ASCII et U+2019, accents, casse,
     * ponctuation, tirets, espaces multiples.
     */
    public function test_normalisation_absorbs_apostrophes_accents_case_and_punctuation(): void
    {
        $selfKnowledge = app(AiSelfKnowledge::class);

        $platformVariants = [
            "C'est quoi BouclePro ?",            // apostrophe ASCII
            "C\u{2019}est quoi BouclePro ?",     // apostrophe typographique U+2019
            "C'EST QUOI BOUCLEPRO",              // casse, sans ponctuation
            "  c'est   quoi   bouclepro  !!! ",  // espaces multiples, ponctuation
            "Qu'est-ce que BouclePro ?",         // tiret
            "Qu\u{2019}est-ce que BouclePro…",   // typographique + tiret + points de suspension
            'What is BouclePro?',                // EN
        ];

        foreach ($platformVariants as $variant) {
            $this->assertSame(
                AiSelfKnowledge::TOPIC_PLATFORM,
                $selfKnowledge->topicFor($variant),
                "« {$variant} » doit etre reconnu.",
            );
        }

        // Les accents : la question « aide » en porte, la normalisation les
        // translittere avant comparaison.
        $this->assertSame(
            AiSelfKnowledge::TOPIC_ASK_HELP,
            $selfKnowledge->topicFor("COMMENT DEMANDER DE L\u{2019}AIDE ?!"),
        );

        $this->assertSame(
            AiSelfKnowledge::TOPIC_CAPABILITIES,
            $selfKnowledge->topicFor('Que puis-je faire ici ?'),
        );
    }

    /** 5. Une erreur de self-knowledge ne devient jamais une erreur utilisateur. */
    public function test_a_self_knowledge_failure_falls_back_to_the_legacy_provider(): void
    {
        // Le catalogue leve : le cas exact que l'arbitrage fail-open nomme.
        // Le tour doit repartir chez le provider legacy — jamais un 500, jamais
        // une reponse degradee.
        $this->app->bind(AiCapabilityCatalogue::class, fn () => new class extends AiCapabilityCatalogue
        {
            public function forMember(Organization $organization, User $user): array
            {
                throw new \RuntimeException('Catalogue indisponible.');
            }
        });

        $this->fakeClarifier();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Que puis-je faire ici ?')
            ->call('send');

        $answer = $this->lastAnswer();

        // Le provider legacy a bien repondu — statut ANSWERED, producteur SDK.
        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status']);
        $this->assertSame('laravel_ai_sdk', $answer->metadata['producer']);
        $this->assertSame(1, AiInteraction::query()->count());
    }

    // =====================================================================
    // B. Version du prompt : v3 autoritaire, v1/v2 legacy
    // =====================================================================

    /** 6. Sous un prompt v2 actif, `interaction_fit=false` ne change RIEN. */
    public function test_under_an_active_v2_prompt_a_false_interaction_fit_stays_legacy(): void
    {
        $this->activatePromptVersion(2);
        $this->fakeClarifier(interactionFit: false);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Merci beaucoup pour votre aide.')
            ->call('send');

        $answer = $this->lastAnswer();

        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status']);
        $this->assertArrayHasKey('title', $answer->metadata);
    }

    /** 7. Sous un prompt v3 actif, `interaction_fit=false` devient autoritaire. */
    public function test_under_an_active_v3_prompt_a_false_interaction_fit_produces_a_non_interaction_turn(): void
    {
        $this->assertSame(3, $this->activePromptVersion(), 'La v3 doit etre active par defaut apres migration.');

        $this->fakeClarifier(interactionFit: false);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Merci beaucoup pour votre aide.')
            ->call('send');

        $answer = $this->lastAnswer();

        $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $answer->metadata['status']);
        $this->assertSame(__('ai.shell_answer_non_interaction'), $answer->content);
    }

    /**
     * 8. Fail-open sur la valeur : absente, nulle ou non booleenne, meme sous
     * v3, le tour reste legacy.
     */
    public function test_an_absent_null_or_non_boolean_interaction_fit_stays_legacy_even_under_v3(): void
    {
        foreach ([null, 'false', 0, []] as $index => $invalid) {
            AiShellMessage::query()->delete();

            $structured = $this->structured();

            if ($index === 0) {
                unset($structured['interaction_fit']);
            } else {
                $structured['interaction_fit'] = $invalid;
            }

            $this->fakeStructured($structured);

            Livewire::actingAs($this->member)
                ->test(AiShell::class)
                ->set('draft', 'Un enonce quelconque.')
                ->call('send');

            $this->assertSame(
                AiShellResponder::STATUS_ANSWERED,
                $this->lastAnswer()->metadata['status'],
                'Une valeur non booleenne ne fait jamais autorite.',
            );
        }
    }

    /** 9. Aucun prompt actif : l'indisponibilite explicite est CONSERVEE. */
    public function test_with_no_active_prompt_the_shell_keeps_its_explicit_unavailability(): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'clarify_help_request')->update(['is_active' => false]);

        $this->fakeClarifier();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Un enonce quelconque.')
            ->call('send');

        $answer = $this->lastAnswer();

        // Aucun prompt legacy n'est invente : le Shell dit qu'il ne peut pas.
        $this->assertSame(AiShellResponder::STATUS_UNAVAILABLE, $answer->metadata['status']);
        $this->assertSame(__('ai.shell_answer_unavailable'), $answer->content);
    }

    // =====================================================================
    // C. Le tour NON_INTERACTION
    // =====================================================================

    /** 10. Metadata MINIMALE : quatre cles admises, pas une de plus. */
    public function test_a_non_interaction_turn_carries_a_minimal_metadata(): void
    {
        $this->fakeClarifier(interactionFit: false);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Bonjour !')
            ->call('send');

        $metadata = $this->lastAnswer()->metadata;

        $this->assertSame(
            ['page_context', 'producer', 'status'],
            collect(array_keys($metadata))->sort()->values()->all(),
        );

        foreach (['title', 'message_draft', 'suggested_loop_id', 'suggested_category', 'cards', 'intent', 'context', 'confidence', 'scenario', 'expected_help_type'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $metadata);
        }
    }

    /** 11. Le contexte epingle reste trace quand il existe — et lui seul s'ajoute. */
    public function test_a_non_interaction_turn_still_traces_pinned_context(): void
    {
        $this->fakeClarifier(interactionFit: false);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('pin', 'loop', (string) $this->loop->id)
            ->set('draft', 'Bonjour !')
            ->call('send');

        $metadata = $this->lastAnswer()->metadata;

        $this->assertSame(
            ['page_context', 'pinned_context', 'producer', 'status'],
            collect(array_keys($metadata))->sort()->values()->all(),
        );
    }

    /** 12. Aucun titre a l'ecran, et aucune carte. */
    public function test_a_non_interaction_turn_renders_no_title_and_no_card(): void
    {
        $this->fakeClarifier(interactionFit: false);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Bonjour !')
            ->call('send')
            ->assertDontSee('data-ai-shell-answer-title', false)
            ->assertDontSee('data-ai-shell-cards', false)
            ->assertSee(__('ai.shell_answer_non_interaction'));
    }

    /** 13. `forDisplay()` rend un tableau vide sur un tour NON_INTERACTION. */
    public function test_for_display_is_empty_on_a_non_interaction_turn(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->member, 'Bonjour !');
        $answer = $thread->appendAssistant($this->organization, $this->member, 'Reponse canonique.', $trigger, [
            'status' => AiShellResponder::STATUS_NON_INTERACTION,
            'producer' => 'laravel_ai_sdk',
            // Meme si une reference de carte etait forgee dans la metadata,
            // le statut interdit son rendu.
            'cards' => [['type' => 'loop', 'id' => (string) $this->loop->id, 'ai_wording' => null]],
        ]);

        $this->assertSame([], app(AiShellTurnCards::class)->forDisplay($this->organization, $this->member, $answer));
    }

    /** 14. `prepareRequest()` est impossible sur un tour NON_INTERACTION. */
    public function test_prepare_request_is_impossible_on_a_non_interaction_turn(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->member, 'Bonjour !');
        $answer = $thread->appendAssistant($this->organization, $this->member, 'Reponse canonique.', $trigger, [
            'status' => AiShellResponder::STATUS_NON_INTERACTION,
            'producer' => 'laravel_ai_sdk',
        ]);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('prepareRequest', $answer->id)
            ->assertNoRedirect();

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->member, $this->organization));
    }

    // =====================================================================
    // D. L'offre
    // =====================================================================

    /** 15. Une offre reste une Interaction valide, avec le CTA « Proposer de l'aide ». */
    public function test_an_offer_gets_the_offer_help_cta_and_never_the_request_cta(): void
    {
        $this->fakeClarifier(
            interactionFit: true,
            helpType: 'service_offer',
            suggestedLoopId: (string) $this->loop->id,
        );

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je peux aider sur Laravel.')
            ->call('send')
            ->assertSee('data-ai-shell-card-action="offer_help"', false)
            ->assertDontSee('data-ai-shell-card-action="prepare_request"', false)
            ->assertSee(route('organization.services.create', ['organization' => $this->organization->slug]), false);

        $answer = $this->lastAnswer();

        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status']);
        $this->assertSame(AiShellTurnCards::INTENT_OFFER, $answer->metadata['intent']);
    }

    /** 16. Aucune PersonCard sur une offre — et la preuve qu'il y en aurait une sinon. */
    public function test_an_offer_carries_no_person_card_while_a_help_request_does(): void
    {
        // Temoin : la MEME situation, en demande d'aide.
        $this->fakeClarifier(
            interactionFit: true,
            helpType: 'information',
            suggestedLoopId: (string) $this->loop->id,
            clarified: 'Je cherche quelqu\'un sur Laravel.',
        );

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche de l\'aide sur Laravel.')
            ->call('send');

        $withRequest = collect($this->lastAnswer()->metadata['cards'])->pluck('type');
        $this->assertTrue($withRequest->contains(AiShellTurnCards::TYPE_PERSON), 'Le temoin doit produire une PersonCard.');

        AiShellMessage::query()->delete();

        // La meme chose, en OFFRE : plus aucune PersonCard.
        $this->fakeClarifier(
            interactionFit: true,
            helpType: 'service_offer',
            suggestedLoopId: (string) $this->loop->id,
            clarified: 'Je peux aider sur Laravel.',
        );

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je peux aider sur Laravel.')
            ->call('send');

        $withOffer = collect($this->lastAnswer()->metadata['cards'])->pluck('type');
        $this->assertFalse($withOffer->contains(AiShellTurnCards::TYPE_PERSON), 'Une offre n\'affiche jamais « qui peut m\'aider ».');
        $this->assertTrue($withOffer->contains(AiShellTurnCards::TYPE_LOOP));
    }

    /** 17. `prepareRequest()` refuse un tour d'offre, meme sur identifiant direct. */
    public function test_prepare_request_refuses_an_offer_turn(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->member, 'Je peux aider sur Laravel.');
        $answer = $thread->appendAssistant($this->organization, $this->member, 'Offre clarifiee.', $trigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'intent' => AiShellTurnCards::INTENT_OFFER,
            'title' => 'Offre Laravel',
            'message_draft' => 'Offre clarifiee.',
        ]);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('prepareRequest', $answer->id)
            ->assertNoRedirect();

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->member, $this->organization));
    }

    // =====================================================================
    // E. Appelants partages
    // =====================================================================

    /**
     * 18. `RequestController::formulate()` n'a pas bouge.
     *
     * Le gate NON_INTERACTION est un comportement de SHELL. Le service partage
     * rend le meme resultat qu'avant, meme quand le modele repond
     * `interaction_fit = false` sous une v3 active : c'est l'appelant qui
     * choisit de lire le verdict, pas le service qui l'impose.
     */
    public function test_the_shared_request_formulate_caller_is_not_regressed(): void
    {
        $this->fakeClarifier(interactionFit: false);

        $response = $this->actingAs($this->member)->postJson(
            route('organization.requests.ai-formulate', ['organization' => $this->organization->slug]),
            ['title' => 'Aide', 'description' => 'Merci beaucoup pour votre aide.'],
        );

        // La forme historique est intacte : 200 + une suggestion titre /
        // description, exactement comme avant TASK-1350. Le verdict
        // `interaction_fit = false` du modele n'y change RIEN.
        $response->assertOk();
        $response->assertJsonStructure(['suggestion' => ['title', 'description']]);
        $this->assertNotSame('', trim((string) data_get($response->json(), 'suggestion.title', '')));
    }

    /** 19. Le service partage expose le verdict, il ne l'applique jamais. */
    public function test_the_shared_service_exposes_the_verdict_without_acting_on_it(): void
    {
        $this->fakeClarifier(interactionFit: false);

        $result = app(ClarifyUserHelpRequestService::class)
            ->clarifyForOrganization($this->organization, $this->member, 'Merci beaucoup.');

        $this->assertFalse($result->interactionFit);
        // Le service a quand meme produit sa clarification : c'est le Shell,
        // et lui seul, qui decide d'en faire un tour NON_INTERACTION.
        $this->assertNotSame('', $result->title);
    }

    // =====================================================================
    // F. Catalogue tenant-aware
    // =====================================================================

    /** 20. Les drapeaux du tenant changent le catalogue, ON comme OFF. */
    public function test_the_capability_catalogue_follows_the_tenant_flags(): void
    {
        $catalogue = app(AiCapabilityCatalogue::class);

        $on = collect($catalogue->forMember($this->organization, $this->member))->pluck('key');
        $this->assertTrue($on->contains('loops'));
        $this->assertTrue($on->contains('create_loop'));
        $this->assertTrue($on->contains('ai_profile'));

        $this->organization->forceFill([
            'loops_enabled' => false,
            'ai_profiles_enabled' => false,
        ])->save();

        $off = collect($catalogue->forMember($this->organization->fresh(), $this->member))->pluck('key');
        $this->assertFalse($off->contains('loops'));
        $this->assertFalse($off->contains('create_loop'));
        $this->assertFalse($off->contains('ai_profile'));

        // Ce qui ne depend d'aucun drapeau reste offert, dans les deux cas.
        $this->assertTrue($off->contains('ask_help'));
        $this->assertTrue($off->contains('offer_help'));
        $this->assertTrue($off->contains('assistant'));
    }

    /** 21. Un catalogue reduit produit une reponse reduite, pas une reponse fausse. */
    public function test_the_capabilities_answer_reflects_a_reduced_tenant(): void
    {
        $this->organization->forceFill(['loops_enabled' => false, 'ai_profiles_enabled' => false])->save();

        $answer = app(AiSelfKnowledge::class)->answer(
            AiSelfKnowledge::TOPIC_CAPABILITIES,
            $this->organization->fresh(),
            $this->member,
        );

        $this->assertStringNotContainsString(__('ai.self_knowledge_capability_loops'), $answer);
        $this->assertStringContainsString(__('ai.self_knowledge_capability_ask_help'), $answer);
    }

    // =====================================================================
    // G. Cout provider
    // =====================================================================

    /** 22. Hors self-knowledge, le delta est 0 : un tour = un appel, jamais deux. */
    public function test_a_normal_turn_still_costs_exactly_one_provider_call(): void
    {
        $this->fakeClarifier();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier.')
            ->call('send');

        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // H. Legacy
    // =====================================================================

    /**
     * 23. Un tour ANSWERED ecrit AVANT TASK-1350 — donc sans `interaction_fit`,
     * sans `intent` et sans `cta` — se relit exactement comme avant.
     */
    public function test_an_answered_turn_written_before_this_task_is_unchanged(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->member, 'Ma question historique.');
        $answer = $thread->appendAssistant($this->organization, $this->member, 'Brouillon historique.', $trigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'producer' => 'laravel_ai_sdk',
            'title' => 'Titre historique',
            'message_draft' => 'Brouillon historique.',
            'suggested_loop_id' => (string) $this->loop->id,
            'cards' => [['type' => 'loop', 'id' => (string) $this->loop->id, 'ai_wording' => null]],
        ]);

        // Le titre s'affiche toujours, et le CTA reste « Preparer ma demande ».
        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->assertSee('data-ai-shell-answer-title', false)
            ->assertSee('Titre historique')
            ->assertSee('data-ai-shell-card-action="prepare_request"', false)
            ->assertDontSee('data-ai-shell-card-action="offer_help"', false);

        // Et « Preparer ma demande » fonctionne toujours.
        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('prepareRequest', $answer->id)
            ->assertRedirect(route('organization.requests.create', ['organization' => $this->organization->slug]));
    }

    /** 24. La carte d'un tour legacy garde son CTA par defaut. */
    public function test_a_legacy_card_reference_defaults_to_the_request_cta(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->member, 'Question.');
        $answer = $thread->appendAssistant($this->organization, $this->member, 'Reponse.', $trigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'cards' => [['type' => 'loop', 'id' => (string) $this->loop->id, 'ai_wording' => null]],
        ]);

        $cards = app(AiShellTurnCards::class)->forDisplay($this->organization, $this->member, $answer);

        $this->assertSame(AiShellTurnCards::CTA_PREPARE_REQUEST, $cards[0]['cta']);
        $this->assertNull($cards[0]['cta_url']);
    }

    // =====================================================================
    // I. Shell global
    // =====================================================================

    /** 25. Une page sans action IA ne dit plus que l'IA n'y sert a rien. */
    public function test_a_page_without_ai_action_still_offers_the_conversation(): void
    {
        $response = $this->actingAs($this->member)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(__('ai.fab_no_page_action'));
        $response->assertDontSee('Ici, aucune action IA');
    }

    // =====================================================================
    // J. Honnetete quand l'IA generative n'est pas utilisable (P0 31/08 22h47)
    // =====================================================================

    /**
     * 26. Le defaut observe pendant la validation : le Shell venait de repondre
     * deux fois, puis annoncait « L'IA n'est pas disponible ». Il ne le dit
     * plus — il distingue ce qu'il sait encore faire de ce qui manque.
     */
    public function test_the_shell_never_claims_all_ai_is_down_while_its_deterministic_answers_work(): void
    {
        // Clarification generative indisponible — exactement l'etat du banc.
        config(['ai.clarify.enabled' => false]);

        $shell = Livewire::actingAs($this->member)->test(AiShell::class);

        $shell->set('draft', "C'est quoi une Boucle ?")->call('send');
        $shell->set('draft', 'Que puis-je faire ici ?')->call('send');
        $shell->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')->call('send');

        $answers = $this->answers();
        $this->assertCount(3, $answers);

        // Les deux premieres restent de vraies reponses, sans provider.
        $this->assertSame(AiSelfKnowledge::PRODUCER, $answers[0]->metadata['producer']);
        $this->assertSame(AiSelfKnowledge::PRODUCER, $answers[1]->metadata['producer']);

        // La troisieme est honnete : elle ne nie pas ce qui precede.
        $third = $answers[2];
        $this->assertSame(AiShellResponder::STATUS_UNAVAILABLE, $third->metadata['status']);
        $this->assertSame(__('ai.shell_answer_request_preparation_unavailable'), $third->content);
        $this->assertStringNotContainsString(__('ai.shell_answer_unavailable'), $third->content);

        // Aucun appel provider n'a eu lieu sur aucun des trois tours.
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    /** 27. Une organisation SANS credential : meme message honnete, aucun appel. */
    public function test_an_organization_without_a_credential_gets_the_honest_message(): void
    {
        // La clarification est activee : ce qui manque est le credential du
        // tenant, comme sur une organisation reellement non configuree.
        OrganizationAiSetting::query()
            ->where('organization_id', $this->organization->id)
            ->update(['api_key' => null]);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send');

        $answer = $this->lastAnswer();

        $this->assertSame(AiShellResponder::STATUS_UNAVAILABLE, $answer->metadata['status']);
        $this->assertSame(__('ai.shell_answer_request_preparation_unavailable'), $answer->content);
        $this->assertSame(0, AiInteraction::query()->count());
    }

    /** 28. Avec un credential, le pipeline normal reprend — rien n'a ete masque. */
    public function test_a_configured_organization_still_runs_the_normal_pipeline(): void
    {
        $this->fakeClarifier();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send');

        $answer = $this->lastAnswer();

        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status']);
        $this->assertSame('laravel_ai_sdk', $answer->metadata['producer']);
        $this->assertSame(1, AiInteraction::query()->count());
    }

    /**
     * 29. Organization = Tenant : une organisation sans credential ne se sert
     * JAMAIS de celui d'une autre. Le tour de A ne produit aucun appel, celui
     * de B en produit un — et il est inscrit sous B.
     */
    public function test_an_unconfigured_tenant_never_borrows_another_tenants_credential(): void
    {
        // Organisation B, configuree, avec son propre membre.
        $organizationB = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-honest-b',
            'name' => 'Org Honest B',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organizationB->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1350-b',
            'monthly_budget_usd' => 5.00,
        ]);

        $memberB = User::factory()->complete()->create([
            'organization_id' => $organizationB->id,
            'first_name' => 'Bo',
            'name' => 'Honest',
        ]);

        // A perd son credential : elle n'est plus configuree.
        OrganizationAiSetting::query()
            ->where('organization_id', $this->organization->id)
            ->update(['api_key' => null]);

        $this->fakeClarifier();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur, cote A.')
            ->call('send');

        $this->assertSame(__('ai.shell_answer_request_preparation_unavailable'), $this->lastAnswer()->content);
        $this->assertSame(0, AiInteraction::query()->count(), 'A ne doit emprunter aucun credential.');

        app()->instance('current_organization', $organizationB);

        Livewire::actingAs($memberB)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur, cote B.')
            ->call('send');

        // B a bien appele, et sa trace est inscrite sous B — jamais sous A.
        $interactions = AiInteraction::query()->get();
        $this->assertCount(1, $interactions);
        $this->assertSame((string) $organizationB->id, (string) $interactions->first()->organization_id);
    }

    /**
     * 30. Le catalogue ne promet aucun usage generatif : ses libelles restent
     * VRAIS quand la clarification generative est indisponible.
     */
    public function test_the_capability_catalogue_promises_nothing_generative(): void
    {
        config(['ai.clarify.enabled' => false]);

        $answer = app(AiSelfKnowledge::class)->answer(
            AiSelfKnowledge::TOPIC_CAPABILITIES,
            $this->organization,
            $this->member,
        );

        // La ligne « me parler » annonce une conversation sur BouclePro, ce que
        // la self-knowledge assure sans provider — et rien de plus.
        $this->assertStringContainsString(__('ai.self_knowledge_capability_assistant'), $answer);

        // Elle ne promet ni redaction, ni brouillon, ni preparation de demande.
        foreach (['rédige', 'brouillon', 'à votre place'] as $promise) {
            $this->assertStringNotContainsString($promise, __('ai.self_knowledge_capability_assistant'));
        }
    }

    /** 31. Aucune chaine du Shell ne montre « Organization » en francais. */
    public function test_no_french_shell_string_shows_the_code_word_organization(): void
    {
        $this->app->setLocale('fr');

        foreach ([
            'ai.shell_answer_unavailable',
            'ai.shell_answer_request_preparation_unavailable',
            'ai.shell_answer_non_interaction',
            'ai.shell_answer_blocked',
            'ai.fab_no_page_action',
            'ai.fab_subtitle_other',
            'ai.shell_empty_hint',
            'ai.self_knowledge_capabilities_intro',
            'ai.self_knowledge_capabilities_empty',
            'ai.self_knowledge_capabilities_outro',
            'ai.self_knowledge_capability_assistant',
        ] as $key) {
            $this->assertStringNotContainsString('Organization', __($key), "{$key} montre « Organization » en francais.");
        }
    }

    /**
     * 32. La microcopy de l'indisponibilite generative respecte ses invariants :
     * elle ne nomme aucune cause, n'expose aucune mecanique interne, ne parle
     * pas de l'organisation, et ne promet pas un parcours dont l'acces n'est
     * pas garanti ici.
     */
    public function test_the_unavailability_microcopy_names_no_cause_and_promises_nothing(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->app->setLocale($locale);
            $message = __('ai.shell_answer_request_preparation_unavailable');
            $lower = mb_strtolower($message);

            // Aucune mecanique interne, dans aucune des deux langues.
            foreach (['provider', 'credential', 'configur', 'budget', 'organisation', 'organization', 'capability', 'metadata', 'rag'] as $leak) {
                $this->assertStringNotContainsString($leak, $lower, "[{$locale}] la microcopy expose « {$leak} ».");
            }

            // Elle n'affirme pas que toute l'IA est indisponible.
            foreach (['pas disponible', 'not available', 'indisponible', 'unavailable'] as $overclaim) {
                $this->assertStringNotContainsString($overclaim, $lower, "[{$locale}] la microcopy declare l'IA indisponible.");
            }

            // Elle ne promet pas la creation manuelle : ce parcours passe par
            // EnsureProfileComplete, et ce point du code ne peut pas garantir
            // que ce membre le franchira.
            foreach (['manuellement', 'manually'] as $promise) {
                $this->assertStringNotContainsString($promise, $lower, "[{$locale}] la microcopy promet un parcours non garanti.");
            }

            // Elle dit bien ce qui reste offert, et reste courte.
            $this->assertStringContainsString('BouclePro', $message);
            $this->assertLessThan(200, mb_strlen($message), "[{$locale}] la microcopy est trop longue.");
        }

        $this->app->setLocale('fr');
    }

    /** 33. Les memes chaines existent en anglais, et ne sont pas des cles nues. */
    public function test_the_new_strings_exist_in_english_too(): void
    {
        $this->app->setLocale('en');

        foreach ([
            'ai.shell_answer_request_preparation_unavailable',
            'ai.shell_answer_non_interaction',
            'ai.shell_card_offer_help',
            'ai.fab_no_page_action',
            'ai.self_knowledge_capabilities_intro',
            'ai.self_knowledge_capability_assistant',
        ] as $key) {
            $value = __($key);
            $this->assertNotSame($key, $value, "{$key} est absente en anglais.");
            $this->assertNotSame('', trim($value));
        }
    }

    // =====================================================================
    // K. Le brouillon est celui de l'UTILISATEUR (P0 31/08 23h34)
    // =====================================================================

    /**
     * 34. Le defaut : « Je cherche un relecteur… » etait rendu comme la parole
     * de BouclePro IA. La bulle de l'assistant ne dit plus que ce que
     * l'assistant dit ; le texte a la premiere personne vit dans une carte
     * attribuee a l'utilisateur.
     */
    public function test_a_request_draft_is_never_rendered_as_the_assistants_own_speech(): void
    {
        $this->fakeClarifier(clarified: 'Je cherche un relecteur pour mon dossier Erasmus.');

        $component = Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send');

        $html = $component->html();

        // Le cadrage est bien la parole de l'assistant.
        $component->assertSee(e(__('ai.shell_request_framing')), false);

        // Le brouillon est dans SA carte, pas dans la bulle.
        $component->assertSee('data-ai-shell-request-draft', false);
        $component->assertSee(e(__('ai.shell_request_draft_heading')), false);

        // Et le titre du brouillon n'est plus affiche comme un titre de reponse.
        $component->assertDontSee('data-ai-shell-answer-title', false);

        // Le texte a la premiere personne apparait UNE seule fois cote
        // assistant : dans le corps de la carte, jamais dans la bulle.
        $this->assertStringContainsString('data-ai-shell-request-draft-body', $html);

        $bubbleBeforeCard = explode('data-ai-shell-request-draft', $html, 2)[0];
        $this->assertStringNotContainsString(
            'Je cherche un relecteur pour mon dossier Erasmus.',
            substr($bubbleBeforeCard, strpos($bubbleBeforeCard, 'data-ai-shell-message="assistant"') ?: 0),
            'Le brouillon ne doit pas apparaitre dans la bulle de l\'assistant.',
        );
    }

    /** 35. Le corps de la carte EST le brouillon de l'utilisateur, mot pour mot. */
    public function test_the_card_body_is_exactly_the_users_own_draft(): void
    {
        $draft = 'Je cherche un relecteur pour mon dossier Erasmus afin de le deposer a temps.';

        $this->fakeClarifier(clarified: $draft);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send')
            ->assertSee($draft)
            ->assertSee('Relecture Erasmus');

        // La donnee, elle, n'a pas bouge : le contrat partage est intact.
        $answer = $this->lastAnswer();
        $this->assertSame($draft, $answer->content);
        $this->assertSame($draft, $answer->metadata['message_draft']);
        $this->assertSame('Relecture Erasmus', $answer->metadata['title']);
    }

    /** 36. Le choix humain : deux boutons, et « Preparer », jamais « Publier ». */
    public function test_the_human_choice_offers_continue_and_prepare_and_never_publishes(): void
    {
        $this->fakeClarifier(clarified: 'Je cherche un relecteur.');

        $component = Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send');

        $component->assertSee('data-ai-shell-request-continue', false);
        $component->assertSee('data-ai-shell-request-prepare', false);
        $component->assertSee(e(__('ai.shell_request_continue')), false);
        $component->assertSee(e(__('ai.shell_request_prepare')), false);

        // Aucun libelle ne laisse croire a une action definitive.
        foreach (['Publier', 'Publish', 'Envoyer la demande'] as $definitive) {
            $this->assertStringNotContainsString($definitive, __('ai.shell_request_prepare'));
        }

        // Le rendu ne publie rien.
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    /**
     * 37. « Continuer a discuter » est un geste CLIENT : aucun aller-retour
     * serveur, donc aucun provider, aucune ecriture, aucun tour de plus.
     */
    public function test_continue_chatting_costs_nothing_at_all(): void
    {
        $this->fakeClarifier(clarified: 'Je cherche un relecteur.');

        $component = Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send');

        $interactions = AiInteraction::query()->count();
        $invocations = AiProviderInvocation::query()->count();
        $messages = AiShellMessage::query()->count();

        // Le bouton ne porte AUCUN `wire:click` : il ne peut pas atteindre le
        // serveur, donc il ne peut rien couter.
        $html = $component->html();
        $button = substr($html, strpos($html, 'data-ai-shell-request-continue') - 400, 500);
        $this->assertStringNotContainsString('wire:click', $button);

        // Rien n'a bouge.
        $this->assertSame($interactions, AiInteraction::query()->count());
        $this->assertSame($invocations, AiProviderInvocation::query()->count());
        $this->assertSame($messages, AiShellMessage::query()->count());
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    /** 38. « Preparer » emprunte le pipeline EXISTANT, et ne publie rien. */
    public function test_prepare_uses_the_existing_handoff_pipeline_without_publishing(): void
    {
        $this->fakeClarifier(clarified: 'Je cherche un relecteur pour mon dossier Erasmus.');

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send');

        $answer = $this->lastAnswer();
        $requests = ServiceRequest::query()->count();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('prepareRequest', $answer->id)
            ->assertRedirect(route('organization.requests.create', ['organization' => $this->organization->slug]));

        $handoff = app(HelpRequestHandoff::class)->pullDraft($this->member, $this->organization);

        $this->assertSame('Relecture Erasmus', $handoff['title']);
        $this->assertSame('Je cherche un relecteur pour mon dossier Erasmus.', $handoff['description']);

        // Preparer n'est jamais publier.
        $this->assertSame($requests, ServiceRequest::query()->count());
    }

    /** 39. Le tenant est nomme sous le choix, sans jamais franchir de frontiere. */
    public function test_the_tenant_is_named_discreetly_under_the_choice(): void
    {
        $this->fakeClarifier(clarified: 'Je cherche un relecteur.');

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send')
            ->assertSee('data-ai-shell-request-tenant', false)
            ->assertSee(e(__('ai.shell_request_tenant', ['organization' => $this->organization->name])), false);
    }

    /** 40. Les autres intentions ne recoivent JAMAIS cette carte. */
    public function test_no_other_intent_ever_gets_the_request_draft_card(): void
    {
        // Une OFFRE : elle a SA carte — meme attribution, cadrage et parcours
        // propres. Ce qu'elle n'a jamais, c'est le CTA de demande.
        $this->fakeClarifier(interactionFit: true, helpType: 'service_offer', suggestedLoopId: (string) $this->loop->id);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je peux aider sur Laravel.')
            ->call('send')
            ->assertSee('data-ai-shell-offer-prepare', false)
            ->assertDontSee('data-ai-shell-request-prepare', false)
            ->assertSee(e(__('ai.shell_offer_draft_heading')), false)
            ->assertSee(e(__('ai.shell_offer_framing')), false)
            ->assertSee('data-ai-shell-card-action="offer_help"', false);

        AiShellMessage::query()->delete();

        // Une NON-INTERACTION.
        $this->fakeClarifier(interactionFit: false);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Merci beaucoup !')
            ->call('send')
            ->assertDontSee('data-ai-shell-request-draft', false);

        AiShellMessage::query()->delete();

        // Une reponse de SELF-KNOWLEDGE.
        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', "C'est quoi une Boucle ?")
            ->call('send')
            ->assertDontSee('data-ai-shell-request-draft', false);
    }

    /** 41. Un tour ANTERIEUR a TASK-1350 garde son rendu — scope fige, §9. */
    public function test_a_pre_task_answered_turn_keeps_its_historical_rendering(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->member, 'Ma question historique.');
        $thread->appendAssistant($this->organization, $this->member, 'Brouillon historique.', $trigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'producer' => 'laravel_ai_sdk',
            'title' => 'Titre historique',
            'message_draft' => 'Brouillon historique.',
        ]);

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->assertSee('data-ai-shell-answer-title', false)
            ->assertSee('Titre historique')
            ->assertDontSee('data-ai-shell-request-draft', false);
    }

    /** 42. Le cadrage et la carte existent dans les deux langues. */
    public function test_the_request_presentation_strings_exist_in_both_languages(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach ([
                'ai.shell_request_framing',
                'ai.shell_request_draft_heading',
                'ai.shell_request_continue',
                'ai.shell_request_prepare',
                'ai.shell_request_tenant',
            ] as $key) {
                $value = __($key);
                $this->assertNotSame($key, $value, "[{$locale}] {$key} est absente.");
                $this->assertNotSame('', trim($value));
            }

            // Le cadrage attribue le brouillon, il ne l'endosse pas.
            $this->assertStringNotContainsString('Organization', __('ai.shell_request_framing'));
        }

        $this->app->setLocale('en');
        $this->assertStringContainsString('help request', __('ai.shell_request_framing'));
        $this->assertSame('Your reformulated request', __('ai.shell_request_draft_heading'));
        $this->assertSame('Continue chatting', __('ai.shell_request_continue'));
        $this->assertSame('Prepare a help request', __('ai.shell_request_prepare'));

        $this->app->setLocale('fr');
        $this->assertSame('Votre demande reformulée', __('ai.shell_request_draft_heading'));
        $this->assertSame('Continuer à discuter', __('ai.shell_request_continue'));
    }

    // =====================================================================
    // L. Le tour COURANT prime sur le transcript (P0 01/09 00h16)
    // =====================================================================

    /**
     * 43. Le prompt distingue explicitement l'arriere-plan de l'objet.
     *
     * Le defaut runtime : « Quel temps fait-il a Marseille ? » a rendu, mot
     * pour mot, le brouillon du tour precedent. Le modele n'avait pas rejoue
     * (lignes distinctes en base) — il avait choisi le mauvais objet, parce que
     * rien dans le prompt ne disait lequel etait le sien.
     */
    public function test_the_prompt_labels_the_current_turn_after_the_transcript(): void
    {
        $this->fakeClarifier();

        $shell = Livewire::actingAs($this->member)->test(AiShell::class);

        $shell->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')->call('send');
        $shell->set('draft', 'Quel temps fait-il a Marseille ?')->call('send');

        $prompt = $this->lastPrompt();
        $label = __('ai.shell_prompt_current_turn');

        // Les trois pieces sont la : l'arriere-plan, l'etiquette, l'objet.
        $this->assertStringContainsString('Echange precedent dans cette conversation :', $prompt);
        $this->assertStringContainsString($label, $prompt);
        $this->assertStringContainsString('Quel temps fait-il a Marseille ?', $prompt);

        // Et dans CET ordre : l'etiquette vient APRES le transcript et AVANT la
        // question. C'est l'ordre qui porte la hierarchie, pas la presence.
        $transcriptAt = strpos($prompt, 'Echange precedent dans cette conversation :');
        $labelAt = strpos($prompt, $label);
        $questionAt = strrpos($prompt, 'Quel temps fait-il a Marseille ?');

        $this->assertLessThan($labelAt, $transcriptAt, 'L\'etiquette doit suivre le transcript.');
        $this->assertLessThan($questionAt, $labelAt, 'L\'etiquette doit preceder la question courante.');
    }

    /**
     * 44. Sur un fil VIDE, aucune etiquette : il n'y a rien a departager, et le
     * prompt reste celui d'avant TASK-1346, a l'octet pres.
     */
    public function test_an_empty_thread_carries_no_current_turn_label(): void
    {
        $this->fakeClarifier();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Ma toute premiere question.')
            ->call('send');

        $this->assertSame('Ma toute premiere question.', $this->lastPrompt());
    }

    /** 45. L'etiquette existe dans les deux langues, et designe le tour courant. */
    public function test_the_current_turn_label_exists_in_both_languages(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->app->setLocale($locale);
            $label = __('ai.shell_prompt_current_turn');

            $this->assertNotSame('ai.shell_prompt_current_turn', $label);
            $this->assertNotSame('', trim($label));
        }

        $this->app->setLocale('fr');
    }

    /**
     * 46. Le prompt v3 porte la regle de priorite : le transcript est un
     * arriere-plan, jamais l'objet.
     */
    public function test_the_v3_prompt_states_that_the_transcript_is_only_background(): void
    {
        $v3 = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 3)
            ->value('prompt_text');

        $this->assertIsString($v3);

        foreach ([
            'ARRIÈRE-PLAN',
            'Analyse TOUJOURS le message actuel',
            'Ne reformule JAMAIS comme demande courante un besoin qui ne provient que d\'un tour précédent',
            'incompréhensible',
        ] as $rule) {
            $this->assertStringContainsString($rule, $v3, "La regle « {$rule} » manque a la v3.");
        }

        // Et la v2 ne l'a PAS : c'est ce qui justifie une version nouvelle.
        $v2 = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 2)
            ->value('prompt_text');

        $this->assertStringNotContainsString('ARRIÈRE-PLAN', (string) $v2);
    }

    /** 47. L'etiquette ne coute aucun appel provider de plus. */
    public function test_the_current_turn_label_adds_no_provider_call(): void
    {
        $this->fakeClarifier();

        $shell = Livewire::actingAs($this->member)->test(AiShell::class);

        $shell->set('draft', 'Premier tour.')->call('send');
        $shell->set('draft', 'Second tour.')->call('send');

        $this->assertSame(2, AiInteraction::query()->count());
        $this->assertSame(2, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // M. direct_reply — le Shell REPOND (01/09 00h25)
    // =====================================================================

    /**
     * 48. Le defaut que la recette a revele : le Shell ne transformait plus
     * tout en demande, mais il ne repondait toujours a rien. Avec
     * `direct_reply`, la bulle porte la parole du modele.
     */
    public function test_a_direct_reply_is_rendered_as_the_assistants_own_words(): void
    {
        $reply = 'Je ne peux pas verifier la meteo en temps reel ici. En revanche, je peux vous aider a formuler un besoin pour vos collegues.';

        $this->fakeClarifier(interactionFit: false, directReply: $reply);

        $component = Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Quel temps fait-il a Marseille ?')
            ->call('send');

        $answer = $this->lastAnswer();

        // La reponse du modele, telle quelle — plus le message canonique.
        $this->assertSame($reply, $answer->content);
        $this->assertNotSame(__('ai.shell_answer_non_interaction'), $answer->content);
        $component->assertSee($reply);

        // Et rien de ce qu'une demande porte.
        $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $answer->metadata['status']);
        $component->assertDontSee('data-ai-shell-request-draft', false);
        $component->assertDontSee('data-ai-shell-answer-title', false);
    }

    /** 49. Repondre n'est pas preparer : la metadata reste bornee, l'action impossible. */
    public function test_a_direct_reply_never_becomes_a_request(): void
    {
        $this->fakeClarifier(interactionFit: false, directReply: 'Pouvez-vous reformuler ? Je n\'ai pas compris.');

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'azerty')
            ->call('send');

        $answer = $this->lastAnswer();

        // Metadata minimale : exactement les memes quatre cles qu'avant.
        $this->assertSame(
            ['page_context', 'producer', 'status'],
            collect(array_keys($answer->metadata))->sort()->values()->all(),
        );

        // Aucune carte, aucune preparation possible.
        $this->assertSame([], app(AiShellTurnCards::class)->forDisplay($this->organization, $this->member, $answer));

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('prepareRequest', $answer->id)
            ->assertNoRedirect();

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->member, $this->organization));
    }

    /**
     * 50. Le repli : champ absent, vide ou non textuel, la bulle porte le
     * message canonique. Une bulle vide serait pire que tout.
     */
    public function test_an_absent_or_invalid_direct_reply_falls_back_to_the_canonical_message(): void
    {
        foreach ([null, '', '   ', 42, []] as $index => $invalid) {
            AiShellMessage::query()->delete();

            $structured = $this->structured(interactionFit: false);

            if ($index === 0) {
                unset($structured['direct_reply']);
            } else {
                $structured['direct_reply'] = $invalid;
            }

            $this->fakeStructured($structured);

            Livewire::actingAs($this->member)
                ->test(AiShell::class)
                ->set('draft', 'Merci beaucoup !')
                ->call('send');

            $answer = $this->lastAnswer();

            $this->assertSame(__('ai.shell_answer_non_interaction'), $answer->content);
            $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $answer->metadata['status']);
        }
    }

    /** 51. Sous un prompt v2, `direct_reply` n'a AUCUNE autorite — legacy strict. */
    public function test_a_direct_reply_is_ignored_under_a_v2_prompt(): void
    {
        $this->activatePromptVersion(2);
        $this->fakeClarifier(interactionFit: false, directReply: 'Cette phrase ne doit jamais atteindre l\'ecran.');

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Merci beaucoup !')
            ->call('send');

        $answer = $this->lastAnswer();

        // Legacy : le tour reste une reponse ANSWERED, et la phrase du modele
        // n'est pas servie comme parole conversationnelle.
        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status']);
        $this->assertNotSame('Cette phrase ne doit jamais atteindre l\'ecran.', $answer->content);
    }

    /** 52. Une INTERACTION valide laisse `direct_reply` de cote. */
    public function test_a_valid_interaction_ignores_direct_reply_and_keeps_the_request_pipeline(): void
    {
        $this->fakeClarifier(
            interactionFit: true,
            clarified: 'Je cherche un relecteur pour mon dossier Erasmus.',
            directReply: 'Cette phrase ne doit pas etre servie.',
        );

        $component = Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je cherche un relecteur pour mon dossier Erasmus.')
            ->call('send');

        $answer = $this->lastAnswer();

        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status']);
        $this->assertSame('Je cherche un relecteur pour mon dossier Erasmus.', $answer->content);
        $component->assertSee('data-ai-shell-request-draft', false);
        $component->assertDontSee('Cette phrase ne doit pas etre servie.');
    }

    /** 53. Le schema de l'agent porte `direct_reply` et son contrat d'honnetete. */
    public function test_the_agent_schema_declares_direct_reply_with_its_honesty_contract(): void
    {
        $agent = new HelpRequestClarifierAgent('instructions de test');
        $schema = $agent->schema(new JsonSchemaTypeFactory);

        $this->assertArrayHasKey('direct_reply', $schema);
        $this->assertArrayHasKey('interaction_fit', $schema);

        // `direct_reply` vient AVANT la redaction : le modele decide, repond,
        // puis seulement redige s'il y a lieu.
        $keys = array_keys($schema);
        $this->assertLessThan(array_search('title', $keys, true), array_search('interaction_fit', $keys, true));
        $this->assertLessThan(array_search('title', $keys, true), array_search('direct_reply', $keys, true));
    }

    /** 54. Le prompt v3 porte la semantique resserree et le contrat direct_reply. */
    public function test_the_v3_prompt_carries_the_narrow_semantics_and_the_direct_reply_contract(): void
    {
        $v3 = (string) AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 3)
            ->value('prompt_text');

        foreach ([
            'UNIQUEMENT lorsque le MESSAGE ACTUEL',
            'désorientation d\'un nouveau membre',
            'Quel temps fait-il à Marseille ?',
            'azerty',
            'direct_reply',
            'pas de donnée en temps réel',
            'Tu ne publies rien',
            'laisse `direct_reply` vide',
        ] as $rule) {
            $this->assertStringContainsString($rule, $v3, "La regle « {$rule} » manque a la v3.");
        }
    }

    /** 55. Le catalogue ne promet plus une navigation qui n'existe pas. */
    public function test_the_catalogue_no_longer_promises_assisted_navigation(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->app->setLocale($locale);
            $outro = __('ai.self_knowledge_capabilities_outro');

            foreach (['emmène', 'emmene', 'take you'] as $promise) {
                $this->assertStringNotContainsString($promise, $outro, "[{$locale}] le catalogue promet une navigation inexistante.");
            }
        }

        $this->app->setLocale('fr');
        $this->assertStringContainsString('expliquer où aller', __('ai.self_knowledge_capabilities_outro'));
    }

    /** 56. Reponse plateforme : doctrine d'abord, et courte, dans les deux langues. */
    public function test_the_platform_answer_starts_from_the_doctrine_in_both_languages(): void
    {
        foreach (['fr' => 'pédagogie par l\'entraide', 'en' => 'learning through mutual aid'] as $locale => $needle) {
            $this->app->setLocale($locale);

            $answer = app(AiSelfKnowledge::class)->answer(
                AiSelfKnowledge::TOPIC_PLATFORM,
                $this->organization,
                $this->member,
            );

            $this->assertStringContainsString($needle, $answer);
            $this->assertLessThan(320, mb_strlen($answer), "[{$locale}] la reponse plateforme est trop longue.");
        }

        $this->app->setLocale('fr');
    }

    /**
     * 57. Une OFFRE non plus n'est jamais rendue comme la parole de l'IA.
     *
     * La recette l'a montre : le modele peut qualifier « Je cherche un
     * relecteur » en `service_offer`. La branche offre reaffichait alors le
     * brouillon a la premiere personne dans la bulle — le defaut meme qu'on
     * ferme. Une offre est ecrite a la premiere personne tout autant qu'une
     * demande ; seuls le cadrage, l'intitule et le parcours changent.
     */
    public function test_an_offer_draft_is_never_rendered_as_the_assistants_own_speech(): void
    {
        $draft = 'Je peux aider sur Laravel, notamment sur les migrations et les tests.';

        $this->fakeClarifier(interactionFit: true, helpType: 'service_offer', clarified: $draft);

        $component = Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je peux aider sur Laravel.')
            ->call('send');

        $html = $component->html();

        $component->assertSee(e(__('ai.shell_offer_framing')), false);
        $component->assertSee(e(__('ai.shell_offer_draft_heading')), false);
        $component->assertSee($draft);
        $component->assertDontSee('data-ai-shell-answer-title', false);

        // Le brouillon n'est pas dans la bulle : il est dans la carte.
        $beforeCard = explode('data-ai-shell-request-draft', $html, 2)[0];
        $this->assertStringNotContainsString($draft, substr($beforeCard, strpos($beforeCard, 'data-ai-shell-message="assistant"') ?: 0));

        // Et le parcours est celui de l'offre.
        $component->assertSee(route('organization.services.create', ['organization' => $this->organization->slug]), false);
    }

    /** 58. Le CTA d'une offre ne prepare jamais une demande, meme force. */
    public function test_an_offer_card_never_exposes_the_request_cta(): void
    {
        $this->fakeClarifier(interactionFit: true, helpType: 'service_offer', clarified: 'Je peux relire vos dossiers.');

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', 'Je peux relire vos dossiers.')
            ->call('send')
            ->assertDontSee('data-ai-shell-request-prepare', false)
            ->assertDontSee(e(__('ai.shell_request_prepare')), false);

        // Et la porte serveur reste fermee (deja prouve en 17, rejoue ici sur
        // le tour REEL produit par le pipeline).
        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('prepareRequest', $this->lastAnswer()->id)
            ->assertNoRedirect();
    }

    /** 59. Le prompt v3 interdit de qualifier une recherche en offre. */
    public function test_the_v3_prompt_forbids_labelling_a_search_as_an_offer(): void
    {
        $v3 = (string) AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 3)
            ->value('prompt_text');

        $this->assertStringContainsString('UNIQUEMENT quand le membre OFFRE', $v3);
        $this->assertStringContainsString('n\'est JAMAIS `service_offer`', $v3);
        $this->assertStringContainsString('est une demande, pas une offre', $v3);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return Collection<int, AiShellMessage> */
    private function answers()
    {
        return AiShellMessage::query()
            ->where('organization_id', $this->organization->id)
            ->where('user_id', $this->member->id)
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->orderBy('created_at')
            ->get();
    }

    /** Le prompt REELLEMENT parti au modele, relu sur l'interaction tracee. */
    private function lastPrompt(): string
    {
        $interaction = AiInteraction::query()->orderByDesc('created_at')->first();

        $this->assertInstanceOf(AiInteraction::class, $interaction, 'Aucun appel provider trace.');

        return (string) $interaction->prompt;
    }

    private function lastAnswer(): AiShellMessage
    {
        $answer = $this->answers()->last();

        $this->assertInstanceOf(AiShellMessage::class, $answer, 'Le tour doit avoir produit une reponse.');

        return $answer;
    }

    private function activePromptVersion(): ?int
    {
        return AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->value('version');
    }

    private function activatePromptVersion(int $version): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'clarify_help_request')->update(['is_active' => false]);

        $updated = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', $version)
            ->update(['is_active' => true]);

        $this->assertSame(1, $updated, "Le prompt v{$version} doit exister.");
    }

    /** @return array<string, mixed> */
    private function structured(
        ?bool $interactionFit = null,
        string $helpType = 'information',
        string $suggestedLoopId = '',
        string $clarified = 'Cadrer la relecture du dossier.',
        string $directReply = '',
    ): array {
        return [
            'interaction_fit' => $interactionFit,
            'direct_reply' => $directReply,
            'title' => 'Relecture Erasmus',
            'clarified_request' => $clarified,
            'help_type' => $helpType,
            'suggested_loop_id' => $suggestedLoopId,
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];
    }

    private function fakeClarifier(
        ?bool $interactionFit = null,
        string $helpType = 'information',
        string $suggestedLoopId = '',
        string $clarified = 'Cadrer la relecture du dossier.',
        string $directReply = '',
    ): void {
        $this->fakeStructured($this->structured($interactionFit, $helpType, $suggestedLoopId, $clarified, $directReply));
    }

    /** @param  array<string, mixed>  $structured */
    private function fakeStructured(array $structured): void
    {
        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openai', 'gpt-4o-mini'),
        ));
    }
}
