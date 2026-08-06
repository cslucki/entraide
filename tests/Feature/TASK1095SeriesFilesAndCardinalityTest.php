<?php

namespace Tests\Feature;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ce que TASK-1095 ouvre **par les routes**.
 *
 * `DossierSeriesServiceTest` verifie les invariants au niveau du service ;
 * ceux-ci verifient qu'on y accede vraiment depuis HTTP. Sans eux, la migration
 * et le service existeraient sans qu'aucun chemin ne les atteigne.
 *
 * Trois choses sont nouvelles et donc testees ici : un fichier peut entrer dans
 * une Serie, une Serie peut exister sans Article racine a condition d'avoir un
 * nom, et un Dossier peut porter plusieurs Series — auquel cas la requete doit
 * dire laquelle.
 */
class TASK1095SeriesFilesAndCardinalityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1095', 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier 1095',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function article(string $titre, ?Dossier $dossier = null): BlogPost
    {
        $dossier ??= $this->dossier;

        $post = BlogPost::create([
            'organization_id' => $dossier->organization_id,
            'user_id' => $this->owner->id,
            'title' => $titre,
            'content' => "Contenu de {$titre}.",
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->owner->id,
            'position' => 0,
        ]);

        return $post;
    }

    private function fichier(string $nom, ?Dossier $dossier = null): DossierFile
    {
        $dossier ??= $this->dossier;

        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->owner->id,
            'disk' => 'local',
            'path' => 'dossiers/'.$dossier->id.'/'.$nom,
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => hash('sha256', $nom.Str::random(6)),
            'source' => 'upload',
        ]);
    }

    private function route(string $name, array $extra = []): string
    {
        return route("organization.{$name}", array_merge([
            'organization' => $this->org->slug,
            'dossier' => $this->dossier->id,
        ], $extra));
    }

    // ── Un fichier dans une Serie ───────────────────────────────────────────

    public function test_a_file_can_be_added_to_a_series(): void
    {
        $racine = $this->article('La racine');
        $fichier = $this->fichier('annexe.pdf');

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.annexes.store'), ['dossier_file_id' => $fichier->id])
            ->assertOk();

        $this->assertDatabaseHas('article_series_items', [
            'dossier_file_id' => $fichier->id,
            'blog_post_id' => null,
        ]);
    }

    public function test_a_series_can_mix_articles_and_files_in_one_order(): void
    {
        $racine = $this->article('La racine');
        $article = $this->article('Une annexe article');
        $fichier = $this->fichier('une-annexe.pdf');

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        foreach ([['blog_post_id' => $article->id], ['dossier_file_id' => $fichier->id]] as $charge) {
            $this->actingAs($this->owner)
                ->postJson($this->route('dossiers.series.annexes.store'), $charge)
                ->assertOk();
        }

        $serie = ArticleSeries::where('dossier_id', $this->dossier->id)->firstOrFail();

        $contenus = ArticleSeriesItem::where('article_series_id', $serie->id)
            ->orderBy('position')
            ->get()
            ->map(fn (ArticleSeriesItem $i) => $i->contentType())
            ->all();

        $this->assertSame(['article', 'file'], $contenus);
    }

    public function test_a_series_of_files_alone_needs_no_root_article(): void
    {
        $a = $this->fichier('premier.pdf');
        $b = $this->fichier('second.pdf');

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['name' => 'Les pieces jointes'])
            ->assertOk();

        $serie = ArticleSeries::where('dossier_id', $this->dossier->id)->firstOrFail();
        $this->assertNull($serie->root_blog_post_id);
        $this->assertSame('Les pieces jointes', $serie->displayName());

        foreach ([$a, $b] as $fichier) {
            $this->actingAs($this->owner)
                ->postJson($this->route('dossiers.series.annexes.store'), ['dossier_file_id' => $fichier->id])
                ->assertOk();
        }

        $this->assertSame(2, ArticleSeriesItem::where('article_series_id', $serie->id)->count());
    }

    public function test_a_series_without_root_and_without_name_is_refused(): void
    {
        // Sans racine dont tirer un titre et sans nom, la Serie n'aurait rien a
        // afficher : elle serait creee et resterait invisible.
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('article_series', 0);
    }

    public function test_a_request_carrying_both_an_article_and_a_file_is_refused(): void
    {
        $racine = $this->article('La racine');
        $article = $this->article('Un article');
        $fichier = $this->fichier('un-fichier.pdf');

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        // Refusee, et non tranchee en faveur de l'un des deux : deviner ce que
        // la personne voulait ranger, c'est ranger la mauvaise chose une fois
        // sur deux.
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.annexes.store'), [
                'blog_post_id' => $article->id,
                'dossier_file_id' => $fichier->id,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('article_series_items', 0);
    }

    public function test_a_file_of_another_dossier_never_enters_this_series(): void
    {
        $racine = $this->article('La racine');

        $autre = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Un autre Dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $etranger = $this->fichier('ailleurs.pdf', $autre);

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.annexes.store'), ['dossier_file_id' => $etranger->id])
            ->assertNotFound();

        $this->assertDatabaseCount('article_series_items', 0);
    }

    public function test_removing_a_file_from_a_series_never_deletes_the_file(): void
    {
        $racine = $this->article('La racine');
        $fichier = $this->fichier('a-retirer.pdf');

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        $item = $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.annexes.store'), ['dossier_file_id' => $fichier->id])
            ->assertOk()
            ->json('item.id');

        $this->actingAs($this->owner)
            ->deleteJson($this->route('dossiers.series.annexes.destroy', ['item' => $item]))
            ->assertOk();

        $this->assertDatabaseMissing('article_series_items', ['id' => $item]);
        // Le fichier reste dans son Dossier : il cesse d'etre ordonne, rien de plus.
        $this->assertDatabaseHas('dossier_files', [
            'id' => $fichier->id,
            'dossier_id' => $this->dossier->id,
        ]);
    }

    // ── Plusieurs Series par Dossier ────────────────────────────────────────

    public function test_a_dossier_can_hold_several_series(): void
    {
        foreach (['Premiere', 'Seconde'] as $titre) {
            $racine = $this->article("Racine {$titre}");

            $this->actingAs($this->owner)
                ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
                ->assertOk();
        }

        $this->assertSame(2, ArticleSeries::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_an_explicit_series_id_lifts_the_ambiguity(): void
    {
        $premiere = $this->serie('Premiere');
        $seconde = $this->serie('Seconde');

        $candidat = $this->article('A ranger dans la seconde');

        // Sans identifiant : refuse, parce qu'il y a deux Series.
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.annexes.store'), ['blog_post_id' => $candidat->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['series_id']);

        // Avec l'identifiant : range exactement la ou on l'a dit.
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.annexes.store'), [
                'blog_post_id' => $candidat->id,
                'series_id' => $seconde->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('article_series_items', [
            'blog_post_id' => $candidat->id,
            'article_series_id' => $seconde->id,
        ]);
        $this->assertSame(0, ArticleSeriesItem::where('article_series_id', $premiere->id)->count());
    }

    public function test_a_series_id_from_another_dossier_is_never_reachable(): void
    {
        $this->serie('La mienne');

        $autre = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier voisin',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $racineVoisine = $this->article('Racine voisine', $autre);
        $serieVoisine = ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $autre->id,
            'root_blog_post_id' => $racineVoisine->id,
            'created_by' => $this->owner->id,
        ]);

        $candidat = $this->article('Candidat');

        // L'identifiant est reel, mais il ne designe pas une Serie de CE
        // Dossier : la resolution le refuse avant toute ecriture.
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.annexes.store'), [
                'blog_post_id' => $candidat->id,
                'series_id' => $serieVoisine->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, ArticleSeriesItem::where('article_series_id', $serieVoisine->id)->count());
    }

    public function test_show_lists_every_series_of_the_dossier(): void
    {
        $premiere = $this->serie('Premiere');
        $seconde = $this->serie('Seconde');

        $reponse = $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.series.show'))
            ->assertOk();

        $ids = collect($reponse->json('series_list'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$premiere->id, $seconde->id], $ids);
        // `series` reste la plus ancienne : le pop-up actuel n'en lit qu'une, et
        // il ne doit pas changer de comportement tant que TASK-1096 n'a pas
        // construit l'onglet.
        $this->assertSame($premiere->id, $reponse->json('series.id'));
    }

    public function test_deleting_one_series_leaves_the_other_intact(): void
    {
        $premiere = $this->serie('Premiere');
        $seconde = $this->serie('Seconde');

        $this->actingAs($this->owner)
            ->deleteJson($this->route('dossiers.series.destroy'), ['series_id' => $seconde->id])
            ->assertOk();

        $this->assertDatabaseMissing('article_series', ['id' => $seconde->id]);
        $this->assertDatabaseHas('article_series', ['id' => $premiere->id]);
    }

    /** Une Serie de ce Dossier, avec sa racine. */
    private function serie(string $nom): ArticleSeries
    {
        $racine = $this->article("Racine {$nom}");

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        return ArticleSeries::where('root_blog_post_id', $racine->id)->firstOrFail();
    }
}
