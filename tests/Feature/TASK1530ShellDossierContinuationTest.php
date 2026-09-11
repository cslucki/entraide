<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\CapabilityRegistry;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\ShellGeneralAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1530 — la continuite documentaire survit a la navigation.
 *
 * ## Le defaut ferme
 *
 * Sur la page du Dossier ARIA, « c'est quoi ARIA ? » repondait avec des
 * sources. L'utilisateur changeait de page, et « qui sont les partenaires ? »
 * tombait sur `shell_general_answer` : le Shell se souvenait SEMANTIQUEMENT
 * d'ARIA — le fil suit la personne de page en page (T1523) — mais avait perdu
 * son AUTORITE documentaire. Plus aucun retrieval, donc une reponse de culture
 * generale sur un sujet interne.
 *
 * ## La regle canonique que ces tests mesurent
 *
 * MEMOIRE  -> aide a RETROUVER l'objet, ne prouve jamais rien.
 * POLICY   -> decide s'il est ENCORE accessible, a chaque tour.
 * RETRIEVAL FRAIS -> fournit les faits.
 * SOURCES FRAICHES -> prouvent la reponse.
 *
 * ## Ce que les cas 4 et 5 prouvent — et ce qu'ils ne prouvent PAS
 *
 * Ils mesurent un RESULTAT : aucun tour documentaire, aucun appel provider,
 * aucun titre prive. Ce resultat est tenu par TROIS gardes independantes — le
 * tenant et `DossierPolicy::view` rejoues par la branche, puis
 * `DossierInsightsService::answer()` qui revalide les deux une troisieme fois
 * (tenant en dur ligne 313, policy juste apres) et leve.
 *
 * Mesure faite, et consignee ici pour que personne ne la refasse a l'aveugle :
 * retirer la garde tenant de la branche laisse TOUTE la suite verte ; retirer
 * sa revalidation policy aussi. La couche de service rattrape a chaque fois,
 * inconditionnellement. Les gardes de la branche sont donc de la defense en
 * profondeur REELLE mais NON FALSIFIABLE par le chemin public : aucun test
 * honnete ne peut les isoler tant que `answer()` garde les siennes (la classe
 * est `final`, elle ne se double pas). Un test qui pretendrait le contraire
 * serait vert quoi qu'il arrive — exactement le piege ferme en T1529.
 *
 * Ce qui EST falsifiable ici, et sabote comme tel : la branche elle-meme, la
 * selection du Dossier, et les deux gardes de routage.
 */
class TASK1530ShellDossierContinuationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Dossier $aria;

    private Dossier $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);

        $this->aria = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'ARIA',
            'visibility' => 'private',
        ]);

        $this->autre = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'HORIZON',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1530',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
            // Le test CONSTRUIT son environnement au lieu de l'EMPRUNTER.
            //
            // Sans ces deux lignes, les cinq tests qui lisent un prompt reel
            // passaient en local et rougissaient en CI. Cause mesuree, pas
            // supposee : en local ces commutateurs viennent de `.env` ; la CI
            // n'a pas de `.env` et retombe sur les defauts de `config/ai.php`,
            // qui eteignent le Shell et la clarification. Le tour se termine
            // alors sans appel fournisseur, donc sans `AiInteraction` — et les
            // assertions de prompt n'avaient plus rien a lire.
            //
            // Reproduction : un worktree SANS `.env`, meme configuration
            // `phpunit.ci-sqlite.xml`. Ni le shard 2 reconstitue a l'identique
            // (1838 tests, comme la CI) ni l'execution isolee ne montraient
            // quoi que ce soit tant que `.env` etait la.
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
        ]);

        Http::preventStrayRequests();

        $this->searchReturnsChunkOfRequestedDossier();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Reponse documentaire. [S1]', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        ShellGeneralAnswerAgent::fake(fn (): TextResponse => new TextResponse(
            'Reponse generale sans source interne.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $structured = [
            'title' => 'Titre', 'clarified_request' => 'Demande clarifiee.', 'help_type' => 'information',
            'suggested_loop_id' => '', 'suggested_category_id' => '', 'suggestion_reason' => '',
            'questions_for_user' => [], 'confidence' => 0.9, 'needs_human_review' => false,
        ];
        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured, json_encode($structured, JSON_UNESCAPED_UNICODE), new Usage(120, 80), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    /** Le retrieval rend un extrait du Dossier REELLEMENT interroge. */
    private function searchReturnsChunkOfRequestedDossier(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturnUsing(function (string $orgId, array $dossierIds): array {
            $dossier = Dossier::findOrFail($dossierIds[0]);

            return [[
                'chunk_id' => (string) Str::uuid(), 'dossier_id' => $dossier->id, 'dossier_name' => $dossier->name,
                'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
                'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'chunk_index' => 1, 'content' => 'Extrait frais du Dossier '.$dossier->name.'.', 'distance' => 0.2,
            ]];
        })->byDefault();
    }

    /** Le retrieval ne trouve rien : le cas « zero source ». */
    private function searchReturnsNothing(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn([])->byDefault();
    }

    /** Un tour tenu SUR la page d'un Dossier, avec la garde de cette page. */
    private function sendOnDossier(Dossier $dossier, string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->member, $this->organization, AiShellPageContext::KIND_DOSSIER, $dossier->id,
        );

        $this->actingAs($this->member);

        return app(AiShellResponder::class)->respond($this->organization, $this->member, $question, $context)['answer'];
    }

    /** Un tour tenu sur une page NEUTRE : aucune autorite documentaire. */
    private function sendOnNeutralPage(string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve($this->member, $this->organization, null, null);

        $this->assertNotSame(AiShellPageContext::KIND_DOSSIER, $context['kind'] ?? null,
            'la premisse du test : la page courante n est pas un Dossier');

        $this->actingAs($this->member);

        return app(AiShellResponder::class)->respond($this->organization, $this->member, $question, $context)['answer'];
    }

    /** @return list<AiInteraction> les appels documentaires, du plus ancien au plus recent */
    private function documentaryInteractions(): array
    {
        return AiInteraction::query()->where('feature', 'loop_knowledge_answer')
            ->orderBy('created_at')->orderBy('id')->get()->all();
    }

    // ── CAS 1 — le cas reel ARIA ────────────────────────────────────────────

    /**
     * LE test de cette TASK : apres navigation, une continuation refait un
     * retrieval FRAIS sur le Dossier repris et repond avec ses sources.
     *
     * Sabotage : retirer la branche de continuite → rouge.
     */
    public function test_a_continuation_after_navigation_runs_a_fresh_retrieval_on_the_recovered_dossier(): void
    {
        $tour1 = $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $this->assertSame('dossier.answer', $tour1->metadata['producer']);
        $this->assertNotSame([], $tour1->metadata['sources'], 'le tour 1 est bien sourcé');
        $this->assertCount(1, $this->documentaryInteractions());

        $tour2 = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $tour2->metadata['producer'],
            'la continuation doit produire un tour documentaire, pas une reponse generale');
        $this->assertTrue($tour2->metadata['continuation']);

        // Un retrieval FRAIS a bien eu lieu : deuxieme appel documentaire ...
        $appels = $this->documentaryInteractions();
        $this->assertCount(2, $appels, 'la memoire ne remplace pas le retrieval : un nouvel appel est exige');

        // ... et ses sources sont celles d'ARIA, obtenues a ce tour-ci.
        $this->assertStringContainsString('Extrait frais du Dossier ARIA.', (string) $appels[1]->prompt);
        $this->assertNotSame([], $tour2->metadata['sources']);

        // L'objet trace est le Dossier repris : le tour suivant le retrouvera.
        $this->assertSame(AiShellPageContext::KIND_DOSSIER, $tour2->metadata['page_context']['object_type']);
        $this->assertSame((string) $this->aria->id, $tour2->metadata['page_context']['object_id']);
    }

    // ── CAS 2 — une question generale n'est jamais capturee ─────────────────

    /**
     * Sabotage : retirer la garde « article indefini / concept produit » →
     * rouge.
     */
    public function test_a_general_product_question_after_a_dossier_is_not_captured(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        $reponse = $this->sendOnNeutralPage('Quelle est la difference entre une Boucle et une Organization ?');

        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer'],
            'une question sur le produit appartient au chemin general');
        $this->assertCount($avant, $this->documentaryInteractions(),
            'aucun retrieval documentaire ne doit etre declenche');
        $this->assertArrayNotHasKey('sources', $reponse->metadata);
    }

    // ── CAS 3 — l'intention d'aide humaine reste protegee ───────────────────

    /**
     * Sabotage : ne plus consulter `mentionsInteractionIntent()` dans la
     * continuite → rouge.
     */
    public function test_a_human_help_request_after_a_dossier_is_not_captured(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        $reponse = $this->sendOnNeutralPage('Quelqu\'un peut m\'aider a trouver un expert ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer'],
            'une ancienne page Dossier ne vole jamais une demande d aide humaine');
        $this->assertNotSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer'],
            'et elle ne part pas non plus sur le chemin general');
        $this->assertCount($avant, $this->documentaryInteractions());
    }

    // ── CAS 4 — droit retire entre les deux tours ───────────────────────────

    /**
     * Sabotage : retirer la revalidation `DossierPolicy::view` → rouge.
     */
    public function test_a_dossier_that_became_inaccessible_is_dropped_silently(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        // Le Dossier change de main : le membre n'y a plus acces.
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->aria->forceFill(['owner_id' => $etranger->id, 'visibility' => 'private'])->save();
        $this->assertTrue($this->member->cannot('view', $this->aria->fresh()), 'premisse : l acces est bien retire');

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);
        $this->assertCount($avant, $this->documentaryInteractions(),
            'aucun appel provider documentaire sur un Dossier devenu inaccessible');
        $this->assertStringNotContainsString('ARIA', $reponse->content,
            'aucun titre prive revele');
    }

    /** Un Dossier supprime se comporte comme un Dossier interdit : fail-closed. */
    public function test_a_deleted_dossier_is_dropped_silently(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $avant = count($this->documentaryInteractions());

        $this->aria->delete();

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);
        $this->assertCount($avant, $this->documentaryInteractions());
    }

    // ── CAS 5 — cross tenant ────────────────────────────────────────────────

    /**
     * Un objet historique d'une AUTRE Organization, ecrit dans le fil, n'est
     * jamais repris — meme si la policy locale se laissait convaincre.
     *
     * Sabotage : retirer la comparaison `organization_id` → rouge.
     */
    public function test_a_dossier_from_another_organization_is_never_recovered(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $autreMembre = User::factory()->create(['organization_id' => $autreOrg->id]);
        $etranger = Dossier::create([
            'organization_id' => $autreOrg->id,
            'owner_id' => $autreMembre->id,
            'name' => 'DOSSIER ETRANGER',
            'visibility' => 'organization',
        ]);

        // Un tour du fil designe ce Dossier etranger : contexte forge.
        $thread = app(AiShellThread::class);
        $page = ['page_context' => [
            'route' => 'dossiers.show', 'kind' => AiShellPageContext::KIND_DOSSIER,
            'object_type' => AiShellPageContext::KIND_DOSSIER, 'object_id' => (string) $etranger->id,
        ]];
        $trigger = $thread->appendUser($this->organization, $this->member, 'Question posee ailleurs.', $page);
        $thread->appendAssistant($this->organization, $this->member, 'Reponse.', $trigger,
            $page + ['status' => AiShellResponder::STATUS_ANSWERED]);

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);
        $this->assertSame([], $this->documentaryInteractions(),
            'aucun appel provider documentaire sur un objet d une autre Organization');
        $this->assertStringNotContainsString('DOSSIER ETRANGER', $reponse->content);
    }

    // ── CAS 6 — A puis B : la continuation repart de B ──────────────────────

    /**
     * Sabotage : parcourir le fil du plus ANCIEN au plus recent → rouge.
     */
    public function test_the_continuation_resumes_the_most_recent_dossier_not_the_first(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $this->sendOnDossier($this->autre, 'C\'est quoi HORIZON ?');

        $tour3 = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $tour3->metadata['producer']);
        $this->assertSame((string) $this->autre->id, $tour3->metadata['page_context']['object_id'],
            'le Dossier repris est le plus recent');

        $appels = $this->documentaryInteractions();
        $dernier = (string) end($appels)->prompt;

        $this->assertStringContainsString('Extrait frais du Dossier HORIZON.', $dernier);
        $this->assertStringNotContainsString('Extrait frais du Dossier ARIA.', $dernier,
            'A ne pollue pas la continuation de B');
    }

    // ── CAS 7 — la memoire n'est jamais une source ──────────────────────────

    /**
     * Le texte d'une ancienne reponse assistant ne devient jamais une source
     * documentaire : il peut aider a retrouver l'objet, jamais a prouver un
     * fait.
     */
    public function test_memory_never_becomes_a_documentary_source(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $tour2 = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $sources = json_encode($tour2->metadata['sources'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Reponse documentaire.', (string) $sources,
            'la reponse precedente de l assistant n est pas une source');
        $this->assertStringContainsString('note.docx', (string) $sources,
            'les sources sont bien les documents du Dossier, obtenus a ce tour');
    }

    // ── CAS 8 — zero source : aucune invention ──────────────────────────────

    /**
     * Sans document consulte, la branche documentaire se tait et laisse le
     * chemin general repondre honnetement — elle ne fabrique rien depuis la
     * memoire.
     */
    public function test_a_continuation_without_any_retrieved_source_falls_back_honestly(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        // A partir d'ici, le corpus ne rend plus rien.
        $this->searchReturnsNothing();

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer'],
            'sans source consultee, la branche documentaire s efface');
        $this->assertArrayNotHasKey('sources', $reponse->metadata,
            'aucune source inventee depuis la memoire');
    }

    // ── Le repli general ne transporte pas le Dossier revoque ───────────────

    /**
     * Le prompt REELLEMENT envoye au fournisseur general de ce tour.
     */
    private function lastGeneralPrompt(): string
    {
        $interaction = AiInteraction::query()->where('feature', CapabilityRegistry::SHELL_GENERAL_ANSWER)
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->assertNotNull($interaction, 'aucun appel general trace');

        return (string) $interaction->prompt;
    }

    /**
     * LA preuve manquante, relevee en review du diff.
     *
     * Refuser la continuation ne suffit pas. Quand la branche s'efface, le tour
     * retombe sur `generalAnswerTurn()`, qui recoit `$generalMemory` — et cette
     * memoire-la n'est filtree NI par objet (`$onlyObjectKey` vaut null) ni par
     * producteur : le filtre de contrat T1528 ne retire que les anciennes
     * reponses GENERALES. Un tour `dossier.answer` (STATUS_NON_INTERACTION) y
     * entre donc intact.
     *
     * Consequence si rien n'est fait : le contenu d'un Dossier dont l'acces
     * vient d'etre RETIRE repart vers le fournisseur general, en clair, dans le
     * bloc conversation. La memoire cesse d'etre un signal pour devenir un
     * canal de fuite apres revocation.
     *
     * Sabotage : reintroduire la memoire documentaire dans ce repli → rouge.
     */
    public function test_a_revoked_dossier_never_reaches_the_general_provider_through_memory(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Les partenaires sont ACMEPRIVE et ZORGLUBPRIVE. [S1]',
            new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $tour1 = $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');
        $this->assertStringContainsString('ACMEPRIVE', $tour1->content, 'premisse : le fait prive est bien dans le tour 1');

        // L'acces est retire entre les deux tours.
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->aria->forceFill(['owner_id' => $etranger->id, 'visibility' => 'private'])->save();
        $this->assertTrue($this->member->cannot('view', $this->aria->fresh()), 'premisse : l acces est bien retire');

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        // La continuation est bien refusee ...
        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);

        // ... et surtout : rien du Dossier revoque n'atteint le fournisseur.
        $prompt = $this->lastGeneralPrompt();

        $this->assertStringNotContainsString('ACMEPRIVE', $prompt,
            'un fait du Dossier revoque ne doit pas repartir vers le fournisseur general');
        $this->assertStringNotContainsString('ZORGLUBPRIVE', $prompt);
        $this->assertStringNotContainsString('Les partenaires sont', $prompt,
            'la phrase de l ancienne reponse documentaire ne subsiste pas');
        $this->assertStringNotContainsString('ACMEPRIVE', $reponse->content);

        // Le titre « ARIA » subsiste par un SEUL chemin : la question que la
        // personne a tapee elle-meme. Mesure faite, et c'est la frontiere
        // voulue — ce qui est retire, c'est ce que l'ASSISTANT a restitue du
        // Dossier ; ce que la personne a ecrit lui appartient, elle le connait
        // deja, et l'effacer casserait sa conversation sans rien proteger.
        $this->assertStringContainsString('C\'est quoi ARIA', $prompt,
            'les messages de la personne restent');
    }

    /**
     * CAS 3 du MASTER — une question GENERALE posee apres un tour documentaire,
     * l'acces au Dossier etant toujours INTACT.
     *
     * Rien n'est revoque, rien n'est refuse : c'est le canal lui-meme qui est
     * mesure. La capability `shell_general_answer` declare
     * `allowedSources: [SOURCE_PRODUCT_SURFACES]` — aucune source documentaire.
     * Un ancien tour `dossier.answer` qui entre dans son prompt par la memoire
     * contourne ce contrat sans qu'aucune garde ne le voie passer.
     */
    public function test_a_general_question_never_carries_previous_documentary_facts(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Les partenaires sont ACMEPRIVE et ZORGLUBPRIVE. [S1]',
            new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $reponse = $this->sendOnNeutralPage('Quelle est la difference entre une Boucle et une Organization ?');

        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer']);

        $prompt = $this->lastGeneralPrompt();

        $this->assertStringNotContainsString('ACMEPRIVE', $prompt,
            'un fait documentaire ne devient jamais le contexte d une reponse generale');
        $this->assertStringNotContainsString('ZORGLUBPRIVE', $prompt);
        $this->assertStringNotContainsString('Les partenaires sont', $prompt);

        // La continuite du dialogue humain, elle, est preservee.
        $this->assertStringContainsString('C\'est quoi ARIA', $prompt,
            'les messages de la personne restent : c est sa conversation');
    }

    /**
     * La revocation ne depend pas du ROUTAGE.
     *
     * Ici la question suivante n'est PAS une continuation — c'est une question
     * produit, qui part directement sur le chemin general sans que la branche
     * de continuite soit seulement tentee. L'exclusion de l'objet d'une
     * continuation refusee ne joue donc pas : seule la relecture des droits
     * dans la memoire empeche le contenu du Dossier revoque de repartir.
     *
     * Sabotage : retirer `documentaryTurnStillVisible()` → rouge.
     */
    public function test_a_revoked_dossier_does_not_leak_through_a_plain_general_question(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Les partenaires sont ACMEPRIVE et ZORGLUBPRIVE. [S1]',
            new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->aria->forceFill(['owner_id' => $etranger->id, 'visibility' => 'private'])->save();
        $this->assertTrue($this->member->cannot('view', $this->aria->fresh()), 'premisse : l acces est bien retire');

        $reponse = $this->sendOnNeutralPage('Quelle est la difference entre une Boucle et une Organization ?');

        $this->assertSame(ShellGeneralAnswerService::PRODUCER, $reponse->metadata['producer'],
            'premisse : ce tour part bien sur le chemin general, sans tentative de continuation');

        $prompt = $this->lastGeneralPrompt();

        $this->assertStringNotContainsString('ACMEPRIVE', $prompt,
            'le contenu d un Dossier revoque ne repart vers aucun fournisseur, quel que soit le routage');
        $this->assertStringNotContainsString('ZORGLUBPRIVE', $prompt);
        $this->assertStringNotContainsString('Les partenaires sont', $prompt,
            'la phrase de l ancienne reponse documentaire ne subsiste pas davantage');

        // Ce qui RESTE, et c'est voulu : la question que la personne a tapee
        // elle-meme. Mesure faite — le nom « ARIA » subsiste par ce seul
        // chemin. Ce n'est pas une divulgation : elle l'a ecrit, elle le
        // connait deja. Retirer ses propres mots de son propre fil ne
        // protegerait rien et casserait la conversation. Seul ce que
        // l'ASSISTANT a restitue du Dossier est retire.
        $this->assertStringContainsString('C\'est quoi ARIA', $prompt,
            'la personne garde ses propres messages');
    }

    /**
     * L'autre porte : `clarify_help_request`.
     *
     * La garde de producteur ci-dessus ne protege QUE la capability generale.
     * Or le chemin historique — `generate()`, la clarification d'entraide —
     * est le repli par defaut de la majorite des tours, et il recoit la
     * memoire NON filtree. Sans relecture des droits, le contenu d'un Dossier
     * revoque y repartait aussi : fermer la porte de devant en laissant celle
     * de derriere ouverte n'aurait rien ferme du tout.
     *
     * Sabotage : retirer `documentaryTurnStillVisible()` → rouge.
     */
    public function test_a_revoked_dossier_does_not_leak_through_the_help_request_path(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Les partenaires sont ACMEPRIVE et ZORGLUBPRIVE. [S1]',
            new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->aria->forceFill(['owner_id' => $etranger->id, 'visibility' => 'private'])->save();
        $this->assertTrue($this->member->cannot('view', $this->aria->fresh()), 'premisse : l acces est bien retire');

        // Enonce d'entraide : il part sur `clarify_help_request`, pas sur le
        // chemin general — c'est tout l'interet de ce test.
        $this->sendOnNeutralPage('Quelqu\'un peut m\'aider a trouver un expert ?');

        $interaction = AiInteraction::query()->where('feature', 'clarify_help_request')
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->assertNotNull($interaction, 'premisse : ce tour passe bien par la clarification d entraide');

        $this->assertStringNotContainsString('ACMEPRIVE', (string) $interaction->prompt,
            'le contenu d un Dossier revoque ne repart pas davantage par le chemin d entraide');
        $this->assertStringNotContainsString('ZORGLUBPRIVE', (string) $interaction->prompt);
    }

    /**
     * Le pendant du cas 8 : sans source fraiche, le repli general ne doit pas
     * pouvoir repondre a la question documentaire depuis l'ancienne reponse.
     * Ici l'acces n'est PAS retire — ce n'est donc pas une fuite, c'est la
     * regle « la memoire n'est jamais une preuve » qui est mesuree.
     */
    public function test_the_general_fallback_cannot_answer_from_a_previous_documentary_answer(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Les partenaires sont ACMEPRIVE et ZORGLUBPRIVE. [S1]',
            new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        // Plus aucune source fraiche : la branche documentaire s'efface.
        $this->searchReturnsNothing();

        $reponse = $this->sendOnNeutralPage('Qui sont les partenaires ?');

        $this->assertNotSame(AiShellResponder::PRODUCER_DOSSIER_CONTINUATION, $reponse->metadata['producer']);
        $this->assertStringNotContainsString('ACMEPRIVE', $this->lastGeneralPrompt(),
            'les faits d une ancienne reponse documentaire ne sont pas un contexte pour repondre a nouveau');
    }

    // ── La page courante garde la main ──────────────────────────────────────

    /**
     * Sur la page d'un Dossier, c'est CE Dossier qui repond, jamais un
     * Dossier repris du fil : la branche courante passe avant.
     */
    public function test_the_current_dossier_page_keeps_priority_over_the_thread(): void
    {
        $this->sendOnDossier($this->aria, 'C\'est quoi ARIA ?');

        $tour2 = $this->sendOnDossier($this->autre, 'Qui sont les partenaires ?');

        $this->assertSame('dossier.answer', $tour2->metadata['producer'],
            'la page courante reste l autorite documentaire prioritaire');
        $this->assertSame((string) $this->autre->id, $tour2->metadata['page_context']['object_id']);
    }
}
