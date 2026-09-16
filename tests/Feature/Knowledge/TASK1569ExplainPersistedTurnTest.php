<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1569 / CDC-01 V0-H0 — un tour persiste se lit sans etre rejoue.
 *
 * ## Ce que ce fichier garde
 *
 *  A. READ ONLY absolu : expliquer un tour n'appelle aucun provider, n'ecrit
 *     aucune interaction, aucune ligne ledger, aucun message ;
 *  B. les trois cles de lookup rendent la MEME ligne (`--interaction`,
 *     `--turn`, `--message`) ; le tenant est une garde, pas un filtre ;
 *  C. la lecture est PURE : ce que le tour a ecrit est rendu tel quel, ce qu'il
 *     n'a pas ecrit est `null` — un tour ancien (cle historique `turn_id`) et un
 *     tour muet (aucune trace) le prouvent ; C19 : `turn.id` d'abord, le repli
 *     est nomme ;
 *  D. le JSON est un contrat : memes sections quel que soit l'age du tour.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1569ExplainPersistedTurnTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = ['mode', 'run', 'identity', 'decision', 'steps', 'history', 'sources', 'retrieval_trace', 'state', 'output', 'provider'];

    private Organization $organization;

    private Organization $autreOrganization;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1569']);
        $this->autreOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1569']);

        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1569',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle expliquee');

        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.retrieval_trace.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $dossier->id, 'dossier_name' => $dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => 0.2,
        ]])->byDefault();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. read only

    public function test_a1_expliquer_n_appelle_aucun_provider_et_n_ecrit_rien(): void
    {
        $interaction = $this->tourReel();

        $interactions = AiInteraction::query()->count();
        $invocations = AiProviderInvocation::query()->count();
        $messages = LoopMessage::query()->count();

        // Un agent qui LEVE au moindre prompt : la commande echouerait. Son
        // succes est donc la preuve qu'aucun provider n'a ete sollicite
        // (`Http::preventStrayRequests` garde le reste du reseau). Sabotage
        // verifie : `fake([])` ne suffisait PAS — un fake sans reponse ne leve
        // pas, et un explain qui rejouait passait vert.
        LoopKnowledgeAgent::fake(function (): never {
            throw new \RuntimeException('EXPLAIN a sollicite un provider');
        });

        $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $interaction->id])
            ->assertSuccessful();

        $this->assertSame($interactions, AiInteraction::query()->count());
        $this->assertSame($invocations, AiProviderInvocation::query()->count());
        $this->assertSame($messages, LoopMessage::query()->count());
    }

    public function test_a2_explain_refuse_de_jouer_un_tour(): void
    {
        $interaction = $this->tourReel();

        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug,
            '--interaction' => (string) $interaction->id,
            '--question' => 'Et si on rejouait ?',
        ])->assertFailed();

        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug,
            '--interaction' => (string) $interaction->id,
            '--turn' => 'deux-cles',
        ])->assertFailed();
    }

    // ────────────────────────────── B. lookups et tenant

    public function test_b1_les_trois_cles_rendent_la_meme_ligne(): void
    {
        $interaction = $this->tourReel();
        $turnId = $interaction->metadata[AiTurnTrace::TURN_METADATA_KEY]['id'];
        $bulle = LoopMessage::query()->where('type', 'ai')->where('metadata->ai_interaction_id', (string) $interaction->id)->firstOrFail();

        $parInteraction = $this->expliquer(['--interaction' => (string) $interaction->id]);
        $parTurn = $this->expliquer(['--turn' => $turnId]);
        $parMessage = $this->expliquer(['--message' => (string) $bulle->id]);

        $this->assertSame((string) $interaction->id, $parInteraction['run']['ai_interaction_id']);
        $this->assertSame($parInteraction, $parTurn);
        $this->assertSame($parInteraction, $parMessage);
    }

    public function test_b2_une_ligne_d_un_autre_tenant_est_introuvable_jamais_rendue(): void
    {
        $interaction = $this->tourReel();
        $turnId = $interaction->metadata[AiTurnTrace::TURN_METADATA_KEY]['id'];
        $bulle = LoopMessage::query()->where('type', 'ai')->firstOrFail();

        foreach ([
            ['--interaction' => (string) $interaction->id],
            ['--turn' => $turnId],
            ['--message' => (string) $bulle->id],
        ] as $cle) {
            $this->artisan('ai:inspect-turn', ['--organization' => $this->autreOrganization->slug, ...$cle, '--json' => true])
                ->expectsOutputToContain('"refused": true')
                ->assertFailed();
        }
    }

    public function test_b3_un_message_humain_sans_interaction_ne_resout_rien(): void
    {
        $this->tourReel();
        $question = LoopMessage::query()->where('type', 'user')->firstOrFail();

        $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, '--message' => (string) $question->id])
            ->assertFailed();
    }

    // ────────────────────────────── C. lecture pure

    public function test_c1_un_tour_v0g_se_lit_tel_qu_il_a_ete_ecrit(): void
    {
        $interaction = $this->tourReel();
        $turn = $interaction->metadata[AiTurnTrace::TURN_METADATA_KEY];

        $trace = $this->expliquer(['--interaction' => (string) $interaction->id]);

        $this->assertSame('explain', $trace['mode']);
        $this->assertSame($turn['id'], $trace['run']['turn_id']);
        $this->assertSame('turn.id', $trace['run']['turn_id_source']);
        $this->assertSame(1, $trace['run']['turn_schema']);
        $this->assertSame(AiExecutionPath::LOOP_CONTROLLER_KNOWLEDGE_JSON, $trace['identity']['execution_path']);
        $this->assertSame($turn['identity'], $trace['identity']);
        $this->assertSame($turn['steps'], $trace['steps']);
        $this->assertSame($turn['history'], $trace['history']);
        $this->assertSame($turn['status'], $trace['decision']['status']);
        $this->assertSame($turn['decided_by'], $trace['decision']['decided_by']);
        $this->assertSame($turn['latency_ms'], $trace['decision']['latency_ms']);
        $this->assertSame($interaction->metadata['latency_ms'], $trace['provider']['latency_ms']);
        $this->assertSame('turn', $trace['state']['source']);
        $this->assertSame($turn['status'], $trace['state']['turn_status']);
        $this->assertNotNull($trace['retrieval_trace']);
        // V0-E (T1573) : les quatre familles, telles qu'ecrites — pas fabriquees.
        $this->assertSame($turn['sources'], $trace['sources']);
        $this->assertSame($interaction->response, $trace['output']['response']);
    }

    /**
     * C19. Sabotage : lire `metadata.turn_id` avant `turn.id` → c2 rougit
     * (source), et un ancien tour dont les deux divergeraient rendrait la
     * mauvaise identite.
     */
    public function test_c2_un_ancien_tour_se_lit_par_sa_cle_historique_et_le_dit(): void
    {
        $ancien = $this->ligneFabriquee([
            'turn_id' => 'ancien-turn-1565',
            'latency_ms' => 321,
            'status' => 'completed',
            'provider' => 'openrouter',
            'capability' => 'loop_knowledge_answer',
            'retrieval' => ['consulted' => ['c1', 'c2'], 'cited' => ['c1']],
        ]);

        $trace = $this->expliquer(['--turn' => 'ancien-turn-1565']);

        $this->assertSame((string) $ancien->id, $trace['run']['ai_interaction_id']);
        $this->assertSame('ancien-turn-1565', $trace['run']['turn_id']);
        $this->assertSame('metadata.turn_id', $trace['run']['turn_id_source']);
        $this->assertNull($trace['run']['turn_schema']);
        $this->assertNull($trace['identity']);
        $this->assertNull($trace['steps']);
        $this->assertNull($trace['history']);
        $this->assertNull($trace['retrieval_trace']);
        $this->assertSame(['status' => null, 'stage' => null, 'reason_code' => null, 'decided_by' => null, 'latency_ms' => null], $trace['decision']);
        $this->assertSame('legacy_metadata', $trace['state']['source']);
        // Ce qui EST ecrit, lui, se lit.
        $this->assertSame(321, $trace['provider']['latency_ms']);
        $this->assertSame(['c1', 'c2'], $trace['output']['consulted_chunk_ids']);
        $this->assertSame(['c1'], $trace['output']['cited_chunk_ids']);
    }

    public function test_c3_un_tour_muet_rend_null_partout_jamais_zero_ni_vide(): void
    {
        $muet = $this->ligneFabriquee(['status' => 'completed']);

        $trace = $this->expliquer(['--interaction' => (string) $muet->id]);

        $this->assertNull($trace['run']['turn_id']);
        $this->assertNull($trace['run']['turn_id_source']);
        $this->assertNull($trace['identity']);
        $this->assertNull($trace['steps']);
        $this->assertNull($trace['history']);
        $this->assertNull($trace['sources']);
        $this->assertNull($trace['retrieval_trace']);
        $this->assertNull($trace['output']['consulted_chunk_ids']);
        $this->assertNull($trace['provider']['latency_ms']);
        $this->assertNull($trace['provider']['embedding_sdk_invocation_ids']);

        $json = json_encode($trace, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"steps": []', $json);
        $this->assertStringNotContainsString('"steps":[]', $json);
    }

    public function test_c4_quand_turn_id_et_la_cle_historique_coexistent_le_bloc_canonique_gagne(): void
    {
        // Le pilote V0-A ecrit les DEUX (compat lecteurs). Meme valeur en
        // production ; ici on les fait diverger pour prouver l'ordre.
        $ligne = $this->ligneFabriquee([
            'turn_id' => 'historique',
            AiTurnTrace::TURN_METADATA_KEY => ['schema' => 1, 'id' => 'canonique'],
        ]);

        $trace = $this->expliquer(['--interaction' => (string) $ligne->id]);

        $this->assertSame('canonique', $trace['run']['turn_id']);
        $this->assertSame('turn.id', $trace['run']['turn_id_source']);

        // Et le lookup `--turn` suit le meme ordre : la cle canonique resout.
        $this->assertSame((string) $ligne->id, $this->expliquer(['--turn' => 'canonique'])['run']['ai_interaction_id']);
    }

    // ────────────────────────────── D. contrat JSON

    public function test_d1_les_sections_sont_les_memes_quel_que_soit_l_age_du_tour(): void
    {
        $recent = $this->expliquer(['--interaction' => (string) $this->tourReel()->id]);
        $muet = $this->expliquer(['--interaction' => (string) $this->ligneFabriquee(['status' => 'completed'])->id]);

        $this->assertSame(self::SECTIONS, array_keys($recent));
        $this->assertSame(self::SECTIONS, array_keys($muet));
        $this->assertSame(array_keys($recent['run']), array_keys($muet['run']));
        $this->assertSame(array_keys($recent['decision']), array_keys($muet['decision']));
        $this->assertSame(array_keys($recent['state']), array_keys($muet['state']));
        $this->assertSame(array_keys($recent['output']), array_keys($muet['output']));
        $this->assertSame(array_keys($recent['provider']), array_keys($muet['provider']));
    }

    public function test_d2_le_rendu_table_ne_plante_sur_aucun_des_deux(): void
    {
        $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $this->tourReel()->id])
            ->expectsOutputToContain('execution_path')
            ->assertSuccessful();

        $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $this->ligneFabriquee(['status' => 'completed'])->id])
            ->assertSuccessful();
    }

    // ────────────────────────────── harnais

    /** Un VRAI tour, par sa vraie porte (endpoint JSON), qui persiste son bloc `turn` V0-G. */
    private function tourReel(): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        AiTurnLock::forgetRequestState();
        LoopKnowledgeAgent::fake([new TextResponse('Le document dit ceci [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'))]);

        $this->actingAs($this->membre)
            ->postJson(route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]), ['question' => 'Que dit le document ?'])
            ->assertOk();

        $nouvelles = AiInteraction::query()->whereNotIn('id', $deja)->get();
        $this->assertCount(1, $nouvelles);

        return $nouvelles->first();
    }

    /**
     * Une ligne ecrite DIRECTEMENT, comme les tours anterieurs a V0-A l'ont ete.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function ligneFabriquee(array $metadata): AiInteraction
    {
        return AiInteraction::create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'knowledge.answer',
            'feature' => 'loop_knowledge_answer',
            'model' => 'openrouter/openai/gpt-4o-mini',
            'prompt' => 'prompt',
            'response' => 'reponse',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Le JSON REELLEMENT emis par la commande — pas le read model appele en
     * direct : c'est la resolution de la cle ET le rendu qui sont sous test.
     *
     * @param  array<string, string>  $cle
     * @return array<string, mixed>
     */
    private function expliquer(array $cle): array
    {
        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, ...$cle, '--json' => true]);
        $sortie = Artisan::output();

        $this->assertSame(0, $code, 'la commande a refuse : '.$sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }
}
