<?php

namespace Tests\Feature;

use App\Livewire\LoopDossiersCard;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1828 — navigation contextuelle depuis les apercus ChatLoop (TASK-1826)
 * vers la Card Dossiers : consulter un Article ou un Fichier PRECIS sans
 * quitter la Boucle.
 *
 * Meme idiome que `LoopRoadmapCard::openDetailFromChat()` (TASK-1826) :
 * `openArticleDetailFromChat()`/`openFileDetailFromChat()` posent un
 * identifiant, et `render()` le REVALIDE a chaque passage — passer un id
 * n'accorde jamais une permission.
 */
class TASK1828DossierCardContextualNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_public' => true, 'is_active' => true]);
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);
        $this->loop = (new LoopService)->createLoop($this->owner, 'Boucle de test');

        app()->instance('current_organization', $this->organization);
    }

    private function rootDossier(): Dossier
    {
        return Dossier::firstOrCreate(
            ['loop_id' => $this->loop->id],
            ['organization_id' => $this->organization->id, 'owner_id' => null, 'name' => 'Racine']
        );
    }

    /** Aucune factory pour BlogPost dans ce depot : creation directe, meme idiome que TASK1826/TASK1827. */
    private function article(array $surcharges = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'title' => 'Article de test',
            'slug' => 'article-test-'.uniqid(),
            'content' => '<p>Contenu complet de l\'article de test.</p>',
            'summary' => 'Resume court.',
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ], $surcharges));
    }

    private function file(array $surcharges = []): DossierFile
    {
        return DossierFile::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->rootDossier()->id,
            'uploaded_by' => $this->owner->id,
        ], $surcharges));
    }

    // -----------------------------------------------------------------
    // ARTICLE — pivot et Dossier racine
    // -----------------------------------------------------------------

    public function test_un_article_lie_par_pivot_s_ouvre_depuis_le_chat(): void
    {
        $post = $this->article();
        $post->loops()->attach($this->loop->id);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openArticleDetailFromChat', $post->id)
            ->assertSet('detailArticleId', $post->id)
            ->assertSee($post->title, false)
            ->assertSee('Contenu complet de l\'article de test', false)
            ->assertSee($this->owner->publicDisplayName());
    }

    public function test_un_article_lie_par_dossier_racine_s_ouvre_depuis_le_chat(): void
    {
        $dossier = $this->rootDossier();
        $post = $this->article();
        DossierBlogPost::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->owner->id,
            'position' => 1,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openArticleDetailFromChat', $post->id)
            ->assertSet('detailArticleId', $post->id)
            ->assertSee($post->title, false);
    }

    public function test_le_panneau_article_propose_le_lien_vers_l_article_complet(): void
    {
        $post = $this->article();
        $post->loops()->attach($this->loop->id);

        $url = route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openArticleDetailFromChat', $post->id)
            ->assertSee($url, false)
            // Comparaison a la valeur ECHAPPEE : l'apostrophe de "l'article"
            // est rendue `&#039;` par Blade — meme instabilite documentee
            // que pour TASK1824ProfileSecurityPageTest.
            ->assertSee(__('loops.cards.dossiers.detail_read_full_article'));
    }

    public function test_un_article_non_publie_ne_resout_jamais_meme_avec_l_id_exact(): void
    {
        $post = $this->article(['status' => 'draft', 'published_at' => null, 'listed_in_blog' => false]);
        $post->loops()->attach($this->loop->id);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openArticleDetailFromChat', $post->id)
            ->assertDontSee($post->title, false);
    }

    public function test_un_article_d_une_autre_organization_ne_resout_jamais(): void
    {
        $otherOrg = Organization::factory()->create(['is_public' => true, 'is_active' => true]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id, 'approved_at' => now()]);
        $foreignPost = BlogPost::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'title' => 'Titre confidentiel etranger',
            'slug' => 'titre-etranger-'.uniqid(),
            'content' => '<p>Secret.</p>',
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openArticleDetailFromChat', $foreignPost->id)
            ->assertDontSee('Titre confidentiel etranger', false);
    }

    // -----------------------------------------------------------------
    // FICHIER — PDF, image, non previsualisable
    // -----------------------------------------------------------------

    public function test_un_fichier_image_affiche_un_apercu_integre(): void
    {
        $file = $this->file(['mime_type' => 'image/png', 'original_name' => 'photo.png', 'display_name' => 'photo.png']);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$file->id])
            ->assertSet('detailFileId', $file->id)
            ->assertSeeHtml('<img')
            ->assertDontSee(__('loops.cards.dossiers.detail_preview_unavailable'), false);
    }

    public function test_un_fichier_pdf_affiche_un_apercu_integre(): void
    {
        $file = $this->file(['mime_type' => 'application/pdf', 'original_name' => 'rapport.pdf', 'display_name' => 'rapport.pdf']);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$file->id])
            ->assertSeeHtml('<iframe')
            ->assertDontSee(__('loops.cards.dossiers.detail_preview_unavailable'), false);
    }

    public function test_un_document_non_previsualisable_affiche_une_fiche_et_jamais_un_telechargement_automatique(): void
    {
        $file = $this->file([
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'original_name' => 'contrat.docx',
            'display_name' => 'contrat.docx',
        ]);

        $response = Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$file->id]);

        $response->assertSee(__('loops.cards.dossiers.detail_preview_unavailable'), false);
        $response->assertSee(__('loops.cards.dossiers.detail_download'), false);
        // Jamais de balise meta-refresh ni de redirection : la fiche se
        // contente d'un LIEN explicite, jamais un telechargement declenche.
        $response->assertDontSeeHtml('<meta http-equiv="refresh"');
    }

    public function test_plusieurs_fichiers_du_meme_depot_proposent_une_selection(): void
    {
        $fileA = $this->file(['original_name' => 'a.pdf', 'display_name' => 'a.pdf']);
        $fileB = $this->file(['original_name' => 'b.pdf', 'display_name' => 'b.pdf']);

        $test = Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$fileA->id, $fileB->id])
            ->assertSet('detailFileId', null)
            ->assertSee(__('loops.cards.dossiers.detail_select_file'), false)
            ->assertSee('a.pdf', false)
            ->assertSee('b.pdf', false);

        $test->call('selectHighlightedFile', $fileA->id)
            ->assertSet('detailFileId', $fileA->id)
            ->assertSee(__('loops.cards.dossiers.detail_back_to_selection'), false);

        $test->call('backToSelection')
            ->assertSet('detailFileId', null)
            ->assertSee(__('loops.cards.dossiers.detail_select_file'), false);
    }

    public function test_un_fichier_absent_ou_supprime_ne_resout_rien_et_ne_casse_pas_la_card(): void
    {
        $file = $this->file();
        $fileId = $file->id;
        $file->delete();

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$fileId])
            ->assertOk()
            ->assertDontSee('data-marqueur="dossier-detail-file"', false);
    }

    public function test_un_fichier_d_une_autre_organization_ne_resout_jamais(): void
    {
        $otherOrg = Organization::factory()->create(['is_public' => true, 'is_active' => true]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id, 'approved_at' => now()]);
        $otherLoop = (new LoopService)->createLoop($otherUser, 'Boucle etrangere');
        $otherDossier = Dossier::firstOrCreate(
            ['loop_id' => $otherLoop->id],
            ['organization_id' => $otherOrg->id, 'owner_id' => null, 'name' => 'Racine']
        );
        $foreignFile = DossierFile::factory()->create([
            'organization_id' => $otherOrg->id,
            'dossier_id' => $otherDossier->id,
            'uploaded_by' => $otherUser->id,
            'original_name' => 'secret.pdf',
            'display_name' => 'secret.pdf',
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$foreignFile->id])
            ->assertDontSee('secret.pdf', false);
    }

    public function test_acces_non_autorise_au_fichier_ne_resout_rien(): void
    {
        // Un fichier de la MEME Organization, dans un Dossier qui n'est NI
        // celui de cette Boucle NI partage avec elle : aucune permission ne
        // s'applique — l'id exact ne doit rien ouvrir.
        $autreUtilisateur = User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);
        $autreLoop = (new LoopService)->createLoop($autreUtilisateur, 'Autre boucle');
        $autreDossier = Dossier::firstOrCreate(
            ['loop_id' => $autreLoop->id],
            ['organization_id' => $this->organization->id, 'owner_id' => null, 'name' => 'Racine']
        );
        $fichierInaccessible = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $autreDossier->id,
            'uploaded_by' => $autreUtilisateur->id,
            'original_name' => 'prive.pdf',
            'display_name' => 'prive.pdf',
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$fichierInaccessible->id])
            ->assertDontSee('prive.pdf', false);
    }

    // -----------------------------------------------------------------
    // FERMETURE / RETOUR A L'ETAT PRECEDENT
    // -----------------------------------------------------------------

    public function test_fermer_le_detail_revient_a_la_liste_normale(): void
    {
        $file = $this->file();

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->call('openFileDetailFromChat', [$file->id])
            ->assertSet('detailFileId', $file->id)
            ->call('closeDetail')
            ->assertSet('detailFileId', null)
            ->assertSet('detailArticleId', null)
            ->assertSet('highlightedFileIds', []);
    }

    // -----------------------------------------------------------------
    // COMPATIBILITE ANCIENNE ANNONCE (pas de file_ids) — rendu ChatLoop
    // -----------------------------------------------------------------

    public function test_un_ancien_message_sans_file_ids_degrade_vers_le_bouton_generique(): void
    {
        $message = \App\Models\LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'A depose un fichier.',
            'type' => 'dossier_file',
            'metadata' => ['files' => ['ancien-fichier.pdf']],
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.chat_dossier_open_card'), false)
            ->assertDontSee("Livewire.dispatch('open-dossier-file-detail'", false);
    }

    public function test_un_depot_unique_recent_ouvre_directement_son_apercu_depuis_le_chat(): void
    {
        $file = $this->file(['original_name' => 'unique.pdf', 'display_name' => 'unique.pdf']);
        app(\App\Services\LoopMessageService::class)->sendDossierFileMessage($this->loop, $this->owner, collect([$file]));

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.chat_dossier_file_open'), false)
            // `@js()` serialise en `JSON.parse('[...]')`, pas un litteral JSON
            // nu : on verifie l'appel ET l'id reel, pas un format precis.
            ->assertSee("Livewire.dispatch('open-dossier-file-detail'", false)
            ->assertSee($file->id, false);
    }

    public function test_un_depot_multiple_recent_propose_voir_les_fichiers_depuis_le_chat(): void
    {
        $fileA = $this->file();
        $fileB = $this->file();
        app(\App\Services\LoopMessageService::class)->sendDossierFileMessage($this->loop, $this->owner, collect([$fileA, $fileB]));

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.chat_dossier_file_open_many'), false);
    }
}
