<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiRunManifest;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiTruthLabel;
use App\Support\Ai\AiTurnComparison;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1584 / CDC-02 TRACE-1C — « deux tours se comparent ».
 *
 * C1 dossiers vs ia : identite (mode, execution_path) a part, premiere etape
 * divergente = context_builder, classe PIPELINE ; C2 LoopChat doc vs Shell
 * doc : IDENTITY execution_path + context_builder bypassee, classe CONTEXT ;
 * C3 deux tours deterministes : first_divergent_step null, divergences ⊆
 * TIMING ; C4 tour pre-V0 : UNAVAILABLE, jamais « identique » ; C5
 * compare-runs 2×3 : 3 paires ; s3 schemas differents : comparables sur la
 * v1, et c'est DIT ; tenant.
 *
 * Contrat d'etapes = les 8 reellement emises (arbitrage MASTER 16/09 :
 * `source_filtering` n'est jamais emis, il sort du contrat et n'entre pas
 * dans le moteur).
 */
#[Group('ai')]
class TASK1584TurnComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    private string $chunkId;

    private bool $rechercheRend = true;

    /** @var list<string> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1584', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1584']);
        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle comparee');
        $this->dossier = Dossier::factory()->create(['organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'Dossier compare', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id]);
        $this->chunkId = (string) Str::uuid();

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key', 'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [], 'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id], 'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true, 'ai.chatloop.min_summary_words' => 0, 'ai.fab.enabled' => true, 'ai.shell.enabled' => true, 'ai.clarify.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturnUsing(fn (): array => $this->rechercheRend ? [[
            'chunk_id' => $this->chunkId, 'dossier_id' => (string) $this->dossier->id, 'dossier_name' => $this->dossier->name, 'source_type' => 'file',
            'blog_post_id' => null, 'title' => null, 'slug' => null, 'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => 0.2,
        ]] : [])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse directe.'));
        ShellGeneralAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse generale.'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->runs as $runId) {
            File::delete(AiRunManifest::path($runId));
        }
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── contrat

    public function test_c0_le_contrat_compare_les_huit_etapes_reellement_emises_sans_source_filtering(): void
    {
        $this->assertSame(['conversation_history', 'economic_check', 'context_builder', 'retrieval', 'rerank', 'grounding', 'provider_call', 'generation'], AiTurnComparison::STEPS);
        $this->assertNotContains('source_filtering', AiTurnComparison::STEPS);
        $this->assertNotContains('provider_resolution', AiTurnComparison::STEPS, 'un stage de verdict, pas une etape');
        // FACT : aucun emetteur de `source_filtering` dans app/ — le contrat
        // suit le moteur, jamais l'inverse.
        $this->assertSame([], $this->fichiersAppContenant("'source_filtering'"));
        $this->assertSame(['CITATION', 'CONTEXT', 'GENERATION', 'HISTORY', 'IDENTITY', 'OUTCOME', 'PIPELINE', 'PROVIDER', 'RERANK', 'RETRIEVAL', 'TIMING'], collect(AiTurnComparison::CLASSES)->sort()->values()->all());
    }

    // ────────────────────────────── C1 dossiers vs ia

    public function test_c1_dossiers_contre_ia_divergent_a_l_identite_puis_au_context_builder(): void
    {
        $dossiers = $this->tour(fn () => $this->dossiers('Que dit le document ?'));
        $ia = $this->tour(fn () => $this->ia('Quelle est la capitale de la France ?'));

        $c = $this->comparer((string) $dossiers->id, (string) $ia->id);

        $this->assertTrue($c['comparable']);
        $this->assertNull($c['schema_note']);
        $champs = array_column($c['identity_differences'], 'field');
        $this->assertContains('identity.mode', $champs);
        $this->assertContains('identity.execution_path', $champs);
        $this->assertSame(['a' => AiExecutionPath::LOOP_CHAT_DOSSIERS, 'b' => AiExecutionPath::LOOP_CHAT_IA], $this->div($c, 'identity.execution_path'));
        // La premiere etape divergente est le ContextBuilder : `executed` d'un
        // cote, `bypassed` (LLM_PATH_NO_CONTEXT_BUILDER) de l'autre — PIPELINE.
        $this->assertSame('context_builder', $c['first_divergent_step']);
        $this->assertSame('PIPELINE', $this->divergence($c, 'steps.context_builder')['class']);
        $this->assertSame(AiTurnReason::CONTEXT_BUILDER_LLM_PATH_NO_CONTEXT_BUILDER, $this->divergence($c, 'steps.context_builder')['b']['reason_code']);
        // conversation_history et economic_check, alignees, PRECEDENT le
        // context_builder : la premiere divergence n'est pas la premiere etape.
        $this->assertFalse($c['steps'][0]['divergent'], 'conversation_history alignee');
        $this->assertFalse($c['steps'][1]['divergent'], 'economic_check alignee');
        $this->assertContains('IDENTITY', $c['divergence_classes']);
        $this->assertContains('PIPELINE', $c['divergence_classes']);
        // Le texte de la reponse n'est JAMAIS compare.
        $this->assertSame([], array_filter($c['divergences'], static fn (array $d): bool => str_starts_with($d['field'], 'output.response')));
        $this->assertStringNotContainsString('Le document dit ceci', json_encode($c));
    }

    // ────────────────────────────── C2 LoopChat doc vs Shell doc

    public function test_c2_loopchat_documentaire_contre_shell_documentaire_divergent_au_contexte(): void
    {
        $loopChat = $this->tour(fn () => $this->dossiers('Que dit le document ?'));
        $shell = $this->shell(AiShellPageContext::KIND_DOSSIER, (string) $this->dossier->id, 'Que dit ce dossier ?');

        $c = $this->comparer((string) $loopChat->id, (string) $shell->id);

        $this->assertTrue($c['comparable']);
        $this->assertSame('shell_message', $c['b']['key_kind']);
        $this->assertSame(['a' => AiExecutionPath::LOOP_CHAT_DOSSIERS, 'b' => AiExecutionPath::AI_SHELL_DOSSIER], $this->div($c, 'identity.execution_path'));
        $this->assertSame('IDENTITY', $this->divergence($c, 'identity.execution_path')['class']);
        // FACT, corrige par TASK-1595 : le writer documentaire du Shell
        // (`DossierInsightsService`) depose DESORMAIS `economic_check` et
        // `provider_call` — deux etages qu'il executait deja et qu'il ne disait
        // pas. Ces deux etapes sont donc ALIGNEES, et la premiere divergence
        // recule jusqu'au `context_builder`.
        //
        // Ce qui reste divergent n'est plus un trou de trace, c'est le
        // PIPELINE lui-meme : la voie documentaire directe n'a ni etape
        // `retrieval` ni etape `rerank`, parce qu'elle n'a ni bassin filtre par
        // distance ni reranker. La comparaison le dit, elle ne le complete pas.
        $this->assertFalse($this->etapeComparee($c, 'economic_check')['divergent'], 'economic_check alignee depuis T1595');
        $this->assertFalse($this->etapeComparee($c, 'provider_call')['divergent'], 'provider_call alignee depuis T1595');
        $this->assertSame('context_builder', $c['first_divergent_step']);
        $this->assertNull($this->etapeComparee($c, 'retrieval')['b']['status'], 'aucun etage de retrieval cote voie directe');
        $this->assertNull($this->etapeComparee($c, 'rerank')['b']['status'], 'aucun rerank cote voie directe');
        $etape = $this->divergence($c, 'steps.context_builder');
        $this->assertNull($etape['absent_in']);
        $this->assertSame('executed', $etape['a']['status']);
        $this->assertSame('bypassed', $etape['b']['status']);
        $this->assertSame(AiTurnReason::CONTEXT_BUILDER_DOCUMENT_PATH_DIRECT_EXECUTION, $etape['b']['reason_code']);
        $this->assertSame('CONTEXT', $etape['class'], 'la voie documentaire directe construit le contexte AUTREMENT : CONTEXT, pas PIPELINE');
    }

    /**
     * L'etape comparee, par son nom — la comparaison rend un tableau ORDONNE,
     * pas une map : le chercher par index se casserait au premier etage ajoute.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function etapeComparee(array $c, string $nom): array
    {
        $etapes = array_values(array_filter($c['steps'], static fn (array $e): bool => $e['name'] === $nom));

        $this->assertCount(1, $etapes, "l'etape comparee `{$nom}` doit apparaitre exactement une fois");

        return $etapes[0];
    }

    // ────────────────────────────── C3 determinisme

    public function test_c3_deux_tours_deterministes_ne_divergent_qu_en_timing(): void
    {
        $a = $this->tour(fn () => $this->dossiers('Que dit le document ?'));
        $b = $this->tour(fn () => $this->dossiers('Que dit le document ?'));

        $c = $this->comparer((string) $a->id, (string) $b->id);

        $this->assertTrue($c['comparable']);
        $this->assertSame([], $c['identity_differences']);
        $this->assertNull($c['first_divergent_step']);
        $this->assertSame([], array_filter($c['steps'], static fn (array $s): bool => $s['divergent']));
        $classes = array_unique(array_column($c['divergences'], 'class'));
        $this->assertSame([], array_diff($classes, ['TIMING']), 'divergences ⊆ TIMING : '.json_encode($c['divergences']));
        $this->assertSame(AiTruthLabel::MEASURED, $c['timing']['latency_ms']['label']);
        // Un compteur present des deux cotes et egal n'est PAS une divergence.
        $ia = $this->inspecter($a);
        $this->assertNotNull($ia['sources']['retrieved']['candidates']);
        // Une latence differente est une divergence TIMING — et SEULEMENT
        // cela : elle n'entre jamais dans first_divergent_step.
        $lent = $ia;
        $lent['decision']['latency_ms'] = ($ia['decision']['latency_ms'] ?? 0) + 500;
        $pur = AiTurnComparison::compare($ia, $lent);
        $this->assertNull($pur['first_divergent_step']);
        $this->assertSame([['field' => 'timing.latency_ms', 'a' => $ia['decision']['latency_ms'], 'b' => $lent['decision']['latency_ms'], 'class' => 'TIMING']], $pur['divergences']);
        // Une latence absente d'un cote : UNAVAILABLE, pas une divergence.
        $sans = $ia;
        $sans['decision']['latency_ms'] = null;
        $pur = AiTurnComparison::compare($ia, $sans);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $pur['timing']['latency_ms']['label']);
        $this->assertSame([], $pur['divergences']);
    }

    // ────────────────────────────── C4 pre-V0

    public function test_c4_un_tour_sans_bloc_turn_rend_unavailable_jamais_identique(): void
    {
        $recent = $this->tour(fn () => $this->dossiers('Que dit le document ?'));
        $ancien = AiInteraction::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->organization->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'knowledge.answer', 'feature' => 'loop_knowledge_answer', 'model' => 'x', 'prompt' => 'p', 'response' => 'r',
            'input_tokens' => 1, 'output_tokens' => 1,
            // Historique : `metadata.turn_id` seul, aucun bloc `turn`.
            'metadata' => ['turn_id' => (string) Str::uuid(), 'status' => 'answered', 'capability' => 'loop_knowledge_answer'],
        ]);

        $c = $this->comparer((string) $recent->id, (string) $ancien->id);

        $this->assertFalse($c['comparable']);
        $this->assertSame('turn_unavailable', $c['reason']);
        $this->assertFalse($c['b']['turn_available']);
        $this->assertNotNull($c['b']['turn_id'], 'le turn_id historique est rendu, il ne suffit pas a comparer');
        $this->assertNull($c['first_divergent_step']);
        $this->assertNull($c['divergences']);
        $this->assertNull($c['identity_differences']);
        // Meme resultat, dans l'autre sens, et avec DEUX anciens.
        $this->assertFalse($this->comparer((string) $ancien->id, (string) $recent->id)['comparable']);
        $this->assertFalse($this->comparer((string) $ancien->id, (string) $ancien->id)['comparable'], 'deux tours pre-V0 : on ne sait pas, on ne dit pas « identiques »');
        // Rendu texte : le mot est NON COMPARABLE.
        $this->artisan('ai:compare-turns', ['a' => (string) $recent->id, 'b' => (string) $ancien->id, '--organization' => $this->organization->slug])
            ->expectsOutputToContain('NON COMPARABLE')->assertExitCode(0);
    }

    // ────────────────────────────── C5 compare-runs

    public function test_c5_deux_runs_de_trois_tours_font_trois_paires(): void
    {
        $runA = $this->jouerRun(['dossiers', 'dossiers', 'dossiers']);
        $this->rechercheRend = false;
        $runB = $this->jouerRun(['dossiers', 'ia', 'dossiers']);

        $code = Artisan::call('ai:compare-runs', ['a' => $runA, 'b' => $runB, '--organization' => $this->organization->slug, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, $sortie);
        $r = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(3, $r['summary']['pairs']);
        $this->assertSame(0, $r['summary']['unpaired']);
        $this->assertSame([1, 2, 3], array_column($r['pairs'], 'order'));
        // #1 : meme chemin (`loop_chat.dossiers`), mais la recherche de B n'a
        // rien rendu. TASK-1595 — sur ce chemin il n'y a plus de seconde
        // provenance pour rattraper un retrieval vide : B S'ABSTIENT, et son
        // abstention laisse desormais un tour comparable au lieu de
        // disparaitre.
        //
        // Ce que la comparaison dit alors, et qui est la verite : B n'a JAMAIS
        // resolu de provider (`provider_effective` et `fallback_used` absents,
        // pas « differents ») et n'a franchi ni la garde economique ni l'appel
        // provider. La premiere etape divergente est donc `economic_check`,
        // le premier etage que A traverse et que B n'atteint pas.
        $this->assertSame(
            ['identity.provider_effective', 'identity.fallback_used'],
            array_column($r['pairs'][0]['identity_differences'], 'field'),
            'un tour qui s\'abstient n\'a resolu aucun provider : absent, jamais « different »',
        );
        $this->assertSame('economic_check', $r['pairs'][0]['first_divergent_step']);
        $this->assertSame('not_applicable', $this->etapeComparee($r['pairs'][0], 'grounding')['b']['status'],
            'rien a fonder : le grounding de B ne s\'applique pas');
        $this->assertNull($this->etapeComparee($r['pairs'][0], 'provider_call')['b']['status'],
            'aucun appel provider du cote qui s\'abstient');
        // #2 : dossiers vs ia -> identite + context_builder.
        $this->assertContains('identity.mode', array_column($r['pairs'][1]['identity_differences'], 'field'));
        $this->assertSame('context_builder', $r['pairs'][1]['first_divergent_step']);
        $this->assertSame(['economic_check' => 2, 'context_builder' => 1], $r['summary']['by_first_divergent_step']);
        $this->assertArrayHasKey('IDENTITY', $r['summary']['by_class']);

        // Un run plus court : la paire manquante est RAPPORTEE, pas completee.
        $runC = $this->jouerRun(['dossiers']);
        $code = Artisan::call('ai:compare-runs', ['a' => $runA, 'b' => $runC, '--organization' => $this->organization->slug, '--json' => true]);
        $r = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame(1, $r['summary']['pairs']);
        $this->assertSame([['order' => 2, 'only_in' => 'a'], ['order' => 3, 'only_in' => 'a']], $r['unpaired']);
    }

    // ────────────────────────────── s3 schemas

    public function test_s3_deux_schemas_differents_restent_comparables_sur_la_v1_et_le_disent(): void
    {
        $v2 = $this->tour(fn () => $this->dossiers('Que dit le document ?'));
        // Un tour v1 : le meme bloc, sans `run`, schema 1 — comme un tour
        // ecrit avant T1583.
        $bloc = $v2->metadata['turn'];
        $bloc['schema'] = AiTurnTrace::SCHEMA_VERSION_FROZEN_V1;
        $bloc['id'] = (string) Str::uuid();
        unset($bloc['run']);
        $v1 = AiInteraction::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->organization->id, 'correlation_id' => (string) Str::uuid(),
            'process' => $v2->process, 'feature' => $v2->feature, 'model' => $v2->model, 'prompt' => 'p', 'response' => 'r',
            'input_tokens' => 1, 'output_tokens' => 1,
            'metadata' => array_replace($v2->metadata, ['turn' => $bloc]),
        ]);

        $c = $this->comparer((string) $v2->id, (string) $v1->id);

        $this->assertTrue($c['comparable']);
        $this->assertSame(2, $c['a']['turn_schema']);
        $this->assertSame(1, $c['b']['turn_schema']);
        $this->assertStringContainsString('schemas differents (2 vs 1)', $c['schema_note']);
        $this->assertNull($c['first_divergent_step']);
        $this->assertSame([], $c['identity_differences']);
        // Le lien `run` n'est pas compare : aucune divergence `run.*`.
        $this->assertSame([], array_filter($c['divergences'], static fn (array $d): bool => str_starts_with($d['field'], 'run.')));
        // Schema INCONNU (sabotage) : le bloc est present, la comparaison le
        // dit, elle n'invente pas une v3.
        $bloc['schema'] = 99;
        $v99 = AiInteraction::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->organization->id, 'correlation_id' => (string) Str::uuid(),
            'process' => $v2->process, 'feature' => $v2->feature, 'model' => $v2->model, 'prompt' => 'p', 'response' => 'r',
            'input_tokens' => 1, 'output_tokens' => 1, 'metadata' => array_replace($v2->metadata, ['turn' => $bloc]),
        ]);
        $this->assertStringContainsString('(2 vs 99)', $this->comparer((string) $v2->id, (string) $v99->id)['schema_note']);
    }

    // ────────────────────────────── tenant & cles

    public function test_t1_les_deux_tours_sont_resolus_dans_la_meme_organization(): void
    {
        $ici = $this->tour(fn () => $this->dossiers('Que dit le document ?'));
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1584']);
        $etranger = User::factory()->create(['organization_id' => $ailleurs->id]);
        $tourEtranger = AiInteraction::create([
            'user_id' => $etranger->id, 'organization_id' => $ailleurs->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'knowledge.answer', 'feature' => 'loop_knowledge_answer', 'model' => 'x', 'prompt' => 'p', 'response' => 'r',
            'input_tokens' => 1, 'output_tokens' => 1, 'metadata' => $ici->metadata,
        ]);

        // Un tour d'ailleurs : introuvable ICI, sans dire qu'il existe.
        $this->artisan('ai:compare-turns', ['a' => (string) $ici->id, 'b' => (string) $tourEtranger->id, '--organization' => $this->organization->slug, '--json' => true])
            ->expectsOutputToContain('Aucun tour persiste ne correspond a la cle b')->assertExitCode(1);
        $this->artisan('ai:compare-turns', ['a' => (string) $tourEtranger->id, 'b' => (string) $ici->id, '--organization' => $this->organization->slug, '--json' => true])
            ->expectsOutputToContain('la cle a')->assertExitCode(1);
        // Non-uuid : refuse proprement, jamais une QueryException.
        $this->artisan('ai:compare-turns', ['a' => 'pas-un-uuid', 'b' => (string) $ici->id, '--organization' => $this->organization->slug])->assertExitCode(1);
        $this->artisan('ai:compare-runs', ['a' => 'x', 'b' => (string) Str::uuid(), '--organization' => $this->organization->slug])->assertExitCode(1);
        // Organization inconnue.
        $this->artisan('ai:compare-turns', ['a' => (string) $ici->id, 'b' => (string) $ici->id, '--organization' => 'nulle-part'])->assertExitCode(1);
        // Un run d'une autre Organization.
        $runId = $this->runId();
        AiRunManifest::start($runId, AiTurnTrace::RUN_KIND_CLI, (string) $ailleurs->id);
        $this->artisan('ai:compare-runs', ['a' => $runId, 'b' => $runId, '--organization' => $this->organization->slug, '--json' => true])
            ->expectsOutputToContain('Aucun manifeste pour le run a')->assertExitCode(1);
    }

    public function test_t2_un_message_de_loop_ou_une_ligne_shell_sont_des_cles_valides(): void
    {
        $trigger = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Capitale ?', 'type' => 'user']);
        AiTurnLock::forgetRequestState();
        $bulle = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Capitale ?', $trigger);
        $this->assertInstanceOf(LoopMessage::class, $bulle);
        $ligne = $this->shell(AiShellPageContext::KIND_DOSSIER, (string) $this->dossier->id, 'Que dit ce dossier ?');

        // Cle = la bulle IA (LoopMessage) ; cle = la ligne Shell ; les deux
        // resolvent leur interaction et se comparent.
        $c = $this->comparer((string) $bulle->id, (string) $ligne->id);
        $this->assertTrue($c['comparable']);
        $this->assertSame('loop_message', $c['a']['key_kind']);
        $this->assertSame('shell_message', $c['b']['key_kind']);
        $this->assertSame((string) $bulle->metadata['ai_interaction_id'], $c['a']['ai_interaction_id']);
        $this->assertSame(['a' => AiExecutionPath::LOOP_CHAT_IA, 'b' => AiExecutionPath::AI_SHELL_DOSSIER], $this->div($c, 'identity.execution_path'));
        // La bulle UTILISATEUR (sans interaction) n'est pas un tour.
        $this->artisan('ai:compare-turns', ['a' => (string) $trigger->id, 'b' => (string) $ligne->id, '--organization' => $this->organization->slug, '--json' => true])
            ->expectsOutputToContain('la cle a')->assertExitCode(1);
    }

    // ────────────────────────────── fixtures

    private function tour(callable $jouer): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        $jouer();

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    private function dossiers(string $question): void
    {
        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, $question, null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
    }

    private function ia(string $question): void
    {
        $trigger = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => $question, 'type' => 'user']);
        app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, $question, $trigger, publish: false);
    }

    private function shell(string $kind, ?string $objectId, string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve($this->membre, $this->organization, $kind, $objectId, 'organization.dossiers.show');
        $this->actingAs($this->membre);
        AiTurnLock::forgetRequestState();

        return app(AiShellResponder::class)->respond($this->organization, $this->membre, $question, $context)['answer'];
    }

    /** @param  list<string>  $modes */
    private function jouerRun(array $modes): string
    {
        $runId = $this->runId();
        foreach ($modes as $mode) {
            $options = [
                '--organization' => $this->organization->slug, '--user' => $this->membre->email, '--loop' => (string) $this->loop->id,
                '--mode' => $mode, '--question' => 'Que dit le document ?', '--run-id' => $runId, '--json' => true,
            ];
            if ($mode === 'ia') {
                $options['--trigger-message'] = (string) LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Que dit le document ?', 'type' => 'user'])->id;
            }
            $code = Artisan::call('ai:inspect-turn', $options);
            $this->assertSame(0, $code, Artisan::output());
            AiTurnLock::forgetRequestState();
        }

        return $runId;
    }

    private function runId(): string
    {
        $id = (string) Str::uuid();
        $this->runs[] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function comparer(string $a, string $b): array
    {
        $code = Artisan::call('ai:compare-turns', ['a' => $a, 'b' => $b, '--organization' => $this->organization->slug, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, $sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function inspecter(AiInteraction $interaction): array
    {
        return AiTurnInspection::fromPersistedTurn($interaction->fresh());
    }

    /** @return array<string, mixed> */
    private function divergence(array $c, string $champ): array
    {
        foreach ($c['divergences'] as $d) {
            if ($d['field'] === $champ) {
                return $d;
            }
        }
        $this->fail("divergence {$champ} absente : ".json_encode(array_column($c['divergences'], 'field')));
    }

    /** @return array{a: mixed, b: mixed} */
    private function div(array $c, string $champ): array
    {
        $d = $this->divergence($c, $champ);

        return ['a' => $d['a'], 'b' => $d['b']];
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }

    /** @return list<string> */
    private function fichiersAppContenant(string $aiguille): array
    {
        $trouves = [];
        $racine = base_path('app');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), $aiguille)) {
                $trouves[] = 'app/'.str_replace($racine.'/', '', $f->getPathname());
            }
        }
        sort($trouves);

        return $trouves;
    }
}
