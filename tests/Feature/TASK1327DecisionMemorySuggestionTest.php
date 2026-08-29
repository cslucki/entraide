<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopDecisionSuggestionAgent;
use App\Ai\Agents\LoopSummaryAgent;
use App\Ai\CapabilityRegistry;
use App\Livewire\LoopDecisionsCard;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopDecision;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Loops\LoopDecisionService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1327 (Premium-1) : « Decision Memory IA ».
 *
 * L'IA ne cree JAMAIS la decision durable : elle pre-remplit le formulaire de
 * `LoopDecisionsCard`, l'humain verifie, edite et capitalise via `promote()` —
 * la surface canonique de TASK-1106, dont les 55 tests couvrent deja
 * l'ecriture humaine et ne sont PAS dupliques ici.
 *
 * Cette matrice couvre la couche IA :
 * - discussion sans conclusion claire -> aucune suggestion forcee ;
 * - decision claire -> suggestion avec reference verifiee au message source ;
 * - annulation -> aucune ecriture durable ;
 * - identifiant invente / autre Boucle / autre Organization / supprime /
 *   deja promu -> refus propre, jamais promu ;
 * - sans `decisions.record` -> jamais propose, refuse au geste ;
 * - contenu editable avant validation ;
 * - provenance Core-1 : fait verifie serveur vs wording IA `verified: false` ;
 * - le JSON d'une suggestion ne devient jamais « le dernier resume ».
 *
 * Reseau fake uniquement (`::fake()` du SDK) ; toute assertion sur `metadata`
 * lit des cles individuelles — l'aller-retour jsonb de PostgreSQL reordonne
 * les cles, un `assertSame` de tableau entier serait un faux vert SQLite.
 */
class TASK1327DecisionMemorySuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $animateur;

    private User $membre;

    private Loop $loop;

    private LoopService $loops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->org->id,
            'provider' => 'openrouter',
            'model' => 'deepseek/deepseek-chat-v3-0324',
        ]);

        $this->animateur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loops = new LoopService;
        $this->loop = $this->loops->createLoop($this->animateur, 'Boucle Decision Memory')->fresh();
        $this->loops->addMember($this->loop, $this->membre, 'member');

        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.decisions'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );

        AiConfig::set('default_provider', 'openrouter');
        AiConfig::set('default_model', 'deepseek/deepseek-chat-v3-0324');

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'test-key',
            'ai.chatloop.min_summary_words' => 0,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function message(string $corps, ?User $qui = null): LoopMessage
    {
        return LoopMessage::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'sender_id' => ($qui ?? $this->membre)->id,
            'body' => $corps,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function fakeSuggestion(array $payload): void
    {
        LoopDecisionSuggestionAgent::fake([
            new TextResponse(
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                new Usage(10, 20),
                new Meta('openrouter', 'deepseek/deepseek-chat-v3-0324'),
            ),
        ]);
    }

    private function service(): ChatLoopAiService
    {
        return app(ChatLoopAiService::class);
    }

    private function card(User $user)
    {
        return Livewire::actingAs($user)->test(LoopDecisionsCard::class, ['loop' => $this->loop]);
    }

    // =====================================================================
    // Le pipeline canonique et sa trace
    // =====================================================================

    public function test_a_clear_decision_yields_a_suggestion_with_a_verified_source(): void
    {
        $conclusion = $this->message('On tranche : on passe sur PostgreSQL des la V2.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Passage sur PostgreSQL en V2',
            'rationale' => 'La discussion a pese SQLite et PostgreSQL ; PostgreSQL est retenu.',
            'source_message_id' => $conclusion->id,
        ]);

        $suggestion = $this->service()->suggestDecision($this->loop, $this->animateur);

        $this->assertTrue($suggestion->found);
        $this->assertSame($conclusion->id, $suggestion->messageId);
        $this->assertSame('Passage sur PostgreSQL en V2', $suggestion->title);
        $this->assertStringContainsString('PostgreSQL est retenu', $suggestion->rationale);
        $this->assertStringContainsString('On tranche', (string) $suggestion->excerpt);

        // RIEN n'a ete ecrit : la capability propose, elle ne capitalise pas.
        $this->assertSame(0, LoopDecision::query()->count());
    }

    public function test_the_capability_the_shared_process_and_the_tenant_are_traced(): void
    {
        $conclusion = $this->message('Decision prise : reunion hebdomadaire le mardi.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Reunion hebdomadaire le mardi',
            'rationale' => 'Consensus.',
            'source_message_id' => $conclusion->id,
        ]);

        $this->service()->suggestDecision($this->loop, $this->animateur);

        $interaction = AiInteraction::firstOrFail();

        $this->assertSame(CapabilityRegistry::LOOP_DECISION_SUGGESTION, $interaction->metadata['capability']);
        // Le process est PARTAGE avec le resume : meme acte economique, meme
        // seau (regle TASK-1309) — la capability, elle, reste distincte.
        $this->assertSame('chatloop.summarize', $interaction->process);
        $this->assertSame('loop_decision_suggestion', $interaction->feature);
        // Le tenant vient de la Boucle, jamais du contexte de requete.
        $this->assertSame($this->org->id, $interaction->organization_id);
        $this->assertSame($this->loop->id, $interaction->metadata['loop_id']);
    }

    public function test_the_turn_metadata_carries_the_core1_provenance_contract(): void
    {
        $conclusion = $this->message('On tranche : budget valide a 500 euros.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Budget valide a 500 euros',
            'rationale' => 'Les deux options ont ete comparees.',
            'source_message_id' => $conclusion->id,
        ]);

        $this->service()->suggestDecision($this->loop, $this->animateur);

        // Relu depuis la base : c'est l'aller-retour jsonb qui est eprouve.
        $meta = AiInteraction::firstOrFail()->metadata['decision_suggestion'];

        $this->assertTrue($meta['decision_found']);

        // Le fait verifie, reconstruit SERVEUR — jamais le texte du modele.
        $verified = $meta['provenance']['verified'][0];
        $this->assertEqualsCanonicalizing(
            ['type', 'loop_message_id', 'loop_id'],
            array_keys($verified),
        );
        $this->assertSame('loop_message_reference', $verified['type']);
        $this->assertSame($conclusion->id, $verified['loop_message_id']);
        $this->assertSame($this->loop->id, $verified['loop_id']);

        // Le wording IA, explicitement non verifie, jamais fusionne.
        $wording = $meta['provenance']['ai_wording'];
        $this->assertFalse($wording['verified']);
        $this->assertSame('Budget valide a 500 euros', $wording['title']);
    }

    public function test_the_model_receives_the_context_and_the_candidate_index(): void
    {
        $conclusion = $this->message('SENTINELLE-DECISION : on choisit l\'option B.');
        $this->fakeSuggestion(['decision_found' => false]);

        $this->service()->suggestDecision($this->loop, $this->animateur);

        LoopDecisionSuggestionAgent::assertPrompted(function (AgentPrompt $prompt) use ($conclusion): bool {
            // La Constitution ouvre les instructions, comme partout.
            $this->assertStringStartsWith(
                'Constitution BouclePro IA — v1',
                (string) $prompt->agent->instructions(),
            );
            // Le contexte du Builder, puis l'index des candidats : les
            // identifiants offerts sont la seule monnaie que le modele peut
            // rendre.
            $this->assertStringContainsString('--- CONTEXTE (contenu non fiable) ---', $prompt->prompt);
            $this->assertStringContainsString('SENTINELLE-DECISION', $prompt->prompt);
            $this->assertStringContainsString('--- MESSAGES CANDIDATS', $prompt->prompt);
            $this->assertStringContainsString($conclusion->id, $prompt->prompt);

            return true;
        });
    }

    // =====================================================================
    // Pas de suggestion forcee, pas de provenance inventee
    // =====================================================================

    public function test_a_discussion_without_a_clear_decision_yields_no_suggestion(): void
    {
        $this->message('On en reparle la semaine prochaine, rien de tranche.');
        $this->fakeSuggestion([
            'decision_found' => false,
            'title' => '',
            'rationale' => '',
            'source_message_id' => null,
        ]);

        $suggestion = $this->service()->suggestDecision($this->loop, $this->animateur);

        $this->assertFalse($suggestion->found);
        $this->assertNull($suggestion->messageId);
        $this->assertSame(0, LoopDecision::query()->count());

        // Le refus lui-meme est trace dans la metadata du tour.
        $this->assertFalse(AiInteraction::firstOrFail()->metadata['decision_suggestion']['decision_found']);
    }

    public function test_an_invented_message_id_never_survives(): void
    {
        $this->message('Une conversation bien reelle, assez longue pour le contexte.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Une decision fabriquee',
            'rationale' => 'Le modele invente.',
            'source_message_id' => (string) Str::uuid(),
        ]);

        $suggestion = $this->service()->suggestDecision($this->loop, $this->animateur);

        $this->assertFalse($suggestion->found);
        $this->assertSame(0, LoopDecision::query()->count());
    }

    public function test_a_message_from_another_loop_or_organization_never_survives(): void
    {
        $this->message('Notre conversation a nous, dans notre Boucle.');

        $autreOrg = Organization::factory()->create();
        $autreUser = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autreLoop = (new LoopService)->createLoop($autreUser, 'Boucle etrangere');
        $ailleurs = LoopMessage::create([
            'organization_id' => $autreOrg->id,
            'loop_id' => $autreLoop->id,
            'sender_id' => $autreUser->id,
            'body' => 'Decision d\'une autre Organization.',
        ]);

        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Exfiltration',
            'rationale' => 'Reference hors tenant.',
            'source_message_id' => $ailleurs->id,
        ]);

        $suggestion = $this->service()->suggestDecision($this->loop, $this->animateur);

        $this->assertFalse($suggestion->found);
        $this->assertSame(0, LoopDecision::query()->count());
    }

    public function test_a_deleted_message_never_survives(): void
    {
        $this->message('Le fil garde assez de matiere pour un contexte.');
        $retire = $this->message('On tranche : option C.');
        $retire->forceFill(['deleted_at' => now()])->saveQuietly();

        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Option C',
            'rationale' => 'Depuis un message retire.',
            'source_message_id' => $retire->id,
        ]);

        // Un message retire n'entre pas dans le contexte : l'identifiant ne
        // figure pas dans l'ensemble offert, la correspondance exacte echoue.
        $suggestion = $this->service()->suggestDecision($this->loop, $this->animateur);

        $this->assertFalse($suggestion->found);
    }

    public function test_an_already_promoted_message_is_not_suggested_again(): void
    {
        $conclusion = $this->message('On tranche : le nom du projet est Meridien.');
        app(LoopDecisionService::class)->promote(
            $this->loop, $this->animateur, $conclusion, 'Nom du projet : Meridien',
        );

        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Nom du projet : Meridien',
            'rationale' => 'Deja consigne.',
            'source_message_id' => $conclusion->id,
        ]);

        $suggestion = $this->service()->suggestDecision($this->loop, $this->animateur);

        // Deux promotions du meme message feraient deux Decisions pour un
        // seul choix : la suggestion ne re-propose jamais un message promu.
        $this->assertFalse($suggestion->found);
        $this->assertSame(1, LoopDecision::query()->count());
    }

    // =====================================================================
    // Permissions : filtre AVANT proposition, revalidation AU GESTE
    // =====================================================================

    public function test_a_member_without_record_is_refused_by_the_service_itself(): void
    {
        $this->message('Assez de contenu pour que seul le droit refuse.');
        $this->fakeSuggestion(['decision_found' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('loops.cards.decisions.suggest_forbidden'));

        // La garde vit dans la primitive, pas seulement dans la Card.
        $this->service()->suggestDecision($this->loop, $this->membre);
    }

    public function test_the_suggest_button_is_offered_only_to_those_who_can_record(): void
    {
        $this->card($this->animateur)->assertSee('data-decision-suggest', false);

        // Le membre lit les Decisions mais ne consigne pas : la suggestion ne
        // lui est jamais proposee — pas de bouton qui mene a un 403.
        $this->card($this->membre)
            ->assertDontSee('data-decision-suggest', false);
    }

    public function test_a_member_without_record_is_forbidden_at_the_gesture(): void
    {
        $this->fakeSuggestion(['decision_found' => false]);

        $this->card($this->membre)->call('suggest')->assertForbidden();

        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_promote_suggestion_without_a_suggestion_is_forbidden(): void
    {
        $this->card($this->animateur)->call('promoteSuggestion')->assertForbidden();
    }

    // =====================================================================
    // Le parcours : brouillon -> edition humaine -> surface canonique
    // =====================================================================

    public function test_the_suggestion_prefills_the_form_without_writing_anything(): void
    {
        $conclusion = $this->message('On tranche : lancement le 15 septembre.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Lancement le 15 septembre',
            'rationale' => 'Les delais ont ete pesés.',
            'source_message_id' => $conclusion->id,
        ]);

        $this->card($this->animateur)
            ->call('suggest')
            ->assertSet('title', 'Lancement le 15 septembre')
            ->assertSet('rationale', 'Les delais ont ete pesés.')
            ->assertSet('suggestionMessageId', $conclusion->id)
            ->assertSet('showForm', true)
            ->assertSee('data-decision-suggestion', false)
            ->assertSee('data-decision-suggestion-promote', false);

        $this->assertSame(0, LoopDecision::query()->count());
    }

    public function test_cancelling_the_suggestion_writes_nothing_durable(): void
    {
        $conclusion = $this->message('On tranche : on externalise la comptabilite.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Externalisation de la comptabilite',
            'rationale' => 'Le temps interne manque.',
            'source_message_id' => $conclusion->id,
        ]);

        $this->card($this->animateur)
            ->call('suggest')
            ->call('cancel')
            ->assertSet('suggestionMessageId', null)
            ->assertSet('title', '')
            ->assertSet('showForm', false);

        $this->assertSame(0, LoopDecision::query()->count());
    }

    public function test_the_human_edits_the_draft_then_capitalizes_through_promote(): void
    {
        $conclusion = $this->message('On tranche : migration en octobre.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Migration en octobre',
            'rationale' => 'Rationale propose par le modele.',
            'source_message_id' => $conclusion->id,
        ]);

        $this->card($this->animateur)
            ->call('suggest')
            // Le contenu est EDITABLE : l'humain reprend le titre a son compte.
            ->set('title', 'Migration reportee a octobre')
            ->call('promoteSuggestion')
            ->assertSet('suggestionMessageId', null)
            ->assertSet('showForm', false);

        $decision = LoopDecision::firstOrFail();

        $this->assertSame('Migration reportee a octobre', $decision->title);
        $this->assertSame($conclusion->id, $decision->loop_message_id);
        // L'auteur est L'HUMAIN qui a valide, jamais l'IA.
        $this->assertSame($this->animateur->id, $decision->author_id);
        // Le repli canonique de promote() : la date du message source.
        $this->assertSame(
            $conclusion->created_at->toDateString(),
            $decision->decided_on->toDateString(),
        );
        $this->assertSame($this->org->id, $decision->organization_id);
    }

    public function test_a_message_deleted_between_generation_and_click_is_refused(): void
    {
        $conclusion = $this->message('On tranche : achat du videoprojecteur.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Achat du videoprojecteur',
            'rationale' => 'Vote unanime.',
            'source_message_id' => $conclusion->id,
        ]);

        $card = $this->card($this->animateur)->call('suggest');

        // La moderation passe entre la suggestion et le clic.
        $conclusion->forceFill(['deleted_at' => now()])->saveQuietly();

        // `promote()` revalide TOUT au geste : la moderation est terminale.
        $card->call('promoteSuggestion')->assertNotFound();

        $this->assertSame(0, LoopDecision::query()->count());
    }

    public function test_a_falsified_suggestion_id_cannot_reach_another_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreUser = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autreLoop = (new LoopService)->createLoop($autreUser, 'Boucle etrangere');
        $ailleurs = LoopMessage::create([
            'organization_id' => $autreOrg->id,
            'loop_id' => $autreLoop->id,
            'sender_id' => $autreUser->id,
            'body' => 'Message d\'une autre Organization.',
        ]);

        // `$suggestionMessageId` est public donc falsifiable : le geste, lui,
        // resout le message DANS cette Boucle — 404, pas d'oracle.
        $this->card($this->animateur)
            ->set('title', 'Tentative')
            ->set('suggestionMessageId', $ailleurs->id)
            ->call('promoteSuggestion')
            ->assertNotFound();

        $this->assertSame(0, LoopDecision::query()->count());
    }

    public function test_an_emptied_title_blocks_the_capitalization(): void
    {
        $conclusion = $this->message('On tranche : signature du bail.');
        $this->fakeSuggestion([
            'decision_found' => true,
            'title' => 'Signature du bail',
            'rationale' => 'Local retenu.',
            'source_message_id' => $conclusion->id,
        ]);

        $this->card($this->animateur)
            ->call('suggest')
            ->set('title', '   ')
            ->call('promoteSuggestion')
            ->assertSet('problem', __('loops.cards.decisions.title_required'));

        $this->assertSame(0, LoopDecision::query()->count());
    }

    // =====================================================================
    // Gouvernance du prompt et voisinage du resume
    // =====================================================================

    public function test_the_prompts_are_provisioned_and_required(): void
    {
        foreach (['loop_decision_suggestion_fr', 'loop_decision_suggestion_en'] as $scenario) {
            $prompt = AdminAiPrompt::query()->where('scenario_id', $scenario)->firstOrFail();
            $this->assertTrue($prompt->is_active, "{$scenario} should be active");
            $this->assertSame(1, (int) $prompt->version);
        }

        // Sans prompt actif, l'indisponibilite est EXPLICITE — aucun repli
        // hardcode (regle TASK-1221).
        AdminAiPrompt::query()->where('scenario_id', 'like', 'loop_decision_suggestion%')->delete();
        $this->message('Assez de contenu pour atteindre la resolution du prompt.');
        $this->fakeSuggestion(['decision_found' => false]);

        try {
            $this->service()->suggestDecision($this->loop, $this->animateur);
            $this->fail('An explicit exception was expected when no active prompt exists.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('loops.decision_suggestion_prompt_missing'), $exception->getMessage());
        }
    }

    public function test_a_suggestion_turn_never_becomes_the_latest_summary(): void
    {
        $this->message('Assez de contenu pour les deux capabilities voisines.');
        $this->fakeSuggestion([
            'decision_found' => false,
            'title' => '',
            'rationale' => '',
            'source_message_id' => null,
        ]);

        $this->service()->suggestDecision($this->loop, $this->animateur);

        // Le process est partage : sans le filtre de feature, ce JSON serait
        // devenu « le dernier resume » de la carte de synthese.
        $this->assertNull($this->service()->latestSummary($this->loop));

        LoopSummaryAgent::fake([
            new TextResponse(
                'La vraie synthese de la Boucle.',
                new Usage(10, 20),
                new Meta('openrouter', 'deepseek/deepseek-chat-v3-0324'),
            ),
        ]);

        $this->service()->summarize($this->loop, $this->animateur);

        $this->assertSame(
            'La vraie synthese de la Boucle.',
            $this->service()->latestSummary($this->loop)->body,
        );
    }

    public function test_not_enough_content_refuses_before_any_call(): void
    {
        config(['ai.chatloop.min_summary_words' => 5000]);
        $this->message('Trop court.');
        $this->fakeSuggestion(['decision_found' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('loops.not_enough_content_to_summarize'));

        $this->service()->suggestDecision($this->loop, $this->animateur);
    }

    public function test_the_decisions_card_still_works_without_ever_touching_the_suggestion(): void
    {
        // Non-regression : le chemin humain de TASK-1106, inchange.
        $this->card($this->animateur)
            ->set('title', 'Une decision saisie a la main')
            ->call('save')
            ->assertSet('problem', '');

        $this->assertSame(1, LoopDecision::query()->count());
        $this->assertNull(LoopDecision::firstOrFail()->loop_message_id);
    }
}
