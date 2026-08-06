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

    // ── L'onglet existe ─────────────────────────────────────────────────────

    public function test_the_dossier_page_offers_a_fourth_tab(): void
    {
        $page = $this->page();

        $page->assertSee('id="tab-series"', false);
        $page->assertSee('id="tabpanel-series"', false);
        $page->assertSee(e(__('dossiers.series_tab')), false);
        // Les trois autres sont intacts : cette tache ajoute, elle ne remplace pas.
        foreach (['tab-contenus', 'tab-fichiers', 'tab-membres'] as $onglet) {
            $page->assertSee('id="'.$onglet.'"', false);
        }
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

        // Les numeros sont rendus, cote a cote avec des noms intacts.
        $page->assertSee('data-series-number', false);
        $page->assertSee('>01<', false);
        $page->assertSee('>02<', false);
        $page->assertSee('>03<', false);
        $page->assertSee('piece.pdf', false);
        $page->assertSee('La racine', false);

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

        // Des boutons nommes, pas seulement une poignee de glissement.
        $page->assertSee(e(__('dossiers.series_move_up_label', ['name' => 'Un'])), false);
        $page->assertSee(e(__('dossiers.series_move_down_label', ['name' => 'Un'])), false);
        $page->assertSee('dossierSeriesReorder', false);
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
        // Lire, oui ; classer, non — et pas seulement en apparence : la route
        // le refuserait aussi, ce que verifie DossierSeriesTest.
        $page->assertDontSee('dossierSeriesReorder', false);
        $page->assertDontSee(e(__('dossiers.series_move_up_label', ['name' => 'Une annexe'])), false);
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
