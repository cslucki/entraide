<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1521 — Un sigle n'est explique que si les sources portent son expansion.
 *
 * ## Le defaut mesure
 *
 * Evaluation Phase 3, 30 questions sur trois corpus reels : DEUX reponses
 * ouvraient sur une expansion d'acronyme que le corpus ne contient pas.
 *
 *   « Le RAG (Referentiel d'Apprentissage et de Gestion) ... »
 *   « Le MVP designe le "Minimum Viable Product" ... »
 *
 * Verifie en base : `Referentiel` et `Apprentissage` ne sont JAMAIS adjacents
 * dans ce Dossier, et `Viable` n'y figure pas du tout. Le reste de ces deux
 * reponses est correctement sourcE et cite ; seule l'ouverture vient de la
 * connaissance generale du modele. Or la question disait « dans ces
 * documents » : le lecteur croit lire le document.
 *
 * ## La cause, dans notre propre instruction
 *
 * `dossiers.answer_preset_instruction` portait deux regles contradictoires :
 * la regle 1 ORDONNAIT l'expansion (« si l'on demande ce qu'est un sigle, la
 * premiere phrase donne ce que le sigle signifie ») pendant que la regle 4
 * interdisait toute connaissance exterieure. Le modele tranchait pour la
 * regle 1 — il obeissait.
 *
 * ## Ce que ces tests mesurent, et ce qu'ils ne mesurent pas
 *
 * Ils mesurent le PROMPT effectivement envoye au fournisseur, jamais la
 * reponse : un modele double repondrait n'importe quoi. La preuve que le
 * comportement change reellement est empirique et vit dans le fichier TASK
 * (harnais `_local/evals/matrice.php`, dimension EXPANSION : 2/30 avant).
 */
class TASK1521AcronymExpansionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier sigles',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1521',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    /**
     * Le corpus nomme le sigle SANS jamais le developper — exactement la
     * situation ou le modele allait chercher l'expansion ailleurs.
     */
    private function mockSearch(): MockInterface
    {
        $ligne = [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 3,
            'content' => "Le ZQX est pilote par l'equipe produit. Le ZQX couvre "
                ."trois etapes et sert de reference pour la planification.",
            'distance' => 0.18,
        ];

        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn([$ligne])->byDefault();

        return $mock;
    }

    private function fakeAgent(string $text = 'Le ZQX est pilote par l\'equipe produit. [S1]'): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function ask(string $question = 'Que signifie ZQX ?'): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('organization.dossiers.answer', [
                'organization' => $this->organization,
                'dossier' => $this->dossier,
            ]), ['question' => $question])
            ->assertOk();
    }

    private function lastPrompt(): string
    {
        $interaction = AiInteraction::query()
            ->where('feature', 'loop_knowledge_answer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($interaction, 'aucun appel na ete trace');

        return (string) $interaction->prompt;
    }

    /**
     * Le prompt, blancs normalises. Sans cela, une assertion mesurerait la
     * MISE EN FORME du heredoc : « general knowledge » est coupe par un retour
     * a la ligne, et la garde rougissait pour un pli, pas pour une absence.
     */
    private function lastPromptNormalise(): string
    {
        return (string) preg_replace('/\s+/u', ' ', $this->lastPrompt());
    }

    // ── La garde ────────────────────────────────────────────────────────────

    /**
     * LE test de cette TASK. La contrainte doit atteindre le fournisseur.
     *
     * Sabotage : retirer la regle 4 de `lang/fr/dossiers.php` → rouge.
     */
    public function test_the_prompt_forbids_expanding_an_acronym_absent_from_the_sources(): void
    {
        $this->mockSearch();
        $this->fakeAgent();

        $this->ask();

        $prompt = $this->lastPromptNormalise();

        $this->assertStringContainsString('Ne développe un sigle que si les sources portent elles-mêmes son expansion', $prompt,
            'la contrainte doit etre transmise au modele, pas seulement ecrite dans le depot');
        $this->assertStringContainsString('SOUS AUCUNE FORME', $prompt,
            'l interdit porte sur la PRESENCE des mots, pas sur une tournure particuliere');
        $this->assertStringContainsString('pas ta culture générale', $prompt,
            'la source interdite doit etre nommee : la culture generale du modele');
    }

    /**
     * La regle 1 ne doit plus ORDONNER l'expansion. C'est la moitie du
     * correctif : sans elle, deux regles se contredisent et le modele obeit
     * a la premiere.
     *
     * Sabotage : retablir « donne ce que le sigle signifie » → rouge.
     */
    public function test_the_first_rule_no_longer_orders_an_unconditional_expansion(): void
    {
        $this->mockSearch();
        $this->fakeAgent();

        $this->ask();

        $prompt = $this->lastPromptNormalise();

        $this->assertStringNotContainsString('donne ce que le sigle signifie', $prompt,
            'cette formulation ordonnait une expansion sans condition');
        $this->assertStringContainsString('donne ce que LES SOURCES disent de ce sigle', $prompt,
            'la premiere phrase reste due, mais rapportee aux sources');
    }

    /**
     * Un lecteur anglophone recoit la MEME contrainte. Une garde ecrite dans
     * une seule langue n'est pas une garde : la locale du lecteur choisit le
     * preset (`readerLocale()`).
     *
     * Sabotage : retirer la regle 4 de `lang/en/dossiers.php` → rouge.
     */
    public function test_an_english_reader_receives_the_same_constraint(): void
    {
        $this->owner->forceFill(['preferred_locale' => 'en'])->save();

        $this->mockSearch();
        $this->fakeAgent('The ZQX is run by the product team. [S1]');

        $this->ask('What does ZQX mean?');

        $prompt = $this->lastPromptNormalise();

        $this->assertStringContainsString('Only expand an acronym when the sources themselves carry that expansion', $prompt);
        $this->assertStringContainsString('IN ANY FORM', $prompt);
        $this->assertStringContainsString('not your general knowledge', $prompt);
        $this->assertStringNotContainsString('the first sentence gives what it stands for', $prompt,
            'la formulation anglaise inconditionnelle doit avoir disparu elle aussi');
    }

    /**
     * Le Shell passe par `answer()` depuis TASK-1519 : il herite donc de la
     * contrainte sans qu'on la duplique. Ce test verifie l'heritage — s'il
     * rougit, c'est qu'une SECONDE instruction s'est glissee quelque part.
     */
    public function test_the_shell_dossier_turn_carries_the_same_constraint(): void
    {
        $this->mockSearch();
        $this->fakeAgent();

        $contexte = app(AiShellPageContext::class)->resolve(
            $this->owner,
            $this->organization,
            AiShellPageContext::KIND_DOSSIER,
            $this->dossier->id,
        );

        $this->actingAs($this->owner);
        app(AiShellResponder::class)->respond(
            $this->organization,
            $this->owner,
            'Que signifie ZQX ?',
            $contexte,
        );

        $this->assertStringContainsString('Ne développe un sigle que si les sources portent', $this->lastPromptNormalise(),
            'le tour de Shell doit heriter de la contrainte, pas en porter une copie');
    }

    // ── Non-regression ──────────────────────────────────────────────────────

    /**
     * La vue d'ensemble (`generate()`) emploie `insights_preset_question`, une
     * chaine DISTINCTE : elle ne doit ni gagner ni perdre quoi que ce soit.
     * Une synthese n'est pas une reponse a une question.
     */
    public function test_the_overview_preset_is_untouched(): void
    {
        $fr = (string) trans('dossiers.insights_preset_question', [], 'fr');
        $en = (string) trans('dossiers.insights_preset_question', [], 'en');

        $this->assertStringNotContainsString('Ne développe un sigle', $fr,
            'la contrainte appartient a la reponse a une question, pas a la synthese');
        $this->assertStringNotContainsString('Only expand an acronym', $en);
        $this->assertNotSame('', trim($fr), 'le preset de synthese doit rester non vide');
    }

    /**
     * La contrainte ne doit pas devenir un refus general : le modele garde le
     * droit de developper un sigle QUE LES SOURCES developpent. Sans cette
     * garde, un correctif trop zele rendrait « ARIA signifie ARtistic
     * Intelligence Alliance » impossible — une reponse pourtant correcte.
     */
    public function test_the_rule_still_allows_an_expansion_that_the_sources_carry(): void
    {
        $this->mockSearch();
        $this->fakeAgent();

        $this->ask();

        $prompt = $this->lastPromptNormalise();

        $this->assertStringContainsString('que si les sources portent elles-mêmes son', $prompt,
            'la regle est CONDITIONNELLE : elle autorise l expansion que les sources portent');
        $this->assertStringNotContainsString('Ne développe jamais un sigle', $prompt,
            'une interdiction absolue casserait une reponse correcte');
    }
}
