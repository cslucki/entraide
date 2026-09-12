<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Models\DerivedKnowledgeNote;
use App\Models\DossierChunk;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Knowledge\ClaimMemory;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1540 — une Boucle porte plusieurs enonces adressables.
 *
 * Ces tests mesurent le CONTRAT : identite forgee par le serveur, preuves
 * verifiees, supersession individuelle, historique conserve, concurrence.
 *
 * Ce qu'ils ne mesurent pas, et qu'aucun double ne peut mesurer : la qualite
 * du patch qu'un vrai modele propose. Cela se mesure au banc — lecon de T1537,
 * ou deux retouches de prompt « evidentes » se sont revelees degradantes.
 */
class TASK1540ClaimMemoryTest extends TestCase
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
            'api_key' => 'sk-tenant-1540',
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

    // ───────────────────────────────────────────────── ADD

    public function test_une_conversation_produit_plusieurs_claims_adressables(): void
    {
        $budget = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $date = $this->message('La mairie veut le plan de circulation avant le 15 novembre.');
        $frn = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote pour Belleville est de 486 000 euros.', 'evidence' => [(string) $budget->id]],
            ['op' => 'ADD', 'text' => 'Le plan de circulation est attendu avant le 15 novembre.', 'evidence' => [(string) $date->id]],
            ['op' => 'ADD', 'text' => 'Vaucanson realise la charpente, avec une hausse de 12%.', 'evidence' => [(string) $frn->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertTrue($bilan['applique']);
        $this->assertSame(3, $bilan['ajoutes']);

        $claims = DerivedKnowledgeNote::query()->claims()->active()->get();
        $this->assertCount(3, $claims);

        // LA propriete qui fait tomber la dilution : un chunk par enonce, et
        // non un vecteur moyenne sur trois sujets.
        $this->assertSame(3, DossierChunk::whereNotNull('derived_knowledge_note_id')->count());

        foreach ($claims as $claim) {
            $this->assertSame(DerivedKnowledgeNote::KIND_CLAIM, $claim->kind);
            $this->assertSame(1, $claim->version);
            $this->assertNotSame('', (string) $claim->subject_key);
        }

        // Trois identites DISTINCTES, forgees par le serveur.
        $this->assertCount(3, $claims->pluck('subject_key')->unique());
    }

    public function test_le_modele_ne_voit_que_des_identites_fournies_par_le_serveur(): void
    {
        $m = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $this->fakePatch([['op' => 'ADD', 'text' => 'Budget travaux : 486 000 euros.', 'evidence' => [(string) $m->id]]]);
        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $claim = DerivedKnowledgeNote::query()->claims()->active()->firstOrFail();

        $this->message('Correction : le budget passe a 531 000 euros.');
        $this->fakePatch([['op' => 'KEEP', 'claim_id' => (string) $claim->subject_key]]);
        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        LoopClaimPatchAgent::assertPrompted(
            fn (AgentPrompt $p): bool => str_contains((string) $p->prompt, '['.$claim->subject_key.']')
                && str_contains((string) $p->prompt, 'ENONCES DEJA EN MEMOIRE'),
        );
    }

    // ───────────────────────────────────────────────── UPDATE

    public function test_une_correction_ne_touche_qu_un_seul_claim(): void
    {
        $c = $this->troisClaims();
        $budgetClaim = $c['budget'];
        $autres = [$c['date'], $c['fournisseur']];

        $correction = $this->message('Correction : le budget travaux passe a 531 000 euros.');

        $this->fakePatch([
            ['op' => 'UPDATE', 'claim_id' => (string) $budgetClaim->subject_key,
                'text' => 'Le budget travaux vote pour Belleville est de 531 000 euros.',
                'evidence' => [(string) $correction->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(1, $bilan['modifies']);
        $this->assertSame(0, $bilan['ajoutes']);

        $actifs = DerivedKnowledgeNote::query()->claims()->active()->get();
        $this->assertCount(3, $actifs, 'corriger le budget ne doit pas faire disparaitre les voisins');

        $budget = $actifs->firstWhere('subject_key', $budgetClaim->subject_key);
        $this->assertSame(2, $budget->version);
        $this->assertStringContainsString('531 000', (string) $budget->content);
        $this->assertStringNotContainsString('486 000', (string) $budget->content);

        // Les voisins sont INTACTS : ni reecrits, ni reversionnes.
        foreach ($autres as $voisin) {
            $intact = $actifs->firstWhere('subject_key', $voisin->subject_key);
            $this->assertSame(1, $intact->version, 'un voisin non concerne ne change pas de version');
            $this->assertSame($voisin->content, $intact->content);
        }

        // L'histoire est conservee, et chainee.
        $ancien = DerivedKnowledgeNote::query()
            ->where('subject_key', $budgetClaim->subject_key)->where('version', 1)->firstOrFail();
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $ancien->status);
        $this->assertSame((string) $budget->id, (string) $ancien->superseded_by_id);
        $this->assertStringContainsString('486 000', (string) $ancien->content);

        // Et la version remplacee n'est plus servie.
        $this->assertSame(0, DossierChunk::where('derived_knowledge_note_id', $ancien->id)->count());
    }

    // ───────────────────────────────────────────────── RETRACT

    public function test_un_retrait_ne_fabrique_aucun_remplacant(): void
    {
        $c = $this->troisClaims();
        $dateClaim = $c['date'];

        $retrait = $this->message('La date du 15 novembre n est plus valable, et aucune nouvelle date n est confirmee.');

        $this->fakePatch([
            ['op' => 'RETRACT', 'claim_id' => (string) $dateClaim->subject_key,
                'reason' => 'plus de date confirmee', 'evidence' => [(string) $retrait->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(1, $bilan['retractes']);
        $this->assertSame(0, $bilan['ajoutes'], 'un RETRACT n invente pas de remplacant');

        $actifs = DerivedKnowledgeNote::query()->claims()->active()->get();
        $this->assertCount(2, $actifs);
        $this->assertNull($actifs->firstWhere('subject_key', $dateClaim->subject_key));

        // Le READ courant ne connait plus AUCUNE date.
        $this->assertStringNotContainsString('15 novembre', (string) $actifs->pluck('content')->implode(' '));

        // Mais l'histoire, elle, la garde.
        $retracte = DerivedKnowledgeNote::query()->where('subject_key', $dateClaim->subject_key)->firstOrFail();
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $retracte->status);
        $this->assertStringContainsString('15 novembre', (string) $retracte->content);
        $this->assertSame('plus de date confirmee', $retracte->provenance['retracted_reason'] ?? null);
        $this->assertNull($retracte->superseded_by_id, 'rien ne le remplace : le chainage reste vide');

        $this->assertSame(0, DossierChunk::where('derived_knowledge_note_id', $retracte->id)->count(),
            'un enonce retracte ne doit plus etre servi');
    }

    // ───────────────────────────────────────────────── preuves

    public function test_une_operation_dont_la_preuve_est_inventee_n_entre_pas_en_memoire(): void
    {
        $vrai = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote est de 486 000 euros.', 'evidence' => [(string) $vrai->id]],
            ['op' => 'ADD', 'text' => 'Le maire a promis une rallonge de 200 000 euros.', 'evidence' => ['msg-invente']],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(1, $bilan['ajoutes'], 'seule l operation prouvee entre en memoire');
        $this->assertSame('preuve_hors_perimetre', $bilan['rejetees'][0]['raison']);

        $contenus = DerivedKnowledgeNote::query()->claims()->active()->pluck('content')->implode(' ');
        $this->assertStringNotContainsString('rallonge', $contenus);
    }

    public function test_une_identite_inventee_ne_detruit_rien(): void
    {
        $this->troisClaims();
        $m = $this->message('On fait le point sur l avancement general du chantier cette semaine.');

        $this->fakePatch([
            ['op' => 'RETRACT', 'claim_id' => 'claim-qui-n-existe-pas', 'reason' => 'obsolete', 'evidence' => [(string) $m->id]],
        ]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame(0, $bilan['retractes']);
        $this->assertSame('identite_inconnue', $bilan['rejetees'][0]['raison']);
        $this->assertSame(3, DerivedKnowledgeNote::query()->claims()->active()->count(),
            'un identifiant invente ne doit pouvoir detruire aucun enonce');
    }

    // ───────────────────────────────────────────────── concurrence

    public function test_un_patch_parti_d_une_memoire_perimee_ne_gagne_pas(): void
    {
        $budgetClaim = $this->troisClaims()['budget'];
        $correction = $this->message('Correction : le budget travaux passe a 531 000 euros.');

        $compiler = app(LoopClaimCompiler::class);
        $bTravaille = false;

        // A part de la memoire courante. Son appel est LENT : pendant qu'il
        // dure, B compile et corrige le budget.
        LoopClaimPatchAgent::fake(function () use (&$bTravaille, $compiler, $budgetClaim, $correction): TextResponse {
            if (! $bTravaille) {
                $bTravaille = true;

                $this->fakePatch([
                    ['op' => 'UPDATE', 'claim_id' => (string) $budgetClaim->subject_key,
                        'text' => 'Le budget travaux est de 531 000 euros.', 'evidence' => [(string) $correction->id]],
                ]);

                $compiler->compile($this->loop->fresh());
            }

            // Ce que A rend : un patch derive de l'etat ANCIEN.
            return $this->reponse([
                ['op' => 'UPDATE', 'claim_id' => (string) $budgetClaim->subject_key,
                    'text' => 'Le budget travaux est de 486 000 euros.', 'evidence' => [(string) $correction->id]],
            ]);
        });

        $bilan = $compiler->compile($this->loop->fresh());

        $this->assertFalse($bilan['applique']);
        $this->assertSame('base_perimee', $bilan['raison']);

        $budget = DerivedKnowledgeNote::query()->claims()->active()
            ->where('subject_key', $budgetClaim->subject_key)->firstOrFail();

        $this->assertStringContainsString('531 000', (string) $budget->content,
            'la correction plus recente reste la verite courante');
        $this->assertStringNotContainsString('486 000', (string) $budget->content);
    }

    public function test_l_empreinte_de_memoire_suit_identites_et_versions(): void
    {
        $memory = app(ClaimMemory::class);
        $budgetClaim = $this->troisClaims()['budget'];

        $avant = $memory->empreinte($memory->actifs($this->organization, $this->loop));

        $correction = $this->message('Correction : le budget travaux passe a 531 000 euros.');
        $this->fakePatch([
            ['op' => 'UPDATE', 'claim_id' => (string) $budgetClaim->subject_key,
                'text' => 'Le budget travaux est de 531 000 euros.', 'evidence' => [(string) $correction->id]],
        ]);
        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $apres = $memory->empreinte($memory->actifs($this->organization, $this->loop));

        $this->assertNotSame($avant, $apres, 'une version qui change doit changer l empreinte');
    }

    // ───────────────────────────────────────────────── refus sans cout

    public function test_sans_prompt_actif_aucun_claim_n_est_ecrit(): void
    {
        \App\Models\AdminAiPrompt::where('scenario_id', 'loop_claim_patch')->update(['is_active' => false]);
        $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $this->fakePatch([['op' => 'ADD', 'text' => 'Ne devrait jamais etre appele.', 'evidence' => ['x']]]);

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $this->assertSame('aucun_prompt_actif', $bilan['raison']);
        $this->assertSame(0, DerivedKnowledgeNote::query()->count());
    }

    public function test_le_tour_est_inscrit_au_ledger_sous_sa_propre_capability(): void
    {
        $m = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $this->fakePatch([['op' => 'ADD', 'text' => 'Budget travaux : 486 000 euros.', 'evidence' => [(string) $m->id]]]);

        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $ligne = \Illuminate\Support\Facades\DB::table('ai_provider_invocations')
            ->where('feature', LoopClaimCompiler::FEATURE)->first();

        $this->assertNotNull($ligne, 'la bascule claim-level doit avoir son propre cout lisible');
        $this->assertSame(\App\Ai\CapabilityRegistry::LOOP_CLAIM_PATCH, $ligne->capability);
        $this->assertSame('success', $ligne->status);
    }

    // ───────────────────────────────────────────────── helpers

    /**
     * Trois claims, designes par leur CONTENU et jamais par leur position.
     *
     * Les trois naissent dans la meme transaction : a la seconde pres, leurs
     * `created_at` sont identiques et l'ordre rendu par la base n'est pas
     * deterministe. Une premiere version prenait « le premier des autres »
     * comme claim de date — verte en local, rouge sur un shard CI qui avait
     * retracte le fournisseur a la place.
     *
     * @return array{budget: DerivedKnowledgeNote, date: DerivedKnowledgeNote, fournisseur: DerivedKnowledgeNote}
     */
    private function troisClaims(): array
    {
        $budget = $this->message('Le budget travaux vote pour Belleville est de 486 000 euros.');
        $date = $this->message('La mairie veut le plan de circulation avant le 15 novembre.');
        $frn = $this->message('Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.');

        $this->fakePatch([
            ['op' => 'ADD', 'text' => 'Le budget travaux vote pour Belleville est de 486 000 euros.', 'evidence' => [(string) $budget->id]],
            ['op' => 'ADD', 'text' => 'Le plan de circulation est attendu avant le 15 novembre.', 'evidence' => [(string) $date->id]],
            ['op' => 'ADD', 'text' => 'Vaucanson realise la charpente, avec une hausse de 12%.', 'evidence' => [(string) $frn->id]],
        ]);

        app(LoopClaimCompiler::class)->compile($this->loop->fresh());

        $claims = DerivedKnowledgeNote::query()->claims()->active()->get();

        $par = static function (string $jeton) use ($claims): DerivedKnowledgeNote {
            $trouve = $claims->first(fn (DerivedKnowledgeNote $c): bool => str_contains((string) $c->content, $jeton));

            if ($trouve === null) {
                throw new \RuntimeException("Claim introuvable pour « {$jeton} »");
            }

            return $trouve;
        };

        return [
            'budget' => $par('486 000'),
            'date' => $par('15 novembre'),
            'fournisseur' => $par('Vaucanson'),
        ];
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

    private function reponse(array $operations): TextResponse
    {
        return new TextResponse(
            (string) json_encode(['operations' => $operations], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        );
    }

    private function fakePatch(array $operations): void
    {
        LoopClaimPatchAgent::fake(fn (): TextResponse => $this->reponse($operations));
    }
}
