<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewModerationLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1808 §2 — cockpit SuperAdmin de moderation des evaluations.
 *
 * Micro-sequences B (consultation + filtres, isolation Organization),
 * C (modifier/masquer/supprimer/restaurer, permissions SuperAdmin),
 * D (journal d'audit : contenu original, acteur, date, motif),
 * E (la moyenne publique exclut les avis masques ou supprimes).
 */
class TASK1808ReviewModerationCockpitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->orgA)->create(['is_admin' => true]);
        $this->membre = User::factory()->for($this->orgA)->create();
    }

    private function avis(Organization $org, array $overrides = []): Review
    {
        $echange = Transaction::factory()->create(['organization_id' => $org->id, 'status' => 'completed']);

        return Review::factory()->create(array_merge([
            'transaction_id' => $echange->id,
            'organization_id' => $org->id,
            'rating' => 4,
            'comment' => 'Avis de depart',
        ], $overrides));
    }

    // ── B — consultation, filtres, isolation Organization ──────────────────

    public function test_un_non_admin_ne_voit_pas_le_cockpit(): void
    {
        $this->actingAs($this->membre)->get(route('admin.reviews'))->assertForbidden();
    }

    public function test_le_cockpit_liste_les_avis_et_filtre_par_note(): void
    {
        $this->avis($this->orgA, ['rating' => 2, 'comment' => 'T1808_AVIS_DEUX']);
        $this->avis($this->orgA, ['rating' => 5, 'comment' => 'T1808_AVIS_CINQ']);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.reviews', ['rating' => 5]))
            ->assertOk();

        $reponse->assertSee('T1808_AVIS_CINQ');
        $reponse->assertDontSee('T1808_AVIS_DEUX');
    }

    public function test_le_filtre_organization_isole_strictement_les_avis_dune_autre_organization(): void
    {
        $this->avis($this->orgA, ['comment' => 'T1808_AVIS_ORG_A']);
        $this->avis($this->orgB, ['comment' => 'T1808_AVIS_ORG_B']);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.reviews', ['organization_id' => $this->orgA->id]))
            ->assertOk();

        $reponse->assertSee('T1808_AVIS_ORG_A');
        $reponse->assertDontSee('T1808_AVIS_ORG_B');
    }

    public function test_le_filtre_visibilite_distingue_visible_masque_et_supprime(): void
    {
        $visible = $this->avis($this->orgA, ['comment' => 'T1808_VISIBLE']);
        $masque = $this->avis($this->orgA, ['comment' => 'T1808_MASQUE', 'hidden_at' => now()]);
        $supprime = $this->avis($this->orgA, ['comment' => 'T1808_SUPPRIME']);
        $supprime->delete();

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.reviews', ['visibility' => 'hidden']))
            ->assertOk();

        $reponse->assertSee('T1808_MASQUE');
        $reponse->assertDontSee('T1808_VISIBLE');
        $reponse->assertDontSee('T1808_SUPPRIME');
    }

    // ── C — modifier / masquer / supprimer / restaurer ──────────────────────

    public function test_modifier_change_la_note_et_le_mot_et_journalise_loriginal(): void
    {
        $avis = $this->avis($this->orgA, ['rating' => 3, 'comment' => 'Avant modification']);

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.reviews.update', $avis->id), [
                'rating' => 5,
                'comment' => 'Apres modification',
                'reason' => 'Langage inapproprie signale par un membre',
            ])
            ->assertRedirect();

        $avis->refresh();
        $this->assertSame(5, $avis->rating);
        $this->assertSame('Apres modification', $avis->comment);
        $this->assertSame($this->superAdmin->id, $avis->moderated_by);

        $entree = ReviewModerationLog::where('review_id', $avis->id)->first();
        $this->assertNotNull($entree);
        $this->assertSame(ReviewModerationLog::ACTION_UPDATED, $entree->action);
        $this->assertSame(3, $entree->original_rating);
        $this->assertSame('Avant modification', $entree->original_comment);
        $this->assertSame($this->superAdmin->id, $entree->actor_id);
        $this->assertSame('Langage inapproprie signale par un membre', $entree->reason);
    }

    public function test_masquer_puis_demasquer_une_evaluation(): void
    {
        $avis = $this->avis($this->orgA);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.reviews.hide', $avis->id), ['reason' => 'Contenu a verifier'])
            ->assertRedirect();

        $avis->refresh();
        $this->assertNotNull($avis->hidden_at);
        $this->assertFalse($avis->estVisiblePubliquement());

        $this->actingAs($this->superAdmin)
            ->post(route('admin.reviews.unhide', $avis->id), ['reason' => 'Verification faite, rien a signaler'])
            ->assertRedirect();

        $avis->refresh();
        $this->assertNull($avis->hidden_at);
        $this->assertTrue($avis->estVisiblePubliquement());

        $this->assertSame(2, ReviewModerationLog::where('review_id', $avis->id)->count());
    }

    public function test_supprimer_est_reversible_via_soft_delete_et_restaurer_fonctionne(): void
    {
        $avis = $this->avis($this->orgA);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.reviews.destroy', $avis->id), ['reason' => 'Doublon'])
            ->assertRedirect();

        $this->assertSoftDeleted('reviews', ['id' => $avis->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.reviews.restore', $avis->id), ['reason' => 'Erreur de manipulation'])
            ->assertRedirect();

        $this->assertDatabaseHas('reviews', ['id' => $avis->id, 'deleted_at' => null]);

        $actions = ReviewModerationLog::where('review_id', $avis->id)->orderBy('created_at')->pluck('action')->all();
        $this->assertSame([ReviewModerationLog::ACTION_DELETED, ReviewModerationLog::ACTION_RESTORED], $actions);
    }

    public function test_un_non_admin_ne_peut_pas_executer_les_actions_de_moderation(): void
    {
        $avis = $this->avis($this->orgA);

        $this->actingAs($this->membre)
            ->post(route('admin.reviews.hide', $avis->id), ['reason' => 'tentative'])
            ->assertForbidden();

        $avis->refresh();
        $this->assertNull($avis->hidden_at);
    }

    // ── E — la moyenne publique exclut masques et supprimes ─────────────────

    public function test_la_moyenne_publique_exclut_les_avis_masques_et_supprimes(): void
    {
        $aidant = User::factory()->for($this->orgA)->create();

        $this->avis($this->orgA, ['reviewed_id' => $aidant->id, 'rating' => 5]);
        $masque = $this->avis($this->orgA, ['reviewed_id' => $aidant->id, 'rating' => 1, 'hidden_at' => now()]);
        $supprime = $this->avis($this->orgA, ['reviewed_id' => $aidant->id, 'rating' => 1]);
        $supprime->delete();

        $aidant->recalculateRating();

        $this->assertSame(5.0, (float) $aidant->refresh()->rating);
    }

    public function test_une_evaluation_masquee_disparait_du_profil_public(): void
    {
        $aidant = User::factory()->for($this->orgA)->create();
        $this->avis($this->orgA, [
            'reviewed_id' => $aidant->id,
            'comment' => 'T1808_COMMENTAIRE_MASQUE',
            'hidden_at' => now(),
        ]);

        $this->actingAs($this->membre)
            ->get(route('profile.show', $aidant))
            ->assertOk()
            ->assertDontSee('T1808_COMMENTAIRE_MASQUE');
    }
}
