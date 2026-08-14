<?php

namespace Tests\Feature;

use App\Models\ArticleSeries;
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
 * La numerotation d'une Serie : `01`, `02`, …
 *
 * Elle est **calculee depuis le rang**, jamais stockee, et surtout jamais
 * ecrite dans un titre d'Article ou un nom de fichier. C'est la seule facon
 * d'avoir des numeros toujours justes : un numero recopie devient faux au
 * premier deplacement, et il faut alors renommer le travail des gens pour le
 * rattraper.
 *
 * Ces tests le verifient a l'endroit ou ca compte — apres un reordonnancement,
 * apres un retrait, apres une promotion de racine — en comparant a chaque fois
 * l'etat complet des titres avant et apres.
 */
class TASK1096SeriesNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1096', 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier 1096',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function article(string $titre): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'title' => $titre,
            'content' => "Contenu de {$titre}.",
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

    /** L'etat complet des noms, des deux cotes : titres d'Articles et noms de fichiers. */
    private function tousLesNoms(): array
    {
        return [
            'articles' => BlogPost::orderBy('id')->pluck('title')->all(),
            'fichiers' => DossierFile::orderBy('id')->pluck('display_name')->all(),
            'originaux' => DossierFile::orderBy('id')->pluck('original_name')->all(),
        ];
    }

    private function serie(): ArticleSeries
    {
        return ArticleSeries::where('dossier_id', $this->dossier->id)->firstOrFail();
    }

    private function numeros(): array
    {
        return $this->serie()->fresh(['rootBlogPost', 'items'])
            ->numberedContents()
            ->map(fn (array $e) => $e['number'].' '.$e['name'])
            ->all();
    }

    // ── Le calcul ───────────────────────────────────────────────────────────

    public function test_the_root_is_number_one_and_annexes_follow(): void
    {
        $racine = $this->article('La racine');

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        foreach (['Premiere annexe', 'Deuxieme annexe'] as $titre) {
            $this->actingAs($this->owner)->postJson(
                $this->route('dossiers.series.annexes.store'),
                ['blog_post_id' => $this->article($titre)->id]
            )->assertOk();
        }

        $this->assertSame(
            ['01 La racine', '02 Premiere annexe', '03 Deuxieme annexe'],
            $this->numeros()
        );
    }

    public function test_a_series_without_root_starts_at_its_first_item(): void
    {
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['name' => 'Les pieces'])
            ->assertOk();

        foreach (['a.pdf', 'b.pdf'] as $nom) {
            $this->actingAs($this->owner)->postJson(
                $this->route('dossiers.series.annexes.store'),
                ['dossier_file_id' => $this->fichier($nom)->id]
            )->assertOk();
        }

        $this->assertSame(['01 a.pdf', '02 b.pdf'], $this->numeros());
    }

    public function test_numbering_pads_to_two_digits_and_stops_padding_beyond(): void
    {
        // Le remplissage a deux chiffres aligne la colonne ; il ne tronque
        // rien. Au dixieme element le numero fait deja deux caracteres et
        // cesse d'etre complete — `10`, pas `010`.
        $racine = $this->article('La racine');
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        for ($i = 2; $i <= 11; $i++) {
            $this->actingAs($this->owner)->postJson(
                $this->route('dossiers.series.annexes.store'),
                ['blog_post_id' => $this->article("Element {$i}")->id]
            )->assertOk();
        }

        $numeros = $this->serie()->fresh(['rootBlogPost', 'items'])
            ->numberedContents()->pluck('number')->all();

        $this->assertSame('01', $numeros[0]);
        $this->assertSame('09', $numeros[8]);
        $this->assertSame('10', $numeros[9]);
        $this->assertSame('11', $numeros[10]);
    }

    // ── Rien n'est jamais reecrit ───────────────────────────────────────────

    public function test_reordering_never_rewrites_a_title_or_a_filename(): void
    {
        $racine = $this->article('La racine');
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        $a = $this->article('Article annexe');
        $f = $this->fichier('piece-jointe.pdf');

        foreach ([['blog_post_id' => $a->id], ['dossier_file_id' => $f->id]] as $charge) {
            $this->actingAs($this->owner)
                ->postJson($this->route('dossiers.series.annexes.store'), $charge)->assertOk();
        }

        $this->assertSame(['01 La racine', '02 Article annexe', '03 piece-jointe.pdf'], $this->numeros());

        $avant = $this->tousLesNoms();

        $items = $this->serie()->items()->orderBy('position')->pluck('id')->all();

        $this->actingAs($this->owner)
            ->patchJson($this->route('dossiers.series.annexes.reorder'), [
                'items' => array_reverse($items),
            ])
            ->assertOk();

        // Les numeros suivent le nouvel ordre…
        $this->assertSame(['01 La racine', '02 piece-jointe.pdf', '03 Article annexe'], $this->numeros());
        // …et pas un seul nom n'a bouge.
        $this->assertSame($avant, $this->tousLesNoms());
    }

    public function test_removing_an_item_renumbers_without_writing_anything(): void
    {
        $racine = $this->article('La racine');
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        foreach (['Un', 'Deux', 'Trois'] as $titre) {
            $this->actingAs($this->owner)->postJson(
                $this->route('dossiers.series.annexes.store'),
                ['blog_post_id' => $this->article($titre)->id]
            )->assertOk();
        }

        $avant = $this->tousLesNoms();
        $deuxieme = $this->serie()->items()->orderBy('position')->skip(1)->first();

        $this->actingAs($this->owner)
            ->deleteJson($this->route('dossiers.series.annexes.destroy', ['item' => $deuxieme->id]))
            ->assertOk();

        // « Trois » etait 04, il devient 03 — sans que son titre change.
        $this->assertSame(['01 La racine', '02 Un', '03 Trois'], $this->numeros());
        $this->assertSame($avant, $this->tousLesNoms());
    }

    public function test_promoting_a_new_root_renumbers_without_writing_anything(): void
    {
        $racine = $this->article('Ancienne racine');
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        $future = $this->article('Future racine');
        $autre = $this->article('Autre');

        foreach ([$future, $autre] as $post) {
            $this->actingAs($this->owner)->postJson(
                $this->route('dossiers.series.annexes.store'),
                ['blog_post_id' => $post->id]
            )->assertOk();
        }

        $avant = $this->tousLesNoms();

        $this->actingAs($this->owner)
            ->patchJson($this->route('dossiers.series.update'), ['root_blog_post_id' => $future->id])
            ->assertOk();

        // La promotion deplace deux Articles a la fois : c'est la que des
        // numeros ecrits quelque part se seraient contredits.
        $this->assertSame(
            ['01 Future racine', '02 Ancienne racine', '03 Autre'],
            $this->numeros()
        );
        $this->assertSame($avant, $this->tousLesNoms());
    }

    public function test_numbering_is_never_persisted_in_any_column(): void
    {
        $racine = $this->article('La racine');
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.series.store'), ['root_blog_post_id' => $racine->id])
            ->assertOk();

        $this->actingAs($this->owner)->postJson(
            $this->route('dossiers.series.annexes.store'),
            ['blog_post_id' => $this->article('Une annexe')->id]
        )->assertOk();

        $this->assertSame(['01 La racine', '02 Une annexe'], $this->numeros());

        // Aucune colonne ne contient le numero affiche : il n'existe qu'au
        // moment du rendu, deduit du rang.
        $this->assertDatabaseMissing('blog_posts', ['title' => '01 La racine']);
        $this->assertDatabaseMissing('blog_posts', ['title' => '02 Une annexe']);
        $this->assertSame(
            ['La racine', 'Une annexe'],
            BlogPost::orderBy('created_at')->pluck('title')->all()
        );
    }
}
