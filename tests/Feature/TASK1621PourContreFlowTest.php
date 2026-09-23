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
use App\Models\Dossier;
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
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
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

    public function test_la_mire_d_attente_nomme_le_modele_qui_prepare(): void
    {
        // Sans ce nom, deux assistants differents se lisent comme un seul qui
        // repond deux fois — constat de recette.
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleMultiAiMode')
            ->set('body', 'Windows ou Linux, que choisir ?')
            ->call('sendMessage');

        // La file est armee : la mire est a l'ecran, pour le PREMIER role.
        $this->assertSame('aperio', $composant->get('pourContreQueue')[0] ?? null);

        $composant->assertSeeHtml('data-multi-ai-pending')
            ->assertSeeHtml('data-multi-ai-pending-model="aperio"');

        // Et le nom est ABREGE, jamais le slug entier : le fournisseur et le
        // palier tarifaire sont du jargon d'administration.
        $modeles = $composant->instance()->multiAiModelLabels();

        $this->assertArrayHasKey('aperio', $modeles);
        $this->assertStringNotContainsString('/', $modeles['aperio']);
        $this->assertStringNotContainsString(':free', $modeles['aperio']);
        $composant->assertSee($modeles['aperio']);
    }

    // ── 1-TER. UNE QUESTION SANS CAMPS S'ARRETE AU PREMIER ROLE ─────────────

    public function test_une_question_sans_camps_ne_fait_qu_un_appel_et_zero_bulle(): void
    {
        $this->fakeHorsSujet();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleMultiAiMode')
            ->set('body', 'Quel CMS choisir ?')
            ->call('sendMessage');

        // Le message humain est publie : il n'y a aucune raison de le retenir.
        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('body', 'Quel CMS choisir ?')->where('type', 'user')->count());

        // Les deux roles etaient armes...
        $this->assertCount(2, $composant->get('pourContreQueue'));

        // ... le PREMIER s'abstient, et la file est annulee.
        $composant->call('runNextPourContre');

        $this->assertSame([], $composant->get('pourContreQueue'),
            'le second role n\'a rien a faire : il rendrait le meme verdict');

        // UN SEUL appel provider, pas deux.
        $this->assertSame(1, AiProviderInvocation::query()->count());

        // Aucune bulle, ni POUR ni CONTRE.
        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'ai')->count());

        // Une notice NEUTRE, et une seule.
        $html = $composant->html();
        $this->assertSame(1, substr_count($html, 'data-multi-ai-not-applicable'));
        $composant->assertSee(__('loops.plugins_multi_ai_not_applicable'));

        // Et surtout PAS le vocabulaire de la panne.
        $composant->assertDontSee(__('loops.plugins_multi_ai_failed_title', ['assistant' => 'Pour']))
            ->assertDontSee(__('loops.plugins_multi_ai_failed_body'))
            ->assertDontSeeHtml('data-multi-ai-retry');
    }

    public function test_une_question_avec_camps_lance_bien_les_deux_roles(): void
    {
        // Le sabotage naturel du test precedent : si la file etait videe a
        // tort, plus aucune question ne produirait deux bulles.
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleMultiAiMode')
            ->set('body', 'Windows ou Linux, que choisir ?')
            ->call('sendMessage');

        while ($composant->get('pourContreQueue') !== []) {
            $composant->call('runNextPourContre');
        }

        $this->assertSame(2, AiProviderInvocation::query()->count());
        $this->assertSame(2, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
        $composant->assertDontSeeHtml('data-multi-ai-not-applicable');
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

    // ── 6. LA CARTE DE DEBAT — arrivee progressive (addendum UX 22/09) ──────
    //
    // Garde MASTER : la carte se construit depuis le message declencheur et
    // l'etat de la file, JAMAIS depuis l'existence simultanee des deux bulles
    // IA. La machine a etats est pinnee telle quelle :
    //   submit -> carte visible ; POUR absent -> mire ; CONTRE absent ->
    //   attente ; POUR publie -> visible immediatement ; CONTRE ensuite.

    public function test_la_carte_existe_des_le_submit_avant_toute_reponse(): void
    {
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(0, AiProviderInvocation::query()->count(),
            'la carte precede toute generation : elle ne depend d\'aucune bulle IA');

        $composant->assertSeeHtml('data-pour-contre-debat')
            ->assertSeeHtml('data-pour-contre-mire="aperio"')
            ->assertSeeHtml('data-pour-contre-attente="traverse"');
    }

    public function test_pour_se_lit_des_sa_publication_sans_attendre_contre(): void
    {
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre');

        // POUR est publie et LISIBLE pendant que CONTRE se prepare — aucune
        // periode ou une reponse terminee reste masquee.
        $composant->assertSee('Reponse de '.self::MODELES['aperio'])
            ->assertSeeHtml('data-pour-contre-mire="traverse"')
            ->assertDontSeeHtml('data-pour-contre-mire="aperio"')
            ->assertDontSeeHtml('data-pour-contre-attente');
    }

    public function test_le_remplacement_se_fait_sans_recreer_la_carte(): void
    {
        // L'identite DOM est la cle du non-clignotement : le MEME wire:key,
        // present UNE fois, du submit a la fin — Livewire met a jour la carte,
        // il ne la recree jamais.
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $declencheur = (string) LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Faut-il tout automatiser ?')->value('id');
        $cle = 'wire:key="debat-'.$declencheur.'"';

        $this->assertSame(1, substr_count($composant->html(), $cle), 'une carte, des le submit');

        $composant->call('runNextPourContre');
        $this->assertSame(1, substr_count($composant->html(), $cle), 'la meme carte pendant CONTRE');

        $composant->call('runNextPourContre');
        $this->assertSame(1, substr_count($composant->html(), $cle), 'la meme carte une fois complete');
    }

    public function test_une_seule_mire_visible_par_viewport(): void
    {
        // La capture de recette du 22/09 montrait la mire DEUX fois : dans la
        // colonne CONTRE et au-dessus du composeur. Le bandeau reste rendu
        // (c'est la surface du telephone) mais porte `md:hidden` ; la mire de
        // la carte, elle, ne le porte pas.
        $this->fakeDeuxReponses();

        $html = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->html();

        preg_match('/<div[^>]*data-multi-ai-pending[^>]*>/', $html, $bandeau);
        $this->assertNotEmpty($bandeau, 'la mire du bandeau existe pour le telephone');
        $this->assertStringContainsString('md:hidden', $bandeau[0],
            'des md:, la carte porte la mire — le bandeau ne la repete pas');

        preg_match('/<p[^>]*data-pour-contre-mire="aperio"[^>]*>/', $html, $mireCarte);
        $this->assertNotEmpty($mireCarte, 'la mire de la carte existe');
        $this->assertStringNotContainsString('md:hidden', $mireCarte[0],
            'la mire de la carte est la surface Desktop');
    }

    public function test_la_bulle_regroupee_reste_au_telephone_et_la_carte_a_l_ordinateur(): void
    {
        // Decision Cyril 22/09 : pas de tableau sur telephone. Les DEUX
        // projections sont rendues, chacune derriere sa porte CSS — bulle de
        // fil `md:hidden`, carte `hidden md:block`.
        $this->fakeDeuxReponses();

        $html = $this->tourComplet('Faut-il tout automatiser ?')->html();

        $bulle = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')
            ->orderBy('created_at')->orderBy('id')->first();

        preg_match('/<div[^>]*id="loop-message-'.preg_quote((string) $bulle->id, '/').'"[^>]*>/', $html, $wrapper);
        $this->assertNotEmpty($wrapper, 'la bulle regroupee reste rendue');
        $this->assertStringContainsString('md:hidden', $wrapper[0],
            'au-dela de md:, seule la carte la montre');

        preg_match('/<div[^>]*data-pour-contre-debat[^>]*>/', $html, $carte);
        $this->assertNotEmpty($carte);
        $this->assertStringContainsString('hidden', $carte[0]);
        $this->assertStringContainsString('md:block', $carte[0],
            'la carte est une projection Desktop uniquement');
    }

    public function test_not_applicable_n_ouvre_aucune_carte(): void
    {
        $this->fakeHorsSujet();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel CMS choisir ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre');

        $composant->assertDontSeeHtml('data-pour-contre-debat')
            ->assertSeeHtml('data-multi-ai-not-applicable');
    }

    public function test_l_echec_d_un_role_se_lit_dans_sa_colonne_sans_emporter_l_autre(): void
    {
        $this->fakeAvecSaturation(LoopMultiAiOrchestrator::ROLE_CONTRE);

        $composant = $this->tourComplet('Faut-il tout automatiser ?');

        // POUR reste lisible, l'echec de CONTRE est compact dans SA colonne,
        // avec le geste humain de reprise. (La doublure de saturation rend
        // « Argument de … », pas « Reponse de … ».)
        $composant->assertSee('Argument de '.self::MODELES['aperio'])
            ->assertSeeHtml('data-pour-contre-echec="traverse"')
            ->assertSeeHtml('data-multi-ai-retry="traverse"');
    }

    public function test_la_carte_survit_a_l_echec_des_deux_roles(): void
    {
        // File videe, AUCUNE bulle publiee : sans la clause sur les etats,
        // la carte disparaissait d'un coup avec ses deux encarts — le flash
        // de disparition que l'addendum interdit.
        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) {
            throw new RateLimitedException('sature en amont');
        });

        $composant = $this->tourComplet('Faut-il tout automatiser ?');

        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());

        $composant->assertSeeHtml('data-pour-contre-debat')
            ->assertSeeHtml('data-pour-contre-echec="aperio"')
            ->assertSeeHtml('data-pour-contre-echec="traverse"');
    }

    // ── 7. LES ACTIONS DE LA CARTE — deux gestes, uniques (Cyril 22/09) ─────

    public function test_repondre_a_quitte_la_carte(): void
    {
        $this->fakeDeuxReponses();

        $this->tourComplet('Faut-il tout automatiser ?')
            ->assertDontSeeHtml('data-pour-contre-repondre');
    }

    public function test_copier_est_un_geste_unique_de_la_carte(): void
    {
        $this->fakeDeuxReponses();

        $html = $this->tourComplet('Faut-il tout automatiser ?')->html();

        $this->assertSame(1, substr_count($html, 'data-pour-contre-copier'),
            'un seul copier pour la carte entiere');
    }

    public function test_ajouter_au_dossier_attend_le_debat_complet(): void
    {
        // Garde MASTER : jamais un demi-debat capitalise en silence. Tant que
        // les deux camps publiables ne sont pas la, le bouton N'EXISTE PAS.
        $this->fakeDeuxReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Faut-il tout automatiser ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $composant->assertDontSeeHtml('data-pour-contre-capitaliser');

        $composant->call('runNextPourContre');
        $composant->assertDontSeeHtml('data-pour-contre-capitaliser');

        $composant->call('runNextPourContre');
        $this->assertSame(1, substr_count($composant->html(), 'data-pour-contre-capitaliser'),
            'un seul « Ajouter au Dossier », au niveau carte, une fois le debat complet');
    }

    public function test_un_role_en_echec_ne_laisse_pas_capitaliser_un_demi_debat(): void
    {
        $this->fakeAvecSaturation(LoopMultiAiOrchestrator::ROLE_CONTRE);
        // Un Dossier inscriptible EXISTE : sans lui, `defaultDossier()` rend
        // null et la methode s'arrete pour la MAUVAISE raison — le sabotage
        // de la garde du demi-debat restait vert (mesure le 22/09).
        $this->dossierInscriptible();

        $composant = $this->tourCompletEnTantQue($this->owner, 'Faut-il tout automatiser ?');

        $composant->assertDontSeeHtml('data-pour-contre-capitaliser');

        // Et une requete FORGEE qui atteindrait la methode s'arrete a la
        // garde elle-meme : l'UI n'est jamais une barriere.
        $declencheur = (string) LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Faut-il tout automatiser ?')->value('id');

        $composant->call('startDebateCapitalization', $declencheur)
            ->assertSet('capitalizingMessageId', null)
            ->assertSet('capitalizeContent', '');
    }

    public function test_le_brouillon_reunit_les_deux_camps_dans_l_ordre(): void
    {
        $this->fakeDeuxReponses();
        $this->dossierInscriptible();

        $composant = $this->tourCompletEnTantQue($this->owner, 'Faut-il tout automatiser ?');

        $declencheur = (string) LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Faut-il tout automatiser ?')->value('id');
        $pour = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')
            ->get()->first(fn (LoopMessage $m) => ($m->metadata['assistant_key'] ?? null) === 'aperio');

        $composant->call('startDebateCapitalization', $declencheur)
            ->assertSet('capitalizingMessageId', $pour->id)
            ->assertHasNoErrors();

        $contenu = $composant->get('capitalizeContent');
        $this->assertStringContainsString("POUR\n\nReponse de ".self::MODELES['aperio'], $contenu);
        $this->assertStringContainsString("CONTRE\n\nReponse de ".self::MODELES['traverse'], $contenu);
        $this->assertLessThan(strpos($contenu, 'CONTRE'), strpos($contenu, 'POUR'),
            'POUR precede CONTRE, comme a l\'ecran');

        $this->assertStringContainsString('Faut-il tout automatiser', $composant->get('capitalizeTitle'),
            'le titre derive de la QUESTION du debat');
    }

    public function test_un_camp_ecourte_est_annonce_dans_le_brouillon(): void
    {
        // PARTIAL reste capitalisable, mais son etat s'ecrit EN TOUTES
        // LETTRES : le document ne se presente jamais comme un debat complet
        // qu'il n'est pas (garde MASTER). Le chemin est le VRAI : un Step
        // `Length` du provider, pas une metadata posee a la main.
        $this->fakeContreEcourtee();
        $this->dossierInscriptible();

        $composant = $this->tourCompletEnTantQue($this->owner, 'Faut-il tout automatiser ?');

        $declencheur = (string) LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Faut-il tout automatiser ?')->value('id');

        $composant->call('startDebateCapitalization', $declencheur)->assertHasNoErrors();

        $contenu = $composant->get('capitalizeContent');
        $this->assertStringContainsString('CONTRE — '.__('loops.plugins_multi_ai_truncated'), $contenu);
        $this->assertStringContainsString("POUR\n\n", $contenu);
        $this->assertStringNotContainsString('POUR — ', $contenu,
            'seul le camp reellement ecourte porte la mention');
    }

    // ── 8. LA REFORMULATION PROPOSEE (TASK-1622) ────────────────────────────

    public function test_l_abstention_propose_une_reformulation_sans_second_appel(): void
    {
        $this->fakeHorsSujetAvecSuggestion('Utiliser un CMS est-il un bon choix pour creer un site web ?');

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel CMS choisir ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre');

        // UN SEUL appel : la suggestion sort du tour qui s'est abstenu.
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $composant->assertSet('pourContreQueue', [], 'CONTRE n\'est pas lance');

        $composant->assertSeeHtml('data-multi-ai-suggestion')
            ->assertSee('Utiliser un CMS est-il un bon choix pour creer un site web ?')
            ->assertSee(__('loops.plugins_multi_ai_suggestion_use'));
    }

    public function test_le_clic_remplit_le_composeur_sans_rien_envoyer(): void
    {
        $this->fakeHorsSujetAvecSuggestion('Faut-il utiliser un CMS ?');

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel CMS choisir ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre');

        $messagesAvant = LoopMessage::where('loop_id', $this->loop->id)->count();

        $composant->call('useSuggestion');

        // Le texte est DANS le composeur, le mode reste arme, et RIEN n'a ete
        // envoye : ni message, ni appel provider.
        $composant->assertSet('body', 'Faut-il utiliser un CMS ?')
            ->assertSet('composerMode', LoopChat::MODE_MULTI_AI);

        $this->assertSame($messagesAvant, LoopMessage::where('loop_id', $this->loop->id)->count(),
            'aucun message publie : l\'humain garde le dernier geste');
        $this->assertSame(1, AiProviderInvocation::query()->count(),
            'aucun nouvel appel provider');
    }

    public function test_un_composeur_occupe_n_est_jamais_ecrase_en_silence(): void
    {
        $this->fakeHorsSujetAvecSuggestion('Faut-il utiliser un CMS ?');

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel CMS choisir ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre');

        // Le membre a retape quelque chose pendant l'attente.
        $composant->set('body', 'un texte que je suis en train d\'ecrire');

        $composant->call('useSuggestion');
        $composant->assertSet('body', 'un texte que je suis en train d\'ecrire',
            'premier clic : on demande, on n\'ecrase pas')
            ->assertSet('suggestionEcrasementConfirme', true)
            ->assertSeeHtml('data-multi-ai-suggestion-confirm');

        $composant->call('useSuggestion');
        $composant->assertSet('body', 'Faut-il utiliser un CMS ?', 'second clic : remplace')
            ->assertSet('suggestionEcrasementConfirme', false);
    }

    public function test_sans_suggestion_fidele_aucune_n_est_inventee(): void
    {
        // Le modele s'abstient SANS proposer : l'ecran ne doit fabriquer
        // aucune opposition, et le bouton ne doit pas exister.
        $this->fakeHorsSujet();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel outil choisir ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre');

        $composant->assertSeeHtml('data-multi-ai-not-applicable')
            ->assertDontSeeHtml('data-multi-ai-suggestion')
            // Mandat §5 : pas de reformulation fidele -> on DEMANDE UNE
            // PRECISION. Souffler un exemple en dur reviendrait a inventer a
            // la place du modele qui vient de ne pas pouvoir le faire.
            ->assertSeeHtml('data-multi-ai-precision')
            ->assertSee(__('loops.plugins_multi_ai_not_applicable_precision'));

        // Et le geste force ne fabrique rien non plus.
        $composant->call('useSuggestion')->assertSet('body', '');
    }

    public function test_une_suggestion_forgee_ne_peut_pas_etre_injectee(): void
    {
        // RIEN ne voyage depuis le client : `useSuggestion()` ne prend aucun
        // parametre et relit l'etat du serveur. Un etat falsifie ne peut donc
        // poser que ce que le serveur y a mis — et ici il n'y a rien.
        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop]);

        $composant->call('useSuggestion')->assertSet('body', '');
    }

    // ── Outils du flux ──────────────────────────────────────────────────────

    /** Un tour complet : publication puis les deux requetes differees. */
    private function tourComplet(string $question): Testable
    {
        return $this->tourCompletEnTantQue($this->membre, $question);
    }

    /** Le meme tour, pour l'acteur des tests de capitalisation (l'owner). */
    private function tourCompletEnTantQue(User $acteur, string $question): Testable
    {
        return Livewire::actingAs($acteur)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', $question)
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->call('runNextPourContre')
            ->call('runNextPourContre');
    }

    /**
     * Un Dossier ou l'owner peut deposer — `Loop::factory()` n'en cree aucun,
     * contrairement a `LoopService::createLoop`. Le tenant courant est lie
     * comme dans le banc T1310 : les requetes de perimetre en dependent.
     */
    private function dossierInscriptible(): void
    {
        app()->instance('current_organization', $this->organization);

        // Un Dossier DE BOUCLE : `loop_id` porte, `owner_id` NUL. La
        // contrainte PostgreSQL `dossiers_holder_xor` exige l'un OU l'autre
        // — et SQLite ne la voit pas : les deux poses ensemble etaient VERTS
        // en local et rouges sur les 3 tests du shard PG (CI du 22/09).
        Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => null,
            'loop_id' => $this->loop->id,
            'name' => 'Dossier du banc 1621',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);
    }

    /**
     * POUR complet, CONTRE ecourte par le budget de sortie : le VRAI signal —
     * un `Step` portant `FinishReason::Length`, comme la passerelle OpenRouter
     * le produit. Les doublures nues (steps vide) mesurent l'absence de
     * signal, pas une troncature.
     */
    private function fakeContreEcourtee(): void
    {
        $ecourte = self::MODELES['traverse'];

        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) use ($ecourte) {
            $usage = new Usage(20, 10);
            $meta = new Meta('openrouter', $model);
            $texte = 'Reponse de '.$model;

            if ($model !== $ecourte) {
                return new TextResponse($texte, $usage, $meta);
            }

            return (new TextResponse($texte, $usage, $meta))
                ->withSteps(collect([new Step($texte, [], [], FinishReason::Length, $usage, $meta)]));
        });
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

    /** Abstention PLUS une reformulation proposee, dans la meme reponse. */
    private function fakeHorsSujetAvecSuggestion(string $suggestion): void
    {
        $texte = LoopMultiAiOrchestrator::MARQUEUR_HORS_SUJET."\n"
            .LoopMultiAiOrchestrator::MARQUEUR_SUGGESTION.' '.$suggestion;

        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            $texte, new Usage(20, 12), new Meta('openrouter', $model),
        ));
    }

    /** Le modele annonce que la question n'a pas de camps a distribuer. */
    private function fakeHorsSujet(): void
    {
        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            LoopMultiAiOrchestrator::MARQUEUR_HORS_SUJET, new Usage(20, 5), new Meta('openrouter', $model),
        ));
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
