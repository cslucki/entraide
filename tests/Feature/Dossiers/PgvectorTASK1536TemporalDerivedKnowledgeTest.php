<?php

namespace Tests\Feature\Dossiers;

use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierAccessScope;
use App\Ai\ProviderResolver;
use App\Models\BlogPost;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
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
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1536 — une connaissance derivee dit QUAND le fait a ete observe.
 *
 * ## Le manque, mesure avant d'etre comble
 *
 * T1534 stocke `observed_at` (le moment ou les humains l'ont dit) et
 * `derived_at` (le moment ou la machine l'a compile), et `selectColumns()`
 * ramene meme `observed_at` dans chaque ligne de retrieval. Mais la colonne
 * s'arretait la : elle n'atteignait ni le bloc de sources donne au modele, ni
 * la provenance rendue au lecteur.
 *
 * Consequence : un extrait de conversation arrivait SANS date, exactement
 * comme un Article. Or les deux ne se lisent pas pareil. « Le chantier demarre
 * le 14 octobre » n'a pas le meme statut selon qu'il a ete dit il y a trois
 * jours ou il y a huit mois — et c'est precisement la question que le produit
 * vise : « le projet dont un collegue parlait MARDI ».
 *
 * ## Ce que cette TASK ne construit pas
 *
 * Ni Temporal Resolver global, ni Memory Compiler multi-pass. La date n'est ni
 * interpretee, ni comparee, ni utilisee pour arbitrer : elle est simplement
 * RENDUE LISIBLE, la ou la source se nomme. Le modele et le lecteur en font ce
 * qu'ils veulent ; le systeme, lui, n'en deduit rien.
 */
class PgvectorTASK1536TemporalDerivedKnowledgeTest extends TestCase
{
    private const FAIT_RARE = 'ZORGHAMMER';

    private Organization $organization;

    private User $alice;

    private Loop $loop;

    private Dossier $rootDossier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Temporal derived knowledge requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->alice->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->rootDossier = app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1536',
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

    public function test_le_bloc_de_sources_donne_au_modele_dit_quand_le_propos_a_ete_tenu(): void
    {
        // Les humains parlent le 3 mars, la machine compile le 20 juin. Les
        // deux moments sont distincts et ne doivent jamais etre confondus.
        $this->travelTo('2026-03-03 10:00:00');
        $this->conversation();
        $this->articleDecor();

        $this->travelTo('2026-06-20 09:00:00');
        $note = $this->derive();

        $this->assertSame('2026-03-03', $note->observed_at->format('Y-m-d'),
            'observed_at est le moment ou les HUMAINS l ont dit');
        $this->assertSame('2026-06-20', $note->derived_at->format('Y-m-d'),
            'derived_at est le moment ou la MACHINE l a compile');

        $this->travelBack();

        $this->actingAs($this->alice);
        $context = app(AiShellPageContext::class)->resolve($this->alice, $this->organization, null, null);
        app(AiShellResponder::class)->respond(
            $this->organization, $this->alice, 'Qui pose la toiture du chantier Belleville ?', $context,
        );

        $prompt = null;
        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $p) use (&$prompt): bool {
            $prompt = (string) $p->prompt;

            return true;
        });

        $this->assertIsString($prompt);
        $this->assertStringContainsString(self::FAIT_RARE, $prompt,
            'PREMISSE : la note derivee est bien dans le bloc de sources');

        // LA mesure : le modele ne peut dire « depuis mars » que si la date
        // lui est donnee. Sans elle, un propos de mars et un propos d hier se
        // presentent a lui exactement de la meme facon.
        $this->assertStringContainsString('3 mars 2026', $prompt,
            'la source derivee doit porter la date a laquelle le propos a ete tenu');

        // Et jamais la date de COMPILATION : elle ne dit rien du fait, elle ne
        // dit que le moment ou la machine s est reveillee.
        $this->assertStringNotContainsString('20 juin 2026', $prompt,
            'la date de derivation n a aucune valeur informative pour le lecteur');
    }

    public function test_le_lecteur_voit_la_date_du_propos_dans_la_source_citee(): void
    {
        $this->travelTo('2026-03-03 10:00:00');
        $this->conversation();
        $this->articleDecor();
        $this->travelTo('2026-06-20 09:00:00');
        $this->derive();
        $this->travelBack();

        $this->actingAs($this->alice);
        $context = app(AiShellPageContext::class)->resolve($this->alice, $this->organization, null, null);
        $reponse = app(AiShellResponder::class)->respond(
            $this->organization, $this->alice, 'Qui pose la toiture du chantier Belleville ?', $context,
        )['answer'] ?? null;

        $this->assertNotNull($reponse);

        $sources = (string) json_encode($reponse->metadata['sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Chantier Belleville', $sources);
        $this->assertStringContainsString('3 mars 2026', $sources,
            'le lecteur doit pouvoir situer le propos sans ouvrir la conversation');
    }

    public function test_une_correction_humaine_deplace_la_date_et_le_read_suit(): void
    {
        $this->travelTo('2026-03-03 10:00:00');
        $message = $this->conversation();
        $this->articleDecor();
        $v1 = $this->derive();
        $this->assertSame('2026-03-03', $v1->observed_at->format('Y-m-d'));

        // Trois mois plus tard, un humain corrige ce qu'il avait ecrit.
        $this->travelTo('2026-06-14 15:00:00');
        $message->forceFill([
            'body' => 'Correction : pour la toiture du chantier Belleville, c est finalement '
                .self::FAIT_RARE.' qui pose, mais en septembre 2027.',
            'edited_at' => now(),
        ])->save();

        $v2 = $this->derive();
        $this->travelBack();

        $this->assertNotSame((string) $v1->id, (string) $v2->id);
        $this->assertSame('2026-06-14', $v2->observed_at->format('Y-m-d'),
            'la correction est un nouveau propos : elle a sa propre date');

        // L'ancien etat reste LISIBLE en base — l'histoire ne se reecrit pas.
        $v1 = $v1->fresh();
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $v1->status);
        $this->assertSame('2026-03-03', $v1->observed_at->format('Y-m-d'),
            'la version remplacee garde la date de son propre propos');

        // Mais le READ ne sert que l'etat corrige, avec sa date.
        $lignes = $this->cherche($this->alice, 'Qui pose la toiture du chantier Belleville ?');
        $json = (string) json_encode($lignes, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('septembre 2027', $json,
            'le futur READ utilise l etat corrige');
        $this->assertStringNotContainsString('fevrier 2027', $json,
            'et jamais celui qu il remplace');
    }

    public function test_un_article_ne_se_voit_jamais_affubler_d_une_date_de_propos(): void
    {
        $this->conversation();
        $this->articleDecor();
        $this->derive();

        $lignes = $this->cherche($this->alice, 'Quels sont les horaires d ouverture du secretariat ?');

        $this->assertNotSame([], $lignes);
        $this->assertSame('article', $lignes[0]['source_type']);
        $this->assertSame('Compte rendu administratif',
            DossierSemanticSearchService::displayTitle($lignes[0]),
            'un Article porte son titre, et rien d autre : il n a pas ete « dit » un jour donne');
    }

    // ------------------------------------------------------------ helpers

    private function conversation(): LoopMessage
    {
        return LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Pour la toiture du chantier Belleville, on part sur '.self::FAIT_RARE.' : ils posent en fevrier 2027.',
            'type' => 'user',
        ]);
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
        $message = LoopMessage::query()->where('loop_id', $this->loop->id)->orderBy('created_at')->first();
        $texte = str_contains((string) $message?->body, 'septembre')
            ? 'L entreprise retenue pour la toiture du chantier Belleville est '.self::FAIT_RARE
                .', avec une pose prevue en septembre 2027.'
            : 'L entreprise retenue pour la toiture du chantier Belleville est '.self::FAIT_RARE
                .', avec une pose prevue en fevrier 2027.';

        LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            $texte, new Usage(50, 20), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        return app(LoopConversationKnowledgeDeriver::class)->derive($this->loop->fresh());
    }

    /** @return list<array<string, mixed>> */
    private function cherche(User $user, string $question): array
    {
        $dossierIds = app(DossierAccessScope::class)
            ->accessibleDossierIds((string) $this->organization->id, $user, null);

        if ($dossierIds === []) {
            return [];
        }

        return app(DossierSemanticSearchService::class)->searchAcrossDossiers(
            (string) $this->organization->id,
            $dossierIds,
            $question,
            (string) app(ProviderResolver::class)->resolveEmbeddingInstance((string) $this->organization->id),
            5,
            [],
            20,
            null,
            app(DerivedChunkEligibility::class)->authorizedLoopIds((string) $this->organization->id, $user),
        );
    }

    /** @return list<float> */
    private function bagOfWords(string $texte): array
    {
        $vector = array_fill(0, 1536, 0.0);
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
