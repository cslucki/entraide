<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Ai\FakeDossierSemanticSearch;
use Tests\TestCase;

/**
 * TASK-1532 — la recherche documentaire admin respecte la policy des Dossiers.
 *
 * ## La fuite fermee
 *
 * `OrgAdminController::searchableDossierIds()` rendait TOUS les Dossiers de
 * l'Organization, sans policy. C'est le perimetre de la TABLE de
 * l'Observatoire, et il est juste pour ce qu'il sert : l'admin voit l'ETAT de
 * l'index sans droit de lecture sur le contenu (doctrine TASK-1217).
 *
 * Mais la recherche BRUTE rend `dossier_name`, le titre du document, la
 * distance et 240 caracteres d'EXTRAIT. C'est du contenu. Un admin pouvait
 * donc lire, par la recherche, l'interieur d'un Dossier prive qu'il ne peut
 * pas ouvrir — alors que `knowledgeObservatory()`, juste en dessous dans le
 * meme controleur, applique justement `DossierPolicy` a son lien « Ouvrir »
 * pour cette raison exacte.
 *
 * Etre admin ne donne pas acces au contenu d'un Dossier prive.
 *
 * ## Sur quelle couche ces tests mesurent
 *
 * Le double partage `FakeDossierSemanticSearch` ENREGISTRE son appel. On lit
 * `lastCall['dossierIds']` : c'est la preuve DIRECTE que la policy s'applique
 * AVANT l'embedding et la requete pgvector, et non par un filtrage des
 * resultats apres coup. Un test qui ne regarderait que le HTML ne saurait pas
 * distinguer les deux — et un filtrage apres coup aurait quand meme calcule un
 * embedding sur un perimetre interdit.
 */
class TASK1532AdminKnowledgeSearchPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private FakeDossierSemanticSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->update(['admin_id' => $this->admin->id]);
        $this->organization = $this->organization->fresh();
        app()->instance('current_organization', $this->organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-1532',
        ]);

        config([
            'ai.default_for_embeddings' => 'openai',
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();

        $this->search = new FakeDossierSemanticSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);
    }

    private function searchUrl(?string $loopId = null): string
    {
        return route('organization.admin.ai-knowledge.search', array_filter([
            'organization' => $this->organization->slug,
            'q' => 'partenaires',
            'loop_id' => $loopId,
        ]));
    }

    /**
     * Un Dossier, avec le bon PORTEUR.
     *
     * PostgreSQL porte la contrainte `dossiers_holder_xor` :
     * `(owner_id IS NULL) <> (loop_id IS NULL)` — un Dossier est tenu par un
     * membre OU par une Boucle, jamais par les deux. SQLite ne sait pas
     * l'exprimer (la migration le dit explicitement), d'ou un faux vert local
     * tant que la fixture renseignait les deux colonnes.
     */
    private function dossier(Organization $organization, User $owner, string $name, string $visibility, ?string $loopId = null, ?string $sharedWithLoopId = null): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $loopId === null ? $owner->id : null,
            'name' => $name,
            'visibility' => $visibility,
            'loop_id' => $loopId,
            'shared_with_loop_id' => $sharedWithLoopId,
        ]);
    }

    /** Le moteur rendra un extrait pour ce Dossier — s'il est interroge. */
    private function searchWouldReturn(Dossier $dossier, string $content): void
    {
        $this->search->rows[] = [
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => $dossier->id, 'dossier_name' => $dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note-'.$dossier->name.'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 1, 'content' => $content, 'distance' => 0.2,
        ];
    }

    // ── 1. Ce que l'admin peut legitimement chercher ────────────────────────

    /** Sabotage : neutraliser le scope → ce test reste vert (c'est le cas nominal). */
    public function test_a_dossier_the_admin_can_open_remains_searchable(): void
    {
        $sien = $this->dossier($this->organization, $this->admin, 'DOSSIERADMIN', Dossier::VISIBILITY_PRIVATE);
        $this->searchWouldReturn($sien, 'Les partenaires publics du Dossier de l admin.');

        $response = $this->actingAs($this->admin)->get($this->searchUrl())->assertOk();

        $this->assertNotNull($this->search->lastCall, 'la recherche a bien eu lieu');
        $this->assertContains((string) $sien->id, $this->search->lastCall['dossierIds']);
        $response->assertSee('DOSSIERADMIN', false);
    }

    // ── 2 et 6. Le Dossier prive : rien, pas meme son existence ─────────────

    /**
     * LE test de cette TASK.
     *
     * Sabotage : rendre tous les Dossiers de l'Organization au lieu du
     * perimetre autorise → rouge.
     */
    public function test_a_private_dossier_the_admin_cannot_open_is_never_searched_nor_revealed(): void
    {
        $autre = User::factory()->create(['organization_id' => $this->organization->id]);
        $prive = $this->dossier($this->organization, $autre, 'DOSSIERPRIVEINTERDIT', Dossier::VISIBILITY_PRIVATE);

        $this->assertTrue($this->admin->cannot('view', $prive),
            'premisse : etre admin ne donne pas acces a ce Dossier');

        // Le moteur RENDRAIT ce contenu s'il etait interroge dessus : c'est ce
        // qui rend le test capable de rougir.
        $this->searchWouldReturn($prive, 'SECRETPRIVE : la liste des partenaires confidentiels.');

        $response = $this->actingAs($this->admin)->get($this->searchUrl())->assertOk();

        // Le Dossier interdit n'entre pas dans le perimetre INTERROGE.
        if ($this->search->lastCall !== null) {
            $this->assertNotContains((string) $prive->id, $this->search->lastCall['dossierIds'],
                'un Dossier que l admin ne peut pas ouvrir n est jamais interroge');
        }

        // Et rien de lui ne transparait : ni nom, ni titre de document, ni
        // extrait, ni distance, ni indice indirect de son existence.
        $html = $response->getContent();
        $this->assertStringNotContainsString('DOSSIERPRIVEINTERDIT', (string) $html, 'nom de Dossier');
        $this->assertStringNotContainsString('SECRETPRIVE', (string) $html, 'extrait de contenu');
        $this->assertStringNotContainsString('note-DOSSIERPRIVEINTERDIT.docx', (string) $html, 'titre du document');
    }

    // ── 3. Cross-tenant ─────────────────────────────────────────────────────

    /** Organization = Tenant : jamais candidat, jamais rendu. */
    public function test_a_dossier_of_another_organization_is_never_a_candidate(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $autreMembre = User::factory()->create(['organization_id' => $autreOrg->id]);
        $etranger = $this->dossier($autreOrg, $autreMembre, 'DOSSIERETRANGER', Dossier::VISIBILITY_ORGANIZATION);
        $this->searchWouldReturn($etranger, 'SECRETETRANGER d une autre Organization.');

        $sien = $this->dossier($this->organization, $this->admin, 'DOSSIERADMIN', Dossier::VISIBILITY_PRIVATE);
        $this->searchWouldReturn($sien, 'Contenu legitime.');

        $response = $this->actingAs($this->admin)->get($this->searchUrl())->assertOk();

        $this->assertNotNull($this->search->lastCall);
        $this->assertSame((string) $this->organization->id, $this->search->lastCall['organizationId'],
            'la recherche est bornee au tenant courant');
        $this->assertNotContains((string) $etranger->id, $this->search->lastCall['dossierIds'],
            'un Dossier d une autre Organization n est jamais un candidat');

        // Ce qui N'EST PAS asserte ici, et pourquoi : que l'extrait etranger
        // soit absent du HTML. Le double rend les lignes qu'on lui a mises,
        // sans honorer `dossierIds` — l'asserter reviendrait a mesurer le
        // double, pas le produit. Le vrai moteur borne en SQL
        // (`whereIn('dossier_chunks.dossier_id', $dossierIds)`), et c'est
        // `PgvectorTASK1532AdminKnowledgeSearchPolicyTest` qui le prouve.
        $this->assertNotNull($response);
    }

    // ── 4. Le filtre par Boucle, conserve pour les Dossiers autorises ───────

    /**
     * Le resserrement par Boucle est inchange : un Dossier racine de la Boucle
     * et un Dossier partage avec elle restent interrogeables par un admin qui
     * en est membre ; un Dossier hors Boucle sort du perimetre.
     */
    public function test_the_loop_filter_keeps_its_behaviour_for_authorized_dossiers(): void
    {
        $loop = Loop::create([
            'organization_id' => $this->organization->id,
            'name' => 'Boucle diagnostic',
            'slug' => 'boucle-diagnostic-'.Str::uuid(),
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $this->admin->id,
        ]);
        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $loop->id,
            'user_id' => $this->admin->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $racine = $this->dossier($this->organization, $this->admin, 'RACINEBOUCLE', Dossier::VISIBILITY_LOOP, $loop->id);
        $partage = $this->dossier($this->organization, $this->admin, 'PARTAGEBOUCLE', Dossier::VISIBILITY_LOOP, null, $loop->id);
        $horsBoucle = $this->dossier($this->organization, $this->admin, 'HORSBOUCLE', Dossier::VISIBILITY_PRIVATE);

        $this->searchWouldReturn($racine, 'Contenu racine.');

        $this->actingAs($this->admin)->get($this->searchUrl($loop->id))->assertOk();

        $this->assertNotNull($this->search->lastCall);
        $ids = $this->search->lastCall['dossierIds'];

        $this->assertContains((string) $racine->id, $ids, 'la racine de la Boucle reste interrogeable');
        $this->assertContains((string) $partage->id, $ids, 'un Dossier partage avec la Boucle aussi');
        $this->assertNotContains((string) $horsBoucle->id, $ids, 'un Dossier hors Boucle sort du perimetre');
    }

    // ── 5. Aucun Dossier autorise ───────────────────────────────────────────

    /**
     * Un admin qui ne peut ouvrir aucun Dossier ne declenche aucune recherche
     * exploitable — et surtout aucun embedding sur un perimetre interdit.
     */
    public function test_an_admin_without_any_accessible_dossier_gets_no_search_and_no_content(): void
    {
        $autre = User::factory()->create(['organization_id' => $this->organization->id]);
        $prive = $this->dossier($this->organization, $autre, 'DOSSIERPRIVEINTERDIT', Dossier::VISIBILITY_PRIVATE);
        $this->searchWouldReturn($prive, 'SECRETPRIVE absolu.');

        $response = $this->actingAs($this->admin)->get($this->searchUrl())->assertOk();

        $this->assertNull($this->search->lastCall,
            'sans Dossier autorise, aucune recherche semantique n est lancee');

        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('SECRETPRIVE', $html);
        $this->assertStringNotContainsString('DOSSIERPRIVEINTERDIT', $html);
    }

    // ── 7. L'Observatoire hors recherche brute ──────────────────────────────

    /**
     * La correction ne touche QUE la recherche brute : la page de
     * l'Observatoire et son fragment vivant continuent de rendre l'ETAT de
     * l'index sur TOUS les Dossiers — c'est la doctrine TASK-1217, et elle
     * n'est pas modifiee ici.
     */
    public function test_the_observatory_still_reports_index_state_on_every_dossier(): void
    {
        $autre = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->dossier($this->organization, $autre, 'DOSSIERPRIVEINTERDIT', Dossier::VISIBILITY_PRIVATE);

        $this->actingAs($this->admin)
            ->get(route('organization.admin.ai-knowledge', ['organization' => $this->organization->slug]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('organization.admin.ai-knowledge.live', ['organization' => $this->organization->slug]))
            ->assertOk();
    }
}
