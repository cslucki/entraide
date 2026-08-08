<?php

namespace Tests\Feature;

use App\Livewire\LoopArticleCard;
use App\Livewire\LoopDossiersCard;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les liens sortants des Cards restent dans l'Organization de leur Boucle.
 *
 * La Card Dossiers appelait `route('blog.show', $slug)` — la route **nue**,
 * celle que `ResolveUrlOrganization` traite comme une route de fonctionnalite
 * et qui retombe sur l'Organization **par defaut**.
 *
 * Mesure sur une Organization non par defaut : **deux liens sur cinq**
 * pointaient ailleurs. C'est le meme defaut que le bloquant de TASK-1107, ou
 * « Creer une Offre » enregistrait dans une autre Organization ; TASK-1111
 * l'avait signale sans le traiter.
 *
 * **Ces tests utilisent deux Organizations**, et la Boucle vit dans la seconde :
 * sur l'Organization par defaut, le defaut est invisible — c'est precisement ce
 * qui l'avait laisse passer en recette.
 */
class TASK1113DossiersCardLinksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaut;

    private Organization $autre;

    private User $auteur;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        // La premiere Organization creee fait office de defaut.
        $this->defaut = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->autre = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->auteur = User::factory()->create(['organization_id' => $this->autre->id]);

        app()->instance('current_organization', $this->autre);

        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->auteur, 'Une Boucle ailleurs')->fresh();

        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.dossiers'],
            ['organization_id' => $this->autre->id, 'enabled' => true],
        );

        $this->dossier = Dossier::firstOrCreate(
            ['loop_id' => $this->loop->id],
            [
                'organization_id' => $this->autre->id,
                'owner_id' => $this->auteur->id,
                'name' => 'Le Dossier',
                'visibility' => 'loop',
            ],
        );
    }

    private function article(string $titre): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->autre->id,
            'user_id' => $this->auteur->id,
            'title' => $titre,
            'slug' => \Illuminate\Support\Str::slug($titre).'-'.\Illuminate\Support\Str::random(6),
            'content' => 'x',
            'status' => 'published',
            'audience' => 'loop',
            'listed_in_blog' => true,
            'published_at' => now(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->autre->id,
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->auteur->id,
            'position' => 0,
        ]);

        return $post->fresh();
    }

    /** Les liens d'une Card qui sortent de l'Organization de la Boucle. */
    private function liensNus(string $html): array
    {
        preg_match_all('#href="([^"]*/(blog|dossiers|services|requests)/[^"]*)"#', $html, $m);

        return array_values(array_unique(array_filter(
            $m[1],
            fn (string $lien) => ! str_contains($lien, '/org/'.$this->autre->slug.'/'),
        )));
    }

    public function test_the_dossiers_card_never_links_outside_its_organization(): void
    {
        $this->article('Un article range');

        $html = Livewire::actingAs($this->auteur)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop->fresh()])
            ->html();

        $this->assertSame([], $this->liensNus($html), 'des liens sortent de l’Organization de la Boucle');
    }

    public function test_the_article_link_points_at_the_loops_organization(): void
    {
        $article = $this->article('Un article range');

        Livewire::actingAs($this->auteur)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop->fresh()])
            ->assertSeeHtml(route('organization.blog.show', [
                'organization' => $this->autre->slug,
                'post' => $article->slug,
            ]));
    }

    public function test_the_nude_route_never_appears(): void
    {
        // La route nue retombe sur l'Organization **par defaut** : sur une
        // Boucle qui n'y appartient pas, elle mene ailleurs.
        $article = $this->article('Un article range');

        $html = Livewire::actingAs($this->auteur)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop->fresh()])
            ->html();

        $this->assertStringNotContainsString(
            '"'.route('blog.show', ['post' => $article->slug]).'"',
            $html,
        );
    }

    public function test_the_dossier_url_reads_the_loop_and_not_the_request(): void
    {
        // L'ordre inverse laissait le slug de l'URL decider. Une interaction
        // Livewire arrive sur `POST /livewire/update`, qui ne porte aucun
        // parametre `organization` — la lecon de TASK-1103.
        $source = file_get_contents(app_path('Livewire/LoopDossiersCard.php'));

        $this->assertStringNotContainsString("request()->route('organization')", $source);
    }

    public function test_no_public_method_takes_a_model(): void
    {
        // Livewire expose toute methode publique comme action, avec liaison
        // implicite du modele — hors de toute garde. Troisieme lecon de la
        // serie.
        $reflet = new \ReflectionClass(LoopDossiersCard::class);
        $examinees = 0;

        foreach ($reflet->getMethods(\ReflectionMethod::IS_PUBLIC) as $methode) {
            if ($methode->getDeclaringClass()->getName() !== LoopDossiersCard::class
                || in_array($methode->getName(), ['mount', 'render', 'boot', 'booted'], true)) {
                continue;
            }

            foreach ($methode->getParameters() as $parametre) {
                $type = $parametre->getType();

                $this->assertFalse(
                    $type instanceof \ReflectionNamedType && str_starts_with((string) $type, 'App\\Models\\'),
                    $methode->getName().'() prend un modele et est exposee comme action',
                );
            }

            $examinees++;
        }

        $this->assertGreaterThan(0, $examinees);
    }

    public function test_the_article_card_was_already_clean(): void
    {
        // Verifie en recette : la Card Article ne produisait aucun lien nu.
        // Ce test empeche qu'elle regresse pendant qu'on repare sa voisine.
        $this->loop->forceFill(['type' => 'writing'])->save();
        LoopCard::where('loop_id', $this->loop->id)->delete();
        app(LoopTypeRegistry::class)->applyPreset($this->loop->fresh());

        $this->article('Un article range');

        $html = Livewire::actingAs($this->auteur)
            ->test(LoopArticleCard::class, ['loop' => $this->loop->fresh()])
            ->html();

        $this->assertSame([], $this->liensNus($html));
    }
}
