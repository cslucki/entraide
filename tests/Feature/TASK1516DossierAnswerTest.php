<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TASK-1516 — « Interroger ce Dossier » : une question libre, une reponse
 * sourcee, trois approfondissements.
 *
 * Ce que ces tests gardent, dans l'ordre de ce qui ferait le plus de degats :
 *
 *  1. le PERIMETRE. La question d'un membre ne doit jamais atteindre un
 *     Dossier qu'il ne peut pas lire, ni un Dossier d'une autre Organization,
 *     ni un fichier hors du Dossier courant ;
 *  2. les CITATIONS. Une reference que le retrieval n'a pas offerte doit
 *     disparaitre du texte : un lecteur ne doit jamais voir une source qu'il
 *     ne peut pas ouvrir ;
 *  3. le NON-SAVOIR. Sans source, le systeme le dit — sans appeler le
 *     provider, sans inventer, et sans se deguiser en panne ;
 *  4. la NON-REGRESSION de `generate()`, qui partage tout le reste.
 *
 * Provider mocke, aucun appel reseau reel (`Http::preventStrayRequests()`).
 */
class TASK1516DossierAnswerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $stranger;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['locale' => 'en']);
        $this->otherOrganization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier ARIA',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1516',
        ]);

        config([
            // La famille d'embeddings fait autorite pour
            // `resolveEmbeddingInstance()` : sans elle, le resolveur cherche un
            // credential tenant dans la famille par defaut (`openai`), n'en
            // trouve pas, et `answer()` refuse. Ce test la POSE au lieu de
            // l'emprunter au `.env` de la machine — sans quoi il passe en local
            // (AI_EMBEDDING_PROVIDER=openrouter) et rougit en CI, ce qui est
            // exactement ce qui s'est produit.
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

        // TASK-1517 : `answer()` demande aussi l'extrait d'OUVERTURE du
        // document le mieux classe. Attente par defaut a vide — les tests qui
        // mesurent l'ancrage la remplacent, les autres n'ont pas a la connaitre.
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();

        return $mock;
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /** Forme rendue par `searchAcrossDossiers()` — identique a celle du Smart Dossier. */
    private function row(string $label, ?string $content = null): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Document '.$label,
            'slug' => 'document-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'mime_type' => null,
            'chunk_index' => 0,
            'content' => $content ?? "Contenu {$label} : ARIA signifie ARtistic Intelligence Alliance.",
            'distance' => 0.12,
        ];
    }

    private function fileRow(DossierFile $file, string $content): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) $file->id,
            'filename' => $file->display_name,
            'mime_type' => $file->mime_type,
            'chunk_index' => 0,
            'content' => $content,
            'distance' => 0.12,
        ];
    }

    private function url(?Organization $organization = null, ?Dossier $dossier = null): string
    {
        return route('organization.dossiers.answer', [
            'organization' => $organization ?? $this->organization,
            'dossier' => $dossier ?? $this->dossier,
        ]);
    }

    private function file(string $name, ?Dossier $dossier = null, ?Organization $organization = null): DossierFile
    {
        $dossier ??= $this->dossier;
        $organization ??= $this->organization;

        return DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->owner->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.Str::uuid().'.docx',
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $name.Str::uuid()),
            'source' => 'upload',
        ]);
    }

    // ── 1. Le perimetre ─────────────────────────────────────────────────────

    public function test_a_user_without_view_access_is_forbidden_and_nothing_is_searched(): void
    {
        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');

        $this->actingAs($this->stranger)
            ->postJson($this->url(), ['question' => 'C\'est quoi ARIA ?'])
            ->assertForbidden();
    }

    /**
     * La garde du SERVICE, mesuree sans passer par la route.
     *
     * Le controleur autorise deja en amont : un test HTTP reste vert meme si
     * on retire la garde du service (sabotage joue, verdict vert). Or c'est
     * cette garde-la, et elle seule, qui protegera l'appel direct du Shell
     * (Phase 2 du CDC). Une garde qu'aucun test ne peut faire rougir est une
     * garde qui disparaitra au premier refactor.
     */
    public function test_the_service_itself_refuses_a_requester_without_view_access(): void
    {
        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');

        $this->expectException(\RuntimeException::class);

        app(DossierInsightsService::class)
            ->answer($this->organization, $this->dossier, $this->stranger, 'C\'est quoi ARIA ?');
    }

    /**
     * Le service refuse seul un Dossier d'une autre Organization.
     *
     * Ce que ce test NE prouve pas, et il faut le dire : lequel des deux
     * verrous a refuse. Sabotage joue — le controle de tenant retire, le test
     * reste VERT, parce que la policy refuse deja ce Dossier a ce demandeur.
     * Le controle explicite de `organization_id` est donc une SECONDE barriere,
     * conservee par symetrie avec `generate()` ; l'isoler exigerait un
     * demandeur autorise a voir un Dossier d'un autre tenant, c'est-a-dire la
     * fuite meme que ces gardes existent pour empecher.
     */
    public function test_the_service_itself_refuses_a_dossier_of_another_organization(): void
    {
        $foreign = Dossier::create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => User::factory()->create(['organization_id' => $this->otherOrganization->id])->id,
            'name' => 'Etranger',
            'visibility' => 'organization',
        ]);

        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');

        $this->expectException(\RuntimeException::class);

        app(DossierInsightsService::class)
            ->answer($this->organization, $foreign, $this->owner, 'Quoi ?');
    }

    public function test_a_dossier_from_another_organization_is_not_found(): void
    {
        $foreign = Dossier::create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => User::factory()->create(['organization_id' => $this->otherOrganization->id])->id,
            'name' => 'Etranger',
            'visibility' => 'organization',
        ]);

        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');

        $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $foreign), ['question' => 'Quoi ?'])
            ->assertNotFound();
    }

    public function test_the_retrieval_is_bounded_to_the_current_dossier_only(): void
    {
        $this->mockSearch()
            ->shouldReceive('searchAcrossDossiers')
            ->once()
            // Le SEUL Dossier courant : aucun elargissement a l'Organization.
            ->withArgs(fn (string $orgId, array $dossierIds, string $q): bool => $orgId === $this->organization->id
                && $dossierIds === [$this->dossier->id]
                && $q === 'Que signifie ARIA ?')
            ->andReturn([$this->row('A')]);

        $this->fakeAgent('ARIA signifie ARtistic Intelligence Alliance. [S1]');

        $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'Que signifie ARIA ?'])
            ->assertOk();
    }

    // ── 2. Les citations ────────────────────────────────────────────────────

    public function test_the_answer_carries_its_cited_sources_and_is_grounded(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->row('A'),
            $this->row('B'),
        ]);

        $this->fakeAgent('ARIA signifie ARtistic Intelligence Alliance. [S1]');

        $response = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'C\'est quoi ARIA ?'])
            ->assertOk();

        $response->assertJsonPath('data.grounded', true);
        $this->assertStringStartsWith('ARIA signifie ARtistic Intelligence Alliance.', $response->json('data.answer'));
        $this->assertCount(1, $response->json('data.sources'), 'seule la source CITEE est rendue');
        $this->assertSame('S1', $response->json('data.sources.0.ref'));
        $this->assertCount(2, $response->json('data.consulted'), 'la provenance consultee reste visible');
    }

    public function test_a_reference_the_retrieval_never_offered_is_stripped_from_the_answer(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([$this->row('A')]);

        // Le modele cite [S7] : aucune source ne porte ce numero.
        $this->fakeAgent('ARIA est une alliance. [S1] Elle compte douze partenaires. [S7]');

        $answer = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'C\'est quoi ARIA ?'])
            ->assertOk()
            ->json('data.answer');

        $this->assertStringContainsString('[S1]', $answer);
        $this->assertStringNotContainsString('[S7]', $answer, 'une citation non offerte ne doit jamais atteindre le lecteur');
    }

    public function test_an_answer_citing_nothing_is_reported_as_not_grounded(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([$this->row('A')]);
        $this->fakeAgent('ARIA est probablement une alliance artistique.');

        $response = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'C\'est quoi ARIA ?'])
            ->assertOk();

        $response->assertJsonPath('data.grounded', false);
        $this->assertSame([], $response->json('data.sources'));
    }

    // ── 3. Les approfondissements ───────────────────────────────────────────

    public function test_the_follow_up_questions_are_parsed_and_kept_out_of_the_answer(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([$this->row('A')]);

        $this->fakeAgent(<<<'MD'
            ARIA signifie ARtistic Intelligence Alliance. [S1]

            ## Possible questions
            - What is the main objective of ARIA?
            - What are the five pillars?
            - What is the role of the partners?
            MD);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'What is ARIA?'])
            ->assertOk();

        $this->assertSame([
            'What is the main objective of ARIA?',
            'What are the five pillars?',
            'What is the role of the partners?',
        ], $response->json('data.follow_up_questions'));

        $this->assertStringNotContainsString('Possible questions', $response->json('data.answer'),
            'le titre de rubrique est une mecanique de parseur, jamais du texte de reponse');
    }

    public function test_never_more_than_three_follow_ups_reach_the_reader(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([$this->row('A')]);

        $this->fakeAgent(<<<'MD'
            ARIA est une alliance. [S1]

            ## Possible questions
            - Une
            - Deux
            - Trois
            - Quatre
            - Cinq
            MD);

        $this->assertCount(3, $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'C\'est quoi ARIA ?'])
            ->assertOk()
            ->json('data.follow_up_questions'));
    }

    // ── 4. Le non-savoir ────────────────────────────────────────────────────

    public function test_an_empty_retrieval_answers_honestly_without_calling_the_provider(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([]);
        LoopKnowledgeAgent::fake([]);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'Quel est le budget secret ?'])
            ->assertOk();

        $response->assertJsonPath('data.grounded', false);
        $this->assertSame([], $response->json('data.sources'));
        $this->assertNotSame('', trim((string) $response->json('data.answer')));
        // Aucun appel provider : il n'y a rien a fonder.
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_a_question_shorter_than_two_characters_is_refused(): void
    {
        $this->mockSearch()->shouldNotReceive('searchAcrossDossiers');

        $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => ' '])
            ->assertStatus(422);
    }

    // ── 5. La restriction a un fichier nomme ────────────────────────────────

    public function test_a_named_file_of_this_dossier_restricts_the_retrieval_server_side(): void
    {
        $target = $this->file('260908-20h12-ARIA template Part B_EU.docx');
        $this->file('Autre document.docx');

        $this->mockSearch()
            ->shouldReceive('searchAcrossDossiers')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[7] ?? null) === [(string) $target->id])
            ->andReturn([$this->row('A')]);

        $this->fakeAgent('ARIA est une alliance. [S1]');

        $this->actingAs($this->owner)
            ->postJson($this->url(), [
                'question' => 'Que dit ce document ?',
                'file' => '260908-20h12-ARIA template Part B_EU.docx',
            ])
            ->assertOk();
    }

    /**
     * Un fichier d'un AUTRE Dossier porte le meme nom : la resolution est
     * bornee au Dossier courant, elle ne doit pas le trouver.
     */
    public function test_a_file_named_outside_the_current_dossier_is_never_resolved(): void
    {
        $elsewhere = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Autre Dossier',
            'visibility' => 'private',
        ]);
        $this->file('secret-ailleurs.docx', $elsewhere);

        $this->mockSearch()
            ->shouldReceive('searchAcrossDossiers')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[7] ?? null) === [])
            ->andReturn([]);

        LoopKnowledgeAgent::fake([]);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'Que dit ce document ?', 'file' => 'secret-ailleurs.docx'])
            ->assertOk();

        $this->assertSame(trans('dossiers.answer_no_source_in_file', [], 'en'), $response->json('data.answer'));
        $this->assertSame([], $response->json('data.consulted'));
    }

    public function test_a_multi_part_b_family_stays_strictly_scoped_and_abstains_without_a_global_budget(): void
    {
        $partB1 = $this->file('260908-ARIA template Part B_EU.docx');
        $partB2 = $this->file('260909-ARIA template Part B_EU revised.docx');
        $budgetPdf = $this->file('BouclePro OLATS budget total 60000 EUR.pdf');
        $expectedIds = [(string) $partB1->id, (string) $partB2->id];

        $this->mockSearch()
            ->shouldReceive('searchAcrossDossiers')
            ->once()
            ->withArgs(function (...$args) use ($expectedIds, $budgetPdf): bool {
                $actualIds = $args[7] ?? null;

                if (! is_array($actualIds)) {
                    return false;
                }

                sort($actualIds);
                sort($expectedIds);

                return $actualIds === $expectedIds
                    && ! in_array((string) $budgetPdf->id, $actualIds, true);
            })
            ->andReturn([
                $this->fileRow($partB1, 'Part B version initiale : aucun budget global n est indique.'),
                $this->fileRow($partB2, 'Part B revisee : aucun budget global n est indique.'),
            ]);

        $this->fakeAgent('Les versions Part B ne permettent pas d etablir un budget global. [S1] [S2]');

        $response = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'Quel est le budget global de Part B ?', 'file' => 'Part B'])
            ->assertOk();

        $this->assertSame(
            'Les versions Part B ne permettent pas d etablir un budget global. [S1] [S2]',
            $response->json('data.answer'),
        );
        $this->assertEqualsCanonicalizing(
            [$partB1->display_name, $partB2->display_name],
            array_column($response->json('data.consulted'), 'title'),
        );
        $this->assertNotContains($budgetPdf->display_name, array_column($response->json('data.consulted'), 'title'));
        $this->assertStringNotContainsString('60 000', $response->json('data.answer'));
    }

    /**
     * Le CDC decrit une phrase naturelle, pas un champ a part : le nom du
     * fichier est DANS la question.
     */
    public function test_a_file_name_written_inside_the_question_restricts_the_retrieval(): void
    {
        $target = $this->file('260908-20h12-ARIA template Part B_EU.docx');

        $this->mockSearch()
            ->shouldReceive('searchAcrossDossiers')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[7] ?? null) === [(string) $target->id])
            ->andReturn([$this->row('A')]);

        $this->fakeAgent('Le document decrit le projet. [S1]');

        $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'Cherche uniquement dans 260908-20h12-ARIA template Part B_EU.docx'])
            ->assertOk();
    }

    /**
     * Le retrecissement MUET est pire que l'absence de fonction : un fichier
     * nomme « ARIA » ne doit pas transformer « C'est quoi ARIA ? » en question
     * portant sur ce seul document.
     */
    public function test_a_bare_word_matching_a_file_never_silently_narrows_the_corpus(): void
    {
        $this->file('ARIA');

        $this->mockSearch()
            ->shouldReceive('searchAcrossDossiers')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[7] ?? null) === null)
            ->andReturn([$this->row('A')]);

        $this->fakeAgent('ARIA est une alliance. [S1]');

        $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'C\'est quoi ARIA ?'])
            ->assertOk();
    }

    // ── 6. La langue du lecteur ─────────────────────────────────────────────

    /**
     * Difference ASSUMEE avec `generate()` : un Insight suit la langue de
     * l'Organization (il est relu par tout le cercle), une reponse suit celle
     * de qui la lit. L'Organization est ici anglophone.
     */
    public function test_the_answer_follows_the_reader_locale_not_the_organization_locale(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([]);

        // `app()->setLocale()` ne survit pas a la requete : le middleware
        // `SetLocale` recalcule la langue depuis `preferred_locale`. C'est
        // cette preference qui fait foi, et c'est elle qu'on regle.
        $this->owner->forceFill(['preferred_locale' => 'fr'])->save();

        $answer = $this->actingAs($this->owner)
            ->postJson($this->url(), ['question' => 'Quel est le budget ?'])
            ->assertOk()
            ->json('data.answer');

        $this->assertSame(trans('dossiers.answer_no_source', [], 'fr'), $answer);
        $this->assertNotSame(trans('dossiers.answer_no_source', [], 'en'), $answer);
    }

    // ── 7. Les deux langues portent le contrat ──────────────────────────────

    public function test_both_locales_carry_the_answer_contract(): void
    {
        $fr = require lang_path('fr/dossiers.php');
        $en = require lang_path('en/dossiers.php');

        $this->assertSame(array_keys($en), array_keys($fr));

        foreach (['answer_title', 'answer_help', 'answer_button', 'answer_sources_heading',
            'answer_follow_ups_heading', 'answer_no_source', 'answer_preset_instruction'] as $key) {
            $this->assertArrayHasKey($key, $fr, "cle {$key} absente du francais");
            $this->assertNotSame('', trim((string) $fr[$key]));
        }

        // Le contrat dicte au modele doit porter les deux substitutions,
        // sinon la question ou le titre de rubrique n'atteindraient jamais le tour.
        foreach ([$fr, $en] as $bundle) {
            $this->assertStringContainsString(':question', (string) $bundle['answer_preset_instruction']);
            $this->assertStringContainsString(':questions_heading', (string) $bundle['answer_preset_instruction']);
        }

        $this->assertStringContainsString('trouvé', (string) $fr['answer_no_source'], 'texte visible en francais : les accents ne sont pas facultatifs');
    }
}
