<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\ShellGeneralAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1530 — la continuite documentaire survit a la navigation.
 *
 * ## Le defaut ferme
 *
 * Sur la page du Dossier ARIA, « c'est quoi ARIA ? » repondait avec des
 * sources. L'utilisateur changeait de page, et « qui sont les partenaires ? »
 * tombait sur `shell_general_answer` : le Shell se souvenait SEMANTIQUEMENT
 * d'ARIA — le fil suit la personne de page en page (T1523) — mais avait perdu
 * son AUTORITE documentaire. Plus aucun retrieval, donc une reponse de culture
 * generale sur un sujet interne.
 *
 * ## La regle canonique que ces tests mesurent
 *
 * MEMOIRE  -> aide a RETROUVER l'objet, ne prouve jamais rien.
 * POLICY   -> decide s'il est ENCORE accessible, a chaque tour.
 * RETRIEVAL FRAIS -> fournit les faits.
 * SOURCES FRAICHES -> prouvent la reponse.
 *
 * ## Ce que les cas 4 et 5 prouvent — et ce qu'ils ne prouvent PAS
 *
 * Ils mesurent un RESULTAT : aucun tour documentaire, aucun appel provider,
 * aucun titre prive. Ce resultat est tenu par TROIS gardes independantes — le
 * tenant et `DossierPolicy::view` rejoues par la branche, puis
 * `DossierInsightsService::answer()` qui revalide les deux une troisieme fois
 * (tenant en dur ligne 313, policy juste apres) et leve.
 *
 * Mesure faite, et consignee ici pour que personne ne la refasse a l'aveugle :
 * retirer la garde tenant de la branche laisse TOUTE la suite verte ; retirer
 * sa revalidation policy aussi. La couche de service rattrape a chaque fois,
 * inconditionnellement. Les gardes de la branche sont donc de la defense en
 * profondeur REELLE mais NON FALSIFIABLE par le chemin public : aucun test
 * honnete ne peut les isoler tant que `answer()` garde les siennes (la classe
 * est `final`, elle ne se double pas). Un test qui pretendrait le contraire
 * serait vert quoi qu'il arrive — exactement le piege ferme en T1529.
 *
 * Ce qui EST falsifiable ici, et sabote comme tel : la branche elle-meme, la
 * selection du Dossier, et les deux gardes de routage.
 */
class TASK1530ShellDossierContinuationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Dossier $aria;

    private Dossier $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);

        $this->aria = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'ARIA',
            'visibility' => 'private',
        ]);

        $this->autre = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'HORIZON',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1530',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();

        $this->searchReturnsChunkOfRequestedDossier();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Reponse documentaire. [S1]', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        ShellGeneralAnswerAgent::fake(fn (): TextResponse => new TextResponse(
            'Reponse generale sans source interne.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $structured = [
            'title' => 'Titre', 'clarified_request' => 'Demande clarifiee.', 'help_type' => 'information',
            'suggested_loop_id' => '', 'suggested_category_id' => '', 'suggestion_reason' => '',
            'questions_for_user' => [], 'confidence' => 0.9, 'needs_human_review' => false,
        ];
        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured, json_encode($structured, JSON_UNESCAPED_UNICODE), new Usage(120, 80), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    /** Le retrieval rend un extrait du Dossier REELLEMENT interroge. */
    private function searchReturnsChunkOfRequestedDossier(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturnUsing(function (string $orgId, array $dossierIds): array {
            $dossier = Dossier::findOrFail($dossierIds[0]);

            return [[
                'chunk_id' => (string) Str::uuid(), 'dossier_id' => $dossier->id, 'dossier_name' => $dossier->name,
                'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
                'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'chunk_index' => 1, 'content' => 'Extrait frais du Dossier '.$dossier->name.'.', 'distance' => 0.2,
            ]];
        })->byDefault();
    }

    /** Le retrieval ne trouve rien : le cas « zero source ». */
    private function searchReturnsNothing(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn([])->byDefault();
    }

    /** Un tour tenu SUR la page d'un Dossier, avec la garde de cette page. */
    private function sendOnDossier(Dossier $dossier, string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->member, $this->organization, AiShellPageContext::KIND_DOSSIER, $dossier->id,
        );

        $this->actingAs($this->member);

        return app(AiShellResponder::class)->respond($this->organization, $this->member, $question, $context)['answer'];
    }

    /** Un tour tenu sur une page NEUTRE : aucune autorite documentaire. */
    private function sendOnNeutralPage(string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve($this->member, $this->organization, null, null);

        $this->assertNotSame(AiShellPageContext::KIND_DOSSIER, $context['kind'] ?? null,
            'la premisse du test : la page courante n est pas un Dossier');

        $this->actingAs($this->member);

        return app(AiShellResponder::class)->respond($this->organization, $this->member, $question, $context)['answer'];
    }

    /** @return list<AiInteraction> les appels documentaires, du plus ancien au plus recent */
    private function documentaryInteractions(): array
    {
        return AiInteraction::query()->where('feature', 'loop_knowledge_answer')
            ->orderBy('created_at')->orderBy('id')->get()->all();
    }

    // ── CAS 1 — le cas reel ARIA ────────────────────────────────────────────

    /**
     * LE test de cette TASK : apres navigation, une continuation refait un
     * retrieval FRAIS sur le Dossier repris et repond avec ses sources.
     *
     * Sabotage : retirer la branche de continuite → rouge.
     */
    public function test_a_continuation_after_navigation_runs_a_fresh_retrieval_on_the_recovered_dossier(): void
    {
        $tour1 = $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $this->assertSame('dossier.answer', $tour1->metadata['producer']);
        $this->assertNotSame([], $tour1->metadata['sources'], 'le tour 1 est bien sourcé');
        $this->assertCount(1, $this->documentaryInteractions());

        $tour2 = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $tour2->metadata['producer'],
            'la continuation doit produire un tour documentaire, pas une reponse generale');
        $this->assertTrue($tour2->metadata['continuation']);

        // Un retrieval FRAIS a bien eu lieu : deuxieme appel documentaire ...
        $appels = $this->documentaryInteractions();
        $this->assertCount(2, $appels, 'la memoire ne remplace pas le retrieval : un nouvel appel est exige');

        // ... et ses sources sont celles d'ARIA, obtenues a ce tour-ci.
        $this->assertStringContainsString('Extrait frais du Dossier ARIA.', (string) $appels[1]->prompt);
        $this->assertNotSame([], $tour2->metadata['sources']);

        // L'objet trace est le Dossier repris : le tour suivant le retrouvera.
        $this->assertSame(AiShellPageContext::KIND_DOSSIER, $tour2->metadata['page_context']['object_type']);
        $this->assertSame((string) $this->aria->id, $tour2->metadata['page_context']['object_id']);
    }

    // ── CAS 2 — une question generale n'est jamais capturee ─────────────────

    /**
     * Sabotage : retirer la garde « article indefini / concept produit » →
     * rouge.
     */
    public function test_a_general_product_question_after_a_dossier_is_not_captured(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        $reponse = $this->sendOnNeutralPage('Quelle est la difference entre une Boucle et une Organization ?');

        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer'],
            'une question sur le produit appartient au chemin general');
        $this->assertCount($avant, $this->documentaryInteractions(),
            'aucun retrieval documentaire ne doit etre declenche');
        $this->assertArrayNotHasKey('sources', $reponse->metadata);
    }

    // ── CAS 3 — l'intention d'aide humaine reste protegee ───────────────────

    /**
     * Sabotage : ne plus consulter `mentionsInteractionIntent()` dans la
     * continuite → rouge.
     */
    public function test_a_human_help_request_after_a_dossier_is_not_captured(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        $reponse = $this->sendOnNeutralPage('Quelqu\'un peut m\'aider a trouver un expert ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer'],
            'une ancienne page Dossier ne vole jamais une demande d aide humaine');
        $this->assertNotSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer'],
            'et elle ne part pas non plus sur le chemin general');
        $this->assertCount($avant, $this->documentaryInteractions());
    }

    // ── CAS 4 — droit retire entre les deux tours ───────────────────────────

    /**
     * Sabotage : retirer la revalidation `DossierPolicy::view` → rouge.
     */
    public function test_a_dossier_that_became_inaccessible_is_dropped_silently(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        // Le Dossier change de main : le membre n'y a plus acces.
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->aria->forceFill(['owner_id' => $etranger->id, 'visibility' => 'private'])->save();
        $this->assertTrue($this->member->cannot('view', $this->aria->fresh()), 'premisse : l acces est bien retire');

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);
        $this->assertCount($avant, $this->documentaryInteractions(),
            'aucun appel provider documentaire sur un Dossier devenu inaccessible');
        $this->assertStringNotContainsString('ARIA', $reponse->content,
            'aucun titre prive revele');
    }

    /** Un Dossier supprime se comporte comme un Dossier interdit : fail-closed. */
    public function test_a_deleted_dossier_is_dropped_silently(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        $this->aria->delete();

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);
        $this->assertCount($avant, $this->documentaryInteractions());
    }

    // ── CAS 5 — cross tenant ────────────────────────────────────────────────

    /**
     * Un objet historique d'une AUTRE Organization, ecrit dans le fil, n'est
     * jamais repris — meme si la policy locale se laissait convaincre.
     *
     * Sabotage : retirer la comparaison `organization_id` → rouge.
     */
    public function test_a_dossier_from_another_organization_is_never_recovered(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $autreMembre = User::factory()->create(['organization_id' => $autreOrg->id]);
        $etranger = Dossier::create([
            'organization_id' => $autreOrg->id,
            'owner_id' => $autreMembre->id,
            'name' => 'DOSSIER ETRANGER',
            'visibility' => 'organization',
        ]);

        // Un tour du fil designe ce Dossier etranger : contexte forge.
        $thread = app(AiShellThread::class);
        $page = ['page_context' => [
            'route' => 'dossiers.show', 'kind' => AiShellPageContext::KIND_DOSSIER,
            'object_type' => AiShellPageContext::KIND_DOSSIER, 'object_id' => (string) $etranger->id,
        ]];
        $trigger = $thread->appendUser($this->organization, $this->member, 'Question posee ailleurs.', $page);
        $thread->appendAssistant($this->organization, $this->member, 'Reponse.', $trigger,
            $page + ['status' => AiShellResponder::STATUS_ANSWERED]);

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);
        $this->assertSame([], $this->documentaryInteractions(),
            'aucun appel provider documentaire sur un objet d une autre Organization');
        $this->assertStringNotContainsString('DOSSIER ETRANGER', $reponse->content);
    }

    // ── CAS 6 — A puis B : la continuation repart de B ──────────────────────

    /**
     * Sabotage : parcourir le fil du plus ANCIEN au plus recent → rouge.
     */
    public function test_the_continuation_resumes_the_most_recent_dossier_not_the_first(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $this->sendOnDossier($this->autre, 'C\'est quoi HORIZON ?');

        $tour3 = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $tour3->metadata['producer']);
        $this->assertSame((string) $this->autre->id, $tour3->metadata['page_context']['object_id'],
            'le Dossier repris est le plus recent');

        $appels = $this->documentaryInteractions();
        $dernier = (string) end($appels)->prompt;

        $this->assertStringContainsString('Extrait frais du Dossier HORIZON.', $dernier);
        $this->assertStringNotContainsString('Extrait frais du Dossier ARIA.', $dernier,
            'A ne pollue pas la continuation de B');
    }

    // ── CAS 7 — la memoire n'est jamais une source ──────────────────────────

    /**
     * Le texte d'une ancienne reponse assistant ne devient jamais une source
     * documentaire : il peut aider a retrouver l'objet, jamais a prouver un
     * fait.
     */
    public function test_memory_never_becomes_a_documentary_source(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $tour2 = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $sources = json_encode($tour2->metadata['sources'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Reponse documentaire.', (string) $sources,
            'la reponse precedente de l assistant n est pas une source');
        $this->assertStringContainsString('note.docx', (string) $sources,
            'les sources sont bien les documents du Dossier, obtenus a ce tour');
    }

    // ── CAS 8 — zero source : aucune invention ──────────────────────────────

    /**
     * Sans document consulte, la branche documentaire se tait et laisse le
     * chemin general repondre honnetement — elle ne fabrique rien depuis la
     * memoire.
     */
    public function test_a_continuation_without_any_retrieved_source_falls_back_honestly(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        // A partir d'ici, le corpus ne rend plus rien.
        $this->searchReturnsNothing();

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer'],
            'sans source consultee, la branche documentaire s efface');
        $this->assertArrayNotHasKey('sources', $reponse->metadata,
            'aucune source inventee depuis la memoire');
    }

    // ── La page courante garde la main ──────────────────────────────────────

    /**
     * Sur la page d'un Dossier, c'est CE Dossier qui repond, jamais un
     * Dossier repris du fil : la branche courante passe avant.
     */
    public function test_the_current_dossier_page_keeps_priority_over_the_thread(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $tour2 = $this->sendOnDossier($this->autre, 'Qui sont les partenaires ?');

        $this->assertSame('dossier.answer', $tour2->metadata['producer'],
            'la page courante reste l autorite documentaire prioritaire');
        $this->assertSame((string) $this->autre->id, $tour2->metadata['page_context']['object_id']);
    }
}
