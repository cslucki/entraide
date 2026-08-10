<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopManifestoService;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un Manifeste ne pour une Boucle est lie a cette Boucle, et ses sources
 * viennent du Dossier partage — jamais des Dossiers prives.
 *
 * Deux incoherences reelles : le document racine etait attache au Dossier mais
 * pas a la Boucle (section « Boucle » vide dans l'editeur), et le selecteur
 * « Sources et documents associes » offrait tous les fichiers de
 * l'Organization, Dossiers prives compris.
 */
class TASK1121ManifestoCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $proprietaire;

    private Loop $boucle;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);
        $this->orgB = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->orgA->id]);

        app()->instance('current_organization', $this->orgA);

        $this->boucle = (new LoopService)->createLoop($this->proprietaire, 'Boucle Manifeste QA')->fresh();
        $this->racine = Dossier::where('loop_id', $this->boucle->id)->firstOrFail();
    }

    private function fichier(Dossier $dossier, string $nom): DossierFile
    {
        return DossierFile::factory()->create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'original_name' => $nom,
            'display_name' => $nom,
        ]);
    }

    private function dossierPrive(): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Dossier prive QA',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    // ── Le Manifeste est lie a sa Boucle ────────────────────────────────────

    public function test_a_new_loop_root_document_is_linked_to_the_loop(): void
    {
        // La creation vient de passer par LoopService : le document racine
        // porte la ligne blog_post_loop — la section « Boucle » de l'editeur
        // n'est plus vide.
        $manifeste = $this->racine->rootBlogPost;

        $this->assertNotNull($manifeste);
        $this->assertTrue($manifeste->loops()->whereKey($this->boucle->id)->exists());
    }

    public function test_an_old_root_document_is_healed_on_the_next_visit(): void
    {
        $manifeste = $this->racine->rootBlogPost;

        // L'etat d'avant TASK-1121 : le lien n'existe pas. 18 des 19 documents
        // racines du parc de dev sont dans ce cas.
        $manifeste->loops()->detach($this->boucle->id);
        $this->assertFalse($manifeste->loops()->whereKey($this->boucle->id)->exists());

        // Le premier passage par le service — ce que fait la Card Manifeste a
        // l'ouverture — repare, sans backfill massif.
        app(LoopRootDocumentService::class)->ensureRootDocument($this->boucle);

        $this->assertTrue($manifeste->fresh()->loops()->whereKey($this->boucle->id)->exists());
    }

    // ── Les sources viennent du Dossier partage, et de lui seul ─────────────

    public function test_the_picker_offers_only_the_root_dossier_files(): void
    {
        $partage = $this->fichier($this->racine, 'reference-partagee.pdf');
        $prive = $this->fichier($this->dossierPrive(), 'note-privee.pdf');

        $candidats = app(LoopManifestoService::class)->candidateFiles($this->boucle)->pluck('id');

        $this->assertTrue($candidats->contains($partage->id));
        $this->assertFalse($candidats->contains($prive->id), 'Un fichier d\'un Dossier prive ne doit jamais etre propose comme source.');
    }

    public function test_a_forged_private_file_id_is_refused_at_attach(): void
    {
        // Le selecteur ne le montre plus — mais un id forge ne doit pas faire
        // mieux que le selecteur.
        $prive = $this->fichier($this->dossierPrive(), 'note-privee.pdf');

        $resultat = app(LoopManifestoService::class)
            ->attachSource($this->boucle, $prive->id, $this->proprietaire);

        $this->assertSame(LoopManifestoService::RESULT_OUTSIDE_ROOT_DOSSIER, $resultat['result']);
        $this->assertSame(0, $this->boucle->manifestoSources()->count());
    }

    public function test_a_shared_file_still_attaches(): void
    {
        $partage = $this->fichier($this->racine, 'reference-partagee.pdf');

        $resultat = app(LoopManifestoService::class)
            ->attachSource($this->boucle, $partage->id, $this->proprietaire);

        $this->assertSame(LoopManifestoService::RESULT_ATTACHED, $resultat['result']);
        $this->assertSame(1, $this->boucle->manifestoSources()->count());
    }

    public function test_cross_tenant_stays_refused_first(): void
    {
        // Un fichier d'une autre Organization : refus tenant, avant meme la
        // question du Dossier.
        app()->instance('current_organization', $this->orgB);
        $etranger = User::factory()->create(['organization_id' => $this->orgB->id]);
        $boucleB = (new LoopService)->createLoop($etranger, 'Boucle B QA')->fresh();
        $racineB = Dossier::where('loop_id', $boucleB->id)->firstOrFail();
        app()->instance('current_organization', $this->orgA);

        $fichierB = DossierFile::factory()->create([
            'organization_id' => $this->orgB->id,
            'dossier_id' => $racineB->id,
            'uploaded_by' => $etranger->id,
        ]);

        $resultat = app(LoopManifestoService::class)
            ->attachSource($this->boucle, $fichierB->id, $this->proprietaire);

        $this->assertSame(LoopManifestoService::RESULT_CROSS_TENANT, $resultat['result']);
    }
}
