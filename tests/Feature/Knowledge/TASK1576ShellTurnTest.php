<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use App\Support\Ai\AiCapabilityCatalogue;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiSelfKnowledge;
use App\Support\Ai\AiTruthLabel;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1576 / CDC-01 V0-I — « inspect-turn explique le Shell ».
 *
 * A. une branche zero-provider compose SON bloc `turn` (identite honnete,
 *    aucun provider, `execution_path` reserve V0-G enfin persiste).
 * B. les declins du tour (C20, option 1) : `metadata.fallthroughs` sur la
 *    ligne assistant, codes du registre, `[]` mesure, hors du bloc `turn`.
 * C. `input_message_id` (C21) la ou le declencheur est en main.
 * D. EXPLAIN `--shell-message` : suit `ai_interaction_id`, sinon lit le bloc
 *    local ; tenant ; `--surface=shell` explain-only.
 */
#[Group('ai')]
class TASK1576ShellTurnTest extends TestCase
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

        $this->organization = Organization::factory()->create([
            'is_active' => true, 'slug' => 'org-1576', 'loops_enabled' => true,
            'members_can_create_loops' => true, 'ai_profiles_enabled' => true,
        ]);
        app()->instance('current_organization', $this->organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-1576',
        ]);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle 1576');

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. le bloc turn zero-provider

    public function test_a1_la_self_knowledge_compose_son_propre_tour_sans_provider(): void
    {
        $reponse = $this->envoyer("C'est quoi BouclePro ?");
        $turn = $reponse->metadata['turn'];

        $this->assertSame(0, AiInteraction::query()->count(), 'zero-provider : aucune interaction');
        $this->assertSame(AiTurnTrace::SCHEMA_VERSION, $turn['schema']);
        $this->assertNotSame('', (string) $turn['id']);
        // `ai_shell_messages.metadata` est jsonb (CDC-01 C5) : PostgreSQL ne
        // conserve pas l'ordre des cles. On compare le CONTENU, pas l'ordre.
        $this->assertEqualsCanonicalizing([
            'surface' => 'ai_shell',
            'mode' => 'zero_provider',
            'execution_path' => AiExecutionPath::AI_SHELL_SELF_KNOWLEDGE,
            'producer' => AiSelfKnowledge::PRODUCER,
            'provider_effective' => 'none',
            'fallback_used' => false,
        ], $turn['identity']);
        $this->assertSame('answered', $turn['status']);
        $this->assertSame(
            [['conversation_history', 'not_applicable'], ['context_builder', 'not_applicable'], ['provider_call', 'not_applicable']],
            array_map(fn (array $s): array => [$s['name'], $s['status']], $turn['steps']),
        );
        $this->assertSame('not_applicable', $turn['state']['verification_status']);
        $this->assertNull($turn['state']['degraded_reason']);
        $this->assertSame([], $turn['sources']['used']);
    }

    public function test_a2_deux_tours_zero_provider_ont_deux_identites(): void
    {
        $a = $this->envoyer("C'est quoi BouclePro ?");
        $b = $this->envoyer("C'est quoi une Boucle ?");

        $this->assertNotSame($a->metadata['turn']['id'], $b->metadata['turn']['id']);
    }

    // ────────────────────────────── B. les declins (C20)

    public function test_b1_un_tour_zero_provider_immediat_n_a_aucun_declin_et_le_dit(): void
    {
        $reponse = $this->envoyer("C'est quoi BouclePro ?");

        // La self-knowledge est la PREMIERE branche : personne n'a decline
        // avant elle. `[]` est une mesure, pas une absence.
        $this->assertSame([], $reponse->metadata['fallthroughs']);
    }

    public function test_b2_les_declins_sont_ecrits_dans_l_ordre_reel_avec_des_codes_du_registre(): void
    {
        ShellGeneralAnswerAgent::fake([new TextResponse('Voici.', new Usage(80, 30), new Meta('openai', 'gpt-4o-mini'))]);

        // Une question generale, hors de toute page objet : chaque branche
        // documentaire decline avant que la branche generale reponde.
        $reponse = $this->envoyer('Combien de temps dure une relecture ?');

        $this->assertSame('shell.general_answer', $reponse->metadata['producer']);
        $declins = $reponse->metadata['fallthroughs'];

        $this->assertSame(
            ['self_knowledge', 'people', 'dossier_answer', 'article_answer', 'dossier_continuation', 'reference_resolution', 'dossier_discovery'],
            array_column($declins, 'branch'),
            'l\'ordre des declins est l\'ordre de la chaine',
        );
        foreach ($declins as $declin) {
            $this->assertContains($declin['status'], ['skipped', 'failed']);
            $this->assertTrue(AiTurnReason::isKnown($declin['reason_code']), $declin['reason_code'].' hors registre');
        }
        $this->assertSame(AiTurnReason::FALLTHROUGH_BRANCH_SHAPE_NOT_MATCHED, $declins[0]['reason_code']);
        $this->assertSame(AiTurnReason::FALLTHROUGH_BRANCH_SHAPE_NOT_MATCHED, $declins[2]['reason_code'], 'dossier_answer : pas une page Dossier');
        $this->assertSame(AiTurnReason::FALLTHROUGH_NO_REFERENCE_CANDIDATE, $declins[5]['reason_code']);

        // Les declins vivent sur la LIGNE, jamais dans le bloc `turn` gele de
        // l'interaction (la branche generale a un moteur : son `turn` est a lui).
        $interaction = AiInteraction::query()->firstOrFail();
        $this->assertArrayNotHasKey('fallthroughs', $interaction->metadata['turn']);
        $this->assertSame((string) $interaction->id, $reponse->metadata['ai_interaction_id']);
    }

    public function test_b3_une_branche_qui_leve_decline_en_failed(): void
    {
        $this->app->bind(AiCapabilityCatalogue::class, fn () => new class extends AiCapabilityCatalogue
        {
            public function forMember(Organization $organization, User $user): array
            {
                throw new \RuntimeException('Catalogue indisponible.');
            }
        });
        ShellGeneralAnswerAgent::fake([new TextResponse('Voici.', new Usage(80, 30), new Meta('openai', 'gpt-4o-mini'))]);

        $reponse = $this->envoyer('Que puis-je faire ici ?');

        $this->assertSame('shell.general_answer', $reponse->metadata['producer']);
        $this->assertEqualsCanonicalizing(
            ['branch' => 'self_knowledge', 'status' => 'failed', 'reason_code' => AiTurnReason::FALLTHROUGH_ENGINE_EXCEPTION],
            $reponse->metadata['fallthroughs'][0],
        );
    }

    // ────────────────────────────── C. input_message_id (C21)

    public function test_c1_le_shell_nomme_le_message_qui_a_declenche_le_tour(): void
    {
        $reponse = $this->envoyer("C'est quoi BouclePro ?");

        $this->assertSame((string) $reponse->reply_to_id, $reponse->metadata['turn']['history']['input_message_id']);
        $this->assertNull($reponse->metadata['turn']['history']['trigger_id'], 'trigger_id = message auquel on repondait : aucun ici');
    }

    public function test_c2_loopchat_nomme_le_message_qui_a_declenche_le_tour(): void
    {
        $declencheur = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Quelle heure ?', 'type' => 'text']);
        LoopDirectAnswerAgent::fake([new TextResponse('Midi.', new Usage(20, 10), new Meta('openai', 'gpt-4o-mini'))]);

        $interaction = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Quelle heure ?', $declencheur, publish: false);

        $history = $interaction->metadata['turn']['history'];
        $this->assertSame((string) $declencheur->id, $history['input_message_id']);
        $this->assertNull($history['trigger_id']);
    }

    // ────────────────────────────── D. EXPLAIN --shell-message

    public function test_d1_une_ligne_zero_provider_s_explique_depuis_son_bloc_local(): void
    {
        $reponse = $this->envoyer("C'est quoi BouclePro ?");
        $trace = $this->expliquer($reponse);

        $this->assertSame('explain', $trace['mode']);
        $this->assertSame($reponse->metadata['turn']['id'], $trace['run']['turn_id']);
        $this->assertSame('turn.id', $trace['run']['turn_id_source']);
        $this->assertNull($trace['run']['ai_interaction_id']);
        $this->assertSame(AiExecutionPath::AI_SHELL_SELF_KNOWLEDGE, $trace['identity']['execution_path']);
        $this->assertSame('answered', $trace['decision']['status']);
        $this->assertSame('not_applicable', $trace['state']['verification_status']);
        $this->assertSame((string) $reponse->id, $trace['shell']['message_id']);
        $this->assertSame([], $trace['shell']['fallthroughs']);
        $this->assertNull($trace['retrieval_trace']);
        $this->assertNull($trace['provider']['input_tokens'], 'aucun provider : UNAVAILABLE, pas 0');

        // Les labels suivent : ce qui n'existe pas pour ce support est UNAVAILABLE.
        $this->assertSame(AiTruthLabel::DECLARED, $trace['truth']['identity.execution_path']);
        $this->assertSame(AiTruthLabel::MEASURED, $trace['truth']['identity.provider_effective']);
        $this->assertSame(AiTruthLabel::MEASURED, $trace['truth']['shell.fallthroughs']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $trace['truth']['provider.input_tokens']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $trace['truth']['retrieval_trace']);
    }

    public function test_d2_une_ligne_a_moteur_suit_son_interaction_et_garde_ses_declins(): void
    {
        ShellGeneralAnswerAgent::fake([new TextResponse('Voici.', new Usage(80, 30), new Meta('openai', 'gpt-4o-mini'))]);
        $reponse = $this->envoyer('Combien de temps dure une relecture ?');
        $interaction = AiInteraction::query()->firstOrFail();

        $trace = $this->expliquer($reponse);

        // Le tour est celui de l'INTERACTION (moteur general)…
        $this->assertSame((string) $interaction->id, $trace['run']['ai_interaction_id']);
        $this->assertSame(AiExecutionPath::AI_SHELL_GENERAL, $trace['identity']['execution_path']);
        $this->assertSame($interaction->metadata['turn']['id'], $trace['run']['turn_id']);
        // … et la ligne Shell apporte ce qu'elle seule sait.
        $this->assertSame((string) $reponse->id, $trace['shell']['message_id']);
        $this->assertCount(7, $trace['shell']['fallthroughs']);

        // Le meme tour lu par --interaction n'a pas de section Shell : rien
        // n'est reconstruit dans l'autre sens.
        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $interaction->id, '--json' => true]);
        $this->assertSame(0, $code);
        $this->assertNull(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['shell']);
    }

    public function test_d3_le_tenant_borne_la_ligne_et_le_lien(): void
    {
        $reponse = $this->envoyer("C'est quoi BouclePro ?");
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1576']);

        $this->artisan('ai:inspect-turn', ['--organization' => $ailleurs->slug, '--shell-message' => (string) $reponse->id, '--json' => true])
            ->expectsOutputToContain('"refused": true')
            ->assertExitCode(1);

        // Une ligne humaine n'est pas un tour.
        $humain = AiShellMessage::query()->whereKey($reponse->reply_to_id)->firstOrFail();
        $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, '--shell-message' => (string) $humain->id, '--json' => true])
            ->expectsOutputToContain('"refused": true')
            ->assertExitCode(1);
    }

    public function test_d3bis_un_lien_vers_l_interaction_d_un_autre_tenant_n_est_jamais_suivi(): void
    {
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1576-b']);
        $etranger = User::factory()->create(['organization_id' => $ailleurs->id]);
        $interactionEtrangere = AiInteraction::create([
            'user_id' => $etranger->id, 'organization_id' => $ailleurs->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'shell.general_answer', 'feature' => 'ai_shell', 'model' => 'x', 'prompt' => 'p', 'response' => 'SECRET D AILLEURS',
            'input_tokens' => 1, 'output_tokens' => 1,
            'metadata' => ['turn' => ['schema' => 1, 'id' => 'turn-etranger', 'identity' => ['execution_path' => AiExecutionPath::AI_SHELL_GENERAL]]],
        ]);

        // Une ligne de CE tenant dont le lien pointe ailleurs (donnee
        // corrompue ou forgee) : la ligne se lit seule, l'interaction jamais.
        $ligne = AiShellMessage::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->membre->id, 'conversation_id' => (string) Str::uuid(),
            'role' => AiShellMessage::ROLE_ASSISTANT, 'content' => 'Reponse.',
            'metadata' => ['status' => AiShellResponder::STATUS_NON_INTERACTION, 'producer' => 'shell.general_answer', 'ai_interaction_id' => (string) $interactionEtrangere->id, 'fallthroughs' => []],
        ]);

        $trace = $this->expliquer($ligne);

        $this->assertNull($trace['identity'], 'l\'identite du tour etranger n\'est pas rendue');
        $this->assertNull($trace['run']['turn_id']);
        $this->assertStringNotContainsString('SECRET D AILLEURS', json_encode($trace, JSON_THROW_ON_ERROR));
        $this->assertSame((string) $interactionEtrangere->id, $trace['shell']['ai_interaction_id'], 'le lien est rendu tel quel, pas suivi');
    }

    public function test_d4_le_shell_est_explain_only_en_execute(): void
    {
        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email,
            '--surface' => 'shell', '--question' => 'Bonjour ?', '--json' => true,
        ])->expectsOutputToContain('explain-only')->assertExitCode(1);

        $this->assertSame(0, AiShellMessage::query()->count());
    }

    // ────────────────────────────── fixtures

    private function envoyer(string $question): AiShellMessage
    {
        Livewire::actingAs($this->membre)->test(AiShell::class)->set('draft', $question)->call('send');

        $reponse = AiShellMessage::query()
            ->where('organization_id', $this->organization->id)
            ->where('user_id', $this->membre->id)
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->orderByDesc('created_at')
            ->first();

        $this->assertInstanceOf(AiShellMessage::class, $reponse);
        $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $reponse->metadata['status']);

        return $reponse;
    }

    /** @return array<string, mixed> */
    private function expliquer(AiShellMessage $ligne): array
    {
        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, '--shell-message' => (string) $ligne->id, '--json' => true]);
        $sortie = Artisan::output();

        $this->assertSame(0, $code, 'la commande a refuse : '.$sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }
}
