<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1522, etage GENERATION (Cas C de la SPEC).
 *
 * Une fois la structure du tableau rendue au modele — cellules, lignes,
 * en-tetes portes par chaque valeur — le prompt contenait bien
 * « Total | ... | PMs: 493.3 ¶ », et le modele repondait ENCORE « le budget
 * total demande est de 493.3 » (3 tirages sur 3), un tirage le relibellant
 * meme en euros. La presupposition de la question l'emportait sur l'unite.
 *
 * La regle ajoutee a `dossiers.answer_preset_instruction` est generique :
 * une quantite ne repond que dans l'unite demandee, aucune devise n'est
 * ajoutee a une valeur qui n'en porte pas, et une presupposition ne se
 * confirme jamais avec une valeur d'une autre nature. Aucune valeur, aucun
 * corpus, aucune devise n'y est code en dur. Mesure apres : 3 abstentions
 * sur 3, FSTP conserve.
 *
 * Ces tests mesurent le PROMPT envoye au fournisseur, jamais la reponse.
 */
class TASK1522AnswerUnitGuardTest extends TestCase
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
            'name' => 'Dossier tableaux',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1522',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();

        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'effort.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 2,
            'content' => '| WP1 | WP2 | PMs ¶ Alpha | WP1: 5 | WP2: 6 | PMs: 11 ¶ Total | WP1: 5 | WP2: 6 | PMs: 11 ¶',
            'distance' => 0.2,
        ]])->byDefault();

        LoopKnowledgeAgent::fake([
            new TextResponse('Les sources n etablissent pas ce montant.', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function ask(string $question): string
    {
        $this->actingAs($this->owner)
            ->postJson(route('organization.dossiers.answer', [
                'organization' => $this->organization,
                'dossier' => $this->dossier,
            ]), ['question' => $question])
            ->assertOk();

        $interaction = AiInteraction::query()->where('feature', 'loop_knowledge_answer')
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->assertNotNull($interaction, 'aucun appel na ete trace');

        // Blancs normalises : une assertion ne doit pas mesurer la mise en
        // forme du heredoc.
        return (string) preg_replace('/\s+/u', ' ', (string) $interaction->prompt);
    }

    /**
     * LE test de cet etage. Sabotage : retirer la regle 5 FR → rouge.
     */
    public function test_the_prompt_binds_a_quantity_to_the_unit_the_question_asks_for(): void
    {
        $prompt = $this->ask('Quel est le budget total demandé ?');

        $this->assertStringContainsString("dans l'unité que la question demande", $prompt);
        $this->assertStringContainsString("n'ajoute jamais une devise ou une unité que la source ne porte pas", $prompt,
            'la devise inventee est le degat visible du P0 : elle doit etre nommee');
    }

    /**
     * La presupposition est nommee : « quel est le budget total ? » suppose
     * qu'un budget existe. Sans cette phrase, le modele confirmait la
     * presupposition avec un total de personnes-mois, structure ou pas.
     *
     * Sabotage : retirer la phrase sur la presupposition → rouge.
     */
    public function test_the_prompt_forbids_confirming_a_presupposition_with_another_nature(): void
    {
        $prompt = $this->ask('Quel est le budget total demandé ?');

        $this->assertStringContainsString('ne confirme jamais cette présupposition', $prompt);
        $this->assertStringContainsString("n'est PAS le budget, même si c'est le seul total disponible", $prompt,
            'le seul total disponible est exactement le piege mesure sur ARIA');
    }

    /**
     * La regle est CONDITIONNELLE : elle ne s'applique qu'en l'absence de
     * valeur portant une devise. « 720 000 € FSTP » reste une reponse due.
     * Une interdiction absolue des montants casserait un fait vert.
     */
    public function test_the_rule_stays_conditional_on_the_absence_of_a_currency_bearing_value(): void
    {
        $prompt = $this->ask('Quel est le budget du FSTP ?');

        $this->assertStringContainsString('ne donnent aucune valeur portant une devise', $prompt,
            'la condition est explicite : la regle ne joue que sans devise dans les sources');
        $this->assertStringNotContainsString('ne donne jamais de montant', $prompt);
    }

    /**
     * Un lecteur anglophone recoit la meme regle. Sabotage : retirer la regle
     * EN seule → rouge, sans toucher au FR.
     */
    public function test_an_english_reader_receives_the_same_rule(): void
    {
        $this->owner->forceFill(['preferred_locale' => 'en'])->save();

        $prompt = $this->ask('What is the total requested budget?');

        $this->assertStringContainsString('only in the unit the question asks for', $prompt);
        $this->assertStringContainsString('never confirm that presupposition', $prompt);
    }

    /**
     * La synthese (`generate()`) emploie un preset distinct : rien n'y change.
     */
    public function test_the_overview_preset_is_untouched(): void
    {
        $this->assertStringNotContainsString('présupposition', (string) trans('dossiers.insights_preset_question', [], 'fr'));
        $this->assertStringNotContainsString('presupposition', (string) trans('dossiers.insights_preset_question', [], 'en'));
    }
}
