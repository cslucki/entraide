<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\CapabilityRegistry;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TASK-1519 — le Dossier COURANT devient le perimetre documentaire du Shell.
 *
 * ## Le defaut
 *
 * Le Shell savait sur quelle page l'utilisateur se trouvait — `PageContext` le
 * lui disait — mais routait TOUTE question vers `clarify_help_request`. Sur un
 * Dossier indexe, il connaissait le nom du Dossier et repondait qu'il ne
 * pouvait pas lire les fichiers.
 *
 * ## Ce que ces tests gardent
 *
 *  1. la branche est PRE-PROVIDER et delegue a la primitive de Phase 1 —
 *     jamais un second moteur documentaire ;
 *  2. `clarify_help_request` n'est PAS elargi : ses `allowedSources` restent
 *     ce qu'elles etaient, et un test le mesure sur le registre ;
 *  3. le contexte de page n'est JAMAIS un droit : un Dossier refuse ne fait
 *     rien fuir, il retombe sur le chemin habituel ;
 *  4. le fil entre dans le PROMPT et jamais dans la recherche — mesure a
 *     l'appui, prefixer la question precedente DEGRADE le classement.
 */
class TASK1519ShellDossierScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private User $outsider;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->outsider = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier ARIA',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1519',
        ]);

        config([
            'ai.clarify.enabled' => true,
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    // ── Harnais ─────────────────────────────────────────────────────────────

    private function mockSearch(): MockInterface
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();

        return $mock;
    }

    private function row(string $contenu): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'rapport.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 2,
            'content' => $contenu,
            'distance' => 0.2,
        ];
    }

    private function fakeDossierAgent(string $texte): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
            new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Titre', 'clarified_request' => 'Demande clarifiee.', 'help_type' => 'information',
            'suggested_loop_id' => '', 'suggested_category_id' => '', 'suggestion_reason' => '',
            'questions_for_user' => [], 'confidence' => 0.9, 'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    private function fakeGeneral(): void
    {
        ShellGeneralAnswerAgent::fake([
            new TextResponse(
                'Je ne dispose d aucune source documentaire pour cette question.',
                new Usage(20, 10),
                new Meta('openrouter', 'openai/gpt-4o-mini'),
            ),
        ]);
    }

    /**
     * Le contexte est construit par le MEME resolveur que le chemin Livewire,
     * donc avec les MEMES gardes. Le fabriquer a la main prouverait le
     * contraire de ce qu'on veut prouver.
     */
    private function send(string $question, ?string $kind = null, ?string $objectId = null, ?User $acteur = null): void
    {
        $acteur ??= $this->member;
        $context = app(AiShellPageContext::class)->resolve(
            $acteur,
            $this->organization,
            $kind ?? AiShellPageContext::KIND_DOSSIER,
            $objectId ?? $this->dossier->id,
        );

        app(AiShellResponder::class)->respond($this->organization, $acteur, $question, $context);
    }

    private function lastAssistant(): AiShellMessage
    {
        return AiShellMessage::query()->where('role', 'assistant')
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    }

    private function lastPrompt(): string
    {
        return (string) AiInteraction::query()
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail()->prompt;
    }

    // ── 1. Le Shell repond avec le moteur du Dossier ─────────────────────────

    public function test_on_a_dossier_page_the_shell_answers_from_the_dossier_and_never_clarifies(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()
            ->andReturn([$this->row('ARIA signifie ARtistic Intelligence Alliance.')]);

        $this->fakeDossierAgent('ARIA signifie ARtistic Intelligence Alliance. [S1]');
        // Le clarificateur ne doit PAS etre appele : aucune reponse ne lui est
        // preparee, un appel leverait.
        HelpRequestClarifierAgent::fake([]);

        $this->send("C'est quoi ARIA ?");

        $message = $this->lastAssistant();

        $this->assertStringContainsString('ARtistic Intelligence Alliance', (string) $message->content);
        $this->assertSame('dossier.answer', $message->metadata['producer'] ?? null,
            'la reponse doit venir du moteur documentaire, pas de la clarification');
    }

    public function test_the_answer_carries_its_sources_and_follow_ups_in_the_thread_metadata(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()
            ->andReturn([$this->row('Le consortium reunit dix-neuf partenaires.')]);

        $this->fakeDossierAgent(<<<'MD'
            Le consortium reunit dix-neuf partenaires. [S1]

            ## Questions possibles
            - Quels sont les cinq piliers du projet ?
            - Quel est le budget total ?
            MD);
        HelpRequestClarifierAgent::fake([]);

        $this->send('Combien de partenaires ?');

        $meta = $this->lastAssistant()->metadata;

        $this->assertTrue($meta['grounded'] ?? false);
        $this->assertCount(1, $meta['sources'] ?? []);
        $this->assertSame([
            'Quels sont les cinq piliers du projet ?',
            'Quel est le budget total ?',
        ], $meta['follow_up_questions'] ?? []);
    }

    // ── 2. La clarification n'est pas elargie ───────────────────────────────

    /**
     * Le CDC l'interdit nommement : ajouter `dossier.retrieval` aux sources de
     * `clarify_help_request` ouvrirait le corpus a TOUTES les questions du
     * produit. Ce test lit le registre, pas une intention.
     */
    public function test_the_clarify_capability_never_gains_documentary_sources(): void
    {
        $definition = app(CapabilityRegistry::class)->get('clarify_help_request');

        $this->assertNotContains(CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL, $definition->allowedSources,
            'la clarification d entraide ne doit jamais recevoir le corpus documentaire');
    }

    public function test_a_page_that_is_not_a_dossier_keeps_the_ordinary_path(): void
    {
        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');
        $this->fakeClarifier();

        $this->send('Je cherche de l aide.', AiShellPageContext::KIND_OTHER, null);

        $this->assertNotSame('dossier.answer', $this->lastAssistant()->metadata['producer'] ?? null);
    }

    // ── 3. Le contexte de page n'est jamais un droit ────────────────────────

    /**
     * Le Dossier est prive et l'acteur n'y a pas acces : la branche doit
     * s'effacer, et RIEN du contenu ne doit apparaitre.
     */
    public function test_a_dossier_the_actor_cannot_view_leaks_nothing_and_falls_back(): void
    {
        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');
        $this->fakeGeneral();

        $this->send("C'est quoi ARIA ?", AiShellPageContext::KIND_DOSSIER, $this->dossier->id, $this->outsider);

        $message = $this->lastAssistant();

        $this->assertNotSame('dossier.answer', $message->metadata['producer'] ?? null);
        $this->assertStringNotContainsString('ARtistic', (string) $message->content);
    }

    public function test_a_dossier_of_another_organization_is_never_used(): void
    {
        $autre = Organization::factory()->create();
        $etranger = Dossier::create([
            'organization_id' => $autre->id,
            'owner_id' => User::factory()->create(['organization_id' => $autre->id])->id,
            'name' => 'Dossier etranger',
            'visibility' => 'organization',
        ]);

        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');
        $this->fakeGeneral();

        $this->send('Que contient ce Dossier ?', AiShellPageContext::KIND_DOSSIER, $etranger->id);

        $this->assertNotSame('dossier.answer', $this->lastAssistant()->metadata['producer'] ?? null);
    }

    /**
     * LE test que le CDC exige nommement : « ne jamais faire confiance a un
     * ancien `metadata.page_context.object_id` sans revalidation actuelle ».
     *
     * Ici le contexte est fabrique A LA MAIN — c'est le seul moyen d'atteindre
     * la garde du responder, parce que `AiShellPageContext::resolve()` refuse
     * l'objet en amont et masque donc la garde interne. Sabotage joue : sans
     * cette garde et avec ce contexte, le Dossier prive d'un tiers serait lu.
     */
    public function test_a_hand_built_page_context_never_becomes_an_access_right(): void
    {
        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');
        $this->fakeGeneral();

        // Exactement la forme que produit le resolveur, mais SANS son refus :
        // un identifiant que l'acteur n'a pas le droit de lire.
        $contexteForge = [
            'refused' => false,
            'organization' => ['id' => (string) $this->organization->id, 'name' => '', 'slug' => ''],
            'route' => 'organization.dossiers.show',
            'surface' => 'dossiers',
            'kind' => AiShellPageContext::KIND_DOSSIER,
            'object' => ['type' => 'dossier', 'id' => (string) $this->dossier->id, 'label' => 'ARIA', 'url' => ''],
            'label' => 'ARIA',
        ];

        // Un refus de policy est un cas NORMAL, pas un incident : il ne doit
        // pas remonter comme une exception rapportee. C'est l'effet observable
        // de la garde du responder — sans elle, le service leve, la branche
        // rattrape et `report()` bruite la supervision a chaque tour.
        Exceptions::fake();

        app(AiShellResponder::class)->respond($this->organization, $this->outsider, "C'est quoi ARIA ?", $contexteForge);

        $message = $this->lastAssistant();

        $this->assertNotSame('dossier.answer', $message->metadata['producer'] ?? null,
            'un contexte forge ne doit jamais ouvrir un Dossier que la policy refuse');
        $this->assertStringNotContainsString('ARtistic', (string) $message->content);

        Exceptions::assertNothingReported();
    }

    /**
     * La branche est conditionnee au KIND, et pas seulement a la presence d'un
     * objet. Sans cette condition, un Article courant — qui porte lui aussi un
     * `object.id` — serait traite comme un Dossier.
     */
    public function test_an_article_page_is_never_treated_as_a_dossier(): void
    {
        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');
        $this->fakeClarifier();

        $contexteArticle = [
            'refused' => false,
            'organization' => ['id' => (string) $this->organization->id, 'name' => '', 'slug' => ''],
            'route' => 'organization.blog.show',
            'surface' => 'blog',
            'kind' => AiShellPageContext::KIND_ARTICLE,
            // L'identifiant est celui d'un Dossier REEL et lisible : seule la
            // condition de KIND empeche de le lire comme tel.
            'object' => ['type' => 'article', 'id' => (string) $this->dossier->id, 'label' => 'Un article', 'url' => ''],
            'label' => 'Un article',
        ];

        app(AiShellResponder::class)->respond($this->organization, $this->member, 'De quoi parle cet article ?', $contexteArticle);

        $this->assertNotSame('dossier.answer', $this->lastAssistant()->metadata['producer'] ?? null,
            'un Article n est pas un Dossier, meme si son identifiant en designe un');
    }

    /**
     * La branche documentaire ne parle QUE si elle a des documents.
     *
     * Sur un Dossier sans contenu indexe, une question peut porter sur tout
     * autre chose que le corpus (« comment je partage ce Dossier ? »).
     * Repondre « je n'ai rien trouve dans ce Dossier » serait moins utile que
     * le chemin habituel. Regression trouvee par la CI, pas par moi.
     */
    public function test_a_dossier_without_any_document_falls_back_to_the_ordinary_path(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([]);
        $this->fakeClarifier();

        $this->send('Comment je partage ce Dossier ?');

        $this->assertNotSame('dossier.answer', $this->lastAssistant()->metadata['producer'] ?? null,
            'sans document, la branche documentaire doit s effacer');
    }

    /**
     * Un tour repondu porte ses cartes, quel que soit le chemin qui l'a
     * produit. Sans cela, une reponse documentaire perdait la reference de
     * document que TOUT autre tour sur cette page portait.
     */
    public function test_a_documentary_turn_carries_its_cards_like_any_other(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()
            ->andReturn([$this->row('Un contenu documentaire.')]);
        $this->fakeDossierAgent('Une reponse. [S1]');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Que contient ce Dossier ?');

        $meta = $this->lastAssistant()->metadata;

        $this->assertArrayHasKey('cards', $meta, 'un tour documentaire doit porter ses cartes');
        $this->assertNotNull($meta['ai_interaction_id'] ?? null,
            'un tour repondu doit pouvoir recevoir un verdict humain');
    }

    // ── 4. Le fil entre dans le prompt, jamais dans la recherche ────────────

    /**
     * Ordre canonique du CDC : SOURCES -> THREAD -> QUESTION.
     *
     * Et la recherche, elle, ne voit QUE la question. Mesure sur corpus reel :
     * prefixer la question precedente degrade le classement dans 3 cas sur 5 —
     * « Et les participants ? » passe du rang 1 a hors du top 20.
     */
    public function test_the_thread_reaches_the_prompt_but_never_the_search_query(): void
    {
        // Le fil est SEME, pas joue : deux tours consecutifs feraient dependre
        // ce test de la serialisation des tours, alors que ce qu'il mesure est
        // « un fil existant entre-t-il dans le prompt, et jamais dans la
        // requete ? ».
        // TASK-1523 : le tour seme porte la page que le serveur ecrit lui-meme
        // a chaque tour (`traceable()`). Sans elle, la memoire DOCUMENTAIRE —
        // bornee au meme objet de page — l'ecarte a juste titre : un tour
        // d'origine inconnue n'est pas « le meme Dossier ». En base reelle,
        // aucun message n'est sans page_context.
        $page = ['page_context' => ['kind' => AiShellPageContext::KIND_DOSSIER, 'object_type' => 'dossier', 'object_id' => (string) $this->dossier->id]];
        $thread = app(AiShellThread::class);
        $declencheur = $thread->appendUser($this->organization, $this->member, "C'est quoi ARIA ?", $page);
        $thread->appendAssistant($this->organization, $this->member, 'ARIA est une alliance.', $declencheur, $page);

        $requetes = [];
        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')
            ->andReturnUsing(function (...$args) use (&$requetes) {
                $requetes[] = (string) $args[2];

                return [$this->row('Le consortium reunit dix-neuf partenaires.')];
            });

        $this->fakeDossierAgent('Une reponse. [S1]');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Et les participants ?');

        $this->assertSame(['Et les participants ?'], $requetes,
            'la recherche ne voit QUE la question : prefixer le tour precedent degrade le classement (mesure sur corpus reel)');

        $prompt = $this->lastPrompt();

        $posSources = strpos($prompt, 'SOURCES DOCUMENTAIRES');
        $posThread = strpos($prompt, 'CONVERSATION EN COURS');
        $posQuestion = strrpos($prompt, 'Et les participants');

        $this->assertNotFalse($posThread, 'le fil doit entrer dans le prompt pour que l ellipse soit comprehensible');
        $this->assertStringContainsString("C'est quoi ARIA", $prompt, 'le tour precedent doit y figurer');
        $this->assertLessThan($posThread, $posSources, 'les SOURCES viennent avant le THREAD');
        $this->assertLessThan($posQuestion, $posThread, 'le THREAD vient avant la QUESTION courante');
    }

    /**
     * La page Dossier, elle, appelle sans fil : son prompt doit rester
     * exactement celui d'avant cette TASK.
     */
    public function test_the_dossier_page_prompt_is_unchanged_when_there_is_no_thread(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()
            ->andReturn([$this->row('Un contenu.')]);
        $this->fakeDossierAgent('Une reponse. [S1]');

        $this->actingAs($this->member)
            ->postJson(route('organization.dossiers.answer', [
                'organization' => $this->organization,
                'dossier' => $this->dossier,
            ]), ['question' => 'Quoi donc ?'])
            ->assertOk();

        $this->assertStringNotContainsString('CONVERSATION EN COURS', $this->lastPrompt(),
            'sans fil, aucun bloc de conversation ne doit apparaitre');
    }
}
