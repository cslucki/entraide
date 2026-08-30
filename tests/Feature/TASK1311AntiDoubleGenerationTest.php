<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiTurnIdempotency;
use App\Support\Ai\AiTurnLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1311 — une intention utilisateur = au maximum UNE execution economique.
 *
 * ## Le probleme, mesure
 *
 * Le mode IA avait un verrou depuis toujours. Les modes Dossiers et
 * IA + Dossiers n'en avaient AUCUN — et ce sont les plus chers : un tour
 * documentaire paie un embedding de requete PUIS une generation. Un double
 * envoi y coutait donc quatre invocations la ou le mode IA en coutait deux.
 *
 * ## Deux mecanismes, deux problemes distincts
 *
 * | Mecanisme | Traite | Cle |
 * |---|---|---|
 * | `AiTurnLock` | la COURSE — double clic, deux onglets, requetes concurrentes | `{organization}:{loop}:{user}` |
 * | `AiTurnIdempotency` | le REJEU — meme tour relance, retry apres re-render | le message declencheur |
 *
 * Ils ne se remplacent pas. Un double clic cree DEUX messages humains
 * distincts : l'idempotence, qui s'appuie sur le declencheur, ne le verrait
 * jamais. Inversement, un rejeu a trois secondes d'intervalle trouve le verrou
 * deja libere.
 *
 * ## Ce que ces tests ne pretendent PAS prouver
 *
 * `phpunit.xml` force `CACHE_STORE=array` — un store PAR PROCESSUS. Aucun test
 * de ce fichier ne prouve donc l'atomicite de `Cache::add()` sous concurrence
 * reelle : ils prouvent que les chemins LISENT le verrou et s'y arretent. La
 * preuve d'atomicite est faite ailleurs, multi-processus, sur un store partage.
 * Confondre les deux serait exactement le « faux test mono-thread » que le
 * brief interdit.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1311AntiDoubleGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $otherMember;

    private User $strangerOwner;

    private Loop $loop;

    private Loop $secondLoop;

    private Loop $foreignLoop;

    private Dossier $dossier;

    private FakeTurnSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['name' => 'LaunchPals', 'slug' => 'launchpals']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Autre Org', 'slug' => 'autre-org']);

        foreach ([$this->organization, $this->otherOrganization] as $org) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $org->id,
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'api_key' => 'sk-or-tenant',
            ]);
        }

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->strangerOwner = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $loopService = new LoopService;

        $this->loop = $loopService->createLoop($this->owner, 'Boucle principale');
        $loopService->addMember($this->loop, $this->member, 'member');
        $loopService->addMember($this->loop, $this->otherMember, 'member');

        $this->secondLoop = $loopService->createLoop($this->owner, 'Seconde Boucle');
        $loopService->addMember($this->secondLoop, $this->member, 'member');

        app()->instance('current_organization', $this->otherOrganization);
        $this->foreignLoop = $loopService->createLoop($this->strangerOwner, 'Boucle d\'une autre Organization');
        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id, $this->otherOrganization->id],
            'ai_pricing.overrides' => [],
            'ai.chatloop.enabled' => true,
            'ai.chatloop.min_summary_words' => 0,
        ]);

        $this->search = new FakeTurnSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. LA COURSE — le verrou tient, sur les TROIS moteurs
    // =====================================================================

    /**
     * Mode IA : un tour deja en cours pour ce membre refuse le second.
     *
     * Le verrou est pose a la main, comme le ferait la requete concurrente :
     * c'est la seule facon honnete de simuler la course dans un test
     * mono-processus. Ce qui est prouve ici, c'est que le chemin S'ARRETE — pas
     * que `Cache::add()` est atomique.
     */
    public function test_the_ia_engine_refuses_a_second_concurrent_turn(): void
    {
        $this->fakeDirect('Reponse IA.');
        Cache::add(AiTurnLock::key($this->loop, $this->member), true, 60);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Une question')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertNoTurnHappened();
    }

    public function test_the_dossiers_engine_refuses_a_second_concurrent_turn(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('D\'apres vos Dossiers [S1].');
        Cache::add(AiTurnLock::key($this->loop, $this->member), true, 60);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Que disent nos documents ?')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertNoTurnHappened();
        // Le chemin le plus cher : meme la RECHERCHE ne doit pas partir.
        $this->assertSame(0, $this->search->calls);
    }

    public function test_the_hybrid_engine_refuses_a_second_concurrent_turn(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('D\'apres vos Dossiers [S1], et par ailleurs.');
        Cache::add(AiTurnLock::key($this->loop, $this->member), true, 60);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia_dossiers')
            ->set('body', 'Que disent nos documents, et par ailleurs ?')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertNoTurnHappened();
        $this->assertSame(0, $this->search->calls);
    }

    /**
     * LE test du contrat produit : « double clic -> 1 message humain ».
     *
     * Le verrou etant pris AVANT la creation du message humain, un tour refuse
     * ne laisse aucune trace dans le fil. Sans cela le fil montrerait deux fois
     * la question de l'utilisateur pour une seule reponse — il mentirait sur ce
     * que la personne a fait.
     */
    public function test_a_refused_turn_never_publishes_a_human_message(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Peu importe.');
        Cache::add(AiTurnLock::key($this->loop, $this->member), true, 60);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertSame(0, LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'user')->count());
    }

    // =====================================================================
    // B. LE REJEU — l'idempotence par message declencheur
    // =====================================================================

    /**
     * Le verrou est libere apres le premier tour. Rejouer le MEME declencheur
     * — retry apres re-render, retour arriere — ne doit pas regenerer.
     */
    public function test_replaying_the_same_trigger_never_generates_a_second_time(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Premiere reponse [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $trigger = LoopMessage::query()->where('type', 'user')->sole();
        $invocationsAfterFirst = $this->invocations();

        // Le verrou est bien retombe : ce n'est PAS lui qui protege ici.
        $this->assertFalse(Cache::has(AiTurnLock::key($this->loop, $this->member)));

        $this->fakeKnowledge('Seconde reponse, qui ne doit jamais exister [S1].');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('loops.ai_turn_already_answered'));

        try {
            app(LoopKnowledgeAnswerService::class)
                ->answer($this->loop, $this->member, 'Ma question', inThreadTrigger: $trigger);
        } finally {
            $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
            $this->assertSame($invocationsAfterFirst, $this->invocations());
        }
    }

    public function test_the_ia_engine_also_refuses_to_replay_an_answered_trigger(): void
    {
        $this->fakeDirect('Reponse IA.');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $trigger = LoopMessage::query()->where('type', 'user')->sole();
        $this->assertTrue(AiTurnIdempotency::alreadyAnswered($trigger));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('loops.ai_turn_already_answered'));

        try {
            app(ChatLoopAiService::class)->respondInThread($this->loop, $this->member, 'Ma question', $trigger);
        } finally {
            $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
        }
    }

    // =====================================================================
    // C. UNICITE ECONOMIQUE — le compte de messages ne prouve pas le cout
    // =====================================================================

    /**
     * Un tour = UNE interaction, UNE serie d'invocations. Le brief l'exige
     * moteur par moteur, et la regle de revue BouclePro rappelle que compter
     * les messages ne prouve rien sur la depense : on compte le LEDGER.
     */
    public function test_one_turn_leaves_exactly_one_interaction_and_one_ledger_series(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Une reponse [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame(1, $this->search->calls);
        $this->assertSame(1, $this->invocations());
    }

    /**
     * Le meme tour tente DEUX fois pendant que le verrou est tenu : le ledger
     * ne bouge pas d'une ligne.
     */
    public function test_a_blocked_duplicate_adds_nothing_to_the_ledger(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeKnowledge('Une reponse [S1].');

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $invocations = $this->invocations();
        $searchCalls = $this->search->calls;

        Cache::add(AiTurnLock::key($this->loop, $this->member), true, 60);

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertSame($invocations, $this->invocations());
        $this->assertSame($searchCalls, $this->search->calls);
        $this->assertSame(1, AiInteraction::query()->count());
    }

    // =====================================================================
    // D. LES CONTRE-EPREUVES — ce qui doit continuer a fonctionner
    // =====================================================================

    /**
     * Deux tours REELLEMENT differents, simultanes : les deux doivent passer.
     *
     * C'est ce cas qui a impose la cle `{organization}:{loop}:{user}`.
     * L'ancienne cle `{loop}` bloquait deux membres l'un l'autre — un effet de
     * bord, jamais une intention. Un verrou qui empeche du travail legitime
     * finit par etre contourne.
     */
    public function test_two_different_members_of_the_same_loop_never_block_each_other(): void
    {
        $this->fakeDirect('Reponse IA.');

        // Le premier membre tient son verrou.
        Cache::add(AiTurnLock::key($this->loop, $this->member), true, 60);

        $this->actingAs($this->otherMember);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Ma propre question')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
    }

    /**
     * Le MEME texte, envoye deux fois volontairement : deux vrais messages,
     * deux vraies reponses. Le texte n'est jamais une cle — deux questions
     * identiques peuvent etre parfaitement intentionnelles.
     */
    public function test_the_same_text_sent_twice_on_purpose_produces_two_real_turns(): void
    {
        $this->fakeDirect('Premiere reponse.', 'Seconde reponse.');

        $this->actingAs($this->member);

        foreach ([1, 2] as $ignored) {
            Livewire::test(LoopChat::class, ['loop' => $this->loop])
                ->call('setComposerMode', 'ia')
                ->set('body', 'Exactement la meme question')
                ->call('sendMessage')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, LoopMessage::query()->where('type', 'user')->count());
        $this->assertSame(2, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame(2, AiInteraction::query()->count());
    }

    public function test_two_loops_of_the_same_organization_hold_independent_locks(): void
    {
        $this->fakeDirect('Reponse IA.');

        Cache::add(AiTurnLock::key($this->loop, $this->member), true, 60);

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->secondLoop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Une question dans l\'autre Boucle')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame(1, LoopMessage::query()->where('loop_id', $this->secondLoop->id)->where('type', 'ai')->count());
        $this->assertSame(0, LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->count());
    }

    /**
     * Deux Organizations ne partagent JAMAIS une cle — et cela se lit dans la
     * cle elle-meme, plutot que de reposer sur l'unicite des identifiants.
     */
    public function test_two_organizations_never_share_a_lock_key(): void
    {
        $mine = AiTurnLock::key($this->loop, $this->member);
        $theirs = AiTurnLock::key($this->foreignLoop, $this->strangerOwner);

        $this->assertNotSame($mine, $theirs);
        $this->assertStringContainsString((string) $this->organization->id, $mine);
        $this->assertStringNotContainsString((string) $this->otherOrganization->id, $mine);
        $this->assertStringContainsString((string) $this->otherOrganization->id, $theirs);
        $this->assertStringNotContainsString((string) $this->organization->id, $theirs);

        // Et la cle porte reellement les trois dimensions annoncees.
        $this->assertStringContainsString((string) $this->loop->id, $mine);
        $this->assertStringContainsString((string) $this->member->id, $mine);
        $this->assertNotSame($mine, AiTurnLock::key($this->loop, $this->otherMember));
    }

    /**
     * Un tour NORMAL n'est pas un tour IA : aucun verrou, aucune depense,
     * aucun embedding. Il doit rester exactement ce qu'il etait.
     */
    public function test_a_normal_turn_stays_free_and_unlocked(): void
    {
        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Un message humain ordinaire')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame(1, LoopMessage::query()->where('type', 'user')->count());
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, $this->invocations());
        $this->assertSame(0, $this->search->calls);
        $this->assertFalse(Cache::has(AiTurnLock::key($this->loop, $this->member)));
    }

    // =====================================================================
    // E. PANNE PROVIDER — un tour ne devient JAMAIS irrecuperable
    // =====================================================================

    /**
     * Si le provider tombe, le verrou doit retomber avec lui.
     *
     * Sans le `finally`, le membre serait gele jusqu'a l'expiration du TTL —
     * 90 secondes pendant lesquelles il ne comprendrait pas pourquoi le fil ne
     * repond plus. Un echec doit rester rejouable.
     */
    public function test_a_provider_failure_releases_the_lock_and_leaves_the_turn_retryable(): void
    {
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake(fn () => throw new RuntimeException('provider down'));

        $this->actingAs($this->member);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasErrors('body');

        // Le verrou est retombe : aucun gel.
        $this->assertFalse(Cache::has(AiTurnLock::key($this->loop, $this->member)));

        // Aucune fausse reponse publiee, et l'echec est trace COMME UN ECHEC —
        // pas efface. La tentative a reellement ete envoyee au provider : elle
        // laisse UNE ligne de ledger, et c'est correct. Ce que la TASK interdit,
        // c'est la SECONDE.
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame('failed', AiInteraction::query()->latest('id')->first()?->metadata['status'] ?? null);
        $this->assertSame(1, $this->invocations());

        // Et un RETRY volontaire repart proprement : le tour n'est pas
        // irrecuperable.
        $this->fakeKnowledge('Cette fois ca marche [S1].');

        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'dossiers')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertSame(1, LoopMessage::query()->where('type', 'ai')->count());
        // Le retry a coute SA generation, pas celle de l'echec en plus.
        $this->assertSame(2, $this->invocations());
    }

    /**
     * Le pendant du precedent, au niveau de la primitive : une exception
     * traversant `run()` ne laisse ni la cle posee, ni l'etat de reentrance.
     */
    public function test_the_primitive_releases_everything_even_when_the_work_throws(): void
    {
        $key = AiTurnLock::key($this->loop, $this->member);

        try {
            AiTurnLock::run($this->loop, $this->member, function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('l\'exception aurait du remonter');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertFalse(Cache::has($key));

        // Et le tour suivant passe : rien n'est reste coince.
        $result = AiTurnLock::run($this->loop, $this->member, fn (): string => 'ok');
        $this->assertSame('ok', $result);
    }

    /**
     * La reentrance intra-requete : le composeur prend le verrou, le service le
     * reprend. Sans elle, tout tour IA echouerait sur son propre verrou.
     *
     * Deux requetes HTTP concurrentes sont deux processus distincts et ne
     * partagent pas cet etat — la course reste arbitree par `Cache::add()`.
     */
    public function test_the_lock_is_reentrant_within_one_request_but_still_exclusive(): void
    {
        $reached = false;

        AiTurnLock::run($this->loop, $this->member, function () use (&$reached): void {
            // Une autre requete, elle, se heurterait au verrou : la cle EST posee.
            $this->assertTrue(Cache::has(AiTurnLock::key($this->loop, $this->member)));

            AiTurnLock::run($this->loop, $this->member, function () use (&$reached): void {
                $reached = true;
            });

            // La reprise interne n'a PAS libere le verrou en sortant.
            $this->assertTrue(Cache::has(AiTurnLock::key($this->loop, $this->member)));
        });

        $this->assertTrue($reached);
        $this->assertFalse(Cache::has(AiTurnLock::key($this->loop, $this->member)));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function assertNoTurnHappened(): void
    {
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count());
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, $this->invocations());
    }

    private function invocations(): int
    {
        return (int) AiProviderInvocation::query()->count();
    }

    private function fakeKnowledge(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function fakeDirect(string ...$texts): void
    {
        LoopDirectAnswerAgent::fake(array_map(
            fn (string $text): TextResponse => new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
            $texts,
        ));
    }

    /** @return array<string, mixed> */
    private function row(string $label): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article '.$label,
            'slug' => 'article-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'content' => "Contenu de l'article {$label}.",
            'distance' => 0.2,
        ];
    }
}

/**
 * Double du moteur pgvector, avec compteur d'appels : c'est lui qui prouve
 * qu'un tour REFUSE ne paie meme pas sa recherche documentaire.
 */
class FakeTurnSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $calls = 0;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null): array
    {
        $this->calls++;

        return array_slice($this->rows, 0, $candidateLimit ?? $limit);
    }
}
