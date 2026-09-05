<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1400 — une reponse aux Dossiers suit la langue de l'Organization.
 *
 * ## Le defaut, et comment il a ete trouve
 *
 * Il n'a PAS ete trouve par un test. Il a ete trouve par la SECONDE
 * repetition du parcours de demonstration : meme question, meme tenant
 * anglais, meme code — et la reponse est revenue en francais, alors que la
 * premiere repetition avait repondu en anglais. Aucun test unitaire n'aurait
 * pu le voir, parce que le defaut n'est pas une valeur fausse : c'est une
 * valeur NON DETERMINEE.
 *
 * ## Trois ancrages francais, zero regle
 *
 * | ancrage | ou |
 * |---|---|
 * | le prompt administrable `loop_knowledge_answer` v3, integralement francais | `admin_ai_prompts` |
 * | l'etiquette « Question du membre : », codee en dur | dans le prompt UTILISATEUR, au plus pres de la question |
 * | la locale du contexte, prise sur le LECTEUR | `str_starts_with(app()->getLocale(), 'en')` |
 *
 * Et aucune consigne de langue, nulle part. Face a une question anglaise sur
 * des sources anglaises, le modele arbitrait donc seul — et il changeait
 * d'avis d'un appel a l'autre.
 *
 * ## Ce que la tranche fait, et ce qu'elle ne fait pas
 *
 * Elle ajoute une consigne de langue EN CODE, ecrite dans la langue qu'elle
 * exige, et localise l'etiquette par la meme autorite. Elle ne touche pas au
 * prompt en base : le reecrire changerait le comportement de TOUS les tenants,
 * francais compris, pour un defaut qui n'est qu'une regle manquante.
 *
 * ## Pourquoi ces gardes mesurent les INSTRUCTIONS
 *
 * Un test ne peut pas verifier qu'un modele repond dans la bonne langue —
 * cela reviendrait a tester le fournisseur. Ce qui se garde, c'est le CONTRAT
 * envoye : la consigne part-elle, dans la bonne langue, a chaque appel. Le
 * reste est verifie en runtime reel, au navigateur.
 */
class TASK1400LoopKnowledgeAnswerLocaleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisationEn;

    private Organization $organisationFr;

    private User $membreEn;

    private User $membreFr;

    private Loop $boucleEn;

    private Loop $boucleFr;

    private SondeRecherche $recherche;

    /** La question du DERNIER appel — elle identifie son prompt parmi les enregistres. */
    private string $derniereQuestion = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationEn = Organization::factory()->create(['locale' => 'en']);
        $this->organisationFr = Organization::factory()->create(['locale' => 'fr']);

        $this->membreEn = User::factory()->create(['organization_id' => $this->organisationEn->id]);
        $this->membreFr = User::factory()->create(['organization_id' => $this->organisationFr->id]);

        app()->instance('current_organization', $this->organisationEn);
        $this->boucleEn = (new LoopService)->createLoop($this->membreEn, 'Sonic Terrain');

        app()->instance('current_organization', $this->organisationFr);
        $this->boucleFr = (new LoopService)->createLoop($this->membreFr, 'Terrain sonore');

        app()->instance('current_organization', $this->organisationEn);

        foreach ([[$this->organisationEn, $this->boucleEn, $this->membreEn], [$this->organisationFr, $this->boucleFr, $this->membreFr]] as [$org, $boucle, $membre]) {
            Dossier::factory()->create([
                'organization_id' => $org->id,
                'owner_id' => $membre->id,
                'name' => 'Dossier '.$org->locale,
                'visibility' => Dossier::VISIBILITY_LOOP,
                'shared_with_loop_id' => $boucle->id,
            ]);

            OrganizationAiSetting::factory()->create([
                'organization_id' => $org->id,
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'api_key' => 'sk-or-'.$org->locale,
            ]);
        }

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organisationEn->id, $this->organisationFr->id],
            'ai_pricing.overrides' => [],
        ]);

        $this->recherche = new SondeRecherche;
        $this->app->instance(DossierSemanticSearchService::class, $this->recherche);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // Le contrat de langue
    // =====================================================================

    /**
     * Une Organization ANGLAISE exige une reponse anglaise.
     *
     * La mesure porte sur les INSTRUCTIONS envoyees a l'agent, c'est-a-dire
     * sur le contrat lui-meme — pas sur ce que le modele en fait, qui ne se
     * teste pas.
     */
    public function test_an_english_organization_demands_an_english_answer(): void
    {
        $this->interroger($this->boucleEn, $this->membreEn, 'What do the reviewers say?');

        $this->assertStringContainsString('Always answer in ENGLISH', $this->instructionsEnvoyees());
        $this->assertStringNotContainsString('Réponds TOUJOURS en FRANÇAIS', $this->instructionsEnvoyees());
    }

    /**
     * Une Organization FRANCAISE reste francaise.
     *
     * Le contre-exemple indispensable : une correction qui basculerait tout en
     * anglais passerait la mesure precedente en cassant le parcours de
     * production.
     */
    public function test_a_french_organization_demands_a_french_answer(): void
    {
        $this->interroger($this->boucleFr, $this->membreFr, 'Que disent les relecteurs ?');

        $this->assertStringContainsString('Réponds TOUJOURS en FRANÇAIS', $this->instructionsEnvoyees());
        $this->assertStringNotContainsString('Always answer in ENGLISH', $this->instructionsEnvoyees());
    }

    // =====================================================================
    // L'autorite : l'Organization, jamais le lecteur
    // =====================================================================

    /**
     * Un lecteur FRANCAIS dans une Organization anglaise obtient l'anglais.
     *
     * Le coeur de l'arbitrage, et la seule mesure qui distingue « suivre
     * l'Organization » de « suivre celui qui pose la question ». Sans elle,
     * un service qui lirait encore `app()->getLocale()` resterait vert.
     */
    public function test_a_french_reader_in_an_english_organization_still_gets_english(): void
    {
        App::setLocale('fr');

        $this->interroger($this->boucleEn, $this->membreEn, 'What do the reviewers say?');

        $this->assertStringContainsString('Always answer in ENGLISH', $this->instructionsEnvoyees());
    }

    /**
     * Et la symetrie, qui n'est pas gratuite.
     *
     * Sans elle, une correction qui forcerait l'anglais partout — au lieu de
     * suivre l'Organization — passerait les trois mesures precedentes.
     */
    public function test_an_english_reader_in_a_french_organization_still_gets_french(): void
    {
        App::setLocale('en');

        $this->interroger($this->boucleFr, $this->membreFr, 'What do the reviewers say?');

        $this->assertStringContainsString('Réponds TOUJOURS en FRANÇAIS', $this->instructionsEnvoyees());
    }

    // =====================================================================
    // Le determinisme, qui est le sujet meme de la tranche
    // =====================================================================

    /**
     * Deux appels identiques envoient la MEME consigne.
     *
     * C'est la garde qui repond au defaut tel qu'il s'est manifeste : non pas
     * une mauvaise langue, mais une langue qui CHANGE entre deux appels. La
     * consigne etant derivee d'une donnee stable, elle ne peut plus varier.
     */
    public function test_two_identical_calls_send_the_same_language_instruction(): void
    {
        $this->interroger($this->boucleEn, $this->membreEn, 'What do the reviewers say?');
        $premier = $this->instructionsEnvoyees();

        $this->interroger($this->boucleEn, $this->membreEn, 'What do the reviewers say?');
        $second = $this->instructionsEnvoyees();

        $this->assertSame($premier, $second);
        $this->assertStringContainsString('Always answer in ENGLISH', $premier);
    }

    /**
     * L'etiquette de la question suit la meme autorite.
     *
     * Elle vit dans le prompt UTILISATEUR, au plus pres de la question : la
     * laisser en francais rouvrirait, a l'endroit le plus influent, l'ancrage
     * que la consigne vient de fermer. Mesure faite sur le prompt envoye, pas
     * sur les instructions.
     */
    public function test_the_question_label_follows_the_organization_too(): void
    {
        $this->interroger($this->boucleEn, $this->membreEn, 'What do the reviewers say?');

        $prompt = $this->promptEnvoye();
        $this->assertStringContainsString('Member question:', $prompt);
        $this->assertStringNotContainsString('Question du membre', $prompt);
    }

    /**
     * Chaque tenant recoit SA langue, dans la meme execution.
     *
     * L'isolation demandee : deux Organizations interrogees l'une apres
     * l'autre ne doivent pas se contaminer — ce qui arriverait si la langue
     * etait posee dans un etat global plutot que derivee a chaque appel.
     */
    public function test_each_tenant_gets_its_own_language(): void
    {
        $this->interroger($this->boucleEn, $this->membreEn, 'What do the reviewers say?');
        $anglaises = $this->instructionsEnvoyees();

        $this->interroger($this->boucleFr, $this->membreFr, 'Que disent les relecteurs ?');
        $francaises = $this->instructionsEnvoyees();

        $this->assertStringContainsString('Always answer in ENGLISH', $anglaises);
        $this->assertStringContainsString('Réponds TOUJOURS en FRANÇAIS', $francaises);
        $this->assertNotSame($anglaises, $francaises);
    }

    /**
     * Et la locale de l'application n'est jamais deplacee.
     *
     * Le contre-exemple de la METHODE : produire la bonne langue en bougeant
     * la locale globale la laisserait bougee pour la suite de la requete.
     */
    public function test_the_application_locale_is_never_moved(): void
    {
        App::setLocale('fr');

        $this->interroger($this->boucleEn, $this->membreEn, 'What do the reviewers say?');

        $this->assertSame('fr', App::getLocale());
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function interroger(Loop $boucle, User $membre, string $question): void
    {
        // Le contexte de tenant de la requete suit la Boucle interrogee — c'est
        // ce que fait le middleware en production. Le laisser fige sur une seule
        // Organization ferait refuser l'autre AVANT tout appel au modele, et la
        // garde serait alors verte pour la mauvaise raison.
        $organisation = $boucle->organization()->firstOrFail();
        app()->instance('current_organization', $organisation);
        config(['ai.dossiers.semantic_search.organization_ids' => [$organisation->id]]);

        $this->recherche->lignes = [$this->ligne($boucle)];

        LoopKnowledgeAgent::fake([
            new TextResponse('Answer [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);

        $this->derniereQuestion = $question;

        app(LoopKnowledgeAnswerService::class)->answer($boucle, $membre, $question);
    }

    /**
     * Les instructions REELLEMENT portees par l'agent, pour le DERNIER appel.
     *
     * Le filtre sur la question n'est pas un detail de confort : le faux agent
     * accumule les prompts sur toute la duree du test, et `assertPrompted`
     * s'arrete au PREMIER qui satisfait le rappel. Sans ce filtre, deux appels
     * successifs rendraient tous les deux les instructions du premier — et la
     * garde d'isolation entre tenants serait verte en comparant une valeur
     * avec elle-meme.
     */
    private function instructionsEnvoyees(): string
    {
        $capturees = '';

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt) use (&$capturees): bool {
            if (! str_contains($prompt->prompt, $this->derniereQuestion)) {
                return false;
            }

            $capturees = (string) $prompt->agent->instructions();

            return true;
        });

        return $capturees;
    }

    private function promptEnvoye(): string
    {
        $capture = '';

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt) use (&$capture): bool {
            if (! str_contains($prompt->prompt, $this->derniereQuestion)) {
                return false;
            }

            $capture = $prompt->prompt;

            return true;
        });

        return $capture;
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(Loop $boucle): array
    {
        $dossier = Dossier::query()->where('shared_with_loop_id', $boucle->id)->firstOrFail();

        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $dossier->id,
            'dossier_name' => $dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Reviewer feedback digest',
            'slug' => 'reviewer-feedback-digest',
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'content' => 'The reviewers criticised the evaluation plan for describing activities.',
            'distance' => 0.2,
        ];
    }
}

class SondeRecherche extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $lignes = [];

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null): array
    {
        return array_slice($this->lignes, 0, $candidateLimit ?? $limit);
    }
}
