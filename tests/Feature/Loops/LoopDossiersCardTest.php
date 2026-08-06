<?php

namespace Tests\Feature\Loops;

use App\Livewire\LoopDossiersCard;
use App\Models\ArticleSeries;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La Card Dossiers.
 *
 * Elle ne cree aucun systeme documentaire : toute Boucle possede deja un Dossier
 * racine, et la Card est une fenetre dessus. Ces tests portent donc sur ce qui
 * peut reellement casser — ce qu'on voit, ce qu'on n'a pas le droit de voir, et
 * ce qu'une Card eteinte doit refuser sans rien detruire.
 */
class LoopDossiersCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $stranger;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->organization->forceFill(['admin_id' => $this->owner->id])->save();

        $this->loop = (new LoopService)->createLoop($this->owner, 'Boucle documentaire');

        LoopMember::factory()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->member->id,
            'status' => 'active',
            'role' => 'member',
        ]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::where('loop_id', $this->loop->id)->firstOrFail();

        app(LoopCardCompositionService::class)->enable($this->loop, 'core.dossiers');
        $this->loop->refresh();
    }

    private function attachArticle(string $title, ?User $author = null): BlogPost
    {
        // Pas de fabrique pour BlogPost dans ce depot : on cree comme le fait
        // le code reel.
        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => ($author ?? $this->owner)->id,
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title).'-'.\Illuminate\Support\Str::random(6),
            'content' => '<p></p>',
            'status' => 'draft',
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->owner->id,
            'position' => DossierBlogPost::where('dossier_id', $this->dossier->id)->count() + 1,
        ]);

        return $post;
    }

    private function addFile(string $name): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->owner->id,
            'disk' => 'local',
            'path' => 'dossiers/'.$this->dossier->id.'/'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $name),
            'source' => 'upload',
        ]);
    }

    // ── Une seule declaration ───────────────────────────────────────────────

    public function test_the_card_is_declared_once_in_the_registry(): void
    {
        $registry = app(LoopCardRegistry::class);

        $this->assertContains('core.dossiers', $registry->gridKeys());
        $this->assertSame(LoopCardRegistry::PLACEMENT_GRID, $registry->placementOf('core.dossiers'));

        // Une seule occurrence dans le catalogue : aucune seconde liste.
        $keys = array_column(config('loop_cards.cards'), 'key');
        $this->assertSame(1, count(array_keys($keys, 'core.dossiers', true)));
    }

    // ── Ce que la Card montre ───────────────────────────────────────────────

    public function test_the_header_names_the_root_dossier(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee($this->dossier->name);
    }

    public function test_the_empty_state_is_shown_when_nothing_but_the_root_document_exists(): void
    {
        // Une Boucle neuve porte deja son document racine : on vide pour
        // atteindre l'etat reellement vide.
        DossierBlogPost::where('dossier_id', $this->dossier->id)->delete();

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.dossiers.empty_title'));
    }

    public function test_recent_articles_are_listed(): void
    {
        $this->attachArticle('Compte rendu de septembre');

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee('Compte rendu de septembre')
            ->assertSee(__('loops.cards.dossiers.recent_articles'));
    }

    public function test_recent_files_are_listed(): void
    {
        $this->addFile('statuts.pdf');

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee('statuts.pdf')
            ->assertSee(__('loops.cards.dossiers.recent_files'));
    }

    public function test_series_are_listed(): void
    {
        $root = $this->attachArticle('Le fil rouge');

        ArticleSeries::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->owner->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.dossiers.series'))
            ->assertSee('Le fil rouge');
    }

    public function test_the_card_never_loads_the_whole_dossier(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->attachArticle(sprintf('Article %02d', $i));
        }

        $html = Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->html();

        // Cinq au plus : une Card n'est pas une liste.
        $shown = 0;
        for ($i = 1; $i <= 12; $i++) {
            if (str_contains($html, sprintf('Article %02d', $i))) {
                $shown++;
            }
        }

        $this->assertLessThanOrEqual(5, $shown);
    }

    public function test_the_most_recent_articles_come_first(): void
    {
        $old = $this->attachArticle('Le plus ancien');
        $old->forceFill(['updated_at' => now()->subYear()])->saveQuietly();

        $fresh = $this->attachArticle('Le plus recent');
        $fresh->forceFill(['updated_at' => now()])->saveQuietly();

        $html = Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->html();

        $this->assertLessThan(
            strpos($html, 'Le plus ancien'),
            strpos($html, 'Le plus recent'),
            'Le plus recent doit apparaitre avant le plus ancien.',
        );
    }

    // ── Le compteur ─────────────────────────────────────────────────────────

    public function test_the_counter_adds_articles_and_files_only(): void
    {
        DossierBlogPost::where('dossier_id', $this->dossier->id)->delete();

        $root = $this->attachArticle('Racine');
        $this->attachArticle('Annexe');
        $this->addFile('piece.pdf');

        // Une Serie dont l'annexe est deja un Article du Dossier : la compter
        // ferait un double comptage, c'est pourquoi elle ne compte pas.
        ArticleSeries::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->owner->id,
        ]);

        $composition = collect(app(LoopCardCompositionService::class)->compositionFor($this->loop))
            ->firstWhere('key', 'core.dossiers');

        $this->assertSame(3, $composition['data_count']);
    }

    // ── Qui voit quoi ───────────────────────────────────────────────────────

    public function test_a_member_sees_the_dossier(): void
    {
        $this->attachArticle('Visible aux membres');

        Livewire::actingAs($this->member)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee('Visible aux membres');
    }

    public function test_someone_of_another_organization_sees_nothing(): void
    {
        $this->attachArticle('Confidentiel');

        Livewire::actingAs($this->stranger)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertDontSee('Confidentiel');
    }

    public function test_a_forged_dossier_id_never_crosses_an_organization(): void
    {
        // Le Dossier d'une autre Organization, demande par son identifiant.
        $otherOwner = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Boucle etrangere');
        $otherDossier = Dossier::where('loop_id', $otherLoop->id)->firstOrFail();

        $this->assertFalse($this->owner->can('view', $otherDossier));
        $this->assertFalse($this->owner->can('update', $otherDossier));
    }

    // ── Ecrire ──────────────────────────────────────────────────────────────

    public function test_the_owner_is_offered_the_two_writing_paths(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.dossiers.create_article'))
            ->assertSee(__('loops.cards.dossiers.upload_file'));
    }

    public function test_a_plain_member_is_offered_neither(): void
    {
        // DossierPolicy delegue a LoopPolicy::update pour un Dossier de Boucle :
        // un membre simple lit, il n'ecrit pas. La Card ne propose donc pas un
        // geste que le serveur refuserait.
        Livewire::actingAs($this->member)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertDontSee(__('loops.cards.dossiers.create_article'))
            ->assertDontSee(__('loops.cards.dossiers.upload_file'));
    }

    // ── Card eteinte ────────────────────────────────────────────────────────

    public function test_a_disabled_card_leaves_the_workspace(): void
    {
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.dossiers');
        $this->loop->refresh();

        $this->assertNotContains(
            'core.dossiers',
            app(LoopTypeRegistry::class)->activeCardsFor($this->loop),
        );
    }

    public function test_a_disabled_card_leaves_the_dossier_governed_by_its_own_rules(): void
    {
        // Le Dossier racine est une infrastructure commune : la Card Dossiers en
        // est une vue, et d'autres viendront — Article, Support de cours,
        // Travaux a rendre, le document racine, les fichiers de la Boucle.
        // Eteindre une interface ne doit pas eteindre le systeme documentaire
        // qu'elle regarde.
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.dossiers');
        $this->loop->refresh();
        $this->dossier->refresh();

        // Les droits du Dossier continuent de s'appliquer, ni plus ni moins.
        $this->assertTrue($this->owner->can('view', $this->dossier));
        $this->assertTrue($this->owner->can('update', $this->dossier));
        $this->assertTrue($this->owner->can('manageFiles', $this->dossier));

        // Et ils restent des droits : un membre simple n'ecrit pas davantage.
        $this->assertTrue($this->member->can('view', $this->dossier));
        $this->assertFalse($this->member->can('update', $this->dossier));
    }

    public function test_a_disabled_card_never_opens_the_dossier_to_another_organization(): void
    {
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.dossiers');
        $this->loop->refresh();
        $this->dossier->refresh();

        $this->assertFalse($this->stranger->can('view', $this->dossier));
        $this->assertFalse($this->stranger->can('update', $this->dossier));
    }

    public function test_an_archived_loop_stays_read_only_whatever_the_card_state(): void
    {
        // L'archivage est porte par LoopPolicy::update, pas par la Card : il
        // tient donc que la Card soit allumee ou eteinte.
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.dossiers');
        $this->loop->update(['status' => 'archived', 'archived_at' => now()]);
        $this->loop->refresh();
        $this->dossier->refresh();

        $this->assertTrue($this->owner->can('view', $this->dossier));
        $this->assertFalse($this->owner->can('update', $this->dossier));
    }

    public function test_disabling_deletes_nothing_and_reactivating_finds_it_all(): void
    {
        $this->attachArticle('Un texte qui doit survivre');
        $this->addFile('un-fichier-qui-doit-survivre.pdf');

        $articlesBefore = $this->dossier->articles()->count();
        $filesBefore = $this->dossier->files()->count();

        $composition = app(LoopCardCompositionService::class);
        $composition->disable($this->loop, 'core.dossiers');
        $this->loop->refresh();
        $this->dossier->refresh();

        $this->assertSame($articlesBefore, $this->dossier->articles()->count());
        $this->assertSame($filesBefore, $this->dossier->files()->count());

        $composition->enable($this->loop, 'core.dossiers');
        $this->loop->refresh();

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee('Un texte qui doit survivre')
            ->assertSee('un-fichier-qui-doit-survivre.pdf');
    }

    // ── Boucle archivee ─────────────────────────────────────────────────────

    public function test_an_archived_loop_reads_but_never_writes(): void
    {
        $this->attachArticle('Memoire de la Boucle');

        $this->loop->update(['status' => 'archived', 'archived_at' => now()]);
        $this->loop->refresh();
        $this->dossier->refresh();

        Livewire::actingAs($this->owner)
            ->test(LoopDossiersCard::class, ['loop' => $this->loop])
            ->assertSee('Memoire de la Boucle')
            ->assertDontSee(__('loops.cards.dossiers.create_article'))
            ->assertDontSee(__('loops.cards.dossiers.upload_file'));

        $this->assertFalse($this->owner->can('update', $this->dossier));
    }

    // ── Preset Communaute ───────────────────────────────────────────────────

    public function test_the_general_preset_carries_the_three_distinctive_cards(): void
    {
        $preset = config('loop_types.types.general.cards');

        $this->assertContains('core.polls', $preset);
        $this->assertContains('core.events', $preset);
        $this->assertContains('core.dossiers', $preset);

        // Le cadre permanent reste declare, hors grille.
        $this->assertContains('core.manifesto', $preset);
        $this->assertContains('core.members', $preset);
    }

    public function test_the_grid_never_shows_more_than_its_slots(): void
    {
        $registry = app(LoopCardRegistry::class);

        $this->assertLessThanOrEqual(
            $registry->gridSlots(),
            count($registry->workspaceCardsFor($this->loop, $this->owner)),
        );
    }
}
