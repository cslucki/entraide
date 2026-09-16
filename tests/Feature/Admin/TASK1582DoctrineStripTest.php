<?php

namespace Tests\Feature\Admin;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Models\AiConfig;
use App\Models\AiCreditSettingChange;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiRerankSettings;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiTruthLabel;
use App\Support\Ai\AiTurnDoctrineProjection;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1582 — Doctrine Strip V0 : « Regles qui ont gouverne ce tour ».
 *
 * Deux regles observables (max_distance, autorite rerank). OBSERVE SUR CE
 * TOUR (MEASURED) et CONFIGURATION ACTUELLE (CURRENT) toujours distincts ;
 * historique de config UNAVAILABLE ; audit rerank borne a l'Organization du
 * tour ; aucune execution au chargement ; prompt jamais rendu.
 */
#[Group('ai')]
class TASK1582DoctrineStripTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $admin;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1582', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true, 'name' => 'Admin Plateforme']);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1582',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle gouvernee');
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id, 'owner_id' => $this->membre->id,
            'name' => 'Dossier gouverne', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.knowledge.max_distance' => 0.6,
            'ai.knowledge.rerank.enabled' => false,
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

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse('Le document dit ceci [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. max_distance

    public function test_a1_max_distance_mesure_sur_le_tour_et_identique_aujourd_hui(): void
    {
        $interaction = $this->tourRag();
        $d = $this->doctrine($interaction);

        $this->assertSame(0.6, $d['max_distance']['measured']);
        $this->assertSame(AiTruthLabel::MEASURED, $d['max_distance']['measured_label']);
        $this->assertSame('turn', $d['max_distance']['measured_scope']);
        $this->assertSame(0.6, $d['max_distance']['current']);
        $this->assertSame(AiTruthLabel::DECLARED, $d['max_distance']['current_label']);
        $this->assertSame('current', $d['max_distance']['current_scope']);
        $this->assertFalse($d['max_distance']['differs']);
        $this->assertNull($d['max_distance']['history']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $d['max_distance']['history_label']);

        $page = $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction));
        $page->assertOk()->assertSee('data-inspector-doctrine', false)->assertSee('Historique')->assertDontSee('data-inspector-doctrine-warning', false);
    }

    public function test_a2_max_distance_absent_du_tour_est_unavailable_et_l_on_ne_compare_pas(): void
    {
        $interaction = $this->tourRag();
        $metadata = $interaction->metadata;
        unset($metadata['retrieval_trace']);
        $interaction->update(['metadata' => $metadata]);

        $d = $this->doctrine($interaction->fresh());

        $this->assertNull($d['max_distance']['measured']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $d['max_distance']['measured_label']);
        $this->assertSame('no_retrieval_trace', $d['max_distance']['measured_reason']);
        $this->assertNull($d['max_distance']['differs'], 'aucune comparaison a du vide');
        $this->assertSame(0.6, $d['max_distance']['current'], 'la configuration d\'aujourd\'hui reste lisible');
    }

    public function test_a3_la_configuration_actuelle_differente_est_dite_explicitement(): void
    {
        $interaction = $this->tourRag();
        config(['ai.knowledge.max_distance' => 0.45]);

        $d = $this->doctrine($interaction);
        $this->assertSame(0.6, $d['max_distance']['measured']);
        $this->assertSame(0.45, $d['max_distance']['current']);
        $this->assertTrue($d['max_distance']['differs']);

        $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction))
            ->assertOk()
            ->assertSee('data-inspector-doctrine-warning', false)
            ->assertSee('La configuration actuelle diffère de celle mesurée pour ce tour.', false);
    }

    // ────────────────────────────── B. rerank

    public function test_b1_rerank_non_tente_observe_avec_sa_raison_et_autorite_off_off(): void
    {
        $interaction = $this->tourRag();
        $d = $this->doctrine($interaction)['rerank'];

        $this->assertFalse($d['observed']['attempted']);
        $this->assertSame(AiTruthLabel::MEASURED, $d['observed']['attempted_label']);
        $this->assertNotNull($d['observed']['reason_not_attempted']);
        $this->assertSame(AiTruthLabel::MEASURED, $d['observed']['reason_not_attempted_label']);
        $this->assertNull($d['observed']['provider']);
        $this->assertSame(AiTruthLabel::MEASURED, $d['observed']['provider_label'], 'null ecrit par la source = mesure');

        $this->assertFalse($d['current']['platform_enabled']);
        $this->assertFalse($d['current']['organization_enabled']);
        $this->assertTrue($d['current']['can_be_enabled_for_organization']);
        $this->assertFalse($d['current']['effective']);
        $this->assertSame('current', $d['current']['scope']);
        $this->assertSame(AiTurnDoctrineProjection::RERANK_RULE, $d['rule']['expression']);
        $this->assertSame(AiTruthLabel::DECLARED, $d['rule']['label']);
    }

    public function test_b2_rerank_tente_sur_le_tour_reste_mesure_meme_si_l_autorite_est_off_aujourd_hui(): void
    {
        // Un ancien tour dont la trace dit « rerank tente et reussi » ; la
        // configuration d'aujourd'hui est OFF : les deux sont rendus, aucun
        // ne pretend etre l'autre.
        $interaction = $this->tourRag();
        $metadata = $interaction->metadata;
        $metadata['retrieval_trace']['dossier_retrieval']['rerank_attempted'] = true;
        $metadata['retrieval_trace']['dossier_retrieval']['rerank_succeeded'] = true;
        $metadata['retrieval_trace']['dossier_retrieval']['reason_not_attempted'] = null;
        $metadata['retrieval_trace']['dossier_retrieval']['rerank_provider'] = 'cohere';
        $metadata['retrieval_trace']['dossier_retrieval']['rerank_model'] = 'rerank-v3.5';
        $metadata['retrieval_trace']['dossier_retrieval']['rerank_duration_ms'] = 42;
        $interaction->update(['metadata' => $metadata]);

        $d = $this->doctrine($interaction->fresh())['rerank'];
        $this->assertTrue($d['observed']['attempted']);
        $this->assertSame('cohere', $d['observed']['provider']);
        $this->assertSame('rerank-v3.5', $d['observed']['model']);
        $this->assertSame(42, $d['observed']['duration_ms']);
        $this->assertFalse($d['current']['effective']);
        $this->assertStringContainsString('ne prouvent pas', $d['current']['caveat']);

        $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction))
            ->assertOk()->assertSee('cohere')->assertSee('rerank-v3.5')->assertSee('OFF')->assertSee('PLATFORM_ENABLED AND ORGANIZATION_ENABLED');
    }

    public function test_b3_autorite_on_on_et_regle_and_avec_chaque_combinaison(): void
    {
        $interaction = $this->tourRag();
        $settings = app(AiRerankSettings::class);

        // ON / ON
        $settings->updatePlatform(true, $this->admin);
        $settings->updateOrganization($this->organization, true, $this->admin);
        $d = $this->doctrine($interaction)['rerank'];
        $this->assertTrue($d['current']['platform_enabled']);
        $this->assertTrue($d['current']['organization_enabled']);
        $this->assertTrue($d['current']['effective']);

        // ON / OFF -> regle AND : OFF
        $settings->updateOrganization($this->organization, false, $this->admin);
        $d = $this->doctrine($interaction)['rerank'];
        $this->assertTrue($d['current']['platform_enabled']);
        $this->assertFalse($d['current']['organization_enabled']);
        $this->assertFalse($d['current']['effective']);

        // OFF / ON -> OFF
        $settings->updatePlatform(false, $this->admin);
        $settings->updateOrganization($this->organization, true, $this->admin);
        $d = $this->doctrine($interaction)['rerank'];
        $this->assertFalse($d['current']['platform_enabled']);
        $this->assertTrue($d['current']['organization_enabled']);
        $this->assertFalse($d['current']['effective']);

        $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction))->assertOk()->assertSee('→ OFF', false);
    }

    public function test_b4_la_derniere_modification_connue_est_rendue_telle_quelle_et_absente_sinon(): void
    {
        $interaction = $this->tourRag();

        // Aucune ligne d'audit : UNAVAILABLE, pas « jamais modifie ».
        $d = $this->doctrine($interaction)['rerank']['last_change'];
        $this->assertFalse($d['platform']['available']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $d['platform']['label']);
        $this->assertSame('no_audit_row', $d['organization']['reason']);

        app(AiRerankSettings::class)->updateOrganization($this->organization, true, $this->admin);
        $d = $this->doctrine($interaction)['rerank']['last_change'];
        $this->assertTrue($d['organization']['available']);
        $this->assertFalse($d['organization']['from']);
        $this->assertTrue($d['organization']['to']);
        $this->assertSame('Admin Plateforme', $d['organization']['changed_by']);
        $this->assertNotNull($d['organization']['created_at']);
        $this->assertSame(AiTruthLabel::MEASURED, $d['organization']['label']);
        $this->assertFalse($d['platform']['available'], 'la plateforme n\'a pas bouge');

        $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction))
            ->assertOk()
            ->assertSee('Dernière modification connue de cette configuration', false)
            ->assertSee('false → true', false)
            ->assertSee('Admin Plateforme')
            ->assertDontSee('a causé ce tour', false);
    }

    public function test_b5_l_audit_d_une_autre_organization_n_est_jamais_affiche(): void
    {
        $interaction = $this->tourRag();
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1582']);
        OrganizationAiSetting::factory()->create(['organization_id' => $ailleurs->id, 'provider' => 'openrouter', 'model' => 'm', 'api_key' => 'k']);
        $auteurAilleurs = User::factory()->create(['organization_id' => $ailleurs->id, 'is_admin' => true, 'name' => 'AUTEUR ETRANGER']);
        app(AiRerankSettings::class)->updateOrganization($ailleurs, true, $auteurAilleurs);
        $this->assertSame(1, AiCreditSettingChange::query()->count());

        $d = $this->doctrine($interaction)['rerank'];
        $this->assertFalse($d['last_change']['organization']['available'], 'l\'audit d\'ailleurs ne compte pas ici');
        $this->assertFalse($d['current']['organization_enabled'], 'l\'autorite lue est celle de l\'Organization du TOUR');

        $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction))->assertOk()->assertDontSee('AUTEUR ETRANGER');
    }

    public function test_b6_l_organization_est_celle_du_tour_jamais_celle_de_l_admin_qui_regarde(): void
    {
        $interaction = $this->tourRag();
        // L'Organization du tour : rerank OFF. L'admin qui regarde vient d'une
        // Organization ou le rerank est ON et audite : rien de cela ne doit
        // apparaitre sur la page du tour.
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1582-b']);
        OrganizationAiSetting::factory()->create(['organization_id' => $ailleurs->id, 'provider' => 'openrouter', 'model' => 'm', 'api_key' => 'k']);
        $adminAilleurs = User::factory()->create(['organization_id' => $ailleurs->id, 'is_admin' => true, 'name' => 'ADMIN D AILLEURS']);
        app(AiRerankSettings::class)->updateOrganization($ailleurs, true, $adminAilleurs);

        $page = $this->actingAs($adminAilleurs)->get(route('admin.ai-turns.show', $interaction));

        // Le nom de l'admin apparait dans la barre laterale (session) : c'est la
        // LIGNE D'AUDIT qui ne doit pas apparaitre.
        $page->assertOk()->assertSee('org-1582')->assertDontSee('→ true · ADMIN D AILLEURS', false)->assertSee('no_audit_row');
        $this->assertStringContainsString('Organization aujourd\'hui</dt><dd class="font-mono">OFF', preg_replace('/\s+/', ' ', $page->getContent()) ?? '');
    }

    // ────────────────────────────── C. robustesse

    public function test_c1_la_page_shell_sans_retrieval_ne_casse_pas_et_dit_unavailable(): void
    {
        $context = app(AiShellPageContext::class)->resolve($this->membre, $this->organization, AiShellPageContext::KIND_DASHBOARD, null, 'organization.dashboard');
        $this->actingAs($this->membre);
        $ligne = app(AiShellResponder::class)->respond($this->organization, $this->membre, "C'est quoi BouclePro ?", $context)['answer'];
        $this->assertInstanceOf(AiShellMessage::class, $ligne);

        $page = $this->actingAs($this->admin)->get(route('admin.ai-turns.shell', $ligne));
        $page->assertOk()->assertSee('data-inspector-doctrine', false)->assertSee('no_retrieval_trace')->assertSee('0.6');
    }

    public function test_c2_le_chargement_n_execute_rien_et_ne_rend_jamais_le_prompt(): void
    {
        $interaction = $this->tourRag();
        $interactions = AiInteraction::query()->count();
        $ledger = AiProviderInvocation::query()->count();

        $page = $this->actingAs($this->admin)->get(route('admin.ai-turns.show', $interaction));

        $page->assertOk();
        $this->assertSame($interactions, AiInteraction::query()->count());
        $this->assertSame($ledger, AiProviderInvocation::query()->count());
        $this->assertStringNotContainsString(Str::limit((string) $interaction->prompt, 80, ''), $page->getContent());
    }

    // ────────────────────────────── fixtures

    private function tourRag(): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    /** @return array<string, mixed> */
    private function doctrine(AiInteraction $interaction): array
    {
        return AiTurnDoctrineProjection::project(AiTurnInspection::fromPersistedTurn($interaction), $this->organization, app(AiRerankSettings::class));
    }
}
