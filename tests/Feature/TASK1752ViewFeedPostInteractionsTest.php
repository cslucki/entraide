<?php

namespace Tests\Feature;

use App\Livewire\ViewFeedPost;
use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1752 (§5.1 du CDC) — sur `/flux/{id}`, reagir et commenter MARCHENT.
 *
 * ## Le defaut, et pourquoi il a survecu
 *
 * `ViewFeedPost` rend la MEME carte que la liste, qui appelle
 * `toggleReaction` et `addComment`. Le composant n'avait que `mount()` et
 * `render()` : les deux boutons levaient `MethodNotFoundException`.
 *
 * **Aucun test ne couvrait ce composant** — 77 methodes sur
 * `OrganizationFeed`, 36 sur la policy, zero ici. Le defaut n'a donc jamais
 * rougi nulle part. Ce fichier ferme ce trou, et c'est sa raison d'etre
 * principale.
 */
class TASK1752ViewFeedPostInteractionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private FeedPost $annonce;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-vfp-1752', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->annonce = FeedPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->membre->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Une annonce a commenter.',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function test_reagir_depuis_la_fiche_fonctionne(): void
    {
        $emoji = Reaction::REACTION_TYPES[0];

        Livewire::actingAs($this->membre)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->call('toggleReaction', $this->annonce->id, $emoji)
            ->assertOk();

        $this->assertDatabaseHas('reactions', [
            'user_id' => $this->membre->id,
            'reactionable_id' => $this->annonce->id,
            'reactionable_type' => FeedPost::class,
            'reaction_type' => $emoji,
        ]);
    }

    public function test_commenter_depuis_la_fiche_fonctionne(): void
    {
        Livewire::actingAs($this->membre)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->set('commentForms.'.$this->annonce->id, 'Mon commentaire depuis la fiche.')
            ->call('addComment', $this->annonce->id)
            ->assertOk();

        $this->assertDatabaseHas('feed_post_comments', [
            'feed_post_id' => $this->annonce->id,
            'user_id' => $this->membre->id,
            'content' => 'Mon commentaire depuis la fiche.',
        ]);
    }

    /**
     * La frontiere de locataire tient aussi depuis cette surface.
     *
     * Le trait verifie la portee a la main, `FeedPost` n'ayant pas de scope
     * global. Sans cette garde, l'extraction aurait pu la perdre en silence.
     */
    public function test_reagir_sur_l_annonce_d_une_autre_organization_est_refuse(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-vfp-autre-1752', 'is_active' => true]);
        $etrangere = FeedPost::create([
            'organization_id' => $autre->id,
            'user_id' => User::factory()->create(['organization_id' => $autre->id])->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Annonce etrangere.',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        Livewire::actingAs($this->membre)
            ->test(ViewFeedPost::class, ['feedPost' => $this->annonce])
            ->call('toggleReaction', $etrangere->id, Reaction::REACTION_TYPES[0]);

        $this->assertDatabaseMissing('reactions', [
            'reactionable_id' => $etrangere->id,
        ]);
    }
}
