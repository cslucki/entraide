<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\BlogPostFile;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use App\Services\Blog\BlogHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-1907 — un fichier prive d'article n'est lu qu'avec les droits de
 * l'article ; avant rattachement, par son seul deposant.
 */
class TASK1907BlogPostFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private Organization $org;

    private User $auteur;

    private User $membre;

    private User $etranger;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BlogPostFile::DISK);

        $this->org = Organization::factory()->create(['is_public' => false, 'slug' => 't1907-privee', 'is_active' => true]);
        $autre = Organization::factory()->create(['is_public' => true, 'slug' => 't1907-autre', 'is_active' => true, 'is_default' => true]);

        $this->auteur = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => false]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => false]);
        $this->etranger = User::factory()->create(['organization_id' => $autre->id, 'is_admin' => false]);
    }

    private function deposer(User $user, UploadedFile $fichier): TestResponse
    {
        return $this->actingAs($user)->postJson(
            route('organization.blog.files.store', [$this->org]),
            ['file' => $fichier],
        );
    }

    private function lire(?User $user, string $id): TestResponse
    {
        if ($user) {
            $this->actingAs($user);
        }

        return $this->get(route('organization.blog.files.show', [$this->org, $id]));
    }

    private function article(User $auteur, string $contenu): BlogPost
    {
        return BlogPost::create([
            'user_id' => $auteur->id,
            'organization_id' => $this->org->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->org->id])->id,
            'title' => 'Article T1907 '.uniqid(),
            'content' => $contenu,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
    }

    private function marqueur(string $id): string
    {
        return '<p>Avant</p><figure data-bp-file="pdf" data-bp-file-id="'.$id.'"></figure>';
    }

    public function test_un_pdf_est_depose_sur_le_disque_prive_et_lu_par_son_seul_deposant_avant_rattachement(): void
    {
        $id = $this->deposer($this->auteur, UploadedFile::fake()->createWithContent('Guide.pdf', self::PDF))
            ->assertCreated()
            ->assertJsonPath('kind', 'pdf')
            ->json('id');

        $fichier = BlogPostFile::findOrFail($id);
        Storage::disk(BlogPostFile::DISK)->assertExists($fichier->path);
        $this->assertNull($fichier->blog_post_id);

        $this->lire($this->auteur, $id)->assertOk();
        $this->lire($this->membre, $id)->assertNotFound();
    }

    public function test_un_faux_pdf_est_refuse(): void
    {
        $this->deposer($this->auteur, UploadedFile::fake()->createWithContent('faux.pdf', '<html><script>alert(1)</script></html>'))
            ->assertStatus(422);

        $this->assertSame(0, BlogPostFile::count());
    }

    public function test_rattache_le_fichier_prend_les_droits_de_l_article(): void
    {
        $id = $this->deposer($this->auteur, UploadedFile::fake()->createWithContent('Guide.pdf', self::PDF))->json('id');

        $post = $this->article($this->auteur, $this->marqueur($id));

        $this->assertSame($post->id, BlogPostFile::findOrFail($id)->blog_post_id);
        $this->lire($this->membre, $id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        auth()->logout();
        // Anonyme : renvoye a la connexion par le groupe de routes, jamais servi.
        $this->assertNotSame(200, $this->lire(null, $id)->getStatusCode());
        $this->lire($this->etranger, $id)->assertNotFound();

        $post->update(['status' => 'draft']);
        $this->lire($this->membre, $id)->assertNotFound();
    }

    public function test_citer_le_fichier_d_un_autre_ne_l_approprie_pas(): void
    {
        $id = $this->deposer($this->auteur, UploadedFile::fake()->createWithContent('Guide.pdf', self::PDF))->json('id');

        // Fichier libre, mais depose par quelqu'un d'autre que l'auteur.
        $this->article($this->membre, $this->marqueur($id));
        $this->assertNull(BlogPostFile::findOrFail($id)->blog_post_id);

        // Fichier deja rattache : un second article ne le reprend pas.
        $premier = $this->article($this->auteur, $this->marqueur($id));
        $this->article($this->auteur, $this->marqueur($id));
        $this->assertSame($premier->id, BlogPostFile::findOrFail($id)->blog_post_id);
    }

    public function test_le_filtre_ne_garde_le_marqueur_que_pour_un_article_et_le_vide(): void
    {
        $sanitizer = app(BlogHtmlSanitizer::class);
        $id = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
        $html = '<figure data-bp-file="audio" data-bp-file-id="'.$id.'" data-bp-file-name="Episode 1.mp3" onclick="alert(1)" style="position:fixed"><audio src="https://evil.example/x.mp3"></audio>texte</figure>';

        $article = $sanitizer->sanitizeHtml($html, allowFileMarkers: true);
        $this->assertSame('<figure data-bp-file="audio" data-bp-file-id="'.$id.'" data-bp-file-name="Episode 1.mp3"></figure>', $article);

        // Ailleurs (Flux, RSS) : la figure est deballee, rien du marqueur ne reste.
        $this->assertStringNotContainsString('data-bp-file', $sanitizer->sanitizeHtml($html));

        // Type ou identifiant invalide : pas de marqueur.
        $this->assertStringNotContainsString('data-bp-file', $sanitizer->sanitizeHtml(str_replace('"audio"', '"video"', $html), allowFileMarkers: true));
        $this->assertStringNotContainsString('data-bp-file', $sanitizer->sanitizeHtml(str_replace($id, 'pas-un-uuid', $html), allowFileMarkers: true));
    }

    public function test_l_affichage_construit_le_lecteur_seulement_pour_un_fichier_de_cet_article(): void
    {
        $audio = $this->deposer($this->auteur, UploadedFile::fake()->createWithContent('Episode.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00", 64)));
        $pdfId = $this->deposer($this->auteur, UploadedFile::fake()->createWithContent('Guide.pdf', self::PDF))->json('id');

        $autre = $this->article($this->auteur, $this->marqueur($pdfId));
        $post = $this->article($this->auteur, $this->marqueur($pdfId).'<figure data-bp-file="audio" data-bp-file-id="'.$audio->json('id').'"></figure>');

        $audio->assertCreated();
        $rendu = $post->fresh()->contenuAffichable();

        // L'audio est a cet article : lecteur construit, adresse = route gardee.
        $this->assertStringContainsString('<audio controls="" preload="none" src="'.route('organization.blog.files.show', [$this->org, $audio->json('id')]).'"', $rendu);
        $this->assertStringContainsString('<figcaption>Episode.mp3</figcaption>', $rendu);
        // Le PDF appartient a l'AUTRE article : rien n'est rendu ici.
        $this->assertStringNotContainsString($pdfId, $rendu);
        $this->assertStringContainsString('data-bp-pdf-src="'.route('organization.blog.files.show', [$this->org, $pdfId]).'"', $autre->fresh()->contenuAffichable());
    }
}
