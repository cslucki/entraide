<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiInteractionFeedback;
use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1486 (AI Quality Q1) — le Shell demande enfin si sa reponse a aide.
 *
 * ## Ce qui existait, et ce qui manquait
 *
 * `ai_interaction_feedbacks` est en place depuis TASK-1256 : deux verdicts
 * (`helpful` / `improve`), un ancrage tenant en FK CASCADE, une politique de
 * retention deja declaree. **Rien de tout cela n'est cree ici.**
 *
 * Ce qui manquait etait le BRANCHEMENT, et la mesure du 2026-09-09 dit a quel
 * point : 281 interactions IA, dix fonctions, **une seule** instrumentee — le
 * blog explorer, 26 interactions, et **zero verdict jamais recueilli**. Le
 * Shell, qui pese 87 interactions a lui seul, n'avait aucun moyen de dire si
 * sa reponse avait servi.
 *
 * ## Pourquoi le blog explorer y arrivait et pas le Shell
 *
 * Un verdict doit designer la reponse qu'il juge. `BlogExplorerController` cree
 * l'`AiInteraction` lui-meme, donc il en a l'identifiant. Le Shell passe par
 * `ClarifyUserHelpRequestService`, qui ecrivait la trace en interne et ne
 * rendait rien qui permette de la retrouver : `AssistedInteractionLabResult`
 * ne portait ni l'id de l'interaction, ni le `correlation_id`.
 *
 * ## Ce que ce fichier protege en priorite
 *
 * La section C. Un verdict ne se donne que sur SA PROPRE reponse. Sans cette
 * garde, poster l'identifiant d'une trace d'un collegue permettrait de
 * decouvrir, par la reponse du serveur, qu'elle existe.
 */
class TASK1486ShellAnswerFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $other;

    private User $member;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1486']);
        $this->other = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1486-autre']);

        foreach ([$this->organization, $this->other] as $org) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $org->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => 'sk-task1486-'.$org->id,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->member = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->colleague = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le chainon qui manquait : la reponse sait d'ou elle vient
    // =====================================================================

    /** Le service rend desormais l'identifiant de la trace qu'il vient d'ecrire. */
    public function test_the_clarifier_returns_the_id_of_the_trace_it_wrote(): void
    {
        $this->fakeClarifier();

        $answer = $this->answerATurn();

        $interactionId = $answer->metadata['ai_interaction_id'] ?? null;

        $this->assertIsString($interactionId);
        $this->assertNotSame('', $interactionId);

        $interaction = AiInteraction::query()->find($interactionId);

        $this->assertNotNull($interaction, 'le pointeur doit designer une trace REELLE');
        $this->assertSame('clarify_help_request', $interaction->feature);
        $this->assertSame((string) $this->member->id, (string) $interaction->user_id);
        $this->assertSame((string) $this->organization->id, (string) $interaction->organization_id);
    }

    /**
     * Le pointeur n'est pose QUE sur un tour REPONDU. Les statuts degrades
     * n'ont produit aucun appel provider : un verdict y designerait le vide.
     */
    public function test_a_degraded_turn_carries_no_pointer(): void
    {
        config(['ai.clarify.enabled' => false]);

        $this->respond('Ma question.');

        $answer = AiShellMessage::query()->where('role', AiShellMessage::ROLE_ASSISTANT)->latest()->firstOrFail();

        $this->assertNotSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status'] ?? null, 'premisse : le tour est degrade');
        $this->assertArrayNotHasKey('ai_interaction_id', $answer->metadata ?? []);
    }

    // =====================================================================
    // B. Le geste : un verdict s'enregistre, et se change
    // =====================================================================

    public function test_a_member_can_say_that_the_answer_helped(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('judge', (string) $answer->id, AiInteractionFeedback::VERDICT_HELPFUL);

        $feedback = AiInteractionFeedback::query()->firstOrFail();

        $this->assertSame(AiInteractionFeedback::VERDICT_HELPFUL, $feedback->verdict);
        $this->assertSame((string) $this->member->id, (string) $feedback->user_id);
        $this->assertSame((string) $this->organization->id, (string) $feedback->organization_id);
        $this->assertSame($answer->metadata['ai_interaction_id'], (string) $feedback->ai_interaction_id);
    }

    /** Un avis se change ; il ne s'empile pas. */
    public function test_a_verdict_replaces_the_previous_one_instead_of_stacking(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        $shell = Livewire::actingAs($this->member)->test(AiShell::class);
        $shell->call('judge', (string) $answer->id, AiInteractionFeedback::VERDICT_HELPFUL);
        $shell->call('judge', (string) $answer->id, AiInteractionFeedback::VERDICT_IMPROVE);

        $this->assertSame(1, AiInteractionFeedback::query()->count());
        $this->assertSame(AiInteractionFeedback::VERDICT_IMPROVE, AiInteractionFeedback::query()->firstOrFail()->verdict);
    }

    /** Une valeur inventee n'entre pas. */
    public function test_an_unknown_verdict_is_refused(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('judge', (string) $answer->id, 'excellent');

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    // =====================================================================
    // C. LA garde qui compte : on ne juge que SA propre reponse
    // =====================================================================

    /**
     * Le coeur du fichier. Un collegue de la MEME Organization ne peut pas
     * juger la reponse d'un autre : sans cette garde, poster l'identifiant
     * d'un message d'autrui revelerait, par la reponse du serveur, qu'il
     * existe.
     */
    public function test_a_colleague_cannot_judge_someone_else_answer(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        Livewire::actingAs($this->colleague)
            ->test(AiShell::class)
            ->call('judge', (string) $answer->id, AiInteractionFeedback::VERDICT_HELPFUL);

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    /** Et un membre d'une AUTRE Organization encore moins. */
    public function test_a_member_of_another_organization_cannot_judge(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        $stranger = User::factory()->complete()->create(['organization_id' => $this->other->id]);
        app()->instance('current_organization', $this->other);

        Livewire::actingAs($stranger)
            ->test(AiShell::class)
            ->call('judge', (string) $answer->id, AiInteractionFeedback::VERDICT_HELPFUL);

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    /**
     * Un identifiant de trace qui existe mais appartient a quelqu'un d'autre,
     * injecte dans la metadata d'un message a soi : la garde porte sur la
     * TRACE, pas seulement sur le message.
     */
    public function test_a_borrowed_interaction_id_is_refused(): void
    {
        $this->fakeClarifier();
        $mine = $this->answerATurn();

        $someoneElse = AiInteraction::query()->create([
            'user_id' => $this->colleague->id,
            'organization_id' => $this->organization->id,
            'feature' => 'clarify_help_request',
            'model' => 'gpt-4o-mini',
            'prompt' => 'La question d\'un collegue.',
            'response' => '{}',
            'input_tokens' => 1,
            'output_tokens' => 1,
        ]);

        $mine->forceFill(['metadata' => array_merge($mine->metadata, ['ai_interaction_id' => (string) $someoneElse->id])])->save();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('judge', (string) $mine->id, AiInteractionFeedback::VERDICT_HELPFUL);

        $this->assertSame(0, AiInteractionFeedback::query()->count(), 'la garde doit porter sur la TRACE, pas sur le message');
    }

    /** Un message qui n'est pas une reponse ne se juge pas. */
    public function test_a_user_message_cannot_be_judged(): void
    {
        $this->fakeClarifier();
        $this->answerATurn();

        $question = AiShellMessage::query()->where('role', AiShellMessage::ROLE_USER)->latest()->firstOrFail();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->call('judge', (string) $question->id, AiInteractionFeedback::VERDICT_HELPFUL);

        $this->assertSame(0, AiInteractionFeedback::query()->count());
    }

    // =====================================================================
    // D. L'ecran : discret, honnete, et jamais celui d'un autre
    // =====================================================================

    public function test_the_question_appears_under_an_answered_turn(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->assertSee('data-ai-shell-feedback="'.$answer->metadata['ai_interaction_id'].'"', false)
            ->assertSee(e(__('ai.shell_feedback_question')), false)
            ->assertSee('data-ai-shell-feedback-helpful', false)
            ->assertSee('data-ai-shell-feedback-improve', false);
    }

    /** Une fois le verdict donne, on remercie — on ne redemande pas. */
    public function test_once_judged_the_shell_stops_asking(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        $shell = Livewire::actingAs($this->member)->test(AiShell::class);
        $shell->call('judge', (string) $answer->id, AiInteractionFeedback::VERDICT_HELPFUL);

        $shell->assertSee(e(__('ai.shell_feedback_thanks')), false)
            ->assertDontSee('data-ai-shell-feedback-helpful', false)
            ->assertDontSee(e(__('ai.shell_feedback_question')), false);
    }

    /** Le verdict d'un tiers ne s'affiche jamais chez quelqu'un d'autre. */
    public function test_the_verdict_of_another_person_is_never_shown(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        AiInteractionFeedback::query()->create([
            'ai_interaction_id' => $answer->metadata['ai_interaction_id'],
            'organization_id' => $this->organization->id,
            'user_id' => $this->colleague->id,
            'verdict' => AiInteractionFeedback::VERDICT_HELPFUL,
        ]);

        // L'auteur du tour, lui, n'a rien dit : la question doit rester posee.
        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->assertSee(e(__('ai.shell_feedback_question')), false)
            ->assertDontSee(e(__('ai.shell_feedback_thanks')), false);
    }

    /** Un tour degrade ne propose rien : il n'y a rien a juger. */
    public function test_a_degraded_turn_offers_no_verdict(): void
    {
        config(['ai.clarify.enabled' => false]);
        $this->respond('Ma question.');

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->assertDontSee('data-ai-shell-feedback', false);
    }

    /**
     * Les tours ECRITS AVANT cette tranche gardent exactement leur rendu.
     *
     * Meme discipline que `intent` (TASK-1350) et `clarification_questions`
     * (TASK-1392) : l'ABSENCE de la cle vaut « rien a proposer ». Mesure du
     * 2026-09-09 sur le banc : 26 reponses historiques, dont 6 REPONDUES,
     * **zero** portant le pointeur — et 40 messages rendus dans le fil sans un
     * seul controle de verdict.
     */
    public function test_a_turn_written_before_this_slice_is_untouched(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        // On retire la cle : le message redevient exactement ce qu'il aurait
        // ete avant TASK-1486.
        $metadata = $answer->metadata;
        unset($metadata['ai_interaction_id']);
        $answer->forceFill(['metadata' => $metadata])->save();

        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->fresh()->metadata['status'], 'premisse : le tour reste REPONDU');

        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->assertDontSee('data-ai-shell-feedback', false)
            ->assertDontSee(e(__('ai.shell_feedback_question')), false);
    }

    /**
     * Un fil sans aucun tour jugeable ne coute AUCUNE requete de plus.
     *
     * C'est le cas de tous les fils ecrits avant cette tranche : ils ne doivent
     * rien payer pour une fonction qui ne les concerne pas. Mesure : on compte
     * les requetes qui touchent `ai_interaction_feedbacks` pendant un rendu.
     */
    public function test_a_thread_without_judgeable_turns_costs_no_extra_query(): void
    {
        $this->fakeClarifier();
        $answer = $this->answerATurn();

        $metadata = $answer->metadata;
        unset($metadata['ai_interaction_id']);
        $answer->forceFill(['metadata' => $metadata])->save();

        $touched = 0;
        DB::listen(function ($query) use (&$touched): void {
            if (str_contains($query->sql, 'ai_interaction_feedbacks')) {
                $touched++;
            }
        });

        Livewire::actingAs($this->member)->test(AiShell::class);

        $this->assertSame(0, $touched, 'un fil sans tour jugeable ne doit pas interroger la table des verdicts');
    }

    /** Et un fil QUI en porte un l'interroge bien — la garde n'est pas un court-circuit permanent. */
    public function test_a_thread_with_a_judgeable_turn_does_query(): void
    {
        $this->fakeClarifier();
        $this->answerATurn();

        $touched = 0;
        DB::listen(function ($query) use (&$touched): void {
            if (str_contains($query->sql, 'ai_interaction_feedbacks')) {
                $touched++;
            }
        });

        Livewire::actingAs($this->member)->test(AiShell::class);

        $this->assertGreaterThan(0, $touched);
    }

    // =====================================================================
    // E. Ce que ce geste n'est PAS
    // =====================================================================

    /**
     * Aucun consentement d'entrainement, aucun export. Le modele de TASK-1256
     * le dit par construction ; ce test le fige au niveau du SCHEMA, la ou une
     * migration future pourrait le defaire.
     */
    public function test_a_verdict_is_not_a_training_consent(): void
    {
        $columns = Schema::getColumnListing('ai_interaction_feedbacks');

        foreach (['export', 'training', 'consent', 'score', 'rank'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($forbidden, $column, "colonne interdite : {$column}");
            }
        }
    }

    /**
     * Le pointeur n'est pose QUE sur le tour repondu — et cette garde-la doit
     * etre STRUCTURELLE, pas comportementale.
     *
     * Le sabotage l'a montre : `test_a_degraded_turn_carries_no_pointer`
     * exerce le repli DETERMINISTE, et il est reste vert quand on posait le
     * pointeur sur le chemin `catch (DomainException)`, quinze lignes plus
     * haut. Le responder a trois sorties degradees et un test de comportement
     * n'en visite qu'une.
     *
     * On compte donc les occurrences a la source : UNE seule, celle du tour
     * repondu.
     */
    public function test_the_pointer_exists_on_exactly_one_path(): void
    {
        $source = php_strip_whitespace(app_path('Services/Ai/AiShellResponder.php'));

        $this->assertSame(
            1,
            substr_count($source, "'ai_interaction_id' =>"),
            'le pointeur doit etre pose sur le tour REPONDU et nulle part ailleurs'
        );

        $this->assertStringContainsString("'ai_interaction_id' => \$result->interactionId", $source);
    }

    /** Le Shell n'invente aucune table ni aucun verdict : il branche l'existant. */
    public function test_no_new_authority_is_created(): void
    {
        $this->assertSame(['helpful', 'improve'], AiInteractionFeedback::VERDICTS);
        $this->assertSame([], glob(database_path('migrations/*shell_feedback*.php')) ?: []);
        $this->assertSame([], glob(database_path('migrations/*ai_quality*.php')) ?: []);

        $source = php_strip_whitespace(app_path('Livewire/AiShell.php'));

        $this->assertStringContainsString('AiInteractionFeedback::query()->updateOrCreate', $source);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function answerATurn(string $prompt = 'Ma question.'): AiShellMessage
    {
        $this->respond($prompt);

        $answer = AiShellMessage::query()
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->latest('created_at')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(AiShellResponder::STATUS_ANSWERED, $answer->metadata['status'] ?? null, 'premisse : le tour a bien repondu');

        return $answer;
    }

    private function respond(string $prompt): void
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->member,
            $this->organization,
            null,
            null,
            'organization.dashboard',
        );

        app(AiShellResponder::class)->respond($this->organization, $this->member, $prompt, $context);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Titre',
            'clarified_request' => 'Demande clarifiee.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
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
}
