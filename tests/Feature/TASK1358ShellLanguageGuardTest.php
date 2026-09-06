<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
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
 * TASK-1358 — le Shell repond dans la langue de l'INTERFACE.
 *
 * ## Le defaut mesure
 *
 * Interface en anglais, « I am new here. What can I do? » recevait une reponse
 * en francais. Le prompt administrable actif (`clarify_help_request` v3) est
 * redige en francais, et le chemin `direct_reply` est une reponse LIBRE : elle
 * herite de la langue de ses INSTRUCTIONS.
 *
 * Les deux autres comportements de langue du Shell etaient deja corrects, et
 * pour une raison differente : la self-knowledge est interceptee localement,
 * et un brouillon de demande est un champ STRUCTURE, qui recopie la matiere de
 * l'utilisateur. « L'UI est en anglais » et « le brouillon est en anglais » ne
 * prouvaient donc rien sur le chemin casse.
 *
 * ## Ce que ce fichier prouve
 *
 *  A. EN — l'instruction est presente, et en TETE.
 *  B. FR — le prompt reste OCTET-EXACT : aucune ligne ajoutee.
 *  C. ORDRE — langue, lieu, pins, transcript, etiquette, question.
 *  D. APPELANTS PARTAGES — les deux autres appelants du service partage
 *     n'heritent de rien.
 *  E. COUT — un tour reste un tour.
 *
 * ## Note de methode sur la locale
 *
 * `Livewire::test()` ne traverse PAS le groupe de middleware `web` : seul
 * `ResolveOrganization` est persistant (`AppServiceProvider`). La locale doit
 * donc y etre posee comme `SetLocale` l'aurait posee. Que `preferred_locale`
 * produise REELLEMENT cette locale sur une vraie requete est le contrat de
 * `SetLocaleMiddlewareTest::test_user_preferred_locale_en_takes_priority_over_org_fr()`,
 * deja verte — elle n'est pas re-prouvee ici. Le test G ferme malgre tout la
 * chaine de bout en bout sur une requete HTTP reelle.
 */
class TASK1358ShellLanguageGuardTest extends TestCase
{
    use RefreshDatabase;

    private const MEMORY_HEADER = 'Echange precedent dans cette conversation :';

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-lang-guard',
            'name' => 'Org Lang Guard',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1358-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Nadia',
            'name' => 'Guard',
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
    // A. Anglais — la garde est posee
    // =====================================================================

    /** 1. Locale EN, fil vide : l'instruction anglaise est dans le prompt. */
    public function test_an_english_locale_puts_the_language_instruction_in_the_prompt(): void
    {
        $this->fakeClarifier();
        $this->asEnglishInterface();

        $this->send('I am new here. What can I do?');

        $prompt = $this->lastPrompt();

        $this->assertStringContainsString('you must reply to the member in English', $prompt);
        $this->assertStringContainsString('I am new here. What can I do?', $prompt);
    }

    /**
     * 2. L'instruction ouvre le prompt.
     *
     * En tete, et pas ailleurs : un transcript long ne doit jamais pouvoir la
     * repousser hors du budget du modele.
     */
    public function test_the_language_instruction_opens_the_prompt(): void
    {
        $this->fakeClarifier();
        $this->asEnglishInterface();

        $this->send('I am new here. What can I do?');

        $this->assertStringStartsWith(
            __('ai.shell_prompt_language_guard', [], 'en'),
            $this->lastPrompt()
        );
    }

    // =====================================================================
    // B. Francais — rien ne bouge, a l'octet pres
    // =====================================================================

    /**
     * 3. Locale FR, fil vide : le prompt EST la question, a l'octet pres.
     *
     * C'est l'invariant de TASK-1346 (`test_an_empty_thread_produces_the_exact_previous_prompt`),
     * re-affirme ici depuis la TASK qui aurait pu le casser. Arbitrage MASTER :
     * `ONLY_IF_DIFFERENT` — la garde ne se declenche pas quand la locale est
     * deja celle du prompt administrable.
     */
    public function test_a_french_locale_leaves_the_prompt_byte_exact(): void
    {
        $this->fakeClarifier();

        $question = 'Je suis nouveau ici. Que puis-je faire ?';

        $this->send($question);

        $this->assertSame($question, $this->lastPrompt());
    }

    /** 4. Et l'instruction francaise n'apparait nulle part. */
    public function test_the_french_path_never_carries_a_language_instruction(): void
    {
        $this->fakeClarifier();

        $this->send('Je cherche un relecteur pour mon dossier Erasmus.');

        $this->assertStringNotContainsString('IMPORTANT', $this->lastPrompt());
    }

    // =====================================================================
    // C. L'ordre du prompt
    // =====================================================================

    /**
     * 5. Langue, puis transcript, puis etiquette du tour, puis question.
     *
     * L'ordre n'est pas cosmetique : il dit au modele ce qu'il lit, et dans
     * quel role. La garde s'insere en amont sans deplacer les autres blocs.
     */
    public function test_the_language_instruction_precedes_transcript_and_current_turn(): void
    {
        $this->fakeClarifier();
        $this->asEnglishInterface();

        $shell = Livewire::actingAs($this->member)->test(AiShell::class);
        $shell->set('draft', 'I work in vocational training in Marseille.')->call('send');
        $shell->set('draft', 'Which city do I already work in?')->call('send');

        $prompt = $this->lastPrompt();

        $guard = mb_strpos($prompt, 'you must reply to the member in English');
        $transcript = mb_strpos($prompt, self::MEMORY_HEADER);
        $currentTurn = mb_strpos($prompt, __('ai.shell_prompt_current_turn', [], 'en'));
        $question = mb_strpos($prompt, 'Which city do I already work in?');

        $this->assertNotFalse($guard);
        $this->assertNotFalse($transcript);
        $this->assertNotFalse($currentTurn);
        $this->assertNotFalse($question);

        $this->assertTrue(
            $guard < $transcript && $transcript < $currentTurn && $currentTurn < $question,
            'Ordre attendu : langue -> transcript -> etiquette du tour courant -> question.'
        );
    }

    // =====================================================================
    // D. Les appelants partages
    // =====================================================================

    /**
     * 6. `ClarifyUserHelpRequestService` est PARTAGE par trois appelants. La
     *    garde vit dans `AiShellResponder::situated()`, qui est `private` :
     *    la formulation d'une demande ne peut donc rien en heriter.
     *
     * Arbitrage MASTER : `SHELL_GUARD_INCLUDES_REQUEST_CONTROLLER = NO`. Le
     * chemin n'est pas corrige par symetrie tant qu'aucun defaut propre n'y a
     * ete reproduit — mais il ne doit pas non plus etre contamine.
     */
    public function test_the_request_formulation_path_never_receives_the_shell_guard(): void
    {
        $this->fakeClarifier();
        $this->member->forceFill(['preferred_locale' => 'en'])->saveQuietly();

        $this->actingAs($this->member)
            ->postJson(route('requests.ai-formulate'), [
                'description' => 'I need someone to proofread my Erasmus application.',
            ])
            ->assertOk();

        $interaction = AiInteraction::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

        // Le prompt de la formulation est bien parti, et il ne porte AUCUNE
        // ligne du Shell : ni la garde de langue, ni l'etiquette du tour.
        $this->assertStringContainsString('Erasmus', (string) $interaction->prompt);
        $this->assertStringNotContainsString(
            'you must reply to the member in English',
            (string) $interaction->prompt
        );
    }

    // =====================================================================
    // E. Le cout
    // =====================================================================

    /** 7. Un tour reste un tour : une interaction, une invocation provider. */
    public function test_the_guard_does_not_add_a_provider_call(): void
    {
        $this->fakeClarifier();
        $this->asEnglishInterface();

        $this->send('I am new here. What can I do?');

        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, AiProviderInvocation::query()->count());
    }

    /**
     * 8. La self-knowledge n'appelle toujours AUCUN provider.
     *
     * Elle s'intercale avant `generate()`, donc avant `situated()` : la garde
     * ne peut structurellement pas la reveiller. Ce test le mesure au lieu de
     * le supposer.
     */
    public function test_self_knowledge_still_calls_no_provider(): void
    {
        $this->fakeClarifier();
        $this->asEnglishInterface();

        $this->send('What is BouclePro?');

        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // F. La chaine complete
    // =====================================================================

    /**
     * 9. De bout en bout, sur une vraie requete HTTP : un membre dont le
     *    `preferred_locale` vaut `en` voit l'interface en anglais.
     *
     * Ferme la chaine que les tests ci-dessus abregent volontairement.
     */
    public function test_a_member_with_an_english_preference_gets_an_english_interface(): void
    {
        $this->member->forceFill(['preferred_locale' => 'en'])->saveQuietly();

        $this->actingAs($this->member)->get(route('dashboard'))->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * La locale telle que `SetLocale` l'aurait posee sur une requete d'un
     * membre anglophone. Voir la note de methode en tete de classe.
     */
    private function asEnglishInterface(): void
    {
        $this->member->forceFill(['preferred_locale' => 'en'])->saveQuietly();

        app()->setLocale('en');
    }

    private function send(string $draft): void
    {
        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', $draft)
            ->call('send');
    }

    /** Le prompt REELLEMENT parti au modele, relu depuis la trace du tour. */
    private function lastPrompt(): string
    {
        $interaction = AiInteraction::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();

        return (string) $interaction->prompt;
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Proofreading',
            'clarified_request' => 'Frame our proofreading.',
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
