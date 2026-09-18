<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1551 — W1.5 : le Shell explique sa memoire durable et renvoie vers la
 * Boucle.
 *
 * Ce que ces tests protegent :
 *
 *  - la garde de `Pourquoi ?` porte sur la TRACE, jamais sur le statut — les
 *    quatre branches du Shell qui ecrivent `sources` sortent en
 *    `STATUS_NON_INTERACTION`, et une garde de statut aurait livre une
 *    fonctionnalite morte, verte en test sur un tour fabrique ;
 *  - le Shell EXPLIQUE et n'ECRIT pas : aucun formulaire, aucun geste, et
 *    `can_correct` est faux PAR CONSTRUCTION (Boucle courante `null`) ;
 *  - la correction reste dans la Boucle source, atteinte par un lien — et
 *    aucun deep-link de message n'est invente ;
 *  - le discriminateur document / memoire durable reste celui de T1549, par
 *    l'autorite d'eligibilite, jamais par la forme publique ;
 *  - une memoire refusee est un NOMBRE : ni auteur, ni titre, ni contenu ;
 *  - ouvrir le panneau n'ecrit RIEN, nulle part ;
 *  - le fil d'un tiers n'est pas explicable, meme avec un identifiant valide.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1551ShellProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    /** Membre de la Boucle SOURCE ; sert le refus d'ACL quand il n'y est pas. */
    private User $bruno;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);
        $this->bruno = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bruno Lefevre']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        $this->membre($this->loop, $this->alice);
        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1551',
        ]);

        config([
            'ai.shell.enabled' => true,
            'ai.chatloop.enabled' => true,
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

    // ───────────────────────────────── la memoire durable, expliquee

    public function test_le_shell_explique_une_memoire_durable_et_renvoie_vers_la_boucle(): void
    {
        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        $rendu = Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->assertSeeHtml('data-ai-shell-why-open')
            ->call('showWhy', (string) $message->id)
            ->assertSet('whyMessageId', (string) $message->id)
            ->assertSeeHtml('data-ai-shell-why-panel')
            ->assertSeeHtml('data-ai-shell-why-memory-entry')
            ->assertSee('Vaucanson realise la charpente')
            // La portee nomme la Boucle ET dit ou la correction s ecrit.
            ->assertSee(__('loops.why_memory_scope_other', ['loop' => 'Chantier Belleville']))
            ->assertSeeHtml('data-ai-shell-why-loop-link');

        // Le lien pointe vers la Boucle SOURCE, et vers aucun message.
        $this->assertStringContainsString($this->loop->fresh()->workspaceUrl(), $rendu->html());
        $this->assertStringNotContainsString('message_id=', $rendu->html());
    }

    public function test_le_shell_n_offre_aucun_geste_de_correction(): void
    {
        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        $composant = Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id);

        // `can_correct` est faux PAR CONSTRUCTION : le lecteur standard recoit
        // `null` comme Boucle courante depuis cet hote. Ce n'est pas la vue qui
        // cache le geste, c'est le lecteur qui le refuse.
        $entree = $composant->get('whyPanel')['memory']['entries'][0];
        $this->assertFalse($entree['can_correct']);
        $this->assertFalse($entree['same_loop']);
        $this->assertSame([], $entree['evidence_message_ids']);

        $html = $composant->html();

        foreach (['data-correct-form', 'data-correct-open-update', 'data-correct-open-retract',
            'data-digest-correct-form', 'startCorrection', 'submitCorrection'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $html,
                "le Shell n est pas une seconde surface d ecriture : `{$interdit}` n a rien a y faire");
        }
    }

    public function test_le_rendu_ne_montre_ni_subject_key_ni_identifiant_ni_score(): void
    {
        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        $html = Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->html();

        $this->assertStringNotContainsString((string) $claim->subject_key, $html,
            'subject_key est une identite choisie par le modele : elle reste cote serveur');
        $this->assertStringNotContainsString('subject_key', $html);
        $this->assertStringNotContainsString((string) $claim->id, $html,
            'aucun identifiant de ligne derivee ne circule dans le rendu');
    }

    // ─────────────────────────────────────────── les autres familles

    public function test_un_document_ordinaire_n_ouvre_aucune_section_memoire(): void
    {
        $chunk = $this->unChunkDocumentaire('Le devis original de la charpente.');
        $message = $this->tourShell($this->alice, [$this->citation(null, $chunk)], [$this->publicSource('S1', 'Devis charpente.pdf')]);

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertSeeHtml('data-ai-shell-why-panel')
            ->assertSeeHtml('data-ai-shell-why-document-entry')
            ->assertSee('Devis charpente.pdf')
            ->assertDontSeeHtml('data-ai-shell-why-memory-entry')
            ->assertDontSeeHtml('data-ai-shell-why-loop-link');
    }

    public function test_une_memoire_refusee_est_un_nombre_sans_aucune_fuite(): void
    {
        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->bruno, [$this->citation($claim)], [$this->publicSource('S1')]);

        // Bruno n'est PAS membre de la Boucle source : l'autorite d'eligibilite
        // refuse, et le refus ne dit rien de plus qu'un nombre.
        $html = Livewire::actingAs($this->bruno)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertSeeHtml('data-ai-shell-why-memory-denied="1"')
            ->assertDontSeeHtml('data-ai-shell-why-memory-entry')
            ->html();

        $this->assertStringNotContainsString('Vaucanson realise la charpente', $html,
            'le contenu d une memoire refusee ne fuite jamais');
        $this->assertStringNotContainsString('Chantier Belleville', $html,
            'ni le nom de la Boucle source');
        $this->assertStringNotContainsString('Alice Renard', $html, 'ni aucun auteur');
    }

    public function test_une_trace_devenue_injoignable_se_dit_sans_nommer_de_famille(): void
    {
        [$claim] = $this->unClaim();
        $citation = $this->citation($claim);

        // La ligne citee disparait : son rattachement part avec elle, donc
        // l origine n est plus etablissable. Ni document, ni memoire.
        DossierChunk::query()->whereKey($citation['chunk_id'])->delete();

        $message = $this->tourShell($this->alice, [$citation], [$this->publicSource('S1')]);

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertSeeHtml('data-ai-shell-why-unreachable="1"')
            ->assertDontSeeHtml('data-ai-shell-why-memory-entry')
            ->assertDontSee('Vaucanson realise la charpente');
    }

    public function test_un_tour_sans_provenance_n_offre_rien_et_n_ouvre_rien(): void
    {
        $message = AiShellMessage::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->alice->id,
            'conversation_id' => (string) Str::uuid(),
            'role' => AiShellMessage::ROLE_ASSISTANT,
            'content' => 'Une reponse sans la moindre source.',
            'metadata' => ['status' => AiShellResponder::STATUS_NON_INTERACTION],
        ]);

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->assertDontSeeHtml('data-ai-shell-why-open')
            ->call('showWhy', (string) $message->id)
            ->assertSet('whyMessageId', null)
            ->assertSet('whyPanel', null)
            ->assertDontSeeHtml('data-ai-shell-why-panel');
    }

    public function test_la_garde_porte_sur_la_trace_et_non_sur_le_statut(): void
    {
        [$claim] = $this->unClaim();

        // PREMISSE : les quatre branches du Shell qui ecrivent `sources`
        // sortent en NON_INTERACTION. Une garde de statut rendrait la
        // fonctionnalite morte sur 100 % des tours reels.
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);
        $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $message->metadata['status']);

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->assertSeeHtml('data-ai-shell-why-open')
            ->call('showWhy', (string) $message->id)
            ->assertSet('whyMessageId', (string) $message->id);
    }

    // ──────────────────────────────────────────────── les sabotages

    public function test_le_tour_d_un_tiers_n_est_pas_explicable(): void
    {
        [$claim] = $this->unClaim();
        $this->membre($this->loop, $this->bruno);

        // Le tour appartient au fil d ALICE. Bruno en connait l identifiant.
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        Livewire::actingAs($this->bruno)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertSet('whyMessageId', null)
            ->assertSet('whyPanel', null);
    }

    public function test_une_trace_d_un_autre_tenant_n_est_jamais_lue(): void
    {
        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        // La trace change de tenant entre la reponse et le clic.
        $autre = Organization::factory()->create(['is_active' => true]);
        AiInteraction::query()
            ->whereKey($message->metadata['ai_interaction_id'])
            ->update(['organization_id' => $autre->id]);

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertSet('whyPanel', null);
    }

    public function test_ouvrir_le_panneau_n_ecrit_rien(): void
    {
        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        $avant = [
            'notes' => DerivedKnowledgeNote::query()->count(),
            'versions' => DerivedKnowledgeNote::query()->sum('version'),
            'messages_boucle' => LoopMessage::query()->count(),
            'messages_shell' => AiShellMessage::query()->count(),
            'interactions' => AiInteraction::query()->count(),
            'chunks' => DossierChunk::query()->count(),
        ];

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            // Le panneau s est REELLEMENT ouvert : sans cette assertion, un
            // `showWhy()` qui sortirait par une garde ferait passer le test
            // pour la plus mauvaise des raisons.
            ->assertSet('whyMessageId', (string) $message->id)
            ->assertSeeHtml('data-ai-shell-why-memory-entry')
            ->call('closeWhy')
            ->assertSet('whyPanel', null);

        $this->assertSame($avant, [
            'notes' => DerivedKnowledgeNote::query()->count(),
            'versions' => DerivedKnowledgeNote::query()->sum('version'),
            'messages_boucle' => LoopMessage::query()->count(),
            'messages_shell' => AiShellMessage::query()->count(),
            'interactions' => AiInteraction::query()->count(),
            'chunks' => DossierChunk::query()->count(),
        ], 'expliquer est une LECTURE : rien n a bouge en base');
    }

    public function test_une_adhesion_revoquee_entre_la_reponse_et_le_clic_ferme_la_memoire(): void
    {
        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        LoopMember::query()
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $this->alice->id)
            ->update(['status' => 'removed']);

        $html = Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertSeeHtml('data-ai-shell-why-memory-denied="1"')
            ->html();

        $this->assertStringNotContainsString('Vaucanson realise la charpente', $html);
        $this->assertStringNotContainsString('Chantier Belleville', $html);
    }

    public function test_une_trace_non_appariable_ne_nomme_aucun_document(): void
    {
        $chunk = $this->unChunkDocumentaire('Le devis original de la charpente.');

        // Deux citations, UNE seule forme publique : l appariement positionnel
        // n est plus prouvable, donc aucun titre n est apparie.
        $message = $this->tourShell(
            $this->alice,
            [$this->citation(null, $chunk), $this->citation(null, $chunk)],
            [$this->publicSource('S1', 'Devis charpente.pdf')],
        );

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertDontSee('Devis charpente.pdf')
            ->assertSeeHtml('data-ai-shell-why-documents-masked="2"');
    }

    // ──────────────────────────────────────────────────── locale EN

    public function test_le_panneau_se_rend_en_anglais(): void
    {
        app()->setLocale('en');

        [$claim] = $this->unClaim();
        $message = $this->tourShell($this->alice, [$this->citation($claim)], [$this->publicSource('S1')]);

        Livewire::actingAs($this->alice)
            ->test(AiShell::class)
            ->call('showWhy', (string) $message->id)
            ->assertSee(__('ai.shell_why_title'))
            ->assertSee(__('ai.shell_why_memory_title'))
            ->assertSee(__('ai.shell_why_open_loop'));

        $this->assertNotSame(__('ai.shell_why_open_loop'), 'ai.shell_why_open_loop',
            'la cle EN doit exister, pas retomber sur son propre nom');
    }

    // ───────────────────────────────────────────────────── fixtures

    /**
     * Un enonce REEL, compile par le vrai pipeline — chunk et provenance reels.
     *
     * @return array{0: DerivedKnowledgeNote, 1: LoopMessage}
     */
    private function unClaim(string $texte = 'Vaucanson realise la charpente, avec une hausse de 12%.'): array
    {
        $source = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Pour la charpente on part sur Vaucanson, malgre les 12% de hausse annoncee.',
            'type' => 'user',
        ]);

        LoopClaimPatchAgent::fake(fn (): TextResponse => new TextResponse(
            (string) json_encode(['operations' => [[
                'op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $source->id],
            ]]], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $bilan = app(LoopClaimCompiler::class)->compile($this->loop->fresh());
        $this->assertTrue($bilan['applique'], 'PREMISSE : la compilation doit reussir');

        $claim = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)
            ->claims()->active()->firstOrFail();

        $this->assertTrue(
            DossierChunk::query()->where('derived_knowledge_note_id', $claim->id)->exists(),
            'PREMISSE : l enonce compile doit etre indexe',
        );

        return [$claim, $source];
    }

    /**
     * Un tour du Shell exactement dans la forme des QUATRE branches reelles :
     * `STATUS_NON_INTERACTION`, `sources` en forme publique, `ai_interaction_id`
     * pointant une trace dont `retrieval.cited` est le MEME tableau, dans le
     * MEME ordre (`DossierInsightsService` : `sources: $cited`).
     *
     * @param  list<array<string, mixed>>  $cited
     * @param  list<array<string, mixed>>  $publicSources
     */
    private function tourShell(User $owner, array $cited, array $publicSources): AiShellMessage
    {
        $interaction = AiInteraction::create([
            'user_id' => $owner->id,
            'organization_id' => $this->organization->id,
            'process' => 'shell',
            'feature' => 'loop_knowledge_answer',
            'model' => 'test-model',
            'prompt' => 'prompt interne',
            'response' => 'reponse',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'metadata' => [
                'requested_by' => $owner->id,
                'capability' => 'loop_knowledge_answer',
                'retrieval' => ['consulted' => $cited, 'cited' => $cited],
            ],
        ]);

        return AiShellMessage::create([
            'organization_id' => $this->organization->id,
            'user_id' => $owner->id,
            'conversation_id' => (string) Str::uuid(),
            'role' => AiShellMessage::ROLE_ASSISTANT,
            'content' => 'Reponse du Shell fondee sur la memoire durable.',
            'metadata' => [
                'status' => AiShellResponder::STATUS_NON_INTERACTION,
                'producer' => 'dossier.answer',
                'grounded' => true,
                'sources' => $publicSources,
                'ai_interaction_id' => (string) $interaction->id,
            ],
        ]);
    }

    /**
     * La forme mesuree du `cited` de `DossierInsightsService` :
     * `{chunk_id, dossier_id}`, SANS `blog_post_id`.
     *
     * @return array{chunk_id: string, dossier_id: ?string}
     */
    private function citation(?DerivedKnowledgeNote $note, ?DossierChunk $chunk = null): array
    {
        $chunk ??= DossierChunk::query()
            ->where('derived_knowledge_note_id', $note?->id)
            ->firstOrFail();

        return [
            'chunk_id' => (string) $chunk->id,
            'dossier_id' => $chunk->dossier_id === null ? null : (string) $chunk->dossier_id,
        ];
    }

    /**
     * La forme publique de `KnowledgeAnswer::publicSource()` : `type =
     * 'retrieval'` pour un document COMME pour une memoire durable — c est
     * precisement pourquoi le discriminateur n a pas le droit de la lire.
     *
     * @return array<string, mixed>
     */
    private function publicSource(string $ref, ?string $title = null): array
    {
        return [
            'ref' => $ref, 'title' => $title, 'dossier_name' => null,
            'excerpt' => null, 'url' => null, 'type' => 'retrieval',
        ];
    }

    private function unChunkDocumentaire(string $contenu): DossierChunk
    {
        $rootDossier = (string) Dossier::query()->where('loop_id', $this->loop->id)->value('id');

        $file = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $rootDossier,
        ]);

        return DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $rootDossier,
            'dossier_file_id' => $file->id,
            'chunk_index' => DossierChunk::query()->where('dossier_id', $rootDossier)->count(),
            'content' => $contenu,
            'content_hash' => hash('sha256', $contenu),
            'embedding' => array_fill(0, 1536, 0.01),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    private function membre(Loop $loop, User $user): void
    {
        LoopMember::create([
            'organization_id' => (string) $loop->organization_id,
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
