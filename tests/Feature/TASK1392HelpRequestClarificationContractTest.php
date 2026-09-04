<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1392 — une intention ambigue pose ses questions avant de proposer un
 * brouillon.
 *
 * ## Le defaut, MESURE
 *
 * La chaine existe et fonctionne, sauf sur son dernier metre :
 *
 * | etape | etat |
 * |---|---|
 * | le provider renvoie `questions_for_user` | OK — champ `required` du schema de `HelpRequestClarifierAgent` |
 * | le service les publie dans `fallback['questions']` | OK — `ClarifyUserHelpRequestService` |
 * | la surface LOOP les affiche | OK — `loops/show.blade.php` |
 * | **le Shell les transmet a sa vue** | **NON** |
 *
 * `AiShellResponder::generate()` assemble la metadata du tour ANSWERED sans
 * jamais y mettre ni `questions`, ni `fallback`. La donnee s'arrete la : aucune
 * condition d'affichage n'existe cote Shell, parce qu'il n'y a rien a
 * afficher.
 *
 * Consequence produit : le membre pose une demande vague, le modele DIT qu'il
 * a besoin de precisions — et le Shell lui presente quand meme un brouillon
 * redige a la premiere personne, comme s'il avait compris. La question posee
 * est perdue, et l'assurance affichee est fausse.
 *
 * ## Ce que la tranche mesure
 *
 * Les deux sens. Quand des questions existent : elles s'affichent, et le
 * brouillon assertif ne s'affiche pas. Quand il n'y en a pas : rien ne change,
 * octet pour octet.
 */
class TASK1392HelpRequestClarificationContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-task1392',
            'name' => 'Org TASK-1392',
            'loops_enabled' => true,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1392-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Ada',
            'name' => 'Clarification',
        ]);

        app()->instance('current_organization', $this->organization);

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
    // Quand le modele demande des precisions
    // =====================================================================

    /**
     * Les questions arrivent jusqu'a la metadata du tour.
     *
     * La mesure la plus directe du defaut : elle porte sur la DONNEE, avant
     * tout rendu. Sans elle, une correction faite dans la vue masquerait que
     * la donnee n'y arrive jamais.
     */
    public function test_the_clarification_questions_reach_the_turn_metadata(): void
    {
        $this->fakeClarifier(questions: ['Pour quelle date ?', 'Dans quelle langue ?']);

        Livewire::actingAs($this->member)->test(AiShell::class)
            ->set('draft', 'j\'ai besoin d\'aide')
            ->call('send');

        $meta = $this->dernierTourAssistant();

        $this->assertSame(
            ['Pour quelle date ?', 'Dans quelle langue ?'],
            $meta['clarification_questions'] ?? null,
        );
    }

    /**
     * Le Shell AFFICHE les questions.
     *
     * La donnee transportee ne sert a rien si la vue l'ignore : la mesure
     * porte sur le RENDU. Sans elle, la cle voyagerait jusqu'a un blade qui ne
     * la lit pas — un no-op silencieux.
     */
    public function test_the_shell_displays_the_clarification_questions(): void
    {
        $this->fakeClarifier(questions: ['Pour quelle date ?', 'Dans quelle langue ?']);

        Livewire::actingAs($this->member)->test(AiShell::class)
            ->set('draft', 'j\'ai besoin d\'aide')
            ->call('send')
            ->assertSee('data-ai-shell-clarification', escape: false)
            ->assertSee('Pour quelle date ?')
            ->assertSee('Dans quelle langue ?');
    }

    /**
     * Le brouillon assertif ne s'affiche PAS a leur place.
     *
     * C'est la moitie qui compte. Afficher les questions ET le brouillon
     * laisserait intacte la faute d'origine : une carte redigee a la premiere
     * personne, presentee comme comprise, alors que le modele vient de dire
     * qu'il ne comprend pas encore.
     */
    public function test_no_assertive_draft_is_shown_while_questions_are_pending(): void
    {
        $this->fakeClarifier(
            questions: ['Pour quelle date ?'],
            clarified: 'Je cherche quelqu\'un pour relire mon dossier Erasmus.',
        );

        Livewire::actingAs($this->member)->test(AiShell::class)
            ->set('draft', 'j\'ai besoin d\'aide')
            ->call('send')
            ->assertDontSee('data-ai-shell-request-draft=', escape: false)
            ->assertDontSee('Je cherche quelqu\'un pour relire mon dossier Erasmus.');
    }

    // =====================================================================
    // Quand la clarification suffit
    // =====================================================================

    /**
     * Sans question, le tour est INCHANGE.
     *
     * Le contre-exemple, et il est indispensable : un correctif qui
     * supprimerait le brouillon dans tous les cas passerait les trois mesures
     * precedentes en cassant le parcours nominal — celui de la demonstration.
     */
    public function test_without_questions_the_answered_turn_is_unchanged(): void
    {
        $this->fakeClarifier(
            questions: [],
            clarified: 'Je cherche quelqu\'un pour relire mon dossier Erasmus.',
        );

        Livewire::actingAs($this->member)->test(AiShell::class)
            ->set('draft', 'je cherche un relecteur pour mon dossier Erasmus')
            ->call('send')
            ->assertSee('data-ai-shell-request-draft=', escape: false)
            ->assertSee('Je cherche quelqu\'un pour relire mon dossier Erasmus.')
            ->assertDontSee('data-ai-shell-clarification', escape: false);

        $meta = $this->dernierTourAssistant();

        $this->assertSame([], $meta['clarification_questions'] ?? []);
    }

    /**
     * Le bouton de preparation reste hors de portee tant qu'on questionne.
     *
     * La validation humaine n'est pas touchee par cette tranche : elle reste
     * le seul chemin de publication. Mais proposer « Preparer une demande »
     * sous une question sans reponse inviterait a sauter l'etape que la
     * question vient d'ouvrir.
     */
    public function test_the_prepare_action_is_not_offered_while_questions_are_pending(): void
    {
        $this->fakeClarifier(questions: ['Pour quelle date ?']);

        Livewire::actingAs($this->member)->test(AiShell::class)
            ->set('draft', 'j\'ai besoin d\'aide')
            ->call('send')
            ->assertDontSee('data-ai-shell-request-prepare', escape: false);
    }

    /**
     * Un tour ANTERIEUR, sans la cle, se relit inchange.
     *
     * Le fil est persiste : les tours ecrits avant cette tranche ne portent
     * aucune cle `clarification_questions`. Leur rendu ne doit pas dependre de
     * son existence — c'est la meme regle que TASK-1350 s'etait donnee pour
     * `intent`.
     */
    public function test_a_turn_written_before_this_slice_still_renders(): void
    {
        $this->fakeClarifier(questions: []);

        Livewire::actingAs($this->member)->test(AiShell::class)
            ->set('draft', 'je cherche un relecteur')
            ->call('send');

        $tour = AiShellMessage::query()
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->latest('id')
            ->firstOrFail();

        $metadata = $tour->metadata;
        unset($metadata['clarification_questions']);
        $tour->forceFill(['metadata' => $metadata])->save();

        Livewire::actingAs($this->member)->test(AiShell::class)
            ->assertOk()
            ->assertDontSee('data-ai-shell-clarification', escape: false);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $questions
     */
    private function fakeClarifier(array $questions = [], string $clarified = 'Cadrer la relecture du dossier.'): void
    {
        $structured = [
            'interaction_fit' => null,
            'direct_reply' => '',
            'title' => 'Relecture Erasmus',
            'clarified_request' => $clarified,
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => $questions,
            // Confiance haute et relecture non requise A DESSEIN : sans cela,
            // le repli se declencherait pour une autre raison que les
            // questions, et la mesure ne dirait plus laquelle.
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openai', 'gpt-4o-mini'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function dernierTourAssistant(): array
    {
        return AiShellMessage::query()
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->latest('id')
            ->firstOrFail()
            ->metadata ?? [];
    }
}
