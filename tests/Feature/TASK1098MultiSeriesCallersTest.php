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
use App\Services\Dossiers\DossierSeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Les appelants restes sur « une seule Serie par Dossier ».
 *
 * TASK-1095 a leve la cardinalite mais n'a pas mis a jour deux chemins qui
 * faisaient `->first()` sur les Series d'un Dossier. L'un d'eux **ecrit**, et
 * la migration l'a rendu silencieusement destructeur : `root_blog_post_id` est
 * devenue nullable, donc promouvoir un item de fichier — dont le
 * `blog_post_id` est NULL — ne levait plus rien. Il fabriquait une Serie sans
 * racine et sans nom, un etat que le service refuse explicitement de creer.
 *
 * Ces tests fixent les trois comportements attendus.
 */
class TASK1098MultiSeriesCallersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1098', 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier 1098',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        app()->instance('current_organization', $this->org);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function article(string $titre): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'title' => $titre,
            'slug' => Str::slug($titre).'-'.uniqid(),
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->owner->id,
            'position' => 0,
        ]);

        return $post;
    }

    private function fichier(string $nom): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->owner->id,
            'disk' => 'local',
            'path' => 'dossiers/'.$this->dossier->id.'/'.$nom,
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $nom.Str::random(6)),
            'source' => 'upload',
        ]);
    }

    private function serie(BlogPost $racine): ArticleSeries
    {
        return ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'root_blog_post_id' => $racine->id,
            'created_by' => $this->owner->id,
        ]);
    }

    private function service(): DossierSeriesService
    {
        return app(DossierSeriesService::class);
    }

    private function route(string $name, array $extra = []): string
    {
        return route("organization.{$name}", array_merge([
            'organization' => $this->org->slug,
            'dossier' => $this->dossier->id,
        ], $extra));
    }

    // ── Le defaut bloquant : une Serie sans racine ni nom ────────────────────

    public function test_detaching_a_root_never_promotes_a_file_to_root(): void
    {
        $racine = $this->article('La racine');
        $serie = $this->serie($racine);
        $fichier = $this->fichier('seul-item.pdf');

        $this->service()->addItem($serie, $fichier, $this->owner);

        // Detacher la racine depuis l'editeur de blog.
        $this->actingAs($this->owner)
            ->deleteJson(route('blog.dossier.detach', ['post' => $racine->slug]))
            ->assertOk();

        $apres = $serie->fresh();

        // La Serie survit — les fichiers ranges par quelqu'un ne sont pas jetes
        // parce qu'un Article s'en va.
        $this->assertNotNull($apres, 'La Serie ne doit pas disparaitre : elle contient encore un fichier.');
        // Et surtout : aucune racine fabriquee a partir d'un fichier.
        $this->assertNull($apres->root_blog_post_id);
        // Sans racine, elle porte un nom — sinon elle n'aurait rien a afficher.
        $this->assertNotSame('', (string) $apres->displayName());
        $this->assertDatabaseHas('article_series_items', [
            'article_series_id' => $serie->id,
            'dossier_file_id' => $fichier->id,
        ]);
    }

    public function test_detaching_a_root_promotes_the_first_article_not_the_first_item(): void
    {
        $racine = $this->article('La racine');
        $serie = $this->serie($racine);

        // Un fichier en tete, un Article ensuite : c'est l'Article qui doit
        // devenir la racine, pas le fichier parce qu'il est premier.
        $this->service()->addItem($serie, $this->fichier('premier.pdf'), $this->owner);
        $suivant = $this->article('Le suivant');
        $this->service()->addItem($serie, $suivant, $this->owner);

        $this->actingAs($this->owner)
            ->deleteJson(route('blog.dossier.detach', ['post' => $racine->slug]))
            ->assertOk();

        $this->assertSame($suivant->id, $serie->fresh()->root_blog_post_id);
    }

    public function test_detaching_reaches_every_series_of_the_dossier(): void
    {
        // Deux Series dans le meme Dossier. L'Article a detacher est la racine
        // de la **seconde** : un `->first()` sans ordre aurait agi sur l'autre
        // et n'aurait rien fait.
        $premiere = $this->serie($this->article('Racine de la premiere'));
        $racineB = $this->article('Racine de la seconde');
        $seconde = $this->serie($racineB);

        $this->service()->addItem($seconde, $this->article('Annexe de la seconde'), $this->owner);

        $this->actingAs($this->owner)
            ->deleteJson(route('blog.dossier.detach', ['post' => $racineB->slug]))
            ->assertOk();

        $this->assertNotSame($racineB->id, $seconde->fresh()->root_blog_post_id);
        // La premiere Serie n'a pas ete touchee.
        $this->assertDatabaseHas('article_series', ['id' => $premiere->id]);
    }

    // ── Le second appelant : la garde de racine ─────────────────────────────

    public function test_an_article_that_is_root_of_any_series_cannot_be_detached(): void
    {
        $this->serie($this->article('Racine de la premiere'));
        $racineB = $this->article('Racine de la seconde');
        $this->serie($racineB);

        // Racine de la **seconde** Serie : la garde doit se declencher, meme si
        // la resolution historique retenait la premiere.
        $this->actingAs($this->owner)
            ->deleteJson($this->route('dossiers.articles.destroy', ['post' => $racineB->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['blog_post_id']);

        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $racineB->id,
        ]);
    }

    // ── series_id mal forme ─────────────────────────────────────────────────

    public function test_a_malformed_series_id_is_refused_not_crashed(): void
    {
        $this->serie($this->article('La racine'));

        // En PostgreSQL la colonne est un `uuid` natif : sans cette validation,
        // la valeur atteignait `whereKey()` et provoquait un 500. En SQLite
        // elle ne trouvait rien, d'ou un 404 — la suite de tests ne pouvait pas
        // voir le defaut. La forme est donc verifiee avant la requete.
        $this->actingAs($this->owner)
            ->deleteJson($this->route('dossiers.series.destroy'), ['series_id' => 'pas-un-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['series_id']);
    }

    // ── Classement : une liste qui se repete est fausse ─────────────────────

    public function test_a_reorder_list_that_repeats_an_item_is_refused(): void
    {
        $racine = $this->article('La racine');
        $serie = $this->serie($racine);

        $a = $this->service()->addItem($serie, $this->article('A'), $this->owner);
        $b = $this->service()->addItem($serie, $this->article('B'), $this->owner);

        $avant = ArticleSeriesItem::where('article_series_id', $serie->id)
            ->orderBy('position')->pluck('position', 'id')->all();

        try {
            $this->service()->reorder($serie, [$a->id, $b->id, $b->id]);
            $this->fail('Une liste qui repete un identifiant doit etre refusee.');
        } catch (ValidationException) {
            // Attendu.
        }

        // Rien n'a bouge, et surtout les rangs sont restes contigus : l'ancienne
        // comparaison laissait passer la liste et ecrivait 0 puis 2.
        $this->assertSame($avant, ArticleSeriesItem::where('article_series_id', $serie->id)
            ->orderBy('position')->pluck('position', 'id')->all());
    }
}
