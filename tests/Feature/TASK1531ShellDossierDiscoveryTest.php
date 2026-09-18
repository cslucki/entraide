<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\ShellGeneralAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\Support\Ai\FakeDossierSemanticSearch;
use Tests\TestCase;

/**
 * TASK-1531 — le Shell retrouve un Dossier autorise SANS contexte prealable.
 *
 * Dernier cas du parcours ARIA : depuis le dashboard, sans avoir ouvert le
 * Dossier, sans pin, sans historique. T1519/T1520 exigent un objet sous les
 * yeux, T1530 un objet deja discute ; ici il n'y a ni l'un ni l'autre.
 *
 * ## Ce que cette suite mesure, et sur quelle couche
 *
 * Le double partage `FakeDossierSemanticSearch` ENREGISTRE son appel. On lit
 * donc `lastCall['dossierIds']` : c'est la preuve directe que le perimetre
 * AUTORISE est etabli AVANT la recherche, et non filtre apres coup. Un test
 * qui se contenterait du contenu de la reponse ne distinguerait pas les deux.
 *
 * L'absence d'appel est mesuree de la meme facon (`lastCall === null`) : c'est
 * ce qui prouve que la garde de declenchement s'execute avant le balayage de
 * perimetre et avant tout embedding — le vrai cout de cette branche.
 *
 * La preuve du moteur lui-meme (pgvector, distances, ordre) n'est PAS ici :
 * elle est dans `Tests\Feature\Dossiers\PgvectorTASK1531ShellDossierDiscoveryTest`,
 * sous PostgreSQL, parce qu'un double ne prouve aucun retrieval.
 */
class TASK1531ShellDossierDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Dossier $aria;

    private FakeDossierSemanticSearch $search;

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

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1531',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
            // Le test CONSTRUIT son environnement (lecon T1530) : sans `.env`,
            // ces commutateurs retombent sur des defauts qui eteignent les
            // chemins mesures ici.
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai.knowledge.max_distance' => 1.0,
        ]);

        Http::preventStrayRequests();

        // Le double enregistre l'appel : c'est lui qui porte la preuve d'ACL.
        $this->search = new FakeDossierSemanticSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);
        $this->searchFinds($this->aria, 'Les partenaires ARIA sont ACMEPUB et ZORGLUBPUB.');

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Reponse documentaire decouverte. [S1]', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
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

    /** Le moteur rendra cet extrait, rattache a ce Dossier. */
    private function searchFinds(Dossier $dossier, string $content): void
    {
        $this->search->rows = [[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => $dossier->id, 'dossier_name' => $dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 1, 'content' => $content, 'distance' => 0.2,
        ]];
    }

    /** Un tour depuis une page NEUTRE, fil vide : aucun contexte prealable. */
    private function askFromDashboard(string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve($this->member, $this->organization, null, null);

        $this->assertNotSame(AiShellPageContext::KIND_DOSSIER, $context['kind'] ?? null,
            'premisse : la page courante n est pas un Dossier');

        $this->actingAs($this->member);

        return app(AiShellResponder::class)->respond($this->organization, $this->member, $question, $context)['answer'];
    }

    // ── 1. Le cas reel ──────────────────────────────────────────────────────

    /**
     * Sabotage : retirer la branche de la chaine → rouge.
     */
    public function test_a_documentary_question_from_the_dashboard_discovers_an_authorized_dossier(): void
    {
        $reponse = $this->askFromDashboard('Qui sont les partenaires ARIA ?');

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_DISCOVERY, $reponse->metadata['producer'],
            'sans page ni historique, la decouverte doit produire le tour documentaire');
        $this->assertTrue($reponse->metadata['discovery']);
        $this->assertNotSame([], $reponse->metadata['sources'], 'la reponse est sourcee');
        $this->assertSame((string) $this->aria->id, $reponse->metadata['page_context']['object_id'],
            'le Dossier trouve est trace, pour que le tour suivant le reprenne');

        // La recherche a bien eu lieu, bornee au perimetre autorise.
        $this->assertNotNull($this->search->lastCall);
        $this->assertSame([(string) $this->aria->id], $this->search->lastCall['dossierIds']);
    }

    // ── 2 et 3. ACL : inaccessible et cross-tenant ──────────────────────────

    /**
     * Un Dossier de l'Organization auquel le membre n'a PAS acces n'entre
     * jamais dans le perimetre de recherche, et son nom ne sort nulle part.
     *
     * Sabotage : remplacer `accessibleDossierIds()` par un `pluck('id')` brut
     * sur l'Organization → rouge.
     */
    public function test_an_inaccessible_dossier_never_enters_the_search_perimeter(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $prive = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $etranger->id,
            'name' => 'DOSSIERPRIVEINTERDIT',
            'visibility' => 'private',
        ]);

        $this->assertTrue($this->member->cannot('view', $prive), 'premisse : le membre n y a pas acces');

        $reponse = $this->askFromDashboard('Qui sont les partenaires ARIA ?');

        $this->assertNotNull($this->search->lastCall);
        $this->assertNotContains((string) $prive->id, $this->search->lastCall['dossierIds'],
            'un Dossier interdit n est jamais un candidat');
        $this->assertStringNotContainsString('DOSSIERPRIVEINTERDIT', $reponse->content,
            'et son nom ne devient ni reponse, ni clarification, ni indice');
        $this->assertStringNotContainsString('DOSSIERPRIVEINTERDIT', json_encode($reponse->metadata, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Organization = Tenant : un Dossier d'une autre Organization n'est ni
     * candidat, ni cite, ni nomme.
     *
     * Sabotage : passer l'`organization_id` d'un autre tenant au scope → rouge.
     */
    public function test_a_dossier_of_another_organization_is_never_used(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $autreMembre = User::factory()->create(['organization_id' => $autreOrg->id]);
        $etranger = Dossier::create([
            'organization_id' => $autreOrg->id,
            'owner_id' => $autreMembre->id,
            'name' => 'DOSSIERETRANGERARIA',
            'visibility' => 'organization',
        ]);

        $reponse = $this->askFromDashboard('Qui sont les partenaires ARIA ?');

        $this->assertNotNull($this->search->lastCall);
        $this->assertSame((string) $this->organization->id, $this->search->lastCall['organizationId']);
        $this->assertNotContains((string) $etranger->id, $this->search->lastCall['dossierIds']);
        $this->assertStringNotContainsString('DOSSIERETRANGERARIA', $reponse->content);
        $this->assertStringNotContainsString('DOSSIERETRANGERARIA', json_encode($reponse->metadata, JSON_UNESCAPED_UNICODE));
    }

    // ── 4 et 5. Le cout : la garde s'execute AVANT le perimetre ─────────────

    /**
     * Une question sur le PRODUIT ne declenche aucune recherche : zero appel a
     * `searchAcrossDossiers`, donc zero balayage de policy et zero embedding.
     *
     * Sabotage : deplacer la garde APRES `accessibleDossierIds()` → rouge.
     */
    public function test_a_general_product_question_triggers_no_search_at_all(): void
    {
        $reponse = $this->askFromDashboard('Quelle est la difference entre une Boucle et une Organization ?');

        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer']);
        $this->assertNull($this->search->lastCall, 'aucune recherche documentaire ne doit etre tentee');
    }

    /**
     * Une intention d'entraide non plus.
     *
     * Sabotage : retirer `mentionsInteractionIntent()` de la garde → rouge.
     */
    public function test_a_human_help_request_triggers_no_search_at_all(): void
    {
        $reponse = $this->askFromDashboard('Quelqu\'un peut m\'aider a trouver un expert ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_DISCOVERY, $reponse->metadata['producer']);
        $this->assertNull($this->search->lastCall, 'une demande d aide humaine ne fouille pas les Dossiers');
    }

    /** Un enonce sans sujet ne paie pas le balayage de perimetre. */
    public function test_a_subjectless_question_triggers_no_search_at_all(): void
    {
        $this->askFromDashboard('Comment allez vous ?');

        $this->assertNull($this->search->lastCall, 'sans sujet, il n y a rien a chercher');
    }

    // ── 6. Zero source ──────────────────────────────────────────────────────

    /**
     * Retrieval vide : la branche disparait, le chemin general repond
     * honnetement, et rien n'est fabrique.
     *
     * Sabotage : autoriser une reponse sans source → rouge.
     */
    public function test_an_empty_retrieval_makes_the_branch_disappear(): void
    {
        $this->search->rows = [];

        $reponse = $this->askFromDashboard('Qui sont les partenaires ARIA ?');

        $this->assertNotNull($this->search->lastCall, 'la recherche a bien ete tentee');
        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer'],
            'sans source, c est le chemin general qui repond');
        $this->assertArrayNotHasKey('sources', $reponse->metadata, 'aucune source inventee');
    }

    /** Un extrait trop lointain est ecarte comme s'il n'existait pas. */
    public function test_a_result_beyond_max_distance_is_discarded(): void
    {
        config(['ai.knowledge.max_distance' => 0.1]);
        $this->searchFinds($this->aria, 'Extrait trop lointain.');

        $reponse = $this->askFromDashboard('Qui sont les partenaires ARIA ?');

        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer']);
    }

    // ── 7. Plusieurs Dossiers pertinents ────────────────────────────────────

    /**
     * La reponse est pilotee par les SOURCES effectivement rendues, pas par le
     * nombre de Dossiers autorises : les deux sont dans le perimetre, seul
     * celui que la recherche rend est trace et cite.
     */
    public function test_the_answer_follows_the_returned_sources_not_the_perimeter(): void
    {
        $second = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'HORIZON',
            'visibility' => 'private',
        ]);

        // Les deux sont autorises ; la recherche ne rend que le second.
        $this->searchFinds($second, 'Le contenu pertinent est celui de HORIZON.');

        $reponse = $this->askFromDashboard('Qui sont les partenaires ARIA ?');

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_DISCOVERY, $reponse->metadata['producer']);
        $this->assertNotNull($this->search->lastCall);
        $this->assertEqualsCanonicalizing(
            [(string) $this->aria->id, (string) $second->id],
            $this->search->lastCall['dossierIds'],
            'les deux Dossiers autorises sont bien dans le perimetre',
        );
        $this->assertSame((string) $second->id, $reponse->metadata['page_context']['object_id'],
            'mais le tour est rattache au Dossier que la recherche a effectivement rendu');
    }

    // ── Precedence : les branches amont gardent la main ─────────────────────

    /** Sur une page Dossier, c'est cette page qui repond, pas la decouverte. */
    public function test_the_current_dossier_page_keeps_priority_over_discovery(): void
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->member, $this->organization, AiShellPageContext::KIND_DOSSIER, $this->aria->id,
        );
        $this->actingAs($this->member);

        $reponse = app(AiShellResponder::class)
            ->respond($this->organization, $this->member, 'Qui sont les partenaires ARIA ?', $context)['answer'];

        $this->assertSame('dossier.answer', $reponse->metadata['producer']);
    }

    /**
     * Et un Dossier deja discute est repris par T1530 : la decouverte n'entre
     * que lorsque le fil ne designe plus rien.
     */
    public function test_a_thread_dossier_keeps_priority_over_discovery(): void
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->member, $this->organization, AiShellPageContext::KIND_DOSSIER, $this->aria->id,
        );
        $this->actingAs($this->member);
        app(AiShellResponder::class)->respond($this->organization, $this->member, 'C\'est quoi ARIA ?', $context);

        $reponse = $this->askFromDashboard('Qui sont les partenaires ?');

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer'],
            'la continuite T1530 passe avant la decouverte');
    }
}
