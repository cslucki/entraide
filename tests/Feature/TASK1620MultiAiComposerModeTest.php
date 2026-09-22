<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopMultiAiAgent;
use App\Ai\CapabilityRegistry;
use App\Livewire\LoopChat;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopPluginAiModels;
use App\Services\Ai\OpenRouterModelCatalog;
use App\Services\Loops\LoopPluginActivation;
use App\Services\Loops\LoopPluginAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1620 — le mode 3 IA ne genere qu'au SUBMIT.
 *
 * Le defaut constate par Cyril sur test.laravel : cliquer « Demander a Aperio »
 * lisait le composeur et GENERAIT aussitot. Le message humain n'avait pas ete
 * soumis, et « 3 assistants IA reflechit… » pouvait apparaitre sur un texte
 * que personne n'avait envoye — trois generations payees pour un brouillon.
 *
 * La cause etait une erreur de conception de TASK-1619 : j'avais fait des
 * assistants des ACTIONS, la ou le composeur n'a jamais eu qu'un MODE et un
 * declencheur — le submit.
 *
 * Ce fichier mesure quatre choses :
 *
 *  1. **armer ne genere rien.** Zero invocation, zero interaction, zero
 *     message, composeur intact. C'est le test qui aurait rougi ;
 *  2. **le submit est le SEUL declencheur**, et il publie UN message humain ;
 *  3. **le mode est ONE-SHOT** : le message suivant ne coute pas trois
 *     generations par surprise ;
 *  4. **le composeur n'a plus qu'une action** — les trois boutons par
 *     assistant ont disparu.
 */
class TASK1620MultiAiComposerModeTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN = 'multi_ai_assistants';

    private const MODELES = [
        'aperio' => 'vendor/modele-a',
        'traverse' => 'vendor/modele-t',
        'limen' => 'vendor/modele-l',
    ];

    private Organization $organization;

    private User $superAdmin;

    private User $owner;

    private User $facilitator;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);

        $this->organization = Organization::factory()->create([
            'name' => 'Alpha 1619', 'is_active' => true, 'loops_enabled' => true, 'locale' => 'fr',
            'loop_composition_policy' => 'owner_allowed',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-task1619',
            'monthly_budget_usd' => null,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->organization->id, 'preferred_locale' => 'fr',
        ]);
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->facilitator = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->owner->id,
            'status' => 'active',
            'type' => 'general',
        ]);

        $this->adhesion($this->owner, 'owner');
        $this->adhesion($this->facilitator, 'facilitator');
        $this->adhesion($this->membre, 'member');

        foreach (['Le budget du projet ARIA est arrete a 40 000 euros.',
            'La livraison est prevue pour mars, apres la phase de tests.'] as $texte) {
            LoopMessage::factory()->create([
                'loop_id' => $this->loop->id, 'sender_id' => $this->membre->id,
                'body' => $texte, 'type' => 'user',
            ]);
        }

        AdminAiPrompt::create([
            'scenario_id' => 'loop_multi_ai', 'name' => 'Socle (banc)', 'description' => 'Banc TASK-1619',
            'version' => 1, 'is_active' => true,
            'prompt_text' => 'SOCLE PLATEFORME : tu ne decides jamais a la place du groupe.',
        ]);

        app()->instance('current_organization', $this->organization);

        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->organization, true, $this->superAdmin);
        app(LoopPluginActivation::class)
            ->setEnabled(self::PLUGIN, $this->loop, true, $this->owner);

        $this->catalogueEtModeles();
    }

    // ── 1. ARMER NE GENERE RIEN — le test qui aurait rougi ──────────────────

    public function test_armer_le_mode_ne_declenche_aucune_generation(): void
    {
        // LE test du defaut constate. Question ECRITE, mode ACTIVE, PAS de
        // submit : il ne doit strictement rien se passer.
        $this->fakeTroisReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode');

        $this->assertSame(0, AiProviderInvocation::query()->count(), 'aucun appel provider');
        $this->assertSame(0, AiInteraction::query()->count(), 'aucune interaction');
        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count(), 'aucune reponse');
        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)
            ->where('body', 'Est-ce que Dieu existe ?')->count(), 'aucun message humain publie');

        // Et le texte du membre est INTACT : armer un mode n'est pas envoyer.
        $composant->assertSet('body', 'Est-ce que Dieu existe ?')
            ->assertSet('composerMode', 'multi_ai');
    }

    public function test_armer_puis_desarmer_ne_laisse_aucune_trace(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Une question.')
            ->call('toggleMultiAiMode')
            ->assertSet('composerMode', 'multi_ai')
            ->call('toggleMultiAiMode')
            ->assertSet('composerMode', 'normal');

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_le_mode_est_exclusif_des_autres_moteurs(): void
    {
        // Trois assistants EN PLUS d'un moteur documentaire seraient quatre
        // generations pour un envoi, et personne ne l'a demande.
        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleComposerEngine', 'ia')
            ->assertSet('composerMode', 'ia')
            ->call('toggleMultiAiMode')
            ->assertSet('composerMode', 'multi_ai');
    }

    // ── 2. LE SUBMIT EST LE SEUL DECLENCHEUR ────────────────────────────────

    public function test_le_submit_publie_un_seul_message_humain_puis_orchestre(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Est-ce que Dieu existe ?')->count(),
            'UN message humain, jamais deux');

        $this->assertSame(3, AiProviderInvocation::query()->count(), 'trois assistants, trois appels');
        $this->assertSame(3, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
    }

    public function test_les_reponses_repondent_au_message_humain_soumis(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $question = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')
            ->where('body', 'Est-ce que Dieu existe ?')->first();

        $bulles = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->get();

        $this->assertCount(3, $bulles);
        foreach ($bulles as $bulle) {
            $this->assertSame((string) $question->id, (string) $bulle->reply_to_id);
        }
    }

    public function test_l_ordre_existant_est_preserve(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(['aperio', 'traverse', 'limen'],
            LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')
                ->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (LoopMessage $m): string => $m->metadata['assistant_key'])->all());
    }

    public function test_aucun_quatrieme_appel_cache(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(3, AiProviderInvocation::query()->count());
        $this->assertSame([CapabilityRegistry::LOOP_MULTI_AI],
            AiProviderInvocation::query()->pluck('capability')->unique()->values()->all());
    }

    // ── 3. LE MODE EST ONE-SHOT ─────────────────────────────────────────────

    public function test_le_mode_se_desarme_apres_le_submit(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSet('composerMode', 'normal');
    }

    public function test_le_message_suivant_ne_coute_pas_trois_generations(): void
    {
        $this->fakeTroisReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $apresPremier = AiProviderInvocation::query()->count();

        $composant->set('body', 'Un simple message.')->call('sendMessage');

        $this->assertSame($apresPremier, AiProviderInvocation::query()->count(),
            'un membre qui a demande trois regards une fois n\'a pas demande a en payer trois a chaque phrase');
    }

    public function test_le_mode_se_desarme_meme_si_tout_echoue(): void
    {
        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) {
            throw new RateLimitedException('sature');
        });

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSet('composerMode', 'normal',
                'un mode reste arme apres un echec rejouerait trois generations au message suivant');
    }

    // ── 4. LE COMPOSEUR N'A PLUS QU'UNE ACTION ──────────────────────────────

    public function test_les_trois_boutons_par_assistant_ont_disparu(): void
    {
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        foreach (['aperio', 'traverse', 'limen'] as $cle) {
            $composant->assertDontSeeHtml('data-multi-ai-ask="'.$cle.'"');
        }

        $composant->assertSeeHtml('data-multi-ai-mode');
    }

    public function test_l_etat_arme_se_lit(): void
    {
        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-armed')
            ->call('toggleMultiAiMode')
            ->assertSeeHtml('data-multi-ai-armed')
            ->assertSee(__('loops.plugins_multi_ai_armed'));
    }

    public function test_le_lien_de_configuration_a_quitte_le_composeur(): void
    {
        Livewire::actingAs($this->owner)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-configure');
    }

    public function test_le_mode_n_est_pas_armable_si_le_plugin_est_eteint(): void
    {
        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->loop, false, $this->owner);

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleMultiAiMode')
            ->assertSet('composerMode', 'normal');
    }

    // ── 5. CE QUI NE DOIT PAS AVOIR BOUGE ───────────────────────────────────

    public function test_sans_mode_le_comportement_chatloop_est_inchange(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Un message humain tout simple.')
            ->call('sendMessage');

        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('body', 'Un message humain tout simple.')->count());
    }

    public function test_un_message_vide_ne_genere_rien(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '   ')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_un_double_submit_ne_duplique_rien(): void
    {
        $this->fakeTroisReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        // Le second envoi part sur un composeur VIDE et un mode desarme :
        // les deux gardes se cumulent.
        $composant->call('sendMessage');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Est-ce que Dieu existe ?')->count());
        $this->assertSame(3, AiProviderInvocation::query()->count());
    }

    public function test_un_echec_partiel_conserve_les_reussites(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSeeHtml('data-multi-ai-status="rate_limited"')
            ->assertSeeHtml('data-multi-ai-retry="traverse"');

        $this->assertSame(2, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
    }

    public function test_le_reessai_reste_local_au_resultat(): void
    {
        $this->fakeAvecSaturation('traverse');

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->fakeTroisReponses();
        $composant->call('retryAssistant', 'traverse');

        // Le reessai n'a pas republie la question, et n'a pas rearme le mode.
        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Est-ce que Dieu existe ?')->count());
        $composant->assertSet('composerMode', 'normal');
    }

    public function test_aucun_code_technique_n_atteint_l_interface(): void
    {
        $this->fakeAvecSaturation('traverse');

        $rendu = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Est-ce que Dieu existe ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->html();

        foreach (['PROVIDER_CALL_FAILED', 'RATE_LIMITED', 'upstream_provider_shared_pool', 'RateLimitedException'] as $fuite) {
            $this->assertStringNotContainsString($fuite, $rendu);
        }
    }

    // ── Outils du banc ──────────────────────────────────────────────────────

    private function fakeTroisReponses(): void
    {
        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            'Reponse de '.$model, new Usage(20, 10), new Meta('openrouter', $model),
        ));
    }

    private function fakeAvecSaturation(string $assistantKey): void
    {
        $sature = self::MODELES[$assistantKey];

        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) use ($sature) {
            if ($model === $sature) {
                throw new RateLimitedException('sature en amont');
            }

            return new TextResponse('Reponse de '.$model, new Usage(20, 10), new Meta('openrouter', $model));
        });
    }

    private function fakeAvecFollowUps(): void
    {
        $titre = __('dossiers.answer_follow_ups_heading');

        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            "Le budget est de 40 000 euros.\n\n## ".$titre."\n- Qui a valide ce budget ?\n- Quelles sont les etapes de mars ?",
            new Usage(20, 10), new Meta('openrouter', $model),
        ));
    }

    private function adhesion(User $user, string $role): void
    {
        LoopMember::create([
            'loop_id' => $this->loop->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
    }

    private function catalogueEtModeles(): void
    {
        Http::swap(new Factory);
        Http::fake(['*/models' => Http::response(['data' => array_map(
            fn (string $slug): array => [
                'id' => $slug, 'name' => 'Modele '.$slug, 'context_length' => 32768,
                'pricing' => ['prompt' => '0', 'completion' => '0', 'request' => '0'],
                'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            ],
            array_values(self::MODELES),
        )], 200)]);

        foreach (self::MODELES as $key => $slug) {
            app(LoopPluginAiModels::class)->assign($key, $slug, $this->superAdmin);
        }
    }
}
