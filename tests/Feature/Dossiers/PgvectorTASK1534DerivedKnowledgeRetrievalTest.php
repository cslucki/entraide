<?php

namespace Tests\Feature\Dossiers;

use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierAccessScope;
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
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Services\Dossiers\DossierSemanticSearchService;
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
 * TASK-1534 — la preuve du systeme nerveux, sur le VRAI moteur.
 *
 * La symetrie que le programme vise tient en une phrase :
 *
 *     WRITE : BouclePro apprend de son activite.
 *     READ  : BouclePro retrouve et comprend.
 *
 * Ce fichier mesure la jonction des deux, et rien d'autre. Le fait choisi
 * n'existe QUE dans ce que deux humains se sont dit : aucun Article, aucun
 * fichier, aucun autre chunk ne le porte. Avant WRITE il est introuvable —
 * c'est mesure, pas suppose. Apres WRITE il se retrouve depuis une page qui
 * n'a aucun rapport avec la Boucle, et la reponse le cite en nommant sa
 * source.
 *
 * ## Pourquoi le dataset ArtSciLab n'est pas utilise ici
 *
 * Mesure faite au banc : dans ce jeu de donnees, les faits des conversations
 * sont AUSSI presents dans les documents indexes. Un recall positif apres
 * derivation n'y aurait rien prouve — la reponse serait venue du document. Un
 * zero avant WRITE est la seule premisse qui rend la suite concluante, et il
 * faut le construire.
 *
 * PostgreSQL uniquement : sous SQLite, il n'y a pas de pgvector, et un vert
 * obtenu sans moteur vectoriel serait un faux vert sur la seule question qui
 * compte ici.
 */
class PgvectorTASK1534DerivedKnowledgeRetrievalTest extends TestCase
{
    /** Le fait qui n'existe QUE dans la conversation humaine. */
    private const FAIT_RARE = 'ZORGHAMMER';

    private const QUESTION = 'Qui pose la toiture du chantier Belleville, et quand ?';

    private Organization $organization;

    private User $alice;

    private User $bob;

    /** Invitee sur le Dossier racine, membre d'AUCUNE Boucle. */
    private User $carol;

    private Loop $loop;

    private Dossier $rootDossier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Derived knowledge retrieval requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);
        $this->bob = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bob Lemoine']);
        $this->carol = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Carol Vasseur']);

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

        // Carol est membre d'une AUTRE Boucle, sans rapport.
        //
        // Ce detail n'est pas decoratif, il est la condition meme de la
        // mesure. Une premiere version n'en donnait aucune a Carol : sa liste
        // de Boucles autorisees etait vide, la clause d'eligibilite sortait
        // par son retour anticipe « ferme par defaut », et la garde
        // d'intersection n'etait JAMAIS evaluee. Le sabotage l'a montre —
        // retirer le `whereIn` sur la Boucle source laissait le test VERT.
        //
        // Et c'est le cas realiste : dans un vrai tenant, presque tout le
        // monde appartient a au moins une Boucle. La personne dangereuse
        // n'est pas celle qui n'en a aucune, c'est celle qui en a d'autres.
        $autreLoop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->carol->id,
            'name' => 'Commission communication',
            'visibility' => 'private',
        ]);

        // Bob y est aussi, et pour la meme raison de mesure : quand il quitte
        // « Chantier Belleville », il lui RESTE une Boucle. Sans cela, son
        // depart le ferait tomber dans le meme refus global, et le test
        // n'observerait plus la garde qu'il pretend observer.
        foreach ([$this->carol, $this->bob] as $membre) {
            LoopMember::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $autreLoop->id,
                'user_id' => $membre->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        // LA fixture que le mandat exige : Carol voit le Dossier — elle y est
        // invitee nommement — mais ne voit PAS la Boucle dont la note est
        // tiree. Le perimetre documentaire dit OUI, la moitie Boucle doit
        // dire NON, et c'est elle seule qui tranche.
        //
        // Bob recoit le meme partage explicite, et c'est encore une condition
        // de mesure : quand il quittera la Boucle, son acces au Dossier lui
        // SURVIVRA. Sans ce partage, son depart fermait aussi le perimetre
        // documentaire, et le test observait `DossierPolicy` — une garde qui
        // existait deja — au lieu de la moitie Boucle que cette TASK ajoute.
        // C'est aussi un cas produit banal : on quitte un projet, on garde
        // l'acces aux documents.
        foreach ([$this->carol, $this->bob] as $invite) {
            DossierMember::create([
                'organization_id' => $this->organization->id,
                'dossier_id' => $this->rootDossier->id,
                'user_id' => $invite->id,
                'role' => DossierMember::ROLE_READER,
                'added_by' => $this->alice->id,
            ]);
        }

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1534',
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

        // Les vecteurs ne sont pas constants : un extrait qui parle de la
        // toiture est PROCHE de la question, les autres en sont loin. Sans
        // cela, « la note remonte » ne dirait rien — tout remonterait.
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (string $input): array => $this->vector($this->parleDeLaToiture($input) ? 0.0 : 0.9),
            array_map('strval', $prompt->inputs),
        ))->preventStrayEmbeddings();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'La reponse s appuie sur les sources fournies. [S1]',
            new Usage(40, 12), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    // ------------------------------------------------------ LA PREUVE

    public function test_un_fait_dit_entre_humains_est_introuvable_avant_write_et_retrouvable_apres(): void
    {
        $this->conversation();
        $this->articleDecor();

        // --- AVANT : la premisse, mesuree et non supposee.
        $this->assertSame(0, DossierChunk::whereNotNull('derived_knowledge_note_id')->count());
        $this->assertStringNotContainsString(
            self::FAIT_RARE,
            (string) json_encode($this->chercheEnTantQue($this->alice), JSON_UNESCAPED_UNICODE),
            'PREMISSE : le fait ne doit exister dans AUCUN chunk avant la derivation',
        );

        // La sonde d'AVANT est posee par Bob, celle d'APRES par Alice — deux
        // membres egaux en droits, donc seule la derivation les separe.
        //
        // Pourquoi deux personnes et non deux tours : le fil du Shell est
        // (organization, user). Mesure : la meme question reposee par la meme
        // personne prend la branche `dossier.answer.continuation` (T1530) et
        // rejoue le tour precedent, sources comprises. Le second tour aurait
        // donc montre les sources du PREMIER — un faux rouge qui aurait eu
        // l'air d'un echec de retrieval.
        $avant = $this->shellEnTantQue($this->bob);
        $this->assertStringNotContainsString(self::FAIT_RARE, (string) json_encode($avant, JSON_UNESCAPED_UNICODE),
            'le Shell ne peut pas repondre ce qu aucun document ne porte');

        // --- WRITE
        $note = $this->derive();
        $this->assertInstanceOf(DerivedKnowledgeNote::class, $note);
        $this->assertGreaterThan(0, DossierChunk::where('derived_knowledge_note_id', $note->id)->count());

        // --- APRES : retrouve, cite, nomme, et relie a la conversation.
        $apres = $this->shellEnTantQue($this->alice);
        $this->assertNotNull($apres, 'la decouverte doit desormais trouver de quoi repondre');

        // `JSON_UNESCAPED_SLASHES` : sans lui, `json_encode` rend
        // `http:\/\/localhost\/loops\/...` et une assertion d'URL echoue
        // pour une raison qui n'a rien a voir avec le produit.
        $sources = (string) json_encode($apres->metadata['sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString(self::FAIT_RARE, $sources,
            'le fait dit entre humains est desormais une source citable');
        // Le LIBELLE, pas seulement le nom de la Boucle : le Dossier racine
        // porte deja ce nom-la, si bien qu'une assertion sur « Chantier
        // Belleville » seul etait satisfaite par le champ `dossier_name` —
        // vert meme quand le titre de la source etait VIDE. Le sabotage l'a
        // dit. Ce qui ne peut venir de nulle part ailleurs, c'est la forme
        // « Conversation de la Boucle « … » ».
        $this->assertStringContainsString(
            __('dossiers.derived_source_named', ['loop' => 'Chantier Belleville']),
            $sources,
            'une source derivee se NOMME par la conversation dont elle vient',
        );
        $this->assertStringContainsString('/loops/'.$this->loop->id, $sources,
            'et renvoie a la conversation, pour que le lecteur puisse verifier');
    }

    // -------------------------------------------------------- ACL (A3)

    public function test_qui_voit_le_dossier_sans_voir_la_boucle_ne_lit_rien_de_la_conversation(): void
    {
        $this->conversation();
        $this->derive();

        // La moitie DOCUMENTAIRE de l'intersection dit oui : le Dossier est
        // bien dans le perimetre de Carol. Sans cette assertion, le zero qui
        // suit pourrait venir d'un perimetre vide — un faux vert.
        $perimetre = app(DossierAccessScope::class)
            ->accessibleDossierIds((string) $this->organization->id, $this->carol, null);
        $this->assertContains((string) $this->rootDossier->id, $perimetre,
            'PREMISSE : Carol voit bien le Dossier racine, elle y est invitee nommement');

        // SECONDE PREMISSE, sans laquelle le zero ne prouverait rien : Carol a
        // bien des Boucles, donc la clause d'eligibilite ENTRE dans sa branche
        // derivee au lieu de sortir par le refus global. C'est le `whereIn`
        // sur la Boucle SOURCE qui l'exclut, et lui seul.
        $sesBoucles = app(DerivedChunkEligibility::class)
            ->authorizedLoopIds((string) $this->organization->id, $this->carol);
        $this->assertNotEmpty($sesBoucles, 'PREMISSE : Carol est membre d au moins une Boucle');
        $this->assertNotContains((string) $this->loop->id, $sesBoucles,
            'PREMISSE : mais pas de celle dont la connaissance est tiree');

        // La moitie BOUCLE dit non, et c'est elle qui tranche.
        $lignes = $this->chercheEnTantQue($this->carol);
        $this->assertStringNotContainsString(self::FAIT_RARE, (string) json_encode($lignes, JSON_UNESCAPED_UNICODE),
            'ce qui s est dit dans une Boucle privee ne sort pas par le Dossier ou la note est rangee');

        foreach ($lignes as $ligne) {
            $this->assertNotSame('derived_knowledge', $ligne['source_type'],
                'aucun chunk derive ne doit meme etre CANDIDAT pour qui ne lit pas la Boucle');
        }

        $shell = $this->shellEnTantQue($this->carol);
        $this->assertStringNotContainsString(self::FAIT_RARE, (string) json_encode($shell, JSON_UNESCAPED_UNICODE));
    }

    public function test_un_membre_qui_quitte_la_boucle_perd_l_acces_au_tour_suivant(): void
    {
        $this->conversation();
        $this->derive();

        $this->assertStringContainsString(self::FAIT_RARE,
            (string) json_encode($this->chercheEnTantQue($this->bob), JSON_UNESCAPED_UNICODE),
            'PREMISSE : Bob, membre actif, retrouve bien la connaissance');

        LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->bob->id)
            ->update(['status' => 'left']);

        // Il lui reste une autre Boucle : la clause derivee est donc bien
        // EVALUEE pour lui, et c'est l'appartenance a la Boucle SOURCE qui
        // manque desormais — pas l'absence totale d'appartenance.
        $this->assertNotEmpty(
            app(DerivedChunkEligibility::class)
                ->authorizedLoopIds((string) $this->organization->id, $this->bob->fresh()),
            'PREMISSE : Bob garde une Boucle apres son depart',
        );

        $this->assertContains(
            (string) $this->rootDossier->id,
            app(DossierAccessScope::class)
                ->accessibleDossierIds((string) $this->organization->id, $this->bob->fresh(), null),
            'PREMISSE : et il voit toujours le Dossier, par son partage explicite',
        );

        // Aucune reindexation, aucune synchronisation : la garde se lit.
        $this->assertStringNotContainsString(self::FAIT_RARE,
            (string) json_encode($this->chercheEnTantQue($this->bob->fresh()), JSON_UNESCAPED_UNICODE),
            'la garde est evaluee a la LECTURE ; quitter la Boucle suffit');
    }

    public function test_aucune_connaissance_derivee_ne_franchit_la_frontiere_du_tenant(): void
    {
        $this->conversation();
        $this->derive();

        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        $lignes = app(DossierSemanticSearchService::class)->searchAcrossDossiers(
            (string) $this->organization->id,
            [(string) $this->rootDossier->id],
            self::QUESTION,
            $this->embeddingInstance(),
            5,
            [],
            20,
            null,
            // Meme si un appelant fautif transmettait la Boucle, le tenant de
            // la note est verifie DANS la clause : la frontiere ne depend pas
            // de la bonne foi de l'appelant.
            app(DerivedChunkEligibility::class)
                ->authorizedLoopIds((string) $autreOrg->id, $etranger),
        );

        $this->assertStringNotContainsString(self::FAIT_RARE, (string) json_encode($lignes, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------- UNE SEULE AUTORITE (A4)

    public function test_les_deux_chemins_documentaires_disent_exactement_la_meme_chose(): void
    {
        $this->conversation();
        $this->derive();

        $search = app(DossierSemanticSearchService::class);
        $dossierIds = [(string) $this->rootDossier->id];
        $autorisees = app(DerivedChunkEligibility::class)
            ->authorizedLoopIds((string) $this->organization->id, $this->alice);

        $recherche = fn (?array $loops): array => $search->searchAcrossDossiers(
            (string) $this->organization->id, $dossierIds, self::QUESTION, $this->embeddingInstance(), 5, [], 20, null, $loops,
        );
        $panorama = fn (?array $loops): array => $search->representativeChunksAcrossDossiers(
            (string) $this->organization->id, $dossierIds, 10, $loops,
        );

        $derivees = static fn (array $lignes): int => count(array_filter(
            $lignes, static fn (array $l): bool => $l['source_type'] === 'derived_knowledge',
        ));

        // Autorise : les DEUX chemins proposent la note.
        $this->assertGreaterThan(0, $derivees($recherche($autorisees)),
            'la recherche semantique propose la note a un membre de la Boucle');
        $this->assertGreaterThan(0, $derivees($panorama($autorisees)),
            'la vue d ensemble aussi — sinon elle publierait ou refuserait ce que l autre ne fait pas');

        // Ferme : les DEUX chemins la refusent. Une garde posee sur un seul
        // laisserait l'autre grand ouvert, en silence.
        $this->assertSame(0, $derivees($recherche(null)));
        $this->assertSame(0, $derivees($panorama(null)));
        $this->assertSame(0, $derivees($recherche([])));
        $this->assertSame(0, $derivees($panorama([])));
    }

    public function test_une_version_superseded_n_est_plus_retrouvable(): void
    {
        $message = $this->conversation();
        $v1 = $this->derive();

        $message->forceFill([
            'body' => 'Finalement la toiture sera posee par une autre entreprise, sans '.self::FAIT_RARE.'.',
            'edited_at' => now()->addMinute(),
        ])->save();

        $this->fakeDeriveAgent('La toiture sera posee par une autre entreprise en mars 2027.');
        $v2 = app(LoopConversationKnowledgeDeriver::class)->derive($this->loop->fresh());

        $this->assertNotSame((string) $v1->id, (string) $v2->id);
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $v1->fresh()->status);

        $lignes = $this->chercheEnTantQue($this->alice);
        $json = (string) json_encode($lignes, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::FAIT_RARE, $json,
            'la version corrigee remplace l ancienne : le moteur ne sert pas deux verites');
        $this->assertStringContainsString('mars 2027', $json);
    }

    public function test_un_chunk_orphelin_d_une_note_remplacee_n_est_pas_servi(): void
    {
        $message = $this->conversation();
        $v1 = $this->derive();

        $chunk = DossierChunk::where('derived_knowledge_note_id', $v1->id)->firstOrFail();

        $message->forceFill([
            'body' => 'Finalement la toiture sera posee par une autre entreprise.',
            'edited_at' => now()->addMinute(),
        ])->save();

        $this->fakeDeriveAgent('La toiture sera posee par une autre entreprise en mars 2027.');
        app(LoopConversationKnowledgeDeriver::class)->derive($this->loop->fresh());
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $v1->fresh()->status);

        // On REMET le vecteur de la version remplacee, comme si le nettoyage
        // n'avait pas eu lieu : une reprise interrompue, un crash entre deux
        // instructions, une purge future qui oublie une famille.
        //
        // Ce cas ne se produit pas dans le chemin nominal — l'indexeur
        // supprime — et c'est bien le probleme : la clause `status = active`
        // n'etait donc JAMAIS evaluee, et un sabotage qui la retirait restait
        // vert. Une garde qu'aucun test ne peut faire tomber n'est pas une
        // garde, c'est une intention.
        DossierChunk::create([
            'organization_id' => $chunk->organization_id,
            'dossier_id' => $chunk->dossier_id,
            'blog_post_id' => null,
            'dossier_file_id' => null,
            'derived_knowledge_note_id' => $v1->id,
            'chunk_index' => 0,
            'content' => (string) $v1->content,
            'content_hash' => hash('sha256', 'orphelin'.Str::uuid()),
            'token_count' => 12,
            'embedding' => $this->vector(0.0),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $this->assertStringNotContainsString(
            self::FAIT_RARE,
            (string) json_encode($this->chercheEnTantQue($this->alice), JSON_UNESCAPED_UNICODE),
            'un vecteur survivant a sa note ne doit pas ressusciter une verite remplacee',
        );
    }

    // ------------------------------------------------------------ helpers

    /** Le fait n'est dit QUE la, par des humains, et nulle part ailleurs. */
    private function conversation(): LoopMessage
    {
        $message = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Pour la toiture du chantier, on part sur '.self::FAIT_RARE.' : ils posent en fevrier 2027.',
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

    /**
     * Un Article indexe dans le MEME Dossier, qui ne dit rien du fait rare.
     *
     * Il garantit que le perimetre n'est pas vide avant WRITE : un zero
     * obtenu sur un corpus vide ne prouverait rien du tout.
     */
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

        DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => 'Les horaires d ouverture du secretariat changent a la rentree.',
            'content_hash' => hash('sha256', 'decor'.Str::uuid()),
            'token_count' => 10,
            'embedding' => $this->vector(0.9),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    private function derive(): ?DerivedKnowledgeNote
    {
        $this->fakeDeriveAgent(
            'La toiture du chantier Belleville est posee par '.self::FAIT_RARE.' en fevrier 2027.',
        );

        return app(LoopConversationKnowledgeDeriver::class)->derive($this->loop->fresh());
    }

    private function fakeDeriveAgent(string $texte): void
    {
        LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            $texte, new Usage(50, 20), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function chercheEnTantQue(User $user): array
    {
        $dossierIds = app(DossierAccessScope::class)
            ->accessibleDossierIds((string) $this->organization->id, $user, null);

        if ($dossierIds === []) {
            return [];
        }

        return app(DossierSemanticSearchService::class)->searchAcrossDossiers(
            (string) $this->organization->id,
            $dossierIds,
            self::QUESTION,
            $this->embeddingInstance(),
            5,
            [],
            20,
            null,
            app(DerivedChunkEligibility::class)
                ->authorizedLoopIds((string) $this->organization->id, $user),
        );
    }

    private function shellEnTantQue(User $user): mixed
    {
        $context = app(AiShellPageContext::class)->resolve($user, $this->organization, null, null);
        $this->assertNotSame(AiShellPageContext::KIND_LOOP, $context['kind'] ?? null,
            'la question est posee depuis une page SANS rapport avec la Boucle');

        $this->actingAs($user);

        return app(AiShellResponder::class)
            ->respond($this->organization, $user, self::QUESTION, $context)['answer'] ?? null;
    }

    private function embeddingInstance(): string
    {
        return app(\App\Ai\ProviderResolver::class)
            ->resolveEmbeddingInstance((string) $this->organization->id);
    }

    private function parleDeLaToiture(string $input): bool
    {
        $input = mb_strtolower($input);

        return str_contains($input, mb_strtolower(self::FAIT_RARE)) || str_contains($input, 'toiture');
    }

    /**
     * Premiere composante a 1.0 : un vecteur entierement nul n'a pas de
     * direction, la distance cosinus vaut NaN et pgvector rend alors des
     * lignes que `max_distance` ecarte toutes.
     *
     * @return list<float>
     */
    private function vector(float $second): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;
        $vector[1] = $second;

        return $vector;
    }
}
