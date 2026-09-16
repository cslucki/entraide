<?php

namespace Tests\Feature\Admin;

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
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiRunManifest;
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
use Tests\TestCase;

/**
 * TASK-1588 — hotfix du flux « Tester une requete » (SENSITIVE).
 *
 * BUG 1 : un manifeste de run inaccessible en ecriture donnait une page 500
 * (ErrorException brute) APRES avoir... rien — mais sans le dire. Desormais :
 * `AiRunManifest::start()` leve une RuntimeException explicite, le controleur
 * revient au formulaire avec le message, la CLI refuse proprement, et AUCUN
 * provider / ledger / interaction n'est touche (le run s'ouvre AVANT toute
 * execution). Les repertoires/fichiers crees portent 0770/0660 (groupe du
 * serveur web, comme `dossier_files`).
 *
 * BUG 2 : les selecteurs Organization / utilisateur / Boucle soumettent le GET
 * au changement (amelioration progressive) ; les regles restent serveur ; une
 * selection de l'ancienne Organization ne survit pas au changement.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1588InspectorTestFlowHotfixTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $admin;

    private Loop $loop;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        // Un storage/ prive au test : on peut y rendre `ai-lab/runs` inaccessible
        // sans toucher au storage du depot.
        $this->storage = sys_get_temp_dir().'/t1588-'.Str::uuid();
        File::ensureDirectoryExists($this->storage.'/app');
        File::ensureDirectoryExists($this->storage.'/framework/views');
        app()->useStoragePath($this->storage);

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1588', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id, 'email' => 'maya-1588@example.test']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1588']);
        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle 1588');
        $dossier = Dossier::factory()->create(['organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'D', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key', 'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [], 'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id], 'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true, 'ai.fab.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $dossier->id, 'dossier_name' => 'D', 'source_type' => 'file',
            'blog_post_id' => null, 'title' => null, 'slug' => null, 'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'chunk_index' => 0, 'content' => 'Contenu.', 'distance' => 0.2,
        ]])->byDefault();

        $this->appelsProvider = 0;
        LoopKnowledgeAgent::fake(function (): TextResponse {
            $this->appelsProvider++;

            return new TextResponse('Le document dit ceci [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
        });
        Http::preventStrayRequests();
    }

    private int $appelsProvider = 0;

    protected function tearDown(): void
    {
        // Remettre les droits avant de supprimer le storage prive.
        foreach ([$this->storage.'/app/ai-lab/runs', $this->storage.'/app/ai-lab', $this->storage.'/app'] as $d) {
            if (is_dir($d)) {
                @chmod($d, 0775);
            }
        }
        File::deleteDirectory($this->storage);
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── BUG 1 — manifeste fail-safe

    public function test_a1_repertoire_inscriptible_run_normal_et_modes_de_groupe(): void
    {
        $this->assertDirectoryDoesNotExist($this->storage.'/app/ai-lab');

        $reponse = $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge());

        $interaction = AiInteraction::query()->sole();
        $reponse->assertRedirect(route('admin.ai-turns.show', ['interaction' => (string) $interaction->id]));
        $this->assertSame(1, $this->appelsProvider);
        $runId = $interaction->metadata['turn']['run']['id'];
        $this->assertFileExists(AiRunManifest::path($runId));
        $this->assertStringStartsWith($this->storage, AiRunManifest::path($runId), 'le manifeste vit sous le storage courant');
        // Les repertoires crees portent le mode groupe (0770) et le fichier 0660 :
        // CLI (operateur) et HTTP (serveur web) ecrivent le MEME manifeste.
        $this->assertSame('0770', substr(sprintf('%o', fileperms($this->storage.'/app/ai-lab/runs')), -4));
        $this->assertSame('0770', substr(sprintf('%o', fileperms($this->storage.'/app/ai-lab')), -4));
        $this->assertSame('0660', substr(sprintf('%o', fileperms(AiRunManifest::path($runId))), -4));
    }

    public function test_a2_manifeste_inaccessible_refus_propre_zero_provider_zero_ledger_zero_interaction(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root ecrit partout : le refus de permission ne peut pas etre simule.');
        }
        File::ensureDirectoryExists($this->storage.'/app/ai-lab/runs');
        chmod($this->storage.'/app/ai-lab/runs', 0550);

        // HTTP : retour au formulaire avec le message — jamais une 500.
        $reponse = $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge());
        $reponse->assertRedirect()->assertSessionHas('inspector_test_error', fn (string $m): bool => str_contains($m, 'Manifeste de run inaccessible en ecriture'));
        $this->assertSame(0, $this->appelsProvider, 'AUCUN provider : le run s\'ouvre avant toute execution');
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertNull(AiTurnTrace::currentRun(), 'aucun contexte de run laisse ouvert');

        // CLI : refus propre, exit 1, meme message, meme zero.
        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email, '--loop' => (string) $this->loop->id,
            '--mode' => 'dossiers', '--question' => 'Que dit le document ?', '--json' => true,
        ])->expectsOutputToContain('Manifeste de run inaccessible en ecriture')->assertExitCode(1);
        $this->assertSame(0, $this->appelsProvider);
        $this->assertSame(0, AiInteraction::query()->count());

        // L'API elle-meme : RuntimeException explicite, pas une ErrorException.
        try {
            AiRunManifest::start((string) Str::uuid(), AiTurnTrace::RUN_KIND_CLI, (string) $this->organization->id);
            $this->fail('RuntimeException attendue');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inaccessible en ecriture', $e->getMessage());
        }

        // Droits retablis : tout repart, sans rien de residuel.
        chmod($this->storage.'/app/ai-lab/runs', 0770);
        $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge())->assertRedirect();
        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, $this->appelsProvider);
    }

    public function test_a3_un_manifeste_existant_non_inscriptible_est_refuse_avant_execution(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root ecrit partout.');
        }
        $runId = (string) Str::uuid();
        AiRunManifest::start($runId, AiTurnTrace::RUN_KIND_CLI, (string) $this->organization->id);
        chmod(AiRunManifest::path($runId), 0440);

        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email, '--loop' => (string) $this->loop->id,
            '--mode' => 'dossiers', '--question' => 'Que dit le document ?', '--run-id' => $runId, '--json' => true,
        ])->assertExitCode(1);
        // `start()` relit un manifeste existant sans l'ecrire ; c'est `addTurn`
        // qui ecrirait — APRES le tour. Le refus doit donc venir AVANT : le run
        // existant est verifie inscriptible a l'ouverture.
        $this->assertSame(0, $this->appelsProvider, 'aucun provider sur un run qu\'on ne pourra pas completer');
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_a4_un_manifeste_illisible_est_dit_en_clair_et_un_echec_d_inscription_apres_le_tour_est_porte(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root lit partout.');
        }
        // F1 : manifeste present mais illisible -> RuntimeException explicite, jamais une ErrorException.
        $runId = (string) Str::uuid();
        AiRunManifest::start($runId, AiTurnTrace::RUN_KIND_CLI, (string) $this->organization->id);
        chmod(AiRunManifest::path($runId), 0200);
        try {
            AiRunManifest::load($runId);
            $this->fail('RuntimeException attendue');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inaccessible en lecture', $e->getMessage());
        }
        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email, '--loop' => (string) $this->loop->id,
            '--mode' => 'dossiers', '--question' => 'Q ?', '--run-id' => $runId, '--json' => true,
        ])->expectsOutputToContain('inaccessible en lecture')->assertExitCode(1);
        $this->assertSame(0, $this->appelsProvider);
        chmod(AiRunManifest::path($runId), 0660);

        // F2 : le manifeste devient inecrivable PENDANT le tour (apres start()) :
        // le tour est execute et facture, la redirection va a SA fiche, et
        // l'echec d'inscription est DIT — jamais « rien n'est parti ».
        $runs = $this->storage.'/app/ai-lab/runs';
        LoopKnowledgeAgent::fake(function () use ($runs): TextResponse {
            $this->appelsProvider++;
            chmod($runs, 0550);

            return new TextResponse('Le document dit ceci [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
        });
        $reponse = $this->actingAs($this->admin)->post(route('admin.ai-turns.test.run'), $this->charge());
        chmod($runs, 0770);
        $interaction = AiInteraction::query()->sole();
        $reponse->assertRedirect(route('admin.ai-turns.show', ['interaction' => (string) $interaction->id]))
            ->assertSessionHas('inspector_test', fn (array $t): bool => $t['refused'] === false && str_contains((string) $t['manifest_failure'], 'inaccessible en ecriture'));
        $this->followRedirects($reponse)->assertOk()->assertSee('NON inscrit au manifeste');
        $this->assertSame(1, $this->appelsProvider);
    }

    // ────────────────────────────── BUG 2 — selecteurs

    public function test_b1_les_selecteurs_soumettent_le_get_et_l_ancienne_organization_ne_survit_pas(): void
    {
        $orgB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-b-1588', 'loops_enabled' => true, 'members_can_create_loops' => true]);
        $userB = User::factory()->complete()->create(['organization_id' => $orgB->id, 'email' => 'user-b-1588@example.test']);
        $loopB = (new LoopService)->createLoop($userB, 'Boucle B 1588');
        LoopMessage::create(['loop_id' => $loopB->id, 'organization_id' => $orgB->id, 'sender_id' => $userB->id, 'body' => 'Question B ?', 'type' => 'user']);
        $this->actingAs($this->admin);

        // Les trois selecteurs soumettent au changement ; le bouton reste (no-JS).
        $page = $this->get(route('admin.ai-turns.test'))->assertOk();
        $this->assertSame(3, preg_match_all('/<select name="(organization|user|loop)" onchange="this\.form\.submit\(\)"/', $page->getContent()));
        $page->assertSee('data-inspector-test-refresh', false);

        // Organization A + Maya selectionnes, puis changement vers B en gardant
        // les anciens `user`/`loop` dans la query (ce que le navigateur envoie) :
        // RIEN de A n'est resolu ni selectionne ; les utilisateurs de B apparaissent.
        $page = $this->get(route('admin.ai-turns.test', ['organization' => (string) $orgB->id, 'user' => (string) $this->membre->id, 'loop' => (string) $this->loop->id]))->assertOk();
        $page->assertSee('user-b-1588@example.test')->assertDontSee('maya-1588@example.test')->assertDontSee('Boucle 1588');
        $this->assertDoesNotMatchRegularExpression('/<option value="'.preg_quote((string) $this->membre->id, '/').'"[^>]*selected/', $page->getContent());
        $page->assertSee('choisir un utilisateur d\'abord');
        // Puis l'utilisateur B -> ses Boucles ; puis la Boucle -> ses declencheurs.
        $page = $this->get(route('admin.ai-turns.test', ['organization' => (string) $orgB->id, 'user' => (string) $userB->id]))->assertOk();
        $page->assertSee('Boucle B 1588');
        $page = $this->get(route('admin.ai-turns.test', ['organization' => (string) $orgB->id, 'user' => (string) $userB->id, 'loop' => (string) $loopB->id]))->assertOk();
        $this->assertMatchesRegularExpression('/<select name="trigger"(?![^>]*disabled)/', $page->getContent(), 'declencheurs actifs');
        $page->assertDontSee('Question B ?', false);
    }

    /** @return array<string, string> */
    private function charge(): array
    {
        return ['organization' => (string) $this->organization->id, 'user' => (string) $this->membre->id, 'loop' => (string) $this->loop->id, 'mode' => 'dossiers', 'question' => 'Que dit le document ?'];
    }
}
