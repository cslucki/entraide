<?php

namespace Tests\Feature;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\ContexteBorne;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\Context\SourceDenied;
use App\Ai\ContexteIa;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1294 — RAG loop-scoped : une question posee DEPUIS une Boucle ne
 * cherche que dans les Dossiers de CETTE Boucle.
 *
 * « Appartenir a une Boucle » a TROIS formes, et le perimetre doit couvrir
 * les trois (doctrine T1130, meme lecture que DossierPolicy::view) :
 *   1. le Dossier racine de la Boucle (dossiers.loop_id) ;
 *   2. un Dossier PARTAGE avec la Boucle (shared_with_loop_id + visibility
 *      loop) ;
 *   3. les ENFANTS de l'un ou l'autre — un enfant ne porte ni loop_id ni
 *      shared_with_loop_id : sa gouvernance vient de governingDossier().
 * Le dataset de dogfooding ne contient que la forme 1 : les formes 2 et 3
 * sont construites ici explicitement, sinon un correctif naif
 * where('loop_id') passerait au vert en etant faux.
 *
 * Sans loopId dans le contexte, le comportement historique (Organization +
 * accessibilite) est conserve — teste EXPLICITEMENT ci-dessous.
 *
 * Le scoping se decide dans accessibleDossierIds() : on l'observe au niveau
 * le plus bas utile, le perimetre exact transmis a searchAcrossDossiers(),
 * via un double du moteur — aucun provider reel, aucune generation.
 */
class TASK1294LoopScopedRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $otherMember;

    private Loop $loopA;

    private Loop $loopB;

    private Dossier $rootA;

    private Dossier $rootB;

    private Task1294FakeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherMember = User::factory()->create(['organization_id' => $this->organization->id]);

        // Le cas reproduit : un membre ACTIF de plusieurs Boucles.
        $service = new LoopService;
        $this->loopA = $service->createLoop($this->member, 'Boucle A');
        $this->loopB = $service->createLoop($this->member, 'Boucle B');

        // Chaque Boucle nait avec son Dossier racine (TASK-1082) : forme 1.
        $this->rootA = Dossier::query()->where('loop_id', $this->loopA->id)->firstOrFail();
        $this->rootB = Dossier::query()->where('loop_id', $this->loopB->id)->firstOrFail();

        app()->instance('current_organization', $this->organization);

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
        ]);

        $this->search = new Task1294FakeSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // Le defaut : depuis A, la Boucle B est aujourd'hui eligible
    // =====================================================================

    public function test_from_loop_a_the_dossiers_of_loop_b_are_excluded_from_the_perimeter(): void
    {
        $this->build($this->contexte($this->loopA->id));

        $this->assertNotNull($this->search->lastCall);
        $this->assertContains($this->rootA->id, $this->search->lastCall['dossierIds']);
        $this->assertNotContains(
            $this->rootB->id,
            $this->search->lastCall['dossierIds'],
            'Une question posee DEPUIS la Boucle A ne doit jamais chercher dans les Dossiers de la Boucle B.',
        );
    }

    // =====================================================================
    // Les TROIS formes d'appartenance a la Boucle (piege du §3)
    // =====================================================================

    public function test_the_three_forms_of_loop_membership_are_all_in_the_perimeter(): void
    {
        // Forme 2 : un Dossier racine d'un autre membre, PARTAGE avec A.
        $sharedWithA = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Partage avec A',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loopA->id,
        ]);

        // Forme 3 : des ENFANTS — ni loop_id ni shared_with_loop_id, la
        // gouvernance se demande a governingDossier().
        $childOfRootA = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->rootA->id,
            'name' => 'Enfant du Dossier de A',
        ]);
        $childOfShared = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $sharedWithA->id,
            'name' => 'Enfant du partage avec A',
        ]);

        // Contre-formes : les memes liaisons, mais vers B.
        $sharedWithB = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Partage avec B',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loopB->id,
        ]);
        $childOfRootB = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->rootB->id,
            'name' => 'Enfant du Dossier de B',
        ]);

        $this->build($this->contexte($this->loopA->id));

        $ids = $this->search->lastCall['dossierIds'];
        $this->assertContains($this->rootA->id, $ids, 'forme 1 : le Dossier racine de la Boucle');
        $this->assertContains($sharedWithA->id, $ids, 'forme 2 : un Dossier partage avec la Boucle');
        $this->assertContains($childOfRootA->id, $ids, 'forme 3 : un enfant du Dossier racine');
        $this->assertContains($childOfShared->id, $ids, 'forme 3 : un enfant d\'un Dossier partage');

        $this->assertNotContains($this->rootB->id, $ids);
        $this->assertNotContains($sharedWithB->id, $ids);
        $this->assertNotContains($childOfRootB->id, $ids);
    }

    public function test_a_dossier_whose_sharing_was_revoked_is_no_longer_in_the_loop_perimeter(): void
    {
        // shared_with_loop_id encore present mais visibility revenue a
        // private : la meme lecture que DossierPolicy::view — le partage
        // exige LES DEUX, une colonne residuelle ne suffit pas.
        $revoked = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Partage revoque',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
            'shared_with_loop_id' => $this->loopA->id,
        ]);

        $this->build($this->contexte($this->loopA->id));

        $this->assertNotContains($revoked->id, $this->search->lastCall['dossierIds']);
    }

    // =====================================================================
    // La policy et le tenant restent appliques dans le perimetre Boucle
    // =====================================================================

    public function test_the_view_policy_still_applies_inside_the_loop_perimeter(): void
    {
        // Meme Organization, mais AUCUNE appartenance a la Boucle A : les
        // Dossiers de A sont dans le perimetre de la Boucle, la policy les
        // refuse quand meme -> la source est refusee, la recherche jamais
        // lancee.
        $outsider = User::factory()->create(['organization_id' => $this->organization->id]);

        $borne = $this->build($this->contexte($this->loopA->id, $outsider->id));

        $this->assertSame(
            DossierRetrievalSource::REASON_NO_ACCESSIBLE_DOSSIER,
            $borne->sourcesDenied[DossierRetrievalSource::NAME],
        );
        $this->assertNull($this->search->lastCall);
    }

    public function test_a_loop_of_another_organization_is_denied(): void
    {
        $strangerOwner = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $foreignLoop = (new LoopService)->createLoop($strangerOwner, 'Boucle etrangere');

        $borne = $this->build($this->contexte($foreignLoop->id));

        $this->assertSame(
            SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION,
            $borne->sourcesDenied[DossierRetrievalSource::NAME],
        );
        $this->assertNull($this->search->lastCall);
    }

    public function test_another_organization_is_never_in_the_perimeter_even_from_a_loop(): void
    {
        $strangerOwner = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $foreignDossier = Dossier::create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => $strangerOwner->id,
            'name' => 'Dossier etranger',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);

        $this->build($this->contexte($this->loopA->id));

        $this->assertSame($this->organization->id, $this->search->lastCall['organizationId']);
        $this->assertNotContains($foreignDossier->id, $this->search->lastCall['dossierIds']);
    }

    public function test_a_recent_loop_is_not_silenced_by_the_organization_wide_candidate_cap(): void
    {
        // Revue COWORK : le cap MAX_CANDIDATE_DOSSIERS (200) se posait sur
        // toute l'Organization, trie par created_at, AVANT le filtre Boucle —
        // une Boucle plus recente que les 200 premiers Dossiers rendait un
        // perimetre VIDE (no_accessible_dossier) alors qu'elle a des
        // documents. Le dataset (13 Dossiers) ne peut pas le reveler : on
        // construit l'Organization saturee, et la restriction Boucle doit
        // s'appliquer AVANT le cap.
        $fillers = Dossier::factory()->count(210)->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);
        // Les remplisseurs sont plus ANCIENS que tout le reste : a eux seuls
        // ils consomment le cap trie par created_at (created_at non fillable
        // -> query builder).
        Dossier::query()->whereIn('id', $fillers->pluck('id'))->update(['created_at' => now()->subDays(2)]);

        // Les trois formes de la Boucle A, toutes au-dela du cap historique.
        $sharedWithA = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Partage recent avec A',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loopA->id,
        ]);
        $childOfRootA = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->rootA->id,
            'name' => 'Enfant recent du Dossier de A',
        ]);

        $this->build($this->contexte($this->loopA->id));

        $this->assertNotNull(
            $this->search->lastCall,
            'Le perimetre d\'une Boucle ne doit jamais etre annule par le cap pose sur toute l\'Organization.',
        );
        $ids = $this->search->lastCall['dossierIds'];
        $this->assertContains($this->rootA->id, $ids, 'forme 1 malgre le cap');
        $this->assertContains($sharedWithA->id, $ids, 'forme 2 malgre le cap');
        $this->assertContains($childOfRootA->id, $ids, 'forme 3 malgre le cap');
        $this->assertNotContains($this->rootB->id, $ids);
    }

    // =====================================================================
    // SANS loopId : le comportement historique, conserve EXPLICITEMENT
    // =====================================================================

    public function test_without_a_loop_the_historic_organization_perimeter_is_unchanged(): void
    {
        $organizationWide = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Visible de toute l\'Organization',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);
        $ownPrivate = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Prive du demandeur',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $othersPrivate = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Prive d\'un autre membre',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->build($this->contexte(null));

        $ids = $this->search->lastCall['dossierIds'];
        $this->assertContains($organizationWide->id, $ids);
        $this->assertContains($ownPrivate->id, $ids);
        $this->assertContains($this->rootA->id, $ids);
        $this->assertContains($this->rootB->id, $ids);
        $this->assertNotContains($othersPrivate->id, $ids);
    }

    // =====================================================================
    // Provenance : les citations restent justes sous le perimetre Boucle
    // =====================================================================

    public function test_provenance_stays_correct_under_the_loop_scope(): void
    {
        $this->search->rows = [[
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->rootA->id,
            'dossier_name' => $this->rootA->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article de la Boucle A',
            'slug' => 'article-boucle-a',
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'content' => 'Contenu documentaire de la Boucle A.',
            'distance' => 0.2,
        ]];

        $borne = $this->build($this->contexte($this->loopA->id));

        $this->assertSame([DossierRetrievalSource::NAME], $borne->sourcesUsed);
        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);
        $this->assertCount(1, $provenance);
        $this->assertSame('S1', $provenance[0]['ref']);
        $this->assertSame($this->rootA->id, $provenance[0]['dossier_id']);
        $this->assertSame('Article de la Boucle A', $provenance[0]['title']);
        $this->assertStringContainsString('[S1] Article de la Boucle A', $borne->text);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function contexte(?string $loopId, ?string $userId = null): ContexteIa
    {
        return new ContexteIa(
            organizationId: $this->organization->id,
            userId: $userId ?? $this->member->id,
            loopId: $loopId,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'Que contiennent les documents ?',
        );
    }

    private function build(ContexteIa $contexte): ContexteBorne
    {
        return app(ContextBuilder::class)->build(
            $contexte,
            app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER),
        );
    }
}

/**
 * Double du moteur pgvector : enregistre le perimetre exact demande — c'est
 * LUI qu'on teste ici — et rend des lignes canoniques.
 */
class Task1294FakeSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = []): array
    {
        $this->lastCall = compact('organizationId', 'dossierIds', 'query', 'embeddingInstance', 'limit', 'traceMetadata');

        return array_slice($this->rows, 0, $limit);
    }
}
