<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1684 — deposer un fichier dans la Boucle, sans ouvrir son administration.
 *
 * Arbitrage MASTER : tout membre ACTIF doit pouvoir deposer un fichier dans la
 * Boucle. `manageFiles` ne pouvait pas porter cette ouverture — il gouverne
 * AUSSI renommer, deplacer, editer le markdown et la projection « puis-je
 * administrer ce dossier » des ecrans. L'elargir aurait donne tout cela a
 * chaque membre et contredit la doctrine T1130 (« etre membre ne suffit pas »).
 *
 * D'ou une ability dediee, `uploadFiles`, lue par le SEUL
 * `StoreDossierFileRequest`. Ces tests mesurent les deux faces : ce qui
 * s'ouvre, et ce qui reste ferme.
 */
class TASK1684DepotFichierBoucleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $membre;

    private $loop;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->owner, 'Boucle de depot', type: 'project');
        $service->addMember($this->loop, $this->membre, 'member');

        // Pose par la creation de la Boucle ; `dossiers.loop_id` est UNIQUE.
        $this->racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();
    }

    public function test_un_membre_simple_peut_deposer_un_fichier_dans_la_boucle(): void
    {
        $this->assertTrue($this->membre->can('uploadFiles', $this->racine));
    }

    /**
     * La contre-mesure qui donne son sens a la precedente : l'ouverture porte
     * sur le DEPOT seul. Si `uploadFiles` avait ete pose en elargissant
     * `manageFiles`, cette assertion tomberait — et avec elle le verrou de
     * renommage, de deplacement et d'edition du markdown.
     */
    public function test_le_depot_n_ouvre_NI_l_administration_NI_la_suppression(): void
    {
        $this->assertFalse($this->membre->can('manageFiles', $this->racine),
            'Renommer, deplacer et editer le markdown restent fermes au membre simple.');
        $this->assertFalse($this->membre->can('update', $this->racine));
        $this->assertFalse($this->membre->can('deleteFile', $this->racine));
    }

    public function test_l_owner_conserve_les_deux(): void
    {
        $this->assertTrue($this->owner->can('uploadFiles', $this->racine));
        $this->assertTrue($this->owner->can('manageFiles', $this->racine));
    }

    public function test_un_non_membre_de_la_boucle_ne_depose_pas(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->assertFalse($etranger->can('uploadFiles', $this->racine));
    }

    public function test_une_autre_organization_ne_depose_jamais(): void
    {
        $autreOrg = Organization::factory()->create();
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);

        $this->assertFalse($intrus->can('uploadFiles', $this->racine));
    }

    /**
     * Une Boucle archivee est en lecture seule — meme borne que
     * `LoopPolicy::update`, qui refuse pour exactement cette raison.
     */
    public function test_une_boucle_archivee_ferme_le_depot(): void
    {
        $this->assertTrue($this->membre->can('uploadFiles', $this->racine));

        // `isArchived()` se lit sur `status`, pas sur `archived_at` : poser la
        // seule date laissait la Boucle VIVANTE et le test passait au vert
        // pour une mauvaise raison — constate en l'ecrivant.
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        $this->racine->refresh()->unsetRelation('loop');

        $this->assertFalse($this->membre->fresh()->can('uploadFiles', $this->racine->fresh()),
            'Archivee, la Boucle ne recoit plus de fichier.');
    }

    /**
     * Portee etroite assumee : l'ouverture vise le Dossier RACINE, pas
     * l'arborescence. Un sous-dossier garde la regle proprietaire.
     */
    public function test_un_sous_dossier_n_herite_pas_de_l_ouverture(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->racine->id,
            'name' => 'Sous-dossier',
            'visibility' => $this->racine->visibility,
        ]);

        $this->assertFalse($this->membre->can('uploadFiles', $enfant),
            'Deposer dans la Boucle n\'est pas administrer son arborescence.');
    }
}
