<?php

namespace Tests\Feature\Dossiers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierSeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Les invariants d'une Serie, tenus par son service.
 *
 * Ce qui est defendu ici plus que le reste :
 *
 * - **un item porte exactement un contenu** — un Article ou un fichier, jamais
 *   les deux, jamais aucun ;
 * - **un contenu n'appartient qu'a une seule Serie** dans le MVP. Cette regle
 *   est verifiee explicitement par le service, pas laissee aux index uniques :
 *   une violation d'index remonte un message que personne ne comprend ;
 * - **rien n'est jamais supprime** : ni en retirant un item, ni en dissolvant
 *   une Serie, ni en changeant sa racine.
 */
class DossierSeriesServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $auteur;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->auteur->id,
            'name' => 'Dossier de travail',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function service(): DossierSeriesService
    {
        return app(DossierSeriesService::class);
    }

    private function article(string $titre, ?Dossier $dossier = null, ?Organization $org = null): BlogPost
    {
        $org ??= $this->org;

        $post = BlogPost::create([
            'organization_id' => $org->id,
            'user_id' => $this->auteur->id,
            'title' => $titre,
            'slug' => Str::slug($titre).'-'.Str::random(6),
            'content' => '<p></p>',
            'status' => 'draft',
        ]);

        $dossier ??= $this->dossier;

        if ($dossier->organization_id === $org->id) {
            DossierBlogPost::create([
                'organization_id' => $org->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id,
                'added_by' => $this->auteur->id,
                'position' => DossierBlogPost::where('dossier_id', $dossier->id)->count() + 1,
            ]);
        }

        return $post;
    }

    private function fichier(string $nom, ?Dossier $dossier = null): DossierFile
    {
        $dossier ??= $this->dossier;

        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->auteur->id,
            'disk' => 'local',
            'path' => 'dossiers/'.$dossier->id.'/'.$nom,
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $nom.Str::random(4)),
            'source' => 'upload',
        ]);
    }

    // ── Creer ───────────────────────────────────────────────────────────────

    public function test_a_series_can_be_created_around_a_root_article(): void
    {
        $racine = $this->article('La racine');

        $serie = $this->service()->create($this->dossier, $this->auteur, $racine);

        $this->assertSame($racine->id, $serie->root_blog_post_id);
        $this->assertSame('La racine', $serie->displayName());
    }

    public function test_a_series_can_exist_without_any_root_article(): void
    {
        // Une Serie de fichiers n'a pas d'Article racine.
        $serie = $this->service()->create($this->dossier, $this->auteur, null, 'Pieces du marche');

        $this->assertNull($serie->root_blog_post_id);
        $this->assertSame('Pieces du marche', $serie->displayName());
    }

    public function test_a_series_without_root_must_have_a_name(): void
    {
        // Sans racine ni nom, la Serie n'aurait rien a afficher.
        $this->expectException(ValidationException::class);
        $this->service()->create($this->dossier, $this->auteur, null, null);
    }

    public function test_a_dossier_can_hold_several_series(): void
    {
        $this->service()->create($this->dossier, $this->auteur, $this->article('Cadrage'));
        $this->service()->create($this->dossier, $this->auteur, $this->article('Livrables'));
        $this->service()->create($this->dossier, $this->auteur, null, 'Comptes rendus');

        $this->assertSame(3, ArticleSeries::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_an_article_of_another_dossier_can_never_be_a_root(): void
    {
        $autreDossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->auteur->id,
            'name' => 'Ailleurs',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $etranger = $this->article('Ailleurs', $autreDossier);

        $this->expectException(ValidationException::class);
        $this->service()->create($this->dossier, $this->auteur, $etranger);
    }

    // ── Ajouter : Articles et fichiers ──────────────────────────────────────

    public function test_an_article_can_be_added_to_a_series(): void
    {
        $serie = $this->service()->create($this->dossier, $this->auteur, $this->article('Racine'));
        $item = $this->service()->addItem($serie, $this->article('Annexe'), $this->auteur);

        $this->assertSame('article', $item->contentType());
        $this->assertNull($item->dossier_file_id);
    }

    public function test_a_file_can_be_added_to_a_series(): void
    {
        $serie = $this->service()->create($this->dossier, $this->auteur, null, 'Pieces');
        $item = $this->service()->addItem($serie, $this->fichier('previsionnel.xls'), $this->auteur);

        $this->assertSame('file', $item->contentType());
        $this->assertNull($item->blog_post_id);
        $this->assertSame('previsionnel.xls', $item->displayName());
    }

    public function test_a_series_can_mix_articles_and_files(): void
    {
        $serie = $this->service()->create($this->dossier, $this->auteur, $this->article('Le projet'));

        $this->service()->addItem($serie, $this->article('Note de cadrage'), $this->auteur);
        $this->service()->addItem($serie, $this->fichier('budget.xls'), $this->auteur);

        $types = ArticleSeriesItem::where('article_series_id', $serie->id)
            ->orderBy('position')->get()->map->contentType()->all();

        $this->assertSame(['article', 'file'], $types);
    }

    public function test_an_item_never_carries_both_an_article_and_a_file(): void
    {
        // La signature du service l'impose : un seul contenu entre.
        $serie = $this->service()->create($this->dossier, $this->auteur, $this->article('Racine'));
        $this->service()->addItem($serie, $this->article('Un texte'), $this->auteur);
        $this->service()->addItem($serie, $this->fichier('un-fichier.pdf'), $this->auteur);

        foreach (ArticleSeriesItem::where('article_series_id', $serie->id)->get() as $item) {
            $renseignes = (int) ($item->blog_post_id !== null) + (int) ($item->dossier_file_id !== null);
            $this->assertSame(1, $renseignes, 'Un item doit porter exactement un contenu.');
        }
    }

    // ── La regle du MVP, explicite ──────────────────────────────────────────

    public function test_an_article_belongs_to_at_most_one_series(): void
    {
        $a = $this->article('Convoite');
        $s1 = $this->service()->create($this->dossier, $this->auteur, $this->article('Racine 1'));
        $s2 = $this->service()->create($this->dossier, $this->auteur, $this->article('Racine 2'));

        $this->service()->addItem($s1, $a, $this->auteur);

        $this->expectException(ValidationException::class);
        $this->service()->addItem($s2, $a, $this->auteur);
    }

    public function test_a_file_belongs_to_at_most_one_series(): void
    {
        $f = $this->fichier('convoite.pdf');
        $s1 = $this->service()->create($this->dossier, $this->auteur, null, 'Serie A');
        $s2 = $this->service()->create($this->dossier, $this->auteur, null, 'Serie B');

        $this->service()->addItem($s1, $f, $this->auteur);

        $this->expectException(ValidationException::class);
        $this->service()->addItem($s2, $f, $this->auteur);
    }

    public function test_the_root_of_one_series_cannot_join_another(): void
    {
        $racine = $this->article('Racine convoitee');
        $this->service()->create($this->dossier, $this->auteur, $racine);
        $autre = $this->service()->create($this->dossier, $this->auteur, null, 'Autre');

        $this->expectException(ValidationException::class);
        $this->service()->addItem($autre, $racine, $this->auteur);
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_a_file_of_another_dossier_is_refused(): void
    {
        $autreDossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->auteur->id,
            'name' => 'Ailleurs',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $serie = $this->service()->create($this->dossier, $this->auteur, null, 'Pieces');

        $this->expectException(ValidationException::class);
        $this->service()->addItem($serie, $this->fichier('ailleurs.pdf', $autreDossier), $this->auteur);
    }

    public function test_an_article_of_another_organization_is_refused(): void
    {
        $serie = $this->service()->create($this->dossier, $this->auteur, null, 'Pieces');
        $etranger = $this->article('Etranger', null, $this->autreOrg);

        $this->expectException(ValidationException::class);
        $this->service()->addItem($serie, $etranger, $this->auteur);
    }

    // ── Rien n'est jamais supprime ──────────────────────────────────────────

    public function test_removing_an_item_never_deletes_its_content(): void
    {
        $serie = $this->service()->create($this->dossier, $this->auteur, null, 'Pieces');
        $f = $this->fichier('a-retirer.pdf');
        $item = $this->service()->addItem($serie, $f, $this->auteur);

        $this->service()->removeItem($serie, $item);

        $this->assertDatabaseHas('dossier_files', ['id' => $f->id, 'dossier_id' => $this->dossier->id]);
        $this->assertDatabaseMissing('article_series_items', ['id' => $item->id]);
    }

    public function test_dissolving_a_series_never_deletes_its_contents(): void
    {
        $racine = $this->article('Racine');
        $serie = $this->service()->create($this->dossier, $this->auteur, $racine);
        $a = $this->article('Un texte');
        $f = $this->fichier('un-fichier.pdf');
        $this->service()->addItem($serie, $a, $this->auteur);
        $this->service()->addItem($serie, $f, $this->auteur);

        $this->service()->delete($serie);

        $this->assertDatabaseHas('blog_posts', ['id' => $racine->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $a->id]);
        $this->assertDatabaseHas('dossier_files', ['id' => $f->id]);
        $this->assertDatabaseMissing('article_series', ['id' => $serie->id]);
    }

    public function test_a_freed_content_can_join_another_series(): void
    {
        // Corollaire de « une seule Serie » : une fois retire, le contenu
        // redevient disponible.
        $f = $this->fichier('libre.pdf');
        $s1 = $this->service()->create($this->dossier, $this->auteur, null, 'A');
        $s2 = $this->service()->create($this->dossier, $this->auteur, null, 'B');

        $item = $this->service()->addItem($s1, $f, $this->auteur);
        $this->service()->removeItem($s1, $item);

        $nouveau = $this->service()->addItem($s2, $f, $this->auteur);

        $this->assertSame($s2->id, $nouveau->article_series_id);
    }

    // ── Racine et ordre ─────────────────────────────────────────────────────

    public function test_promoting_an_annex_puts_the_former_root_first(): void
    {
        $racine = $this->article('Ancienne racine');
        $serie = $this->service()->create($this->dossier, $this->auteur, $racine);
        $futur = $this->article('Future racine');
        $this->service()->addItem($serie, $futur, $this->auteur);

        $apres = $this->service()->setRoot($serie, $futur, $this->auteur);

        $this->assertSame($futur->id, $apres->root_blog_post_id);

        $premier = ArticleSeriesItem::where('article_series_id', $serie->id)->orderBy('position')->first();
        $this->assertSame($racine->id, $premier->blog_post_id);
    }

    public function test_positions_stay_contiguous_after_every_mutation(): void
    {
        $serie = $this->service()->create($this->dossier, $this->auteur, $this->article('Racine'));

        $items = [];
        foreach (['A', 'B', 'C'] as $n) {
            $items[] = $this->service()->addItem($serie, $this->article("Annexe {$n}"), $this->auteur);
        }
        $this->service()->addItem($serie, $this->fichier('piece.pdf'), $this->auteur);

        $this->service()->removeItem($serie, $items[1]);

        $positions = ArticleSeriesItem::where('article_series_id', $serie->id)
            ->orderBy('position')->pluck('position')->all();

        $this->assertSame(range(0, count($positions) - 1), $positions);
    }

    public function test_reordering_accepts_only_a_complete_and_current_list(): void
    {
        $serie = $this->service()->create($this->dossier, $this->auteur, $this->article('Racine'));
        $a = $this->service()->addItem($serie, $this->article('A'), $this->auteur);
        $b = $this->service()->addItem($serie, $this->fichier('b.pdf'), $this->auteur);

        $this->service()->reorder($serie, [$b->id, $a->id]);

        $ordre = ArticleSeriesItem::where('article_series_id', $serie->id)
            ->orderBy('position')->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $ordre);

        // Une liste incomplete est refusee en entier.
        $this->expectException(ValidationException::class);
        $this->service()->reorder($serie, [$a->id]);
    }
}
