<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supprimer un Dossier — CAS A contre CAS B (TASK-1130 passe 4).
 *
 * CAS A : le Dossier est reellement possede ici (un vrai sous-dossier, ou un
 * Dossier personnel racine) — "Supprimer" est le geste attendu, borne par la
 * meme policy que partout ailleurs.
 *
 * CAS B : le Dossier vit ailleurs — l'espace personnel de son proprietaire —
 * et n'est que **partage** avec cette Boucle (`shared_with_loop_id`). Le
 * retirer de la Boucle (`unshare`) et le supprimer definitivement sont deux
 * gestes distincts, jamais confondus sous un seul bouton, et tous deux
 * reserves au proprietaire reel.
 *
 * Ce que ces tests refusent explicitement de faire, faute de sémantique
 * bornee dans le mandat : laisser un facilitateur de Boucle retirer ou
 * supprimer le Dossier personnel de quelqu'un d'autre. Cette autorite
 * n'existe pas ici — voir le rapport de checkpoint.
 */
class TASK1130DossierDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $membre;

    private Loop $loop;

    private Dossier $racineBoucle;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->owner->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->owner->id, 'joined_at' => now(),
        ]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->membre->id, 'role' => 'member', 'joined_at' => now(),
        ]);

        $this->racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'loop_id' => $this->loop->id,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function destroyRoute(Dossier $dossier): string
    {
        return route('organization.dossiers.destroy', ['organization' => $this->org->slug, 'dossier' => $dossier->getKey()]);
    }

    private function unshareRoute(Dossier $dossier): string
    {
        return route('organization.dossiers.unshare', ['organization' => $this->org->slug, 'dossier' => $dossier->getKey()]);
    }

    // ── CAS A : un vrai sous-dossier, reellement possede ici ────────────────

    public function test_the_loop_owner_can_delete_a_real_child_of_the_loop_root(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($enfant))->assertOk();

        $this->assertSoftDeleted('dossiers', ['id' => $enfant->getKey()]);
    }

    public function test_a_plain_loop_member_cannot_delete_a_child_of_the_loop_root(): void
    {
        // Meme regle que manageFiles : etre membre ne suffit pas, il faut
        // pouvoir administrer la Boucle (owner ou facilitateur).
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);

        $this->actingAs($this->membre)->delete($this->destroyRoute($enfant))->assertStatus(403);

        $this->assertDatabaseHas('dossiers', ['id' => $enfant->getKey(), 'deleted_at' => null]);
    }

    public function test_the_loop_root_itself_can_never_be_deleted_from_here(): void
    {
        // Detruire le Dossier racine emporterait tout l'espace documentaire
        // de la Boucle — une decision de cycle de vie de la Boucle, hors de
        // portee d'un geste Dossier. Refuse meme au proprietaire de la Boucle.
        $this->actingAs($this->owner)->delete($this->destroyRoute($this->racineBoucle))->assertStatus(403);

        $this->assertDatabaseHas('dossiers', ['id' => $this->racineBoucle->getKey(), 'deleted_at' => null]);
    }

    public function test_a_folder_with_an_active_child_refuses_deletion_without_touching_it(): void
    {
        // TASK-1130 (etape A) : plus de promotion automatique. Avant cette
        // regle, cette meme situation ecrivait sur PostgreSQL une ligne
        // parent_id=NULL, owner_id=NULL, loop_id=NULL — rejetee par la
        // contrainte dossiers_holder_xor (confirme empiriquement sur
        // bouclepro_test, SQLSTATE 23514). Le Dossier non vide doit
        // desormais refuser la suppression, sans rien deplacer.
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);
        $petitEnfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $enfant->getKey(), 'name' => 'Presse',
        ]);

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($enfant))->assertStatus(422);

        $this->assertDatabaseHas('dossiers', ['id' => $enfant->getKey(), 'parent_id' => $this->racineBoucle->getKey(), 'deleted_at' => null]);
        // Aucune promotion : le petit-enfant reste attache a son parent reel.
        $this->assertDatabaseHas('dossiers', ['id' => $petitEnfant->getKey(), 'parent_id' => $enfant->getKey(), 'deleted_at' => null]);
    }

    public function test_a_folder_with_a_file_refuses_deletion(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);
        $fichier = \App\Models\DossierFile::factory()->create([
            'organization_id' => $this->org->id, 'dossier_id' => $enfant->getKey(), 'uploaded_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)->deleteJson($this->destroyRoute($enfant));

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('dossiers.delete_not_empty'));
        $this->assertDatabaseHas('dossiers', ['id' => $enfant->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseHas('dossier_files', ['id' => $fichier->getKey(), 'dossier_id' => $enfant->getKey(), 'deleted_at' => null]);
    }

    public function test_a_folder_with_an_attached_article_refuses_deletion(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);
        $article = \App\Models\BlogPost::create([
            'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
            'title' => 'Compte rendu', 'content' => 'Contenu.', 'status' => 'draft',
        ]);
        \App\Models\DossierBlogPost::create([
            'organization_id' => $this->org->id, 'dossier_id' => $enfant->getKey(), 'blog_post_id' => $article->getKey(),
            'added_by' => $this->owner->id, 'position' => 0,
        ]);

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($enfant))->assertStatus(422);

        $this->assertDatabaseHas('dossiers', ['id' => $enfant->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseHas('dossier_blog_posts', ['dossier_id' => $enfant->getKey(), 'blog_post_id' => $article->getKey()]);
    }

    public function test_an_empty_folder_still_deletes_normally(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($enfant))->assertOk();

        $this->assertSoftDeleted('dossiers', ['id' => $enfant->getKey()]);
    }

    public function test_an_empty_folder_with_a_content_less_series_still_deletes_and_dissolves_it(): void
    {
        // Une Serie ne peut avoir de racine ou d'item que sur un Article/
        // fichier deja attache au Dossier (DossierSeriesService::
        // assertBelongsToDossier) : sur un Dossier sans fichier ni Article,
        // seule une Serie "coquille vide" (nom seul) peut exister. La
        // dissoudre ne perd aucun contenu.
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);
        $serie = \App\Models\ArticleSeries::create([
            'organization_id' => $this->org->id, 'dossier_id' => $enfant->getKey(),
            'root_blog_post_id' => null, 'name' => 'Serie vide', 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($enfant))->assertOk();

        $this->assertSoftDeleted('dossiers', ['id' => $enfant->getKey()]);
        $this->assertDatabaseMissing('article_series', ['id' => $serie->getKey()]);
    }

    public function test_a_private_owner_can_delete_a_real_child_of_their_own_dossier(): void
    {
        $racinePrivee = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => $this->owner->id,
            'name' => 'Mon espace', 'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $enfant = Dossier::create(['organization_id' => $this->org->id, 'parent_id' => $racinePrivee->getKey(), 'name' => 'Brouillons']);

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($enfant))->assertOk();

        $this->assertSoftDeleted('dossiers', ['id' => $enfant->getKey()]);
    }

    public function test_cross_organization_cannot_delete_a_child(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(), 'name' => 'Communication',
        ]);

        $this->actingAs($intrus)->deleteJson(route('organization.dossiers.destroy', [
            'organization' => $autreOrg->slug, 'dossier' => $enfant->getKey(),
        ]))->assertNotFound();
    }

    // ── CAS B : ce Dossier vit ailleurs, seulement partage ici ──────────────

    private function dossierPartage(string $nom = 'Projet Marseille'): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);
    }

    public function test_the_real_owner_can_unshare_their_own_dossier_without_deleting_it(): void
    {
        $partage = $this->dossierPartage();

        $this->actingAs($this->owner)->patch($this->unshareRoute($partage))->assertRedirect();

        $this->assertDatabaseHas('dossiers', [
            'id' => $partage->getKey(),
            'shared_with_loop_id' => null,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
            'deleted_at' => null,
        ]);
    }

    public function test_the_real_owner_can_unshare_their_dossier_even_when_it_has_content(): void
    {
        // "Retirer de cette Boucle" est un partage, pas une suppression : la
        // regle "vide obligatoire" ne doit jamais s'y appliquer.
        $partage = $this->dossierPartage();
        \App\Models\DossierFile::create([
            'organization_id' => $this->org->id, 'dossier_id' => $partage->getKey(), 'uploaded_by' => $this->owner->id,
            'disk' => 'local', 'path' => 'dossiers/x/y.pdf', 'original_name' => 'y.pdf', 'display_name' => 'y.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'checksum_sha256' => hash('sha256', 'y'), 'source' => 'upload',
        ]);

        $this->actingAs($this->owner)->patch($this->unshareRoute($partage))->assertRedirect();

        $this->assertDatabaseHas('dossiers', [
            'id' => $partage->getKey(), 'shared_with_loop_id' => null,
            'visibility' => Dossier::VISIBILITY_PRIVATE, 'deleted_at' => null,
        ]);
    }

    public function test_the_real_owner_can_still_delete_their_shared_dossier_definitively(): void
    {
        $partage = $this->dossierPartage();

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($partage))->assertOk();

        $this->assertSoftDeleted('dossiers', ['id' => $partage->getKey()]);
    }

    public function test_the_real_owner_cannot_delete_their_shared_dossier_definitively_when_it_has_content(): void
    {
        $partage = $this->dossierPartage();
        \App\Models\DossierFile::create([
            'organization_id' => $this->org->id, 'dossier_id' => $partage->getKey(), 'uploaded_by' => $this->owner->id,
            'disk' => 'local', 'path' => 'dossiers/x/z.pdf', 'original_name' => 'z.pdf', 'display_name' => 'z.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'checksum_sha256' => hash('sha256', 'z'), 'source' => 'upload',
        ]);

        $this->actingAs($this->owner)->deleteJson($this->destroyRoute($partage))->assertStatus(422);

        $this->assertDatabaseHas('dossiers', ['id' => $partage->getKey(), 'deleted_at' => null]);
    }

    public function test_the_loop_owner_cannot_unshare_someone_elses_personal_dossier(): void
    {
        // Le Dossier appartient a $this->owner, partage avec la Boucle dont
        // $this->membre n'est qu'un membre simple ici : aucune des deux
        // portes (updateVisibility, delete) ne doit ceder.
        $partage = $this->dossierPartage();

        $this->actingAs($this->membre)->patchJson($this->unshareRoute($partage))->assertStatus(403);
        $this->actingAs($this->membre)->deleteJson($this->destroyRoute($partage))->assertStatus(403);

        $this->assertDatabaseHas('dossiers', [
            'id' => $partage->getKey(), 'shared_with_loop_id' => $this->loop->getKey(), 'deleted_at' => null,
        ]);
    }

    public function test_cross_organization_cannot_unshare_or_delete(): void
    {
        $partage = $this->dossierPartage();
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($intrus)->patchJson(route('organization.dossiers.unshare', [
            'organization' => $autreOrg->slug, 'dossier' => $partage->getKey(),
        ]))->assertNotFound();
        $this->actingAs($intrus)->deleteJson(route('organization.dossiers.destroy', [
            'organization' => $autreOrg->slug, 'dossier' => $partage->getKey(),
        ]))->assertNotFound();
    }
}
