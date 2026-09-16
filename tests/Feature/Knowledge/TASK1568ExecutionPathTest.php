<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * TASK-1568 / CDC-01 V0-G — chaque chemin porte son nom.
 *
 * ## Ce que ce fichier garde, famille par famille
 *
 *  A. le VOCABULAIRE gele par `TRACE0_SCHEMA_FROZEN` : 18 `execution_path`,
 *     dont 4 reserves a V0-I ; les codes de bypass du `ContextBuilder` ; les 8
 *     codes de fallthrough Shell — poses, pas branches ;
 *  B. L'APPELANT EST L'AUTORITE (C15) : un moteur partage ecrit le chemin que
 *     son point d'entree lui a NOMME, et n'ecrit rien s'il n'a rien recu. La
 *     regression que cette TASK corrige est B1 — l'endpoint JSON
 *     `LoopController::knowledge()` etait trace `loop_chat.dossiers` parce que
 *     V0-A derivait le chemin du seul mode ;
 *  C. l'etape `context_builder` : `executed` la ou le composant tourne,
 *     `bypassed` + code la ou un moteur ignore le composant que sa capability
 *     declare (P0.3, P0.7, C3) — contrat COMPLET 7 / 7 / 4, table
 *     `CONTEXT_BUILDER_CONTRACT`, et pas seulement quelques exemples ;
 *  E. l'etape `conversation_history` (C18) : `executed` la ou le mecanisme
 *     d'historique du chemin a tourne — meme a `count = 0` —,
 *     `not_applicable` la ou l'etage n'existe pas ; traduite depuis ce que le
 *     writer sait DEJA, jamais collectee ni deduite ;
 *  D. les FRONTIERES : la collecte coupee ne change pas le produit ; aucun
 *     fallthrough n'est branche avant V0-I (G-β, C20) ; rien du contenu ne
 *     fuit dans le bloc (I9).
 *
 * ## Pourquoi chaque point d'entree est exerce par sa VRAIE porte
 *
 * `LoopController` par sa route, `LoopChat` par Livewire, la CLI par
 * `artisan`, le Shell par `AiShellResponder::respond()`, la page Dossier par
 * sa route. Appeler le moteur directement prouverait que le moteur ecrit ce
 * qu'on lui passe — pas que le point d'entree lui passe la bonne valeur. Or
 * c'est le point d'entree qui est l'autorite : c'est donc lui qu'on teste.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1568ExecutionPathTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossierDeLaBoucle;

    private Dossier $dossierPrive;

    /**
     * La recherche documentaire rend-elle une source ? `false` pour atteindre
     * les branches du Shell qui ne parlent que si la decouverte documentaire
     * se tait (generale, clarify).
     */
    private bool $rechercheRend = true;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1568']);

        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1568',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle des chemins');

        $this->dossierDeLaBoucle = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        $this->dossierPrive = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Dossier prive',
            'visibility' => 'private',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.chatloop.enabled' => true,
            'ai.chatloop.min_summary_words' => 0,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
        ]);

        // La recherche documentaire rend UNE source du Dossier demande, quel
        // qu'il soit : ce qui est mesure ici est le NOM du chemin, jamais le
        // retrieval.
        $recherche = $this->mock(DossierSemanticSearchService::class);
        $lignes = fn (string $orgId, array $dossierIds): array => $this->rechercheRend
            ? [$this->ligne(Dossier::findOrFail($dossierIds[0]))]
            : [];
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturnUsing($lignes)->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturnUsing($lignes)->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse directe.'));
        ShellGeneralAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse generale.'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. le vocabulaire gele

    public function test_a1_dix_huit_chemins_uniques_et_bien_formes(): void
    {
        $chemins = AiExecutionPath::all();

        $this->assertCount(18, $chemins);
        $this->assertSame($chemins, array_values(array_unique($chemins)), 'deux constantes portent la meme valeur');

        foreach ($chemins as $chemin) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $chemin, "`{$chemin}` : la forme est `<surface>.<branche>`");
        }

        // Le CDC ecrivait `ai_shell.people` ; C16 le scinde. L'ancien nom ne
        // doit PAS survivre a cote des deux nouveaux : il se lirait comme un
        // troisieme chemin.
        $this->assertFalse(AiExecutionPath::isKnown('ai_shell.people'));
        $this->assertTrue(AiExecutionPath::isKnown(AiExecutionPath::AI_SHELL_PEOPLE_MATCHING));
        $this->assertTrue(AiExecutionPath::isKnown(AiExecutionPath::AI_SHELL_PEOPLE_SELF));
    }

    public function test_a2_quatre_chemins_sont_reserves_et_quatorze_ecrits(): void
    {
        $reserves = AiExecutionPath::reservedForShellZeroProvider();
        $ecrits = AiExecutionPath::writtenByInteractionWriters();

        $this->assertCount(4, $reserves);
        $this->assertCount(14, $ecrits);
        $this->assertSame([], array_intersect($reserves, $ecrits));
        $this->assertEqualsCanonicalizing(AiExecutionPath::all(), [...$reserves, ...$ecrits]);

        // Garde de scope V0-I : aucun writer d'`ai_interactions` ne nomme une
        // branche zero-provider. Un grep statique suffit — ces constantes ne
        // doivent apparaitre QUE dans le registre.
        foreach (['AI_SHELL_SELF_KNOWLEDGE', 'AI_SHELL_REFERENCE', 'AI_SHELL_PEOPLE_MATCHING', 'AI_SHELL_PEOPLE_SELF'] as $constante) {
            $this->assertSame(
                [],
                $this->fichiersApplicatifsContenant('AiExecutionPath::'.$constante),
                "`{$constante}` est ecrite par un chemin applicatif : c'est le perimetre de V0-I",
            );
        }
    }

    public function test_a3_le_registre_des_raisons_connait_les_codes_de_v0g(): void
    {
        $familles = AiTurnReason::byFamily();

        $this->assertSame(
            ['LLM_PATH_NO_CONTEXT_BUILDER', 'DOCUMENT_PATH_DIRECT_EXECUTION'],
            $familles['context_builder'],
        );

        $this->assertSame([
            'BRANCH_SHAPE_NOT_MATCHED',
            'CONTEXT_OBJECT_ABSENT',
            'OBJECT_NOT_ACCESSIBLE',
            'CONTEXT_EMPTY',
            'ENGINE_EXCEPTION',
            'NO_SOURCES_FOUND',
            'EMPTY_MODEL_ANSWER',
            'NO_REFERENCE_CANDIDATE',
        ], $familles['fallthrough']);

        foreach ([...$familles['context_builder'], ...$familles['fallthrough']] as $code) {
            $this->assertTrue(AiTurnReason::isKnown($code));
        }
    }

    /**
     * Le contrat `context_builder` des 18 chemins — GELE par
     * `TRACE0_SCHEMA_FROZEN`. 7 `executed` · 7 `bypassed` · 4 `not_applicable`.
     * Les 4 `not_applicable` sont zero-provider : declares ici, ecrits par V0-I.
     */
    private const CONTEXT_BUILDER_CONTRACT = [
        AiExecutionPath::LOOP_CHAT_IA => 'bypassed',
        AiExecutionPath::LOOP_CHAT_DOSSIERS => 'executed',
        AiExecutionPath::LOOP_CHAT_IA_DOSSIERS => 'executed',
        AiExecutionPath::LOOP_CHAT_LEGACY_ASK => 'executed',
        AiExecutionPath::LOOP_CHAT_LEGACY_ANSWER => 'executed',
        AiExecutionPath::LOOP_CONTROLLER_KNOWLEDGE_JSON => 'executed',
        AiExecutionPath::AI_SHELL_SELF_KNOWLEDGE => 'not_applicable',
        AiExecutionPath::AI_SHELL_DOSSIER => 'bypassed',
        AiExecutionPath::AI_SHELL_ARTICLE => 'bypassed',
        AiExecutionPath::AI_SHELL_CONTINUATION => 'bypassed',
        AiExecutionPath::AI_SHELL_REFERENCE => 'not_applicable',
        AiExecutionPath::AI_SHELL_PEOPLE_MATCHING => 'not_applicable',
        AiExecutionPath::AI_SHELL_PEOPLE_SELF => 'not_applicable',
        AiExecutionPath::AI_SHELL_DISCOVERY => 'bypassed',
        AiExecutionPath::AI_SHELL_GENERAL => 'executed',
        AiExecutionPath::AI_SHELL_CLARIFY => 'executed',
        AiExecutionPath::DOSSIER_PAGE_ANSWER => 'bypassed',
        AiExecutionPath::DOSSIER_PAGE_INSIGHTS => 'bypassed',
    ];

    /**
     * Le contrat `conversation_history` (C18) des 14 chemins ecrits par V0-G.
     * `executed` = le mecanisme d'historique de CE chemin a tourne (chaine de
     * reply LoopChat, fil Shell) ; `not_applicable` = l'etage n'existe pas.
     */
    private const CONVERSATION_HISTORY_CONTRACT = [
        AiExecutionPath::LOOP_CHAT_IA => 'executed',
        AiExecutionPath::LOOP_CHAT_DOSSIERS => 'executed',
        AiExecutionPath::LOOP_CHAT_IA_DOSSIERS => 'executed',
        AiExecutionPath::LOOP_CHAT_LEGACY_ASK => 'not_applicable',
        AiExecutionPath::LOOP_CHAT_LEGACY_ANSWER => 'not_applicable',
        AiExecutionPath::LOOP_CONTROLLER_KNOWLEDGE_JSON => 'executed',
        AiExecutionPath::AI_SHELL_DOSSIER => 'executed',
        AiExecutionPath::AI_SHELL_ARTICLE => 'executed',
        AiExecutionPath::AI_SHELL_CONTINUATION => 'executed',
        AiExecutionPath::AI_SHELL_DISCOVERY => 'not_applicable',
        AiExecutionPath::AI_SHELL_GENERAL => 'executed',
        AiExecutionPath::AI_SHELL_CLARIFY => 'executed',
        AiExecutionPath::DOSSIER_PAGE_ANSWER => 'not_applicable',
        AiExecutionPath::DOSSIER_PAGE_INSIGHTS => 'not_applicable',
    ];

    public function test_a4_le_contrat_context_builder_couvre_les_18_chemins_en_7_7_4(): void
    {
        $this->assertEqualsCanonicalizing(AiExecutionPath::all(), array_keys(self::CONTEXT_BUILDER_CONTRACT));

        $cardinalites = array_count_values(self::CONTEXT_BUILDER_CONTRACT);

        $this->assertSame(['bypassed' => 7, 'executed' => 7, 'not_applicable' => 4], [
            'bypassed' => $cardinalites['bypassed'],
            'executed' => $cardinalites['executed'],
            'not_applicable' => $cardinalites['not_applicable'],
        ]);

        // Les `not_applicable` sont EXACTEMENT les zero-provider reserves a V0-I.
        $this->assertEqualsCanonicalizing(
            AiExecutionPath::reservedForShellZeroProvider(),
            array_keys(array_filter(self::CONTEXT_BUILDER_CONTRACT, static fn (string $s): bool => $s === 'not_applicable')),
        );

        $this->assertEqualsCanonicalizing(AiExecutionPath::writtenByInteractionWriters(), array_keys(self::CONVERSATION_HISTORY_CONTRACT));
    }

    // ────────────────────────────── B. l'appelant est l'autorite

    /**
     * LA regression corrigee. Sabotage : reintroduire dans le moteur
     * `'execution_path' => $mode === MODE_HYBRID ? … : 'loop_chat.dossiers'`
     * → ce test rougit.
     */
    public function test_b1_l_endpoint_json_porte_son_propre_nom_et_plus_celui_du_composeur(): void
    {
        $identity = $this->identiteDuTour(function (): void {
            $this->actingAs($this->membre)
                ->postJson(
                    route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]),
                    ['question' => 'Que dit le document ?'],
                )
                ->assertOk();
        });

        $this->assertSame(AiExecutionPath::LOOP_CONTROLLER_KNOWLEDGE_JSON, $identity['execution_path']);
        $this->assertNotSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $identity['execution_path']);
        // Le mode, lui, reste celui du moteur : le chemin ne l'a pas remplace.
        $this->assertSame('dossiers', $identity['mode']);
    }

    public function test_b2_le_composeur_nomme_ses_deux_modes_documentaires(): void
    {
        $dossiers = $this->identiteDuTour(fn () => $this->envoyerDepuisLeComposeur('dossiers', 'Que dit le document ?'));
        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $dossiers['execution_path']);

        $hybride = $this->identiteDuTour(fn () => $this->envoyerDepuisLeComposeur('ia_dossiers', 'Et en croisant avec ce que tu sais ?'));
        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA_DOSSIERS, $hybride['execution_path']);
    }

    public function test_b3_la_cli_observe_le_chemin_produit_et_ne_s_invente_pas_le_sien(): void
    {
        $identity = $this->identiteDuTour(function (): void {
            $this->artisan('ai:inspect-turn', [
                '--organization' => $this->organization->slug,
                '--user' => $this->membre->email,
                '--surface' => 'loop',
                '--loop' => (string) $this->loop->id,
                '--mode' => 'dossiers',
                '--question' => 'Que dit le document ?',
            ])->assertSuccessful();
        });

        // Un tour observe par la CLI EST un `loop_chat.dossiers` : meme
        // methode, memes gardes. Un pseudo-chemin « cli » serait la faute
        // inverse de celle que C15 corrige.
        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $identity['execution_path']);
    }

    /**
     * Sabotage : remplacer `$executionPath` par un repli derive du mode dans
     * `generateUnderLock()` → ce test rougit (la cle apparait).
     */
    public function test_b4_un_appelant_muet_laisse_la_cle_absente_jamais_devinee(): void
    {
        $identity = $this->identiteDuTour(function (): void {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false);
        });

        $this->assertArrayNotHasKey('execution_path', $identity, 'le moteur a devine un chemin que personne ne lui a nomme');
        // Le reste de l'identite, que le moteur CONNAIT, est bien la.
        $this->assertSame('loop_chat', $identity['surface']);
        $this->assertSame('dossiers', $identity['mode']);
    }

    public function test_b5_les_deux_pages_dossier_nomment_deux_chemins_pour_un_seul_moteur(): void
    {
        $answer = $this->identiteDuTour(function (): void {
            $this->actingAs($this->membre)
                ->postJson(
                    route('organization.dossiers.answer', ['organization' => $this->organization, 'dossier' => $this->dossierPrive]),
                    ['question' => 'Que dit le document ?'],
                )
                ->assertOk();
        });
        $this->assertSame(AiExecutionPath::DOSSIER_PAGE_ANSWER, $answer['execution_path']);

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse(<<<'MD'
        ## Synthèse
        Le document décrit une procédure.

        ## Faits saillants
        - Une étape de validation est prévue [S1].
        MD));

        $insights = $this->identiteDuTour(function (): void {
            $this->actingAs($this->membre)
                ->postJson(route('organization.dossiers.insights', ['organization' => $this->organization, 'dossier' => $this->dossierPrive]))
                ->assertOk();
        });
        $this->assertSame(AiExecutionPath::DOSSIER_PAGE_INSIGHTS, $insights['execution_path']);
    }

    public function test_b6_le_shell_nomme_ses_branches_dossier_generale_et_clarify(): void
    {
        $dossier = $this->identiteDuTour(fn () => $this->envoyerAuShell(AiShellPageContext::KIND_DOSSIER, $this->dossierPrive->id, 'Que dit ce dossier ?'));
        $this->assertSame(AiExecutionPath::AI_SHELL_DOSSIER, $dossier['execution_path']);

        // Sans source documentaire, la decouverte se tait et le tour suit le
        // chemin general — puis le clarifier pour une question d'entraide.
        $this->rechercheRend = false;

        $general = $this->identiteDuTour(fn () => $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, 'Quelle est la capitale de la France ?'));
        $this->assertSame(AiExecutionPath::AI_SHELL_GENERAL, $general['execution_path']);

        $this->faireRepondreLeClarifier();
        $clarify = $this->identiteDuTour(fn () => $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, "Qui peut m'aider à trouver un relecteur ?"));
        $this->assertSame(AiExecutionPath::AI_SHELL_CLARIFY, $clarify['execution_path']);
    }

    // ────────────────────────────── C. context_builder : 6 executed / 8 bypassed

    /**
     * Sabotage : ecrire `executed` au lieu de `bypassed` dans
     * `respondInThread()` → ce test rougit.
     */
    public function test_c1_le_mode_ia_bypasse_le_context_builder_que_sa_capability_declare(): void
    {
        $bloc = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('ia', 'Quelle est la capitale de la France ?'));

        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA, $bloc['identity']['execution_path']);

        $etape = $this->etapeUnique($bloc, 'context_builder');
        $this->assertSame('bypassed', $etape['status']);
        $this->assertSame(AiTurnReason::CONTEXT_BUILDER_LLM_PATH_NO_CONTEXT_BUILDER, $etape['reason_code']);
    }

    public function test_c2_le_moteur_documentaire_direct_bypasse_le_context_builder(): void
    {
        $page = $this->blocDuTour(function (): void {
            $this->actingAs($this->membre)
                ->postJson(
                    route('organization.dossiers.answer', ['organization' => $this->organization, 'dossier' => $this->dossierPrive]),
                    ['question' => 'Que dit le document ?'],
                )
                ->assertOk();
        });
        $etape = $this->etapeUnique($page, 'context_builder');
        $this->assertSame('bypassed', $etape['status']);
        $this->assertSame(AiTurnReason::CONTEXT_BUILDER_DOCUMENT_PATH_DIRECT_EXECUTION, $etape['reason_code']);

        // La MEME capability, l'autre moteur : `executed`. C'est exactement ce
        // que `execution_path` existe pour rendre visible.
        $composeur = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('dossiers', 'Que dit le document ?'));
        $this->assertSame('executed', $this->etapeUnique($composeur, 'context_builder')['status']);
        $this->assertSame($page['identity']['capability'], $composeur['identity']['capability']);
    }

    public function test_c3_les_chemins_qui_appellent_le_context_builder_le_disent(): void
    {
        $legacy = $this->blocDuTour(function (): void {
            app(ChatLoopAiService::class)->ask($this->loop, $this->membre, 'Quelle est la prochaine etape ?');
        });
        $this->assertSame(AiExecutionPath::LOOP_CHAT_LEGACY_ASK, $legacy['identity']['execution_path']);
        $this->assertSame('executed', $this->etapeUnique($legacy, 'context_builder')['status']);
        $this->assertArrayHasKey('consulted', $this->etapeUnique($legacy, 'context_builder')['metrics']);

        $this->rechercheRend = false;

        $general = $this->blocDuTour(fn () => $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, 'Quelle est la capitale de la France ?'));
        $this->assertSame(AiExecutionPath::AI_SHELL_GENERAL, $general['identity']['execution_path']);
        $this->assertSame('executed', $this->etapeUnique($general, 'context_builder')['status']);

        $this->faireRepondreLeClarifier();
        $clarify = $this->blocDuTour(fn () => $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, "Qui peut m'aider à trouver un relecteur ?"));
        $this->assertSame(AiExecutionPath::AI_SHELL_CLARIFY, $clarify['identity']['execution_path']);
        $this->assertSame('executed', $this->etapeUnique($clarify, 'context_builder')['status']);
    }

    public function test_c4_une_etape_executed_ne_porte_aucun_reason_code(): void
    {
        $bloc = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('dossiers', 'Que dit le document ?'));

        $etape = $this->etapeUnique($bloc, 'context_builder');
        $this->assertSame('executed', $etape['status']);
        $this->assertArrayNotHasKey('reason_code', $etape);
    }

    /**
     * Le contrat, mesure au RUNTIME sur les 13 chemins que ce harnais atteint
     * par leur vraie porte — `context_builder` ET `conversation_history` (C18).
     * Seul `ai_shell.article` reste declare par la table : il exige un article
     * de blog publie dans un Dossier, deja couvert par sa propre suite pour le
     * comportement produit.
     */
    public function test_c5_le_contrat_des_deux_etapes_est_mesure_sur_treize_chemins(): void
    {
        $mesures = [];

        foreach ($this->toursAtteignables() as $chemin => $tour) {
            $bloc = $this->blocDuTour($tour);

            $this->assertSame($chemin, $bloc['identity']['execution_path'] ?? null, "le tour joue n'est pas `{$chemin}`");

            $mesures[$chemin] = [
                'context_builder' => $this->etapeUnique($bloc, 'context_builder')['status'],
                'conversation_history' => $this->etapeUnique($bloc, 'conversation_history')['status'],
            ];
        }

        $this->assertCount(13, $mesures);

        foreach ($mesures as $chemin => $statuts) {
            $this->assertSame(self::CONTEXT_BUILDER_CONTRACT[$chemin], $statuts['context_builder'], "context_builder de `{$chemin}`");
            $this->assertSame(self::CONVERSATION_HISTORY_CONTRACT[$chemin], $statuts['conversation_history'], "conversation_history de `{$chemin}`");
        }
    }

    // ────────────────────────────── E. conversation_history (C18)

    /**
     * Sabotage : ecrire `not_applicable` quand `count = 0` dans
     * `respondInThread()` (ou `executed` seulement si `$history !== []` ET
     * `count > 0` dans `conversationHistoryStep()`) → ce test rougit.
     */
    public function test_e1_un_mecanisme_qui_a_tourne_a_vide_reste_executed(): void
    {
        // Mode IA sans reply : `AiConversationContextBuilder` tourne et rend 0
        // message — c'est le comportement produit (CDC-01 P0.12, scenario 13b).
        $bloc = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('ia', 'Quelle est la capitale de la France ?'));

        $this->assertSame(0, $bloc['history']['count']);

        $etape = $this->etapeUnique($bloc, 'conversation_history');
        $this->assertSame('executed', $etape['status']);
        $this->assertSame(0, $etape['metrics']['count']);
        $this->assertArrayNotHasKey('reason_code', $etape);

        // Fil Shell vide, meme regle : le mecanisme a tourne, `executed` a zero.
        $this->rechercheRend = false;
        $shell = $this->blocDuTour(fn () => $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, 'Quelle est la capitale de la France ?'));
        $this->assertSame('shell_thread', $shell['history']['strategy']);
        $this->assertSame('executed', $this->etapeUnique($shell, 'conversation_history')['status']);
    }

    public function test_e2_un_chemin_sans_etage_de_conversation_est_not_applicable_et_sans_history(): void
    {
        $page = $this->blocDuTour(function (): void {
            $this->actingAs($this->membre)
                ->postJson(
                    route('organization.dossiers.answer', ['organization' => $this->organization, 'dossier' => $this->dossierPrive]),
                    ['question' => 'Que dit le document ?'],
                )
                ->assertOk();
        });

        $etape = $this->etapeUnique($page, 'conversation_history');
        $this->assertSame('not_applicable', $etape['status']);
        $this->assertArrayNotHasKey('metrics', $etape);
        $this->assertArrayNotHasKey('reason_code', $etape);
        // Coherence avec V0-L : pas d'etage → pas de bloc `history`.
        $this->assertArrayNotHasKey('history', $page);

        $legacy = $this->blocDuTour(function (): void {
            app(ChatLoopAiService::class)->ask($this->loop, $this->membre, 'Quelle est la prochaine etape ?');
        });
        $this->assertSame('not_applicable', $this->etapeUnique($legacy, 'conversation_history')['status']);
        $this->assertArrayNotHasKey('history', $legacy);
    }

    public function test_e3_les_metriques_de_l_etape_sont_celles_du_bloc_history(): void
    {
        $bloc = $this->blocDuTour(fn () => $this->envoyerAuShell(AiShellPageContext::KIND_DOSSIER, $this->dossierPrive->id, 'Que dit ce dossier ?'));

        $etape = $this->etapeUnique($bloc, 'conversation_history');
        $this->assertSame('executed', $etape['status']);
        $this->assertSame($bloc['history']['count'], $etape['metrics']['count']);
        $this->assertSame($bloc['history']['chars'], $etape['metrics']['chars']);
    }

    // ────────────────────────────── D. frontieres

    public function test_d1_collecte_coupee_le_produit_est_identique_et_le_chemin_absent(): void
    {
        $route = route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]);

        $avec = $this->actingAs($this->membre)->postJson($route, ['question' => 'Que dit le document ?'])->assertOk()->json();
        $blocAvec = AiInteraction::query()->latest('id')->firstOrFail()->metadata[AiTurnTrace::TURN_METADATA_KEY];

        AiTurnTrace::pauseCollectionForTesting();
        AiTurnLock::forgetRequestState();

        $sans = $this->actingAs($this->membre)->postJson($route, ['question' => 'Que dit le document ?'])->assertOk()->json();
        $blocSans = AiInteraction::query()->latest('id')->firstOrFail()->metadata[AiTurnTrace::TURN_METADATA_KEY];

        // Le PRODUIT ne depend pas de la trace.
        $this->assertSame($avec['answer'], $sans['answer']);

        // L'identite du tour survit ; l'OBSERVATION, elle, est coupee : le
        // chemin n'est pas devine pour combler le trou.
        $this->assertSame(AiExecutionPath::LOOP_CONTROLLER_KNOWLEDGE_JSON, $blocAvec['identity']['execution_path']);
        $this->assertTrue(Str::isUuid($blocSans['id']));
        $this->assertArrayNotHasKey('identity', $blocSans);
        $this->assertArrayNotHasKey('steps', $blocSans);
    }

    /**
     * Garde de scope G-β / C20. Sabotage : ajouter dans `AiShellResponder` un
     * `AiTurnTrace::step(…, AiTurnReason::FALLTHROUGH_…)` → ce test rougit.
     */
    public function test_d2_aucun_fallthrough_n_est_branche_avant_v0i(): void
    {
        $this->assertSame(
            [],
            $this->fichiersApplicatifsContenant('FALLTHROUGH_', except: 'AiTurnReason.php'),
            'un code de fallthrough est emis hors du registre : c\'est le perimetre de V0-I (C20)',
        );
    }

    public function test_d3_le_bloc_ne_porte_ni_question_ni_contenu_de_document(): void
    {
        $question = 'Que dit le document ?';
        $bloc = $this->blocDuTour(fn () => $this->envoyerDepuisLeComposeur('dossiers', $question));

        $json = json_encode($bloc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($question, $json);
        $this->assertStringNotContainsString('Contenu du Dossier', $json);
        $this->assertStringNotContainsString('Le document dit ceci', $json);
    }

    // ────────────────────────────── harnais

    /**
     * Les 13 chemins que ce fichier sait jouer par leur VRAIE porte, sous la
     * forme `execution_path => callable`, dans un ORDRE qui compte (voir la
     * decouverte). Chaque callable joue UN tour.
     *
     * @return array<string, callable(): void>
     */
    private function toursAtteignables(): array
    {
        $question = 'Que dit le document ?';
        $routeKnowledge = route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]);
        $routeAnswer = route('organization.dossiers.answer', ['organization' => $this->organization, 'dossier' => $this->dossierPrive]);
        $routeInsights = route('organization.dossiers.insights', ['organization' => $this->organization, 'dossier' => $this->dossierPrive]);
        $insightsMarkdown = "## Synthèse\nLe document décrit une procédure.\n\n## Faits saillants\n- Une étape de validation est prévue [S1].";

        return [
            AiExecutionPath::LOOP_CHAT_IA => fn () => $this->envoyerDepuisLeComposeur('ia', 'Quelle est la capitale de la France ?'),
            AiExecutionPath::LOOP_CHAT_DOSSIERS => fn () => $this->envoyerDepuisLeComposeur('dossiers', $question),
            AiExecutionPath::LOOP_CHAT_IA_DOSSIERS => fn () => $this->envoyerDepuisLeComposeur('ia_dossiers', $question),
            AiExecutionPath::LOOP_CHAT_LEGACY_ASK => fn () => app(ChatLoopAiService::class)->ask($this->loop, $this->membre, 'Quelle est la prochaine etape ?'),
            AiExecutionPath::LOOP_CHAT_LEGACY_ANSWER => fn () => app(ChatLoopAiService::class)->answer($this->loop, $this->membre),
            AiExecutionPath::LOOP_CONTROLLER_KNOWLEDGE_JSON => fn () => $this->actingAs($this->membre)->postJson($routeKnowledge, ['question' => $question])->assertOk(),
            // Sur le tableau de bord, une question sans objet courant NI objet
            // deja discute, dont la recherche rend une source, part en
            // DECOUVERTE documentaire. Elle doit donc etre jouee AVANT tout
            // tour de Dossier : ensuite, la meme question devient une
            // CONTINUATION (ordre des branches, `AiShellResponder::respond()`).
            AiExecutionPath::AI_SHELL_DISCOVERY => function (): void {
                $this->rechercheRend = true;
                $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, 'Que disent mes documents sur la procedure ?');
            },
            AiExecutionPath::AI_SHELL_DOSSIER => function (): void {
                $this->rechercheRend = true;
                $this->envoyerAuShell(AiShellPageContext::KIND_DOSSIER, $this->dossierPrive->id, 'Que dit ce dossier ?');
            },
            // Le Dossier vient d'etre discute : depuis le tableau de bord, une
            // question documentaire le CONTINUE.
            AiExecutionPath::AI_SHELL_CONTINUATION => function (): void {
                $this->rechercheRend = true;
                $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, 'Et que dit-il de la validation ?');
            },
            // Sans source, le documentaire se tait : chemin GENERAL, puis
            // CLARIFY pour l'entraide.
            AiExecutionPath::AI_SHELL_GENERAL => function (): void {
                $this->rechercheRend = false;
                $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, 'Quelle est la capitale de la France ?');
            },
            AiExecutionPath::AI_SHELL_CLARIFY => function (): void {
                $this->rechercheRend = false;
                $this->faireRepondreLeClarifier();
                $this->envoyerAuShell(AiShellPageContext::KIND_DASHBOARD, null, "Qui peut m'aider à trouver un relecteur ?");
            },
            AiExecutionPath::DOSSIER_PAGE_ANSWER => function () use ($routeAnswer, $question): void {
                $this->rechercheRend = true;
                $this->actingAs($this->membre)->postJson($routeAnswer, ['question' => $question])->assertOk();
            },
            AiExecutionPath::DOSSIER_PAGE_INSIGHTS => function () use ($routeInsights, $insightsMarkdown): void {
                $this->rechercheRend = true;
                LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse($insightsMarkdown));
                $this->actingAs($this->membre)->postJson($routeInsights)->assertOk();
            },
        ];
    }

    /**
     * Execute UN tour et rend le bloc `turn` de l'interaction que CE tour a
     * ecrite — identifiee par difference d'ids, jamais par date.
     *
     * @return array<string, mixed>
     */
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

    /** @return array<string, mixed> */
    private function identiteDuTour(callable $tour): array
    {
        $bloc = $this->blocDuTour($tour);

        $this->assertArrayHasKey('identity', $bloc);

        return $bloc['identity'];
    }

    /** @return array<string, mixed> */
    private function etapeUnique(array $bloc, string $nom): array
    {
        $this->assertArrayHasKey('steps', $bloc);

        $etapes = array_values(array_filter($bloc['steps'], static fn (array $etape): bool => $etape['name'] === $nom));

        $this->assertCount(1, $etapes, "l'etape `{$nom}` doit apparaitre exactement une fois");

        return $etapes[0];
    }

    private function envoyerDepuisLeComposeur(string $mode, string $question): void
    {
        $this->actingAs($this->membre);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', $mode)
            ->set('body', $question)
            ->call('sendMessage')
            ->assertHasNoErrors();
    }

    private function envoyerAuShell(string $kind, ?string $objectId, string $question): void
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->membre,
            $this->organization,
            $kind,
            $objectId,
            $kind === AiShellPageContext::KIND_DASHBOARD ? 'organization.dashboard' : 'organization.dossiers.show',
        );

        $this->actingAs($this->membre);

        app(AiShellResponder::class)->respond($this->organization, $this->membre, $question, $context);
    }

    private function faireRepondreLeClarifier(): void
    {
        $structure = [
            'interaction_fit' => true,
            'direct_reply' => '',
            'title' => 'Relecture',
            'clarified_request' => 'Je cherche un relecteur.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structure,
            json_encode($structure, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
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

    /**
     * Les fichiers de `app/` qui contiennent `$aiguille` — la garde statique
     * de scope. Cherche le texte tel quel, pas une expression reguliere.
     *
     * @return list<string>
     */
    private function fichiersApplicatifsContenant(string $aiguille, ?string $except = null): array
    {
        $trouves = [];

        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $fichier) {
            if ($except !== null && $fichier->getFilename() === $except) {
                continue;
            }

            if (str_contains($fichier->getContents(), $aiguille)) {
                $trouves[] = $fichier->getRelativePathname();
            }
        }

        sort($trouves);

        return $trouves;
    }
}
