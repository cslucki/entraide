<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Support\Ai\FakeDossierSemanticSearch;
use Tests\TestCase;

/**
 * TASK-1391 — provenance honnete.
 *
 * ## La promesse, en une phrase
 *
 * > Une source annoncee comme utilisee a reellement ete recuperee, et elle
 * > s'ouvre correctement.
 *
 * Les deux moities comptent. Une provenance exacte qui renvoie vers un fichier
 * que le navigateur telecharge au lieu de l'afficher ne vaut guere mieux
 * qu'une provenance fausse : dans les deux cas le membre ne peut pas
 * VERIFIER.
 *
 * ## Les quatre ecarts mesures, et leur sens
 *
 * Chacun casse la promesse dans une direction differente :
 *
 * 1. **citation groupee** — le modele cite correctement `[S1, S2]`, le
 *    parseur litteral ne reconnait rien, et deux sources reellement lues
 *    disparaissent de « Sources utilisees ».
 * 2. **citation en lien markdown** — le modele ecrit `[S1](fichier.pdf)`, le
 *    sanitizer detruit les crochets AVANT que les citations soient lues, et
 *    la source disparait de la meme facon.
 * 3. **reference inventee** — le modele ecrit `[S9]` qui n'existe pas ; le
 *    texte le garde et le membre lit une citation qui ne pointe nulle part.
 * 4. **manifeste presente comme source lue** — une entree qui LISTE un
 *    document sans en lire une ligne s'affiche sous « Sources utilisees »,
 *    avec un lien « Ouvrir », comme si elle avait soutenu la reponse.
 *
 * Les trois premiers font mentir la promesse par DEFAUT (une source lue n'est
 * pas annoncee), le quatrieme par EXCES (une source annoncee n'a rien
 * fourni). Il faut les deux sens pour que la phrase soit vraie.
 */
class TASK1391HonestRagProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private User $otherMember;

    private Loop $loop;

    private Dossier $dossier;

    private FakeDossierSemanticSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle provenance');

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Dossier partagé',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        $this->search = new FakeDossierSemanticSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // 1. Une source lue et citee est annoncee — quelle que soit la forme
    // =====================================================================

    /**
     * Une citation GROUPEE compte pour chacune de ses references.
     *
     * `[S1, S2]` est une forme naturelle et frequente : le modele l'ecrit des
     * qu'une affirmation s'appuie sur deux extraits. Le parseur litteral
     * cherchait la chaine `[S1]` exacte — donc ni S1 ni S2 n'etaient reconnus,
     * et DEUX sources reellement lues sortaient de « Sources utilisees ».
     *
     * Le rendre reconnaissable n'est pas un confort d'affichage : sans lui,
     * `grounded` passe a false et la reponse la mieux etayee est celle qui
     * paraissait la moins fondee.
     */
    public function test_a_grouped_citation_counts_for_each_of_its_references(): void
    {
        $this->search->rows = [$this->row('A'), $this->row('B')];
        $this->fakeAgent('Une installation itinérante tient dans une valise [S1, S2].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que doit contenir une installation ?');

        $this->assertTrue($answer->grounded);
        $this->assertSame(['S1', 'S2'], array_column($answer->sources, 'ref'));
    }

    /**
     * Les autres formes groupees comptent aussi.
     *
     * Sans espace, avec « et », avec un point-virgule : le modele n'a aucune
     * raison de s'en tenir a une seule ponctuation, et aucun prompt ne la lui
     * impose.
     */
    public function test_other_grouped_forms_count_too(): void
    {
        $this->search->rows = [$this->row('A'), $this->row('B')];
        $this->fakeAgent('Premier point [S1,S2]. Second point [S1 et S2].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');

        $this->assertSame(['S1', 'S2'], array_column($answer->sources, 'ref'));
    }

    /**
     * Une citation ecrite en LIEN markdown est reconnue.
     *
     * Habitude tres courante d'un modele : `[S1](fichier.pdf)`. Le sanitizer
     * neutralise les URL non http(s) en ne gardant que le libelle — donc
     * `[S1](fichier.pdf)` devenait `S1`, sans crochets, AVANT que les
     * citations soient lues. Une source reellement recuperee ET reellement
     * citee basculait alors en « consultee ».
     *
     * C'est le seul des quatre ecarts qu'aucun audit de prompt n'aurait pu
     * trouver : il est produit par notre propre chaine de traitement.
     */
    public function test_a_citation_written_as_a_markdown_link_is_recognised(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Fondé sur [S1](dossiers/12/fichier.pdf).');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');

        $this->assertTrue($answer->grounded);
        $this->assertSame(['S1'], array_column($answer->sources, 'ref'));
    }

    // =====================================================================
    // 2. Une source annoncee a reellement fourni quelque chose
    // =====================================================================

    /**
     * Une reference inventee n'est ni annoncee, ni laissee dans le texte.
     *
     * Qu'elle ne devienne pas une source etait deja acquis. Qu'elle
     * DISPARAISSE du texte publie ne l'etait pas : le membre lisait `[S9]`
     * sans aucun S9 dans le bloc de provenance — une citation qui ne pointe
     * nulle part, ce qui est exactement ce que la promesse interdit.
     */
    public function test_an_invented_reference_is_neither_announced_nor_left_in_the_text(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Réponse appuyée sur [S1] et sur une source inexistante [S9].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');

        $this->assertSame(['S1'], array_column($answer->sources, 'ref'));
        $this->assertStringContainsString('[S1]', $answer->answer);
        $this->assertStringNotContainsString('[S9]', $answer->answer);
    }

    /**
     * Le manifeste est IDENTIFIABLE comme non lu.
     *
     * ## Le correctif que je n'ai PAS ecrit, et pourquoi
     *
     * Ma premiere version retirait le manifeste des sources utilisees et
     * passait `grounded` a false. Elle a fait rougir quatre tests de T1213 et
     * T1309 — et ces tests avaient raison. Pour une question d'INVENTAIRE
     * (« quels fichiers y a-t-il ? »), le manifeste EST la bonne source : le
     * declasser aurait presente comme non fondee une reponse parfaitement
     * fondee. J'aurais deplace le mensonge, pas retire.
     *
     * Le defaut reel etait ailleurs, et plus etroit :
     * `KnowledgeAnswer::publicSource()` jetait la cle `type`, donc plus rien en
     * aval ne pouvait distinguer un document LU d'un document seulement
     * REPERTORIE. Les deux s'affichaient a l'identique, avec un lien
     * « Ouvrir ». Le seul indice etait le prefixe `M` de la reference —
     * lisible par un humain averti, jamais par le code.
     *
     * La correction transporte donc le `type`, et la bulle porte un marqueur.
     * Aucune semantique n'est touchee.
     */
    public function test_a_manifest_source_is_identifiable_as_not_read(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Le Dossier contient plusieurs documents [M1], dont celui-ci [S1].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');

        $parRef = [];

        foreach ($answer->sources as $source) {
            $parRef[$source['ref']] = KnowledgeAnswer::publicSource($source);
        }

        $this->assertSame('manifest', $parRef['M1']['type'] ?? null, 'Le manifeste doit rester reconnaissable en aval.');
        $this->assertNull($parRef['M1']['excerpt'], 'Le manifeste ne lit aucun contenu.');
        $this->assertNotSame('manifest', $parRef['S1']['type'] ?? null);
        $this->assertNotNull($parRef['S1']['excerpt'], 'Un extrait de retrieval porte du contenu lu.');
    }

    /**
     * La bulle affiche le marqueur « répertorié, non lu ».
     *
     * Le `type` transporte ne sert a rien si la vue l'ignore : la mesure porte
     * sur le RENDU, pas sur la donnee. Sans elle, la correction serait un
     * no-op silencieux — la cle voyagerait jusqu'a une vue qui ne la lit pas.
     */
    public function test_the_bubble_marks_a_manifest_source_as_listed_only(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Le Dossier contient plusieurs documents [M1], dont celui-ci [S1].');

        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');

        $rendu = $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->slug]))
            ->getContent();

        $this->assertStringContainsString('data-source-listed-only', $rendu);
        $this->assertStringContainsString(__('loops.knowledge_source_listed_only'), $rendu);
    }

    // =====================================================================
    // 3. « et elle s'ouvre correctement »
    // =====================================================================

    /**
     * Un fichier Markdown s'ouvre DANS le navigateur.
     *
     * La seconde moitie de la promesse. Le serveur posait bien
     * `Content-Disposition: inline`, mais avec `Content-Type: text/markdown`
     * — un type qu'aucun navigateur ne sait rendre, et qu'ils traitent donc
     * en telechargement malgre l'en-tete.
     *
     * Ce n'est pas theorique : le corpus RAG de la demonstration ArtSciLab
     * compte **16 fichiers, dont 12 en `text/markdown`**. Trois sources
     * verifiables sur quatre se seraient telechargees au lieu de s'ouvrir.
     */
    public function test_a_markdown_source_opens_in_the_browser(): void
    {
        Storage::fake('dossier_files');

        $fichier = $this->attachFile('note-de-cadrage.md', 'text/markdown');
        Storage::disk('dossier_files')->put($fichier->path, '# Note de cadrage');

        $reponse = $this->actingAs($this->member)
            ->get(route('organization.dossiers.files.preview', ['organization' => $this->organization->slug, 'dossier' => $this->dossier->id, 'file' => $fichier->id]));

        $reponse->assertOk();
        $reponse->assertHeader('content-disposition', 'inline; filename="note-de-cadrage.md"');
        $this->assertStringStartsWith(
            'text/plain',
            $reponse->headers->get('content-type'),
            'Aucun navigateur ne rend text/markdown : servi tel quel, le fichier se telecharge malgre inline.',
        );
    }

    /**
     * Le type d'origine des AUTRES fichiers n'est pas touche.
     *
     * Le contre-exemple. Un correctif qui forcerait `text/plain` partout
     * casserait l'apercu des images et des PDF — ceux-la, les navigateurs
     * savent les rendre, et c'est leur vrai type qui le permet.
     */
    public function test_other_file_types_keep_their_own_content_type(): void
    {
        Storage::fake('dossier_files');

        $fichier = $this->attachFile('schema.pdf', 'application/pdf');
        Storage::disk('dossier_files')->put($fichier->path, '%PDF-1.4');

        $reponse = $this->actingAs($this->member)
            ->get(route('organization.dossiers.files.preview', ['organization' => $this->organization->slug, 'dossier' => $this->dossier->id, 'file' => $fichier->id]));

        $reponse->assertOk();
        $this->assertStringStartsWith('application/pdf', $reponse->headers->get('content-type'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function row(string $label, float $distance = 0.2): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article '.$label,
            'slug' => 'article-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'content' => "Contenu de l'article {$label} : une installation itinérante tient dans une valise.",
            'distance' => $distance,
        ];
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function attachFile(string $name, string $mimeType): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->member->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$this->dossier->id.'/'.Str::uuid().'-'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mimeType,
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $name),
            'source' => 'upload',
        ]);
    }

    /**
     * La metadata de la derniere bulle IA publiee dans le fil.
     *
     * C'est ce que la vue lit reellement : mesurer le DTO seul laisserait
     * passer une divergence entre ce que le service rend a l'appelant et ce
     * qu'il ecrit dans le fil.
     *
     * @return array<string, mixed>
     */
    private function derniereBulleIa(): array
    {
        return LoopMessage::query()
            ->where('loop_id', $this->loop->id)
            ->where('type', 'ai')
            ->latest('id')
            ->firstOrFail()
            ->metadata ?? [];
    }
}
