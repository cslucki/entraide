<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1346 — memoire conversationnelle BORNEE du Shell « BouclePro IA ».
 *
 * TASK-1315 avait livre la persistance ET l'affichage du fil sans livrer son
 * INJECTION : la personne lisait son historique a l'ecran et parlait a un
 * interlocuteur qui n'avait rien recu. Ce fichier prouve que l'ecart est
 * ferme, et STRICTEMENT lui.
 *
 * Contrats prouves ici :
 *
 *  A. MEMOIRE — un second tour recoit le premier, et le message qu'on envoie
 *     n'entre jamais dans son propre contexte.
 *  B. BORNE — `ai.shell.max_context_chars` coupe du PLUS ANCIEN vers le plus
 *     recent ; le tour le plus recent survit toujours.
 *  C. PORTEE — la conversation COURANTE, et elle seule. Effacer le fil, c'est
 *     perdre la memoire ; une autre conversation du meme fil n'entre pas.
 *  D. BRUIT — une reponse technique (`unavailable` / `blocked`) n'est pas de
 *     la memoire.
 *  E. TENANT — Organization = Tenant : ni un autre utilisateur, ni une autre
 *     Organization n'entrent jamais dans le prompt.
 *  F. NON-REGRESSION — sur fil vide, le prompt est celui d'avant T1346, a
 *     l'octet pres.
 *  G. COUT — un tour reste un tour : aucun appel provider supplementaire.
 */
class TASK1346AiShellConversationMemoryTest extends TestCase
{
    use RefreshDatabase;

    /** L'entete du bloc de memoire, ecrite une fois ici comme dans le service. */
    private const MEMORY_HEADER = 'Echange precedent dans cette conversation :';

    private Organization $organizationA;

    private Organization $organizationB;

    private User $memberA;

    private User $otherMemberA;

    private User $memberB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create(['is_active' => true, 'slug' => 'org-mem-a', 'name' => 'Org Mem A']);
        $this->organizationB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-mem-b', 'name' => 'Org Mem B']);

        foreach ([$this->organizationA, $this->organizationB] as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => 'sk-task1346-'.$organization->id,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->memberA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Roger', 'name' => 'Mem']);
        $this->otherMemberA = User::factory()->complete()->create(['organization_id' => $this->organizationA->id, 'first_name' => 'Autre', 'name' => 'Mem']);
        $this->memberB = User::factory()->complete()->create(['organization_id' => $this->organizationB->id, 'first_name' => 'Bo', 'name' => 'Mem']);

        app()->instance('current_organization', $this->organizationA);

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
    // A. La memoire
    // =====================================================================

    /** 1. Le second tour recoit le premier. */
    public function test_the_second_turn_receives_the_first(): void
    {
        $this->fakeClarifier();

        $shell = Livewire::actingAs($this->memberA)->test(AiShell::class);

        $shell->set('draft', 'Je travaille dans la formation professionnelle a Marseille.')->call('send');
        $shell->set('draft', 'Dans quelle ville je travaille deja ?')->call('send');

        $second = $this->lastPrompt();

        // L'entete du bloc, le tour humain precedent, la reponse precedente,
        // et la question courante : les quatre, dans le meme prompt.
        $this->assertStringContainsString(self::MEMORY_HEADER, $second);
        $this->assertStringContainsString('Membre : Je travaille dans la formation professionnelle a Marseille.', $second);
        $this->assertStringContainsString('Assistant : Cadrer notre relecture.', $second);
        $this->assertStringContainsString('Dans quelle ville je travaille deja ?', $second);

        // Le critere produit, dit sans detour : le mot que la question
        // prolonge est REELLEMENT parti au modele.
        $this->assertStringContainsString('Marseille', $second);
    }

    /**
     * 2. Le message courant n'entre pas dans son propre contexte.
     *
     * C'est LE test de la capture avant `appendUser()` : si l'historique etait
     * lu apres l'ecriture du declencheur, la question apparaitrait deux fois —
     * une fois en « Membre : … », une fois comme question.
     */
    public function test_the_current_message_is_never_injected_into_its_own_context(): void
    {
        $this->fakeClarifier();

        $question = 'Une question parfaitement unique pour ce test.';

        Livewire::actingAs($this->memberA)->test(AiShell::class)
            ->set('draft', $question)
            ->call('send');

        $prompt = $this->lastPrompt();

        $this->assertSame(1, substr_count($prompt, $question), 'La question ne doit apparaitre QUE comme question.');
        $this->assertStringNotContainsString(self::MEMORY_HEADER, $prompt, 'Un premier tour n\'a aucune memoire.');
        $this->assertStringNotContainsString('Membre : '.$question, $prompt);
    }

    /** Deuxieme angle : au second tour non plus, la question du tour ne se dedouble pas. */
    public function test_the_current_message_is_not_duplicated_on_a_later_turn(): void
    {
        $this->fakeClarifier();

        $shell = Livewire::actingAs($this->memberA)->test(AiShell::class);
        $shell->set('draft', 'Premier message.')->call('send');

        $question = 'Deuxieme message, strictement unique.';
        $shell->set('draft', $question)->call('send');

        $prompt = $this->lastPrompt();

        $this->assertSame(1, substr_count($prompt, $question));
        $this->assertStringContainsString('Membre : Premier message.', $prompt);
    }

    // =====================================================================
    // B. La borne
    // =====================================================================

    /** 3. Le budget en caracteres est respecte. */
    public function test_the_transcript_never_exceeds_the_character_budget(): void
    {
        config(['ai.shell.max_context_chars' => 200]);

        $this->seedConversation([
            ['user', str_repeat('A', 300)],
            ['assistant', str_repeat('B', 300), AiShellResponder::STATUS_ANSWERED],
            ['user', str_repeat('C', 120)],
        ]);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();
        $transcript = $this->transcriptOf($prompt);

        $this->assertNotSame('', $transcript);
        $this->assertLessThanOrEqual(200, mb_strlen($transcript), 'Le transcript doit tenir dans le budget.');
    }

    /** 4. Quand le budget est atteint, c'est le PLUS ANCIEN qui tombe. */
    public function test_the_oldest_turn_falls_before_the_most_recent(): void
    {
        config(['ai.shell.max_context_chars' => 120]);

        // Chaque ligne porte son statut : un assistant sans `answered` serait
        // ecarte par `remembered()`, et le budget ne serait jamais atteint —
        // le test ne prouverait alors plus rien de la troncature.
        $this->seedConversation([
            ['user', 'TRES-ANCIEN '.str_repeat('x', 60)],
            ['assistant', 'INTERMEDIAIRE '.str_repeat('y', 60), AiShellResponder::STATUS_ANSWERED],
            ['user', 'LE-PLUS-RECENT'],
        ]);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();

        // Budget 120 : le plus recent (23) + l'intermediaire (86) tiennent ;
        // le plus ancien (81) ne tient plus et tombe. L'ordre de chute est le
        // contrat, pas le compte exact.
        $this->assertStringContainsString('LE-PLUS-RECENT', $prompt, 'Le plus recent est toujours conserve.');
        $this->assertStringContainsString('INTERMEDIAIRE', $prompt, 'Ce qui tient dans le budget reste.');
        $this->assertStringNotContainsString('TRES-ANCIEN', $prompt, 'Le plus ancien tombe en premier.');
    }

    /** Le tour le plus recent est TRONQUE, jamais supprime, meme seul trop long. */
    public function test_a_single_oversized_recent_turn_is_truncated_not_dropped(): void
    {
        config(['ai.shell.max_context_chars' => 80]);

        $this->seedConversation([
            ['user', 'DEBUT-RECONNAISSABLE '.str_repeat('z', 500)],
        ]);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();
        $transcript = $this->transcriptOf($prompt);

        $this->assertStringContainsString('DEBUT-RECONNAISSABLE', $prompt);
        $this->assertLessThanOrEqual(80, mb_strlen($transcript));
    }

    /** Budget a zero : la memoire se desactive sans rien casser. */
    public function test_a_zero_budget_disables_the_memory_without_breaking_the_turn(): void
    {
        config(['ai.shell.max_context_chars' => 0]);

        $this->seedConversation([['user', 'Un message anterieur.']]);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();

        $this->assertStringNotContainsString(self::MEMORY_HEADER, $prompt);
        $this->assertStringContainsString('La question courante.', $prompt);
    }

    // =====================================================================
    // C. La portee : la conversation courante, et elle seule
    // =====================================================================

    /** 5. Effacer le fil, c'est perdre la memoire. */
    public function test_clearing_the_thread_empties_the_memory(): void
    {
        $this->fakeClarifier();

        $shell = Livewire::actingAs($this->memberA)->test(AiShell::class);
        $shell->set('draft', 'Un message que je vais effacer.')->call('send');

        $shell->call('clearThread');
        $this->assertSame(0, AiShellMessage::query()->count());

        $shell->set('draft', 'Une question apres effacement.')->call('send');

        $prompt = $this->lastPrompt();

        $this->assertStringNotContainsString(self::MEMORY_HEADER, $prompt);
        $this->assertStringNotContainsString('Un message que je vais effacer.', $prompt);
    }

    /**
     * 6. Une conversation ANTERIEURE du meme fil n'entre pas.
     *
     * Defense en profondeur : les deux conversations appartiennent au meme
     * couple (Organization, utilisateur) — seul le filtre de conversation les
     * separe. Sans lui, le fil d'hier deborderait dans celui d'aujourd'hui.
     */
    public function test_an_earlier_conversation_of_the_same_thread_is_never_injected(): void
    {
        $ancienne = (string) Str::uuid();
        $courante = (string) Str::uuid();

        $this->seedConversation([['user', 'MESSAGE-DHIER']], $ancienne, now()->subDays(2));
        $this->seedConversation([['user', 'MESSAGE-DAUJOURDHUI']], $courante, now()->subMinute());

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();

        $this->assertStringContainsString('MESSAGE-DAUJOURDHUI', $prompt);
        $this->assertStringNotContainsString('MESSAGE-DHIER', $prompt);
    }

    // =====================================================================
    // D. Le bruit n'est pas de la memoire
    // =====================================================================

    /** 7. Les reponses techniques `unavailable` / `blocked` sont exclues. */
    public function test_technical_answers_never_enter_the_memory(): void
    {
        $this->seedConversation([
            ['user', 'Ma premiere question.'],
            ['assistant', 'REPONSE-INDISPONIBLE', AiShellResponder::STATUS_UNAVAILABLE],
            ['user', 'Ma deuxieme question.'],
            ['assistant', 'REPONSE-REFUSEE', AiShellResponder::STATUS_BLOCKED],
            ['user', 'Ma troisieme question.'],
            ['assistant', 'REPONSE-VRAIE', AiShellResponder::STATUS_ANSWERED],
        ]);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();

        // Ce que la personne a ecrit entre TOUJOURS.
        $this->assertStringContainsString('Membre : Ma premiere question.', $prompt);
        $this->assertStringContainsString('Membre : Ma troisieme question.', $prompt);

        // De l'assistant, seule une VRAIE reponse entre.
        $this->assertStringContainsString('Assistant : REPONSE-VRAIE', $prompt);
        $this->assertStringNotContainsString('REPONSE-INDISPONIBLE', $prompt);
        $this->assertStringNotContainsString('REPONSE-REFUSEE', $prompt);
    }

    // =====================================================================
    // E. Tenant : Organization = Tenant, le Shell est (Organization, User)
    // =====================================================================

    /** 8. Le fil d'un AUTRE utilisateur de la meme Organization n'entre jamais. */
    public function test_another_users_thread_is_never_injected(): void
    {
        $this->seedConversation([['user', 'SECRET-DE-LAUTRE-MEMBRE']], null, null, $this->otherMemberA, $this->organizationA);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();

        $this->assertStringNotContainsString('SECRET-DE-LAUTRE-MEMBRE', $prompt);
        $this->assertStringNotContainsString(self::MEMORY_HEADER, $prompt);
    }

    /** 9. Le fil d'une AUTRE Organization n'entre jamais. */
    public function test_another_organizations_thread_is_never_injected(): void
    {
        $this->seedConversation([['user', 'SECRET-DE-LAUTRE-ORG']], null, null, $this->memberB, $this->organizationB);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();

        $this->assertStringNotContainsString('SECRET-DE-LAUTRE-ORG', $prompt);
        $this->assertStringNotContainsString(self::MEMORY_HEADER, $prompt);
    }

    /**
     * Defense en profondeur : le scope porte sur le COUPLE, pas sur l'un des
     * deux.
     *
     * Des lignes portant le MEME `user_id` mais l'`organization_id` d'un autre
     * tenant ne devraient pas exister — `User::organization_id` est unique et
     * `clarifyForOrganization()` refuse deja un demandeur venu d'ailleurs.
     * C'est precisement pour cela qu'on les ecrit ici, a la main : si un jour
     * une affiliation multiple, un import ou une migration en produisait, elles
     * ne doivent pas remonter dans le prompt. `scopeForThread()` filtre sur les
     * DEUX colonnes ; ce test le prouve plutot que de le supposer.
     */
    public function test_rows_of_another_tenant_for_the_same_person_never_leak(): void
    {
        $this->seedConversation([['user', 'CONTEXTE-ORG-A']], null, now()->subHours(2), $this->memberA, $this->organizationA);
        $this->seedConversation([['user', 'CONTEXTE-ORG-B']], null, now()->subMinute(), $this->memberA, $this->organizationB);

        $this->fakeClarifier();
        $this->respond('La question courante.');

        $prompt = $this->lastPrompt();

        // Le tour est joue dans l'Organization A : seul le fil de A existe pour
        // lui, meme si les lignes de B sont PLUS RECENTES et porteraient donc
        // la « conversation courante » d'un filtre qui aurait oublie le tenant.
        $this->assertStringContainsString('CONTEXTE-ORG-A', $prompt);
        $this->assertStringNotContainsString('CONTEXTE-ORG-B', $prompt);
    }

    // =====================================================================
    // F. Non-regression
    // =====================================================================

    /**
     * 10. Sur fil vide, le prompt est celui d'AVANT T1346, a l'octet pres.
     *
     * Sans contexte de page ni pin, `situated()` rendait exactement le prompt
     * de la personne. Cette egalite stricte est la garantie que la memoire
     * n'ajoute RIEN au chemin nominal.
     *
     * TASK-1359 : l'invariant porte sur l'ABSENCE DE CONTEXTE, pas sur le
     * tableau de bord. Il etait exprime sur `kind = dashboard` parce que cette
     * page n'etait alors pas un lieu ; depuis T1359 elle en est un, et la page
     * reellement sans lieu est `kind = other`. L'assertion stricte est
     * conservee telle quelle : seule la page qui la porte change.
     */
    public function test_an_empty_thread_produces_the_exact_previous_prompt(): void
    {
        $this->fakeClarifier();

        $question = 'Ma toute premiere question.';

        app(AiShellResponder::class)->respond(
            $this->organizationA,
            $this->memberA,
            $question,
            ['route' => 'profile.edit', 'kind' => 'other', 'object' => null],
        );

        $this->assertSame($question, $this->lastPrompt());
    }

    // =====================================================================
    // G. Cout : un tour reste un tour
    // =====================================================================

    /** 11. Aucun appel provider supplementaire. */
    public function test_the_memory_adds_no_provider_call(): void
    {
        $this->fakeClarifier();

        $shell = Livewire::actingAs($this->memberA)->test(AiShell::class);
        $shell->set('draft', 'Premier tour.')->call('send');

        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, AiProviderInvocation::query()->count());

        $shell->set('draft', 'Second tour, avec memoire.')->call('send');

        // Deux tours = deux appels. La memoire ne declenche AUCUNE generation
        // annexe : ni resume, ni embedding, ni second passage.
        $this->assertSame(2, AiInteraction::query()->count());
        $this->assertSame(2, AiProviderInvocation::query()->count());

        // …et le second appel porte bien la memoire : la preuve que le compte
        // ci-dessus n'est pas simplement celui d'une memoire inactive.
        $this->assertStringContainsString('Membre : Premier tour.', $this->lastPrompt());
    }

    /** La lecture passe par la porte unique : aucune requete directe au modele. */
    public function test_the_responder_never_queries_the_messages_table_directly(): void
    {
        $source = file_get_contents(app_path('Services/Ai/AiShellResponder.php'));

        $this->assertStringNotContainsString('AiShellMessage::query()', $source);
        $this->assertStringContainsString('$this->thread->messages(', $source);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Un tour reel, hors Livewire, sur l'Organization et le membre par defaut.
     */
    private function respond(string $prompt): void
    {
        app(AiShellResponder::class)->respond(
            $this->organizationA,
            $this->memberA,
            $prompt,
            ['route' => 'dashboard', 'kind' => 'dashboard', 'object' => null],
        );
    }

    /**
     * Le prompt REELLEMENT parti au modele, relu depuis la trace du tour —
     * la meme colonne que celle qu'affiche « Pourquoi cette reponse ? ».
     */
    private function lastPrompt(): string
    {
        $interaction = AiInteraction::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();

        return (string) $interaction->prompt;
    }

    /** Le bloc de memoire seul, entete comprise, ou '' s'il n'y en a pas. */
    private function transcriptOf(string $prompt): string
    {
        $start = mb_strpos($prompt, self::MEMORY_HEADER);

        if ($start === false) {
            return '';
        }

        // Le transcript occupe tout ce qui suit l'entete, sauf la derniere
        // ligne qui est la question elle-meme.
        $bloc = mb_substr($prompt, $start);
        $lignes = explode("\n", $bloc);
        array_pop($lignes);

        // TASK-1350 : quand un transcript precede, la question courante est
        // ETIQUETEE. Cette etiquette n'est pas du contenu memorise — elle ne
        // compte donc pas dans le budget, exactement comme l'entete. Le budget
        // lui-meme n'a pas bouge : `conversationMemory()` est inchangee.
        if (end($lignes) === __('ai.shell_prompt_current_turn')) {
            array_pop($lignes);
        }

        // L'entete n'est pas du contenu memorise : elle ne compte pas dans le
        // budget, exactement comme dans `AiConversationContextBuilder`.
        array_shift($lignes);

        return implode("\n", $lignes);
    }

    /**
     * Ecrit un fil directement, pour poser un etat que le parcours normal
     * mettrait des dizaines de tours a produire.
     *
     * `created_at` n'est pas `fillable` (et le modele n'a pas de timestamps
     * automatiques) : il se pose en `forceFill` + `saveQuietly`, jamais en
     * elargissant `$fillable`.
     *
     * @param  list<array{0: string, 1: string, 2?: string}>  $messages  [role, contenu, statut?]
     */
    private function seedConversation(
        array $messages,
        ?string $conversationId = null,
        ?Carbon $baseTime = null,
        ?User $user = null,
        ?Organization $organization = null,
    ): void {
        $conversationId ??= (string) Str::uuid();
        $baseTime ??= now()->subHour();
        $user ??= $this->memberA;
        $organization ??= $this->organizationA;

        foreach (array_values($messages) as $index => $entry) {
            $status = $entry[2] ?? null;
            $message = new AiShellMessage;

            $message->forceFill([
                'organization_id' => (string) $organization->id,
                'user_id' => (string) $user->id,
                'conversation_id' => $conversationId,
                'role' => $entry[0],
                'content' => $entry[1],
                'reply_to_id' => null,
                'metadata' => $status === null ? null : ['status' => $status],
                'created_at' => $baseTime->copy()->addSeconds($index),
            ]);

            $message->saveQuietly();
        }
    }

    /**
     * Une reponse structuree pour CHAQUE tour : une closure, pas une file —
     * un test multi-tours ne doit pas dependre du nombre d'elements queues.
     */
    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Relecture',
            'clarified_request' => 'Cadrer notre relecture.',
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
