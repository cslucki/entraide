<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Loops\LoopDossierAnswerService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1595 — « Consulter les Dossiers » repond par le moteur documentaire
 * CANONIQUE, sur le Dossier racine de la Boucle.
 *
 * ## Ce que cette suite mesure, et pourquoi de cette facon
 *
 * Le composeur est exerce par sa VRAIE porte (Livewire), jamais le service
 * appele a la main : ce qui est en cause ici n'est pas qu'un moteur fasse ce
 * qu'on lui demande, c'est que le point d'entree appelle le BON moteur. Un
 * test qui appellerait `LoopDossierAnswerService` directement resterait vert
 * si `LoopChat` continuait d'appeler l'ancien chemin.
 *
 * Trois familles :
 *
 *  A. le MOTEUR : le tour de `loop_chat.dossiers` porte desormais le
 *     producteur `dossier.insights`, et son perimetre est le Dossier RACINE ;
 *  B. le CONTRAT DE BULLE, inchange — `ai_mode`, `action`, `reply_to_id`,
 *     `ai_interaction_id` : c'est par cette derniere cle que l'Inspector et le
 *     Lab retrouvent la bulle d'un tour, et la perdre ne casse rien de visible
 *     tout en cassant toute l'observabilite ;
 *  C. ce qui SORT du chemin (§9 du mandat) : ni Context Builder, ni manifest,
 *     ni knowledge.delta, ni `max_distance`, ni rerank. Mesure au RUNTIME sur
 *     le tour, pas par lecture de code — un composant peut etre importe et
 *     jamais atteint, et c'est l'inverse qu'il faut prouver.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1595LoopChatDossiersCanonicalEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $racine;

    /** Les Dossiers reellement demandes a la recherche, tour apres tour. */
    private array $dossiersInterroges = [];

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1595']);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1595',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle documentaire');

        // Le Dossier racine de la Boucle — celui que `loop_id` designe, et le
        // seul que ce chemin doit desormais interroger.
        $this->racine = Dossier::query()->where('loop_id', $this->loop->id)->sole();

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.chatloop.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $rend = function (string $orgId, array $dossierIds): array {
            $this->dossiersInterroges = $dossierIds;

            return [$this->ligne(Dossier::findOrFail($dossierIds[0]))];
        };
        $recherche->shouldReceive('searchAcrossDossiers')->andReturnUsing($rend)->byDefault();
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────── A. le moteur et son perimetre

    public function test_a1_le_composeur_repond_par_le_moteur_canonique(): void
    {
        $bloc = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('Que dit le document ?'));

        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $bloc['identity']['execution_path'],
            'le chemin produit ne change pas de nom : seul le moteur derriere lui change');
        $this->assertSame(DossierInsightsService::PRODUCER, $bloc['identity']['producer'],
            'le producteur est desormais `dossier.insights`, celui de « Posez une question a ce Dossier »');
    }

    public function test_a2_le_perimetre_est_le_dossier_racine_et_lui_seul(): void
    {
        // Un Dossier PARTAGE a la Boucle : interroge par l'ancien chemin,
        // volontairement hors perimetre du nouveau. Le test l'acte plutot que
        // de le subir — c'est l'hypothese produit « une Boucle, un Dossier ».
        Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Dossier partage',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        $this->envoyerDepuisLeComposeur('Que dit le document ?');

        $this->assertSame([(string) $this->racine->id], $this->dossiersInterroges,
            'un seul Dossier est interroge, et c\'est la racine de la Boucle');
    }

    public function test_a3_une_boucle_sans_dossier_racine_ne_paie_rien(): void
    {
        $this->racine->forceDelete();

        $this->actingAs($this->membre);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que dit le document ?')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertSame(0, AiInteraction::query()->count(), 'aucun appel provider, donc aucune interaction');
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count(), 'aucune bulle');
        $this->assertSame(1, LoopMessage::query()->where('type', 'user')->count(),
            'le message humain est CONSERVE : il a bien ete ecrit par son auteur');
    }

    // ────────────────────────── B. le contrat de bulle

    public function test_b1_la_bulle_garde_son_contrat(): void
    {
        $this->envoyerDepuisLeComposeur('Que dit le document ?');

        $humain = LoopMessage::query()->where('type', 'user')->sole();
        $bulle = LoopMessage::query()->where('type', 'ai')->sole();
        $interaction = AiInteraction::query()->sole();

        $this->assertSame('rag', $bulle->metadata['ai_mode'], 'l\'identite de bulle que le membre lit ne change pas');
        $this->assertSame('dossiers', $bulle->metadata['action']);
        $this->assertSame((string) $humain->id, (string) $bulle->reply_to_id, 'la reponse pend au message declencheur');
        $this->assertSame((string) $interaction->id, (string) $bulle->metadata['ai_interaction_id'],
            'le SEUL lien par lequel l\'Inspector et le Lab retrouvent la bulle d\'un tour');
        $this->assertSame('Que dit le document ?', $bulle->metadata['question']);
        $this->assertTrue($bulle->metadata['grounded']);
        $this->assertSame('openrouter', $bulle->metadata['provider']);
        $this->assertSame('openai/gpt-4o-mini', $bulle->metadata['model']);
        $this->assertNotSame([], $bulle->metadata['sources'], 'la provenance est publiee');
        $this->assertArrayNotHasKey('chunk_id', $bulle->metadata['sources'][0], 'jamais d\'identifiant interne dans une bulle');
    }

    public function test_b2_publish_false_execute_le_tour_sans_publier(): void
    {
        $reponse = app(LoopDossierAnswerService::class)
            ->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false);

        $this->assertNotNull($reponse->interactionId, 'le tour a REELLEMENT tourne : il a paye et laisse sa trace');
        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count(), 'aucune bulle dans le fil de personne');
    }

    public function test_b3_un_non_membre_n_obtient_rien(): void
    {
        $etranger = User::factory()->complete()->create([
            'organization_id' => Organization::factory()->create(['is_active' => true, 'slug' => 'org-1595-autre'])->id,
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            app(LoopDossierAnswerService::class)->answer($this->loop, $etranger, 'Que dit le document ?');
        } finally {
            $this->assertSame(0, AiInteraction::query()->count(), 'un refus n\'ecrit rien et ne coute rien');
            $this->assertSame([], $this->dossiersInterroges, 'aucune recherche n\'a meme ete tentee');
        }
    }

    // ────────────────────────── D. les questions pour approfondir

    public function test_d1_les_questions_pour_approfondir_sont_publiees_avec_la_bulle(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse(
            "Le document dit ceci [S1].\n\n## Questions possibles\n- Et les partenaires ?\n- Quel est le calendrier ?"
        ));

        $this->envoyerDepuisLeComposeur('Que dit le document ?');

        $bulle = LoopMessage::query()->where('type', 'ai')->sole();

        $this->assertSame(['Et les partenaires ?', 'Quel est le calendrier ?'], $bulle->metadata['follow_up_questions']);
        $this->assertStringNotContainsString('Questions possibles', $bulle->body,
            'la rubrique est EXTRAITE du corps, pas laissee en texte');

        // Et elles sont REELLEMENT offertes dans le fil : une metadata que la
        // bulle n'affiche pas ne propose rien a personne.
        $this->actingAs($this->membre);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('data-loop-follow-up="0"', escape: false)
            ->assertSee('data-loop-follow-up="1"', escape: false)
            ->assertSee('Et les partenaires ?');
    }

    public function test_d2_aucune_question_aucune_cle(): void
    {
        $this->envoyerDepuisLeComposeur('Que dit le document ?');

        $bulle = LoopMessage::query()->where('type', 'ai')->sole();

        $this->assertArrayNotHasKey('follow_up_questions', $bulle->metadata,
            'pas de cle vide : la forme de la metadata ne change pas quand il n\'y a rien a proposer');
    }

    public function test_d3_un_clic_repart_par_loop_chat_dossiers_sans_jamais_ouvrir_le_shell(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse(
            "Le document dit ceci [S1].\n\n## Questions possibles\n- Et les partenaires ?"
        ));

        $this->envoyerDepuisLeComposeur('Que dit le document ?');
        $bulle = LoopMessage::query()->where('type', 'ai')->sole();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->actingAs($this->membre);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('askFollowUp', (string) $bulle->id, 0)
            ->assertHasNoErrors();

        // La question est partie comme un message HUMAIN du fil, telle que le
        // serveur l'avait proposee.
        // `orderBy('created_at')` SEUL n'est pas un ordre total : la colonne
        // est un `timestamp` de precision SECONDE (mesure :
        // `datetime_precision = 0`), et les deux messages de ce test naissent
        // dans la meme seconde — ils sont donc a egalite. PostgreSQL rend
        // alors l'ordre du TAS, qui depend de la charge : le test passait
        // toujours seul et tombait une fois sur deux dans un shard complet.
        // `id` departage (UUID ordonne, donc chronologique) et l'ordre
        // redevient deterministe sur les deux moteurs.
        $humains = LoopMessage::query()->where('type', 'user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $humains);
        $this->assertSame('Et les partenaires ?', $humains[1]->body);
        $this->assertSame('dossiers', $humains[1]->metadata['requested_mode'],
            'le mode Dossiers est celui de l\'envoi, pas un toggle laisse au hasard');

        // Et le tour qui en decoule est un tour documentaire canonique.
        $dernier = AiInteraction::query()->latest('id')->first();
        $bloc = $dernier->metadata[AiTurnTrace::TURN_METADATA_KEY];
        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $bloc['identity']['execution_path']);
        $this->assertSame(DossierInsightsService::PRODUCER, $bloc['identity']['producer']);

        // Aucun Shell : ni ligne de conversation Shell, ni chemin `ai_shell.*`.
        $this->assertSame(0, AiShellMessage::query()->count(), 'le clic ne doit JAMAIS invoquer le Shell');
        $this->assertSame([], AiInteraction::query()->get()
            ->map(fn (AiInteraction $i): ?string => $i->metadata[AiTurnTrace::TURN_METADATA_KEY]['identity']['execution_path'] ?? null)
            ->filter(fn (?string $p): bool => $p !== null && str_starts_with($p, 'ai_shell.'))
            ->values()->all());
    }

    public function test_d4_l_index_est_relu_dans_la_bulle_de_cette_boucle(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse(
            "Le document dit ceci [S1].\n\n## Questions possibles\n- Et les partenaires ?"
        ));

        $this->envoyerDepuisLeComposeur('Que dit le document ?');
        $bulle = LoopMessage::query()->where('type', 'ai')->sole();
        $avant = LoopMessage::query()->count();

        AiTurnLock::forgetRequestState();
        $this->actingAs($this->membre);

        // Un index hors bornes, et un identifiant de bulle qui n'est pas de
        // cette Boucle : deux refus SILENCIEUX, aucun message, aucun tour.
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('askFollowUp', (string) $bulle->id, 7)
            ->call('askFollowUp', (string) Str::uuid(), 0);

        $this->assertSame($avant, LoopMessage::query()->count(), 'rien n\'a ete publie');
        $this->assertSame(1, AiInteraction::query()->count(), 'aucun second tour');
    }

    // ────────────────────────── C. ce qui sort du chemin (§9)

    public function test_c1_le_chemin_ne_passe_plus_par_le_context_builder(): void
    {
        $bloc = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('Que dit le document ?'));

        $etape = $this->etapeUnique($bloc, 'context_builder');

        $this->assertSame('bypassed', $etape['status']);
        $this->assertSame(AiTurnReason::CONTEXT_BUILDER_DOCUMENT_PATH_DIRECT_EXECUTION, $etape['reason_code']);
    }

    public function test_c2_ni_manifest_ni_knowledge_delta_ne_fondent_plus_ce_tour(): void
    {
        $bloc = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('Quels fichiers y a-t-il ?'));

        $this->assertSame([DossierInsightsService::SOURCE_NAME], $bloc['sources']['used'],
            'une seule source fonde ce tour, et c\'est le moteur canonique');
        $this->assertNotContains('dossier.manifest', $bloc['sources']['used']);
        $this->assertNotContains('dossier.retrieval', $bloc['sources']['used']);
        $this->assertNotContains('knowledge.delta', $bloc['sources']['used']);
    }

    public function test_c3_ni_max_distance_ni_rerank_ne_s_appliquent_plus(): void
    {
        $this->envoyerDepuisLeComposeur('Que dit le document ?');

        $metadata = AiInteraction::query()->sole()->metadata;

        // `retrieval_trace` est l'objet qui porte `max_distance`, `rerank_*` et
        // les compteurs de candidats. Il est depose par `DossierRetrievalSource`
        // et par elle seule : son ABSENCE est la mesure que cette source n'est
        // plus sur le chemin. Une lecture d'imports ne prouverait rien.
        $this->assertArrayNotHasKey('retrieval_trace', $metadata,
            'plus de DossierRetrievalSource sur ce chemin, donc plus de max_distance ni de rerank');
    }

    public function test_c4_l_historique_de_fil_est_conserve(): void
    {
        // La bascule ne devait rien retirer d'autre que ce qui etait decide :
        // repondre a une bulle emporte toujours le contexte du fil.
        $this->envoyerDepuisLeComposeur('Que dit le document ?');

        $bulle = LoopMessage::query()->where('type', 'ai')->sole();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->actingAs($this->membre);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', (string) $bulle->id)
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Et ensuite ?')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $dernier = AiInteraction::query()->latest('id')->first();
        $bloc = $dernier->metadata[AiTurnTrace::TURN_METADATA_KEY];

        $this->assertSame('executed', $this->etapeUnique($bloc, 'conversation_history')['status']);
        $this->assertGreaterThan(0, $bloc['history']['count'], 'le fil a bien ete lu');
        $this->assertSame('reply_chain', $bloc['history']['strategy']);
    }

    // ────────────────────────── helpers

    private function envoyerDepuisLeComposeur(string $question): void
    {
        $this->actingAs($this->membre);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', $question)
            ->call('sendMessage')
            ->assertHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function blocDuTour(callable $tour): array
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        AiTurnLock::forgetRequestState();

        $tour();

        $nouvelles = AiInteraction::query()->whereNotIn('id', $deja)->get();

        $this->assertCount(1, $nouvelles, 'un tour doit ecrire EXACTEMENT une interaction');

        $metadata = $nouvelles->first()->metadata;

        $this->assertArrayHasKey(AiTurnTrace::TURN_METADATA_KEY, $metadata);

        return $metadata[AiTurnTrace::TURN_METADATA_KEY];
    }

    /**
     * @param  array<string, mixed>  $bloc
     * @return array<string, mixed>
     */
    private function etapeUnique(array $bloc, string $nom): array
    {
        $etapes = array_values(array_filter($bloc['steps'], static fn (array $etape): bool => $etape['name'] === $nom));

        $this->assertCount(1, $etapes, "l'etape `{$nom}` doit apparaitre exactement une fois");

        return $etapes[0];
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }

    /** @return array<string, mixed> */
    private function ligne(Dossier $dossier): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => (string) $dossier->id,
            'dossier_name' => $dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 0,
            'content' => 'Contenu du '.$dossier->name.'.',
            'distance' => 0.2,
        ];
    }
}
