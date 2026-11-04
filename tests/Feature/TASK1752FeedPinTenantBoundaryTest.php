<?php

namespace Tests\Feature;

use App\Livewire\MyFeedPosts;
use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1752 — l'epinglage ne traverse plus la frontiere de locataire.
 *
 * ## Le defaut, tel qu'il a ete PROUVE par l'audit du 04/11
 *
 * `MyFeedPosts::togglePin()` demandait `can('pin', FeedPost::class)` — la
 * CLASSE. `FeedPostPolicy::pin()` ne recevait donc aucun objet et ne
 * verifiait que l'appartenance de l'ACTEUR.
 *
 * Trois faits se combinaient pour en faire une ecriture reelle :
 *
 *  1. `FeedPost` n'a PAS de scope global d'Organization — `HasOrganizationId`
 *     ne fait que REMPLIR la colonne a la creation ;
 *  2. `togglePin()` resolvait par `findOrFail()`, sans portee ;
 *  3. le `saving` du modele DEPINGLE EN MASSE les autres annonces de
 *     l'Organization de la cible.
 *
 * L'administrateur de A epinglait donc une annonce de B, `pinned_by_id`
 * enregistrait quelqu'un d'etranger au tenant modifie, et l'epinglage
 * precedent de B disparaissait en silence.
 *
 * ## Ce que ce fichier garde, et ce qu'il NE garde pas
 *
 * Il garde le refus ET sa reciproque. Sans la seconde, un code qui refuserait
 * TOUT passerait le premier test sans rien mesurer — c'est la lecon payee en
 * T1744 sur les gardes de masquage.
 *
 * Il garde aussi l'effet de bord le plus silencieux : l'epinglage existant de
 * la cible ne doit pas bouger. Un refus qui laisserait quand meme le `saving`
 * s'executer serait une demi-correction.
 */
class TASK1752FeedPinTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1752', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1752', 'is_active' => true]);

        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->orgA->forceFill(['admin_id' => $this->adminA->id])->save();
    }

    private function annonce(Organization $org, ?User $auteur = null, ?string $epingleeLe = null): FeedPost
    {
        $auteur ??= User::factory()->create(['organization_id' => $org->id]);

        return FeedPost::create([
            'organization_id' => $org->id,
            'user_id' => $auteur->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce de '.$org->slug,
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
            'pinned_at' => $epingleeLe,
        ]);
    }

    /** Le contexte de locataire, pose comme une requete HTTP le ferait. */
    private function dansLOrganization(Organization $org): void
    {
        app()->instance('current_organization', $org);
    }

    // ── LA garde ───────────────────────────────────────────────────────────

    public function test_un_administrateur_ne_peut_pas_epingler_l_annonce_d_une_AUTRE_organization(): void
    {
        $etrangere = $this->annonce($this->orgB);

        $this->dansLOrganization($this->orgA);

        Livewire::actingAs($this->adminA)
            ->test(MyFeedPosts::class)
            ->call('togglePin', $etrangere->id)
            ->assertForbidden();

        $this->assertNull($etrangere->fresh()->pinned_at,
            'Une annonce d\'une autre Organization a ete epinglee.');
        $this->assertNull($etrangere->fresh()->pinned_by_id,
            '`pinned_by_id` porte quelqu\'un qui n\'est pas membre du tenant modifie.');
    }

    /**
     * L'effet de bord le plus silencieux : le `saving` du modele depingle en
     * masse. Un refus incomplet laisserait la cible perdre son epinglage.
     */
    public function test_l_epinglage_existant_de_l_autre_organization_ne_bouge_pas(): void
    {
        $dejaEpinglee = $this->annonce($this->orgB, epingleeLe: now()->subDay()->toDateTimeString());
        $autre = $this->annonce($this->orgB);

        $this->dansLOrganization($this->orgA);

        Livewire::actingAs($this->adminA)
            ->test(MyFeedPosts::class)
            ->call('togglePin', $autre->id)
            ->assertForbidden();

        $this->assertNotNull($dejaEpinglee->fresh()->pinned_at,
            'L\'epinglage de l\'autre Organization a ete silencieusement retire.');
    }

    // ── La RECIPROQUE, sans quoi la garde ne mesure rien ───────────────────

    public function test_le_meme_administrateur_epingle_bien_CHEZ_LUI(): void
    {
        $sienne = $this->annonce($this->orgA, $this->adminA);

        $this->dansLOrganization($this->orgA);

        Livewire::actingAs($this->adminA)
            ->test(MyFeedPosts::class)
            ->call('togglePin', $sienne->id)
            ->assertOk();

        $this->assertNotNull($sienne->fresh()->pinned_at,
            'Le correctif a ferme le cas legitime en meme temps que la fuite.');
        $this->assertSame($this->adminA->id, $sienne->fresh()->pinned_by_id);
    }

    /**
     * La policy repond encore a la question SANS objet.
     *
     * `CreateFeedPost` et la vue `my-feed-posts` demandent `can('pin',
     * FeedPost::class)` pour decider d'afficher une case ou un bouton, avant
     * que l'annonce existe. Rendre l'argument obligatoire les aurait casses.
     */
    public function test_la_permission_generale_reste_interrogeable_sans_annonce(): void
    {
        $this->dansLOrganization($this->orgA);

        $this->assertTrue($this->adminA->can('pin', FeedPost::class));

        $simpleMembre = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->assertFalse($simpleMembre->can('pin', FeedPost::class));
    }
}
