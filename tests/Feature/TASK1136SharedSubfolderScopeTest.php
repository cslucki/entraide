<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\PersonalDocumentsRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le partage d'un sous-dossier personnel ne partage QUE son sous-arbre.
 *
 * ## La fuite
 *
 * Partager « Dossier test 3 », enfant de « Mes documents », ecrivait la ligne
 * `dossier_members` sur la **racine** — parce que `isMember()`, `isEditor()` et
 * `isReader()` remontent a `governingDossier()` et lisent les membres la-haut.
 * Le membre invite sur UN dossier voyait donc l'espace personnel entier :
 * 17 sous-dossiers, 50 fichiers, 7 Articles, mesures sur le banc de Cyril.
 *
 * La policy portait pourtant la regle en clair : « inviter quelqu'un dans
 * Mes documents reviendrait a partager tout l'espace personnel d'un coup ».
 * Le refus qui protegeait a ete leve par TASK-1131 sans que la consequence
 * soit vue.
 *
 * ## La doctrine que ces tests gardent
 *
 * - la gouvernance (`governingDossier()`) et le **partage** sont deux notions
 *   distinctes ; la premiere ne doit plus servir de perimetre a la seconde ;
 * - le membership s'ecrit sur le dossier **cible** ;
 * - l'acces se lit sur la chaine des **ancetres** : un descendant herite du
 *   partage de ses ancetres, jamais du governing root ;
 * - une racine `personal_documents` n'est **jamais** un point d'ancrage ;
 * - les Dossiers de Boucle et les racines privees ordinaires ne changent pas.
 */
class TASK1136SharedSubfolderScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $proprietaire;

    private User $invite;

    private Dossier $racine;

    /** Le dossier explicitement partage. */
    private Dossier $partage;

    /** Un descendant du dossier partage. */
    private Dossier $descendant;

    /** Un frere, jamais partage. */
    private Dossier $frere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->org->id]);
        $this->invite = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->racine = app(PersonalDocumentsRoot::class)->resolve($this->org->id, $this->proprietaire->id);
        $this->partage = $this->sousDossier($this->racine, 'Dossier test 3');
        $this->descendant = $this->sousDossier($this->partage, 'Sous-dossier du partage');
        $this->frere = $this->sousDossier($this->racine, 'Test Cyril');
    }

    private function sousDossier(Dossier $parent, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $parent->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    /** Le geste reel : Admin partage un sous-dossier avec quelqu'un. */
    private function partagerAvecLInvite(?Dossier $cible = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->proprietaire)->postJson(
            route('organization.dossiers.members.store', [
                'organization' => $this->org->slug,
                'dossier' => ($cible ?? $this->partage)->getKey(),
            ]),
            ['user_id' => $this->invite->getKey(), 'role' => 'editor'],
        );
    }

    /** La route `members.destroy` designe le membre par son `user_id`. */
    private function retirerLePartage(Dossier $cible): void
    {
        $this->actingAs($this->proprietaire)->deleteJson(
            route('organization.dossiers.members.destroy', [
                'organization' => $this->org->slug,
                'dossier' => $cible->getKey(),
                'member' => $this->invite->getKey(),
            ])
        )->assertSuccessful();
    }

    private function fichier(Dossier $dossier, string $nom): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.\Illuminate\Support\Str::random(12).'.txt',
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $dossier->id.$nom),
            'source' => 'upload',
        ]);
    }

    private function article(Dossier $dossier, string $titre): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->proprietaire->id,
            'title' => $titre,
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->proprietaire->id,
            'position' => 1,
        ]);

        return $post;
    }

    // ── A. Le membership atterrit sur le bon dossier ─────────────────────────

    public function test_a_membership_lands_on_the_shared_folder_never_on_the_root(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();

        $this->assertDatabaseHas('dossier_members', [
            'dossier_id' => $this->partage->getKey(),
            'user_id' => $this->invite->getKey(),
        ]);
        $this->assertSame(
            0,
            DossierMember::where('dossier_id', $this->racine->getKey())->count(),
            'Aucun membre ne doit etre pose sur « Mes documents » : ce serait partager tout l’espace personnel.',
        );
    }

    // ── B / C. Ce que le partage donne ───────────────────────────────────────

    public function test_b_the_guest_sees_the_shared_folder(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();

        $this->assertTrue($this->invite->can('view', $this->partage->fresh()));
    }

    public function test_c_the_guest_sees_a_descendant_of_the_shared_folder(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();

        // Un descendant herite du partage de ses ANCETRES.
        $this->assertTrue($this->invite->can('view', $this->descendant->fresh()));
    }

    // ── D / E / F. Ce que le partage ne donne PAS ────────────────────────────

    public function test_d_the_guest_never_sees_the_personal_root(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();

        $this->assertFalse($this->invite->can('view', $this->racine->fresh()));
        $this->assertFalse($this->invite->can('viewFiles', $this->racine->fresh()));
    }

    public function test_e_the_guest_never_sees_a_sibling(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();

        $this->assertFalse($this->invite->can('view', $this->frere->fresh()));
    }

    public function test_f_the_guest_never_reaches_a_sibling_content(): void
    {
        $this->fichier($this->frere, 'prive.txt');
        $this->article($this->frere, 'Note privee');
        $this->partagerAvecLInvite()->assertSuccessful();

        $this->assertFalse($this->invite->can('viewFiles', $this->frere->fresh()));

        // Et par l'URL directe, pas seulement par la policy.
        $this->actingAs($this->invite)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug,
                'dossier' => $this->frere->getKey(),
            ]))
            ->assertForbidden();
    }

    // ── G. L'espace « Partages » ─────────────────────────────────────────────

    public function test_g_the_shared_space_lists_only_the_shared_folder(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();

        $html = $this->actingAs($this->invite)
            ->get(route('organization.dossiers.index', ['organization' => $this->org->slug, 'espace' => 'partages']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Dossier test 3', $html);
        $this->assertStringNotContainsString('Test Cyril', $html, 'Un frere jamais partage ne doit pas apparaitre.');
        // « Mes documents » est le nom de l'espace du visiteur lui-meme : on
        // verifie l'identite du dossier, pas un libelle qui existe partout.
        $this->assertStringNotContainsString($this->racine->getKey(), $html, 'La racine personnelle d’autrui ne doit jamais etre listee.');
    }

    // ── H / I. Retirer le partage ────────────────────────────────────────────

    public function test_h_removing_the_share_cuts_the_whole_subtree(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();
        $this->retirerLePartage($this->partage);

        $this->assertFalse($this->invite->can('view', $this->partage->fresh()));
        $this->assertFalse($this->invite->can('view', $this->descendant->fresh()));
    }

    public function test_i_an_explicit_share_on_a_descendant_survives(): void
    {
        $this->partagerAvecLInvite()->assertSuccessful();
        $this->partagerAvecLInvite($this->descendant)->assertSuccessful();

        $this->retirerLePartage($this->partage);

        // Le partage du parent est retire, celui du descendant demeure.
        $this->assertFalse($this->invite->can('view', $this->partage->fresh()));
        $this->assertTrue($this->invite->can('view', $this->descendant->fresh()));
    }

    // ── J / K. Frontieres ────────────────────────────────────────────────────

    public function test_j_cross_organization_is_a_404(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);
        app()->instance('current_organization', $this->autreOrg);

        $this->actingAs($etranger)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->autreOrg->slug,
                'dossier' => $this->partage->getKey(),
            ]))
            ->assertNotFound();
    }

    public function test_k_the_personal_root_can_never_be_shared(): void
    {
        $this->partagerAvecLInvite($this->racine)->assertForbidden();

        $this->assertSame(0, DossierMember::where('dossier_id', $this->racine->getKey())->count());
    }

    // ── L. Non-regression : les Boucles et les racines privees ordinaires ────

    public function test_l_a_loop_dossier_keeps_its_behaviour(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->proprietaire->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->proprietaire->id, 'joined_at' => now(),
        ]);
        LoopMember::factory()->create([
            'loop_id' => $loop->id, 'user_id' => $this->invite->id, 'role' => 'member', 'joined_at' => now(),
        ]);

        $racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'loop_id' => $loop->id,
        ]);
        $enfantBoucle = $this->sousDossier($racineBoucle, 'Communication');

        // Un membre de la Boucle lit la racine ET l'enfant, sans aucun
        // `dossier_members` : la gouvernance de Boucle est inchangee.
        $this->assertTrue($this->invite->can('view', $racineBoucle->fresh()));
        $this->assertTrue($this->invite->can('view', $enfantBoucle->fresh()));
    }

    public function test_l_bis_a_plain_private_root_still_shares_normally(): void
    {
        // Les Dossiers d'avant « Mes documents » sont des racines a part
        // entiere : les partager doit continuer de fonctionner tel quel.
        $racineOrdinaire = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Dossier historique',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $enfant = $this->sousDossier($racineOrdinaire, 'Brouillons');

        $this->partagerAvecLInvite($racineOrdinaire)->assertSuccessful();

        $this->assertDatabaseHas('dossier_members', [
            'dossier_id' => $racineOrdinaire->getKey(),
            'user_id' => $this->invite->getKey(),
        ]);
        $this->assertTrue($this->invite->can('view', $racineOrdinaire->fresh()));
        $this->assertTrue($this->invite->can('view', $enfant->fresh()), 'Un enfant herite du partage de sa racine.');
    }
}
