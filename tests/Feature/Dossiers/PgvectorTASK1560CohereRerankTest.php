<?php

namespace Tests\Feature\Dossiers;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1560 — le rerank Cohere entre le bassin de candidats et la selection
 * finale, sur le VRAI moteur pgvector.
 *
 * Ce qui est prouve ici n'est pas « l'appel part » — ce serait un test de
 * cablage. Ce qui est prouve, c'est que le rerank ne peut pas franchir les
 * frontieres qui comptent :
 *
 *   - il ne recoit QUE des candidats deja autorises (ACL -> Dense -> Cohere) ;
 *   - il ne rend QUE des lignes qu'il a recues ;
 *   - son ordre est reellement celui qui gouverne le final5 ;
 *   - son absence ou sa panne est indiscernable, pour l'aval, de l'ordre dense.
 *
 * PostgreSQL uniquement : sous SQLite le test est ignore.
 */
class PgvectorTASK1560CohereRerankTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('TASK-1560 rerank integration requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }
    }

    /**
     * REQ 1 + REQ 2 — le bassin part au rerank, et un reranker qui INVERSE
     * l'ordre dense change reellement la selection finale.
     *
     * C'est le test qui distingue un cablage d'une integration : si le final5
     * ne bougeait pas, le rerank serait decoratif.
     */
    public function test_a_reranker_that_inverts_the_dense_order_really_changes_the_final_selection(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        // Quatre documents DISTINCTS, ordre dense croissant par distance.
        foreach (['alpha', 'bravo', 'charlie', 'delta'] as $i => $mot) {
            $this->chunk($organization, $dossier, $member, $this->vector($i * 0.1), $mot);
        }

        $vus = null;
        Reranking::fake(function (RerankingPrompt $prompt) use (&$vus): array {
            $vus = $prompt->documents;

            // Inversion stricte de l'ordre recu.
            return array_map(
                fn (int $index, int $rang): RankedDocument => new RankedDocument(
                    index: $index,
                    document: $prompt->documents[$index],
                    score: 1.0 - ($rang / 10),
                ),
                array_reverse(array_keys($prompt->documents)),
                range(0, count($prompt->documents) - 1),
            );
        })->preventStrayRerankings();

        $texte = $this->ask($organization, $member, $loop);

        $this->assertNotNull($vus, 'Le rerank n a pas ete invoque.');
        $this->assertCount(4, $vus);

        // L'ordre dense etait alpha, bravo, charlie, delta. Inverse, delta
        // passe premier — et c'est le RENDU qui doit le montrer.
        $this->assertStringContainsString('[S1] Article delta', $texte);
        $this->assertStringContainsString('[S2] Article charlie', $texte);
    }

    /**
     * REQ 3 + REQ 4 — au plus 20 candidats atteignent Cohere, au plus 5 sont
     * cites. Le bassin est un plafond, pas une suggestion.
     */
    public function test_at_most_twenty_candidates_reach_cohere_and_at_most_five_are_cited(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        // 26 documents : plus que le bassin, bien plus que le final.
        for ($i = 0; $i < 26; $i++) {
            $this->chunk($organization, $dossier, $member, $this->vector($i * 0.01), 'doc'.$i);
        }

        $recus = null;
        Reranking::fake(function (RerankingPrompt $prompt) use (&$recus): array {
            $recus = count($prompt->documents);

            return $this->identity($prompt);
        })->preventStrayRerankings();

        $borne = $this->build($organization, $member, $loop);
        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);

        $this->assertNotNull($recus);
        $this->assertLessThanOrEqual(20, $recus, 'Plus de 20 candidats ont atteint Cohere.');
        $this->assertLessThanOrEqual(5, count($provenance), 'Plus de 5 sources ont ete citees.');
    }

    /**
     * REQ 5 — un document d'une AUTRE Organization n'entre jamais dans le
     * payload Cohere.
     *
     * L'architecture exigee est `ACL -> Dense -> Cohere`. Ce test echouerait
     * si quelqu'un inversait un jour l'ordre en `retrieval global -> Cohere ->
     * filtre ACL` : le texte etranger apparaitrait dans le payload avant
     * d'etre filtre.
     */
    public function test_no_document_of_another_organization_reaches_the_cohere_payload(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        $autre = Organization::factory()->create();
        $etranger = User::factory()->create(['organization_id' => $autre->id]);
        $dossierEtranger = $this->dossier($autre, $etranger, Dossier::VISIBILITY_ORGANIZATION, 'Etranger');

        $this->chunk($organization, $dossier, $member, $this->vector(0.1), 'interne');
        $this->chunk($organization, $dossier, $member, $this->vector(0.2), 'interne bis');
        // MEME vecteur que le meilleur candidat interne : s'il pouvait sortir,
        // il sortirait. Seule l'ACL l'en empeche.
        $this->chunk($autre, $dossierEtranger, $etranger, $this->vector(0.1), 'SECRET_ETRANGER');

        $payload = $this->capture();
        $this->build($organization, $member, $loop);

        $this->assertNotSame([], $payload->documents ?? [], 'Le rerank n a pas ete invoque.');

        foreach ($payload->documents as $document) {
            $this->assertStringNotContainsString('SECRET_ETRANGER', $document);
        }
    }

    /**
     * REQ 6 — un document NON AUTORISE du meme tenant (Dossier prive d'un
     * tiers) n'entre jamais dans le payload Cohere.
     */
    public function test_no_unauthorized_document_reaches_the_cohere_payload(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        $tiers = User::factory()->create(['organization_id' => $organization->id]);
        $prive = $this->dossier($organization, $tiers, Dossier::VISIBILITY_PRIVATE, 'Prive du tiers');

        $this->chunk($organization, $dossier, $member, $this->vector(0.1), 'autorise');
        $this->chunk($organization, $dossier, $member, $this->vector(0.2), 'autorise bis');
        $this->chunk($organization, $prive, $tiers, $this->vector(0.1), 'SECRET_PRIVE');

        $payload = $this->capture();
        $this->build($organization, $member, $loop);

        $this->assertNotSame([], $payload->documents ?? [], 'Le rerank n a pas ete invoque.');

        foreach ($payload->documents as $document) {
            $this->assertStringNotContainsString('SECRET_PRIVE', $document);
        }
    }

    /**
     * REQ 7 — Cohere indisponible : la selection retombe EXACTEMENT sur
     * l'ordre dense. Pas un ordre « proche », le meme.
     */
    public function test_a_cohere_failure_falls_back_to_the_exact_dense_order(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        foreach (['alpha', 'bravo', 'charlie'] as $i => $mot) {
            $this->chunk($organization, $dossier, $member, $this->vector($i * 0.1), $mot);
        }

        Reranking::fake(function (): array {
            throw new RuntimeException('cohere indisponible');
        });

        $texte = $this->ask($organization, $member, $loop);

        // Ordre dense intact : alpha (plus proche) reste premier.
        $this->assertStringContainsString('[S1] Article alpha', $texte);
        $this->assertStringContainsString('[S2] Article bravo', $texte);

        // La tentative echouee est inscrite au ledger, avec la CLASSE de
        // l'exception — jamais son message.
        $ligne = AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_RERANK)
            ->first();

        $this->assertNotNull($ligne);
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $ligne->status);
        $this->assertSame(RuntimeException::class, $ligne->failure_reason);
    }

    /**
     * REVUE — un provider qui rend des index INCOHERENTS ne corrompt pas la
     * sortie.
     *
     * Un reranker distant n'est pas tenu de bien se conduire. S'il renvoie deux
     * fois le meme index, ou un index hors bornes, la garantie « permutation du
     * bassin d'entree » doit tenir quand meme : ni doublon, ni ligne fantome,
     * ni perte de candidat.
     *
     * Ce cas n'etait couvert que par LECTURE avant la revue du SHA f76ce414.
     * Il l'est desormais par mesure.
     */
    public function test_an_incoherent_provider_response_cannot_corrupt_the_pool(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        foreach (['alpha', 'bravo', 'charlie'] as $i => $mot) {
            $this->chunk($organization, $dossier, $member, $this->vector($i * 0.1), $mot);
        }

        // Le meme index deux fois, un index hors bornes, un index negatif.
        // Aucun de ces trois ne doit pouvoir fabriquer ni dupliquer une ligne.
        Reranking::fake(fn (RerankingPrompt $prompt): array => [
            new RankedDocument(index: 2, document: $prompt->documents[2], score: 0.9),
            new RankedDocument(index: 2, document: $prompt->documents[2], score: 0.8),
            new RankedDocument(index: 99, document: 'document fantome', score: 0.7),
            new RankedDocument(index: -1, document: 'document fantome', score: 0.6),
        ])->preventStrayRerankings();

        $texte = $this->ask($organization, $member, $loop);

        // L'unique index valide est honore : charlie passe premier. Les deux
        // non classes reprennent leur rang DENSE derriere lui, dans l'ordre.
        // Le doublon n'a donc pas duplique, et les index invalides n'ont rien
        // fabrique — la sortie est exactement une permutation du bassin.
        $this->assertStringContainsString('[S1] Article charlie', $texte);
        $this->assertStringContainsString('[S2] Article alpha', $texte);
        $this->assertStringContainsString('[S3] Article bravo', $texte);

        // Chaque citation exactement une fois, et pas de quatrieme.
        foreach (['S1', 'S2', 'S3'] as $ref) {
            $this->assertSame(1, substr_count($texte, "[{$ref}] Article "), "[{$ref}] doit etre cite une seule fois.");
        }

        $this->assertStringNotContainsString('[S4]', $texte, 'Aucune ligne fantome ne doit apparaitre.');
        $this->assertStringNotContainsString('document fantome', $texte);
    }

    /**
     * REVUE — une configuration cassee ne fait pas tomber la source
     * documentaire.
     *
     * `resolveRerankingInstance()` leve `DomainException` quand le rerank est
     * actif mais que son URL ou son modele est vide. Cet appel se trouvait HORS
     * du filet : `ContextBuilder` n'attrapant que `SourceDenied`, une variable
     * d'environnement videe faisait tomber tout le chemin documentaire au lieu
     * de le laisser continuer en ordre dense.
     *
     * Defaut trouve par la revue du SHA f76ce414, corrige, et garde ici.
     */
    public function test_a_broken_rerank_configuration_falls_back_instead_of_breaking_retrieval(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        foreach (['alpha', 'bravo', 'charlie'] as $i => $mot) {
            $this->chunk($organization, $dossier, $member, $this->vector($i * 0.1), $mot);
        }

        // Rerank ACTIF, mais sans URL : la resolution du credential echoue.
        config(['ai.knowledge.rerank.url' => '']);

        Reranking::fake()->preventStrayRerankings();

        $texte = $this->ask($organization, $member, $loop);

        // Le chemin documentaire a survecu, dans l'ordre dense exact.
        $this->assertStringContainsString('[S1] Article alpha', $texte);
        $this->assertStringContainsString('[S2] Article bravo', $texte);

        // Et rien n'a ete facture : la tentative n'a jamais eu lieu.
        $this->assertSame(0, AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_RERANK)
            ->count());
    }

    /**
     * REQ 8 — le chemin « aucune preuve » n'est pas regresse : sans candidat
     * au-dessus du seuil de distance, la source reste vide et AUCUN rerank
     * n'est tente. On ne paie pas un provider pour trier le vide.
     */
    public function test_the_no_evidence_path_is_not_regressed_and_costs_no_rerank(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant(maxDistance: 0.01);

        $this->chunk($organization, $dossier, $member, $this->vector(0.9), 'trop loin');
        $this->chunk($organization, $dossier, $member, $this->vector(0.95), 'encore plus loin');

        Reranking::fake()->preventStrayRerankings();

        $borne = $this->build($organization, $member, $loop);

        $this->assertSame([], $borne->provenanceFor(DossierRetrievalSource::NAME));
        Reranking::assertNothingReranked();
        $this->assertSame(0, AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_RERANK)->count());
    }

    /**
     * REQ 9 — EXIGENCE COCKPIT : `diversify()` travaille SUR l'ordre reranke,
     * ne retombe pas implicitement sur l'ordre dense, et son plafond de
     * TASK-1307 (au plus 2 extraits du meme document) reste vrai.
     *
     * C'est le test qui documente la seule divergence assumee avec le Bench :
     * le final5 produit n'est pas byte-equivalent des que le plafond mord.
     */
    public function test_diversify_applies_to_the_reranked_order_and_keeps_the_per_document_cap(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        // Un document DOMINANT (4 chunks) et deux autres documents.
        $dominant = $this->article($organization, $dossier, $member, 'Dominant');
        for ($i = 0; $i < 4; $i++) {
            $this->chunkOf($organization, $dossier, $dominant, $i, $this->vector(0.5 + $i * 0.01), 'dominant'.$i);
        }
        // Assez de documents DISTINCTS pour que `diversify()` puisse remplir
        // topK sans relacher son plafond. Avec trop peu de documents, le
        // repechage documente de TASK-1307 autorise legitimement un 3e extrait
        // du meme document — ce n'est pas une violation du cap, c'est sa
        // clause de repli, et la confondre avec un defaut rendrait ce test faux.
        foreach (['second', 'tiers', 'quart', 'quint'] as $i => $mot) {
            $this->chunk($organization, $dossier, $member, $this->vector(0.8 + $i * 0.01), $mot);
        }

        // Le reranker place les QUATRE chunks du document dominant en tete.
        Reranking::fake(function (RerankingPrompt $prompt): array {
            $ordre = [];

            foreach ($prompt->documents as $index => $document) {
                $ordre[] = [str_contains($document, 'dominant') ? 0 : 1, $index];
            }

            usort($ordre, fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

            return array_map(
                fn (array $paire, int $rang): RankedDocument => new RankedDocument(
                    index: $paire[1],
                    document: $prompt->documents[$paire[1]],
                    score: 1.0 - ($rang / 100),
                ),
                $ordre,
                range(0, count($ordre) - 1),
            );
        })->preventStrayRerankings();

        $borne = $this->build($organization, $member, $loop);
        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);

        $titres = array_column($provenance, 'title');

        // Le plafond TASK-1307 tient MALGRE l'ordre du reranker.
        $this->assertLessThanOrEqual(
            2,
            count(array_filter($titres, fn (string $t): bool => $t === 'Dominant')),
            'Le plafond de 2 extraits par document n a pas survecu au rerank.',
        );

        // Et l'ordre reranke gouverne bien : le dominant ouvre la selection,
        // ce que l'ordre DENSE seul n'aurait pas donne (il etait le plus loin).
        $this->assertSame('Dominant', $titres[0]);
    }

    /**
     * Parite Mode B — le document soumis porte l'en-tete factuel du document,
     * puis le texte du chunk. Meme SEMANTIQUE que le Bench, avec les champs
     * du produit.
     */
    public function test_documents_submitted_to_cohere_carry_the_mode_b_header(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        $this->chunk($organization, $dossier, $member, $this->vector(0.1), 'premier');
        $this->chunk($organization, $dossier, $member, $this->vector(0.2), 'second');

        $payload = $this->capture();
        $this->build($organization, $member, $loop);

        $this->assertNotSame([], $payload->documents ?? []);

        foreach ($payload->documents as $document) {
            $this->assertStringStartsWith('Document : ', $document);
            $this->assertStringContainsString("\n\n", $document);
        }

        // Le titre rendu est celui de l'autorite produit, pas un champ invente.
        $this->assertStringContainsString('Document : Article premier', implode("\n", $payload->documents));
    }

    /**
     * Rerank indisponible (desactive) : AUCUN appel, et l'ordre dense survit
     * intact. Un rerank absent doit etre indiscernable, pour l'aval, d'un
     * rerank qui n'a jamais existe.
     *
     * ✎ Ce test ne peut PAS s'ecrire en retirant la cle du tenant, et c'est un
     * constat, pas une commodite : sans credential il n'y a pas d'embedding de
     * requete, donc AUCUN retrieval — le contexte retombe sur le manifeste
     * (metadonnees seules, `[M1]`). La branche « pas de cle » de
     * `resolveRerankingInstance()` est donc DEFENSIVE et inatteignable par ce
     * chemin : la garde d'embedding ferme la porte avant elle.
     */
    public function test_when_rerank_is_disabled_no_call_is_made_and_the_dense_order_survives(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        config(['ai.knowledge.rerank.enabled' => false]);

        $this->chunk($organization, $dossier, $member, $this->vector(0.1), 'alpha');
        $this->chunk($organization, $dossier, $member, $this->vector(0.2), 'bravo');

        Reranking::fake()->preventStrayRerankings();

        $texte = $this->ask($organization, $member, $loop);

        Reranking::assertNothingReranked();
        $this->assertStringContainsString('[S1] Article alpha', $texte);
        $this->assertStringContainsString('[S2] Article bravo', $texte);
        $this->assertSame(0, AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_RERANK)->count());
    }

    /**
     * Isolation du credential — l'instance SDK qui rerank est celle du TENANT
     * (`org:{id}:rerank`), jamais une famille nue dont la cle viendrait de la
     * configuration plateforme.
     */
    public function test_the_rerank_runs_on_the_tenant_instance_never_on_a_platform_family(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        config(['ai.providers.openrouter.key' => 'PLATEFORME_NE_DOIT_PAS_SERVIR']);

        $this->chunk($organization, $dossier, $member, $this->vector(0.1), 'alpha');
        $this->chunk($organization, $dossier, $member, $this->vector(0.2), 'bravo');

        Reranking::fake(fn (RerankingPrompt $prompt): array => $this->identity($prompt))->preventStrayRerankings();

        $this->build($organization, $member, $loop);

        Reranking::assertReranked(
            fn (RerankingPrompt $prompt): bool => $prompt->provider->name() === 'org:'.$organization->id.':rerank'
                && $prompt->model === 'cohere/rerank-v3.5',
        );
    }

    /** L'invocation reussie est inscrite au ledger, avec la preuve du credential tenant. */
    public function test_a_successful_rerank_is_recorded_in_the_ledger_with_the_tenant_credential(): void
    {
        [$organization, $member, $loop, $dossier] = $this->tenant();

        $this->chunk($organization, $dossier, $member, $this->vector(0.1), 'alpha');
        $this->chunk($organization, $dossier, $member, $this->vector(0.2), 'bravo');

        Reranking::fake(fn (RerankingPrompt $prompt): array => $this->identity($prompt))->preventStrayRerankings();

        $this->build($organization, $member, $loop);

        $ligne = AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_RERANK)
            ->first();

        $this->assertNotNull($ligne);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $ligne->status);
        $this->assertSame('cohere/rerank-v3.5', $ligne->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $ligne->credential_source);
        // Aucun jeton, aucun cout : le SDK n'en rend aucun. NULL, jamais 0.
        $this->assertNull($ligne->total_tokens);
        $this->assertNull($ligne->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $ligne->cost_status);
    }

    /**
     * REQ 10 — aucun BM25, aucun RRF, aucun seuil HARD du Bench n'a ete
     * introduit dans le produit par cette TASK.
     */
    public function test_no_bench_only_mechanism_leaked_into_the_product(): void
    {
        $chemins = array_merge(
            glob(base_path('app/Ai/Context/*.php')) ?: [],
            [base_path('config/ai.php')],
        );

        foreach ($chemins as $chemin) {
            $source = (string) file_get_contents($chemin);

            $this->assertStringNotContainsString('0.095527', $source, "Seuil HARD du Bench dans {$chemin}");
            $this->assertDoesNotMatchRegularExpression('/\bBM25\b/i', $source, "BM25 dans {$chemin}");
            $this->assertDoesNotMatchRegularExpression('/\bRRF\b/i', $source, "RRF dans {$chemin}");
        }
    }

    /**
     * DEFAULT_OFF_BY_DESIGN — TASK-1560 livre une CAPACITE, pas une mise en
     * service.
     *
     * Ce test garde la seule ligne qui separe « le produit sait reranker » de
     * « tous les tenants rerankent des maintenant ». Un defaut repasse a `true`
     * allumerait un appel provider supplementaire a chaque question
     * documentaire, facture au tenant, sans decision humaine. Le test echouerait
     * alors, et c'est exactement ce qu'on lui demande.
     *
     * Les deux sens sont verifies : variable absente -> OFF, variable posee a
     * `true` -> le chemin redevient activable.
     */
    public function test_the_feature_flag_is_off_until_someone_turns_it_on(): void
    {
        // Variable d'environnement absente : c'est le defaut du fichier de
        // configuration qui parle, et il dit non.
        $this->assertNull(
            env('AI_KNOWLEDGE_RERANK_ENABLED'),
            'Ce test mesure le DEFAUT : il ne vaut rien si l\'environnement pose la variable.',
        );
        $this->assertFalse(
            config('ai.knowledge.rerank.enabled'),
            'Le rerank doit rester DORMANT tant que AI_KNOWLEDGE_RERANK_ENABLED=true n\'est pas pose.',
        );

        // Le modele et l'URL, eux, restent prets : c'est l'interrupteur qui est
        // ouvert, pas le cablage qui manque.
        $this->assertSame('cohere/rerank-v3.5', config('ai.knowledge.rerank.model'));
        $this->assertSame('https://openrouter.ai/api/v1', config('ai.knowledge.rerank.url'));

        $organization = Organization::factory()->create();
        $setting = OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'api_key' => 'sk-tenant',
        ]);
        config(['ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform']);

        $resolver = app(ProviderResolver::class);

        // TASK-1562 puis TASK-1563 : l'interrupteur d'environnement ferme NE
        // SUFFIT PLUS a ouvrir. Deux verrous en serie, et le second — autoriser
        // cette Organization NOMMEMENT — est celui qui fait d'un pilote un
        // pilote. Il se pose desormais depuis l'admin, sur la ligne de reglages
        // de l'Organization, plus par une allowlist d'environnement.
        config(['ai.knowledge.rerank.enabled' => true]);

        $this->assertNull(
            $resolver->resolveRerankingInstance($organization->id),
            'Le drapeau maitre seul ne doit ouvrir a PERSONNE : sans autorisation, la porte reste fermee.',
        );

        // Les deux verrous ouverts, le chemin redevient franchissable.
        $setting->rerank_enabled = true;
        $setting->save();

        $this->assertSame(
            "org:{$organization->id}:rerank",
            $resolver->resolveRerankingInstance($organization->id),
        );
    }

    // ───────────────────────────────────────────────────────── harnais

    /** @return array{0: Organization, 1: User, 2: object, 3: Dossier} */
    private function tenant(float $maxDistance = 1.0): array
    {
        $organization = Organization::factory()->create();
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $loop = (new LoopService)->createLoop($member, 'Boucle rerank');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'api_key' => 'sk-tenant',
            // TASK-1563 : le drapeau maitre ne suffit plus, et l'allowlist
            // d'environnement n'existe plus. L'autorisation vit desormais ICI,
            // sur la ligne de reglages de l'Organization. Sans elle, tous les
            // tests ci-dessous mesureraient un chemin inactif.
            'rerank_enabled' => true,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => $maxDistance,
            'ai.knowledge.rerank.enabled' => true,
            'ai.knowledge.rerank.model' => 'cohere/rerank-v3.5',
            'ai.knowledge.rerank.url' => 'https://openrouter.ai/api/v1',
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $dossier = $this->dossier($organization, $member, Dossier::VISIBILITY_LOOP, 'Dossier rerank', $loop->id);

        return [$organization, $member, $loop, $dossier];
    }

    private function build(Organization $organization, User $member, object $loop): object
    {
        return app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $member->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'needle',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));
    }

    private function ask(Organization $organization, User $member, object $loop): string
    {
        return $this->build($organization, $member, $loop)->text;
    }

    /** Capture le prompt reellement soumis a Cohere, sans en changer l'ordre. */
    private function capture(): object
    {
        $vu = new class
        {
            /** @var list<string> */
            public array $documents = [];
        };

        Reranking::fake(function (RerankingPrompt $prompt) use ($vu): array {
            $vu->documents = $prompt->documents;

            return $this->identity($prompt);
        })->preventStrayRerankings();

        return $vu;
    }

    /** Un reranker qui ne change rien : preserve l'ordre recu. */
    private function identity(RerankingPrompt $prompt): array
    {
        return array_map(
            fn (int $index): RankedDocument => new RankedDocument(
                index: $index,
                document: $prompt->documents[$index],
                score: 1.0 - ($index / 100),
            ),
            array_keys($prompt->documents),
        );
    }

    /** @return list<float> */
    private function vector(float $seed): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0 - $seed;
        $vector[1] = $seed;

        return $vector;
    }

    private function dossier(Organization $organization, User $owner, string $visibility, string $name, ?string $sharedWithLoopId = null): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => $visibility,
            'shared_with_loop_id' => $sharedWithLoopId,
        ]);
    }

    private function chunk(Organization $organization, Dossier $dossier, User $owner, array $vector, string $content): DossierChunk
    {
        $post = $this->article($organization, $dossier, $owner, 'Article '.$content);

        return $this->chunkOf($organization, $dossier, $post, 0, $vector, $content);
    }

    private function article(Organization $organization, Dossier $dossier, User $owner, string $title): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => $title,
            'slug' => 'article-'.Str::uuid(),
            'content' => '<p>'.$title.'</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);

        return $post;
    }

    private function chunkOf(Organization $organization, Dossier $dossier, BlogPost $post, int $chunkIndex, array $vector, string $content): DossierChunk
    {
        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'content_hash' => hash('sha256', $content.Str::uuid()),
            'token_count' => 3,
            'embedding' => $vector,
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }
}
