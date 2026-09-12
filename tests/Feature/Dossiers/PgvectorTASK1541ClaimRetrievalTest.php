<?php

namespace Tests\Feature\Dossiers;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Ai\Context\DossierAccessScope;
use App\Ai\ProviderResolver;
use App\Jobs\DeriveLoopConversationKnowledge;
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
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Knowledge\ClaimMemory;
use App\Services\Knowledge\DerivedKnowledgeNoteIndexer;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Services\Loops\LoopRootDocumentService;
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
 * TASK-1541 — ce que la bascule change POUR LE LECTEUR, sur le vrai moteur.
 *
 * ## Ce que ce fichier mesure, et ce qu'il ne mesure pas
 *
 * Un double d'embedding rend les vecteurs qu'on lui demande. Lui faire dire
 * « l'enonce precis bat l'Article generique » reviendrait a mesurer sa propre
 * fixture : la dilution se mesure au banc, sur de vrais vecteurs
 * (`_local/task1541/dilution.php`), et nulle part ailleurs.
 *
 * Ce qui se mesure ICI, et qui ne se mesure qu'en SQL reel, ce sont les regles
 * que la nouvelle population traverse :
 *
 *  - **l'ACL.** L'eligibilite derivee joint `derived_knowledge_notes`. Les
 *    claims sont une population NEUVE dans cette table : que l'invariant ait
 *    tenu pour un digest ne dit rien de ce qu'il fait pour eux. Ce qui s'est
 *    dit dans une Boucle privee ne sort pas davantage en N morceaux qu'en un ;
 *  - **la diversite.** `documentKey` compte un claim comme UN document. Le
 *    plafond par document — deux chunks — s'appliquait a toute la conversation
 *    quand elle n'etait qu'un paragraphe ; il s'applique maintenant a chaque
 *    enonce, et plusieurs enonces d'une meme Boucle peuvent donc etre cites ;
 *  - **la supersession.** Une version remplacee disparait du READ par la
 *    jointure `status = active`, sans reindexation d'aucune sorte ;
 *  - **une seule population servie.** Le digest de la meme Boucle n'apparait
 *    plus, alors meme que son texte porte les memes mots.
 */
class PgvectorTASK1541ClaimRetrievalTest extends TestCase
{
    private const QUESTION = 'Quel est le budget des travaux du chantier Belleville ?';

    /** Le montant ne figure QUE dans ce que deux humains se sont dit. */
    private const MONTANT = '486 000';

    private Organization $organization;

    private User $alice;

    private User $bob;

    /** Invitee sur le Dossier racine, membre d'une AUTRE Boucle. */
    private User $carol;

    private Loop $loop;

    private Dossier $rootDossier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Claim retrieval requires PostgreSQL pgvector.');
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

        foreach ([$this->alice, $this->bob] as $membre) {
            LoopMember::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $this->loop->id,
                'user_id' => $membre->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $this->rootDossier = app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        // Carol appartient a une AUTRE Boucle, et ce detail est la condition
        // meme de la mesure : sans Boucle du tout, l'eligibilite sort par son
        // refus global « ferme par defaut » et la garde d'intersection n'est
        // JAMAIS evaluee. C'est la lecon du sabotage de T1534, et elle vaut
        // exactement pareil pour la population neuve.
        $autreLoop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->carol->id,
            'name' => 'Commission communication',
            'visibility' => 'private',
        ]);

        // Bob y est AUSSI, et pour la meme raison de mesure : quand il quitte
        // « Chantier Belleville », il lui reste une Boucle. Sans cela, son
        // depart le fait tomber dans le refus global et le test n'observe plus
        // l'intersection qu'il pretend observer — le sabotage l'a montre, en
        // laissant ce test VERT alors que la moitie Boucle avait disparu.
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

        // Et elle voit le Dossier, nommement. La moitie documentaire de
        // l'intersection dit donc OUI ; c'est la moitie Boucle qui doit
        // trancher, et elle seule.
        DossierMember::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier->id,
            'user_id' => $this->carol->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $this->alice->id,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1541',
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
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();

        // Les vecteurs ne sont pas constants : ce qui parle d'argent est
        // PROCHE de la question, le reste en est loin. Sans cela, « l'enonce
        // remonte » ne dirait rien — tout remonterait.
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (string $input): array => $this->vector($this->parleDArgent($input) ? 0.0 : 0.9),
            array_map('strval', $prompt->inputs),
        ))->preventStrayEmbeddings();
    }

    // ────────────────────────────────── une seule population servie

    public function test_l_enonce_remonte_et_le_paragraphe_de_la_meme_boucle_ne_remonte_plus(): void
    {
        $this->conversation();
        $this->articleDecor();

        // PREMISSE : avant WRITE, le montant n'existe dans aucun chunk. Un
        // zero sur un corpus vide ne prouverait rien.
        $this->assertSame(0, DossierChunk::whereNotNull('derived_knowledge_note_id')->count());
        $this->assertStringNotContainsString(self::MONTANT, $this->jsonDe($this->alice));

        // Le chemin historique d'abord : c'est de LUI que la Boucle bascule.
        $digest = $this->digestHistorique();

        $this->compiler();

        $this->assertSame(0, DossierChunk::where('derived_knowledge_note_id', $digest->id)->count(),
            'le paragraphe perd ses vecteurs des que la Boucle a des enonces');

        $lignes = $this->chercheEnTantQue($this->alice);
        $this->assertStringContainsString(self::MONTANT, (string) json_encode($lignes, JSON_UNESCAPED_UNICODE),
            'l enonce qui repond doit etre retrouvable');

        // Le conteneur porte EXACTEMENT les memes mots : s'il etait servi, le
        // lecteur verrait deux sources dire la meme chose, et le vecteur
        // moyenne du paragraphe irait concurrencer l enonce qui repond.
        $conteneur = app(ClaimMemory::class)->conteneur($this->organization, $this->loop);
        $this->assertNotNull($conteneur);
        $this->assertSame((string) $digest->id, (string) $conteneur->id,
            'le paragraphe devient le conteneur : c est la MEME ligne');
        $this->assertStringContainsString(self::MONTANT, (string) $conteneur->content,
            'PREMISSE : le conteneur porte bien le meme fait');

        $idsServis = array_column($lignes, 'derived_knowledge_note_id');
        $this->assertNotContains((string) $conteneur->id, $idsServis,
            'une seule population est servie : le paragraphe n est plus candidat');
    }

    public function test_plusieurs_enonces_d_une_meme_boucle_comptent_pour_plusieurs_documents(): void
    {
        // LA consequence structurelle de la bascule. `documentKey` compte un
        // claim comme un document : le plafond de deux chunks par document ne
        // s applique plus a toute la conversation, mais a chaque enonce.
        $this->conversation();
        $this->compiler();

        $lignes = $this->chercheEnTantQue($this->alice);

        $documents = array_unique(array_filter(array_map(
            static fn (array $l): string => (string) ($l['derived_knowledge_note_id'] ?? ''),
            $lignes,
        )));

        $this->assertGreaterThanOrEqual(2, count($documents),
            'quand une conversation n etait qu un document, deux extraits epuisaient son plafond');
    }

    public function test_chaque_enonce_se_presente_avec_sa_propre_date(): void
    {
        $this->conversation(ancienBudget: true);
        $this->compiler();

        $titres = array_values(array_unique(array_map(
            static fn (array $l): string => DossierSemanticSearchService::displayTitle($l),
            array_values(array_filter($this->chercheEnTantQue($this->alice),
                static fn (array $l): bool => ($l['source_type'] ?? '') === 'derived_knowledge')),
        )));

        $this->assertGreaterThanOrEqual(2, count($titres),
            'deux enonces dits a huit mois d ecart ne peuvent pas porter le meme intitule');

        foreach ($titres as $titre) {
            $this->assertStringContainsString('Chantier Belleville', $titre);
        }
    }

    // ────────────────────────────────── l'ACL, sur la population neuve

    public function test_qui_voit_le_dossier_sans_voir_la_boucle_ne_lit_aucun_enonce(): void
    {
        $this->conversation();
        $this->compiler();

        // PREMISSE 1 — la moitie documentaire dit oui.
        $perimetre = app(DossierAccessScope::class)
            ->accessibleDossierIds((string) $this->organization->id, $this->carol, null);
        $this->assertContains((string) $this->rootDossier->id, $perimetre,
            'PREMISSE : Carol voit bien le Dossier racine, elle y est invitee nommement');

        // PREMISSE 2 — et elle a des Boucles, donc la clause derivee est bien
        // EVALUEE au lieu d etre court-circuitee par le refus global.
        $sesBoucles = app(DerivedChunkEligibility::class)
            ->authorizedLoopIds((string) $this->organization->id, $this->carol);
        $this->assertNotEmpty($sesBoucles);
        $this->assertNotContains((string) $this->loop->id, $sesBoucles);

        $lignes = $this->chercheEnTantQue($this->carol);

        $this->assertStringNotContainsString(self::MONTANT, (string) json_encode($lignes, JSON_UNESCAPED_UNICODE),
            'un budget dit dans une Boucle privee ne sort pas davantage en trois enonces qu en un paragraphe');

        foreach ($lignes as $ligne) {
            $this->assertNotSame('derived_knowledge', $ligne['source_type'],
                'aucun enonce ne doit meme etre CANDIDAT pour qui ne lit pas la Boucle');
        }
    }

    public function test_un_membre_qui_quitte_la_boucle_perd_les_enonces_au_tour_suivant(): void
    {
        $this->conversation();
        $this->compiler();

        DossierMember::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier->id,
            'user_id' => $this->bob->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $this->alice->id,
        ]);

        $this->assertStringContainsString(self::MONTANT, $this->jsonDe($this->bob),
            'PREMISSE : Bob, membre actif, retrouve bien les enonces');

        LoopMember::where('loop_id', $this->loop->id)->where('user_id', $this->bob->id)
            ->update(['status' => 'left']);

        // Son acces au Dossier lui SURVIT — partage explicite. Sans cela, le
        // test mesurerait `DossierPolicy` au lieu de la moitie Boucle.
        $this->assertContains(
            (string) $this->rootDossier->id,
            app(DossierAccessScope::class)
                ->accessibleDossierIds((string) $this->organization->id, $this->bob->fresh(), null),
            'PREMISSE : et il voit toujours le Dossier',
        );

        $this->assertStringNotContainsString(self::MONTANT, $this->jsonDe($this->bob->fresh()),
            'la garde est evaluee a la LECTURE, enonce par enonce ; aucune reindexation');
    }

    // ────────────────────────────────── la supersession, en SQL

    public function test_une_version_remplacee_d_un_enonce_n_est_plus_retrouvable(): void
    {
        $this->conversation();
        $this->compiler();

        $budget = $this->claimPortant(self::MONTANT);

        $correction = $this->message($this->alice,
            'Correction : le budget travaux de Belleville passe finalement a 531 000 euros.');

        $this->fakePatch([
            ['op' => 'UPDATE', 'claim_id' => (string) $budget->subject_key,
                'text' => 'Le budget travaux du chantier Belleville est de 531 000 euros.',
                'evidence' => [(string) $correction->id]],
        ]);

        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $json = $this->jsonDe($this->alice);

        $this->assertStringContainsString('531 000', $json);
        $this->assertStringNotContainsString(self::MONTANT, $json,
            'deux budgets ne peuvent pas coexister dans le READ : la version remplacee sort');

        // Et l'histoire, elle, n'est pas perdue.
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $budget->fresh()->status);
        $this->assertNotNull($budget->fresh()->superseded_by_id);
    }

    // ────────────────────────────────────────────────────── helpers

    private function conversation(bool $ancienBudget = false): void
    {
        $budget = $this->message($this->alice,
            'Le budget travaux vote pour le chantier Belleville est de '.self::MONTANT.' euros.');

        if ($ancienBudget) {
            $budget->forceFill(['created_at' => now()->subMonths(8)])->save();
        }

        $this->message($this->bob,
            'Cote tresorerie, l acompte de 120 000 euros part a la signature du marche.');

        $this->message($this->alice,
            'Pour la charpente on part sur Vaucanson, malgre les 12% de hausse annoncee.');
    }

    /**
     * Un Article indexe dans le MEME Dossier, qui ne dit rien du montant.
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

    /**
     * Le paragraphe du chemin HISTORIQUE, indexe.
     *
     * Sans lui, « le paragraphe ne remonte plus » ne mesure rien : personne
     * n'aurait jamais essaye de l'indexer, et desarmer la regle d'eclipse
     * laisserait le test vert. Le sabotage l'a montre.
     */
    private function digestHistorique(): DerivedKnowledgeNote
    {
        LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Le budget travaux vote pour le chantier Belleville est de '.self::MONTANT.' euros.'
            .' Un acompte de 120 000 euros part a la signature du marche.'
            .' Vaucanson realise la charpente, avec 12% de hausse.',
            new Usage(50, 20), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $digest = app(LoopConversationKnowledgeDeriver::class)
            ->derive($this->loop->fresh());

        $this->assertNotNull($digest, 'PREMISSE : le chemin historique produit bien un paragraphe');
        $this->assertGreaterThan(0, DossierChunk::where('derived_knowledge_note_id', $digest->id)->count(),
            'PREMISSE : et ce paragraphe est bien SERVI avant la bascule');

        return $digest;
    }

    /**
     * Compile la conversation en enonces, un par sujet — PAR LE JOB.
     *
     * C'est lui qui porte la bascule en production : appeler le compilateur
     * seul laisserait le paragraphe indexe, et le test ne verrait pas la
     * difference entre « la regle d'eclipse marche » et « personne ne l'a
     * jamais appelee ».
     */
    private function compiler(): void
    {
        $messages = LoopMessage::where('loop_id', $this->loop->id)->orderBy('created_at')->get();

        $par = fn (string $jeton): string => (string) $messages
            ->first(fn (LoopMessage $m): bool => str_contains((string) $m->body, $jeton))?->id;

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote pour le chantier Belleville est de '.self::MONTANT.' euros.',
                'evidence' => [$par(self::MONTANT)]],
            ['op' => 'ADD', 'text' => 'Un acompte de 120 000 euros part a la signature du marche Belleville.',
                'evidence' => [$par('120 000')]],
            ['op' => 'ADD', 'text' => 'Vaucanson realise la charpente du chantier Belleville, avec 12% de hausse.',
                'evidence' => [$par('Vaucanson')]],
        ]);

        (new DeriveLoopConversationKnowledge((string) $this->loop->id))->handle(
            app(LoopClaimCompiler::class),
            app(DerivedKnowledgeNoteIndexer::class),
        );

        $this->assertSame(3, DerivedKnowledgeNote::query()->claims()->active()->count(),
            'PREMISSE : les trois enonces doivent etre en memoire');
    }

    private function claimPortant(string $jeton): DerivedKnowledgeNote
    {
        $claim = DerivedKnowledgeNote::query()->claims()->active()->get()
            ->first(fn (DerivedKnowledgeNote $c): bool => str_contains((string) $c->content, $jeton));

        if ($claim === null) {
            throw new \RuntimeException("Enonce introuvable pour « {$jeton} »");
        }

        return $claim;
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
            app(ProviderResolver::class)->resolveEmbeddingInstance((string) $this->organization->id),
            5,
            [],
            20,
            null,
            app(DerivedChunkEligibility::class)
                ->authorizedLoopIds((string) $this->organization->id, $user),
        );
    }

    private function jsonDe(User $user): string
    {
        return (string) json_encode($this->chercheEnTantQue($user), JSON_UNESCAPED_UNICODE);
    }

    private function message(User $sender, string $body): LoopMessage
    {
        return LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $sender->id,
            'body' => $body,
            'type' => 'user',
        ]);
    }

    private function fakePatch(array $operations): void
    {
        LoopClaimPatchAgent::fake(fn (): TextResponse => new TextResponse(
            (string) json_encode(['operations' => $operations], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    private function parleDArgent(string $input): bool
    {
        $input = mb_strtolower($input);

        foreach (['budget', 'euros', 'acompte', self::MONTANT, '531 000'] as $jeton) {
            if (str_contains($input, mb_strtolower($jeton))) {
                return true;
            }
        }

        return false;
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
