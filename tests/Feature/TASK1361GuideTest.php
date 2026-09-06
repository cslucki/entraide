<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Ai\AiSelfKnowledge;
use App\Support\Onboarding\MemberOnboardingSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1361 — le Guide : « je commence par quoi ? » et « comment rejoindre
 * une Boucle ? », repondus de facon DETERMINISTE.
 *
 * ## Ce que cette TASK ne fait pas, et c'est le coeur de l'arbitrage
 *
 * Elle ne CLASSE personne comme « nouveau ». Le produit n'a aucun signal
 * honnete de nouveaute — ni `last_login_at`, ni etat d'onboarding persiste —
 * et `created_at` serait un mauvais proxy. Avec 4 profils publies sur 57
 * membres, un seuil naif aurait etiquete ~93 % des membres.
 *
 * Le Shell repond donc a la question POSEE, et enumere ce qui reste. Aucun
 * seuil, aucun accueil spontane, aucune carte : de la prose.
 *
 * ## Une seule verite d'onboarding
 *
 * Les etapes viennent de `MemberOnboardingSteps`, extrait de
 * `DashboardController` — pas recopie. Les libelles viennent des memes cles
 * `dashboard.steps.*`. Deux sources auraient diverge a la premiere retouche.
 */
class TASK1361GuideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-guide',
            'name' => 'Org Guide',
            'ai_profiles_enabled' => true,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Noe',
            'name' => 'Guide',
            'bio' => null,
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Les formulations sont reconnues, en egalite STRICTE
    // =====================================================================

    /** 1. Les deux nouveaux sujets sont reconnus, FR et EN. */
    public function test_the_new_topics_are_recognised_in_both_languages(): void
    {
        $shell = app(AiSelfKnowledge::class);

        foreach (['Je commence par quoi ?', 'Par où commencer ?', 'I am new here', 'Where do I start?'] as $phrase) {
            $this->assertSame(AiSelfKnowledge::TOPIC_GET_STARTED, $shell->topicFor($phrase), $phrase);
        }

        foreach (['Comment rejoindre une boucle ?', 'How do I join a loop?'] as $phrase) {
            $this->assertSame(AiSelfKnowledge::TOPIC_JOIN_LOOP, $shell->topicFor($phrase), $phrase);
        }
    }

    /**
     * 2. Et une question CONTEXTUELLE n'est PAS interceptee.
     *
     * L'egalite stricte est le contrat de T1350 : une interception par
     * inclusion volerait les vraies questions, celles qu'on n'a pas imaginees.
     */
    public function test_a_contextual_question_is_never_intercepted(): void
    {
        $shell = app(AiSelfKnowledge::class);

        $this->assertNull($shell->topicFor('Je commence par quoi pour organiser un atelier de sonification ?'));
        $this->assertNull($shell->topicFor('How do I join a loop about climate data?'));
    }

    // =====================================================================
    // B. « Je commence par quoi ? » dit la verite du produit
    // =====================================================================

    /** 3. Les etapes qui RESTENT sont listees, avec les libelles du tableau de bord. */
    public function test_it_lists_the_remaining_steps_using_the_dashboard_labels(): void
    {
        $answer = app(AiSelfKnowledge::class)
            ->answer(AiSelfKnowledge::TOPIC_GET_STARTED, $this->organization, $this->member);

        $this->assertStringContainsString(__('ai.self_knowledge_get_started_intro'), $answer);
        $this->assertStringContainsString(__('dashboard.steps.presentation.title'), $answer);
        $this->assertStringContainsString(__('dashboard.steps.request.title'), $answer);
    }

    /** 4. Une etape DEJA faite n'est plus proposee. */
    public function test_a_completed_step_is_no_longer_proposed(): void
    {
        $this->member->forceFill(['bio' => 'Je travaille sur la sonification.'])->saveQuietly();

        $answer = app(AiSelfKnowledge::class)
            ->answer(AiSelfKnowledge::TOPIC_GET_STARTED, $this->organization, $this->member->fresh());

        $this->assertStringNotContainsString(__('dashboard.steps.presentation.title'), $answer);
        $this->assertStringContainsString(__('dashboard.steps.request.title'), $answer);
    }

    /**
     * 5. Un membre qui a TOUT complete recoit une reponse honnete.
     *
     * Pas une etape inventee pour avoir quelque chose a dire.
     */
    public function test_a_fully_onboarded_member_is_told_the_truth(): void
    {
        $this->completeEveryStep();

        $answer = app(AiSelfKnowledge::class)
            ->answer(AiSelfKnowledge::TOPIC_GET_STARTED, $this->organization, $this->member->fresh());

        $this->assertSame(__('ai.self_knowledge_get_started_complete'), $answer);
    }

    /**
     * 6. Une etape rendue INDISPONIBLE par l'Organization n'est pas proposee.
     *
     * Proposer le profil IA quand `ai_profiles_enabled` est faux, ce serait
     * envoyer quelqu'un vers une porte fermee.
     */
    public function test_a_step_disabled_by_the_organization_is_never_proposed(): void
    {
        $this->organization->forceFill(['ai_profiles_enabled' => false])->saveQuietly();

        $remaining = app(MemberOnboardingSteps::class)
            ->remainingFor($this->member, $this->organization->fresh());

        $this->assertNotContains(MemberOnboardingSteps::STEP_AI_PROFILE, $remaining);
        $this->assertContains(MemberOnboardingSteps::STEP_PRESENTATION, $remaining);
    }

    // =====================================================================
    // C. UNE SEULE verite d'onboarding
    // =====================================================================

    /**
     * 7. Le tableau de bord et le Guide lisent la MEME source.
     *
     * Le controleur a ete allege de sa definition en ligne ; ce test verifie
     * que la page rend toujours ses quatre etapes, avec le meme etat que
     * celui que le support rend au Shell.
     */
    public function test_the_dashboard_and_the_guide_share_one_truth(): void
    {
        $this->member->forceFill(['bio' => 'Presente.'])->saveQuietly();

        $response = $this->actingAs($this->member->fresh())->get(route('dashboard'));
        $response->assertOk();

        $steps = $response->viewData('onboardingSteps');

        $this->assertCount(4, $steps);
        $this->assertSame('done', collect($steps)->firstWhere('key', 'presentation')['status']);
        $this->assertSame('todo', collect($steps)->firstWhere('key', 'request')['status']);

        $remaining = app(MemberOnboardingSteps::class)->remainingFor($this->member->fresh(), $this->organization);

        $this->assertNotContains(MemberOnboardingSteps::STEP_PRESENTATION, $remaining);
        $this->assertContains(MemberOnboardingSteps::STEP_REQUEST, $remaining);
    }

    // =====================================================================
    // D. Le cout, et ce que le Guide n'est pas
    // =====================================================================

    /** 8. Les deux reponses ne coutent AUCUN appel provider. */
    public function test_the_guide_costs_no_provider_call(): void
    {
        $shell = app(AiSelfKnowledge::class);

        $shell->answer(AiSelfKnowledge::TOPIC_GET_STARTED, $this->organization, $this->member);
        $shell->answer(AiSelfKnowledge::TOPIC_JOIN_LOOP, $this->organization, $this->member);

        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    /**
     * 9. La reponse « rejoindre une Boucle » ne porte AUCUN lien.
     *
     * Doctrine du catalogue de capacites : la prose explique ou aller, elle
     * ne fabrique pas la destination — chaque page rejoue sa garde au clic.
     */
    public function test_the_join_loop_answer_carries_no_link(): void
    {
        $answer = app(AiSelfKnowledge::class)
            ->answer(AiSelfKnowledge::TOPIC_JOIN_LOOP, $this->organization, $this->member);

        $this->assertNotSame('', $answer);
        $this->assertStringNotContainsString('http', $answer);
        $this->assertStringNotContainsString('/loops', $answer);
    }

    private function completeEveryStep(): void
    {
        $this->member->forceFill(['bio' => 'Presente.'])->saveQuietly();

        ServiceRequest::factory()->create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
        ]);

        Service::factory()->create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
        ]);

        MemberAiProfile::factory()->create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
        ]);
    }
}
