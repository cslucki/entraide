<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\ArticleChunker;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TASK-1518 — un fait present dans le corpus ne doit pas devenir un faux refus.
 *
 * ## Le defaut, mesure sur corpus reel
 *
 * « Quel budget FSTP ? » repondait « Je n'ai pas trouve cette information dans
 * les sources auxquelles j'ai acces », alors que le montant figure dans quatre
 * extraits du Dossier.
 *
 * Le retrieval n'y etait pour RIEN : l'extrait porteur arrivait au **rang 1**
 * du classement vectoriel, survivait au bassin de candidats, au repli des
 * quasi-doublons et a la borne de citation. La trace de l'appel le confirme —
 * six sources envoyees, et ni « 720,000 » ni « 30,000 » dans le prompt.
 *
 * ## La cause
 *
 * `buildSourcesBlock()` coupait chaque source a 700 caracteres. Les extraits
 * de ce corpus mesurent 3 532 caracteres en moyenne : **80 % de chacun etait
 * jete avant que le modele ne le voie**, et un fait ecrit plus loin dans le
 * texte disparaissait.
 *
 * 700 caracteres etait — et reste — le budget de la vue d'ensemble
 * (`generate()`), ou chaque source est un ECHANTILLON delibere. `answer()` en
 * avait herite en reutilisant le meme constructeur de bloc. La reutilisation
 * etait bonne ; la constante qu'elle transportait ne l'etait pas.
 *
 * ## Ce que ces tests gardent
 *
 * Le defaut etait invisible aux tests existants parce qu'ils mockent le
 * retrieval avec des contenus COURTS. Ceux-ci utilisent donc des extraits de
 * taille REELLE, et mesurent le prompt effectivement envoye — pas la reponse.
 */
class TASK1518AnswerSourceBudgetTest extends TestCase
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
            'name' => 'Dossier long',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1518',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    private function mockSearch(): MockInterface
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();

        return $mock;
    }

    /**
     * Un extrait de taille REELLE — c'est tout l'enjeu. Le fait utile est
     * place APRES le budget de la vue d'ensemble, la ou vivait le defaut.
     */
    private function longRow(string $fait, int $positionDuFait = 2000, string $ouverture = ''): array
    {
        // L'ouverture doit differer d'une source a l'autre : sinon le repli
        // des quasi-doublons (TASK-1517) les fusionne — a juste titre — et le
        // test mesurerait le repli au lieu du budget de caracteres.
        $remplissage = $ouverture.' '.str_repeat('Le projet decrit ici ses methodes de travail et ses etapes. ', 200);
        $contenu = mb_substr($remplissage, 0, $positionDuFait).' '.$fait.' '.mb_substr($remplissage, 0, 800);

        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'file',
            'blog_post_id' => null,
            'title' => null,
            'slug' => null,
            'dossier_file_id' => (string) Str::uuid(),
            'filename' => 'rapport.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 6,
            'content' => $contenu,
            'distance' => 0.21,
        ];
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function ask(string $question): void
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
        // `latest()` seul ne departage pas deux appels de la MEME seconde, et
        // ce test en fait deux. L'id est un UUIDv7, donc croissant dans le temps.
        $interaction = AiInteraction::query()
            ->where('feature', 'loop_knowledge_answer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($interaction, 'aucun appel na ete trace');

        return (string) $interaction->prompt;
    }

    // ── Le defaut ───────────────────────────────────────────────────────────

    /**
     * LE test de cette TASK. Il mesure le PROMPT, pas la reponse : un modele
     * mocke repondrait n'importe quoi, seul compte ce qu'on lui a montre.
     */
    public function test_a_fact_written_late_in_an_excerpt_still_reaches_the_model(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->longRow('Le budget total est de 720 000 euros pour 24 projets.'),
        ]);

        $this->fakeAgent('Le budget est de 720 000 euros. [S1]');

        $this->ask('Quel est le budget total ?');

        $this->assertStringContainsString('720 000 euros pour 24 projets', $this->lastPrompt(),
            'un fait present dans la source doit atteindre le modele, ou qu il soit dans le texte');
    }

    /**
     * Le vrai contrat de TASK-1518 : AUCUNE seconde coupe.
     *
     * Pas « une coupe plus haute » — aucune. L'extrait est deja borne par le
     * chunker (500 tokens) ; le recouper en CARACTERES revient a arbitrer une
     * longueur que personne ne connait. Mesure en base : un plafond a 4 400
     * aurait tronque 5 extraits sur 583, et le maximum reel atteint 5 486.
     * Le prochain document depassera le prochain chiffre choisi.
     *
     * Ce test envoie un extrait PLUS LONG que tout plafond qu'on aurait pu
     * figer, et exige qu'il arrive entier.
     */
    public function test_an_answer_never_truncates_an_excerpt_whatever_its_length(): void
    {
        $tresLong = $this->longRow('Le repere final vaut OMEGA-FINAL.', 8000);

        $this->assertGreaterThan(8000, mb_strlen($tresLong['content']),
            'la fixture doit depasser tout plafond qu on aurait pu figer');

        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([$tresLong]);
        $this->fakeAgent('Le repere final. [S1]');

        $this->ask('Quel est le repere final ?');

        $this->assertStringContainsString('OMEGA-FINAL', $this->lastPrompt(),
            'une reponse ne doit couper aucun extrait, quelle que soit sa longueur');
    }

    /**
     * Le chunker est LE borneur. Ce test le mesure plutot que de le supposer,
     * et documente l'ordre de grandeur reel : ~500 tokens produisent des
     * milliers de caracteres, sans aucune garantie en caracteres.
     */
    public function test_the_chunker_is_the_only_bound_and_it_is_expressed_in_tokens(): void
    {
        $texte = str_repeat('Cette phrase decrit une etape du projet avec quelques chiffres comme 12 345 euros. ', 400);
        $chunks = app(ArticleChunker::class)->chunk($texte, 500, 50);

        $this->assertNotEmpty($chunks, 'le chunker doit produire des extraits');

        $plusLong = max(array_map(static fn (array $c): int => mb_strlen($c['content']), $chunks));

        // Aucune assertion sur une borne en caracteres : il n'en existe pas.
        // Ce qui est garanti, c'est le nombre de MOTS.
        $plusDeMots = max(array_map(static fn (array $c): int => (int) $c['token_count'], $chunks));

        $this->assertLessThanOrEqual(500, $plusDeMots,
            'le chunker borne en tokens — c est cette borne, et elle seule, qui protege le contexte');
        $this->assertGreaterThan(1000, $plusLong,
            "un extrait de 500 tokens pese des milliers de caracteres ({$plusLong} ici) : "
            .'une borne en caracteres serait un chiffre arbitraire');
    }

    /**
     * La CLASSE du defaut, pas le cas FSTP.
     *
     * Trois rouges avaient ete rapportes comme trois couches distinctes :
     * un fait absent, une abstention, et un COMPTE FAUX pourtant cite. La
     * mesure a montre une seule cause. Ce test la rejoue sur le troisieme
     * cas, le moins evident : une liste tronquee fait compter JUSTE ce qui
     * reste visible, et produit donc un nombre faux avec une citation vraie.
     */
    public function test_a_truncated_list_is_what_makes_a_count_wrong(): void
    {
        // Douze elements, repartis sur toute la longueur de l'extrait.
        $elements = [];
        for ($i = 1; $i <= 12; $i++) {
            $elements[] = $i.' ORGANISME'.$i.' Organisation numero '.$i.' '.str_repeat('description du role tenu. ', 12);
        }

        $row = $this->longRow('', 0);
        $row['content'] = 'Liste des participants '.implode(' ', $elements);

        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([$row]);
        $this->fakeAgent('Douze organismes. [S1]');

        $this->ask('Combien d organismes sont mentionnes ?');

        $prompt = $this->lastPrompt();

        // Le DOUZIEME doit etre visible : sans lui, un modele qui compte bien
        // ce qu'il voit repondra un nombre faux — et le citera.
        $this->assertStringContainsString('ORGANISME12', $prompt,
            'le dernier element de la liste doit atteindre le modele, sinon le compte sera faux ET cite');
        $this->assertStringContainsString('ORGANISME1 ', $prompt, 'le premier aussi, evidemment');
    }

    /**
     * Le correctif ne doit pas se contenter de rallonger la PREMIERE source :
     * chaque source citee doit etre montree entiere, sinon le defaut se
     * deplace au lieu de disparaitre.
     */
    public function test_every_source_is_shown_in_full_not_just_the_first(): void
    {
        $this->mockSearch()->shouldReceive('searchAcrossDossiers')->once()->andReturn([
            $this->longRow('Le premier repere vaut ALPHA-TARDIF.', 2000, 'Premiere source, ouverture distincte.'),
            $this->longRow('Le second repere vaut BETA-TARDIF.', 2000, 'Deuxieme source, ouverture differente.'),
            $this->longRow('Le troisieme repere vaut GAMMA-TARDIF.', 2000, 'Troisieme source, encore une autre.'),
        ]);

        $this->fakeAgent('Trois reperes. [S1][S2][S3]');

        $this->ask('Quels sont les reperes ?');

        $prompt = $this->lastPrompt();

        foreach (['ALPHA-TARDIF', 'BETA-TARDIF', 'GAMMA-TARDIF'] as $repere) {
            $this->assertStringContainsString($repere, $prompt, "la source portant {$repere} a ete tronquee");
        }
    }

    // ── La non-regression de la vue d'ensemble ──────────────────────────────

    /**
     * `generate()` garde SON budget : la synthese montre un ECHANTILLON de
     * chaque document, c'est le sens de cette borne et elle ne bouge pas.
     */
    public function test_the_overview_keeps_its_sample_budget(): void
    {
        // `hasIndexedContent()` interroge la meme primitive AVANT `generate()` :
        // deux appels, pas un. Un `once()` ferait echouer le controleur en 503
        // et le test mesurerait une panne au lieu d'un budget.
        $search = $this->mockSearch();
        $search->shouldReceive('representativeChunksAcrossDossiers')->andReturn([
            $this->longRow('Un detail tres tardif du document.'),
        ]);

        // Le titre de rubrique suit la langue de l'ORGANIZATION et vient de
        // `dossiers.insights_heading_summary` : l'ecrire a la main sans accent
        // ferait tomber la rubrique, et le test mesurerait une panne.
        $heading = trans('dossiers.insights_heading_summary', [], $this->organization->locale ?: 'fr');
        $this->fakeAgent("## {$heading}\n\nUn resume. [S1]");

        $this->actingAs($this->owner)
            ->postJson(route('organization.dossiers.insights', [
                'organization' => $this->organization,
                'dossier' => $this->dossier,
            ]))
            ->assertOk();

        $prompt = $this->lastPrompt();

        $this->assertStringNotContainsString('Un detail tres tardif', $prompt,
            'la vue d ensemble echantillonne volontairement : elle ne doit PAS avoir change de budget');
    }

    /**
     * Les deux chemins ne doivent PAS partager de politique de contexte.
     *
     * Ce test ne compare pas deux nombres — il n'y en a plus qu'un. Il mesure
     * les deux comportements sur le MEME extrait : la vue d'ensemble coupe,
     * la reponse ne coupe pas. Les confondre est exactement ce qui a produit
     * le faux refus.
     */
    public function test_the_overview_samples_where_the_answer_shows_everything(): void
    {
        $repere = 'REPERE-TARDIF-COMMUN';
        $extrait = $this->longRow("Le repere vaut {$repere}.");

        // 1. la REPONSE : l'extrait arrive entier.
        $search = $this->mockSearch();
        $search->shouldReceive('searchAcrossDossiers')->once()->andReturn([$extrait]);
        $this->fakeAgent('Une reponse. [S1]');
        $this->ask('Quel est le repere ?');

        $this->assertStringContainsString($repere, $this->lastPrompt(),
            'la reponse doit montrer l extrait entier');

        // 2. la VUE D'ENSEMBLE : le meme extrait, echantillonne.
        $search->shouldReceive('representativeChunksAcrossDossiers')->andReturn([$extrait]);
        $heading = trans('dossiers.insights_heading_summary', [], $this->organization->locale ?: 'fr');
        $this->fakeAgent("## {$heading}\n\nUn resume. [S1]");

        $this->actingAs($this->owner)
            ->postJson(route('organization.dossiers.insights', [
                'organization' => $this->organization,
                'dossier' => $this->dossier,
            ]))
            ->assertOk();

        $this->assertStringNotContainsString($repere, $this->lastPrompt(),
            'la vue d ensemble echantillonne : elle ne doit PAS avoir change de politique');
    }
}
