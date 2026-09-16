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
use App\Support\Ai\AiConversationTrace;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiShellPageContext;
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
 * TASK-1579 / CDC-02 TRACE-1A — « les tours se relient ».
 *
 * A1-A6 du CDC-02 §8, joues par de vrais tours produit et relus UNIQUEMENT par
 * `ai:inspect-conversation --json`. Sabotages du CDC : s1 (`turn.history`
 * retire → UNAVAILABLE, jamais NO), s4 (`reply_to_id` vers un message
 * disparu → chaine intacte, arret dit).
 */
#[Group('ai')]
class TASK1579ConversationTraceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1579', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1579',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle reliee');
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id, 'owner_id' => $this->membre->id,
            'name' => 'Dossier relie', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $this->dossier->id, 'dossier_name' => $this->dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => 0.2,
        ]])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse directe.'));
        ShellGeneralAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse generale.'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. les scenarios du CDC-02 §8

    public function test_a1_deux_tours_loopchat_relies_par_reply_le_second_a_vu_le_premier(): void
    {
        [$u1, $b1] = $this->tourIa('Bonjour ?', null);
        [$u2, $b2] = $this->tourIa('Et ensuite ?', $b1);

        // Depuis N'IMPORTE QUEL maillon — ici le message humain du milieu.
        $trace = $this->conversation(['--message' => (string) $u2->id]);

        $this->assertSame('reply_chain', $trace['strategy']);
        $this->assertSame([(string) $u1->id, (string) $b1->id, (string) $u2->id], array_column($trace['chain'], 'message_id'), 'la chaine est remontee depuis le maillon donne, dans l\'ordre');
        $this->assertCount(1, $trace['turns'], 'un tour par bulle IA presente dans la chaine');

        // Depuis la derniere bulle : les deux tours.
        $trace = $this->conversation(['--message' => (string) $b2->id]);
        $this->assertCount(2, $trace['turns']);
        [$t1, $t2] = $trace['turns'];

        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA, $t1['execution_path']);
        $this->assertSame('UNAVAILABLE', $t1['derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertSame('no_previous_turn', $t1['derived']['unavailable_reasons']['PREVIOUS_AI_ANSWER_VISIBLE']);

        $this->assertSame('YES', $t2['derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertSame('YES', $t2['derived']['PREVIOUS_USER_MESSAGE_VISIBLE']);
        $this->assertGreaterThanOrEqual(2, $t2['history']['count']);
        $this->assertSame((string) $b1->id, $t2['history']['trigger_id']);
        $this->assertSame((string) $u2->id, $t2['history']['input_message_id']);
        $this->assertSame('NO', $t2['derived']['MODE_CHANGED']);
        $this->assertSame('NO', $t2['derived']['EXECUTION_PATH_CHANGED']);
        $this->assertSame('NO', $t2['derived']['CONTEXT_BUILDER_CHANGED']);
        $this->assertSame('none_declared', $t2['derived']['REFERENT_RESOLUTION']);
        $this->assertNull($trace['stopped']);
    }

    public function test_a2_deux_tours_loopchat_sans_reply_ne_se_relient_pas_et_personne_ne_juge(): void
    {
        $this->tourIa('Bonjour ?', null);
        [, $b2] = $this->tourIa('Seul ?', null);

        $trace = $this->conversation(['--message' => (string) $b2->id]);

        // La chaine de b2 ne contient que u2 et b2 : un seul tour, aucun
        // precedent — `NO` n'est jamais rendu pour un precedent qui n'existe
        // pas dans la chaine.
        $this->assertCount(1, $trace['turns']);
        $tour = $trace['turns'][0];
        $this->assertSame(0, $tour['history']['count']);
        $this->assertNull($tour['history']['trigger_id']);
        $this->assertSame('answered', $tour['status']);
        $this->assertSame('UNAVAILABLE', $tour['derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertNull($trace['stopped']);
    }

    public function test_a2bis_un_reply_dont_le_tour_n_a_rien_vu_rend_no(): void
    {
        // Le produit ne lit que `user|ai` : un message `text` casse la lecture
        // de l'historique tout en restant un maillon de reply. Le tour 2 a
        // donc VRAIMENT rien vu — et la chaine, elle, le relie : NO mesure.
        $u1 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Bonjour ?', 'type' => 'user']);
        $b1 = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Bonjour ?', $u1);
        $texte = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'note', 'type' => 'text', 'reply_to_id' => $b1->id]);
        $u2 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Et ensuite ?', 'type' => 'user', 'reply_to_id' => $texte->id]);
        $b2 = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Et ensuite ?', $u2);

        $trace = $this->conversation(['--message' => (string) $b2->id]);
        $t2 = $trace['turns'][1];

        $this->assertSame(0, $t2['history']['count']);
        $this->assertSame('NO', $t2['derived']['PREVIOUS_AI_ANSWER_VISIBLE'], 'les deux termes sont mesures : la bulle precedente existe, l\'historique est vide');
    }

    public function test_a3_deux_tours_shell_meme_conversation_le_second_a_vu_le_premier(): void
    {
        $this->mock(DossierSemanticSearchService::class)->shouldReceive('searchAcrossDossiers')->andReturn([])->byDefault();
        $l1 = $this->shell('Quelle est la capitale de la France ?');
        $l2 = $this->shell('Et celle de l\'Italie ?');
        $this->assertSame((string) $l1->conversation_id, (string) $l2->conversation_id);

        $trace = $this->conversation(['--shell-conversation' => (string) $l2->conversation_id]);

        $this->assertSame('shell_thread', $trace['strategy']);
        $this->assertCount(2, $trace['turns']);
        [$t1, $t2] = $trace['turns'];
        $this->assertSame(AiExecutionPath::AI_SHELL_GENERAL, $t1['execution_path']);
        $this->assertSame('YES', $t2['derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertSame('shell_thread', $t2['history']['strategy']);
        $this->assertSame('none_declared', $t2['derived']['REFERENT_RESOLUTION'], 'aucune branche reference executee');

        // Une ligne zero-provider dans le meme fil : son tour se lit aussi.
        $l3 = $this->shell("C'est quoi BouclePro ?");
        $trace = $this->conversation(['--shell-conversation' => (string) $l3->conversation_id]);
        $this->assertSame(AiExecutionPath::AI_SHELL_SELF_KNOWLEDGE, $trace['turns'][2]['execution_path']);
        $this->assertSame('YES', $trace['turns'][2]['derived']['EXECUTION_PATH_CHANGED']);
    }

    public function test_a4_dossiers_puis_ia_en_reply_le_mode_et_le_context_builder_changent(): void
    {
        // Tour 1 : RAG en fil (publie), tour 2 : mode ia en reply a la bulle RAG.
        $u1 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Que dit le document ?', 'type' => 'user']);
        AiTurnLock::forgetRequestState();
        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', $u1, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
        $b1 = LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->latest('id')->firstOrFail();
        [, $b2] = $this->tourIa('Et en resume ?', $b1);

        $trace = $this->conversation(['--message' => (string) $b2->id]);
        [$t1, $t2] = $trace['turns'];

        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $t1['execution_path']);
        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA, $t2['execution_path']);
        $this->assertSame('YES', $t2['derived']['MODE_CHANGED']);
        $this->assertSame('YES', $t2['derived']['EXECUTION_PATH_CHANGED']);
        $this->assertSame('YES', $t2['derived']['CONTEXT_BUILDER_CHANGED']);
        $this->assertSame('YES', $t2['derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertSame('UNAVAILABLE', $t2['derived']['DOSSIER_CONTEXT_PRESERVED'], 'le mode ia n\'a aucune famille de sources : rien a intersecter');
        $this->assertSame('sources_unavailable', $t2['derived']['unavailable_reasons']['DOSSIER_CONTEXT_PRESERVED']);
    }

    public function test_a5_un_tour_pre_v0_dans_la_chaine_est_unavailable_jamais_no(): void
    {
        [$u1, $b1] = $this->tourIa('Bonjour ?', null);
        // Le tour 1 devient « historique » : son interaction n'a plus de bloc turn.
        $interaction = AiInteraction::query()->findOrFail($b1->metadata['ai_interaction_id']);
        $metadata = $interaction->metadata;
        unset($metadata['turn']);
        $interaction->update(['metadata' => $metadata]);

        [, $b2] = $this->tourIa('Et ensuite ?', $b1);
        $trace = $this->conversation(['--message' => (string) $b2->id]);
        [$t1, $t2] = $trace['turns'];

        $this->assertFalse($t1['turn_available']);
        $this->assertNull($t1['execution_path']);
        $this->assertSame('UNAVAILABLE', $t1['derived']['REFERENT_RESOLUTION']);
        // Le tour 2 est complet et a VU la bulle 1 (son historique est a lui) ;
        // mais les comparaisons avec un precedent muet sont UNAVAILABLE, jamais NO.
        $this->assertSame('YES', $t2['derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertSame('UNAVAILABLE', $t2['derived']['MODE_CHANGED']);
        $this->assertSame('UNAVAILABLE', $t2['derived']['CONTEXT_BUILDER_CHANGED']);
        $this->assertSame('turn_unavailable', $t2['derived']['unavailable_reasons']['MODE_CHANGED']);
        $this->assertCount(2, $trace['turns'], 'la chaine reste intacte');
    }

    public function test_a6_une_chaine_qui_traverse_un_autre_tenant_s_arrete_sans_fuite(): void
    {
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1579']);
        $etranger = User::factory()->create(['organization_id' => $ailleurs->id]);
        $loopEtrangere = Loop::factory()->create(['organization_id' => $ailleurs->id, 'created_by' => $etranger->id]);
        $racine = LoopMessage::create(['loop_id' => $loopEtrangere->id, 'organization_id' => $ailleurs->id, 'sender_id' => $etranger->id, 'body' => 'SECRET AILLEURS', 'type' => 'user']);

        // Fixture : un message de CE tenant qui pointe (donnee corrompue ou
        // forgee) vers un message d'ailleurs.
        $u = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Bonjour ?', 'type' => 'user', 'reply_to_id' => $racine->id]);
        $b = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Bonjour ?', $u);

        $trace = $this->conversation(['--message' => (string) $b->id]);

        $this->assertSame(AiConversationTrace::STOP_CROSS_TENANT, $trace['stopped']['reason_code']);
        $this->assertSame([(string) $u->id, (string) $b->id], array_column($trace['chain'], 'message_id'));
        $json = json_encode($trace, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $racine->id, $json, 'aucun id du maillon refuse');
        $this->assertStringNotContainsString('SECRET AILLEURS', $json);
        $this->assertTrue($trace['turns'][0]['history']['trigger_refused'], 'le produit avait ecrit l\'id etranger comme trigger_id : masque ici');
        $this->assertNull($trace['turns'][0]['history']['trigger_id']);
        $this->assertTrue($trace['chain'][0]['reply_to_refused']);

        // Et un message d'ailleurs donne comme ancre est introuvable ici.
        $this->artisan('ai:inspect-conversation', ['--organization' => $this->organization->slug, '--message' => (string) $racine->id, '--json' => true])
            ->expectsOutputToContain('"refused": true')->assertExitCode(1);
        // Une autre Boucle du MEME tenant est aussi une frontiere (J7 : Loop ≠ Tenant, mais une chaine ne traverse pas les Boucles).
        $autreLoop = Loop::factory()->create(['organization_id' => $this->organization->id, 'created_by' => $this->membre->id]);
        $x = LoopMessage::create(['loop_id' => $autreLoop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Hors boucle', 'type' => 'user']);
        $u2 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Bonjour ?', 'type' => 'user', 'reply_to_id' => $x->id]);
        $trace = $this->conversation(['--message' => (string) $u2->id]);
        $this->assertSame(AiConversationTrace::STOP_CROSS_TENANT, $trace['stopped']['reason_code']);
        $this->assertStringNotContainsString((string) $x->id, json_encode($trace, JSON_THROW_ON_ERROR));
    }

    // ────────────────────────────── sabotages du CDC (s1, s4) et bornes

    public function test_s1_un_tour_sans_history_rend_unavailable_jamais_no(): void
    {
        [$u1, $b1] = $this->tourIa('Bonjour ?', null);
        [, $b2] = $this->tourIa('Et ensuite ?', $b1);
        $interaction = AiInteraction::query()->findOrFail($b2->metadata['ai_interaction_id']);
        $metadata = $interaction->metadata;
        unset($metadata['turn']['history']);
        $interaction->update(['metadata' => $metadata]);

        $t2 = $this->conversation(['--message' => (string) $b2->id])['turns'][1];

        $this->assertNull($t2['history']);
        $this->assertSame('UNAVAILABLE', $t2['derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertSame('history_unavailable', $t2['derived']['unavailable_reasons']['PREVIOUS_AI_ANSWER_VISIBLE']);
        // Les comparaisons d'identite, elles, restent mesurables.
        $this->assertSame('NO', $t2['derived']['MODE_CHANGED']);
    }

    public function test_s4_un_reply_vers_un_message_disparu_laisse_la_chaine_intacte_et_le_dit(): void
    {
        [$u1, $b1] = $this->tourIa('Bonjour ?', null);
        [$u2, $b2] = $this->tourIa('Et ensuite ?', $b1);
        // Suppression DOUCE (celle du produit) : le maillon reste, marque.
        LoopMessage::query()->whereKey($b1->id)->update(['deleted_at' => now()]);

        $trace = $this->conversation(['--message' => (string) $b2->id]);

        $this->assertSame([(string) $u1->id, (string) $b1->id, (string) $u2->id, (string) $b2->id], array_column($trace['chain'], 'message_id'));
        $this->assertTrue($trace['chain'][1]['deleted']);
        $this->assertNull($trace['stopped']);
        $this->assertCount(2, $trace['turns'], 'la bulle supprimee garde son tour (son interaction existe)');

        // Suppression DURE : `reply_to_id` est `nullOnDelete` (FACT
        // 2026_06_14_090000) — la chaine s'arrete a la racine, sans lien
        // pendant ; `REPLY_TO_DELETED_OR_MISSING` reste la garde d'un lien
        // corrompu, jamais atteinte par le produit.
        LoopMessage::query()->whereKey($b1->id)->delete();
        $trace = $this->conversation(['--message' => (string) $b2->id]);
        $this->assertSame([(string) $u2->id, (string) $b2->id], array_column($trace['chain'], 'message_id'));
        $this->assertNull($trace['stopped']);
        $this->assertSame('UNAVAILABLE', $trace['turns'][0]['derived']['PREVIOUS_AI_ANSWER_VISIBLE'], 'precedent absent de la chaine -> UNAVAILABLE, pas NO');
    }

    public function test_b1_la_profondeur_borne_la_lecture_comme_le_produit(): void
    {
        $bulle = null;
        for ($i = 0; $i < 5; $i++) {
            [, $bulle] = $this->tourIa("Tour {$i} ?", $bulle);
        }

        $trace = $this->conversation(['--message' => (string) $bulle->id]);
        $this->assertSame(6, $trace['depth']);
        $this->assertCount(6, $trace['chain']);
        $this->assertSame(AiConversationTrace::STOP_DEPTH, $trace['stopped']['reason_code']);

        $trace = $this->conversation(['--message' => (string) $bulle->id, '--depth' => 20]);
        $this->assertCount(10, $trace['chain']);
        $this->assertCount(5, $trace['turns']);
        $this->assertNull($trace['stopped']);
    }

    public function test_b2_le_json_est_stable_et_la_commande_refuse_ce_qu_elle_ne_relie_pas(): void
    {
        [, $b1] = $this->tourIa('Bonjour ?', null);
        $trace = $this->conversation(['--message' => (string) $b1->id]);

        $this->assertSame(['mode', 'strategy', 'anchor', 'depth', 'chain', 'turns', 'stopped'], array_keys($trace));
        $this->assertSame(['message_id', 'type', 'reply_to_id', 'reply_to_refused', 'deleted'], array_keys($trace['chain'][0]));
        $this->assertSame(['position', 'message_id', 'turn_id', 'ai_interaction_id', 'execution_path', 'mode', 'status', 'reason_code', 'history', 'turn_available', 'derived'], array_keys($trace['turns'][0]));
        $this->assertSame(
            ['PREVIOUS_AI_ANSWER_VISIBLE', 'PREVIOUS_USER_MESSAGE_VISIBLE', 'MODE_CHANGED', 'EXECUTION_PATH_CHANGED', 'CONTEXT_BUILDER_CHANGED', 'DOSSIER_CONTEXT_PRESERVED', 'REFERENT_RESOLUTION', 'unavailable_reasons'],
            array_keys($trace['turns'][0]['derived']),
        );
        $this->assertSame($trace, $this->conversation(['--message' => (string) $b1->id]), 'deterministe');

        // J3 : une seule strategie par appel.
        $this->artisan('ai:inspect-conversation', ['--organization' => $this->organization->slug, '--message' => (string) $b1->id, '--shell-conversation' => (string) Str::uuid(), '--json' => true])
            ->expectsOutputToContain('"refused": true')->assertExitCode(1);
        $this->artisan('ai:inspect-conversation', ['--organization' => $this->organization->slug, '--message' => 'pas-un-uuid', '--json' => true])
            ->expectsOutputToContain('doit etre un uuid')->assertExitCode(1);
        $this->artisan('ai:inspect-conversation', ['--organization' => $this->organization->slug, '--shell-conversation' => (string) Str::uuid(), '--json' => true])
            ->expectsOutputToContain('"refused": true')->assertExitCode(1);
        $this->assertSame(0, AiInteraction::query()->where('created_at', '>', now()->addMinute())->count(), 'lecture pure');
    }

    // ────────────────────────────── fixtures

    /** @return array{0: LoopMessage, 1: LoopMessage} le message humain et la bulle IA */
    private function tourIa(string $question, ?LoopMessage $enReponseA): array
    {
        $u = LoopMessage::create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id,
            'body' => $question, 'type' => 'user', 'reply_to_id' => $enReponseA?->id,
        ]);
        AiTurnLock::forgetRequestState();
        $b = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, $question, $u);

        return [$u, $b];
    }

    private function shell(string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve($this->membre, $this->organization, AiShellPageContext::KIND_DASHBOARD, null, 'organization.dashboard');
        $this->actingAs($this->membre);
        AiTurnLock::forgetRequestState();

        return app(AiShellResponder::class)->respond($this->organization, $this->membre, $question, $context)['answer'];
    }

    /** @return array<string, mixed> */
    private function conversation(array $options): array
    {
        $code = Artisan::call('ai:inspect-conversation', ['--organization' => $this->organization->slug, ...$options, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, 'la commande a refuse : '.$sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }
}
