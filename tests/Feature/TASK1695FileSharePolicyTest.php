<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierFileShare;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1695, geste B — une autorisation de lecture posée sur UN FICHIER.
 *
 * ## Le défaut que cette TASK corrige
 *
 * Mesuré sur OFSH le 24/10 : le partage vivait sur le **Dossier**, la création
 * de dossiers a été supprimée (TASK-1629), et « Mes documents » ne peut pas
 * être une ancre de partage (TASK-1136). Un membre n'avait **aucun** chemin
 * pour partager un fichier.
 *
 * ## Ce que le partage ouvre, et rien de plus
 *
 * Arbitrage de Cyril, chemin « C » : **lecture seule**, pas de re-partage, pas
 * d'héritage. Les tests qui comptent ici sont donc ceux de l'**absence** de
 * droit — ils rendent la borne démontrable au lieu de seulement voulue.
 *
 * ## La policy AJOUTE une branche, elle ne redit rien
 *
 * `DossierFilePolicy` portait cet avertissement, suivi à la lettre :
 * *« une policy dormante désalignée est un piège armé »*. La branche ajoute un
 * cas ; `manageFiles` et `deleteFile` restent une pure délégation au Dossier.
 */
class TASK1695FileSharePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $destinataire;

    private User $temoin;

    private Dossier $dossier;

    private DossierFile $fichier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1695', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->destinataire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->temoin = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        $this->fichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
        ]);
    }

    private function partager(?User $pour = null): DossierFileShare
    {
        return DossierFileShare::query()->create([
            'organization_id' => $this->fichier->organization_id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => ($pour ?? $this->destinataire)->id,
            'shared_by' => $this->proprietaire->id,
        ]);
    }

    /** Prémisse : sans partage, personne d'autre ne lit le fichier. */
    public function test_without_a_share_nobody_else_can_read_the_file(): void
    {
        $this->assertFalse($this->destinataire->can('viewFiles', $this->fichier));
        $this->assertFalse($this->temoin->can('viewFiles', $this->fichier));
    }

    /** LE geste : le partage ouvre la LECTURE. */
    public function test_a_share_grants_read_access(): void
    {
        $this->partager();

        $this->assertTrue($this->destinataire->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * Et à CETTE personne seulement.
     *
     * Sans cette moitié, une branche qui répondrait « un partage existe »
     * — sans regarder pour qui — passerait le test précédent.
     */
    public function test_a_share_grants_access_to_that_person_only(): void
    {
        $this->partager();

        $this->assertFalse($this->temoin->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * Le partage n'ouvre NI la modification NI la suppression.
     *
     * C'est la borne du chemin « C ». Ces deux abilities restent une pure
     * délégation au Dossier — le test le rend démontrable.
     */
    public function test_a_share_opens_neither_management_nor_deletion(): void
    {
        $this->partager();
        $destinataire = $this->destinataire->fresh();

        $this->assertFalse($destinataire->can('manageFiles', $this->fichier));
        $this->assertFalse($destinataire->can('deleteFile', $this->fichier));
    }

    /**
     * Le partage n'ouvre PAS le Dossier qui contient le fichier.
     *
     * C'est la fuite qu'il fallait écarter : un accès au contenant donnerait
     * aussi accès à tout ce qu'il contient — l'inverse de « pas d'héritage ».
     */
    public function test_a_share_does_not_open_the_containing_dossier(): void
    {
        $this->partager();
        $destinataire = $this->destinataire->fresh();

        $this->assertFalse($destinataire->can('view', $this->dossier));
        $this->assertFalse($destinataire->can('viewFiles', $this->dossier));
    }

    /**
     * Ni les fichiers VOISINS du même Dossier.
     *
     * Même raison, mesurée dans l'autre sens : l'autorisation porte sur une
     * ligne, pas sur un emplacement.
     */
    public function test_a_share_does_not_open_the_neighbouring_files(): void
    {
        $this->partager();

        $voisin = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
        ]);

        $this->assertFalse($this->destinataire->fresh()->can('viewFiles', $voisin));
    }

    /**
     * Une personne d'une AUTRE Organization n'entre pas, même avec un partage.
     *
     * La ligne est forgée exprès : c'est la seule façon de prouver que la
     * frontière de tenant est vérifiée **à la lecture**, et pas seulement au
     * moment où le partage est créé.
     */
    public function test_a_forged_share_never_crosses_the_tenant_boundary(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1695-autre', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $etranger->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $this->assertFalse($etranger->can('viewFiles', $this->fichier));
    }

    /**
     * Une ligne de partage dont l'Organization NE CORRESPOND PAS au fichier
     * n'ouvre rien.
     *
     * ## Pourquoi ce test existe
     *
     * La policy porte deux bornes de tenant : `tenantOk()` compare
     * l'Organization courante au fichier **et** à la personne, et `sharedWith()`
     * exige en plus que la ligne de partage porte celle du fichier.
     *
     * Contre-épreuve faite avant d'écrire ce test : retirer la **seconde** ne
     * faisait rougir **aucun** test. C'était une garde que rien ne prouvait —
     * et une garde que personne ne mesure finit par disparaître lors d'un
     * nettoyage, sans que rien ne le signale.
     *
     * Le cas qu'elle couvre, et que `tenantOk()` laisse passer : une ligne
     * **corrompue ou forgée**, portant la bonne personne et le bon fichier mais
     * une autre Organization. Les deux bornes répondent à des questions
     * différentes ; chacune doit avoir la sienne.
     */
    public function test_a_share_row_with_a_mismatched_organization_opens_nothing(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1695-tiers', 'is_active' => true]);

        DossierFileShare::query()->create([
            // La personne et le fichier sont bons ; l'Organization de la LIGNE
            // ne l'est pas.
            'organization_id' => $autreOrg->id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $this->assertFalse($this->destinataire->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * Un fichier SUPPRIMÉ n'est plus lisible, même partagé.
     *
     * `DossierFile` emploie `SoftDeletes` : la cascade SQL ne joue qu'à la
     * suppression **dure**, donc la ligne de partage survit. Sans la
     * vérification explicite dans la policy, le destinataire lirait un fichier
     * que son propriétaire croit disparu.
     */
    public function test_a_soft_deleted_file_is_no_longer_readable(): void
    {
        $this->partager();
        $this->assertTrue($this->destinataire->fresh()->can('viewFiles', $this->fichier));

        $this->fichier->delete();

        $this->assertTrue($this->fichier->fresh()->trashed(), 'premisse : la suppression est DOUCE');
        $this->assertFalse($this->destinataire->fresh()->can('viewFiles', $this->fichier->fresh()));
    }

    /**
     * Et la suppression DURE emporte la ligne de partage.
     *
     * C'est la règle posée par Cyril — « le partage suit le fichier » — et sa
     * conséquence : aucune autorisation ne doit survivre à ce qu'elle désigne.
     */
    public function test_a_hard_delete_takes_the_share_with_it(): void
    {
        $partage = $this->partager();

        $this->fichier->forceDelete();

        $this->assertDatabaseMissing('dossier_file_shares', ['id' => $partage->id]);
    }

    /** Le propriétaire garde évidemment tous ses droits. */
    public function test_the_owner_keeps_every_right(): void
    {
        $proprietaire = $this->proprietaire->fresh();

        $this->assertTrue($proprietaire->can('viewFiles', $this->fichier));
        $this->assertTrue($proprietaire->can('manageFiles', $this->fichier));
    }

    /** On ne partage pas deux fois la même chose à la même personne. */
    public function test_the_same_file_cannot_be_shared_twice_with_the_same_person(): void
    {
        $this->partager();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->partager();
    }
}
