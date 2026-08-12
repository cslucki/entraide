<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Le Dossier racine d'une Boucle n'est pas un second espace collaboratif.
 *
 * BOUCLE -> gouvernance ; DOSSIER RACINE -> documents partages de cette
 * Boucle. Le schema portait deja cette doctrine (`owner_id = null`, aucun
 * `dossier_members`, policies deleguees a la Boucle) — mais la couche de
 * lecture la contredisait : role `role_none` en cle brute pour le proprietaire
 * de la Boucle, « Utilisateur desactive » comme Proprietaire, onglet Membres
 * vide, Dossier introuvable dans « Mes dossiers », bouton d'upload cache la
 * ou la policy autorisait.
 */
class TASK1121RootDossierGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $proprietaire;

    private User $membre;

    private User $etranger;

    private Loop $boucle;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);
        $this->orgB = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->etranger = User::factory()->create(['organization_id' => $this->orgB->id]);

        app()->instance('current_organization', $this->orgA);

        $this->boucle = (new LoopService)->createLoop($this->proprietaire, 'Boucle QA 1121')->fresh();
        (new LoopService)->addMemberByUserId($this->boucle, $this->membre->id);

        $this->racine = Dossier::where('loop_id', $this->boucle->id)->firstOrFail();
    }

    private function voirDossier(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->get(route('organization.dossiers.show', [
            'organization' => $this->orgA->slug, 'dossier' => $this->racine->id,
        ]));
    }

    // ── La doctrine du schema, verifiee une fois ────────────────────────────

    public function test_the_root_dossier_is_held_by_the_loop_and_nobody_else(): void
    {
        $this->assertNull($this->racine->owner_id);
        $this->assertSame($this->boucle->id, $this->racine->loop_id);
        $this->assertSame(0, $this->racine->dossierMembers()->count());
        // Plus jamais la valeur historique `shared` sur une creation neuve.
        $this->assertSame(Dossier::VISIBILITY_PRIVATE, $this->racine->visibility);
        $this->assertSame(Dossier::VISIBILITY_LOOP, $this->racine->effectiveVisibility());
    }

    // ── Le role affiche derive de la Boucle ─────────────────────────────────

    public function test_the_loop_owner_is_not_role_none_anymore(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->voirDossier($this->proprietaire)
            ->assertOk()
            // Le mot, jamais la cle brute.
            ->assertDontSee('dossiers.role_none')
            ->assertSee('Propriétaire de la Boucle')
            // Et plus de faux « Utilisateur desactive » : owner_id est null
            // par doctrine, pas un compte supprime.
            ->assertDontSee(__('profile.deactivated_user'));
    }

    public function test_a_loop_member_sees_a_member_role(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->voirDossier($this->membre)
            ->assertOk()
            ->assertDontSee('dossiers.role_none')
            ->assertSee(__('dossiers.role_loop_member'));
    }

    // ── L'onglet Partager represente la Boucle, en lecture seule ────────────
    // TASK-1130 passe 4 : « Membres » est devenu « Partager » — le contenu du
    // panneau (source de verite = la Boucle, en lecture seule) n'a pas change.

    public function test_the_share_panel_lists_the_loop_members(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->voirDossier($this->proprietaire)
            ->assertOk()
            ->assertSee(__('dossiers.share_loop_body'))
            ->assertSee($this->proprietaire->publicDisplayName())
            ->assertSee($this->membre->publicDisplayName());
    }

    public function test_the_members_api_derives_from_the_loop(): void
    {
        $reponse = $this->actingAs($this->proprietaire)->getJson(route('organization.dossiers.members.index', [
            'organization' => $this->orgA->slug, 'dossier' => $this->racine->id,
        ]))->assertOk();

        $this->assertTrue($reponse->json('managed_by_loop'));

        $roles = collect($reponse->json('members'))->pluck('role', 'id');

        $this->assertSame('loop_owner', $roles[$this->proprietaire->id]);
        $this->assertSame('loop_member', $roles[$this->membre->id]);
        // Le proprietaire de la Boucle d'abord : l'ordre est celui des roles.
        $this->assertSame($this->proprietaire->id, $reponse->json('members.0.id'));
    }

    public function test_no_parallel_member_management_on_a_root_dossier(): void
    {
        // La Boucle est la source de verite : l'API de gestion refuse, meme au
        // proprietaire de la Boucle.
        $this->actingAs($this->proprietaire)->postJson(route('organization.dossiers.members.store', [
            'organization' => $this->orgA->slug, 'dossier' => $this->racine->id,
        ]), ['user_id' => $this->membre->id, 'role' => 'editor'])
            ->assertForbidden();

        $this->assertSame(0, $this->racine->dossierMembers()->count());
    }

    // ── « Mes dossiers » retrouve le Dossier racine ─────────────────────────

    public function test_a_loop_member_finds_the_root_dossier_in_the_index(): void
    {
        $this->withSession(['locale' => 'fr']);

        // TASK-1130 (decision finale) : le module a trois espaces, et les
        // Drives de Boucle vivent dans « Boucles » — une VUE, jamais un
        // Dossier physique. La ligne parle de la Boucle : son nom, mon role,
        // et une sortie explicite vers elle.
        $this->actingAs($this->membre)
            ->get(route('organization.dossiers.index', ['organization' => $this->orgA->slug, 'espace' => 'boucles']))
            ->assertOk()
            ->assertSee(e($this->boucle->name), false)
            ->assertSee(e(__('dossiers.space_loops')), false)
            ->assertSee(e(__('dossiers.loop_visit')), false)
            // Le nom de la Boucle dit le detenteur — plus de proprietaire fantome.
            ->assertDontSee(__('profile.deactivated_user'));
    }

    public function test_a_non_member_does_not_find_it_in_the_index(): void
    {
        $sansBoucle = User::factory()->create(['organization_id' => $this->orgA->id]);

        $this->actingAs($sansBoucle)
            ->get(route('organization.dossiers.index', ['organization' => $this->orgA->slug, 'espace' => 'boucles']))
            ->assertOk()
            ->assertDontSee(e($this->boucle->name), false);
    }

    public function test_the_index_never_crosses_the_organization(): void
    {
        // Une Boucle et son Dossier chez B, portes par un membre de B qui
        // porte le meme nom de Dossier : la donnee etrangere est réellement
        // candidate si le tenant fuit.
        app()->instance('current_organization', $this->orgB);
        $boucleB = (new LoopService)->createLoop($this->etranger, 'Boucle QA 1121');
        app()->instance('current_organization', $this->orgA);

        $this->actingAs($this->membre)
            ->get(route('organization.dossiers.index', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertDontSee($boucleB->fresh()->name.' — B');

        $racineB = Dossier::where('loop_id', $boucleB->id)->firstOrFail();

        // Et l'acces direct au Dossier de B refuse aussi.
        $this->actingAs($this->membre)->get(route('organization.dossiers.show', [
            'organization' => $this->orgA->slug, 'dossier' => $racineB->id,
        ]))->assertNotFound();
    }

    // ── Les capacites viennent des policies ─────────────────────────────────

    public function test_the_loop_owner_can_upload_a_file_to_the_root_dossier(): void
    {
        Storage::fake('dossier_files');

        // La policy autorisait deja (manageFiles -> update -> Boucle) ; c'est
        // l'ecran qui cachait le bouton. Le POST prouve la chaine entiere.
        $this->actingAs($this->proprietaire)->post(route('organization.dossiers.files.store', [
            'organization' => $this->orgA->slug, 'dossier' => $this->racine->id,
        ]), ['files' => [UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')]])
            ->assertSuccessful();

        $this->assertSame(1, $this->racine->files()->count());
    }

    public function test_a_simple_member_cannot_upload(): void
    {
        Storage::fake('dossier_files');

        // Les droits actuels de la Boucle : update() refuse un simple membre,
        // le Dossier racine suit.
        $this->actingAs($this->membre)->post(route('organization.dossiers.files.store', [
            'organization' => $this->orgA->slug, 'dossier' => $this->racine->id,
        ]), ['files' => [UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')]])
            ->assertForbidden();
    }

    public function test_an_archived_loop_makes_the_root_dossier_read_only(): void
    {
        Storage::fake('dossier_files');

        $this->boucle->forceFill(['status' => 'archived'])->save();

        $this->actingAs($this->proprietaire)->post(route('organization.dossiers.files.store', [
            'organization' => $this->orgA->slug, 'dossier' => $this->racine->id,
        ]), ['files' => [UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')]])
            ->assertForbidden();
    }

    public function test_the_loop_owner_can_attach_an_article(): void
    {
        // Avant : attachArticle ne connaissait pas les Dossiers racines et
        // refusait tout le monde, proprietaire de la Boucle compris.
        $this->assertTrue($this->proprietaire->can('attachArticle', $this->racine));
        $this->assertTrue($this->proprietaire->can('deleteFile', $this->racine));
        $this->assertFalse($this->membre->can('attachArticle', $this->racine));
        $this->assertFalse($this->proprietaire->can('manageMembers', $this->racine));
        $this->assertFalse($this->proprietaire->can('delete', $this->racine));
    }

    // ── Les Dossiers personnels ne bougent pas ──────────────────────────────

    public function test_a_personal_dossier_keeps_its_own_rules(): void
    {
        $this->withSession(['locale' => 'fr']);

        $personnel = Dossier::create([
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Dossier personnel QA',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);

        // Le proprietaire garde son role et sa gestion des membres.
        $this->assertTrue($this->proprietaire->can('manageMembers', $personnel));

        // Un visiteur par visibilite Organization a un role lisible — la cle
        // `role_none` existe desormais au lieu de s'afficher brute.
        $this->actingAs($this->membre)->get(route('organization.dossiers.show', [
            'organization' => $this->orgA->slug, 'dossier' => $personnel->id,
        ]))
            ->assertOk()
            ->assertDontSee('dossiers.role_none')
            ->assertSee(__('dossiers.role_none'));
    }
}
