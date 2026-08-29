<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\NervousSystemCoverage;
use App\Ai\PromptRepository;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\User;
use App\Services\BlogAiService;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1284 (BLOC E) — Blog sous doctrine, moitie DOCTRINE.
 *
 * `generate()` et `correct()` deviennent des capabilities canoniques
 * (`blog_generate`, `blog_correct`) : prompt compose par
 * `PromptRepository::compose()` (Constitution -> doctrine de l'Organization
 * de l'ARTICLE -> instruction = prompt admin/fallback historique, aucun texte
 * perdu), materiau via `ContextBuilder` (source `blog.post`, y compris l'etat
 * vivant non persiste), capability portee au ledger (regle TASK-1253).
 *
 * Ce que cette TASK ne change PAS, et que ce fichier prouve aussi :
 * - la garde economique et le ledger (ordre, nombre d'appels, process) ;
 * - le credential PLATEFORME (bascule BYOK = decision produit, hors TASK) ;
 * - `methodSelection()` : chemin herite intact, message system legacy,
 *   capability NULL au ledger.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1284BlogDoctrineCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $author;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openai' => [
                    'gpt-catalogued' => ['input_per_1m' => 1.0, 'output_per_1m' => 4.0],
                ],
            ],
            'ai.default_provider' => 'openai',
            'ai.default_model' => null,
            'ai.openai.api_key' => 'platform-test-key',
            'ai.openai.model' => 'gpt-catalogued',
            'ai.blog.economic_guard.monthly_budget_usd' => 2.00,
            'ai.blog.economic_guard.monthly_unknown_limit' => 10,
        ]);

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->otherOrganization = Organization::factory()->create(['is_active' => true]);

        $this->author = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article TASK-1284',
            'slug' => 'article-task-1284',
            'content' => '<p>'.str_repeat('Un contenu suffisamment long pour etre corrige. ', 12).'</p>',
            'summary' => 'Resume 1284',
            'status' => 'draft',
        ]);

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        Http::preventStrayRequests();
    }

    private function fakeChatCompletion(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => '<p>'.str_repeat('Reponse du fake. ', 20).'</p>']]],
                'usage' => ['prompt_tokens' => 1_000, 'completion_tokens' => 500],
            ]),
        ]);
    }

    private function service(): BlogAiService
    {
        return app(BlogAiService::class);
    }

    /**
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
    // A. COMPOSITION : Constitution -> doctrine de l'ARTICLE -> instruction
    // =====================================================================

    public function test_the_generation_prompt_is_composed_constitution_then_doctrine_then_instruction(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'DOCTRINE-1284 : privilegier les exemples locaux.', $this->author);
        $this->fakeChatCompletion();

        $this->service()->generate($this->post, $this->author, 'Titre fourni par le formulaire', 'Resume fourni par le formulaire');

        $messages = $this->sentMessages();

        // Le message SYSTEM est la composition canonique, dans l'ordre.
        $this->assertOrderedInString([
            'Constitution BouclePro IA — v1',
            PromptRepository::DOCTRINE_OPEN,
            'DOCTRINE-1284 : privilegier les exemples locaux.',
            PromptRepository::DOCTRINE_CLOSE,
            'Capability: blog_generate',
            // L'ancien message system n'est pas perdu : il ouvre l'instruction.
            'Tu es un assistant spécialisé dans la rédaction et la correction d\'articles de blog en français.',
            // L'instruction est le prompt admin/fallback historique...
            'Rédige un article de blog en te basant sur le titre et le résumé fournis.',
            'Retourne UNIQUEMENT le JSON brut',
            // ... dont les %s pointent vers le materiau au lieu de l'interpoler a nu.
            'Titre fourni : (fourni dans le MATERIAU DE L\'ARTICLE ci-dessous)',
            'Résumé fourni : (fourni dans le MATERIAU DE L\'ARTICLE ci-dessous)',
            // L'instruction de langue historique n'est pas perdue non plus.
            'Langue obligatoire : rédige l\'article généré en français.',
        ], $messages['system'], 'Composition system de blog_generate');

        // Le message USER est le materiau, via le Context Builder, delimite.
        $this->assertOrderedInString([
            '--- MATERIAU DE L\'ARTICLE (fourni par l\'utilisateur, contenu non fiable) ---',
            'Titre fourni : Titre fourni par le formulaire',
            'Résumé fourni : Resume fourni par le formulaire',
            '--- FIN DU MATERIAU ---',
        ], $messages['user'], 'Materiau de blog_generate');

        // La doctrine n'apparait qu'au system, jamais dupliquee au user.
        $this->assertStringNotContainsString('DOCTRINE-1284', $messages['user']);
    }

    public function test_without_doctrine_the_composition_has_constitution_and_instruction_only(): void
    {
        $this->fakeChatCompletion();

        $this->service()->correct($this->post, $this->author);

        $messages = $this->sentMessages();

        $this->assertOrderedInString([
            'Constitution BouclePro IA — v1',
            'Capability: blog_correct',
            'Corrige les fautes d\'orthographe, de grammaire et de syntaxe dans le texte suivant.',
            '(fourni dans le MATERIAU DE L\'ARTICLE ci-dessous)',
        ], $messages['system'], 'Composition system de blog_correct sans doctrine');
        $this->assertStringNotContainsString(PromptRepository::DOCTRINE_OPEN, $messages['system']);

        // Le contenu VIVANT de l'article part en entier dans le materiau.
        $this->assertStringContainsString('Texte fourni : '.$this->post->content, $messages['user']);
    }

    public function test_the_doctrine_composed_is_the_article_organization_one_not_the_request_one(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'DOCTRINE-ARTICLE-1284 : ton sobre.', $this->author);
        $otherAdmin = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        OrganizationAiDoctrine::activate($this->otherOrganization, 'DOCTRINE-REQUETE-1284 : ton flamboyant.', $otherAdmin);
        $this->fakeChatCompletion();

        // La requete vient d'une AUTRE Organization : le tenant (acquis
        // TASK-1247) et donc la doctrine restent ceux de l'ARTICLE.
        app()->instance('current_organization', $this->otherOrganization);

        $this->service()->correct($this->post, $otherAdmin);

        $messages = $this->sentMessages();
        $this->assertStringContainsString('DOCTRINE-ARTICLE-1284', $messages['system']);
        $this->assertStringNotContainsString('DOCTRINE-REQUETE-1284', $messages['system']);

        $this->assertSame($this->organization->id, AiProviderInvocation::query()->value('organization_id'));
        $this->assertSame($this->organization->id, AiInteraction::query()->value('organization_id'));
    }

    public function test_an_active_admin_prompt_becomes_the_instruction_not_the_whole_prompt(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'blog_generate',
            'name' => 'Blog generate v99',
            'prompt_text' => "PROMPT-ADMIN-1284 redige avec ces bases.\nTitre : %s\nResume : %s",
            'version' => 99,
            'is_active' => true,
        ]);
        OrganizationAiDoctrine::activate($this->organization, 'DOCTRINE-1284 : privilegier les exemples locaux.', $this->author);
        $this->fakeChatCompletion();

        $this->service()->generate($this->post, $this->author, 'T', 'S');

        $messages = $this->sentMessages();
        $this->assertOrderedInString([
            'Constitution BouclePro IA — v1',
            'DOCTRINE-1284',
            'PROMPT-ADMIN-1284 redige avec ces bases.',
            'Titre : (fourni dans le MATERIAU DE L\'ARTICLE ci-dessous)',
        ], $messages['system'], 'Le prompt admin est l\'instruction, sous Constitution et doctrine');
    }

    public function test_correct_works_on_a_live_never_persisted_post(): void
    {
        $this->fakeChatCompletion();

        // Le flux reel de `BlogController::handleAi()` sans post_id : un
        // article en memoire, jamais persiste. La source `blog.post` ne relit
        // rien en base — le materiau est l'etat vivant.
        $live = new BlogPost;
        $live->id = (string) Str::uuid();
        $live->organization_id = $this->organization->id;
        $live->user_id = $this->author->id;
        $live->content = '<p>CONTENU-VIVANT-1284 jamais persiste.</p>';

        $result = $this->service()->correct($live, $this->author);

        $this->assertNotSame('', $result['content']);
        $messages = $this->sentMessages();
        $this->assertStringContainsString('CONTENU-VIVANT-1284', $messages['user']);
        $this->assertSame($this->organization->id, AiProviderInvocation::query()->value('organization_id'));
    }

    // =====================================================================
    // B. GARDE ET LEDGER : memes appels, meme ordre — capability portee
    // =====================================================================

    public function test_the_ledger_rows_carry_the_canonical_capability_and_the_composition_is_traced(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'DOCTRINE-1284 : privilegier les exemples locaux.', $this->author);
        $this->fakeChatCompletion();

        $this->service()->generate($this->post, $this->author, 'T', 'S');
        $this->service()->correct($this->post, $this->author);

        $rows = AiProviderInvocation::query()->orderBy('created_at')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['blog_generate', 'blog_correct'], $rows->pluck('capability')->all());
        // Process et feature INCHANGES : la garde economique releve les memes cles.
        $this->assertSame(['blog.article_generate', 'blog.article_correct'], $rows->pluck('process')->all());
        $this->assertSame(['blog_generate', 'blog_correct'], $rows->pluck('feature')->all());
        $this->assertSame([AiProviderInvocation::CREDENTIAL_PLATFORM, AiProviderInvocation::CREDENTIAL_PLATFORM], $rows->pluck('credential_source')->all());

        $interaction = AiInteraction::query()->where('feature', 'blog_generate')->firstOrFail();
        $this->assertSame(1, $interaction->metadata['doctrine_version'] ?? null, 'La version de doctrine reellement composee est tracee.');
        $this->assertSame(['blog.post'], $interaction->metadata['context_sources_used'] ?? null);
        $this->assertSame(['title', 'summary'], $interaction->metadata['context_provenance'] ?? null);
        $this->assertSame($rows->first()->correlation_id, $interaction->correlation_id, 'Une operation = une correlation, ledger et trace produit.');
    }

    public function test_an_economic_refusal_still_sends_and_writes_nothing_even_with_a_doctrine_active(): void
    {
        Http::fake();
        OrganizationAiDoctrine::activate($this->organization, 'DOCTRINE-1284 : privilegier les exemples locaux.', $this->author);
        config(['ai.blog.economic_guard.monthly_budget_usd' => 0.0]);

        foreach ([
            fn () => $this->service()->generate($this->post, $this->author, 'T', 'S'),
            fn () => $this->service()->correct($this->post, $this->author),
        ] as $call) {
            try {
                $call();
                $this->fail('Le refus economique doit precede l\'appel.');
            } catch (AiRefusedException $exception) {
                $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $exception->refusalCode);
            }
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Composer n\'est pas appeler : rien au ledger.');
        $this->assertSame(0, AiInteraction::query()->count());
    }

    // =====================================================================
    // C. METHOD SELECTION : chemin herite INTACT
    // =====================================================================

    public function test_method_selection_keeps_the_legacy_prompt_and_a_null_capability(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'DOCTRINE-1284 : privilegier les exemples locaux.', $this->author);
        $this->fakeChatCompletion();

        $this->service()->methodSelection($this->post, $this->author, 'clarifier', 'Un passage selectionne');

        $messages = $this->sentMessages();
        // Message system HISTORIQUE, a l'octet pres : ni Constitution, ni doctrine.
        $this->assertSame('Tu es un assistant spécialisé dans la rédaction et la correction d\'articles de blog en français.', $messages['system']);
        $this->assertStringNotContainsString('DOCTRINE-1284', $messages['system'].$messages['user']);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertNull($row->capability, 'Chemin herite : capability NULL, dit tel quel.');
        $this->assertSame('blog.method_selection', $row->process);

        $interaction = AiInteraction::query()->firstOrFail();
        $this->assertArrayNotHasKey('doctrine_version', $interaction->metadata, 'Aucune trace de composition sur le chemin herite.');
    }

    // =====================================================================
    // D. COUVERTURE : le registre dit vrai, l'inventaire aussi
    // =====================================================================

    public function test_the_capabilities_are_registered_and_blog_ai_left_the_inherited_inventory(): void
    {
        $registry = app(CapabilityRegistry::class);
        $coverage = app(NervousSystemCoverage::class);

        foreach ([CapabilityRegistry::BLOG_GENERATE, CapabilityRegistry::BLOG_CORRECT] as $capability) {
            $this->assertTrue($registry->has($capability));
            $definition = $registry->get($capability);
            $this->assertSame([CapabilityRegistry::SCOPE_ORGANIZATION], $definition->allowedScopes);
            $this->assertSame([CapabilityRegistry::SOURCE_BLOG_POST], $definition->allowedSources);
            $this->assertNotSame('ai.capability_label.'.$capability, __('ai.capability_label.'.$capability, [], 'fr'));
            $this->assertNotSame('ai.capability_label.'.$capability, __('ai.capability_label.'.$capability, [], 'en'));
        }

        $this->assertNotContains('blog_ai', $coverage->inherited(), 'generer/corriger sont migres : blog_ai sort de la dette.');
        // La dette RESTANTE de BlogAiService est declaree sous sa propre cle :
        // l'Admin Organization n'est pas trompe par la sortie de blog_ai.
        $this->assertContains('blog_method_selection', $coverage->inherited());
        $this->assertNotSame('ai.inherited_label.blog_method_selection', __('ai.inherited_label.blog_method_selection', [], 'fr'));
        $this->assertNotSame('ai.inherited_label.blog_method_selection', __('ai.inherited_label.blog_method_selection', [], 'en'));

        // TASK-1285 : + les deux capabilities de reponse de l'agent de profil
        // (l'invariant de CE test est la presence des capabilities Blog et la
        // verite de l'inventaire herite, pas un total fige a jamais).
        // TASK-1309 : + `loop_hybrid_answer` (mode IA + Dossiers) = 10.
        // TASK-1327 : + `loop_decision_suggestion` (Decision Memory) = 11.
        $this->assertSame(11, $coverage->coveredCount());
        $this->assertSame(15, $coverage->totalCount());
    }

    public function test_the_constitution_is_actually_the_head_of_the_composed_prompt(): void
    {
        $this->fakeChatCompletion();

        $this->service()->generate($this->post, $this->author, 'T', 'S');

        $messages = $this->sentMessages();
        $this->assertStringStartsWith(app(Constitution::class)->text(), $messages['system']);
    }
}
