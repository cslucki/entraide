<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\ContexteIa;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\ShellGeneralAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\LoopService;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * TASK-1566 / CDC-01 V0-A — les QUATRE writers P0 NON pilotes portent
 * l'identite canonique du tour.
 *
 * ## Pourquoi ce fichier existe
 *
 * L'audit READ ONLY du SHA `e8fcd80f` a releve, a juste titre, que le livrable
 * central de V0-A — « `turn.id` chez TOUS les writers P0 » — ne reposait que
 * sur la lecture du code : seul le producteur pilote etait couvert par un test.
 * Les quatre autres writers n'etaient atteints par AUCUNE assertion.
 *
 * Un livrable prouve par relecture n'est pas un livrable prouve.
 *
 * ## Ce que ces tests gardent, writer par writer
 *
 *  1. `turn.schema === 1` — le tour est versionne ;
 *  2. `turn` contient EXACTEMENT `schema` et `id` — aucune semantique V0-G
 *     (`execution_path`, `steps`, `identity`…) n'a fuite chez un writer qui ne
 *     doit porter QUE l'identite a ce stade ;
 *  3. aucune cle de PREMIER NIVEAU nouvelle hors `turn` — le garde I8, celui
 *     qui empeche d'allumer un bandeau membre par megarde ;
 *  4. la metadata legacy de chaque writer est preservee ;
 *  5. `turn.id` est l'identite DU TOUR : elle change a chaque tour, et n'est
 *     JAMAIS le `correlation_id` — l'invariant fondateur de CDC-01 §5.0.2,
 *     celui qui justifie l'existence meme de `ContexteIa::$turnId` ;
 *  6. et surtout : cette identite est bien celle qu'un `ContexteIa` a generee.
 *
 * ## Comment le point 6 est prouve sans toucher une ligne applicative
 *
 * Pour `DossierInsightsService`, directement : `answerOverSources()` accepte un
 * `turnId` explicite, qu'on injecte puis compare.
 *
 * Pour les trois autres, le `turnId` est auto-genere au plus profond du moteur
 * (`ContexteIa:78`, `Str::uuid()`) et aucune couture ne l'expose :
 * `ProviderResolver` — le seul collaborateur qui recoit le `ContexteIa` sur ces
 * chemins — est une classe `final`, donc ni extensible ni mockable. Fabriquer
 * une couture DANS le code applicatif pour le confort d'un test est exactement
 * ce que V0-A interdit.
 *
 * La solution ne coute pourtant aucune ligne applicative : un
 * GENERATEUR-OBSERVATEUR d'uuid (`observerLesTurnIds()`), qui rend de VRAIS
 * uuid mais retient ceux frappes DEPUIS `ContexteIa::__construct`.
 *
 * Ce qui rend cette preuve reelle et non decorative : un writer qui frapperait
 * son PROPRE `Str::uuid()` au moment de persister — l'erreur la plus plausible,
 * puisqu'elle produit un uuid parfaitement valide et unique a chaque tour — ne
 * serait pas dans le jeu capture, et ferait rougir le test. Les proprietes
 * observables seules (unicite, difference d'avec `correlation_id`) laissaient
 * precisement passer ce cas.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1566WritersIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);

        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1566-writers',
        ]);

        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->membre, 'Boucle des writers');

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.chatloop.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();
        Str::createUuidsNormally();

        parent::tearDown();
    }

    // ────────────────────────────── writer 2 : ChatLoopAiService

    public function test_chatloop_ai_service_porte_l_identite_canonique(): void
    {
        $vu = $this->observerLesTurnIds();

        $interaction = $this->executerChatLoop('Quelle est la prochaine etape ?');

        $this->assertIdentiteVientDuContexteIa($vu, $interaction);
        $this->assertIdentiteSeule($interaction);
        $this->assertLegacyPreservee($interaction, ['loop_id', 'requested_by', 'latency_ms', 'provider', 'capability', 'status']);
    }

    public function test_chatloop_deux_tours_ont_deux_identites_mais_une_seule_correlation(): void
    {
        // L'invariant fondateur de CDC-01 §5.0.2 : `correlation_id` est partage
        // par toute une operation metier, `turn.id` ne l'est jamais. Si un
        // writer confondait les deux — la tentation la plus naturelle, puisque
        // `correlation_id` etait deja la —, ce test rougirait.
        $premier = $this->executerChatLoop('Premiere question');
        $second = $this->executerChatLoop('Seconde question');

        $this->assertNotSame($this->identite($premier), $this->identite($second));

        // Le nom de ce test promet DEUX choses : il en prouve donc deux. La
        // correlation, elle, EST partagee — c'est precisement ce qui rend
        // `turn.id` necessaire.
        $this->assertSame(
            (string) $premier->correlation_id,
            (string) $second->correlation_id,
            'les deux tours appartiennent a la meme operation metier',
        );
    }

    // ────────────────────────────── writer 3 : ShellGeneralAnswerService

    public function test_shell_general_answer_service_porte_l_identite_canonique(): void
    {
        $vu = $this->observerLesTurnIds();

        $interaction = $this->executerShellGeneral('A quoi sert cette page ?');

        $this->assertIdentiteVientDuContexteIa($vu, $interaction);
        $this->assertIdentiteSeule($interaction);
        $this->assertLegacyPreservee($interaction, ['requested_by', 'latency_ms', 'provider', 'capability', 'status', 'general_contract_hash']);

        // FACT preexistant a TASK-1566, volontairement NON corrige ici : ce
        // writer ecrit `sources_denied` au PREMIER niveau. La TASK ne l'a ni
        // ajoute ni retire. L'asserter documente la dette la ou elle vit.
        $this->assertArrayHasKey('sources_denied', $interaction->metadata);
    }

    public function test_shell_general_deux_tours_ont_deux_identites(): void
    {
        $premier = $this->executerShellGeneral('Premiere question');
        $second = $this->executerShellGeneral('Seconde question');

        $this->assertNotSame($this->identite($premier), $this->identite($second));
    }

    // ────────────────────────────── writer 4 : ClarifyUserHelpRequestService

    public function test_clarify_user_help_request_service_porte_l_identite_canonique(): void
    {
        $vu = $this->observerLesTurnIds();

        $interaction = $this->executerClarify('Je cherche de l aide pour cadrer nos usages');

        $this->assertIdentiteVientDuContexteIa($vu, $interaction);
        $this->assertIdentiteSeule($interaction);
        $this->assertLegacyPreservee($interaction, ['requested_by', 'latency_ms', 'provider', 'capability', 'status']);
    }

    public function test_clarify_deux_tours_ont_deux_identites(): void
    {
        // Symetrique des deux autres writers. Sans lui, une CONSTANTE uuid
        // codee en dur passerait pour ce writer — c'est exactement le trou que
        // l'audit delta a releve.
        $premier = $this->executerClarify('Premiere demande');
        $second = $this->executerClarify('Seconde demande');

        $this->assertNotSame($this->identite($premier), $this->identite($second));
    }

    // ────────────────────────────── writer 5 : DossierInsightsService

    public function test_dossier_insights_service_ecrit_exactement_le_turn_id_du_tour(): void
    {
        // Le seul des quatre ou l'egalite est prouvable DIRECTEMENT : le turnId
        // est un parametre explicite, transmis au `ContexteIa` (correction
        // C2-bis du CDC).
        $turnId = (string) Str::uuid();

        $interaction = $this->executerDossierInsights($turnId);

        $this->assertSame($turnId, $this->identite($interaction));
        $this->assertIdentiteSeule($interaction);
        $this->assertLegacyPreservee($interaction, ['dossier_id', 'requested_by', 'latency_ms', 'provider', 'capability', 'status', 'retrieval']);
    }

    // ────────────────────────────── la preuve d'identite

    /**
     * Installe un GENERATEUR-OBSERVATEUR d'uuid.
     *
     * Il ne FIGE rien : il rend de vrais uuid v4, tous distincts, pour que
     * `HasUuids` (cles primaires) et `AiCorrelation` continuent de fonctionner.
     * Figer la fabrique serait d'ailleurs impossible ici — `Str::orderedUuid()`
     * consulte LA MEME fabrique que `Str::uuid()` (`Str.php:1948-1951`), donc
     * un uuid constant ferait collisionner les cles primaires.
     *
     * Son seul role est d'OBSERVER : il note les uuid frappes depuis
     * `ContexteIa::__construct`, c'est-a-dire les identites de tour. La pile est
     * examinee sur 4 niveaux au plus — assez pour voir l'appelant, assez peu
     * pour ne rien couter.
     */
    private function observerLesTurnIds(): object
    {
        $vu = new class
        {
            /** @var list<string> */
            public array $turnIds = [];
        };

        Str::createUuidsUsing(function () use ($vu) {
            $uuid = Uuid::uuid4();

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
                if (($frame['class'] ?? null) === ContexteIa::class) {
                    $vu->turnIds[] = (string) $uuid;

                    break;
                }
            }

            return $uuid;
        });

        return $vu;
    }

    /** L'identite ecrite est-elle bien celle qu'un `ContexteIa` a generee ? */
    private function assertIdentiteVientDuContexteIa(object $vu, AiInteraction $interaction): void
    {
        $this->assertNotEmpty($vu->turnIds, 'le tour doit avoir construit un ContexteIa');

        $this->assertContains(
            $this->identite($interaction),
            $vu->turnIds,
            'turn.id doit etre le turnId du ContexteIa du tour, jamais un uuid frappe ailleurs',
        );
    }

    // ────────────────────────────── assertions communes

    /** Le contrat V0-A d'un writer NON pilote : l'identite, et rien d'autre. */
    private function assertIdentiteSeule(AiInteraction $interaction): void
    {
        $metadata = $interaction->metadata;

        $this->assertArrayHasKey(AiTurnTrace::TURN_METADATA_KEY, $metadata, 'ce writer doit porter le bloc `turn`');

        $turn = $metadata[AiTurnTrace::TURN_METADATA_KEY];

        $this->assertSame(1, $turn['schema']);
        $this->assertTrue(Str::isUuid($turn['id']), '`turn.id` doit etre un uuid');

        // EXACTEMENT ces deux cles — toute semantique supplementaire chez un
        // writer non pilote serait une anticipation de V0-G. Canonicalise :
        // c'est le JEU de cles qui est contractuel, pas leur ordre d'insertion.
        $this->assertEqualsCanonicalizing(['schema', 'id'], array_keys($turn));

        // `turn.id` n'est JAMAIS le `correlation_id` (CDC-01 §5.0.2). On lit
        // l'interaction DEJA isolee par ce tour, jamais une relecture par date :
        // deux tours d'un meme test partagent la seconde.
        $this->assertNotSame((string) $interaction->correlation_id, $turn['id']);
    }

    /**
     * Garde I8 + non-regression des lecteurs : `turn` est la seule cle nouvelle,
     * et rien de ce qui existait n'a disparu.
     *
     * @param  list<string>  $legacy
     */
    private function assertLegacyPreservee(AiInteraction $interaction, array $legacy): void
    {
        $metadata = $interaction->metadata;

        foreach ($legacy as $cle) {
            $this->assertArrayHasKey($cle, $metadata, "la cle legacy `{$cle}` doit survivre a TASK-1566");
        }

        // Les cles qui allumeraient une surface produit, ou qui appartiennent a
        // une TASK ulterieure, n'ont rien a faire au premier niveau.
        $this->assertArrayNotHasKey('execution_path', $metadata);
        $this->assertArrayNotHasKey('steps', $metadata);
        $this->assertArrayNotHasKey('turn_id', $metadata, 'seul le pilote porte la cle historique `turn_id`');
    }

    private function identite(AiInteraction $interaction): string
    {
        return $interaction->metadata[AiTurnTrace::TURN_METADATA_KEY]['id'];
    }

    // ────────────────────────────── harnais par writer

    private function executerChatLoop(string $question): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        AiTurnLock::forgetRequestState();

        $declencheur = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->membre->id,
            'body' => $question,
            'type' => 'text',
        ]);

        LoopDirectAnswerAgent::fake([
            new TextResponse('Voici une reponse.', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);

        app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, $question, $declencheur);

        return $this->interactionNouvelle($deja);
    }

    private function executerShellGeneral(string $question): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        ShellGeneralAnswerAgent::fake([
            new TextResponse('Voici une reponse generale.', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);

        app(ShellGeneralAnswerService::class)->answer($this->organization, $this->membre, $question);

        return $this->interactionNouvelle($deja);
    }

    private function executerClarify(string $phrase): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->update(['is_active' => true]);

        $structure = [
            'title' => 'Cadrer nos usages',
            'clarified_request' => 'Je cherche de l aide pour cadrer nos usages.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse(
                $structure,
                json_encode($structure, JSON_UNESCAPED_UNICODE),
                new Usage(20, 10),
                new Meta('openrouter', 'openai/gpt-4o-mini'),
            ),
        ]);

        app(ClarifyUserHelpRequestService::class)->clarifyForOrganization($this->organization, $this->membre, $phrase);

        return $this->interactionNouvelle($deja);
    }

    private function executerDossierInsights(string $turnId): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Dossier des writers',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        LoopKnowledgeAgent::fake([
            new TextResponse('La reponse documentaire [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);

        app(DossierInsightsService::class)->answerOverSources(
            $this->organization,
            $dossier,
            $this->membre,
            'Que dit le document ?',
            [$this->source($dossier)],
            null,
            $turnId,
        );

        return $this->interactionNouvelle($deja);
    }

    /**
     * L'interaction que CE tour vient d'ecrire, identifiee par difference d'ids
     * et non par `latest()` : deux tours d'un meme test partagent la seconde.
     *
     * @param  list<mixed>  $deja
     */
    private function interactionNouvelle(array $deja): AiInteraction
    {
        $nouvelles = AiInteraction::query()->whereNotIn('id', $deja)->get();

        $this->assertCount(1, $nouvelles, 'un tour doit ecrire EXACTEMENT une interaction');

        return $nouvelles->first();
    }

    /** @return array<string, mixed> */
    private function source(Dossier $dossier): array
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
            'filename' => 'document.docx',
            'mime_type' => 'application/pdf',
            'chunk_index' => 0,
            'content' => 'Le document decrit la procedure.',
            'distance' => 0.31,
        ];
    }
}
