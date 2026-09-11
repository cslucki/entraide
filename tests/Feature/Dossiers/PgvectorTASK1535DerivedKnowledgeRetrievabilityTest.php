<?php

namespace Tests\Feature\Dossiers;

use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\BlogPost;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1535 — la connaissance derivee se retrouve depuis la page LIEE, et sur
 * une formulation qui ne reprend pas les mots de la note.
 *
 * ## Ce que T1534 avait laisse de cote, et pourquoi ce n'etait pas un choix
 *
 * Le mandat demande que le meme fait soit retrouvable dans DEUX situations :
 *
 *   Case B — une surface sans rapport avec la Boucle source. Prouve par
 *            `PgvectorTASK1534DerivedKnowledgeRetrievalTest`.
 *   Case A — une surface directement liee au Dossier de la Boucle source.
 *
 * Sur une page Dossier, le Shell ne prend PAS la branche de decouverte : elle
 * s'efface explicitement au profit de la branche documentaire de la page
 * (`dossierAnswerTurn` -> `DossierInsightsService::answer()`). Or T1534 avait
 * laisse ce chemin ferme par defaut.
 *
 * La raison invoquee etait bonne pour `generate()` — un Insight est un
 * artefact dont l'audience est celle du DOSSIER, et y faire entrer une Boucle
 * privee blanchirait la garde. Elle ne vaut PAS pour `answer()`, qui rend une
 * reponse A UNE PERSONNE, bornee par ce que cette personne peut lire, sans
 * rien publier. Les deux avaient ete fermes ensemble : c'est cette confusion
 * que la presente TASK corrige.
 *
 * ## Sur la « paraphrase », et ce que ces tests prouvent vraiment
 *
 * Les embeddings sont DOUBLES : aucun modele reel ne tourne ici. Le double
 * n'est pas constant pour autant — il calcule un sac de mots normalise, donc
 * la distance reflete un vrai recouvrement lexical entre la question et
 * l'extrait.
 *
 * Ce qui est donc mesure : **une question qui ne reprend pas le mot rare de la
 * note la retrouve quand meme**, et la note derivee concourt a egalite avec
 * les Articles et les fichiers dans le meme classement. Ce qui n'est PAS
 * mesure, et qu'aucun double ne peut mesurer : la qualite semantique d'un vrai
 * modele d'embedding. Cela se verifie au banc, sur du contenu reel.
 */
class PgvectorTASK1535DerivedKnowledgeRetrievabilityTest extends TestCase
{
    private const FAIT_RARE = 'ZORGHAMMER';

    private Organization $organization;

    private User $alice;

    private User $bob;

    private Loop $loop;

    private Dossier $rootDossier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Derived knowledge retrievability requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);
        $this->bob = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bob Lemoine']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        foreach ([$this->alice, $this->bob] as $member) {
            LoopMember::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $this->loop->id,
                'user_id' => $member->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $this->rootDossier = app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1535',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai.knowledge.max_distance' => 1.0,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (string $input): array => $this->bagOfWords($input),
            array_map('strval', $prompt->inputs),
        ))->preventStrayEmbeddings();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'La reponse s appuie sur les sources fournies. [S1]',
            new Usage(40, 12), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    // ------------------------------------------------------- CASE A

    public function test_la_connaissance_derivee_se_retrouve_depuis_la_page_du_dossier_de_sa_boucle(): void
    {
        $this->conversation();
        $this->articleDecor();
        $this->derive();

        // La page du Dossier racine de la Boucle source : la surface LIEE.
        // Le Shell y prend la branche documentaire de la page, pas celle de
        // decouverte — cette derniere s'efface explicitement pour un objet
        // documentaire courant.
        $context = app(AiShellPageContext::class)->resolve(
            $this->alice,
            $this->organization,
            AiShellPageContext::KIND_DOSSIER,
            (string) $this->rootDossier->id,
        );
        $this->assertSame(AiShellPageContext::KIND_DOSSIER, $context['kind'],
            'PREMISSE : nous sommes bien sur la page du Dossier');

        $this->actingAs($this->alice);
        $reponse = app(AiShellResponder::class)->respond(
            $this->organization, $this->alice, 'Qui pose la toiture du chantier Belleville ?', $context,
        )['answer'] ?? null;

        $this->assertNotNull($reponse);

        $sources = (string) json_encode($reponse->metadata['sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString(self::FAIT_RARE, $sources,
            'le meme fait doit etre retrouvable depuis la page LIEE comme depuis une page sans rapport');
        $this->assertStringContainsString(
            __('dossiers.derived_source_named', ['loop' => 'Chantier Belleville']),
            $sources,
            'et il s y nomme de la meme facon',
        );
    }

    public function test_la_page_du_dossier_reste_fermee_a_qui_ne_voit_pas_la_boucle(): void
    {
        // Meme fixture que l'ACL de T1534 : Carol voit le Dossier par un
        // partage explicite, appartient a une AUTRE Boucle, et pas a celle-ci.
        $carol = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Carol Vasseur']);

        $autreLoop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $carol->id,
            'name' => 'Commission communication',
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $autreLoop->id,
            'user_id' => $carol->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        DossierMember::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier->id,
            'user_id' => $carol->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $this->alice->id,
        ]);

        $this->conversation();
        $this->articleDecor();
        $this->derive();

        $context = app(AiShellPageContext::class)->resolve(
            $carol, $this->organization, AiShellPageContext::KIND_DOSSIER, (string) $this->rootDossier->id,
        );
        $this->assertSame(AiShellPageContext::KIND_DOSSIER, $context['kind'],
            'PREMISSE : Carol accede bien a la page de ce Dossier');

        $this->actingAs($carol);
        $reponse = app(AiShellResponder::class)->respond(
            $this->organization, $carol, 'Qui pose la toiture du chantier Belleville ?', $context,
        )['answer'] ?? null;

        // Ouvrir Case A ne doit rien ouvrir d'autre : la page du Dossier est
        // exactement l'endroit ou le blanchiment serait le plus tentant.
        $this->assertStringNotContainsString(
            self::FAIT_RARE,
            (string) json_encode($reponse, JSON_UNESCAPED_UNICODE),
            'la page du Dossier ne doit pas devenir la porte derobee de la Boucle privee',
        );
    }

    public function test_l_insight_partage_du_dossier_ne_voit_aucune_connaissance_derivee(): void
    {
        // Pas d'Article ici : le Dossier ne contient QUE la note derivee.
        $this->conversation();
        $this->derive();

        $service = app(\App\Services\Dossiers\DossierInsightsService::class);
        $dossier = $this->rootDossier->fresh();

        // PREMISSE : la note est bien indexee dans CE Dossier, et Alice, qui
        // est membre de la Boucle, la retrouve par le chemin par-lecteur.
        $this->assertGreaterThan(0,
            DossierChunk::where('dossier_id', $dossier->id)
                ->whereNotNull('derived_knowledge_note_id')->count());
        $this->assertStringContainsString(
            self::FAIT_RARE,
            (string) json_encode($this->cherche($this->alice, 'Qui pose la toiture ?'), JSON_UNESCAPED_UNICODE),
        );

        // Et pourtant, pour Smart Dossier, ce Dossier est VIDE.
        //
        // `hasIndexedContent()` utilise exactement la primitive qui nourrit
        // `generate()`. La mesurer ici plutot que de derouler un Insight
        // complet evite d'arrimer ce test au format a rubriques de T1341 :
        // ce qu'on veut pinner, c'est l'ELIGIBILITE, pas la mise en page.
        //
        // Un Insight est un artefact range dans le Dossier et relu par tout
        // son cercle — y compris par qui ne voit pas la Boucle. L'intersection
        // ne se laisse pas porter par un artefact partage.
        $this->assertFalse($service->hasIndexedContent($this->organization, $dossier),
            'un Insight partage ne doit jamais pouvoir se nourrir d une conversation de Boucle');
    }

    // --------------------------------------------- FORMULATIONS

    public function test_une_question_qui_ne_reprend_pas_les_mots_de_la_note_la_retrouve_quand_meme(): void
    {
        $this->conversation();
        $this->articleDecor();
        $this->derive();

        // Aucun de ces enonces ne contient le mot rare de la note.
        $formulations = [
            'exacte' => 'Qui pose la toiture du chantier Belleville ?',
            'paraphrase' => 'Quelle entreprise a ete retenue pour le chantier de Belleville ?',
            'indirecte' => 'Le chantier dont Alice parlait, ou en est-on ?',
        ];

        foreach ($formulations as $forme => $question) {
            $this->assertStringNotContainsString(self::FAIT_RARE, $question,
                "PREMISSE : la question « {$forme} » ne contient pas la reponse");

            $lignes = $this->cherche($this->alice, $question);

            $this->assertNotSame([], $lignes, "formulation « {$forme} » : aucun resultat");
            $this->assertSame('derived_knowledge', $lignes[0]['source_type'],
                "formulation « {$forme} » : la note derivee doit primer sur l Article sans rapport");
            $this->assertStringContainsString(self::FAIT_RARE, (string) $lignes[0]['content'],
                "formulation « {$forme} » : l extrait retrouve porte bien le fait");
        }
    }

    public function test_une_question_sur_un_tout_autre_sujet_ne_ramene_pas_la_note(): void
    {
        $this->conversation();
        $this->articleDecor();
        $this->derive();

        // Le pendant indispensable du test precedent : si TOUT remontait, le
        // « la note primait » ne voudrait rien dire. Cette question parle du
        // sujet de l'Article leurre, et c'est lui qui doit sortir.
        $lignes = $this->cherche($this->alice, 'Quels sont les horaires d ouverture du secretariat ?');

        $this->assertNotSame([], $lignes);
        $this->assertSame('article', $lignes[0]['source_type'],
            'le classement suit le CONTENU : une question administrative ne ramene pas la conversation');
    }

    // ------------------------------------------------------------ helpers

    private function conversation(): LoopMessage
    {
        $message = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Pour la toiture du chantier Belleville, on part sur '.self::FAIT_RARE.' : ils posent en fevrier 2027.',
            'type' => 'user',
        ]);

        LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->bob->id,
            'body' => 'D accord pour la toiture en fevrier, je previens la maitrise d ouvrage cette semaine.',
            'type' => 'user',
        ]);

        return $message;
    }

    private function articleDecor(): void
    {
        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->alice->id,
            'title' => 'Compte rendu administratif',
            'slug' => 'compte-rendu-'.Str::uuid(),
            'content' => '<p>Les horaires d ouverture du secretariat changent a la rentree.</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->alice->id,
            'position' => 1,
        ]);

        $contenu = 'Les horaires d ouverture du secretariat changent a la rentree scolaire.';

        DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => $contenu,
            'content_hash' => hash('sha256', 'decor'.Str::uuid()),
            'token_count' => 10,
            'embedding' => $this->bagOfWords($contenu),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    private function derive(): ?DerivedKnowledgeNote
    {
        LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            "L entreprise retenue pour la toiture du chantier Belleville est ".self::FAIT_RARE
                .", avec une pose prevue en fevrier 2027. Alice Renard a annonce ce choix.",
            new Usage(50, 20), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        return app(LoopConversationKnowledgeDeriver::class)->derive($this->loop->fresh());
    }

    /** @return list<array<string, mixed>> */
    private function cherche(User $user, string $question): array
    {
        $dossierIds = app(\App\Ai\Context\DossierAccessScope::class)
            ->accessibleDossierIds((string) $this->organization->id, $user, null);

        if ($dossierIds === []) {
            return [];
        }

        return app(\App\Services\Dossiers\DossierSemanticSearchService::class)->searchAcrossDossiers(
            (string) $this->organization->id,
            $dossierIds,
            $question,
            (string) app(\App\Ai\ProviderResolver::class)
                ->resolveEmbeddingInstance((string) $this->organization->id),
            5,
            [],
            20,
            null,
            app(\App\Services\Dossiers\DerivedChunkEligibility::class)
                ->authorizedLoopIds((string) $this->organization->id, $user),
        );
    }

    /**
     * Un embedding DOUBLE mais non constant : sac de mots normalise.
     *
     * Chaque mot significatif tombe dans une dimension stable (`crc32 % 1536`)
     * et le vecteur est normalise, si bien que la distance cosinus reflete le
     * recouvrement lexical reel entre deux textes. C'est ce qui rend « une
     * autre formulation retrouve la note » mesurable de facon DETERMINISTE,
     * la ou un vecteur constant rendait tout equidistant et un vecteur pilote
     * par mot-clef ne mesurait que le mot-clef lui-meme.
     *
     * Ce n'est pas un modele semantique, et ce fichier ne pretend nulle part
     * le contraire.
     *
     * @return list<float>
     */
    private function bagOfWords(string $texte): array
    {
        $vector = array_fill(0, 1536, 0.0);

        // Une composante constante faible : un vecteur entierement nul n'a pas
        // de direction et la distance cosinus vaudrait NaN.
        $vector[0] = 0.05;

        $mots = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($texte), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($mots as $mot) {
            if (mb_strlen($mot) < 3) {
                continue;
            }

            $vector[crc32($mot) % 1536] += 1.0;
        }

        $norme = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        return $norme > 0.0
            ? array_map(static fn (float $v): float => $v / $norme, $vector)
            : $vector;
    }
}
