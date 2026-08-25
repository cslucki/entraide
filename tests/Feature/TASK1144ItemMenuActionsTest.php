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
 * Le menu « … » d'un item ne propose que des actions SUR CET ITEM.
 *
 * ## Le defaut
 *
 * Trois menus d'items — Article en vue liste, fichier en vue liste, fichier en
 * vue grille — proposaient « Partager le dossier… ». Un fichier n'est pas un
 * Dossier, un Article non plus. L'entree n'appelait aucune route et ne mutait
 * rien : elle diffusait `open-share-panel`, l'evenement du panneau du Dossier
 * CONTENANT. Le geste etait donc sans danger, mais le menu promettait une
 * action sur l'item et en executait une sur son contenant.
 *
 * Les trois autres menus — les deux menus Dossier, et l'Article en vue grille —
 * ne l'ont jamais portee : l'ecran etait deja incoherent avec lui-meme.
 *
 * ## Ce que ces tests fixent
 *
 * - un Dossier garde son action de partage, par un lien vers SA propre page ;
 * - un fichier et un Article n'en ont plus aucune, quel que soit le role ;
 * - il ne reste qu'un seul emetteur de `open-share-panel` : le bouton d'en-tete
 *   du Dossier ouvert, qui parle bien de ce Dossier.
 *
 * Aucune permission, aucune policy, aucun partage individuel de fichier ou
 * d'Article n'est introduit.
 */
class TASK1144ItemMenuActionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $owner;

    private User $reader;

    private User $editor;

    private Dossier $racine;

    /** Le Dossier ouvert pendant les tests, avec un fichier et un Article. */
    private Dossier $dossier;

    private Dossier $sousDossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->reader = User::factory()->create(['organization_id' => $this->org->id]);
        $this->editor = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->racine = app(PersonalDocumentsRoot::class)->resolve($this->org->id, $this->owner->id);
        $this->dossier = $this->sousDossier($this->racine, 'Dossier de main member 3');
        $this->sousDossier = $this->sousDossier($this->dossier, 'Sous-dossier');

        $this->fichier($this->dossier, 'Rapport.pdf');
        $this->article($this->dossier, 'Note de synthese');
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

    private function fichier(Dossier $dossier, string $nom): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->owner->id,
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

    private function article(Dossier $dossier, string $titre): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $dossier->organization_id,
            'user_id' => $this->owner->id,
            'title' => $titre,
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->owner->id,
        ]);

        return $post;
    }

    private function partager(User $invite, string $role): void
    {
        DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'user_id' => $invite->id,
            'role' => $role,
            'added_by' => $this->owner->id,
        ]);
    }

    private function ouvrir(User $acteur, ?Dossier $cible = null): TestResponse
    {
        return $this->actingAs($acteur)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => ($cible ?? $this->dossier)->getKey(),
        ]));
    }

    /**
     * Combien de commandes « ouvre le panneau de partage » la page contient.
     *
     * C'est la mesure qui compte : le libelle pouvait changer, l'entree fautive
     * restait. Le seul emetteur legitime est le bouton d'en-tete, rendu sous
     * `@elseif($userRole === 'owner')` — donc UN pour le proprietaire d'un
     * Dossier ordinaire, ZERO partout ailleurs (lecteur, editeur, racine
     * personnelle, Drive de Boucle). Tout ce qui depasse est une entree de
     * menu d'item.
     */
    private function emetteursDuPanneau(string $html): int
    {
        return substr_count($html, "new CustomEvent('open-share-panel')");
    }

    // =====================================================================
    // Le Dossier garde son action
    // =====================================================================

    public function test_a_folder_row_keeps_its_own_sharing_action(): void
    {
        $reponse = $this->ouvrir($this->owner)->assertOk();

        // Un lien vers SA page a lui, avec le deep-link du panneau : l'action
        // designe le Dossier concerne, pas le Dossier ouvert.
        $reponse->assertSee(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => $this->sousDossier->getKey(),
            'partage' => 1,
        ]), false);
    }

    public function test_the_open_dossier_keeps_its_header_share_button(): void
    {
        $html = $this->ouvrir($this->owner)->assertOk()->getContent();

        // Exactement un emetteur : l'en-tete. Zero signifierait qu'on a casse
        // le partage du Dossier lui-meme.
        $this->assertSame(1, $this->emetteursDuPanneau($html));
    }

    // =====================================================================
    // Fichier et Article : plus aucune action de Dossier
    // =====================================================================

    public function test_the_owner_never_sees_share_the_folder_on_items(): void
    {
        $html = $this->ouvrir($this->owner)->assertOk()->getContent();

        $this->assertStringNotContainsString('Partager le dossier', $html);
        $this->assertSame(1, $this->emetteursDuPanneau($html));
    }

    public function test_a_reader_never_sees_share_the_folder_on_items(): void
    {
        $this->partager($this->reader, DossierMember::ROLE_READER);

        $html = $this->ouvrir($this->reader)->assertOk()->getContent();

        $this->assertStringNotContainsString('Partager le dossier', $html);
    }

    public function test_an_editor_never_sees_share_the_folder_on_items(): void
    {
        $this->partager($this->editor, DossierMember::ROLE_EDITOR);

        $html = $this->ouvrir($this->editor)->assertOk()->getContent();

        // L'editeur gere les fichiers et les Articles : c'est le role qui
        // voyait le plus d'entrees de menu, donc le plus expose au defaut.
        $this->assertStringNotContainsString('Partager le dossier', $html);
    }

    /**
     * Le libelle a disparu, mais surtout la commande : un menu d'item ne doit
     * plus contenir AUCUN declencheur du panneau, quel que soit son texte.
     */
    public function test_no_item_menu_can_open_the_sharing_panel(): void
    {
        $this->partager($this->reader, DossierMember::ROLE_READER);
        $this->partager($this->editor, DossierMember::ROLE_EDITOR);

        // Le proprietaire garde son bouton d'en-tete, et rien de plus.
        $this->assertSame(1, $this->emetteursDuPanneau($this->ouvrir($this->owner)->assertOk()->getContent()));

        // Un lecteur et un editeur n'administrent pas le partage : chez eux, la
        // page ne doit contenir AUCUN declencheur. Avant correction, leurs
        // menus d'items en offraient un par fichier et par Article.
        foreach ([$this->reader, $this->editor] as $acteur) {
            $this->assertSame(0, $this->emetteursDuPanneau($this->ouvrir($acteur)->assertOk()->getContent()),
                'Un menu d\'item declenche encore le panneau de partage.');
        }
    }

    /** La cle de traduction elle-meme ne doit plus exister nulle part. */
    public function test_the_dead_translation_key_is_gone_in_both_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            // Une cle absente se rend telle quelle : c'est la preuve qu'elle
            // n'est plus definie, dans l'une comme dans l'autre langue.
            $this->assertSame('dossiers.share_the_folder', __('dossiers.share_the_folder'));
        }
    }

    // =====================================================================
    // Les surfaces voisines ne changent pas
    // =====================================================================

    public function test_a_loop_drive_keeps_its_existing_behaviour(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->owner->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->owner->id, 'joined_at' => now(),
        ]);

        $racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $loop->id,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
        $this->fichier($racineBoucle, 'Fichier de Boucle.pdf');

        $html = $this->ouvrir($this->owner, $racineBoucle)->assertOk()->getContent();

        // Un Drive de Boucle s'administre depuis la Boucle : l'en-tete n'y
        // offre aucun partage, et les menus d'items n'en offrent plus non plus.
        $this->assertStringNotContainsString('Partager le dossier', $html);
        $this->assertSame(0, $this->emetteursDuPanneau($html));
    }

    public function test_the_personal_documents_root_is_unchanged(): void
    {
        $this->fichier($this->racine, 'A la racine.pdf');

        $html = $this->ouvrir($this->owner, $this->racine)->assertOk()->getContent();

        // « Mes documents » n'est jamais une ancre de partage : ni entree de
        // menu, ni bouton d'en-tete. La racine masquait deja l'entree fautive
        // par un `@unless` — elle ne doit pas la gagner au passage.
        $this->assertStringNotContainsString('Partager le dossier', $html);
        $this->assertSame(0, $this->emetteursDuPanneau($html));
    }

    public function test_another_organization_stays_isolated(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);

        // La donnee est reellement candidate : un Dossier ouvrable, avec du
        // contenu, dans une autre Organization.
        $this->actingAs($etranger)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug,
                'dossier' => $this->dossier->getKey(),
            ]))
            ->assertForbidden();
    }
}
