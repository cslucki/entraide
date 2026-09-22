<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopMultiAiAgent;
use App\Livewire\LoopChat;
use App\Models\AdminAiPrompt;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPluginAiModel;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopPluginAiModels;
use App\Services\Ai\OpenRouterModelCatalog;
use App\Services\Loops\LoopAiAssistants;
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
 * TASK-1619 / SLICE E — les 3 assistants IA dans ChatLoop.
 *
 * MIGRE PAR TASK-1620 : le declencheur n'est plus un bouton par assistant mais
 * l'ENVOI du composeur, mode « Demander aux 3 IA » arme. Les comportements
 * mesures ici n'ont pas change d'un iota — reponses partielles, libelles
 * produit, follow-ups, synthese, etat ephemere — seule la porte d'entree a
 * bouge. Les tests d'INTERFACE des anciens boutons vivent desormais dans
 * `TASK1620MultiAiComposerModeTest`.
 *
 * SLICE D a pose le moteur ; ici le membre s'en sert. Ce fichier mesure les
 * quatre choses dont MASTER a fait des regles, et dont aucune n'est cosmetique :
 *
 *  1. **rien n'est atomique.** Aperio reussit, Traverse sature, Limen reussit :
 *     le membre voit DEUX reponses tout de suite. « Une IA echoue donc tout a
 *     echoue » n'existe pas ;
 *  2. **l'echec est EPHEMERE.** Les reussites deviennent des bulles du fil ;
 *     l'indisponibilite vit dans le composeur, chez le seul demandeur, et
 *     n'entre JAMAIS dans la conversation de tout le monde ;
 *  3. **le membre lit une phrase, pas un diagnostic.** `PROVIDER_CALL_FAILED`,
 *     `429` et `upstream_provider_shared_pool` ne doivent apparaitre nulle part
 *     dans l'interface ;
 *  4. **rien ne se declenche tout seul.** Pas de reessai automatique, pas de
 *     synthese automatique, aucune IA qui relit une IA sans qu'un humain l'ait
 *     demande.
 */
class TASK1619MultiAiChatLoopTest extends TestCase
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

    // ── A. Disponibilite du mode ────────────────────────────────────────────
    //
    // TASK-1620 — les tests des QUATRE boutons ont ete retires : il n'y en a
    // plus qu'un, et son interface est mesuree dans
    // `TASK1620MultiAiComposerModeTest`. Ce qui reste ici est ce que la
    // DISPONIBILITE commande, independamment de la forme du bouton.

    public function test_le_mode_n_est_pas_disponible_sans_plugin_actif(): void
    {
        app(LoopPluginActivation::class)->setEnabled(self::PLUGIN, $this->loop, false, $this->owner);

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-mode');
    }

    public function test_retirer_la_disponibilite_de_l_organization_eteint_le_mode(): void
    {
        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->organization, false, $this->superAdmin);

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-mode');
    }

    public function test_un_assistant_eteint_ne_participe_pas_au_tour(): void
    {
        app(LoopAiAssistants::class)->save($this->loop, ['limen' => ['enabled' => false]], $this->owner);
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertEqualsCanonicalizing(['aperio', 'traverse'],
            LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->get()
                ->map(fn (LoopMessage $m): string => $m->metadata['assistant_key'])->all());
    }

    public function test_un_non_membre_ne_voit_pas_le_mode(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        Livewire::actingAs($etranger)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-mode');
    }

    // ── B. Les reponses partielles ──────────────────────────────────────────

    public function test_deux_reussites_et_une_saturation_publient_deux_bulles(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $bulles = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->get();

        $this->assertCount(2, $bulles, 'les reussites ne doivent pas attendre l\'echec du voisin');
        $this->assertEqualsCanonicalizing(
            ['aperio', 'limen'],
            $bulles->map(fn (LoopMessage $m): string => $m->metadata['assistant_key'])->all(),
        );
    }

    public function test_l_assistant_sature_n_ecrit_rien_dans_le_fil(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'ai')
            ->get()
            ->filter(fn (LoopMessage $m): bool => ($m->metadata['assistant_key'] ?? null) === 'traverse')
            ->count(), 'un incident de trente secondes ne reste pas des mois dans la conversation');
    }

    public function test_l_etat_de_l_assistant_sature_est_montre_au_demandeur(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSeeHtml('data-multi-ai-state="traverse"')
            ->assertSeeHtml('data-multi-ai-status="rate_limited"');
    }

    public function test_une_seule_bulle_question_pour_les_trois_assistants(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')
            ->where('body', 'Quel est le budget ?')
            ->count(), 'le membre a demande une fois : le fil ne doit pas laisser croire qu\'il a demande trois fois');
    }

    public function test_le_message_humain_reste_meme_si_les_trois_echouent(): void
    {
        // TASK-1620 a INVERSE cette regle, et c'est voulu.
        //
        // Avant, les trois assistants etaient declenches sans submit : publier
        // une question que personne n'avait honoree aurait laisse une
        // interpellation sans suite dans le fil, et le membre n'avait rien
        // envoye. Desormais il APPUIE SUR ENVOYER : son message est un message
        // humain, il lui appartient, et il reste — que l'IA ait repondu ou non.
        //
        // Ce qui n'entre toujours pas dans le fil, c'est l'ECHEC : aucune
        // bulle d'assistant, seulement un etat ephemere chez le demandeur.
        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) {
            throw new RateLimitedException('sature');
        });

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Une question sans reponse ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Une question sans reponse ?')->count(),
            'le membre a envoye son message : il lui appartient');

        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count(),
            'aucun echec n\'entre dans la conversation de tout le monde');
    }

    // ── C. La traduction produit ────────────────────────────────────────────

    public function test_le_membre_lit_une_phrase_et_pas_un_diagnostic(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSee(__('loops.plugins_multi_ai_rate_limited_title', ['assistant' => 'Traverse']))
            ->assertSee(__('loops.plugins_multi_ai_rate_limited_body', ['assistant' => 'Traverse']));
    }

    public function test_aucun_code_technique_n_atteint_l_interface(): void
    {
        $this->fakeAvecSaturation('traverse');

        $rendu = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->html();

        foreach (['PROVIDER_CALL_FAILED', 'RATE_LIMITED', 'upstream_provider_shared_pool', 'HTTP 429', 'RateLimitedException'] as $fuite) {
            $this->assertStringNotContainsString($fuite, $rendu,
                "`{$fuite}` appartient aux traces SuperAdmin, jamais a l'ecran du membre");
        }
    }

    public function test_reessayer_n_est_propose_que_pour_une_saturation(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSeeHtml('data-multi-ai-retry="traverse"');
    }

    public function test_un_modele_non_configure_ne_propose_pas_de_reessai(): void
    {
        LoopPluginAiModel::query()->where('assistant_key', 'traverse')->delete();
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSeeHtml('data-multi-ai-status="refused"')
            ->assertDontSeeHtml('data-multi-ai-retry="traverse"');
    }

    // TASK-1620 — `test_demander_a_une_autre_ia_est_propose` a ete RETIRE avec
    // la fonctionnalite. Dans le flux « Demander aux 3 IA », les deux autres
    // assistants ONT DEJA repondu quand l'un echoue : proposer de leur demander
    // etait devenu une action sans objet, et c'etait un declencheur direct de
    // plus — exactement la famille de defaut que cette TASK corrige.

    // ── D. Le reessai, HUMAIN ───────────────────────────────────────────────

    public function test_un_reessai_ne_republie_pas_la_question(): void
    {
        $this->fakeAvecSaturation('traverse');

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->fakeTroisReponses();
        $composant->call('retryAssistant', 'traverse');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'user')->where('body', 'Quel est le budget ?')->count());
    }

    public function test_un_reessai_reussi_efface_l_avertissement_et_publie(): void
    {
        $this->fakeAvecSaturation('traverse');

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSeeHtml('data-multi-ai-state="traverse"');

        $this->fakeTroisReponses();
        $composant->call('retryAssistant', 'traverse')
            ->assertDontSeeHtml('data-multi-ai-state="traverse"');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'ai')->get()
            ->filter(fn (LoopMessage $m): bool => ($m->metadata['assistant_key'] ?? null) === 'traverse')
            ->count());
    }

    public function test_aucun_reessai_ne_part_sans_clic_humain(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        // Trois assistants sollicites = trois appels emis, pas un de plus.
        // Un reessai automatique en ajouterait un quatrieme, sature lui aussi,
        // et consommerait le quota de tout le monde sans que personne l'ait
        // demande.
        $this->assertSame(3, AiProviderInvocation::query()->count());
    }

    // ── E. Les follow-ups, dans le MEME appel ───────────────────────────────

    public function test_les_follow_ups_sortent_du_meme_appel_que_la_reponse(): void
    {
        // Un seul assistant actif : le mode lance les assistants ACTIFS, et
        // c'est ainsi qu'on isole une generation unique sans inventer un
        // second declencheur.
        app(LoopAiAssistants::class)->save($this->loop, [
            'traverse' => ['enabled' => false], 'limen' => ['enabled' => false],
        ], $this->owner);

        $this->fakeAvecFollowUps();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertSame(1, AiProviderInvocation::query()->count(),
            'une question suggeree ne vaut pas une generation de plus');

        $bulle = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->first();

        $this->assertSame(
            ['Qui a valide ce budget ?', 'Quelles sont les etapes de mars ?'],
            $bulle->metadata['follow_up_questions'],
        );
        $this->assertStringNotContainsString('Pour approfondir', (string) $bulle->body,
            'la section est SEPAREE de la reponse, pas recopiee dedans');
    }

    public function test_l_absence_de_follow_ups_est_un_cas_nominal(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $bulle = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->first();

        $this->assertArrayNotHasKey('follow_up_questions', $bulle->metadata,
            'cle ABSENTE, jamais un tableau vide qui se lirait comme une mesure');
    }

    public function test_une_section_en_tete_ne_fait_pas_perdre_la_reponse(): void
    {
        // Defaut trouve par la RECETTE REELLE, pas par le banc : un modele
        // gratuit a place la section « Pour approfondir » en tete, le corps est
        // ressorti vide, et le tour s'est conclu `EMPTY_MODEL_ANSWER` — appel
        // paye, membre sans reponse. Une reponse mal mise en forme vaut mieux
        // qu'une reponse perdue.
        $titre = __('dossiers.answer_follow_ups_heading');

        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            '## '.$titre."\n- Une question ?\n\nLe budget est de 40 000 euros.",
            new Usage(20, 10), new Meta('openrouter', $model),
        ));

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $bulle = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->first();

        $this->assertNotNull($bulle, 'la reponse ne doit pas etre perdue a cause de sa mise en forme');
        $this->assertStringContainsString('40 000 euros', (string) $bulle->body);
    }

    // ── F. La synthese, un GESTE ────────────────────────────────────────────

    public function test_la_synthese_n_est_pas_proposee_sans_reponse_prealable(): void
    {
        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-synthesise');
    }

    public function test_la_synthese_apparait_une_fois_des_reponses_obtenues(): void
    {
        $this->fakeTroisReponses();

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSeeHtml('data-multi-ai-synthesise');
    }

    public function test_demander_aux_trois_ne_declenche_aucune_synthese(): void
    {
        $vus = [];
        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) use (&$vus) {
            $vus[] = $prompt;

            return new TextResponse('Reponse de '.$model, new Usage(20, 10), new Meta('openrouter', $model));
        });

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $this->assertCount(3, $vus);

        foreach ($vus as $prompt) {
            $this->assertStringNotContainsString('REPONSES', $prompt,
                'aucun assistant ne lit un autre assistant tant qu\'un humain ne l\'a pas demande');
        }
    }

    public function test_la_synthese_relit_les_reponses_des_autres(): void
    {
        $this->fakeTroisReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $vus = [];
        LoopMultiAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) use (&$vus) {
            $vus[] = $prompt;

            return new TextResponse('Synthese.', new Usage(20, 10), new Meta('openrouter', $model));
        });

        $composant->call('synthesiseAssistants');

        $this->assertCount(1, $vus, 'seul le synthetiseur est appele');
        $this->assertStringContainsString('Reponse de '.self::MODELES['aperio'], $vus[0]);
        $this->assertStringContainsString('<<<REPONSES', $vus[0],
            'les reponses relues sont DELIMITEES : ce sont des propos rapportes, pas des consignes');
    }

    // ── G. Ce que l'interface doit tenir ────────────────────────────────────

    public function test_l_etat_d_echec_est_ephemere_entre_deux_montages(): void
    {
        $this->fakeAvecSaturation('traverse');

        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->assertSeeHtml('data-multi-ai-state="traverse"');

        // Un nouveau montage = un rechargement. L'avertissement a disparu, les
        // reponses sont restees.
        Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-state="traverse"');

        $this->assertSame(2, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
    }

    public function test_la_bulle_nomme_l_assistant_qui_parle(): void
    {
        $this->fakeTroisReponses();

        // Le nom de la bulle porte l'identite TENANT et le BADGE porte le
        // moteur (doctrine TASK-1312). Pour trois assistants, un badge « IA »
        // partout donnerait a lire un seul interlocuteur qui se contredit :
        // c'est donc le badge qui doit les nommer.
        $html = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage')
            ->html();

        foreach (['Aperio', 'Traverse', 'Limen'] as $nom) {
            $this->assertStringContainsString('>'.$nom.'<', $html,
                "`{$nom}` doit apparaitre comme un libelle rendu, pas seulement dans un attribut");
        }

        $this->assertStringContainsString($this->organization->name, $html,
            "l'Organization reste le locuteur : la doctrine T1308 n'est pas levee, elle est precisee");
    }

    public function test_repondre_a_un_assistant_ne_bascule_pas_le_composeur(): void
    {
        $this->fakeTroisReponses();

        $composant = Livewire::actingAs($this->membre)->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Quel est le budget ?')
            ->call('toggleMultiAiMode')
            ->call('sendMessage');

        $bulle = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->first();

        $composant->call('replyTo', (string) $bulle->id)
            ->assertSet('composerMode', 'normal');
    }

    public function test_un_non_membre_ne_voit_aucun_bouton(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        Livewire::actingAs($etranger)->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('data-multi-ai-ask-all');
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
