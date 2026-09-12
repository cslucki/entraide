<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
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
use App\Services\ChatLoop\AiResponseExplanationService;
use App\Services\Knowledge\ClaimPatch;
use App\Services\Knowledge\ClaimProvenanceReader;
use App\Services\Knowledge\HumanClaimCorrection;
use App\Services\Knowledge\LoopClaimCompiler;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1549 — « Pourquoi ? » sur la memoire durable, et le chemin standard
 * « Corriger » depuis le ChatLoop.
 *
 * Ce que ces tests protegent :
 *
 *  - le DISCRIMINATEUR : un document ordinaire ne recoit jamais l'action
 *    Corriger ; seule une source citee dont le chunk porte la FK de note
 *    derivee est une memoire durable — la forme publique (`type =
 *    'retrieval'`) ne distingue rien, et rien ici ne s'appuie sur elle ;
 *  - une memoire hors ACL est masquee SANS fuite (ni auteur, ni titre, ni
 *    contenu), et une trace devenue injoignable apres supersession porte un
 *    wording DISTINCT d'un refus de droit — deux cles de traduction ;
 *  - la correction vise la VERSION LUE : perimee avant message = rien
 *    d'ecrit, pas meme un message ; perimee sous verrou = le message humain
 *    reste, la memoire ne bouge pas, le conflit est dit — jamais un ACK ;
 *  - zero provider generatif sur le chemin, et une Boucle `ai_agent` ne
 *    repond pas au message de correction ;
 *  - le rendu ne montre ni `subject_key`, ni score, ni pretention
 *    d'exhaustivite ; la portee nomme la Boucle ;
 *  - l'histoire d'un sujet n'est jamais tronquee par une borne technique
 *    (le piege `LoopClaimDelta::MAX_EVENEMENTS = 20`).
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1549ProvenanceAndCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    /** Membre de la Boucle courante SEULEMENT — jamais de la Boucle source B. */
    private User $bruno;

    private Loop $loop;

    /** Bascule du saboteur d'evenement modele (conflit sous verrou). */
    private static bool $tamperUnderLock = false;

    protected function setUp(): void
    {
        parent::setUp();

        self::$tamperUnderLock = false;

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
        $this->membre($this->loop, $this->bruno);

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1549',
        ]);

        config([
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

    // ─────────────────────────────────────── discriminateur (BP-35.1)

    public function test_un_document_ordinaire_n_offre_ni_section_memoire_ni_corriger(): void
    {
        $rootDossier = $this->rootDossier($this->loop);
        $file = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $rootDossier,
        ]);

        $chunk = DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $rootDossier,
            'dossier_file_id' => $file->id,
            'chunk_index' => 0,
            'content' => 'Le devis original de la charpente.',
            'content_hash' => hash('sha256', 'devis'),
            'embedding' => array_fill(0, 1536, 0.01),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $bubble = $this->bubbleCiting([$this->cited($chunk)], [$this->publicSource('S1', 'Devis charpente')]);

        $panel = $this->panel($bubble, $this->alice);

        $this->assertNull($panel['ledger']['memory'],
            'un chunk sans FK de note derivee n est PAS une memoire durable');

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertDontSeeHtml('data-why-memory')
            ->assertDontSeeHtml('data-correct-open-update');
    }

    public function test_une_memoire_durable_citee_montre_provenance_et_geste_corriger(): void
    {
        [$claim, $source] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $panel = $this->panel($bubble, $this->alice);
        $memory = $panel['ledger']['memory'];

        $this->assertCount(1, $memory['entries']);
        $entry = $memory['entries'][0];
        $this->assertSame('S1', $entry['ref']);
        $this->assertSame('active', $entry['state']);
        $this->assertSame((string) $claim->content, $entry['statement']);
        $this->assertNotNull($entry['observed_at']);
        $this->assertTrue($entry['same_loop']);
        $this->assertTrue($entry['can_correct']);
        $this->assertContains((string) $source->id, $entry['evidence_message_ids'],
            'la preuve humaine du claim doit etre navigable');

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertSeeHtml('data-why-memory')
            ->assertSee((string) $claim->content)
            ->assertSee(__('loops.why_memory_scope_here'))
            ->assertSeeHtml('data-correct-open-update')
            ->assertSeeHtml('data-correct-open-retract')
            ->call('startCorrection', 'S1', 'update')
            ->assertSet('correctingRef', 'S1')
            ->assertSet('correctingVersion', (int) $claim->version)
            ->assertSee(__('loops.correct_scope'))
            ->assertSee(__('loops.correct_form_note'));
    }

    public function test_la_navigation_vers_la_preuve_charge_le_message_d_origine(): void
    {
        [$claim, $source] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('showMessageInThread', (string) $source->id)
            ->assertDispatched('scroll-to-message', messageId: (string) $source->id);
    }

    // ─────────────────────────────────────── lecture seule et ACL

    public function test_une_memoire_d_une_autre_boucle_est_en_lecture_seule(): void
    {
        $loopB = $this->autreBoucle('Jardin partage', [$this->alice]);
        [$claimB] = $this->unClaim($loopB, 'Le forage du puits est confie a Ternisien.',
            'Pour le puits on part sur Ternisien.');

        $bubble = $this->memoryBubble($claimB);

        $panel = $this->panel($bubble, $this->alice);
        $entry = $panel['ledger']['memory']['entries'][0];

        $this->assertSame('active', $entry['state']);
        $this->assertFalse($entry['same_loop']);
        $this->assertFalse($entry['can_correct'], 'corriger depuis A ecrirait un message dans B');
        $this->assertSame([], $entry['evidence_message_ids'],
            'aucune navigation vers les messages d une autre Boucle');
        $this->assertSame('Jardin partage', $entry['loop_name'], 'la portee NOMME l autre Boucle');

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertSee('Jardin partage')
            ->assertDontSeeHtml('data-correct-open-update')
            // Meme forgee, l'ouverture est refusee : la version d'une entree
            // non corrigeable n'est jamais figee dans la carte.
            ->call('startCorrection', 'S1', 'update')
            ->assertSet('correctingRef', null);
    }

    public function test_une_source_de_memoire_hors_acl_est_masquee_sans_fuite(): void
    {
        $loopB = $this->autreBoucle('Budget confidentiel', [$this->alice]);
        [$claimB] = $this->unClaim($loopB, 'La reserve du bureau est de 40000 euros.',
            'On garde 40000 euros de reserve, entre nous.');

        $bubble = $this->memoryBubble($claimB);

        // Bruno est membre de la Boucle LUE, pas de la Boucle SOURCE.
        $panel = $this->panel($bubble, $this->bruno);
        $memory = $panel['ledger']['memory'];

        $this->assertSame([], $memory['entries']);
        $this->assertSame(1, $memory['denied_count']);

        $this->actingAs($this->bruno);
        $rendered = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertSee(trans_choice('loops.why_memory_denied', 1));

        $html = $rendered->html();
        $this->assertStringNotContainsString('40000', $html, 'le CONTENU refuse ne fuit pas');
        $this->assertStringNotContainsString('Budget confidentiel', $html, 'le NOM de la Boucle refusee ne fuit pas');
    }

    public function test_une_trace_injoignable_a_un_wording_distinct_d_un_refus_de_droit(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        // La supersession emporte les chunks de la note
        // (`DerivedKnowledgeNoteIndexer::forget()`) : la trace citee ne se
        // resout plus — ce n'est PAS un refus de droit.
        DossierChunk::query()->where('derived_knowledge_note_id', $claim->id)->delete();

        $panel = $this->panel($bubble, $this->alice);

        // La ligne a disparu, donc sa FK aussi : plus rien ne prouve que cette
        // source etait de la memoire. La section memoire SE TAIT — elle
        // n'affirme pas une origine qu'on ne peut plus etablir.
        $this->assertNull($panel['ledger']['memory'],
            'une origine indeterminable n invente pas une section memoire');
        $this->assertSame(1, $panel['ledger']['unreachable_count'],
            'la source injoignable se dit au niveau du ledger, sans nommer de famille');

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertSee(trans_choice('loops.why_source_unreachable', 1))
            // Toujours DISTINCT d'un refus de droit (BP-34), et desormais
            // distinct aussi d'une affirmation de memoire.
            ->assertDontSee(trans_choice('loops.why_memory_denied', 1))
            ->assertDontSeeHtml('data-why-memory')
            ->assertDontSeeHtml('data-correct-open-update');
    }

    /**
     * REMEDIATION MAJEUR 1 — le defaut qui a arrete le lifecycle.
     *
     * Un chunk de DOCUMENT ordinaire disparait a chaque reindexation d'Article
     * (`DossierArticleIndexer` supprime puis recree avec de NOUVEAUX ids). La
     * sonde d'existence passant AVANT le discriminateur, ce document devenait
     * une « trace de memoire injoignable » et ouvrait « Memoire de BouclePro »
     * sur une reponse qui n'avait cite AUCUNE memoire durable.
     */
    public function test_un_document_ordinaire_disparu_n_ouvre_jamais_la_section_memoire(): void
    {
        $rootDossier = $this->rootDossier($this->loop);
        $file = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $rootDossier,
        ]);

        $chunk = DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $rootDossier,
            'dossier_file_id' => $file->id,
            'chunk_index' => 0,
            'content' => 'Le devis original de la charpente.',
            'content_hash' => hash('sha256', 'devis-disparu'),
            'embedding' => array_fill(0, 1536, 0.01),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $bubble = $this->bubbleCiting([$this->cited($chunk)], [$this->publicSource('S1', 'Devis charpente')]);

        // Reindexation : la ligne citee disparait. Aucune memoire n'a jamais
        // ete impliquee dans cette reponse.
        DossierChunk::query()->whereKey($chunk->id)->delete();

        $panel = $this->panel($bubble, $this->alice);

        $this->assertNull($panel['ledger']['memory'],
            'un document disparu n est PAS une memoire durable');
        $this->assertSame(1, $panel['ledger']['unreachable_count']);

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertDontSeeHtml('data-why-memory')
            ->assertDontSee(__('loops.why_memory_title'))
            ->assertDontSeeHtml('data-correct-open-update')
            // Le troisieme etat, lui, est bien dit — sans nommer de famille.
            ->assertSee(trans_choice('loops.why_source_unreachable', 1));
    }

    /**
     * REMEDIATION R2 — F4 : les trois familles sont EXCLUSIVES.
     *
     * Une meme reponse cite les trois : un document ordinaire vivant, une
     * memoire durable, et une ligne disparue. Chaque source doit apparaitre
     * dans UNE seule categorie. Avant la remediation, la memoire etait
     * annoncee DEUX FOIS — comme memoire ET comme document nomme, puisque son
     * chunk vit dans le Dossier racine, accessible a tout membre.
     */
    public function test_chaque_source_citee_n_appartient_qu_a_une_seule_famille(): void
    {
        $rootDossier = $this->rootDossier($this->loop);

        [$claim] = $this->unClaim($this->loop);
        $memoryChunk = DossierChunk::query()
            ->where('derived_knowledge_note_id', $claim->id)
            ->firstOrFail();

        $file = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $rootDossier,
        ]);

        $documentChunk = $this->unChunkDocumentaire($rootDossier, $file, 'Le devis original de la charpente.');
        $disparuChunk = $this->unChunkDocumentaire($rootDossier, $file, 'Un extrait qui sera reindexe.');

        $bubble = $this->bubbleCiting(
            [
                $this->cited($documentChunk),
                $this->cited($memoryChunk),
                $this->cited($disparuChunk),
            ],
            [
                $this->publicSource('S1', 'Devis charpente'),
                $this->publicSource('S2', 'Memoire de la Boucle'),
                $this->publicSource('S3', 'Extrait reindexe'),
            ],
        );

        // Reindexation de la troisieme : sa ligne disparait, sa FK avec elle.
        DossierChunk::query()->whereKey($disparuChunk->id)->delete();

        $panel = $this->panel($bubble, $this->alice);
        $ledger = $panel['ledger'];

        // ── Documentaire : S1 SEULE. Ni la memoire, ni la ligne disparue.
        $this->assertSame(1, $ledger['documents']['cited_count'],
            'le compte documentaire derive des entrees RETENUES, pas de la longueur de la trace');
        $this->assertSame(0, $ledger['documents']['masked_count']);
        $this->assertSame([['ref' => 'S1', 'title' => 'Devis charpente', 'dossier_name' => null]],
            $ledger['documents']['entries'],
            'une memoire durable n est jamais listee comme document ordinaire');

        // ── Memoire : S2 SEULE.
        $this->assertCount(1, $ledger['memory']['entries']);
        $this->assertSame('S2', $ledger['memory']['entries'][0]['ref']);
        $this->assertSame((string) $claim->content, $ledger['memory']['entries'][0]['statement']);
        $this->assertSame(0, $ledger['memory']['denied_count']);

        // ── Injoignable : S3 SEULE, et sans nommer de famille.
        $this->assertSame(1, $ledger['unreachable_count']);

        $this->actingAs($this->alice);
        $html = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertSee(trans_choice('loops.why_source_unreachable', 1))
            ->assertSee(__('loops.why_memory_title'))
            ->assertSee('Devis charpente')
            ->html();

        // UNE seule entree documentaire dans le panneau, et UNE seule entree
        // memoire : ni la memoire ni la ligne disparue ne sont listees comme
        // documents. On compte les entrees plutot que de chercher un titre
        // dans la page — la bulle IA affiche par ailleurs ses propres sources,
        // et un `assertDontSee` global serait vert pour la mauvaise raison.
        $this->assertSame(1, substr_count($html, 'data-why-document-entry'),
            'une seule source est presentee comme document');
        $this->assertSame(1, substr_count($html, 'data-why-memory-entry'),
            'une seule source est presentee comme memoire');
    }

    /**
     * REMEDIATION R2 — F3 : apres une correction, la surface ne promet rien
     * qu'elle ne puisse tenir.
     *
     * La supersession emporte le chunk cite : la section memoire se tait, et
     * le ledger dit l'injoignabilite SANS nommer de famille. Les deux mentions
     * « cet enonce a evolue » / « a ete retire » ont ete retirees de cette
     * surface — elle ne peut pas les produire.
     */
    public function test_apres_une_correction_la_surface_ne_promet_pas_un_etat_qu_elle_ne_peut_pas_produire(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'update')
            ->set('correctionNewText', 'Lemercier realise la charpente du chantier.')
            ->set('correctionText', 'Ce n est plus Vaucanson, le marche est passe a Lemercier.')
            ->call('submitCorrection')
            ->assertSet('correctionFlash', __('loops.correct_ack'));

        // L'ACK reste l'autorite immediate : c'est lui qui dit ce qui s'est
        // passe, pas une section qui aurait survecu a la mutation.
        $panel = $this->panel($bubble->fresh(), $this->alice);

        $this->assertNull($panel['ledger']['memory'],
            'la memoire corrigee quitte la section : son chunk cite n existe plus');
        $this->assertSame(1, $panel['ledger']['unreachable_count'],
            'elle se dit au ledger, sans nommer de famille');

        $component
            ->assertDontSeeHtml('data-memory-evolved')
            ->assertDontSeeHtml('data-memory-retracted')
            ->assertSee(__('loops.correct_ack'));

        $this->assertSame([], $component->get('whyMemoryVersions'),
            'plus aucune version figee : il n y a plus rien a corriger sur cette bulle');

        // Les libelles morts n'existent plus : une cle absente se rend
        // elle-meme, ce qui rendrait le test ci-dessus trompeusement vert si
        // on l'avait ecrit sur le texte.
        $this->assertSame('loops.why_memory_evolved', __('loops.why_memory_evolved'));
        $this->assertSame('loops.why_memory_retracted', __('loops.why_memory_retracted'));
    }

    public function test_le_sujet_retracte_se_dit_au_lecteur_standard(): void
    {
        [$claim] = $this->unClaim($this->loop);

        $resultat = app(HumanClaimCorrection::class)->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key, (int) $claim->version,
            'Non, ce marche a ete annule la semaine derniere.',
        );
        $this->assertTrue($resultat['ok'], 'PREMISSE : le retrait doit reussir');

        // Le lecteur standard — celui que W1.5-B reutilisera — dit l'etat, y
        // compris l'historique de correction APRES un RETRACT : la frontiere
        // vit sur la ligne archivee, les champs `corrected_*` n'existent plus.
        $provenance = app(ClaimProvenanceReader::class)->provenance(
            $this->organization, $this->loop->fresh(), $claim->fresh(), $this->alice,
        );

        $this->assertSame('retracted', $provenance['state']);
        $this->assertFalse($provenance['can_correct'], 'un sujet sans enonce actif n a pas de geste');
        $this->assertCount(1, $provenance['corrections']);
        $this->assertSame($this->alice->publicDisplayName(), $provenance['corrections'][0]['by_name']);
        $this->assertSame($resultat['message_id'], $provenance['corrections'][0]['message_id']);
    }

    // ─────────────────────────────────────── le geste Corriger

    public function test_l_update_depuis_le_panneau_corrige_la_memoire_et_accuse_reception(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);
        $invocationsAvant = AiProviderInvocation::count();

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'update')
            ->set('correctionNewText', 'Lemercier realise la charpente du chantier.')
            ->set('correctionText', 'Ce n est plus Vaucanson, le marche est passe a Lemercier.')
            ->call('submitCorrection');

        $component->assertSet('correctionFlash', __('loops.correct_ack'))
            ->assertSet('correctingRef', null)
            ->assertSet('correctionConflict', null);

        $ancien = DerivedKnowledgeNote::findOrFail($claim->id);
        $this->assertSame(DerivedKnowledgeNote::STATUS_SUPERSEDED, $ancien->status);
        $this->assertNotNull($ancien->superseded_by_id, 'l UPDATE chaine la supersession');
        $this->assertIsArray(($ancien->provenance ?? [])['human_correction'] ?? null,
            'la frontiere de verite est posee sur la ligne archivee');

        $nouveau = DerivedKnowledgeNote::findOrFail((string) $ancien->superseded_by_id);
        $this->assertSame('Lemercier realise la charpente du chantier.', $nouveau->content);
        $this->assertSame((int) $claim->version + 1, (int) $nouveau->version);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $nouveau->status);

        $messageCorrection = LoopMessage::query()
            ->where('loop_id', $this->loop->id)
            ->get()
            ->first(fn (LoopMessage $m): bool => $m->isClaimCorrection());
        $this->assertNotNull($messageCorrection, 'la preuve de la correction est un vrai message');
        $this->assertSame((string) $this->alice->id, (string) $messageCorrection->sender_id);

        $this->assertSame($invocationsAvant, AiProviderInvocation::count(),
            'corriger depuis le panneau ne facture AUCUN provider');
    }

    public function test_le_retract_depuis_le_panneau_retire_l_enonce_et_ses_chunks(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);
        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'retract')
            ->set('correctionText', 'Ce marche a ete annule, cet enonce n est plus vrai.')
            ->call('submitCorrection')
            ->assertSet('correctionFlash', __('loops.correct_ack'));

        $this->assertSame(0, DerivedKnowledgeNote::query()->claims()->active()->count(),
            'aucun successeur : l enonce sort de la memoire');
        $this->assertSame(0, DossierChunk::query()->where('derived_knowledge_note_id', $claim->id)->count(),
            'l enonce sort aussi du retrieval');
        $this->assertSame($messagesAvant + 1,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'exactement UN message humain de correction');
    }

    /**
     * La garde `version_perimee` de `HumanClaimCorrection` (1re passe, AVANT
     * toute ecriture) est-elle REELLEMENT exercee depuis le panneau ?
     *
     * Ce test a ete reecrit en remediation : la version precedente faisait
     * bouger la base via une correction CONCURRENTE, et une correction emporte
     * les chunks de l'enonce qu'elle archive (`forget()`, synchrone). La trace
     * citee devenait donc irresoluble, `citedMemoryNote()` rendait `null`, et
     * `submitCorrection()` sortait a SA branche `$note === null` : le service
     * n'etait jamais appele. Le vert venait d'un autre chemin que celui que le
     * mandat demande de proteger — deplacer la garde de `HumanClaimCorrection`
     * l'aurait laisse vert.
     *
     * La peremption est donc posee DIRECTEMENT, par mutation de la colonne
     * `version`, sans rien faire subir a la trace citee : le chunk reste
     * vivant, la note reste resoluble, et le seul motif de refus possible est
     * la garde de version elle-meme.
     */
    public function test_une_version_perimee_avant_message_n_ecrit_rien(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'retract')
            ->assertSet('correctingVersion', 1);

        // La memoire vieillit SOUS le formulaire ouvert — sans toucher a la
        // trace citee. C'est la seule facon d'atteindre la garde de version
        // avec une citation encore resoluble.
        DerivedKnowledgeNote::query()->whereKey($claim->id)->update(['version' => 2]);

        // ── Les deux sorties anticipees de `submitCorrection()` sont EXCLUES,
        //    donc le service SERA appele. C'est ce que ce test doit prouver.
        $this->assertTrue(
            DossierChunk::query()->where('derived_knowledge_note_id', $claim->id)->exists(),
            'PREMISSE : la trace citee est toujours en base',
        );
        $this->assertNotNull(
            app(AiResponseExplanationService::class)
                ->citedMemoryNote($this->loop, $bubble, $this->alice, 'S1'),
            'PREMISSE : la citation se resout — la branche `$note === null` est hors jeu',
        );
        // REMEDIATION R2 : la carte est indexee par BULLE ET par reference —
        // c'est le triplet (bulle, reference, version) qui est revalide.
        $this->assertSame(
            1,
            $component->get('whyMemoryVersions')[$bubble->id.'|S1'] ?? null,
            'PREMISSE : le triplet bulle/ref/version tient — la garde d appariement est hors jeu',
        );
        $this->assertSame(2, (int) $claim->fresh()->version,
            'PREMISSE : la version ACTIVE (2) differe de la version LUE (1)');

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $component
            ->set('correctionText', 'Ma correction arrive trop tard.')
            ->call('submitCorrection')
            ->assertSet('correctionConflict', __('loops.correct_conflict_before'))
            ->assertSet('correctionFlash', '');

        $this->assertSame($messagesAvant,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'une action perimee ne laisse AUCUNE trace, pas meme un message');

        $apres = $claim->fresh();
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $apres->status,
            'l enonce n est pas archive : la 1re passe a refuse avant toute mutation');
        $this->assertSame(2, (int) $apres->version, 'la memoire n a pas bouge');
    }

    /**
     * Le retour du service, lu directement : `version_perimee` rend bien
     * `message_id === null`, ce qui est la seule chose sur laquelle la surface
     * s'appuie pour dire « rien n'a ete enregistre ».
     */
    public function test_la_garde_de_version_rend_message_id_null_et_n_ecrit_rien(): void
    {
        [$claim] = $this->unClaim($this->loop);

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $resultat = app(HumanClaimCorrection::class)->retracter(
            $this->organization, $this->loop->fresh(), $this->alice,
            (string) $claim->subject_key,
            (int) $claim->version + 1, // une version que personne n'a jamais lue
            'Sonde directe de la garde de version.',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('version_perimee', $resultat['raison']);
        $this->assertNull($resultat['message_id'],
            'la 1re passe refuse AVANT le message : c est ce `null` que la surface traduit');
        $this->assertSame($messagesAvant,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count());
    }

    public function test_un_conflit_apres_message_conserve_le_message_et_dit_le_conflit(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        // Saboteur : des que le message de correction est ecrit, la version du
        // claim bouge — la revalidation SOUS VERROU doit alors refuser, tout
        // en CONSERVANT le message humain deja poste.
        LoopMessage::created(function (LoopMessage $message) use ($claim): void {
            if (self::$tamperUnderLock && $message->isClaimCorrection()) {
                DB::table('derived_knowledge_notes')
                    ->where('id', $claim->id)
                    ->update(['version' => (int) $claim->version + 7]);
            }
        });

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();
        self::$tamperUnderLock = true;

        try {
            $this->actingAs($this->alice);
            Livewire::test(LoopChat::class, ['loop' => $this->loop])
                ->call('showWhy', $bubble->id)
                ->call('startCorrection', 'S1', 'retract')
                ->set('correctionText', 'Cet enonce est faux, retirez-le.')
                ->call('submitCorrection')
                ->assertSet('correctionConflict', __('loops.correct_conflict_after'))
                ->assertSet('correctionFlash', '');
        } finally {
            self::$tamperUnderLock = false;
        }

        $this->assertSame($messagesAvant + 1,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'le message humain n est JAMAIS supprime pour simuler une transaction parfaite');

        $apres = DerivedKnowledgeNote::findOrFail($claim->id);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $apres->status, 'la memoire n a pas bouge');
        $this->assertNull(($apres->provenance ?? [])['human_correction'] ?? null,
            'aucune frontiere posee : la correction n a pas eu lieu');
    }

    /**
     * REMEDIATION MAJEUR 2 — le plus grave des quatre.
     *
     * L'ADRESSE etait reecrivable par le client pendant que la VERSION etait
     * figee : un formulaire ouvert sur S1 rétractait S2 des que les deux sujets
     * partageaient un numero de version, avec un ACK POSITIF. La preuve humaine
     * parlait de S1, la frontiere `human_correction` se posait sur S2 — une
     * frontiere auto-coherente et FAUSSE, sur laquelle `ClaimResurrectionGuard`
     * arbitrerait ensuite toute correction legitime de S1.
     *
     * Deux mecanismes INDEPENDANTS ferment ce trou, et ce test verifie que la
     * MUTATION est impossible, pas seulement qu'un des deux a parle.
     */
    public function test_une_ref_forgee_ne_peut_pas_faire_muter_un_autre_sujet(): void
    {
        // Deux sujets DISTINCTS, tous deux en version 1 : la condition exacte
        // qui rendait la forge silencieuse.
        [$claimX] = $this->unClaim($this->loop,
            'Vaucanson realise la charpente du chantier.',
            'Pour la charpente on part sur Vaucanson.');

        $source2 = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Les menuiseries arrivent le 14 mars.',
            'type' => 'user',
        ]);

        LoopClaimPatchAgent::fake(fn (): TextResponse => new TextResponse(
            (string) json_encode(['operations' => [[
                'op' => 'ADD', 'text' => 'La livraison des menuiseries est prevue le 14 mars.',
                'evidence' => [(string) $source2->id],
            ]]], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
        $this->assertTrue(app(LoopClaimCompiler::class)->compile($this->loop->fresh())['applique'],
            'PREMISSE : le second enonce doit se compiler');

        $claimY = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->claims()->active()
            ->where('content', 'La livraison des menuiseries est prevue le 14 mars.')
            ->firstOrFail();

        $this->assertNotSame((string) $claimX->subject_key, (string) $claimY->subject_key);
        $this->assertSame(1, (int) $claimX->fresh()->version);
        $this->assertSame(1, (int) $claimY->version);

        $bubble = $this->bubbleCiting(
            [
                $this->cited(DossierChunk::query()->where('derived_knowledge_note_id', $claimX->id)->firstOrFail()),
                $this->cited(DossierChunk::query()->where('derived_knowledge_note_id', $claimY->id)->firstOrFail()),
            ],
            [$this->publicSource('S1', null), $this->publicSource('S2', null)],
        );

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'retract')
            ->assertSet('correctingRef', 'S1')
            ->assertSet('correctingVersion', 1);

        // 1er mecanisme : `#[Locked]`. Le client ne peut PAS reecrire l'adresse.
        try {
            $component->set('correctingRef', 'S2');
            $this->fail('`correctingRef` doit etre verrouille cote serveur');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('correctingRef', $e->getMessage());
        }

        // 2e mecanisme, independant : meme si l'adresse changeait, le couple
        // (adresse, version) est revalide contre la carte figee a la
        // soumission. On le prouve en cassant l'appariement cote SERVEUR.
        $component
            ->set('correctionText', 'Non, Vaucanson est faux pour la charpente.')
            ->call('submitCorrection')
            ->assertSet('correctionFlash', __('loops.correct_ack'));

        // Le sujet REELLEMENT ouvert est celui qui a bouge — jamais l'autre.
        $this->assertNotSame(DerivedKnowledgeNote::STATUS_ACTIVE, $claimX->fresh()->status,
            'X, le sujet ouvert par la personne, est bien celui qui est retracte');
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $claimY->fresh()->status,
            'Y, jamais ouvert, n a pas bouge');

        $correction = LoopMessage::query()->where('loop_id', $this->loop->id)->get()
            ->first(fn (LoopMessage $m): bool => $m->isClaimCorrection());
        $this->assertNotNull($correction);
        $this->assertSame((string) $claimX->subject_key, (string) ($correction->metadata['corrected_subject_key'] ?? null),
            'la preuve humaine et la memoire mutee designent le MEME sujet');
        $this->assertSame($messagesAvant + 1,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'une seule ecriture, celle qui etait demandee');
    }

    /**
     * REMEDIATION R2 — F1, le defaut que le verrou de `correctingRef` NE
     * FERMAIT PAS.
     *
     * Ce qui decide quel enonce est mute n'est pas la reference affichee :
     * c'est la BULLE dont les citations sont re-resolues a la soumission. Une
     * reference `S1` n'est unique qu'a l'interieur d'une reponse — c'est un
     * numero d'ordre de citation, pas une identite.
     *
     * Deux bulles nommant chacune leur source `S1`, sur deux sujets differents
     * en meme version, satisfaisaient donc trivialement la garde d'appariement
     * de R1 : `S1 == S1`, `1 == 1`. Forger `whyMessageId` rendait un ACCUSE DE
     * RECEPTION POSITIF pour la RETRACTATION d'un sujet que la personne
     * n'avait jamais ouvert — et posait sur lui une frontiere humaine fondee
     * sur un message qui parle d'autre chose.
     */
    public function test_une_bulle_forgee_ne_peut_pas_muter_l_enonce_d_une_autre_bulle(): void
    {
        [$claimX] = $this->unClaim($this->loop,
            'Vaucanson realise la charpente du chantier.',
            'Pour la charpente on part sur Vaucanson.');

        $source2 = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Les menuiseries arrivent le 14 mars.',
            'type' => 'user',
        ]);

        LoopClaimPatchAgent::fake(fn (): TextResponse => new TextResponse(
            (string) json_encode(['operations' => [[
                'op' => 'ADD', 'text' => 'La livraison des menuiseries est prevue le 14 mars.',
                'evidence' => [(string) $source2->id],
            ]]], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
        $this->assertTrue(app(LoopClaimCompiler::class)->compile($this->loop->fresh())['applique'],
            'PREMISSE : le second enonce doit se compiler');

        $claimY = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->claims()->active()
            ->where('content', 'La livraison des menuiseries est prevue le 14 mars.')
            ->firstOrFail();

        // La condition exacte de la forge : meme reference, meme version.
        $this->assertSame(1, (int) $claimX->fresh()->version);
        $this->assertSame(1, (int) $claimY->version);

        $bulleX = $this->memoryBubble($claimX);
        $bulleY = $this->memoryBubble($claimY);

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bulleX->id)
            ->call('startCorrection', 'S1', 'retract')
            ->assertSet('correctingMessageId', (string) $bulleX->id)
            ->assertSet('correctingVersion', 1);

        // 1er mecanisme : `#[Locked]`. Le client ne reecrit pas la bulle lue.
        try {
            $component->set('whyMessageId', (string) $bulleY->id);
            $this->fail('`whyMessageId` doit etre verrouille cote serveur');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('whyMessageId', $e->getMessage());
        }

        // 2e mecanisme, INDEPENDANT : la carte figee est indexee par bulle. On
        // casse donc le triplet au niveau PHP — la seule facon honnete de
        // prouver que la garde refuse au lieu d'ecrire, le chemin client etant
        // desormais ferme.
        $instance = $component->instance();
        $instance->whyMessageId = (string) $bulleY->id;
        $instance->correctionText = 'Non, Vaucanson est faux pour la charpente.';
        $instance->submitCorrection(
            app(HumanClaimCorrection::class),
            app(AiResponseExplanationService::class),
        );

        $this->assertSame('', $instance->correctionFlash, 'aucun ACK sur un triplet rompu');
        $this->assertSame(__('loops.correct_conflict_before'), $instance->correctionConflict);
        $this->assertNull($instance->correctingRef, 'le formulaire est referme');

        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $claimX->fresh()->status,
            'X, le sujet ouvert, n a pas bouge — on n a pas corrige a sa place');
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $claimY->fresh()->status,
            'Y, jamais ouvert, n a pas bouge non plus');
        $this->assertSame($messagesAvant,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'un triplet rompu n ecrit rien, pas meme un message');
    }

    /**
     * REMEDIATION R2 — F2 : le mode decide entre une reecriture et une
     * SUPPRESSION. Une valeur hors domaine ne retombe pas sur la seconde.
     */
    public function test_un_mode_hors_domaine_n_ecrit_rien(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'update')
            ->assertSet('correctingMode', 'update');

        // 1er mecanisme : `#[Locked]` — le mode n'est plus une liaison de
        // propriete, il se change par une ACTION dont le domaine est controle.
        try {
            $component->set('correctingMode', 'n-importe-quoi');
            $this->fail('`correctingMode` doit etre verrouille cote serveur');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('correctingMode', $e->getMessage());
        }

        $component->call('setCorrectionMode', 'n-importe-quoi')
            ->assertSet('correctingMode', 'update', 'l action refuse une valeur hors domaine');
        $component->call('setCorrectionMode', 'retract')
            ->assertSet('correctingMode', 'retract', 'et accepte les deux gestes reels');
        $component->call('setCorrectionMode', 'update')
            ->assertSet('correctingMode', 'update');

        // 2e mecanisme, INDEPENDANT : la revalidation au point d'ecriture.
        $instance = $component->instance();
        $instance->correctingMode = 'n-importe-quoi';
        $instance->correctionText = 'Je voulais REMPLACER l enonce, pas le retirer.';
        $instance->submitCorrection(
            app(HumanClaimCorrection::class),
            app(AiResponseExplanationService::class),
        );

        $this->assertSame('', $instance->correctionFlash,
            'jamais un ACK positif pour un geste que personne n a demande');
        $this->assertSame(__('loops.correct_conflict_before'), $instance->correctionConflict);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $claim->fresh()->status,
            'aucun repli silencieux sur RETRACT');
        $this->assertSame($messagesAvant,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'et aucun message public');
    }

    /**
     * REMEDIATION R2 — F5 : `booted()` conclut l'adhesion a chaque requete,
     * mais Livewire applique les mises a jour du client APRES. Sans verrou,
     * une requete portant `isMember: true` rouvrait la gate d'affichage pour
     * toute la duree de l'appel.
     *
     * Ce verrou ferme l'honnetete Livewire — l'autorite metier
     * (`canView()`, `HumanClaimCorrection`) n'a jamais dependu de ce champ.
     */
    public function test_l_adhesion_n_est_pas_reinscriptible_par_le_client(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->assertSet('isMember', true);

        try {
            $component->set('isMember', false);
            $this->fail('`isMember` doit etre verrouille cote serveur');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('isMember', $e->getMessage());
        }
    }

    /**
     * REMEDIATION MAJEUR 2, second mecanisme isole.
     *
     * `whyMemoryVersions` est elle aussi `#[Locked]` : Livewire refuse net une
     * tentative de reecriture depuis le client (verifie ci-dessous). La garde
     * d'appariement est donc une DEFENSE EN PROFONDEUR — elle ne se declenche
     * plus par un chemin client. On l'exerce au niveau PHP, en cassant le
     * triplet sur l'instance puis en appelant la methode directement : c'est la
     * seule facon honnete de prouver qu'elle refuse au lieu d'ecrire.
     *
     * REMEDIATION R2 : la carte est desormais indexee par BULLE ET reference.
     * Ecraser son contenu par une cle de l'ancienne forme (`S1`) suffit donc a
     * rompre le triplet — ce que ce test fait, et ce qu'il doit refuser.
     */
    public function test_un_appariement_ref_version_rompu_n_ecrit_rien(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'retract')
            ->assertSet('correctingVersion', 1);

        // La carte figee est verrouillee : le client ne la reecrit pas.
        try {
            $component->set('whyMemoryVersions', ['S1' => 7]);
            $this->fail('`whyMemoryVersions` doit etre verrouille cote serveur');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('whyMemoryVersions', $e->getMessage());
        }

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        // Couple rompu au niveau PHP, puis appel direct de la methode.
        $instance = $component->instance();
        $instance->whyMemoryVersions = ['S1' => 7];
        $instance->correctionText = 'Ce marche est annule, retirez cet enonce.';
        $instance->submitCorrection(
            app(HumanClaimCorrection::class),
            app(AiResponseExplanationService::class),
        );

        $this->assertSame('', $instance->correctionFlash, 'aucun ACK sur un couple rompu');
        $this->assertSame(__('loops.correct_conflict_before'), $instance->correctionConflict);
        $this->assertNull($instance->correctingRef, 'le formulaire est referme');

        $this->assertSame($messagesAvant,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'un couple rompu n ecrit rien, pas meme un message');
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $claim->fresh()->status,
            'la memoire n a pas bouge');
    }

    /**
     * REMEDIATION MAJEUR 3 — aucune correction ne survit a un changement de
     * panneau. Reproductible SANS aucune forge : il suffisait d'ouvrir
     * « Pourquoi ? » sur une autre bulle.
     */
    public function test_ouvrir_un_autre_pourquoi_ferme_la_correction_en_cours(): void
    {
        [$claimA] = $this->unClaim($this->loop,
            'Vaucanson realise la charpente du chantier.',
            'Pour la charpente on part sur Vaucanson.');

        $source2 = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Les menuiseries arrivent le 14 mars.',
            'type' => 'user',
        ]);

        LoopClaimPatchAgent::fake(fn (): TextResponse => new TextResponse(
            (string) json_encode(['operations' => [[
                'op' => 'ADD', 'text' => 'La livraison des menuiseries est prevue le 14 mars.',
                'evidence' => [(string) $source2->id],
            ]]], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
        $this->assertTrue(app(LoopClaimCompiler::class)->compile($this->loop->fresh())['applique']);

        $claimB = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $this->loop->id)->claims()->active()
            ->where('content', 'La livraison des menuiseries est prevue le 14 mars.')
            ->firstOrFail();

        $bulleA = $this->memoryBubble($claimA);
        $bulleB = $this->memoryBubble($claimB);

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bulleA->id)
            ->call('startCorrection', 'S1', 'retract')
            ->assertSet('correctingRef', 'S1')
            // Aucune forge : la personne ouvre simplement l'autre panneau.
            ->call('showWhy', $bulleB->id)
            ->assertSet('correctingRef', null)
            ->assertSet('correctingVersion', 0)
            ->assertSet('correctionText', '')
            ->assertDontSeeHtml('data-correct-form');
    }

    /**
     * REMEDIATION MAJEUR 4 — un enonce plus court que `ClaimPatch::MIN_TEXTE`
     * se refuse AVANT tout appel : zero message publie, et un message qui NOMME
     * la contrainte au lieu d'inventer un conflit.
     */
    public function test_un_enonce_trop_court_est_refuse_avant_toute_ecriture(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'update')
            ->set('correctionNewText', 'Budget: 5000e')   // 13 < MIN_TEXTE
            ->set('correctionText', 'Le budget a change.')
            ->call('submitCorrection')
            ->assertHasErrors(['correctionNewText'])
            ->assertSee(__('loops.correct_new_text_min', ['min' => ClaimPatch::MIN_TEXTE]))
            // Ni ACK, ni conflit invente : la contrainte se nomme.
            ->assertSet('correctionFlash', '')
            ->assertSet('correctionConflict', null)
            // Le formulaire reste ouvert : la personne corrige sa saisie.
            ->assertSet('correctingRef', 'S1');

        $this->assertSame($messagesAvant,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'aucun message public pour une faute de saisie');
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $claim->fresh()->status);
    }

    public function test_une_double_soumission_n_applique_qu_une_mutation(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'retract')
            ->set('correctionText', 'Ce marche est annule.')
            ->call('submitCorrection')
            ->assertSet('correctionFlash', __('loops.correct_ack'))
            // Le formulaire est ferme AVANT l'annonce : la seconde soumission
            // n'a plus d'etat, elle ne fait rien.
            ->call('submitCorrection');

        $corrections = LoopMessage::query()
            ->where('loop_id', $this->loop->id)
            ->get()
            ->filter(fn (LoopMessage $m): bool => $m->isClaimCorrection());

        $this->assertCount(1, $corrections, 'un seul message de correction');
        $this->assertSame(1, DerivedKnowledgeNote::query()->claims()
            ->where('status', DerivedKnowledgeNote::STATUS_SUPERSEDED)->count(),
            'une seule mutation');
    }

    public function test_une_boucle_archivee_rend_la_memoire_lisible_sans_geste(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $this->loop->fresh()])
            ->call('showWhy', $bubble->id)
            ->assertSee((string) $claim->content)
            ->assertSet('whyCanCorrect', false)
            ->assertDontSeeHtml('data-correct-open-update')
            ->assertDontSeeHtml('data-correct-open-retract')
            ->call('startCorrection', 'S1', 'update')
            ->assertSet('correctingRef', null);
    }

    public function test_le_droit_revoque_entre_l_affichage_et_le_clic_n_ecrit_rien(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'retract')
            ->set('correctionText', 'Je n ai plus le droit de dire ceci.');

        LoopMember::query()
            ->where('loop_id', $this->loop->id)
            ->where('user_id', $this->alice->id)
            ->update(['status' => 'inactive']);

        $messagesAvant = LoopMessage::query()->where('loop_id', $this->loop->id)->count();

        $component->call('submitCorrection');

        $this->assertSame($messagesAvant,
            LoopMessage::query()->where('loop_id', $this->loop->id)->count(),
            'un droit tombe entre l affichage et le clic n ecrit rien');
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE,
            DerivedKnowledgeNote::findOrFail($claim->id)->status);
    }

    // ─────────────────────────────────────── vocabulaire et bornes

    public function test_le_rendu_ne_montre_ni_subject_key_ni_score_ni_exhaustivite(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $bubble = $this->memoryBubble($claim);

        $this->actingAs($this->alice);
        $component = Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'update');

        $html = $component->html();

        $this->assertStringNotContainsString((string) $claim->subject_key, $html,
            'subject_key est une identite serveur : elle ne s affiche jamais');
        $this->assertStringNotContainsString('human_correction', $html,
            'la frontiere est une donnee interne, pas un mot d interface');
        $this->assertStringNotContainsString((string) $claim->id, $html,
            'l id de la note ne s affiche jamais');
        $component->assertSee(__('loops.correct_scope'));
    }

    public function test_l_historique_d_un_sujet_n_est_pas_tronque_a_vingt_evenements(): void
    {
        [$claim] = $this->unClaim($this->loop);
        $correction = app(HumanClaimCorrection::class);

        // Plus de 20 evenements sur le SUJET : la borne de prompt
        // `LoopClaimDelta::MAX_EVENEMENTS = 20` ne doit jamais gouverner la
        // lecture d'un historique.
        $version = (int) $claim->version;

        for ($i = 1; $i <= 21; $i++) {
            $resultat = $correction->mettreAJour(
                $this->organization, $this->loop->fresh(), $this->alice,
                (string) $claim->subject_key, $version,
                'Precision numero '.$i.'.',
                'Enonce corrige, iteration '.$i.'.',
            );
            $this->assertTrue($resultat['ok'], 'PREMISSE : la correction '.$i.' doit reussir');
            $version++;
        }

        $provenance = app(ClaimProvenanceReader::class)->provenance(
            $this->organization, $this->loop->fresh(), $claim->fresh(), $this->alice,
        );

        $this->assertCount(21, $provenance['corrections'],
            'les 21 corrections sont lues — aucune borne silencieuse');
        $this->assertSame('Enonce corrige, iteration 21.', $provenance['statement']);
    }

    public function test_une_boucle_ai_agent_ne_repond_pas_au_message_de_correction(): void
    {
        $agentLoop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Assistant du chantier',
            'visibility' => 'private',
            'type' => 'ai_agent',
        ]);
        $this->membre($agentLoop, $this->alice);
        app(LoopRootDocumentService::class)->ensureRootDossier($agentLoop->fresh());

        [$claim] = $this->unClaim($agentLoop, 'Le rendez-vous de chantier est le mardi.',
            'On cale le rendez-vous de chantier au mardi.');
        $bubble = $this->memoryBubble($claim, loop: $agentLoop);

        Bus::fake([GenerateAiAgentResponse::class]);

        $this->actingAs($this->alice);
        Livewire::test(LoopChat::class, ['loop' => $agentLoop])
            ->call('showWhy', $bubble->id)
            ->call('startCorrection', 'S1', 'retract')
            ->set('correctionText', 'Le rendez-vous du mardi est supprime.')
            ->call('submitCorrection')
            ->assertSet('correctionFlash', __('loops.correct_ack'));

        Bus::assertNotDispatched(GenerateAiAgentResponse::class);
    }

    // ═════════════════════════════════════════════════════════ helpers

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

    private function autreBoucle(string $nom, array $membres): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => $nom,
            'visibility' => 'private',
        ]);

        foreach ($membres as $membre) {
            $this->membre($loop, $membre);
        }

        app(LoopRootDocumentService::class)->ensureRootDossier($loop->fresh());

        return $loop;
    }

    private function rootDossier(Loop $loop): string
    {
        return (string) Dossier::query()
            ->where('loop_id', $loop->id)
            ->value('id');
    }

    /**
     * Un enonce reel, compile par le chemin reel — jamais insere a la main :
     * son chunk et sa provenance viennent du vrai pipeline (motif T1548).
     *
     * @return array{0: DerivedKnowledgeNote, 1: LoopMessage}
     */
    private function unClaim(
        Loop $loop,
        string $texte = 'Vaucanson realise la charpente, avec une hausse de 12%.',
        string $preuve = 'Pour la charpente on part sur Vaucanson, malgre les 12% de hausse.',
    ): array {
        $source = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $loop->id,
            'sender_id' => $this->alice->id,
            'body' => $preuve,
            'type' => 'user',
        ]);

        LoopClaimPatchAgent::fake(fn (): TextResponse => new TextResponse(
            (string) json_encode(['operations' => [[
                'op' => 'ADD', 'text' => $texte, 'evidence' => [(string) $source->id],
            ]]], JSON_UNESCAPED_UNICODE),
            new Usage(60, 40), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        $bilan = app(LoopClaimCompiler::class)->compile($loop->fresh());
        $this->assertTrue($bilan['applique'], 'PREMISSE : la compilation initiale doit reussir');

        $claim = DerivedKnowledgeNote::query()
            ->where('source_loop_id', $loop->id)
            ->claims()->active()->firstOrFail();

        $this->assertTrue(
            DossierChunk::query()->where('derived_knowledge_note_id', $claim->id)->exists(),
            'PREMISSE : l enonce compile doit etre indexe',
        );

        return [$claim, $source];
    }

    /**
     * Une bulle IA de la Boucle COURANTE citant le chunk d'une note derivee —
     * la forme exacte que `LoopKnowledgeAnswerService` persiste : `retrieval`
     * dans la trace, forme publique appariee position par position.
     */
    private function memoryBubble(DerivedKnowledgeNote $note, string $ref = 'S1', ?Loop $loop = null): LoopMessage
    {
        $loop ??= $this->loop;

        $chunk = DossierChunk::query()
            ->where('derived_knowledge_note_id', $note->id)
            ->firstOrFail();

        return $this->bubbleCiting(
            [$this->cited($chunk)],
            [$this->publicSource($ref, null)],
            $loop,
        );
    }

    /**
     * @param  list<array{chunk_id: string, dossier_id: ?string, blog_post_id: ?string}>  $cited
     * @param  list<array<string, mixed>>  $sources
     */
    private function bubbleCiting(array $cited, array $sources, ?Loop $loop = null): LoopMessage
    {
        $loop ??= $this->loop;

        $interaction = AiInteraction::create([
            'user_id' => $this->alice->id,
            'organization_id' => $this->organization->id,
            'process' => 'chatloop',
            'feature' => 'loop_knowledge_answer',
            'model' => 'test-model',
            'prompt' => 'prompt interne',
            'response' => 'reponse',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'metadata' => [
                'loop_id' => (string) $loop->id,
                'requested_by' => $this->alice->id,
                'capability' => 'loop_knowledge_answer',
                'retrieval' => ['consulted' => $cited, 'cited' => $cited],
            ],
        ]);

        return LoopMessage::create([
            'organization_id' => (string) $loop->organization_id,
            'loop_id' => $loop->id,
            'sender_id' => null,
            'body' => 'Reponse IA fondee sur la memoire.',
            'type' => 'ai',
            'metadata' => [
                'ai_mode' => 'rag',
                'requested_by' => $this->alice->id,
                'sources' => $sources,
                'ai_interaction_id' => $interaction->id,
            ],
        ]);
    }

    /**
     * Un chunk de DOCUMENT ordinaire : aucune FK de note derivee, donc le
     * discriminateur positif le laisse dans la voie documentaire.
     */
    private function unChunkDocumentaire(string $dossierId, DossierFile $file, string $contenu): DossierChunk
    {
        return DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossierId,
            'dossier_file_id' => $file->id,
            'chunk_index' => DossierChunk::query()->where('dossier_id', $dossierId)->count(),
            'content' => $contenu,
            'content_hash' => hash('sha256', $contenu),
            'embedding' => array_fill(0, 1536, 0.01),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    /**
     * @return array{chunk_id: string, dossier_id: ?string, blog_post_id: ?string}
     */
    private function cited(DossierChunk $chunk): array
    {
        return [
            'chunk_id' => (string) $chunk->id,
            'dossier_id' => $chunk->dossier_id === null ? null : (string) $chunk->dossier_id,
            'blog_post_id' => $chunk->blog_post_id === null ? null : (string) $chunk->blog_post_id,
        ];
    }

    /**
     * La forme publique de `KnowledgeAnswer::publicSource()` : `type =
     * 'retrieval'` pour un document COMME pour une memoire durable — c'est
     * precisement pourquoi le discriminateur n'a pas le droit de la lire.
     *
     * @return array<string, mixed>
     */
    private function publicSource(string $ref, ?string $title): array
    {
        return [
            'ref' => $ref,
            'title' => $title,
            'dossier_name' => null,
            'excerpt' => null,
            'url' => null,
            'type' => 'retrieval',
        ];
    }

    private function panel(LoopMessage $message, User $viewer): array
    {
        $panel = app(AiResponseExplanationService::class)
            ->explain($message->loop, $message, $viewer);

        $this->assertNotNull($panel, 'PREMISSE : le panneau doit etre visible pour ce spectateur');

        return $panel;
    }
}
