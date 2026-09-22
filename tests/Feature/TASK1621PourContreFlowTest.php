<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopMultiAiAgent;
use App\Ai\Context\LoopMessagesSource;
use App\Ai\Context\SourceFragment;
use App\Ai\ContexteIa;
use App\Ai\MultiAssistant\MultiAssistantRun;
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
use App\Services\Ai\LoopMultiAiOrchestrator;
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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1621 — le FLUX « Pour / Contre » dans ChatLoop.
 *
 * Le moteur a son propre banc ; celui-ci mesure le chemin que parcourt un
 * membre, et les deux promesses que MASTER a posees :
 *
 *  1. **rien ne part avant ENVOYER** — ni au clic sur l'action, ni a
 *     l'activation depuis la modale ;
 *  2. **le membre voit son message AVANT d'attendre les IA.** C'est le
 *     decouplage : `sendMessage()` publie et rend la main, la generation
 *     part sur une requete DIFFEREE (`wire:init`), un role a la fois.
 *
 * Plus les gardes du pivot : deux generations, jamais trois ; aucun RAG ;
 * aucune synthese ; aucun follow-up ; vocabulaire produit a l'ecran.
 */
class TASK1621PourContreFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN = 'multi_ai_assistants';

    private const MODELE_ORGANIZATION = 'openai/gpt-4o-mini';

    /** Les slugs du banc — JAMAIS ceux du pilote : un test ne fige pas un choix d'ecran. */
    private const MODELES = [
        'aperio' => 'vendor/modele-a',
        'traverse' => 'vendor/modele-t',
        'limen' => 'vendor/modele-l',
    ];

    private Organization $organization;

    private Organization $ailleurs;

    private User $superAdmin;

    private User $owner;

    private User $membre;

    private User $etranger;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);

        $this->organization = Organization::factory()->create([
            'name' => 'Alpha 1618', 'is_active' => true, 'loops_enabled' => true, 'locale' => 'fr',
        ]);
        $this->ailleurs = Organization::factory()->create([
            'name' => 'Beta 1618', 'is_active' => true, 'loops_enabled' => true,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => self::MODELE_ORGANIZATION,
            'api_key' => 'sk-or-task1618',
            'monthly_budget_usd' => null,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->organization->id, 'preferred_locale' => 'fr',
        ]);
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->etranger = User::factory()->create(['organization_id' => $this->ailleurs->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->owner->id,
            'status' => 'active',
            'type' => 'general',
        ]);

        $this->adhesion($this->loop, $this->owner, 'owner');
        $this->adhesion($this->loop, $this->membre, 'member');

        // De la matiere reelle : sans messages, la source rend un fragment vide
        // et les tests de provenance mesureraient l'absence de Boucle, pas le
        // partage des preuves.
        foreach (['Le budget du projet ARIA est arrete a 40 000 euros.',
            'La livraison est prevue pour mars, apres la phase de tests.',
            'Nous avons ecarte la sous-traitance pour la partie front.'] as $texte) {
            LoopMessage::factory()->create([
                'loop_id' => $this->loop->id,
                'sender_id' => $this->membre->id,
                'body' => $texte,
                'type' => 'user',
            ]);
        }

        // Le prompt administrable de la capability. Sans lui, le tour refuse
        // AVANT toute depense — et c'est un comportement voulu, mesure plus bas.
        AdminAiPrompt::create([
            'scenario_id' => 'loop_multi_ai',
            'name' => 'Socle multi-assistants (banc)',
            'description' => 'Banc TASK-1618',
            'version' => 1,
            'is_active' => true,
            'prompt_text' => "SOCLE PLATEFORME : tu ne decides jamais a la place du groupe et tu n'inventes aucun fait.",
        ]);

        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->organization, true, $this->superAdmin);
        app(LoopPluginActivation::class)
            ->setEnabled(self::PLUGIN, $this->loop, true, $this->owner);

        $this->catalogueEtModeles();
    }

    // ── 1. RIEN NE PART AVANT « ENVOYER » ───────────────────────────────────

    public function test_le_bouton_est_un_interrupteur_et_aucune_modale_ne_subsiste(): void
    {
        // TASK-1621 — la modale a ete retiree : un ecran a confirmer coutait un
        // geste a chaque envoi pour une phrase qu'une infobulle porte aussi
        // bien. Le bouton EST l'interrupteur.
        $this->fakeDeuxReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->assertSeeHtml('data-multi-ai-toggle')
            ->assertDontSeeHtml('data-pour-contre-modal')
            ->assertDontSeeHtml('bp-open-pour-contre')
            ->assertSee(__('loops.plugins_multi_ai_hint'))
            ->assertSet('composerMode', 'normal');

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_armer_le_mode_ne_genere_rien(): void
    {
        // LE test du defaut d'origine, transpose au nouveau geste.
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode');

        $this->assertSame(0, AiProviderInvocation::query()->count(), 'aucun appel provider');
        $this->assertSame(0, AiInteraction::query()->count(), 'aucune interaction');
        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)
            ->where('body', 'Faut-il tout automatiser ?')->count(), 'aucun message humain publie');

        $composant->assertSet('body', 'Faut-il tout automatiser ?')
            ->assertSet('composerMode', 'multi_ai')
            ->assertSet('pourContreQueue', []);
    }

    public function test_l_etat_arme_se_lit_sur_le_bouton_et_nulle_part_ailleurs(): void
    {
        // TASK-1621 — le badge d'activation separe a disparu. Deux surfaces
        // pour un meme etat, c'est deux occasions qu'elles se contredisent :
        // l'etat vit sur le bouton, en `aria-pressed`.
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        $composant->assertSeeHtml('aria-pressed="false"')
            ->assertDontSeeHtml('data-multi-ai-armed');

        $composant->call('toggleMultiAiMode')
            ->assertSeeHtml('aria-pressed="true"')
            ->assertDontSeeHtml('data-multi-ai-armed')
            ->assertDontSeeHtml('data-multi-ai-disarm');

        // Et le re-clic desarme, sans rien declencher.
        $composant->call('toggleMultiAiMode')
            ->assertSet('composerMode', 'normal')
            ->assertSeeHtml('aria-pressed="false"');

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_aucune_pastille_de_mode_vide_ne_double_le_badge(): void
    {
        // Constat de Cyril en recette : activer « Pour / Contre » faisait
        // apparaitre DEUX indicateurs — le badge nomme, et la pastille de mode
        // du composeur, qui ne connait pas `multi_ai` et sortait donc VIDE,
        // reduite a son bouton ×.
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleMultiAiMode');

        $composant->assertDontSeeHtml('data-composer-mode')
            ->assertSeeHtml('aria-pressed="true"');

        // Et le mode du composeur reste bien arme : c'est l'AFFICHAGE qui est
        // supprime, pas le mode.
        $composant->assertSet('composerMode', 'multi_ai');
    }

    public function test_aucun_mode_ne_rend_de_pastille_et_le_mode_reste_arme(): void
    {
        // Arbitrage de recette : le bouton du mode choisi change d'aspect, a
        // l'endroit meme ou le geste a eu lieu. La pastille redisait cet etat
        // ailleurs, avec son propre bouton ×. Elle disparait pour TOUS les
        // moteurs, pas seulement « Pour / Contre ».
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        // `toggleComposerEngine` COMBINE (ia + dossiers = ia_dossiers) : on
        // repart de zero a chaque moteur, sinon on mesure l'accumulation.
        foreach (['ia', 'dossiers', 'ia_dossiers'] as $moteur) {
            $composant->call('setComposerMode', $moteur)
                ->assertSet('composerMode', $moteur)
                ->assertDontSeeHtml('data-composer-mode');
        }

        $composant->call('toggleMultiAiMode')
            ->assertSet('composerMode', 'multi_ai')
            ->assertDontSeeHtml('data-composer-mode');
    }

    // ── 2. LE MESSAGE HUMAIN AVANT LES IA ───────────────────────────────────

    public function test_le_submit_publie_le_message_humain_sans_generer(): void
    {
        // Le decouplage, mesure : a la fin de `sendMessage()`, le message est
        // dans le fil et AUCUN appel n'est encore parti.
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Faut-il tout automatiser ?')->count(),
            'le membre voit son message tout de suite');

        $this->assertSame(0, AiProviderInvocation::query()->count(),
            'aucune generation dans la requete de publication');

        $composant->assertSet('pourContreQueue', ['aperio', 'traverse'])
            ->assertSet('composerMode', 'normal');
    }

    public function test_la_requete_differee_lance_un_role_a_la_fois(): void
    {
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $composant->call('runNextPourContre');
        $this->assertSame(1, AiProviderInvocation::query()->count(), 'POUR seul est parti');
        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count(),
            'et sa reponse est deja visible, sans attendre CONTRE');
        $composant->assertSet('pourContreQueue', ['traverse']);

        $composant->call('runNextPourContre');
        $this->assertSame(2, AiProviderInvocation::query()->count());
        $this->assertSame(2, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
        $composant->assertSet('pourContreQueue', []);
    }

    public function test_la_file_epuisee_ne_relance_rien(): void
    {
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre')
            ->call('runNextPourContre');

        // Deux appels de trop : la file est vide, rien ne doit repartir.
        $composant->call('runNextPourContre')->call('runNextPourContre');

        $this->assertSame(2, AiProviderInvocation::query()->count(),
            'la cle est consommee AVANT l\'appel : un wire:init double ne trouve plus rien');
    }

    public function test_exactement_deux_generations_jamais_trois(): void
    {
        $this->fakeDeuxReponses();

        $this->tourComplet('Faut-il tout automatiser ?');

        $this->assertSame(2, AiProviderInvocation::query()->count());
        $this->assertEqualsCanonicalizing(
            ['loop_multi_ai:aperio', 'loop_multi_ai:traverse'],
            AiProviderInvocation::query()->pluck('feature')->all(),
        );
    }

    // ── 3. LE ONE-SHOT ET LES DOUBLONS ──────────────────────────────────────

    public function test_le_mode_se_desarme_des_la_publication(): void
    {
        $this->fakeDeuxReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSet('composerMode', 'normal');
    }

    public function test_le_message_suivant_ne_repart_pas_en_pour_contre(): void
    {
        $this->fakeDeuxReponses();

        $composant = $this->tourComplet('Faut-il tout automatiser ?');
        $apres = AiProviderInvocation::query()->count();

        $composant->set('body', 'Un simple message.')->call('sendMessage');

        $this->assertSame($apres, AiProviderInvocation::query()->count());
        $composant->assertSet('pourContreQueue', []);
    }

    public function test_un_double_submit_ne_duplique_rien(): void
    {
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('sendMessage');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Faut-il tout automatiser ?')->count());
    }

    public function test_un_message_vide_ne_publie_ni_ne_genere(): void
    {
        $this->fakeDeuxReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '   ')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertHasErrors('body')
            ->assertSet('pourContreQueue', []);

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    // ── 4. ECHEC PARTIEL ────────────────────────────────────────────────────

    public function test_un_echec_de_pour_ne_bloque_pas_contre(): void
    {
        $this->fakeAvecSaturation(LoopMultiAiOrchestrator::ROLE_POUR);

        $this->tourComplet('Faut-il tout automatiser ?');

        $bulles = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->get();
        $this->assertCount(1, $bulles);
        $this->assertSame(LoopMultiAiOrchestrator::ROLE_CONTRE, $bulles->first()->metadata['assistant_key']);
    }

    public function test_un_echec_de_contre_ne_masque_pas_pour(): void
    {
        $this->fakeAvecSaturation(LoopMultiAiOrchestrator::ROLE_CONTRE);

        $composant = $this->tourComplet('Faut-il tout automatiser ?');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
        $composant->assertSeeHtml('data-multi-ai-status="rate_limited"')
            ->assertSeeHtml('data-multi-ai-retry="'.LoopMultiAiOrchestrator::ROLE_CONTRE.'"');
    }

    public function test_aucun_code_technique_n_atteint_l_interface(): void
    {
        $this->fakeAvecSaturation(LoopMultiAiOrchestrator::ROLE_CONTRE);

        $rendu = $this->tourComplet('Faut-il tout automatiser ?')->html();

        foreach (['PROVIDER_CALL_FAILED', 'RATE_LIMITED', 'upstream_provider_shared_pool',
            'RateLimitedException', 'Aperio', 'Traverse', 'Limen', 'evidence', 'orchestration'] as $fuite) {
            $this->assertStringNotContainsString($fuite, $rendu,
                "« {$fuite} » appartient aux traces internes, jamais a l'ecran du membre");
        }
    }

    // ── 5. LE VOCABULAIRE PRODUIT ───────────────────────────────────────────

    public function test_l_ecran_parle_de_pour_et_de_contre(): void
    {
        $this->fakeDeuxReponses();

        $composant = $this->tourComplet('Faut-il tout automatiser ?');

        $composant->assertSee(__('loops.plugins_multi_ai_ask_all'))
            ->assertSee('Pour')
            ->assertSee('Contre');
    }

    public function test_l_infobulle_annonce_le_contexte_et_l_absence_de_dossiers(): void
    {
        // La promesse la plus importante a poser : sans elle, un membre qui
        // interroge un document conclurait que le produit ne sait pas lire ses
        // fichiers. Elle est desormais NON BLOQUANTE — une infobulle sur le
        // bouton, plus une modale a fermer a chaque envoi.
        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.plugins_multi_ai_hint'))
            ->assertSeeHtml('data-multi-ai-toggle');

        // Et elle dit les deux choses qui comptent : la discussion recente est
        // lue, les Dossiers ne le sont pas.
        $infobulle = __('loops.plugins_multi_ai_hint');
        $this->assertStringContainsString('discussion récente', $infobulle);
        $this->assertStringContainsString('Dossiers', $infobulle);
    }

    public function test_aucune_synthese_ni_follow_up_a_l_ecran(): void
    {
        $this->fakeDeuxReponses();

        $composant = $this->tourComplet('Faut-il tout automatiser ?');

        $composant->assertDontSeeHtml('data-multi-ai-synthesise');
        $this->assertSame(0, substr_count($composant->html(), 'askFollowUp'));
    }

    // ── Outils du flux ──────────────────────────────────────────────────────

    /** Un tour complet : publication puis les deux requetes differees. */
    private function tourComplet(string $question): Testable
    {
        return Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', $question)
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre')
            ->call('runNextPourContre');
    }

    // ── Outils du banc ──────────────────────────────────────────────────────

    private function orchestrateur(): LoopMultiAiOrchestrator
    {
        return app(LoopMultiAiOrchestrator::class);
    }

    /** Une reponse par assistant, distinguee par son modele. */
    /** UN role sature (429), l'autre repond. Le cas partiel, tel qu'il arrive. */
    private function fakeAvecSaturation(string $role): void
    {
        $sature = self::MODELES[$role];

        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) use ($sature) {
            if ($model === $sature) {
                throw new RateLimitedException('sature en amont');
            }

            return new TextResponse('Argument de '.$model, new Usage(20, 10), new Meta('openrouter', $model));
        });
    }

    private function fakeDeuxReponses(): void
    {
        // `$attachments` n'est PAS un array : le SDK passe la Collection du
        // message. Une signature typee `array` leve un TypeError A L'INTERIEUR
        // de l'appel — donc attrape comme une panne provider, donc VERTE pour
        // les tests qui ne comptaient que des lignes. C'est exactement ce qui
        // s'est produit ici : trois tests passaient sur trois generations qui
        // avaient toutes echoue.
        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            'Reponse de '.$model, new Usage(20, 10), new Meta('openrouter', $model),
        ));
    }

    /**
     * La garde qui aurait vu le defaut ci-dessus du premier coup.
     *
     * Compter des lignes ne distingue pas une generation reussie d'une
     * generation echouee : les deux en ecrivent une. Tout test qui suppose
     * que les trois ont repondu doit le DIRE.
     */
    private function assertTousReussis(MultiAssistantRun $run): void
    {
        foreach ($run->outcomes as $outcome) {
            $this->assertTrue($outcome->succeeded(), sprintf(
                '%s devait reussir, statut %s (%s)', $outcome->assistantKey, $outcome->status, $outcome->errorCode ?? '-',
            ));
        }
    }

    /**
     * Compte les collectes REELLES de la source autorisee.
     *
     * `ContextBuilder` est `final` : on n'espionne donc pas le builder, mais
     * la source qu'il appelle — ce qui mesure exactement la meme chose et se
     * lit mieux, puisque c'est la collecte qui coute.
     */
    private function espionnerLaSource(): object
    {
        $espion = new class extends LoopMessagesSource
        {
            public static int $appels = 0;

            public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
            {
                self::$appels++;

                return parent::collect($contexte, $charBudget);
            }
        };

        app()->instance(LoopMessagesSource::class, $espion);

        return $espion;
    }

    private function adhesion(Loop $loop, User $user, string $role): void
    {
        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    /** Catalogue OpenRouter double + les trois modeles assignes. */
    private function catalogueEtModeles(): void
    {
        Http::swap(new Factory);
        Http::fake(['*/models' => Http::response(['data' => array_map(
            fn (string $slug): array => [
                'id' => $slug,
                'name' => 'Modele '.$slug,
                'context_length' => 32768,
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
