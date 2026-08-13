<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Modifier » un Article depuis le Drive (TASK-1134).
 *
 * Le 404 signale venait d'URL d'edition **fabriquees a la main** :
 * `/org/{org}/blog/{slug}/edit`, alors que la route canonique est
 * `/org/{org}/blog/rediger/{slug}/modifier`. Une premiere occurrence avait ete
 * corrigee dans `DossierArticleController` (TASK-1130) ; la derniere du depot
 * vivait dans `BlogController::orgStore`.
 *
 * Ces tests gardent les deux moities du parcours :
 * le **lien** que le Drive affiche, et la **destination** qu'il vise.
 */
class TASK1134DriveArticleEditLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $auteur;

    private User $tiers;

    private Dossier $dossier;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->tiers = User::factory()->create(['organization_id' => $this->org->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->auteur->id,
            'name' => 'Notes',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->article = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->auteur->id,
            'title' => 'Cadrage de la reunion',
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $this->article->id,
            'added_by' => $this->auteur->id,
            'position' => 1,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function urlCanonique(): string
    {
        return route('organization.blog.edit', [
            'organization' => $this->org->slug,
            'post' => $this->article->slug,
        ]);
    }

    // ── Le lien affiche par le Drive ─────────────────────────────────────────

    public function test_the_drive_links_to_the_canonical_edit_route(): void
    {
        $html = $this->actingAs($this->auteur)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug,
                'dossier' => $this->dossier->getKey(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->urlCanonique(), $html);
        // La forme fabriquee qui produisait le 404 ne doit plus apparaitre.
        $this->assertStringNotContainsString("/blog/{$this->article->slug}/edit", $html);
    }

    public function test_the_canonical_link_actually_opens(): void
    {
        // Un lien qui pointe bien ne suffit pas : la destination doit repondre.
        $this->actingAs($this->auteur)
            ->get($this->urlCanonique())
            ->assertOk();
    }

    // ── Autorisations, inchangees ────────────────────────────────────────────

    public function test_the_author_may_edit(): void
    {
        $this->actingAs($this->auteur)->get($this->urlCanonique())->assertOk();
    }

    public function test_someone_without_rights_is_refused(): void
    {
        $this->actingAs($this->tiers)->get($this->urlCanonique())->assertForbidden();
    }

    public function test_another_organization_gets_a_404(): void
    {
        // Organization = Tenant : on ne confirme pas l'existence de l'Article.
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);
        app()->instance('current_organization', $this->autreOrg);

        $this->actingAs($etranger)
            ->get(route('organization.blog.edit', [
                'organization' => $this->autreOrg->slug,
                'post' => $this->article->slug,
            ]))
            ->assertNotFound();
    }

    // ── Plus aucune URL d'edition fabriquee dans le depot ────────────────────

    public function test_no_hand_built_edit_url_remains(): void
    {
        // La cause de fond, gardee a la source : c'est la fabrication d'URL qui
        // produisait le 404, pas tel ou tel appelant.
        $fautives = [];

        foreach ([app_path(), resource_path()] as $racine) {
            $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));
            foreach ($iterateur as $fichier) {
                if (! $fichier->isFile() || ! in_array($fichier->getExtension(), ['php', 'js'], true)) {
                    continue;
                }
                $contenu = file_get_contents($fichier->getPathname());
                if (preg_match('#blog/\{?\$[A-Za-z_>\-]*slug\}?/edit#', $contenu)) {
                    $fautives[] = str_replace(base_path().'/', '', $fichier->getPathname());
                }
            }
        }

        $this->assertSame([], $fautives, 'URL d\'edition fabriquee a la main : utiliser route(\'organization.blog.edit\').');
    }
}
