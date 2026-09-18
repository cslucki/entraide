<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Jobs\DeriveLoopConversationKnowledge;
use App\Models\DerivedKnowledgeNote;
use App\Models\DossierChunk;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Knowledge\ClaimMemory;
use App\Services\Knowledge\DerivedKnowledgeNoteIndexer;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1541 — le retrieval sert des ENONCES, et plus un paragraphe.
 *
 * T1540 a rendu la memoire adressable : une Boucle porte N enonces, chacun sur
 * sa ligne, chacun avec son identite. Rien n'en decoulait encore au READ — le
 * digest continuait d'etre servi a cote d'eux, donc chaque fait remontait
 * DEUX fois, porte une premiere fois par l'enonce precis et une seconde par le
 * paragraphe qui le noyait parmi sept autres sujets.
 *
 * Ce fichier mesure la bascule, et elle tient en trois proprietes :
 *
 *  1. **une seule population est servie.** Des qu'une Boucle a des enonces,
 *     son digest perd ses vecteurs. Sa ligne reste — conteneur, empreinte de
 *     source, provenance agregee (CDC §9) — mais elle n'est plus retrouvable ;
 *  2. **le cout ne bouge pas.** Le conteneur porte l'empreinte de la source
 *     deja compilee, et cette empreinte est lue AVANT toute resolution de
 *     provider. Sans elle, le balayage automatique de T1539 facturerait chaque
 *     passage sur une conversation endormie ;
 *  3. **chaque enonce est date par SA preuve.** Le digest n'avait qu'une date
 *     possible, celle du tour. Un enonce a mieux, et c'est cette date que le
 *     lecteur voit (`derivedTitle`, T1536).
 *
 * Ce que ces tests ne mesurent PAS : le classement. Un double d'embedding rend
 * les vecteurs qu'on lui demande — mesurer un rang avec lui reviendrait a
 * mesurer sa fixture. La dilution se mesure au banc, sur de vrais vecteurs
 * (`_local/task1541/dilution.php`), et le classement reel sur PostgreSQL dans
 * `PgvectorTASK1541ClaimRetrievalTest`.
 */
class TASK1541ClaimRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

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

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

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
            'ai_pricing.overrides' => [],
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, 1536, 0.01),
            $prompt->inputs,
        ))->preventStrayEmbeddings();
    }

    // ──────────────────────────────────── une seule population servie

    public function test_le_digest_cesse_d_etre_servi_des_que_la_boucle_a_des_enonces(): void
    {
        $this->troisMessages();

        // AVANT : le chemin historique, et la premisse mesuree. Sans elle, un
        // « zero chunk de digest » a la fin ne dirait rien — il pourrait n'y
        // en avoir jamais eu.
        $digest = $this->compilerLeDigest();

        $this->assertGreaterThan(0, $this->chunksDe($digest),
            'PREMISSE : le digest doit etre servi AVANT la bascule');

        // APRES : le chemin claim-level, par le job — c'est lui qui porte la
        // bascule en production.
        $this->compilerLesEnonces();

        $this->assertSame(0, $this->chunksDe($digest->fresh()),
            'le digest ne doit plus etre retrouvable des lors que la Boucle a des enonces');

        $claims = DerivedKnowledgeNote::query()->claims()->active()->get();
        $this->assertCount(3, $claims);

        foreach ($claims as $claim) {
            $this->assertGreaterThan(0, $this->chunksDe($claim),
                'chaque enonce porte son propre vecteur : c est toute la bascule');
        }

        // Et la ligne du digest, elle, n'est pas perdue.
        $this->assertNotNull($digest->fresh(), 'le conteneur reste en base');
        $this->assertTrue($digest->fresh()->isActive());
    }

    public function test_un_conteneur_d_enonces_n_est_jamais_servi_meme_sans_aucun_enonce_actif(): void
    {
        // LE cas dangereux, et il n'est pas theorique : si le conteneur
        // redevenait servable quand la Boucle n'a plus d'enonce actif, un
        // RETRACT rendrait a nouveau lisible ce qu'il vient d'effacer — par
        // un simple repli, sans que personne ne l'ait decide.
        $this->troisMessages();
        $this->compilerLesEnonces();

        $claims = DerivedKnowledgeNote::query()->claims()->active()->get();
        $this->assertCount(3, $claims);

        $retrait = $this->message('Tout est annule : le chantier Belleville ne se fait pas.');

        $this->fakePatch($claims->map(fn (DerivedKnowledgeNote $c): array => [
            'op' => 'RETRACT', 'claim_id' => (string) $c->subject_key,
            'reason' => 'chantier annule', 'evidence' => [(string) $retrait->id],
        ])->all());

        (new DeriveLoopConversationKnowledge((string) $this->loop->id))->handle(
            app(LoopClaimCompiler::class), app(DerivedKnowledgeNoteIndexer::class),
        );

        $this->assertSame(0, DerivedKnowledgeNote::query()->claims()->active()->count(),
            'PREMISSE : plus aucun enonce actif');

        $conteneur = app(ClaimMemory::class)->conteneur($this->organization, $this->loop);
        $this->assertNotNull($conteneur);

        $this->assertSame(0, $this->chunksDe($conteneur),
            'un conteneur d enonces ne redevient jamais une source : le repli ressusciterait un RETRACT');

        $this->assertStringNotContainsString('486 000', (string) $conteneur->content,
            'le texte du conteneur ne doit plus enoncer ce qui vient d etre retracte');
    }

    public function test_un_paragraphe_compile_apre_s_les_enonces_n_est_jamais_indexe(): void
    {
        // Le cas que le banc de dilution a trouve et que les tests laissaient
        // passer : `knowledge:derive-loop-conversations` reste appelable, et
        // rien n'empeche de le lancer sur une Boucle qui a deja des enonces.
        //
        // L'indexeur recevait alors l'instance rendue par `create()`, dont le
        // `kind` n'etait porte que par le defaut SQL — donc nul en memoire. Il
        // ne reconnaissait pas un digest, et l'indexait. Deux populations
        // servaient les memes faits, en silence.
        $this->troisMessages();
        $this->compilerLesEnonces();

        $this->assertSame(3, DerivedKnowledgeNote::query()->claims()->active()->count());

        $digest = $this->compilerLeDigest();

        $this->assertSame(DerivedKnowledgeNote::KIND_DIGEST, $digest->kind,
            'la note doit se declarer digest AVANT tout aller-retour en base');
        $this->assertSame(0, $this->chunksDe($digest),
            'un paragraphe compile apres les enonces ne doit jamais etre servi a cote d eux');
    }

    public function test_un_digest_historique_sans_aucun_enonce_reste_servi(): void
    {
        // La regle d'eclipse ne doit pas devenir « plus aucun digest » : le
        // chemin `knowledge:derive-loop-conversations` reste au CDC, et une
        // Boucle qui n'a jamais ete compilee en enonces garde sa note.
        $this->troisMessages();
        $digest = $this->compilerLeDigest();

        $this->assertGreaterThan(0, $this->chunksDe($digest));

        app(DerivedKnowledgeNoteIndexer::class)->synchronize($digest->fresh());

        $this->assertGreaterThan(0, $this->chunksDe($digest->fresh()),
            'sans enonce concurrent, un digest compile par un modele reste une source legitime');
    }

    public function test_une_correction_retire_l_ancienne_valeur_du_retrieval(): void
    {
        $this->troisMessages();
        $this->compilerLesEnonces();

        $budget = DerivedKnowledgeNote::query()->claims()->active()
            ->get()->first(fn (DerivedKnowledgeNote $c): bool => str_contains((string) $c->content, '486 000'));

        $this->assertNotNull($budget);
        $ancienId = $budget->id;

        $correction = $this->message('Correction : le budget travaux passe finalement a 531 000 euros.');
        $this->fakePatch([
            ['op' => 'UPDATE', 'claim_id' => (string) $budget->subject_key,
                'text' => 'Le budget travaux de Belleville est de 531 000 euros.',
                'evidence' => [(string) $correction->id]],
        ]);

        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, DossierChunk::where('derived_knowledge_note_id', $ancienId)->count(),
            'la version remplacee perd ses vecteurs : servir les deux ferait coexister deux budgets');

        $servi = DossierChunk::whereNotNull('derived_knowledge_note_id')->pluck('content')->implode(' ');
        $this->assertStringContainsString('531 000', $servi);
        $this->assertStringNotContainsString('486 000', $servi);

        // Les deux autres enonces n'ont pas bouge : c'est la propriete que le
        // digest ne pouvait pas tenir, puisqu'il se reecrivait en entier.
        $this->assertStringContainsString('15 novembre', $servi);
        $this->assertStringContainsString('Vaucanson', $servi);
    }

    // ──────────────────────────────────── le cout ne bouge pas

    public function test_une_source_inchangee_ne_coute_aucun_appel(): void
    {
        $this->troisMessages();
        $this->compilerLesEnonces();

        $this->assertSame(1, $this->appels(), 'PREMISSE : la premiere compilation appelle bien le modele');

        // Rien n'a change dans la conversation. Le balayeur de T1539 repasse.
        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame('source_inchangee', $bilan['raison']);
        $this->assertSame(1, $this->appels(),
            'un balayage sur une conversation endormie ne doit rien facturer');
    }

    public function test_un_message_nouveau_rouvre_la_compilation(): void
    {
        $this->troisMessages();
        $this->compilerLesEnonces();

        $nouveau = $this->message('On ajoute une tranche de voirie sur le parvis, cote nord.');
        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Une tranche de voirie est ajoutee sur le parvis nord.',
                'evidence' => [(string) $nouveau->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(1, $bilan['ajoutes']);
        $this->assertSame(2, $this->appels(), 'une source qui bouge redonne lieu a compilation');
    }

    public function test_le_conteneur_porte_l_empreinte_et_le_compte_des_enonces(): void
    {
        $this->troisMessages();
        $this->compilerLesEnonces();

        $conteneur = app(ClaimMemory::class)->conteneur($this->organization, $this->loop);

        $this->assertNotNull($conteneur);
        $this->assertNotSame('', (string) $conteneur->source_fingerprint);
        $this->assertSame(ClaimMemory::CONTENEUR, $conteneur->provenance['derived_by'] ?? null);
        $this->assertSame(3, $conteneur->provenance['claim_count'] ?? null);

        // Il est une PROJECTION des enonces, jamais un second appel au modele.
        $this->assertSame(1, $this->appels());

        foreach (['486 000', '15 novembre', 'Vaucanson'] as $jeton) {
            $this->assertStringContainsString($jeton, (string) $conteneur->content);
        }
    }

    // ──────────────────────────────────── chaque enonce a sa date

    public function test_chaque_enonce_porte_la_date_de_sa_propre_preuve(): void
    {
        // Trois faits dits a trois moments tres differents. Le digest n'aurait
        // eu qu'une date pour les trois — celle du dernier —, et le lecteur
        // aurait cru que le budget venait d'etre annonce.
        $ancien = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $ancien->forceFill(['created_at' => now()->subMonths(8)])->save();

        $moyen = $this->message('La mairie veut le plan de circulation avant le 15 novembre.');
        $moyen->forceFill(['created_at' => now()->subDays(20)])->save();

        $recent = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote pour Belleville est de 486 000 euros.', 'evidence' => [(string) $ancien->id]],
            ['op' => 'ADD', 'text' => 'Le plan de circulation est attendu avant le 15 novembre.', 'evidence' => [(string) $moyen->id]],
            ['op' => 'ADD', 'text' => 'Vaucanson realise la charpente, avec une hausse de 12%.', 'evidence' => [(string) $recent->id]],
        ]);

        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $par = fn (string $jeton): DerivedKnowledgeNote => DerivedKnowledgeNote::query()->claims()->active()
            ->get()->first(fn (DerivedKnowledgeNote $c): bool => str_contains((string) $c->content, $jeton))
            ?? throw new \RuntimeException("Claim introuvable pour « {$jeton} »");

        $this->assertTrue($par('486 000')->observed_at->isSameDay($ancien->created_at),
            'un fait dit il y a huit mois ne doit pas se presenter comme dit aujourd hui');
        $this->assertTrue($par('15 novembre')->observed_at->isSameDay($moyen->created_at));
        $this->assertTrue($par('Vaucanson')->observed_at->isSameDay($recent->created_at));

        // Trois dates DISTINCTES : c'est ce que le paragraphe unique ne pouvait
        // pas porter.
        $this->assertCount(3, DerivedKnowledgeNote::query()->claims()->active()
            ->get()->map(fn (DerivedKnowledgeNote $c): string => $c->observed_at->toDateString())->unique());
    }

    // ──────────────────────────────────── helpers

    private function troisMessages(): void
    {
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $this->message('La mairie veut le plan de circulation avant le 15 novembre.');
        $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');
    }

    /** Le chemin HISTORIQUE : un paragraphe, indexe. */
    private function compilerLeDigest(): DerivedKnowledgeNote
    {
        LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Le budget travaux de Belleville est de 486 000 euros. Le plan de circulation est attendu'
            .' avant le 15 novembre. Vaucanson realise la charpente, avec une hausse de 12%.',
            new Usage(50, 20), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $digest = app(LoopConversationKnowledgeDeriver::class)->derive($this->loop->fresh());

        $this->assertNotNull($digest, 'PREMISSE : le chemin historique doit produire une note');

        return $digest;
    }

    /**
     * Le chemin de T1541, par le JOB — c'est lui qui porte la bascule.
     *
     * Les enonces reprennent les memes jetons que le digest : sans cela,
     * « le digest n'est plus servi » pourrait vouloir dire « la connaissance a
     * disparu », et le test ne saurait pas faire la difference.
     */
    private function compilerLesEnonces(): void
    {
        $messages = LoopMessage::where('loop_id', $this->loop->id)->orderBy('created_at')->get();

        $par = fn (string $jeton): string => (string) $messages
            ->first(fn (LoopMessage $m): bool => str_contains((string) $m->body, $jeton))?->id;

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux de Belleville est de 486 000 euros.', 'evidence' => [$par('486 000')]],
            ['op' => 'ADD', 'text' => 'Le plan de circulation est attendu avant le 15 novembre.', 'evidence' => [$par('15 novembre')]],
            ['op' => 'ADD', 'text' => 'Vaucanson realise la charpente, avec une hausse de 12%.', 'evidence' => [$par('Vaucanson')]],
        ]);

        (new DeriveLoopConversationKnowledge((string) $this->loop->id))->handle(
            app(LoopClaimCompiler::class), app(DerivedKnowledgeNoteIndexer::class),
        );
    }

    private function chunksDe(DerivedKnowledgeNote $note): int
    {
        return DossierChunk::where('derived_knowledge_note_id', $note->id)->count();
    }

    private function appels(): int
    {
        return DB::table('ai_provider_invocations')->where('feature', LoopClaimCompiler::FEATURE)->count();
    }

    private function message(string $body): LoopMessage
    {
        return LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
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
}
