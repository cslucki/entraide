<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\BlogTodo;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1884 correctif 7 (#6) — l'editeur montre, sous la barre de boutons, UNE
 * ligne : Boucle, Dossier, derniere tache, auteurs ; sans fuite d'une Boucle
 * privee ni d'un Dossier invisible.
 */
class TASK1884EditorSummaryLineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $coAuteur;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Martin', 'preferred_locale' => 'fr']);
        $this->coAuteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Bruno', 'name' => 'Petit', 'preferred_locale' => 'fr']);
        $this->post = BlogPost::withoutGlobalScopes()->forceCreate([
            'user_id' => $this->auteur->id, 'organization_id' => $this->org->id,
            'title' => 'Article resume', 'slug' => 'article-resume', 'content' => '<p>x</p>', 'status' => 'draft',
        ]);
        $this->post->coAuthors()->attach($this->coAuteur->id, ['role' => 'coauthor', 'added_by' => $this->auteur->id]);
    }

    private function editeur(User $qui): string
    {
        return $this->actingAs($qui)->get(route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]))
            ->assertOk()->getContent();
    }

    private function boucle(string $nom, string $visibilite, array $membres): Loop
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'name' => $nom, 'visibility' => $visibilite, 'created_by' => $this->auteur->id]);
        foreach ($membres as $m) {
            LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $m->id]);
        }

        return $loop;
    }

    public function test_la_ligne_porte_boucle_dossier_derniere_tache_et_auteurs(): void
    {
        $boucle = $this->boucle('Atelier Ecriture', 'public', [$this->auteur, $this->coAuteur]);
        $this->post->loops()->attach($boucle->id);
        $dossier = Dossier::factory()->create(['organization_id' => $this->org->id, 'owner_id' => $this->auteur->id, 'name' => 'Dossier Printemps', 'visibility' => 'organization']);
        DossierBlogPost::create(['organization_id' => $this->org->id, 'dossier_id' => $dossier->id, 'blog_post_id' => $this->post->id, 'added_by' => $this->auteur->id, 'position' => 0]);
        BlogTodo::create(['organization_id' => $this->org->id, 'blog_post_id' => $this->post->id, 'user_id' => $this->auteur->id, 'title' => 'Ancienne tache', 'status' => 'done', 'position' => 1, 'created_at' => now()->subDay()]);
        BlogTodo::create(['organization_id' => $this->org->id, 'blog_post_id' => $this->post->id, 'user_id' => $this->auteur->id, 'title' => 'Relire la conclusion', 'status' => 'in_progress', 'position' => 2]);

        $html = $this->editeur($this->coAuteur);

        $this->assertStringContainsString('data-editor-summary', $html);
        $this->assertMatchesRegularExpression('/data-editor-summary-loop>.*Atelier Ecriture/s', $html);
        $this->assertMatchesRegularExpression('/data-editor-summary-dossier>.*Dossier Printemps/s', $html);
        $this->assertMatchesRegularExpression('/data-editor-summary-task>.*Relire la conclusion \(En cours\)/s', $html);
        $this->assertStringNotContainsString('Ancienne tache', $html);
        $this->assertMatchesRegularExpression('/data-editor-summary-authors>.*Auteurs :.*'.preg_quote(e($this->auteur->publicDisplayName()), '/').'.*'.preg_quote(e($this->coAuteur->publicDisplayName()), '/').'/s', $html);
    }

    public function test_une_boucle_privee_n_est_nommee_qu_a_ses_membres(): void
    {
        $privee = $this->boucle('Cercle Secret', 'private', [$this->auteur]);
        $this->post->loops()->attach($privee->id);

        $this->assertStringContainsString('Cercle Secret', $this->editeur($this->auteur));

        // Ni la ligne de resume, ni la ligne de perimetre de la co-ecriture (T1884)
        // ne la nomment a un co-auteur qui n'en est pas membre. (Le pop-up
        // « Boucle », anterieur a T1884, porte encore son nom : signale a Cyril.)
        $html = $this->editeur($this->coAuteur);
        $this->assertMatchesRegularExpression('/data-editor-summary-loop>[^<]*<span[^>]*>[^<]*<\/span>\s*'.preg_quote(__('blog.summary_private_loop'), '/').'/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-editor-summary-loop>.{0,300}Cercle Secret/s', $html);
        $this->assertDoesNotMatchRegularExpression('/data-coauthor-scope>.{0,300}Cercle Secret/s', $html);
    }

    public function test_sans_boucle_ni_dossier_ni_tache_seuls_les_auteurs(): void
    {
        $html = $this->editeur($this->auteur);

        $this->assertStringContainsString('data-editor-summary-authors', $html);
        foreach (['data-editor-summary-loop', 'data-editor-summary-dossier', 'data-editor-summary-task'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }
}
