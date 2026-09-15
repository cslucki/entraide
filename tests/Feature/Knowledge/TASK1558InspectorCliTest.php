<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiTurnInspection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1558 — AI Inspector CLI : observer le VRAI chemin, sans le polluer.
 *
 * Ce que ces tests gardent, dans l'ordre de ce qui ferait le plus de dégâts :
 *
 *  1. **le CLI appelle le service PRODUIT.** C'est l'invariant central : le jour
 *     où il reconstruirait son propre retrieval, il n'observerait plus le
 *     produit mais sa propre idée du produit — et il déclarerait vertes des
 *     questions que l'utilisateur voit rouges ;
 *  2. **il ne publie RIEN.** Observer ne parle pas dans le fil de quelqu'un
 *     d'autre. Zéro `loop_messages` créé, sur un chemin qui en crée deux ;
 *  3. **les gardes tiennent.** Tenant, ACL, appartenance : un identifiant passé
 *     en ligne de commande n'est pas une autorisation ;
 *  4. **`null` reste `null`.** Une valeur non observée ne devient jamais 0 —
 *     sans quoi l'outil fabriquerait les fausses certitudes qu'il existe pour
 *     empêcher.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1558InspectorCliTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $autre;

    private User $membre;

    private User $etranger;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);
        $this->autre = Organization::factory()->create(['is_active' => true]);

        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->etranger = User::factory()->create(['organization_id' => $this->autre->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->membre->id,
            'name' => 'BoucleAria',
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->membre->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Le Dossier racine se cree par le SERVICE, jamais a la main : la
        // contrainte PostgreSQL `dossiers_holder_xor` exige exactement UN
        // porteur — `owner_id` XOR `loop_id`. SQLite ne l'applique pas, donc un
        // fixture fabrique passe en local et rougit en CI (paye une fois ici).
        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        $this->dossier = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1558',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
        ]);

        Http::preventStrayRequests();
    }

    // ────────── 1. LE test : le CLI appelle le service PRODUIT

    /**
     * L'invariant central, et le sabotage qui doit le faire rougir.
     *
     * Si quelqu'un remplaçait l'appel par `DossierSemanticSearchService` ou
     * `ContextBuilder` « pour aller plus vite », ce mock ne serait jamais
     * atteint et ce test deviendrait rouge. C'est la seule garde qui empêche le
     * CLI de dériver vers une imitation.
     */
    public function test_le_cli_appelle_le_service_produit_et_pas_une_imitation(): void
    {
        $this->mock(LoopKnowledgeAnswerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('answer')
                ->once()
                ->withArgs(fn ($loop, $user, $question, $trigger, $publish): bool => $loop->id === $this->loop->id
                    && $user->id === $this->membre->id
                    && $question === 'Qui est le responsable ?'
                    && $trigger === null
                    && $publish === false)
                ->andReturn($this->reponseVide());
        });

        $this->artisan('ai:inspect-turn', $this->invocation())->assertSuccessful();
    }

    /** Le mode hybride passe par l'autre entrée publique du MÊME service. */
    public function test_le_mode_hybride_appelle_l_autre_entree_du_meme_service(): void
    {
        $this->mock(LoopKnowledgeAnswerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('answerHybrid')->once()->andReturn($this->reponseVide());
            $mock->shouldNotReceive('answer');
        });

        $this->artisan('ai:inspect-turn', $this->invocation(['--mode' => 'ia_dossiers']))->assertSuccessful();
    }

    // ────────── 2. il n'écrit RIEN dans le fil

    public function test_observer_n_ecrit_aucun_message_dans_la_boucle(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.')]);
        $this->agentRepond('UNIVE coordonne le projet [S1].');

        $avant = LoopMessage::query()->count();

        $this->artisan('ai:inspect-turn', $this->invocation())->assertSuccessful();

        $this->assertSame($avant, LoopMessage::query()->count(),
            'observer ne parle jamais dans le fil de quelqu un d autre');
        $this->assertSame(0, LoopMessage::query()->where('loop_id', $this->loop->id)->count());
    }

    /**
     * Mais la TÉLÉMÉTRIE, elle, est écrite — parce qu'elle appartient au chemin.
     *
     * Une observation qui n'aurait rien coûté n'aurait rien observé : le tour a
     * réellement appelé le provider, et la trace doit le dire.
     */
    public function test_la_telemetrie_canonique_est_bien_ecrite(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.')]);
        $this->agentRepond('UNIVE coordonne le projet [S1].');

        $this->artisan('ai:inspect-turn', $this->invocation())->assertSuccessful();

        $this->assertSame(1, AiInteraction::query()->count(),
            'l interaction est la trace canonique du chemin, pas un effet de bord de l outil');
    }

    /** TASK-1556 : `turn_id` est tracé, donc les embeddings deviennent rattachables. */
    public function test_le_turn_id_est_propage_jusqu_a_la_trace(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.')]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $this->artisan('ai:inspect-turn', $this->invocation())->assertSuccessful();

        $metadata = AiInteraction::query()->firstOrFail()->metadata;

        $this->assertArrayHasKey('turn_id', $metadata);
        $this->assertTrue(Str::isUuid((string) $metadata['turn_id']),
            'le turn_id trace doit etre l uuid du tour, pas un placeholder');
        $this->assertArrayHasKey('embedding_sdk_invocation_ids', $metadata);
    }

    // ────────── 3. les gardes

    public function test_un_utilisateur_d_un_autre_tenant_est_refuse(): void
    {
        $this->rechercheMuette();

        $this->artisan('ai:inspect-turn', $this->invocation(['--user' => $this->etranger->email]))
            ->assertFailed();

        $this->assertSame(0, AiInteraction::query()->count(), 'un refus ne coute rien');
    }

    public function test_une_boucle_d_un_autre_tenant_est_refusee(): void
    {
        $ailleurs = Loop::factory()->create([
            'organization_id' => $this->autre->id,
            'created_by' => $this->etranger->id,
            'name' => 'Boucle etrangere',
            'visibility' => 'private',
        ]);

        $this->rechercheMuette();

        $this->artisan('ai:inspect-turn', $this->invocation(['--loop' => (string) $ailleurs->id]))
            ->assertFailed();
    }

    public function test_un_membre_non_autorise_de_la_boucle_est_refuse_par_le_service(): void
    {
        $autreMembre = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->rechercheMuette();

        // Le CLI le laisse passer (meme tenant) ; c'est le SERVICE qui refuse,
        // et c'est exactement ce qu'on veut prouver : la garde n'est pas
        // reimplementee dans l'outil.
        $this->artisan('ai:inspect-turn', $this->invocation(['--user' => $autreMembre->email]))
            ->assertFailed();

        $this->assertSame(0, LoopMessage::query()->count());
    }

    public function test_une_surface_ou_un_mode_non_reproductible_est_refuse_jamais_approxime(): void
    {
        $this->rechercheMuette();

        $this->artisan('ai:inspect-turn', $this->invocation(['--surface' => 'shell']))->assertFailed();
        $this->artisan('ai:inspect-turn', $this->invocation(['--mode' => 'people']))->assertFailed();
    }

    // ────────── 4. la forme de la trace

    public function test_le_json_porte_les_huit_sections_attendues(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.')]);
        $this->agentRepond('UNIVE coordonne le projet [S1].');

        $sortie = $this->sortieJson();

        // TASK-1565 : `retrieval_trace` s'ajoute aux huit sections d'origine —
        // les etages que le retrieval a REELLEMENT traverses, ecrits par le
        // pipeline et lus ici.
        $this->assertSame(
            ['run', 'identity', 'scope', 'retrieval', 'retrieval_trace', 'selection', 'llm_input', 'output', 'provider'],
            array_keys($sortie),
        );
        $this->assertSame('loop_knowledge_answer', $sortie['run']['capability']);
        $this->assertSame(1, $sortie['llm_input']['chunks_sent']);
    }

    /**
     * `NULL` reste `NULL` — la règle qui distingue une mesure d'une absence de
     * mesure.
     *
     * TASK-1565 a rendu `candidates_found` OBSERVABLE (le pipeline écrit sa
     * trace, l'inspecteur la lit), donc l'exemple d'origine n'en est plus un :
     * la règle se garde désormais là où elle mord encore — une interaction qui
     * ne porte AUCUNE trace. Elle doit rendre `null`, et jamais `0`, sans quoi
     * « je n'ai pas mesuré » deviendrait « le retrieval n'a rien trouvé ».
     */
    public function test_une_valeur_non_observee_reste_null_et_ne_devient_jamais_zero(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.')]);
        $this->agentRepond('UNIVE coordonne [S1].');

        config(['ai.knowledge.retrieval_trace.enabled' => false]);

        $sortie = $this->sortieJson();

        $this->assertNull($sortie['retrieval']['candidates_found']);
        $this->assertNotSame(0, $sortie['retrieval']['candidates_found']);
        $this->assertNull($sortie['retrieval_trace']['dense_candidates_count']);
    }

    /** Le périmètre rendu est celui de l'autorité réelle, pas une seconde règle. */
    public function test_le_perimetre_affiche_est_celui_de_l_autorite_reelle(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.')]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $sortie = $this->sortieJson();

        $this->assertContains((string) $this->dossier->id, $sortie['scope']['authorized_dossier_ids']);
    }

    /**
     * Une trace historique sans les clés récentes ne casse pas la lecture, et
     * surtout ne fabrique aucune valeur : `null` dit « cette trace ne le porte
     * pas », jamais « aucune source refusée ».
     */
    public function test_une_trace_historique_sans_les_cles_recentes_reste_lisible(): void
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'process' => 'loop_knowledge',
            'feature' => 'loop_knowledge_answer',
            'model' => 'legacy',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'metadata' => ['capability' => 'loop_knowledge_answer'],
        ]);

        $trace = AiTurnInspection::build([], [], 'q', $this->reponseVide(), $interaction);

        $this->assertNull($trace['run']['turn_id']);
        $this->assertNull($trace['provider']['embedding_sdk_invocation_ids']);
        $this->assertNull($trace['provider']['sources_denied'],
            'null = « cette trace ne les porte pas », jamais « aucune source refusee »');
    }

    /** G/H : la trace ne rend que ce que la provenance autorisée porte déjà. */
    public function test_aucune_source_non_autorisee_ne_peut_apparaitre_dans_la_trace(): void
    {
        $this->rechercheRendant([$this->ligne('Prof. Enrica De Cian — Team Lead UNIVE.')]);
        $this->agentRepond('UNIVE coordonne [S1].');

        $brut = json_encode($this->sortieJson(), JSON_UNESCAPED_UNICODE);

        // Le Dossier d'un AUTRE tenant n'entre jamais dans le perimetre, donc
        // rien de lui ne peut sortir — ni nom, ni identifiant.
        // Dossier PERSONNEL d'un autre tenant : porteur = `owner_id` seul,
        // conforme au XOR.
        $secret = Dossier::create([
            'organization_id' => $this->autre->id,
            'owner_id' => $this->etranger->id,
            'loop_id' => null,
            'name' => 'CONFIDENTIEL AUTRE TENANT',
            'visibility' => 'organization',
        ]);

        $this->assertStringNotContainsString('CONFIDENTIEL', (string) $brut);
        $this->assertStringNotContainsString((string) $secret->id, (string) $brut);
    }

    /** La commande n'expose aucune option destructive. */
    public function test_la_commande_n_offre_aucune_option_destructive(): void
    {
        $definition = $this->app->make(Kernel::class)
            ->all()['ai:inspect-turn']->getDefinition();

        $options = array_keys($definition->getOptions());

        foreach (['force', 'delete', 'reset', 'purge', 'truncate', 'fresh', 'publish'] as $interdit) {
            $this->assertNotContains($interdit, $options, "l observation n offre jamais « {$interdit} »");
        }
    }

    // ─────────────────────────────────────────────── fixtures

    /** @param  array<string, string>  $remplace */
    private function invocation(array $remplace = []): array
    {
        return array_merge([
            '--organization' => $this->organization->slug,
            '--user' => $this->membre->email,
            '--surface' => 'loop',
            '--loop' => (string) $this->loop->id,
            '--mode' => 'dossiers',
            '--question' => 'Qui est le responsable ?',
        ], $remplace);
    }

    /** @return array<string, mixed> */
    private function sortieJson(): array
    {
        // `$this->artisan()` rend un PendingCommand dont la sortie n'est pas
        // capturee : pour LIRE le JSON, il faut l'appel direct du Kernel.
        $code = Artisan::call('ai:inspect-turn', $this->invocation(['--json' => true]));

        $this->assertSame(0, $code, 'la commande doit reussir pour que sa trace soit lisible');

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function reponseVide(): KnowledgeAnswer
    {
        return new KnowledgeAnswer(
            answer: 'Je n\'ai pas trouve.',
            sources: [],
            consulted: [],
            grounded: false,
            interactionId: null,
        );
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function rechercheRendant(array $lignes): MockInterface
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn($lignes);

        return $mock;
    }

    private function rechercheMuette(): MockInterface
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn([])->byDefault();

        return $mock;
    }

    private function agentRepond(string $texte): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /** @return array<string, mixed> */
    private function ligne(string $contenu): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => (string) $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'ARIA Part B.docx',
            'mime_type' => 'application/pdf',
            'chunk_index' => 0,
            'content' => $contenu,
            'distance' => 0.12,
        ];
    }
}
