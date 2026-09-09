<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1492 — la confidentialite d'une Organization s'etend a son blog.
 *
 * ## L'autorite est CONTINUEE, pas inventee
 *
 * `BlogController::assertPrivateLoopManifestoIsReadable()` ecrit deja le contrat
 * en toutes lettres depuis TASK-1079 :
 *
 * > « A published article is normally readable by the whole **Organization**.
 * >   [...] the Loop's confidentiality must extend to the article itself —
 * >   otherwise the direct /blog/{slug} URL walks straight around it. »
 *
 * `published` veut donc dire « lisible par l'Organization », pas « publie sur
 * Internet ». Et la confidentialite d'un contenant s'etend a l'article qu'il
 * porte. TASK-1079 l'a applique a une Boucle privee ; il manquait le contenant
 * le plus englobant — l'Organization elle-meme.
 *
 * ## Ce qui a ete mesure au HEAD 8f540d69
 *
 * `test20260822` (`is_public = false`), article publie
 * `02-design-cadre-du-dialogue` : **200 avec son titre** pour un membre d'une
 * AUTRE Organization ET pour un anonyme complet. `BlogController` ne filtrait
 * que sur `organization_id`.
 *
 * ## Ce que ces tests protegent AUTANT que la fermeture
 *
 * Le blog d'une Organization PUBLIQUE est une surface d'acquisition. La moitie
 * des tests ci-dessous existe pour qu'un correctif de vie privee ne la ferme
 * pas par exces de zele.
 */
class TASK1492BlogPrivateOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $private;

    private Organization $public;

    private User $privateMember;

    private User $outsider;

    private BlogPost $privatePost;

    private BlogPost $publicPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->private = Organization::factory()->create(['is_public' => false, 'slug' => 't1492-privee', 'is_active' => true]);
        $this->public = Organization::factory()->create(['is_public' => true, 'slug' => 't1492-publique', 'is_active' => true, 'is_default' => true]);

        $this->privateMember = User::factory()->create(['organization_id' => $this->private->id, 'is_admin' => false]);
        $this->outsider = User::factory()->create(['organization_id' => $this->public->id, 'is_admin' => false]);

        $this->privatePost = $this->publishedPost($this->private, 'ARTICLE CONFIDENTIEL T1492');
        $this->publicPost = $this->publishedPost($this->public, 'ARTICLE VITRINE T1492');
    }

    private function publishedPost(Organization $organization, string $title): BlogPost
    {
        $author = User::factory()->create(['organization_id' => $organization->id]);
        $category = Category::factory()->create(['organization_id' => $organization->id]);

        return BlogPost::create([
            'user_id' => $author->id,
            'organization_id' => $organization->id,
            'category_id' => $category->id,
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'content' => 'Contenu de '.$title,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    /** @return array<string, string> Les quatre surfaces blog de l'Organization privee. */
    private function privateSurfaces(): array
    {
        return [
            'index' => route('organization.blog.index', [$this->private]),
            'show' => route('organization.blog.show', [$this->private, $this->privatePost->slug]),
            'category' => route('organization.blog.category', [$this->private, $this->privatePost->category->slug]),
            'tag' => route('organization.blog.tag', [$this->private, 'un-tag']),
        ];
    }

    /** Organization PRIVEE + invite : refus sur les quatre surfaces. */
    public function test_a_guest_never_reads_the_blog_of_a_private_organization(): void
    {
        foreach ($this->privateSurfaces() as $label => $url) {
            $response = $this->get($url);

            $this->assertNotSame(200, $response->getStatusCode(), "[$label] est encore servi a un invite.");
            $this->assertStringNotContainsString('ARTICLE CONFIDENTIEL T1492', $response->getContent(), "[$label] laisse fuir le titre.");
        }
    }

    /** Organization PRIVEE + membre d'une AUTRE Organization : refus. */
    public function test_a_member_of_another_organization_never_reads_it_either(): void
    {
        foreach ($this->privateSurfaces() as $label => $url) {
            $response = $this->actingAs($this->outsider)->get($url);

            $this->assertNotSame(200, $response->getStatusCode(), "[$label] est servi a un membre etranger.");
            $this->assertStringNotContainsString('ARTICLE CONFIDENTIEL T1492', $response->getContent(), "[$label] laisse fuir le titre a un membre etranger.");
        }
    }

    /** Organization PRIVEE + membre de CETTE Organization : comportement normal. */
    public function test_a_member_of_the_private_organization_reads_it_normally(): void
    {
        $this->actingAs($this->privateMember)
            ->get(route('organization.blog.show', [$this->private, $this->privatePost->slug]))
            ->assertOk()
            ->assertSee('ARTICLE CONFIDENTIEL T1492');

        $this->actingAs($this->privateMember)
            ->get(route('organization.blog.index', [$this->private]))
            ->assertOk();
    }

    /**
     * L'AUTRE moitie du contrat, et elle compte autant : le blog d'une
     * Organization PUBLIQUE reste une surface d'acquisition, ouverte aux
     * invites. Un correctif de vie privee qui la fermerait serait une
     * regression produit, pas une amelioration.
     */
    public function test_the_blog_of_a_public_organization_stays_open_to_guests(): void
    {
        $this->get(route('organization.blog.show', [$this->public, $this->publicPost->slug]))
            ->assertOk()
            ->assertSee('ARTICLE VITRINE T1492');

        $this->get(route('organization.blog.index', [$this->public]))->assertOk();
    }

    /** Le SuperAdmin garde l'acces transverse deja accorde par les gardes voisines. */
    public function test_a_super_admin_keeps_the_cross_organization_access(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->public->id, 'is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('organization.blog.show', [$this->private, $this->privatePost->slug]))
            ->assertOk();
    }
}
