<?php

namespace Tests\Feature;

use App\Livewire\LoopDossiersCard;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les actions de la Card Dossiers restent dans la Boucle.
 *
 * « Ecrire un article » posait l'utilisateur sur l'onglet contenus du Dossier ;
 * il pose desormais un brouillon **deja lie** au Dossier racine et a la Boucle,
 * puis ouvre l'editeur Blog existant. « Deposer un fichier » quittait la
 * Boucle ; la Card porte desormais le depot, vers l'endpoint d'upload existant
 * — memes policies, meme stockage, et l'ecran du Dossier reste a un clic.
 */
class TASK1121DossierCardActionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $proprietaire;

    private User $membre;

    private Loop $boucle;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);
        $this->orgB = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->orgA->id]);

        app()->instance('current_organization', $this->orgA);

        $this->boucle = (new LoopService)->createLoop($this->proprietaire, 'Boucle Card QA')->fresh();
        (new LoopService)->addMemberByUserId($this->boucle, $this->membre->id);

        $this->racine = Dossier::where('loop_id', $this->boucle->id)->firstOrFail();
    }

    private function ecrire(User $user, string $titre): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(route('organization.loops.dossier.articles.store', [
            'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
        ]), ['title' => $titre]);
    }

    // ── Ecrire un article ───────────────────────────────────────────────────

    public function test_the_button_creates_a_draft_linked_to_dossier_and_loop(): void
    {
        $reponse = $this->ecrire($this->proprietaire, 'Notes de la Boucle');

        $post = BlogPost::where('title', 'Notes de la Boucle')->firstOrFail();

        // L'editeur Blog existant s'ouvre — aucun second editeur.
        $reponse->assertRedirect(route('organization.blog.edit', [
            'organization' => $this->orgA->slug, 'post' => $post->slug,
        ]));

        // Les trois liens, poses d'un coup.
        $this->assertSame($this->orgA->id, $post->organization_id);
        $this->assertSame('draft', $post->status);
        $this->assertTrue($this->racine->articles()->whereKey($post->id)->exists());
        $this->assertTrue($post->loops()->whereKey($this->boucle->id)->exists());
    }

    public function test_two_identical_titles_do_not_collide_on_the_slug(): void
    {
        $this->ecrire($this->proprietaire, 'Notes');
        $this->ecrire($this->proprietaire, 'Notes');

        $slugs = BlogPost::where('title', 'Notes')->pluck('slug');

        $this->assertCount(2, $slugs);
        $this->assertSame(2, $slugs->unique()->count());
    }

    public function test_a_simple_member_cannot_create_from_the_card(): void
    {
        // La meme porte que l'ecran du Dossier : DossierPolicy::update delegue
        // a la Boucle, qu'un simple membre n'administre pas.
        $this->ecrire($this->membre, 'Tentative')->assertForbidden();

        $this->assertDatabaseMissing('blog_posts', ['title' => 'Tentative']);
    }

    public function test_an_archived_loop_refuses_the_article(): void
    {
        $this->boucle->forceFill(['status' => 'archived'])->save();

        $this->ecrire($this->proprietaire, 'Trop tard')->assertForbidden();
    }

    public function test_another_organization_gets_a_404(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->orgB->id]);

        // Meme un titre valide : la Boucle n'est pas de son Organization.
        $this->actingAs($etranger)->post(route('organization.loops.dossier.articles.store', [
            'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
        ]), ['title' => 'Intrusion'])->assertNotFound();

        $this->assertDatabaseMissing('blog_posts', ['title' => 'Intrusion']);
    }

    // ── La Card ─────────────────────────────────────────────────────────────

    public function test_the_card_offers_the_inline_actions_to_the_owner(): void
    {
        $this->withSession(['locale' => 'fr']);

        Livewire::actingAs($this->proprietaire)
            ->test(LoopDossiersCard::class, ['loop' => $this->boucle])
            ->assertSee(__('loops.cards.dossiers.create_article'))
            ->assertSee(__('loops.cards.dossiers.article_start'))
            ->assertSee(__('loops.cards.dossiers.upload_file'))
            // Les deux URLs des gestes rapides sont dans le rendu. Celle du
            // formulaire est un attribut HTML ; celle du depot passe par @js,
            // qui echappe les slashes — on cherche donc sa forme JSON.
            ->assertSee(route('organization.loops.dossier.articles.store', [
                'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
            ]), false)
            ->assertSee(str_replace('/', '\/', route('organization.dossiers.files.store', [
                'organization' => $this->orgA->slug, 'dossier' => $this->racine->id,
            ])), false)
            // « Ouvrir » reste la pour la gestion documentaire complete.
            ->assertSee(__('loops.cards.dossiers.open'));
    }

    public function test_the_card_offers_no_write_gesture_to_a_simple_member(): void
    {
        Livewire::actingAs($this->membre)
            ->test(LoopDossiersCard::class, ['loop' => $this->boucle])
            ->assertDontSee(__('loops.cards.dossiers.article_start'))
            ->assertDontSee(__('loops.cards.dossiers.uploading'));
    }

    public function test_an_archived_loop_shows_the_card_read_only(): void
    {
        $this->boucle->forceFill(['status' => 'archived'])->save();

        Livewire::actingAs($this->proprietaire)
            ->test(LoopDossiersCard::class, ['loop' => $this->boucle->fresh()])
            ->assertSee(__('loops.cards.dossiers.archived_read_only'))
            ->assertDontSee(__('loops.cards.dossiers.article_start'));
    }
}
