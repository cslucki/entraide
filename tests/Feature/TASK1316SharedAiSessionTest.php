<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Livewire\LoopChat;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
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
use Tests\TestCase;

/**
 * TASK-1316 — Shared AI Session V1 : le groupe travaille avec la MEME IA, dans
 * la MEME Boucle, et voit qui lui parle.
 *
 * ## Ce que cette TASK n'a pas construit
 *
 * Aucune table de session, aucune migration, aucun WebSocket, aucun second
 * moteur de conversation. ChatLoop EST deja une session IA partagee : un fil
 * unique, des messages `type = 'ai'` sans expediteur, un `reply_to_id` vers le
 * declencheur humain, un `metadata.requested_by` ecrit depuis TASK-1233, et un
 * cout deja attribuable dans `ai_interactions`. Il manquait deux choses, et
 * deux seulement : que l'attribution SE VOIE, et que l'attente SE PARTAGE.
 *
 * ## L'audit qui precede le signal
 *
 * `ai/scripts/audit-1316-signal-authority.php` a mesure, sur deux processus
 * reels, ce que `AiTurnLock` peut et ne peut pas dire :
 *
 * | Question | `AiTurnLock` | Messages persistes |
 * |---|---|---|
 * | qui a demande ? | seulement si on le sait deja (c'est la CLE) | `sender_id` |
 * | quel mode ? | rien — la valeur stockee est `true` | `metadata.requested_mode` |
 * | depuis quand ? | rien | `created_at` |
 * | est-ce fini ? | rien (il ne sait que « rendue / pas rendue ») | reponse `reply_to_id` |
 * | tourne-t-il encore ? | **oui, et lui seul** | non |
 * | cout d'une lecture | O(membres) par battement de poll | 0 requete sans candidat |
 *
 * D'ou la repartition : les messages disent QUI et QUOI, le verrou dit
 * ENCORE. Les tests ci-dessous verifient les deux moities separement, parce
 * qu'un signal qui apparait sans jamais disparaitre serait pire que pas de
 * signal du tout.
 *
 * ## Ce que ces tests ne pretendent PAS prouver
 *
 * `phpunit.xml` force `CACHE_STORE=array` : un store PAR PROCESSUS. Rien ici
 * ne prouve la concurrence reelle — la preuve cross-processus est faite par le
 * script d'audit, sur le store `database` du banc. Ces tests prouvent que le
 * rendu LIT les bonnes autorites et s'arrete aux bonnes frontieres.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1316SharedAiSessionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $alice;

    private User $bob;

    private User $stranger;

    private User $nonMember;

    private Loop $loop;

    private Loop $secondLoop;

    private Loop $foreignLoop;

    private Dossier $dossier;

    private FakeSharedSessionSearch $search;

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

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Aubry']);
        $this->bob = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bob Barral']);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Nina Dehors']);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id, 'name' => 'Sam Ailleurs']);

        app()->instance('current_organization', $this->organization);
        $loops = new LoopService;

        $this->loop = $loops->createLoop($this->alice, 'Boucle partagee');
        $loops->addMember($this->loop, $this->bob, 'member');

        $this->secondLoop = $loops->createLoop($this->alice, 'Seconde Boucle');
        $loops->addMember($this->secondLoop, $this->bob, 'member');

        app()->instance('current_organization', $this->otherOrganization);
        $this->foreignLoop = $loops->createLoop($this->stranger, 'Boucle d\'ailleurs');
        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->alice->id,
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

        $this->search = new FakeSharedSessionSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. ATTRIBUTION — « demande par », depuis la donnee, et VISIBLE
    // =====================================================================

    /**
     * Alice demande, Bob lit. Le fil doit nommer Alice — pas Bob, pas « un
     * membre », pas l'IA. C'est la difference entre une reponse tombee du ciel
     * et un tour de parole dans un groupe.
     */
    public function test_a_shared_answer_names_the_exact_human_who_asked_for_it(): void
    {
        $this->fakeDirect('La reponse partagee.');

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Ma question au groupe')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-ai-requested-by="'.$this->alice->id.'"')
            ->assertDontSeeHtml('data-ai-requested-by="'.$this->bob->id.'"')
            ->assertSee(__('loops.ai_requested_by', ['name' => $this->alice->publicDisplayName()]));
    }

    /**
     * LE defaut que cette TASK corrige, et sa preuve par mutation.
     *
     * L'attribution etait CONCATENEE au sous-titre du mode, dans un `<span>`
     * porteur de `truncate`. Sur mobile, « Reponse croisee entre l'IA et les
     * connaissances de cette Boucle » consomme deja toute la largeur : le
     * « Demande par ... » qui suivait etait coupe par le navigateur, sans
     * qu'aucune assertion serveur ne s'en apercoive — le HTML le contenait.
     *
     * Ce test echoue si quelqu'un recolle les deux : il interdit la FORME
     * concatenee, pas seulement la presence du texte.
     */
    public function test_the_attribution_no_longer_hides_inside_the_truncated_subtitle(): void
    {
        $this->message('ai', null, 'Reponse hybride.', [
            'requested_by' => $this->alice->id,
            'ai_mode' => 'llm_rag',
        ]);

        $concatenated = __('loops.hybrid_bubble_subtitle')
            .' · '.__('loops.ai_requested_by', ['name' => $this->alice->publicDisplayName()]);

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.hybrid_bubble_subtitle'))
            ->assertSee(__('loops.ai_requested_by', ['name' => $this->alice->publicDisplayName()]))
            // La forme fusionnee, elle, ne doit plus exister nulle part.
            ->assertDontSee($concatenated);
    }

    /**
     * L'attribution vient de `metadata.requested_by`, JAMAIS d'une lecture du
     * texte. Le corps ment ici volontairement : il nomme Bob. Le marqueur doit
     * porter Alice.
     */
    public function test_the_attribution_never_parses_the_message_body(): void
    {
        $this->message('ai', null, 'Demande par '.$this->bob->publicDisplayName().' — enfin, c\'est ce que dit ce texte.', [
            'requested_by' => $this->alice->id,
            'ai_mode' => 'llm',
        ]);

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-ai-requested-by="'.$this->alice->id.'"')
            ->assertDontSeeHtml('data-ai-requested-by="'.$this->bob->id.'"');
    }

    /** Les trois moteurs attribuent, ou aucun ne le fait vraiment. */
    public function test_the_three_engines_all_attribute_their_answer_to_the_requester(): void
    {
        foreach (['ia', 'dossiers', 'ia_dossiers'] as $mode) {
            $this->search->rows = [$this->row('A')];
            $this->fakeDirect('Reponse IA.');
            $this->fakeKnowledge('Reponse documentaire [S1].');

            $this->actingAs($this->alice);
            Livewire::test(LoopChat::class, ['loop' => $this->loop])
                ->call('setComposerMode', $mode)
                ->set('body', 'Question en mode '.$mode)
                ->call('sendMessage')
                ->assertHasNoErrors();
        }

        $answers = LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->get();

        $this->assertCount(3, $answers);
        foreach ($answers as $answer) {
            $this->assertSame($this->alice->id, $answer->metadata['requested_by'] ?? null);
            $this->assertNotNull($answer->reply_to_id, 'Une reponse partagee est toujours rattachee a son declencheur.');
        }

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-ai-mode="llm"')
            ->assertSeeHtml('data-ai-mode="rag"')
            ->assertSeeHtml('data-ai-mode="llm_rag"')
            ->assertSeeHtml('data-ai-requested-by="'.$this->alice->id.'"');
    }

    // =====================================================================
    // B. LE SIGNAL PARTAGE — il apparait, et surtout il DISPARAIT
    // =====================================================================

    /**
     * Alice a envoye sa question ; sa generation tourne encore. Bob, qui
     * n'a rien fait, doit voir que quelque chose arrive — et de qui.
     */
    public function test_another_member_sees_that_an_ai_turn_is_running(): void
    {
        $trigger = $this->pendingTurn($this->alice, 'ia_dossiers');

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-ai-turn-pending="'.$trigger->id.'"')
            ->assertSeeHtml('data-ai-turn-requester="'.$this->alice->id.'"')
            ->assertSeeHtml('data-ai-turn-mode="llm_rag"')
            ->assertSee(__('loops.ai_turn_in_progress', [
                'ai' => $this->organization->name.' · '.__('loops.hybrid_mode_label'),
                'name' => $this->alice->publicDisplayName(),
            ]));
    }

    /**
     * Pas de faux streaming : tant que la reponse n'existe pas, le fil ne
     * contient AUCUNE bulle IA. Le signal annonce une attente, il ne simule
     * pas un texte en train de s'ecrire.
     */
    public function test_the_signal_shows_no_fabricated_answer(): void
    {
        $this->pendingTurn($this->alice, 'ia');

        $this->assertSame(0, LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->count());

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('data-ai-turn-pending')
            ->assertDontSeeHtml('data-ai-mode=');
    }

    /**
     * Fin normale : la reponse est publiee. Le signal doit s'effacer — et il
     * s'efface parce que la condition de fin est celle d'`AiTurnIdempotency`,
     * pas une seconde regle ecrite pour l'affichage.
     */
    public function test_the_signal_disappears_as_soon_as_the_answer_lands(): void
    {
        $trigger = $this->pendingTurn($this->alice, 'ia');

        $this->message('ai', null, 'Et voici la reponse.', [
            'requested_by' => $this->alice->id,
            'ai_mode' => 'llm',
        ], $trigger->id);

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-ai-turn-pending')
            ->assertSee('Et voici la reponse.');
    }

    /**
     * Fin ANORMALE — panne provider, refus economique, reponse vide. Aucune
     * reponse ne sera jamais publiee pour ce declencheur : sur les messages
     * seuls, le signal resterait affiche.
     *
     * C'est exactement ce que le verrou sait, et lui seul : son `finally`
     * l'a rendu. Le signal s'efface au battement suivant.
     */
    public function test_the_signal_disappears_when_the_turn_released_its_lock(): void
    {
        $trigger = $this->pendingTurn($this->alice, 'dossiers');

        // Le `finally` d'AiTurnLock::run() — celui qui s'execute meme sur
        // AiRefusedException et sur panne provider.
        Cache::forget(AiTurnLock::key($this->loop, $this->alice));

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-ai-turn-pending')
            ->assertSee($trigger->body);
    }

    /**
     * Le cas que le verrou seul ne couvre pas : fatal PHP, timeout FPM, kill.
     * Le `finally` ne s'execute pas et la cle survit jusqu'a son TTL — un
     * verrou FANTOME.
     *
     * La borne de temps du signal est `AiTurnLock::ttl()`, appelee et non
     * recopiee : au-dela, plus aucun tour ne peut legitimement tourner, donc
     * plus aucun signal. Sans elle, un fantome afficherait « IA travaille »
     * pendant 90 secondes apres la mort du tour.
     */
    public function test_the_signal_expires_with_the_turn_window_even_if_the_lock_is_a_ghost(): void
    {
        $trigger = $this->pendingTurn($this->alice, 'ia');

        // `created_at` n'est pas fillable : il se pose ainsi, jamais par create().
        $trigger->forceFill(['created_at' => now()->subSeconds(AiTurnLock::ttl() + 5)])->saveQuietly();

        // Le verrou, lui, est toujours la : personne ne l'a rendu.
        $this->assertTrue(Cache::has(AiTurnLock::key($this->loop, $this->alice)));

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-ai-turn-pending');
    }

    /**
     * Un message ordinaire ne demande rien a l'IA. Meme si un verrou traine
     * par ailleurs pour cette personne, il ne doit lever aucun signal : c'est
     * `requested_mode` qui fait foi, pas la presence d'une cle.
     */
    public function test_an_ordinary_message_never_raises_the_signal(): void
    {
        $this->message('user', $this->alice, 'Bonjour tout le monde.');
        Cache::add(AiTurnLock::key($this->loop, $this->alice), true, 60);

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-ai-turn-pending');
    }

    /**
     * Le signal annonce le mode que la reponse PORTERA (`ai_mode`, T1312).
     * Annoncer « Dossiers » puis publier « IA » serait une promesse trahie.
     */
    public function test_the_signal_announces_the_mode_the_answer_will_carry(): void
    {
        foreach (['ia' => 'llm', 'dossiers' => 'rag', 'ia_dossiers' => 'llm_rag'] as $requested => $aiMode) {
            $trigger = $this->pendingTurn($this->alice, $requested);

            $this->actingAs($this->bob);
            Livewire::test(LoopChat::class, ['loop' => $this->loop])
                ->assertSeeHtml('data-ai-turn-mode="'.$aiMode.'"');

            $trigger->forceDelete();
            Cache::forget(AiTurnLock::key($this->loop, $this->alice));
        }
    }

    // =====================================================================
    // C. LES FRONTIERES — Boucle, Organization, adhesion
    // =====================================================================

    /** Loop != Tenant : un tour en cours ici n'existe pas la-bas. */
    public function test_a_pending_turn_never_leaks_into_another_loop(): void
    {
        $trigger = $this->pendingTurn($this->alice, 'ia');

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->secondLoop])
            ->assertDontSeeHtml('data-ai-turn-pending="'.$trigger->id.'"')
            ->assertDontSeeHtml('data-ai-turn-requester="'.$this->alice->id.'"');
    }

    /** Organization = Tenant : la frontiere la plus dure de toutes. */
    public function test_a_pending_turn_never_crosses_organizations(): void
    {
        $trigger = $this->pendingTurn($this->alice, 'ia');

        $this->actingAs($this->stranger);
        Livewire::test(LoopChat::class, ['loop' => $this->foreignLoop])
            ->assertDontSeeHtml('data-ai-turn-pending')
            ->assertDontSee($this->alice->publicDisplayName())
            ->assertDontSee($trigger->body);
    }

    /** Sans adhesion, rien ne se lit — le signal compris. */
    public function test_a_non_member_sees_neither_the_thread_nor_the_signal(): void
    {
        $trigger = $this->pendingTurn($this->alice, 'ia');

        $this->actingAs($this->nonMember);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-ai-turn-pending')
            ->assertDontSee($trigger->body);
    }

    // =====================================================================
    // D. T1311 PRESERVE — la concurrence reste arbitree comme avant
    // =====================================================================

    /**
     * Le double clic d'UNE personne reste refuse : un tour deja en cours pour
     * ce membre bloque le second, et aucun message humain n'est publie.
     */
    public function test_the_same_member_double_submitting_is_still_refused(): void
    {
        $this->fakeDirect('Peu importe.');
        Cache::add(AiTurnLock::key($this->loop, $this->alice), true, 60);

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Ma question')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertSame(0, LoopMessage::query()->where('loop_id', $this->loop->id)->count());
    }

    /**
     * Deux PERSONNES differentes sont deux tours reellement differents : la
     * cle `{org}:{loop}:{user}` les autorise (T1311), et le fil reste unique.
     * Bob voit le tour d'Alice pendant que le sien aboutit.
     */
    public function test_two_different_members_may_run_their_turns_at_the_same_time(): void
    {
        $aliceTrigger = $this->pendingTurn($this->alice, 'ia');
        $this->fakeDirect('Reponse pour Bob.');

        $this->actingAs($this->bob);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('setComposerMode', 'ia')
            ->set('body', 'Ma propre question')
            ->call('sendMessage')
            ->assertHasNoErrors()
            // Le tour d'Alice, toujours en cours, reste visible pour Bob.
            ->assertSeeHtml('data-ai-turn-pending="'.$aliceTrigger->id.'"')
            // Et sa reponse a lui est attribuee a lui.
            ->assertSeeHtml('data-ai-requested-by="'.$this->bob->id.'"');

        // Un seul fil, partage : les deux tours y vivent ensemble.
        $this->assertSame(
            1,
            LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->count(),
        );
        $this->assertDatabaseHas('loop_messages', ['id' => $aliceTrigger->id, 'loop_id' => $this->loop->id]);
    }

    // =====================================================================
    // E. LES TROIS MODES — non-regression du fil partage
    // =====================================================================

    public function test_the_three_engines_still_publish_into_the_single_shared_thread(): void
    {
        $expected = ['ia' => 'llm', 'dossiers' => 'rag', 'ia_dossiers' => 'llm_rag'];

        foreach ($expected as $mode => $aiMode) {
            $this->search->rows = [$this->row('A')];
            $this->fakeDirect('Reponse IA.');
            $this->fakeKnowledge('Reponse documentaire [S1].');

            $this->actingAs($this->alice);
            Livewire::test(LoopChat::class, ['loop' => $this->loop])
                ->call('setComposerMode', $mode)
                ->set('body', 'Question '.$mode)
                ->call('sendMessage')
                ->assertHasNoErrors();

            $answer = LoopMessage::query()
                ->where('loop_id', $this->loop->id)
                ->where('type', 'ai')
                ->latest('created_at')->latest('id')->first();

            $this->assertNotNull($answer, "Le mode {$mode} n'a rien publie.");
            $this->assertSame($aiMode, $answer->metadata['ai_mode'] ?? null);
            $this->assertNull($answer->sender_id, 'Une reponse IA n\'a jamais d\'expediteur humain.');
            $this->assertSame($this->loop->organization_id, $answer->organization_id);
        }

        // Un seul fil : trois questions, trois reponses, aucune duplication.
        $this->assertSame(3, LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'ai')->count());
        $this->assertSame(3, LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'user')->count());
    }

    // =====================================================================
    // Utilitaires
    // =====================================================================

    /**
     * Un tour EN COURS, dans l'etat exact ou une requete concurrente le
     * laisserait : le message humain deja commite (il l'est avant toute
     * generation), et le verrou de ce membre pose.
     */
    private function pendingTurn(User $requester, string $mode): LoopMessage
    {
        $trigger = $this->message('user', $requester, 'Question en attente ('.$mode.')', [
            'requested_mode' => $mode,
        ]);

        Cache::add(AiTurnLock::key($this->loop, $requester), true, AiTurnLock::ttl());

        return $trigger;
    }

    private function message(string $type, ?User $sender, string $body, ?array $metadata = null, ?string $replyToId = null): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $sender?->id,
            'reply_to_id' => $replyToId,
            'body' => $body,
            'type' => $type,
            'metadata' => $metadata,
            'organization_id' => $this->loop->organization_id,
        ]);
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

/** Double du moteur pgvector : aucun appel reel, aucune depense. */
class FakeSharedSessionSearch extends DossierSemanticSearchService
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
