<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierFileShare;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1695, geste E — le fichier partagé APPARAÎT dans « Partagés ».
 *
 * ## Le maillon qui manquait
 *
 * Après le geste D, le destinataire avait bien le droit de lire le fichier —
 * la policy le lui accordait, c'était testé — mais **rien ne lui donnait
 * l'URL**. Il aurait fallu la lui coller à la main dans la barre d'adresse.
 *
 * Une capacité qu'aucun écran n'expose est une capacité **absente**.
 *
 * ## Pas d'URL nouvelle
 *
 * Décision de Cyril : les fichiers rejoignent `?espace=partages`, dans les
 * **deux vues existantes**. Un libellé les distingue des Dossiers, sans quoi
 * la liste laisserait croire à un accès au dossier porteur.
 */
class TASK1695SharedFilesEspaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $destinataire;

    private Dossier $dossier;

    private DossierFile $fichier;

    protected function setUp(): void
    {
        parent::setUp();

        // Le fichier doit exister SUR LE DISQUE : sans cela, le transport
        // rend 404 et l'on croirait a un refus d'autorisation.
        Storage::fake('local');
        Storage::disk('local')->put('dossiers/1695e/rapport.pdf', 'CONTENU_1695E');

        $this->organization = Organization::factory()->create(['slug' => 'org-1695e', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->destinataire = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        $this->fichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'original_name' => 'rapport-annuel-1695.pdf',
            'disk' => 'local',
            'path' => 'dossiers/1695e/rapport.pdf',
        ]);
    }

    private function partager(): DossierFileShare
    {
        return DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);
    }

    private function espace(User $user, string $vue = 'avec-moi'): string
    {
        return $this->actingAs($user)
            ->get(route('organization.dossiers.index', [
                'organization' => $this->organization->slug,
                'espace' => 'partages',
                'vue' => $vue,
            ]))
            ->assertOk()
            ->getContent();
    }

    /** LE geste : le destinataire voit le fichier sans qu'on lui donne l'URL. */
    public function test_the_recipient_sees_the_shared_file_in_his_espace(): void
    {
        $this->partager();

        $this->assertStringContainsString(
            e($this->fichier->original_name),
            $this->espace($this->destinataire),
        );
    }

    /** Et celui qui a partagé le voit sous « Par moi ». */
    public function test_the_sharer_sees_the_file_under_shared_by_me(): void
    {
        $this->partager();

        $this->assertStringContainsString(
            e($this->fichier->original_name),
            $this->espace($this->proprietaire, 'par-moi'),
        );
    }

    /**
     * Le lien vise la route DÉDIÉE, jamais le chemin du Dossier.
     *
     * C'est toute la raison d'être des routes du geste C : le destinataire n'a
     * aucun droit sur le Dossier porteur. Un lien vers lui rendrait 403 — un
     * écran qui propose une porte fermée est pire qu'un écran muet.
     */
    public function test_the_link_points_at_the_dedicated_read_route(): void
    {
        $this->partager();
        $html = $this->espace($this->destinataire);

        $attendu = route('organization.dossiers.shared.preview', [
            'organization' => $this->organization->slug,
            'file' => $this->fichier->id,
        ]);

        $this->assertStringContainsString(e($attendu), $html);

        $interdit = route('organization.dossiers.show', [
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
        ]);
        $this->assertStringNotContainsString(e($interdit), $html);
    }

    /** Le lien suivi fonctionne VRAIMENT — pas seulement présent dans la page. */
    public function test_following_that_link_actually_serves_the_file(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)
            ->get(route('organization.dossiers.shared.preview', [
                'organization' => $this->organization->slug,
                'file' => $this->fichier->id,
            ]))
            ->assertSuccessful();
    }

    /** Sans partage, rien. L'écran ne révèle pas l'existence du fichier. */
    public function test_a_file_not_shared_with_me_never_appears(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->partager();

        $this->assertStringNotContainsString(
            e($this->fichier->original_name),
            $this->espace($etranger),
        );
    }

    /**
     * Un fichier mis à la corbeille disparaît de la liste.
     *
     * La ligne de partage lui SURVIT : la cascade SQL n'agit qu'à la
     * suppression dure. L'assertion sur `dossier_file_shares` le prouve — sans
     * elle, ce test pourrait passer parce que le partage a disparu, pas parce
     * que l'écran filtre.
     *
     * **Ce test ne prouve PAS la clause `withoutTrashed()` du contrôleur.**
     * Mesuré le 24/10 : la retirer laisse ce test vert, parce que
     * `DossierFile` porte `SoftDeletes` et que la corbeille est déjà hors
     * requête. Ce qu'il épingle est le RÉSULTAT — un fichier en corbeille ne
     * se propose pas — quelle que soit la mécanique qui l'obtient. C'est ce
     * qu'on veut tenir : la policy le refuse déjà, l'écran ne doit pas
     * proposer ce qu'elle refusera.
     */
    public function test_a_soft_deleted_file_leaves_the_list(): void
    {
        $this->partager();
        $this->fichier->delete();

        $this->assertDatabaseCount('dossier_file_shares', 1);

        $this->assertStringNotContainsString(
            e($this->fichier->original_name),
            $this->espace($this->destinataire),
        );
    }

    /**
     * L'écran dit ce qu'un fichier partagé n'ouvre PAS.
     *
     * Même exigence qu'au geste D : personne ne devinerait que le partage
     * s'arrête au fichier seul, et le deviner de travers ferait croire à un
     * accès au dossier entier.
     */
    public function test_the_list_states_that_a_file_opens_alone(): void
    {
        $this->partager();

        $this->assertStringContainsString(
            e(__('dossiers.shared_files_hint')),
            $this->espace($this->destinataire),
        );
    }

    /**
     * « Par moi » se lit sur `shared_by`, PAS sur `uploaded_by`.
     *
     * Sans cette distinction, un partage posé par quelqu'un d'autre sur un
     * fichier que j'ai téléversé apparaîtrait sous « Par moi » — en me
     * présentant comme l'auteur d'un geste que je n'ai pas fait.
     */
    public function test_shared_by_me_reads_the_author_of_the_share(): void
    {
        $autreGestionnaire = User::factory()->create(['organization_id' => $this->organization->id]);

        DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $autreGestionnaire->id,
        ]);

        // Le téléverseur, lui, n'a rien partagé.
        $this->assertStringNotContainsString(
            e($this->fichier->original_name),
            $this->espace($this->proprietaire, 'par-moi'),
        );

        $this->assertStringContainsString(
            e($this->fichier->original_name),
            $this->espace($autreGestionnaire, 'par-moi'),
        );
    }

    /** Un espace qui ne contient QUE des fichiers ne s'annonce pas vide. */
    public function test_an_espace_holding_only_files_is_not_announced_empty(): void
    {
        $this->partager();

        $this->assertStringNotContainsString(
            e(__('dossiers.shared_with_me_empty')),
            $this->espace($this->destinataire),
        );
    }

    /** Le partage d'une AUTRE Organization n'entre pas dans ma liste. */
    public function test_a_share_from_another_organization_never_appears(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1695e-bis', 'is_active' => true]);
        $autreFichier = DossierFile::factory()->create([
            'organization_id' => $autreOrg->id,
            'dossier_id' => Dossier::factory()->create(['organization_id' => $autreOrg->id])->id,
            'original_name' => 'etranger-1695.pdf',
        ]);

        DossierFileShare::query()->create([
            'organization_id' => $autreOrg->id,
            'dossier_file_id' => $autreFichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $this->assertStringNotContainsString(
            e($autreFichier->original_name),
            $this->espace($this->destinataire),
        );
    }
}
