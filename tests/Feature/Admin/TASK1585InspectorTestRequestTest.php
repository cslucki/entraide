<?php

namespace Tests\Feature\Admin;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiRunManifest;
use App\Support\Ai\AiTurnExecutor;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Ai\RecordsAiConsumption;
use Tests\TestCase;

/**
 * TASK-1585 — Inspector « Tester une requete » (SENSITIVE : une entree web
 * capable d'appeler un provider).
 *
 * A acces SuperAdmin seul ; B tenant org/user/loop/trigger ; C modes reels
 * (dossiers, ia) + redirect vers le bon tour + run `inspector` ; D economie :
 * vrai ledger, cout conserve, refus AVANT provider inspecte quand meme ;
 * E non-publication ; F aucun effet au simple GET ; G nit « (aucun) ».
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1585InspectorTestRequestTest extends TestCase
{
    use RecordsAiConsumption;
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $admin;

    private Loop $loop;

    private Dossier $dossier;

    /** @var list<string> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1585', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1585']);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle testee');
        $this->dossier = Dossier::factory()->create(['organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'Dossier teste', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key', 'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [], 'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id], 'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true, 'ai.chatloop.min_summary_words' => 0, 'ai.fab.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $this->dossier->id, 'dossier_name' => $this->dossier->name, 'source_type' => 'file',
            'blog_post_id' => null, 'title' => null, 'slug' => null, 'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => 0.2,
        ]])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse directe.'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (AiInteraction::query()->pluck('metadata')->all() as $metadata) {
            $run = is_array($metadata) ? ($metadata['turn']['run']['id'] ?? null) : null;
            if (is_string($run)) {
                File::delete(AiRunManifest::path($run));
            }
        }
        foreach ($this->runs as $run) {
            File::delete(AiRunManifest::path($run));
        }
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. acces

    public function test_a1_seul_un_admin_plateforme_voit_et_lance_le_test(): void
    {
        $this->assertContains($this->get(route('admin.ai-turns.test'))->getStatusCode(), [302, 401, 403]);
        $this->assertContains($this->post(route('admin.ai-turns.test.run'), $this->charge())->getStatusCode(), [302, 401, 403]);
        $this->assertSame(0, AiInteraction::query()->count(), 'anonyme : rien');

        $this->actingAs($this->membre)->get(route('admin.ai-turns.test'))->assertForbidden();
        $this->actingAs($this->membre)->post(route('admin.ai-turns.test.run'), $this->charge())->assertForbidden();
        $this->assertSame(0, AiInteraction::query()->count(), 'membre : rien');

        $this->actingAs($this->admin)->get(route('admin.ai-turns.test'))->assertOk()
            ->assertSee('Ce test peut appeler des fournisseurs IA et générer un coût.')
            ->assertSee('Tester une requête')->assertSee('Observer');
        // L'onglet est aussi sur la page Observer.
        $this->actingAs($this->admin)->get(route('admin.ai-turns'))->assertOk()->assertSee(route('admin.ai-turns.test'));
    }

    // ────────────────────────────── B. tenant

    public function test_b1_user_loop_et_trigger_doivent_appartenir_a_l_organization_choisie(): void
    {
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1585', 'loops_enabled' => true, 'members_can_create_loops' => true]);
        $etranger = User::factory()->complete()->create(['organization_id' => $ailleurs->id]);
        $loopEtrangere = (new LoopService)->createLoop($etranger, 'Boucle etrangere');
        OrganizationAiSetting::factory()->create(['organization_id' => $ailleurs->id, 'provider' => 'openrouter', 'model' => 'm', 'api_key' => 'k']);
        $this->actingAs($this->admin);

        // Utilisateur d'ailleurs sous l'Organization d'ici.
        $this->post(route('admin.ai-turns.test.run'), $this->charge(['user' => (string) $etranger->id]))
            ->assertRedirect()->assertSessionHas('inspector_test_error');
        // Boucle d'ailleurs.
        $this->post(route('admin.ai-turns.test.run'), $this->charge(['loop' => (string) $loopEtrangere->id]))
            ->assertRedirect()->assertSessionHas('inspector_test_error');
        // Utilisateur d'ici, non membre de la Boucle : la Boucle ne lui est pas proposee, le POST est refuse.
        $nonMembre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->assertTrue(AiTurnExecutor::accessibleLoops($this->organization, $nonMembre)->isEmpty());
        $this->post(route('admin.ai-turns.test.run'), $this->charge(['user' => (string) $nonMembre->id]))
            ->assertRedirect()->assertSessionHas('inspector_test_error');
        // Declencheur d'une autre Boucle pour le mode ia.
        $triggerEtranger = LoopMessage::create(['loop_id' => $loopEtrangere->id, 'organization_id' => $ailleurs->id, 'sender_id' => $etranger->id, 'body' => 'Q ?', 'type' => 'user']);
        $this->post(route('admin.ai-turns.test.run'), $this->charge(['mode' => 'ia', 'trigger' => (string) $triggerEtranger->id]))
            ->assertRedirect()->assertSessionHas('inspector_test_error');
        // Mode ia sans declencheur.
        $this->post(route('admin.ai-turns.test.run'), $this->charge(['mode' => 'ia']))
            ->assertRedirect()->assertSessionHas('inspector_test_error');

        $this->assertSame(0, AiInteraction::query()->count(), 'aucun refus tenant n\'a execute quoi que ce soit');
        $this->assertSame(0, AiProviderInvocation::query()->count());

        // Le formulaire ne DECOUVRE rien hors de l'Organization choisie.
        $page = $this->get(route('admin.ai-turns.test', ['organization' => (string) $this->organization->id, 'user' => (string) $this->membre->id]));
        $page->assertOk()->assertSee($this->membre->email)->assertSee('Boucle testee')
            ->assertDontSee($etranger->email)->assertDontSee('Boucle etrangere');
        // Un utilisateur d'ailleurs passe en query sous l'Organization d'ici : non resolu, aucune Boucle listee.
        $this->get(route('admin.ai-turns.test', ['organization' => (string) $this->organization->id, 'user' => (string) $etranger->id]))
            ->assertOk()->assertDontSee('Boucle etrangere')->assertDontSee('Boucle testee');
    }

    public function test_b2_l_executeur_revalide_le_tenant_lui_meme_avant_toute_execution(): void
    {
        // Defense en profondeur : meme un appelant qui aurait resolu de travers
        // (pas le controleur, qui filtre) est refuse PAR L'EXECUTEUR, avant
        // tout service, tout run, tout provider.
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1585-b2', 'loops_enabled' => true, 'members_can_create_loops' => true]);
        $etranger = User::factory()->complete()->create(['organization_id' => $ailleurs->id]);
        $loopEtrangere = (new LoopService)->createLoop($etranger, 'Boucle etrangere b2');
        $executor = app(AiTurnExecutor::class);
        $manifestes = count(File::glob(dirname(AiRunManifest::path((string) Str::uuid())).'/*.json') ?: []);

        foreach ([
            [$this->organization, $etranger, $this->loop, 'dossiers', null],
            [$this->organization, $this->membre, $loopEtrangere, 'dossiers', null],
            [$this->organization, $this->membre, $this->loop, 'ia', null],
            [$this->organization, $this->membre, $this->loop, 'ia', LoopMessage::create(['loop_id' => $loopEtrangere->id, 'organization_id' => $ailleurs->id, 'sender_id' => $etranger->id, 'body' => 'Q', 'type' => 'user'])],
            [$this->organization, $this->membre, $this->loop, 'shell', null],
        ] as [$org, $user, $loop, $mode, $trigger]) {
            try {
                $executor->execute($org, $user, $loop, $mode, 'Q ?', $trigger, null, AiTurnTrace::RUN_KIND_INSPECTOR);
                $this->fail("l'executeur devait refuser ({$mode})");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame($manifestes, count(File::glob(dirname(AiRunManifest::path((string) Str::uuid())).'/*.json') ?: []), 'aucun run ouvert par un refus d\'entree');
        $this->assertNull(AiTurnTrace::currentRun());
    }

    // ────────────────────────────── C. modes reels + redirect + run

    public function test_c1_mode_dossiers_produit_un_vrai_tour_et_redirige_vers_sa_fiche(): void
    {
        $ledger = AiProviderInvocation::query()->count();
        $reponse = $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge());

        $interaction = AiInteraction::query()->sole();
        $reponse->assertRedirect(route('admin.ai-turns.show', ['interaction' => (string) $interaction->id]))
            ->assertSessionHas('inspector_test', fn (array $t): bool => $t['refused'] === false && Str::isUuid($t['run_id']));

        $turn = $interaction->metadata['turn'];
        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $turn['identity']['execution_path'], 'le MEME chemin produit, pas un pseudo-chemin inspector');
        $this->assertSame('answered', $turn['status']);
        $this->assertSame((string) $this->membre->id, (string) $interaction->user_id, 'le tour est celui du membre choisi');
        $this->assertSame((string) $this->organization->id, (string) $interaction->organization_id);
        // Run d'UN tour, kind inspector, manifeste ecrit.
        $this->assertSame(AiTurnTrace::RUN_KIND_INSPECTOR, $turn['run']['kind']);
        $manifeste = AiRunManifest::load($turn['run']['id']);
        $this->assertSame([(string) $interaction->id], array_column($manifeste['turns'], 'interaction_id'));
        $this->assertNull(AiTurnTrace::currentRun(), 'contexte de run retire apres le tour');
        // Vrai ledger : une generation de plus, cout tel que calcule.
        $this->assertSame($ledger + 1, AiProviderInvocation::query()->where('operation', AiProviderInvocation::OPERATION_GENERATION)->count());
        // La ligne du ledger de CE tour (meme correlation_id) : Organization,
        // acteur, statut, cout — tels que le chemin produit les ecrit.
        $ligne = AiProviderInvocation::query()->where('operation', AiProviderInvocation::OPERATION_GENERATION)->where('correlation_id', $interaction->correlation_id)->sole();
        $this->assertSame((string) $this->organization->id, (string) $ligne->organization_id);
        $this->assertSame((string) $this->membre->id, (string) $ligne->user_id);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $ligne->status);
        // Cout conserve tel quel : la meme valeur des deux cotes, jamais un $0 invente.
        $this->assertSame((float) $interaction->cost_usd, (float) $ligne->provider_cost);
        $this->assertSame('known', $ligne->cost_status);
        $this->assertSame('organization', $ligne->credential_source, 'la VRAIE cle de l\'Organization, pas une cle Inspector');
        $this->assertSame((string) $this->membre->id, (string) $ligne->user_id, 'l\'acteur est le membre choisi, pas le SuperAdmin');
        // La fiche s'ouvre et dit que le tour vient du test.
        $this->followRedirects($reponse)->assertOk()->assertSee('Tour produit par le test')->assertSee($turn['run']['id']);
    }

    public function test_c2_mode_ia_exige_un_declencheur_du_fil_et_ne_le_repond_pas_publiquement(): void
    {
        $trigger = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Quelle est la capitale ?', 'type' => 'user']);
        $messages = LoopMessage::query()->count();

        $reponse = $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge(['mode' => 'ia', 'trigger' => (string) $trigger->id, 'question' => 'Quelle est la capitale ?']));

        $interaction = AiInteraction::query()->sole();
        $reponse->assertRedirect(route('admin.ai-turns.show', ['interaction' => (string) $interaction->id]));
        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA, $interaction->metadata['turn']['identity']['execution_path']);
        $this->assertSame((string) $trigger->id, $interaction->metadata['turn']['history']['input_message_id']);
        $this->assertSame($messages, LoopMessage::query()->count(), 'aucune bulle : le declencheur reste sans reponse publiee');
        // Et donc le test est rejouable : l'idempotence ne voit pas de reponse.
        $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge(['mode' => 'ia', 'trigger' => (string) $trigger->id, 'question' => 'Quelle est la capitale ?']))->assertRedirect();
        $this->assertSame(2, AiInteraction::query()->count());
        $this->assertSame($messages, LoopMessage::query()->count());
        // Le declencheur est propose dans le formulaire, tronque.
        $this->get(route('admin.ai-turns.test', ['organization' => (string) $this->organization->id, 'user' => (string) $this->membre->id, 'loop' => (string) $this->loop->id]))
            ->assertOk()->assertSee('Quelle est la capitale ?');
    }

    // ────────────────────────────── D. economie

    public function test_d1_un_refus_economique_n_appelle_aucun_provider_et_le_tour_refuse_s_inspecte(): void
    {
        // Credit utilisateur epuise (G4) : la garde refuse AVANT le provider.
        app(AiUserCreditSettings::class)->updatePlatform(['free_enabled' => true, 'monthly_uses' => 1, 'alert_percent' => 80, 'offer_subscription' => true], $this->admin);
        $this->recordAiGeneration((string) $this->organization->id, (string) $this->membre->id, 'chatloop.ask', 'chatloop_ai_ask', 0.001);
        $ledger = AiProviderInvocation::query()->count();

        $reponse = $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge());

        $refuse = AiInteraction::query()->whereNull('response')->orWhere('response', '')->latest('id')->first()
            ?? AiInteraction::query()->latest('id')->first();
        $this->assertNotNull($refuse);
        $turn = $refuse->metadata['turn'];
        $this->assertSame('refused', $turn['status']);
        $this->assertSame('economic_check', $turn['stage']);
        $this->assertSame(AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED, $turn['reason_code']);
        $this->assertSame(AiTurnTrace::RUN_KIND_INSPECTOR, $turn['run']['kind'], 'le tour refuse porte le run : c\'est par lui qu\'on l\'a retrouve');
        $this->assertSame($ledger, AiProviderInvocation::query()->count(), 'ledger : rien n\'est parti');
        // Redirige vers le tour REFUSE, et le dit.
        $reponse->assertRedirect(route('admin.ai-turns.show', ['interaction' => (string) $refuse->id]))
            ->assertSessionHas('inspector_test', fn (array $t): bool => $t['refused'] === true && $t['message'] !== null);
        $this->followRedirects($reponse)->assertOk()->assertSee('Tour refusé par le service');
        // Aucune gratuite Inspector : la MEME regle que pour le membre.
        $this->assertSame(1, AiInteraction::query()->where('metadata->turn->status', 'refused')->count());
    }

    public function test_d2_la_cle_et_le_budget_sont_ceux_de_l_organization(): void
    {
        // Budget mensuel de l'Organization atteint (G3) : refus par la vraie
        // garde, sur la vraie configuration de l'Organization — l'Inspector
        // n'a aucune cle ni budget a lui.
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->update(['monthly_budget_usd' => 0.0001]);
        $this->recordAiGeneration((string) $this->organization->id, (string) $this->membre->id, 'loop_knowledge.answer', 'loop_knowledge_answer', 0.5);
        $ledger = AiProviderInvocation::query()->count();

        $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge())->assertRedirect();

        $refuse = AiInteraction::query()->where('metadata->turn->status', 'refused')->sole();
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $refuse->metadata['turn']['reason_code']);
        $this->assertSame($ledger, AiProviderInvocation::query()->count());
    }

    // ────────────────────────────── E/F. non-publication, aucun effet au GET

    public function test_e1_rien_n_est_publie_dans_la_boucle_et_le_get_n_ecrit_rien(): void
    {
        $messages = LoopMessage::query()->count();
        $interactions = AiInteraction::query()->count();
        $ledger = AiProviderInvocation::query()->count();
        $manifestes = count(File::glob(dirname(AiRunManifest::path((string) Str::uuid())).'/*.json') ?: []);

        $this->actingAs($this->admin)
            ->get(route('admin.ai-turns.test', ['organization' => (string) $this->organization->id, 'user' => (string) $this->membre->id, 'loop' => (string) $this->loop->id, 'mode' => 'dossiers', 'question' => 'Que dit le document ?']))
            ->assertOk()->assertSee('Que dit le document ?');

        $this->assertSame($interactions, AiInteraction::query()->count(), 'GET : aucun tour');
        $this->assertSame($ledger, AiProviderInvocation::query()->count(), 'GET : aucun appel');
        $this->assertSame($messages, LoopMessage::query()->count());
        $this->assertSame($manifestes, count(File::glob(dirname(AiRunManifest::path((string) Str::uuid())).'/*.json') ?: []), 'GET : aucun run');

        $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge())->assertRedirect();
        $this->assertSame($interactions + 1, AiInteraction::query()->count());
        $this->assertSame($messages, LoopMessage::query()->count(), 'POST : le tour existe, la Boucle n\'a AUCUN message de plus');
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());
    }

    // ────────────────────────────── G. nit « (aucun) »

    public function test_g1_un_null_mesure_se_lit_aucun_et_un_null_indisponible_reste_unavailable(): void
    {
        $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge());
        $interaction = AiInteraction::query()->sole();

        $page = $this->actingAs($this->admin)->get(route('admin.ai-turns.show', ['interaction' => (string) $interaction->id]))->assertOk();
        $html = $page->getContent();
        // Verdict : `reason_code` d'un tour answered n'est pas persiste
        // (compose() ne garde pas les cles nulles) -> UNAVAILABLE, pas « (aucun) ».
        $verdict = $this->section($html, 'data-inspector-decision');
        $this->assertStringContainsString('UNAVAILABLE', $verdict);
        // Etat : `degraded_reason` = null MESURE (le tour a ecrit son etat,
        // sans degradation) -> « (aucun) », jamais « UNAVAILABLE » a cote d'un badge MEASURED.
        $etat = $this->section($html, 'data-inspector-state');
        $this->assertStringContainsString('(aucun)', $etat);
        $this->assertDoesNotMatchRegularExpression('/UNAVAILABLE<\/dd>\s*<span[^>]*>MEASURED/', $etat, 'un null MEASURED ne s\'affiche plus UNAVAILABLE');
        $this->assertDoesNotMatchRegularExpression('/UNAVAILABLE<\/dd>\s*<span[^>]*>MEASURED/', $this->section($html, 'data-inspector-identity'));
        $this->assertDoesNotMatchRegularExpression('/UNAVAILABLE<\/p>\s*<span[^>]*>MEASURED/', $verdict);
    }

    // ────────────────────────────── fixtures

    /** @return array<string, string> */
    private function charge(array $surcharge = []): array
    {
        return array_replace([
            'organization' => (string) $this->organization->id,
            'user' => (string) $this->membre->id,
            'loop' => (string) $this->loop->id,
            'mode' => 'dossiers',
            'question' => 'Que dit le document ?',
        ], $surcharge);
    }

    private function section(string $html, string $marqueur): string
    {
        $debut = strpos($html, $marqueur);
        $this->assertNotFalse($debut, "section {$marqueur} absente");
        $fin = strpos($html, 'data-inspector-', $debut + strlen($marqueur));

        return substr($html, $debut, $fin === false ? null : $fin - $debut);
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }
}
