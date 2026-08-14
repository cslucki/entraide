<?php

namespace Tests\Feature;

use App\Models\ArticleSeries;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'onglet Series du Dossier.
 *
 * Ce qui est verifie ici, c'est ce qui se **rend** : les Series presentes, leurs
 * numeros calcules, et — le point qui compte le plus — le fait qu'on puisse
 * classer **sans glisser**. Un classement qui n'existe qu'au glissement est
 * inaccessible au clavier et impraticable sur un ecran tactile etroit.
 *
 * Le geste de glissement lui-meme n'est pas observable dans cet environnement.
 * Il n'est donc pas teste ici, et n'est presente nulle part comme valide.
 */
class TASK1096SeriesTabTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $reader;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-tab', 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->reader = User::factory()->create(['organization_id' => $this->org->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier onglet',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'user_id' => $this->reader->id,
            'role' => 'reader',
            'added_by' => $this->owner->id,
        ]);
    }

    private function article(string $titre): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'title' => $titre,
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

    private function route(string $name, array $extra = []): string
    {
        return route("organization.{$name}", array_merge([
            'organization' => $this->org->slug,
            'dossier' => $this->dossier->id,
        ], $extra));
    }

    private function serieAvecRacine(string $titreRacine): ArticleSeries
    {
        $racine = $this->article($titreRacine);

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        return ArticleSeries::where('root_blog_post_id', $racine->id)->firstOrFail();
    }

    private function page(?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->get($this->route('dossiers.show'))
            ->assertOk();
    }

    // ── Le point d'entree Series existe ──────────────────────────────────────

    public function test_the_dossier_page_offers_a_series_entry_point(): void
    {
        // TASK-1130 (doctrine finale) : Liste | Grille | Serie sont trois
        // MODES de la meme surface — le point d'entree est le troisieme
        // bouton de la bascule, la sortie est de revenir a Liste ou Grille.
        // Un gestionnaire peut creer une Serie depuis le selecteur du mode.
        $page = $this->page();

        $page->assertSee(e(__('dossiers.series_mode_label')), false);
        $page->assertSee(e(__('dossiers.series_mode_create')), false);
        $page->assertSee(e(__('dossiers.series_mode_pick')), false);
    }

    public function test_an_empty_dossier_explains_what_a_series_is(): void
    {
        $this->page()->assertSee(e(__('dossiers.series_tab_empty_title')), false);
    }

    // ── Ce que l'onglet montre ──────────────────────────────────────────────

    public function test_the_tab_lists_every_series_of_the_dossier(): void
    {
        $this->serieAvecRacine('Premiere racine');
        $this->serieAvecRacine('Seconde racine');

        $page = $this->page();

        $page->assertSee('Premiere racine', false);
        $page->assertSee('Seconde racine', false);
        $this->assertSame(2, ArticleSeries::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_the_tab_shows_computed_numbers_and_mixes_files_in(): void
    {
        $serie = $this->serieAvecRacine('La racine');

        $this->actingAs($this->owner)->postJson(
            $this->route('dossiers.series.annexes.store'),
            ['blog_post_id' => $this->article('Une annexe')->id]
        )->assertOk();

        $this->actingAs($this->owner)->postJson(
            $this->route('dossiers.series.annexes.store'),
            ['dossier_file_id' => $this->fichier('piece.pdf')->id]
        )->assertOk();

        $page = $this->page();

        // TASK-1130 (addendum) : la numerotation 01/02/03 est desormais
        // calculee a l'ecran depuis la projection embarquee (mode Serie) —
        // le rang, jamais une copie. La page porte la projection avec des
        // noms intacts, et l'API rend l'ordre qui produit ces numeros.
        $page->assertSee('seriesMode', false);
        $page->assertSee('piece.pdf', false);
        $page->assertSee('La racine', false);

        $reponse = $this->actingAs($this->owner)->getJson($this->route('dossiers.series.show'))->assertOk();
        $items = $reponse->json('series_list.0.items');
        $this->assertCount(2, $items);
        $this->assertSame('Une annexe', $items[0]['blog_post']['title']);
        $this->assertSame('piece.pdf', $items[1]['dossier_file']['original_name']);
        $this->assertSame('La racine', $reponse->json('series_list.0.root_blog_post.title'));

        // Et aucun nom n'a ete prefixe d'un numero.
        $this->assertDatabaseHas('blog_posts', ['title' => 'La racine']);
        $this->assertDatabaseHas('dossier_files', ['display_name' => 'piece.pdf']);
    }

    // ── Classer sans glisser ────────────────────────────────────────────────

    public function test_an_editor_gets_keyboard_reorder_controls(): void
    {
        $this->serieAvecRacine('La racine');

        foreach (['Un', 'Deux'] as $titre) {
            $this->actingAs($this->owner)->postJson(
                $this->route('dossiers.series.annexes.store'),
                ['blog_post_id' => $this->article($titre)->id]
            )->assertOk();
        }

        $page = $this->page();

        // Des boutons nommes (Monter/Descendre), pas seulement une poignee de
        // glissement — TASK-1130 addendum : ils vivent dans le mode Serie,
        // gates cote SERVEUR par manageSeries (un lecteur ne recoit pas ce
        // markup, voir le test suivant).
        $page->assertSee(e(__('dossiers.move_up')).' — ', false);
        $page->assertSee(e(__('dossiers.move_down')).' — ', false);
        // Une region polie annonce le nouvel ordre sans interrompre.
        $page->assertSee('aria-live="polite"', false);
    }

    public function test_a_reader_sees_the_series_but_no_reorder_control(): void
    {
        $this->serieAvecRacine('La racine');

        $this->actingAs($this->owner)->postJson(
            $this->route('dossiers.series.annexes.store'),
            ['blog_post_id' => $this->article('Une annexe')->id]
        )->assertOk();

        $page = $this->page($this->reader);

        $page->assertSee('La racine', false);
        $page->assertSee('Une annexe', false);
        // Lire, oui ; classer, non — et pas seulement en apparence : le
        // markup des controles (Monter/Descendre, poignee, Ajouter a la
        // serie) est gate cote serveur par manageSeries, et la route le
        // refuserait aussi, ce que verifie DossierSeriesTest.
        $page->assertDontSee(e(__('dossiers.move_up')).' — ', false);
        // La banniere du mode Serie ne lui propose ni l'ajout ni l'aide au
        // classement (le volet heritage « Gestion avancee » garde ses libelles
        // dans sa config, mais aucun declencheur n'existe pour un lecteur).
        $page->assertDontSee(e(__('dossiers.series_mode_hint')), false);
        $page->assertDontSee(e(__('dossiers.series_mode_no_candidates')), false);
    }

    public function test_the_keyboard_reorder_uses_the_same_route_as_the_drag(): void
    {
        $serie = $this->serieAvecRacine('La racine');

        foreach (['Un', 'Deux'] as $titre) {
            $this->actingAs($this->owner)->postJson(
                $this->route('dossiers.series.annexes.store'),
                ['blog_post_id' => $this->article($titre)->id]
            )->assertOk();
        }

        $items = $serie->items()->orderBy('position')->pluck('id')->all();

        // Exactement ce que le bouton « Descendre » envoie : la liste complete
        // dans l'ordre voulu, et l'identifiant de la Serie.
        $this->actingAs($this->owner)
            ->patchJson($this->route('dossiers.series.annexes.reorder'), [
                'items' => array_reverse($items),
                'series_id' => $serie->id,
            ])
            ->assertOk();

        $this->assertSame(
            array_reverse($items),
            $serie->items()->orderBy('position')->pluck('id')->all()
        );
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_the_tab_never_shows_a_series_of_another_dossier(): void
    {
        $this->serieAvecRacine('La mienne');

        $autre = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier voisin',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $racineVoisine = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'title' => 'Racine du voisin',
            'content' => 'c',
            'status' => 'draft',
        ]);
        DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $autre->id,
            'blog_post_id' => $racineVoisine->id,
            'added_by' => $this->owner->id,
            'position' => 0,
        ]);
        ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $autre->id,
            'root_blog_post_id' => $racineVoisine->id,
            'created_by' => $this->owner->id,
        ]);

        $this->page()->assertDontSee('Racine du voisin', false);
    }
}
