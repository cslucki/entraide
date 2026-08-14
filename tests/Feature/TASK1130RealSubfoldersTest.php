<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les vrais sous-dossiers (`parent_id`), passe 4 de TASK-1130.
 *
 * Avant cette passe, un Dossier n'avait qu'un niveau : racine, point final.
 * `parent_id` ajoute une vraie hierarchie, mais un enfant ne porte ni
 * `owner_id` ni `loop_id` ni `dossier_members` a lui — sa gouvernance se
 * demande a la racine via `governingDossier()`. Ces tests gardent :
 *
 * - un enfant cree depuis le Drive (Boucle ou prive) est un vrai `parent_id`,
 *   sans second holder ;
 * - un enfant herite du role affiche, des droits d'ecriture et du panneau
 *   Partager de sa racine, a n'importe quelle profondeur ;
 * - ajouter un membre depuis le Partager d'un enfant ecrit sur la racine, pas
 *   sur l'enfant — sans quoi la ligne serait invisible a toute policy ;
 * - la garde anti-cycle et la garde tenant refusent avant tout ecrit ;
 * - un Dossier d'avant cette passe (`parent_id` NULL) reste une racine
 *   parfaitement valide.
 */
class TASK1130RealSubfoldersTest extends TestCase
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

    private function creerEnfant(Dossier $parent, string $nom, ?User $acteur = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($acteur ?? $this->owner)->post(
            route('organization.dossiers.store', ['organization' => $this->org->slug]),
            ['name' => $nom, 'parent_id' => $parent->getKey()],
        );
    }

    // ── Creation d'un vrai enfant ────────────────────────────────────────────

    public function test_a_child_created_under_the_loop_root_has_no_second_holder(): void
    {
        $this->creerEnfant($this->racineBoucle, 'Communication')
            ->assertRedirect(route('organization.dossiers.show', [
                'organization' => $this->org->slug, 'dossier' => $this->racineBoucle->getKey(),
            ]));

        $this->assertDatabaseHas('dossiers', [
            'name' => 'Communication',
            'parent_id' => $this->racineBoucle->getKey(),
            'owner_id' => null,
            'loop_id' => null,
        ]);
    }

    public function test_a_child_created_under_a_private_dossier_has_no_second_holder(): void
    {
        $racinePrivee = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Mon espace',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->creerEnfant($racinePrivee, 'Brouillons')
            ->assertRedirect(route('organization.dossiers.show', [
                'organization' => $this->org->slug, 'dossier' => $racinePrivee->getKey(),
            ]));

        $this->assertDatabaseHas('dossiers', [
            'name' => 'Brouillons',
            'parent_id' => $racinePrivee->getKey(),
            'owner_id' => null,
            'loop_id' => null,
        ]);
    }

    public function test_creating_a_child_requires_update_on_the_parent(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        $this->creerEnfant($this->racineBoucle, 'Intrus', $etranger)->assertForbidden();

        $this->assertDatabaseMissing('dossiers', ['name' => 'Intrus']);
    }

    public function test_a_parent_from_another_organization_is_refused(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $parentAilleurs = Dossier::create([
            'organization_id' => $autreOrg->id,
            'owner_id' => User::factory()->create(['organization_id' => $autreOrg->id])->id,
            'name' => 'Ailleurs',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        // Le parent n'existe pas dans le tenant courant : 404, pas une fuite.
        $this->creerEnfant($parentAilleurs, 'Intrusion')->assertNotFound();

        $this->assertDatabaseMissing('dossiers', ['name' => 'Intrusion']);
    }

    // ── Gouvernance a deux niveaux de profondeur ────────────────────────────

    public function test_a_grandchild_still_shows_the_loop_dossier_badge(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(),
            'name' => 'Communication',
        ]);
        $petitEnfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $enfant->getKey(),
            'name' => 'Presse',
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $petitEnfant->getKey()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('dossiers.loop_dossier_badge'), $html);
        // La chaine complete : l'espace « Boucles », puis le Drive, puis les
        // deux niveaux reels.
        $this->assertStringContainsString(__('dossiers.space_loops'), $html);
        $this->assertStringContainsString('Communication', $html);
        $this->assertStringContainsString('Presse', $html);
    }

    public function test_a_plain_loop_member_reads_a_child_but_cannot_write_it(): void
    {
        // Meme regle que sur la racine (TASK-1121) : update() sur un Dossier
        // gouverne par une Boucle demande de pouvoir gerer la Boucle
        // elle-meme, pas seulement d'en etre membre. La profondeur ne change
        // rien a la regle.
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(),
            'name' => 'Communication',
        ]);

        $this->assertTrue($this->membre->can('view', $enfant));
        $this->assertFalse($this->membre->can('manageFiles', $enfant));

        $this->assertTrue($this->owner->can('manageFiles', $enfant));
    }

    public function test_cross_organization_never_leaks_through_a_child(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);

        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(),
            'name' => 'Communication',
        ]);

        app()->instance('current_organization', $autreOrg);

        $this->actingAs($intrus)
            ->get(route('organization.dossiers.show', ['organization' => $autreOrg->slug, 'dossier' => $enfant->getKey()]))
            ->assertNotFound();
    }

    // ── Le panneau Partager d'un enfant ──────────────────────────────────────

    public function test_the_share_panel_of_a_loop_child_lists_the_loop_members(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $this->racineBoucle->getKey(),
            'name' => 'Communication',
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $enfant->getKey()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('dossiers.share_loop_body'), $html);
        $this->assertStringContainsString($this->owner->publicDisplayName(), $html);
        $this->assertStringContainsString($this->membre->publicDisplayName(), $html);
        // Le lien de gestion pointe vers la Boucle qui gouverne, pas vers
        // un dossier — la Boucle reste la seule source de verite.
        $this->assertStringContainsString(
            route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $this->loop->getKey()]),
            $html,
        );
    }

    public function test_the_share_panel_of_a_private_child_targets_that_child(): void
    {
        $racinePrivee = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Mon espace',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $racinePrivee->getKey(),
            'name' => 'Brouillons',
        ]);

        // Ce test exigeait l'id de la RACINE jusqu'a TASK-1136. C'etait la
        // cause de la fuite : le partage atterrissait sur le porteur de
        // gouvernance au lieu du dossier designe. Le panneau vise desormais
        // l'ENFANT ; la policy lit le partage sur lui puis sur ses ancetres,
        // et une racine « Mes documents » n'est jamais une ancre.
        $html = $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $enfant->getKey()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($enfant->getKey(), $html);

        // Preuve par le geste reel : ajouter un membre depuis la route de
        // l'ENFANT atterrit sur l'ENFANT.
        $this->actingAs($this->owner)->postJson(
            route('organization.dossiers.members.store', ['organization' => $this->org->slug, 'dossier' => $enfant->getKey()]),
            ['user_id' => $this->membre->id, 'role' => DossierMember::ROLE_READER],
        )->assertOk();

        $this->assertDatabaseHas('dossier_members', [
            'dossier_id' => $enfant->getKey(),
            'user_id' => $this->membre->id,
        ]);
        $this->assertDatabaseMissing('dossier_members', [
            'dossier_id' => $racinePrivee->getKey(),
        ]);

        // La policy le confirme : l'invite lit l'enfant partage...
        $this->assertTrue($this->membre->fresh()->can('view', $enfant));
        // ...et PAS la racine, qui ne lui a jamais ete confiee.
        $this->assertFalse($this->membre->fresh()->can('view', $racinePrivee->fresh()));
    }

    // ── Garde anti-cycle et compatibilite ────────────────────────────────────

    public function test_a_dossier_may_never_become_its_own_parent(): void
    {
        $dossier = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => $this->owner->id,
            'name' => 'Seul au monde', 'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->expectException(\RuntimeException::class);
        $dossier->assertValidParent($dossier);
    }

    public function test_a_dossier_may_never_move_into_its_own_descendant(): void
    {
        $racine = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => $this->owner->id,
            'name' => 'Racine', 'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $enfant = Dossier::create(['organization_id' => $this->org->id, 'parent_id' => $racine->getKey(), 'name' => 'Enfant']);
        $petitEnfant = Dossier::create(['organization_id' => $this->org->id, 'parent_id' => $enfant->getKey(), 'name' => 'Petit-enfant']);

        $this->expectException(\RuntimeException::class);
        $racine->assertValidParent($petitEnfant);
    }

    public function test_a_pre_existing_root_with_no_parent_id_is_still_a_valid_root(): void
    {
        // Simule un Dossier d'avant la migration : `parent_id` jamais renseigne.
        $ancien = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => $this->owner->id,
            'name' => 'Dossier historique', 'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->assertNull($ancien->parent_id);
        $this->assertTrue($ancien->isRoot());
        $this->assertTrue($ancien->is($ancien->governingDossier()));

        $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $ancien->getKey()]))
            ->assertOk();
    }
}
