<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierFileShare;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1695, geste C — lire un fichier qu'on vous a partagé, par une route à soi.
 *
 * ## La décision de conception, et sa raison
 *
 * **Le destinataire n'emprunte jamais l'URL du Dossier d'autrui.**
 *
 * Les routes existantes sont imbriquées sous `/dossiers/{dossier}/files/{file}`
 * et autorisent sur le **Dossier**. Y faire accepter un partage de fichier
 * aurait obligé à masquer tout le reste du Dossier au destinataire — chaque
 * voisin, chaque sous-dossier, chaque Article. **C'est là que les fuites se
 * logent.**
 *
 * Les tests ci-dessous mesurent donc deux choses à parts égales : que le
 * chemin dédié **ouvre** le fichier partagé, et que le chemin du Dossier
 * **reste fermé**.
 */
class TASK1695SharedFileRoutesTest extends TestCase
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

        Storage::fake('local');

        $this->organization = Organization::factory()->create(['slug' => 'org-1695c', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->destinataire = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        $chemin = 'dossiers/'.$this->organization->id.'/secret-1695.txt';
        Storage::disk('local')->put($chemin, 'CONTENU_SECRET_1695');

        $this->fichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'disk' => 'local',
            'path' => $chemin,
            'original_name' => 'secret-1695.txt',
            'mime_type' => 'text/plain',
        ]);
    }

    private function partager(): void
    {
        DossierFileShare::query()->create([
            'organization_id' => $this->fichier->organization_id,
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $this->destinataire->id,
            'shared_by' => $this->proprietaire->id,
        ]);
    }

    private function urlPartagee(string $quoi = 'show'): string
    {
        return route('organization.dossiers.shared.'.$quoi, [
            'organization' => $this->organization->slug,
            'file' => $this->fichier->id,
        ]);
    }

    private function urlDuDossier(): string
    {
        return route('organization.dossiers.files.show', [
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
            'file' => $this->fichier->id,
        ]);
    }

    /** Prémisse : sans partage, la route dédiée refuse. */
    public function test_without_a_share_the_dedicated_route_refuses(): void
    {
        $this->actingAs($this->destinataire)->get($this->urlPartagee())->assertForbidden();
    }

    /** LE geste : avec un partage, le fichier se télécharge. */
    public function test_with_a_share_the_file_can_be_downloaded(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)->get($this->urlPartagee())->assertOk();
    }

    /** Et se prévisualise. */
    public function test_with_a_share_the_file_can_be_previewed(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)->get($this->urlPartagee('preview'))->assertOk();
    }

    /**
     * LA moitié qui compte : le chemin du DOSSIER reste fermé.
     *
     * C'est toute la raison d'une route à part. Sans cette mesure, on aurait
     * ouvert un accès sans savoir ce qu'il ouvre d'autre.
     */
    public function test_the_dossier_path_stays_closed_even_with_a_share(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)->get($this->urlDuDossier())->assertForbidden();
    }

    /** Et l'écran du Dossier lui-même reste fermé. */
    public function test_the_dossier_screen_stays_closed(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))
            ->assertForbidden();
    }

    /**
     * Un fichier VOISIN n'est pas atteignable par la route dédiée.
     *
     * L'autorisation porte sur une ligne, pas sur un emplacement : c'est ce que
     * ce test rend vérifiable côté HTTP, et pas seulement côté policy.
     */
    public function test_a_neighbouring_file_is_not_reachable(): void
    {
        $this->partager();

        $voisin = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'disk' => 'local',
        ]);

        $this->actingAs($this->destinataire)
            ->get(route('organization.dossiers.shared.show', [
                'organization' => $this->organization->slug,
                'file' => $voisin->id,
            ]))
            ->assertForbidden();
    }

    /** Le propriétaire passe aussi par là, sans avoir à savoir par quel droit. */
    public function test_the_owner_may_use_the_dedicated_route_too(): void
    {
        $this->actingAs($this->proprietaire)->get($this->urlPartagee())->assertOk();
    }

    /**
     * Un fichier supprimé en douceur rend 404, pas 403.
     *
     * « Il n'y a rien là » est la vérité, et c'est aussi ce qui n'apprend rien
     * à qui sonde des identifiants.
     */
    public function test_a_soft_deleted_file_answers_not_found(): void
    {
        $this->partager();
        $this->fichier->delete();

        $this->actingAs($this->destinataire)->get($this->urlPartagee())->assertNotFound();
    }

    /**
     * Une personne d'une autre Organization n'entre pas — et le refus est 404.
     *
     * J'attendais 403 ; la mesure dit **404**, et c'est mieux. Le refus vient
     * de la frontiere de TENANT (`organization.member`), **avant** que la
     * policy ne soit consultee : pour qui n'appartient pas a l'Organization de
     * l'URL, « il n'y a rien la » — l'existence meme du fichier n'est pas
     * revelee.
     *
     * Mon attente etait fausse, pas le code. Le test dit maintenant ce que la
     * maison fait vraiment, et pourquoi c'est le bon code.
     */
    public function test_a_member_of_another_organization_never_reaches_the_policy(): void
    {
        $this->partager();

        $autreOrg = Organization::factory()->create(['slug' => 'org-1695c-autre', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($etranger)->get($this->urlPartagee())->assertNotFound();
    }

    /** Et un visiteur anonyme non plus. */
    public function test_an_anonymous_visitor_is_sent_away(): void
    {
        $this->partager();

        $this->get($this->urlPartagee())->assertRedirect();
    }

    /**
     * « partages » n'est pas confondu avec un identifiant de Dossier.
     *
     * Les routes dédiées sont déclarées **avant** `/dossiers/{dossier}` — même
     * idiome que `/relations/modeles` devant `/relations/{contact}`. Le test
     * constate l'ordre plutôt que de s'en remettre au format des identifiants.
     */
    public function test_the_shared_path_is_not_mistaken_for_a_dossier(): void
    {
        $this->partager();

        $this->actingAs($this->destinataire)
            ->get($this->urlPartagee())
            ->assertOk();
    }
}
