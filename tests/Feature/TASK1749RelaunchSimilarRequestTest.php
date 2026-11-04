<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1749 (demande Cyril, 04/11) — relancer une demande POURVUE.
 *
 * ## Le defaut d'origine
 *
 * Sur une demande pourvue, « Modifier » menait a « Vous n'avez pas acces a
 * cette page ». La cause n'est PAS le statut : `ServiceRequestPolicy::update()`
 * refuse des qu'une transaction reference la demande — et c'est juste, editer
 * reecrirait les termes d'un echange deja conclu.
 *
 * Le bouton etait pourtant rendu SANS CONDITION. On proposait une porte fermee,
 * avec un message qui se lit comme un defaut de permission sur sa propre
 * demande.
 *
 * ## Pourquoi relancer et non rouvrir
 *
 * Rouvrir la meme ligne aurait fait pointer un echange TERMINE vers une demande
 * OUVERTE : le badge du tableau de bord, l'historique d'echanges et l'ecriture
 * de points auraient dit trois choses differentes du meme objet.
 */
class TASK1749RelaunchSimilarRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private Category $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->organization = Organization::factory()->create([
            'slug' => 'org-relance-1749',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->auteur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->categorie = Category::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    private function demande(string $statut, ?User $auteur = null): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => ($auteur ?? $this->auteur)->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie->id,
            'status' => $statut,
            'title' => 'Besoin d\'un coup de main en comptabilite',
            'description' => str_repeat('Une description suffisamment longue pour passer la validation. ', 3),
            'budget_min' => 20,
            'budget_max' => 40,
            'delivery_mode' => 'remote',
        ]);
    }

    private function urlDeRelance(ServiceRequest $source): string
    {
        return route('organization.requests.create', ['organization' => $this->organization])
            .'?relancer='.$source->id;
    }

    public function test_la_demande_pourvue_propose_de_relancer_et_non_de_modifier(): void
    {
        $pourvue = $this->demande('closed');

        $html = $this->actingAs($this->auteur)
            ->get(route('organization.requests.show', ['organization' => $this->organization, 'request' => $pourvue]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-request-relaunch', $html);
        $this->assertStringContainsString(__('requests.show.relaunch'), $html);
        $this->assertStringNotContainsString(__('requests.show.edit'), $html);
    }

    public function test_une_demande_encore_ouverte_garde_modifier(): void
    {
        $ouverte = $this->demande('open');

        $html = $this->actingAs($this->auteur)
            ->get(route('organization.requests.show', ['organization' => $this->organization, 'request' => $ouverte]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('requests.show.edit'), $html);
        $this->assertStringNotContainsString('data-request-relaunch', $html);
    }

    public function test_le_formulaire_arrive_pre_rempli(): void
    {
        $pourvue = $this->demande('closed');

        $html = $this->actingAs($this->auteur)
            ->get($this->urlDeRelance($pourvue))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-request-relaunch-notice', $html);
        $this->assertStringContainsString('name="relancer_de" value="'.$pourvue->id.'"', $html);
        $this->assertStringContainsString(e($pourvue->title), $html);
    }

    /**
     * L'ECHEANCE n'est pas reprise : celle de l'origine est passee, et `store()`
     * exige `after:today`. La recopier aurait produit un formulaire pre-rempli
     * qui echoue a l'envoi, sur un champ que la personne n'a pas touche.
     */
    public function test_l_echeance_n_est_pas_reprise(): void
    {
        $pourvue = $this->demande('closed');
        $pourvue->forceFill(['deadline' => now()->subMonth()])->save();

        $this->actingAs($this->auteur)->get($this->urlDeRelance($pourvue))->assertOk();

        $this->assertNull(session('_old_input.deadline'));
    }

    /**
     * LA garde qui compte le plus : les pieces jointes sont DUPLIQUEES. Sans
     * borne d'auteur, n'importe qui recopierait les fichiers de n'importe qui.
     */
    public function test_on_ne_relance_PAS_la_demande_d_un_autre(): void
    {
        $autre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $sienne = $this->demande('closed', $autre);

        $this->actingAs($this->auteur)
            ->get($this->urlDeRelance($sienne))
            ->assertNotFound();
    }

    public function test_on_ne_relance_PAS_une_demande_encore_ouverte(): void
    {
        $ouverte = $this->demande('open');

        $this->actingAs($this->auteur)
            ->get($this->urlDeRelance($ouverte))
            ->assertNotFound();
    }

    /**
     * Les pieces jointes sont REPRISES, et le fichier est COPIE.
     *
     * Reutiliser le meme chemin aurait rendu la nouvelle demande dependante de
     * l'ancienne : supprimer la piece jointe d'un echange conclu efface le
     * fichier (`Storage::disk('public')->delete(...)`, methode `update`) et
     * aurait casse l'illustration d'une demande vivante.
     */
    public function test_les_pieces_jointes_sont_reprises_dans_un_fichier_DISTINCT(): void
    {
        $pourvue = $this->demande('closed');

        $chemin = UploadedFile::fake()->image('plan.png')->store('request-attachments', 'public');
        $pourvue->attachments()->create([
            'path' => $chemin,
            'original_name' => 'plan.png',
            'mime_type' => 'image/png',
            'order' => 0,
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->auteur)->post(
            route('organization.requests.store', ['organization' => $this->organization]),
            [
                'title' => 'Encore besoin d\'un coup de main',
                'description' => str_repeat('Une description suffisamment longue pour passer la validation. ', 3),
                'category_id' => $this->categorie->id,
                'delivery_mode' => 'remote',
                'budget_min' => 20,
                'relancer_de' => $pourvue->id,
            ]
        )->assertRedirect();

        $neuve = ServiceRequest::where('title', 'Encore besoin d\'un coup de main')->firstOrFail();
        $copie = $neuve->attachments()->firstOrFail();

        $this->assertSame('plan.png', $copie->original_name);
        $this->assertNotSame($chemin, $copie->path, 'La copie partage le chemin de l\'original : supprimer l\'un casserait l\'autre.');
        Storage::disk('public')->assertExists($copie->path);
        Storage::disk('public')->assertExists($chemin);
    }

    /**
     * Le champ est CACHE, donc modifiable. `store()` reapplique les memes
     * conditions que l'affichage — sans quoi la borne d'auteur ne tiendrait
     * qu'a l'interface.
     */
    public function test_le_champ_cache_ne_permet_pas_de_copier_les_fichiers_d_un_autre(): void
    {
        $autre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $sienne = $this->demande('closed', $autre);

        $chemin = UploadedFile::fake()->image('prive.png')->store('request-attachments', 'public');
        $sienne->attachments()->create([
            'path' => $chemin,
            'original_name' => 'prive.png',
            'mime_type' => 'image/png',
            'order' => 0,
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->auteur)->post(
            route('organization.requests.store', ['organization' => $this->organization]),
            [
                'title' => 'Tentative de recopie',
                'description' => str_repeat('Une description suffisamment longue pour passer la validation. ', 3),
                'category_id' => $this->categorie->id,
                'delivery_mode' => 'remote',
                'budget_min' => 20,
                'relancer_de' => $sienne->id,
            ]
        )->assertNotFound();

        $this->assertDatabaseMissing('service_requests', ['title' => 'Tentative de recopie']);
    }
}
