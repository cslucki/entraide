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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ce qui est partage doit SE VOIR — sans mentir sur ce qu'il est.
 *
 * ## Les trois mensonges corriges
 *
 * 1. Dans « Mes documents », l'etat de partage d'une ligne se lisait sur la
 *    seule racine gouvernante. « Mes documents » n'etant jamais une ancre de
 *    partage, elle n'a jamais de membre : un sous-dossier explicitement
 *    partage s'affichait donc « Prive » a son propre proprietaire.
 * 2. En ouvrant un Dossier recu par partage, tout son contenu heritait du meme
 *    calcul et s'affichait « Prive » — alors que le lecteur le lisait
 *    precisement parce qu'il etait partage.
 * 3. La colonne « Partage » ne disait rien d'utile dans un Dossier deja recu
 *    par partage, ou la vraie question est QUI a depose quoi.
 *
 * ## La semantique que ces tests fixent
 *
 * - « Partage » : la ligne porte une ancre EXPLICITE (des membres, ou une
 *   Boucle) ;
 * - « Acces herite » : la ligne tient son acces d'une ancre situee ailleurs
 *   sur sa chaine — jamais presente comme un partage direct ;
 * - « Prive » : aucune ancre nulle part ;
 * - « Ajoute par » et non « Proprietaire » pour les items : le modele ne
 *   connait que `dossier_files.uploaded_by` et `dossier_blog_posts.added_by`,
 *   deux depots, pas deux proprietes. Un Dossier, lui, a un vrai proprietaire.
 *
 * Aucun `dossier_members` n'est ecrit pour l'affichage.
 */
class TASK1143SharedContentReadabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $m3;

    private User $m4;

    private Dossier $racine;

    /** L'ancre : « Dossier de main member 3 », partage avec m4. */
    private Dossier $a;

    /** Un descendant de A : acces herite, aucun membership propre. */
    private Dossier $b;

    /** Un frere de A, jamais partage. */
    private Dossier $frere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->m3 = User::factory()->create(['organization_id' => $this->org->id]);
        $this->m4 = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->racine = app(PersonalDocumentsRoot::class)->resolve($this->org->id, $this->m3->id);
        $this->a = $this->sousDossier($this->racine, 'Dossier de main member 3');
        $this->b = $this->sousDossier($this->a, 'Sous-dossier herite');
        $this->frere = $this->sousDossier($this->racine, 'Jamais partage');
    }

    private function sousDossier(Dossier $parent, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $parent->organization_id,
            'parent_id' => $parent->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function partager(Dossier $cible, User $invite, string $role = DossierMember::ROLE_READER): DossierMember
    {
        return DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $cible->id,
            'user_id' => $invite->id,
            'role' => $role,
            'added_by' => $this->m3->id,
        ]);
    }

    private function fichier(Dossier $dossier, string $nom, User $deposant): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $deposant->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.Str::random(12).'.txt',
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $dossier->id.$nom),
            'source' => 'upload',
        ]);
    }

    private function article(Dossier $dossier, string $titre, User $auteur, User $deposant): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $auteur->id,
            'title' => $titre,
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $deposant->id,
        ]);

        return $post;
    }

    private function ouvrir(User $acteur, Dossier $dossier): TestResponse
    {
        return $this->actingAs($acteur)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => $dossier->getKey(),
        ]));
    }

    private function mesDocuments(User $acteur): TestResponse
    {
        return $this->actingAs($acteur)->get(route('organization.dossiers.index', [
            'organization' => $this->org->slug,
        ]));
    }

    // =====================================================================
    // 1 et 2 — dans « Mes documents », une ancre se voit et n'est jamais privee
    // =====================================================================

    public function test_an_explicitly_shared_child_is_shown_as_shared_in_my_documents(): void
    {
        $this->partager($this->a, $this->m4);

        $reponse = $this->mesDocuments($this->m3)->assertOk();

        $reponse->assertSee(__('dossiers.share_shared'));
        // Le marqueur visuel de la ligne, lisible aussi en mobile ou la
        // colonne est masquee.
        $reponse->assertSee(__('dossiers.share_shared_badge'));
    }

    public function test_an_explicit_anchor_is_never_labelled_private(): void
    {
        $this->partager($this->a, $this->m4);

        $lignes = $this->mesDocuments($this->m3)->assertOk()->viewData('driveFolders');
        $ancre = $lignes->firstWhere('id', $this->a->id);

        // La ligne connait desormais SON partage : sans ce compte, la vue
        // retombait sur l'etat de « Mes documents », donc « Prive ».
        $this->assertSame(1, $ancre->dossier_members_count);
    }

    // =====================================================================
    // 3 — un descendant herite, il n'est jamais presente comme partage direct
    // =====================================================================

    public function test_an_inherited_descendant_is_not_presented_as_a_direct_share(): void
    {
        $this->partager($this->a, $this->m4);

        // Vu depuis A : B n'a aucun membership propre.
        $lignes = $this->ouvrir($this->m3, $this->a)->assertOk()->viewData('driveFolders');
        $descendant = $lignes->firstWhere('id', $this->b->id);

        $this->assertSame(0, $descendant->dossier_members_count);
        $this->assertSame(0, $this->b->dossierMembers()->count());

        // Et la surface dit « herite », pas « partage ».
        $this->ouvrir($this->m3, $this->a)->assertOk()->assertSee(__('dossiers.share_inherited'));
    }

    public function test_a_never_shared_sibling_stays_private(): void
    {
        $this->partager($this->a, $this->m4);

        $reponse = $this->ouvrir($this->m3, $this->frere)->assertOk();

        // Le frere ne tient son acces d'aucune ancre : il reste prive, et le
        // correctif ne repeint pas tout l'espace en « partage ».
        $this->assertFalse($reponse->viewData('couvertParUnPartage'));
        $reponse->assertSee(__('dossiers.share_private'));
    }

    // =====================================================================
    // 4 — le lecteur voit un acces, jamais « Prive »
    // =====================================================================

    public function test_the_reader_never_sees_private_inside_a_shared_dossier(): void
    {
        $this->partager($this->a, $this->m4);
        $this->fichier($this->a, 'Rapport.pdf', $this->m3);
        $this->article($this->a, 'Note de synthese', $this->m3, $this->m3);

        $reponse = $this->ouvrir($this->m4, $this->a)->assertOk();

        $this->assertTrue($reponse->viewData('couvertParUnPartage'));
        $this->assertTrue($reponse->viewData('isSharedSurface'));
        $reponse->assertDontSee(__('dossiers.share_private'));
    }

    public function test_the_reader_sees_an_access_on_a_descendant_too(): void
    {
        $this->partager($this->a, $this->m4);
        $this->fichier($this->b, 'Dans le descendant.pdf', $this->m3);

        $reponse = $this->ouvrir($this->m4, $this->b)->assertOk();

        // Le descendant est couvert par l'ancre A, sans ligne a lui.
        $this->assertTrue($reponse->viewData('couvertParUnPartage'));
        $this->assertSame(0, $this->b->dossierMembers()->count());
        $reponse->assertDontSee(__('dossiers.share_private'));
    }

    // =====================================================================
    // 5 — « Ajoute par » : la colonne remplace « Partage » chez le lecteur
    // =====================================================================

    /**
     * Les en-tetes se lisent en EN : « Partage » est une sous-chaine de
     * « Partage », « Partages » et « Partager », qui couvrent la page entiere,
     * et « Ajoute par » est aussi la valeur FR de `file_uploaded_by`. En EN les
     * trois libelles — Sharing, Added by, Owner — sont sans collision.
     */
    private function enTetesDeColonnes(string $html): array
    {
        preg_match_all('/<div>([^<]+)<\\/div>/', $html, $m);

        return array_map('trim', $m[1]);
    }

    public function test_the_reader_gets_an_added_by_column_instead_of_sharing(): void
    {
        // `SetLocale` relit la locale dans la session a chaque requete :
        // la fixer sur l'application seule serait ecrase par le middleware.
        $this->withSession(['locale' => 'en']);

        $this->partager($this->a, $this->m4, DossierMember::ROLE_EDITOR);
        // m4 depose lui-meme un fichier et range un Article ecrit par m3 :
        // c'est exactement le cas ou « qui a mis ca ici » devient la question.
        $this->fichier($this->a, 'Depose par m4.pdf', $this->m4);
        $this->article($this->a, 'Ecrit par m3, range par m4', $this->m3, $this->m4);

        $reponse = $this->ouvrir($this->m4, $this->a)->assertOk();
        $enTetes = $this->enTetesDeColonnes($reponse->getContent());

        $this->assertTrue($reponse->viewData('isSharedSurface'));
        $this->assertContains('Added by', $enTetes);
        $this->assertNotContains('Sharing', $enTetes);
    }

    public function test_the_owner_keeps_the_sharing_column_in_their_own_drive(): void
    {
        $this->withSession(['locale' => 'en']);

        $this->partager($this->a, $this->m4);

        $reponse = $this->ouvrir($this->m3, $this->a)->assertOk();
        $enTetes = $this->enTetesDeColonnes($reponse->getContent());

        // m3 gouverne : chez lui la question reste « qu'est-ce qui est
        // partage », pas « qui a depose ».
        $this->assertFalse($reponse->viewData('isSharedSurface'));
        $this->assertContains('Sharing', $enTetes);
        $this->assertNotContains('Added by', $enTetes);
    }

    /**
     * La donnee affichee sous « Ajoute par » pour un Article est bien le
     * deposant du lien, pas l'auteur du contenu.
     */
    public function test_the_added_by_value_of_an_article_is_the_filer_not_the_author(): void
    {
        $this->partager($this->a, $this->m4, DossierMember::ROLE_EDITOR);
        $this->article($this->a, 'Ecrit par m3, range par m4', $this->m3, $this->m4);

        $lien = DossierBlogPost::where('dossier_id', $this->a->id)->firstOrFail();

        $this->assertSame($this->m4->id, $lien->added_by);
        $this->assertSame($this->m3->id, $lien->blogPost->user_id);

        // La relation que la vue consulte existe et pointe le deposant.
        $this->assertSame($this->m4->id, $lien->addedBy->id);
    }

    // =====================================================================
    // 6 — les deux sous-vues ont deux icones distinctes
    // =====================================================================

    public function test_shared_with_me_and_shared_by_me_have_two_distinct_icons(): void
    {
        $this->partager($this->a, $this->m4);

        $html = $this->actingAs($this->m3)->get(route('organization.dossiers.index', [
            'organization' => $this->org->slug,
            'espace' => 'partages',
        ]))->assertOk()->getContent();

        // Deux traces SVG differentes, une par sous-vue : entrant et sortant.
        $this->assertStringContainsString('M12 3.75v12m0 0 4.5-4.5M12 15.75l-4.5-4.5M4.5 19.5h15', $html);
        $this->assertStringContainsString('M12 20.25V8.25m0 0 4.5 4.5M12 8.25l-4.5 4.5M4.5 4.5h15', $html);
    }

    // =====================================================================
    // 7 — FR / EN
    // =====================================================================

    public function test_the_new_labels_exist_in_both_locales(): void
    {
        foreach (['fr' => ['Accès hérité', 'Ajouté par'], 'en' => ['Inherited access', 'Added by']] as $locale => $attendus) {
            app()->setLocale($locale);

            $this->assertSame($attendus[0], __('dossiers.share_inherited'));
            $this->assertSame($attendus[1], __('dossiers.col_added_by'));
            // La cle du marqueur ne doit pas rester brute dans une des langues.
            $this->assertNotSame('dossiers.share_shared_badge', __('dossiers.share_shared_badge'));
        }
    }

    // =====================================================================
    // 9 — aucune fuite inter-Organization
    // =====================================================================

    public function test_no_cross_organization_leak_in_the_shared_surface(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);
        $racineEtrangere = app(PersonalDocumentsRoot::class)->resolve($this->autreOrg->id, $etranger->id);
        $dossierEtranger = Dossier::create([
            'organization_id' => $this->autreOrg->id,
            'parent_id' => $racineEtrangere->id,
            'name' => 'Dossier etranger',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        // La donnee etrangere est reellement candidate : elle porte une ancre.
        DossierMember::create([
            'organization_id' => $this->autreOrg->id,
            'dossier_id' => $dossierEtranger->id,
            'user_id' => $etranger->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $etranger->id,
        ]);

        $this->partager($this->a, $this->m4);

        $lignes = $this->mesDocuments($this->m3)->assertOk()->viewData('driveFolders');

        $this->assertNotContains($dossierEtranger->id, $lignes->pluck('id')->all());
        $this->assertNotContains($racineEtrangere->id, $lignes->pluck('id')->all());
    }

    // =====================================================================
    // 10 — le Drive de Boucle ne change pas
    // =====================================================================

    /**
     * TASK-1143 figeait ici « Owner » — le Drive de Boucle etait alors hors
     * scope. TASK-1146 a corrige cette colonne : elle montrait l'uploader d'un
     * fichier et l'auteur d'un Article sous un en-tete « Proprietaire ». Le
     * Drive de Boucle porte desormais le meme « Ajoute par » que les autres
     * surfaces. Ce qui reste garde ici : il n'y a toujours pas de colonne
     * « Partage » dans une Boucle, et ce Drive n'est pas une surface partagee.
     */
    public function test_a_loop_drive_uses_the_added_by_column(): void
    {
        $this->withSession(['locale' => 'en']);

        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->m3->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->m3->id, 'joined_at' => now(),
        ]);

        $racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $loop->id,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
        $this->sousDossier($racineBoucle, 'Communication');

        $reponse = $this->ouvrir($this->m3, $racineBoucle)->assertOk();
        $enTetes = $this->enTetesDeColonnes($reponse->getContent());

        $this->assertContains('Added by', $enTetes);
        $this->assertNotContains('Owner', $enTetes);
        // Dans une Boucle, une colonne « Partage » repeterait « Boucle » a
        // chaque ligne : elle n'a jamais existe ici et n'apparait pas.
        $this->assertNotContains('Sharing', $enTetes);
        $this->assertFalse($reponse->viewData('isSharedSurface'));
    }

    /**
     * Le correctif est une lecture : aucune ligne `dossier_members` n'apparait
     * du fait de l'affichage, ni sur le descendant ni ailleurs.
     */
    public function test_rendering_never_creates_membership_rows(): void
    {
        $this->partager($this->a, $this->m4);
        $avant = DossierMember::count();

        $this->mesDocuments($this->m3)->assertOk();
        $this->ouvrir($this->m3, $this->a)->assertOk();
        $this->ouvrir($this->m4, $this->a)->assertOk();
        $this->ouvrir($this->m4, $this->b)->assertOk();

        $this->assertSame($avant, DossierMember::count());
        $this->assertSame(0, $this->b->dossierMembers()->count());
        $this->assertSame(0, $this->racine->dossierMembers()->count());
    }
}
