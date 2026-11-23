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
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1884 correctif 6b (#3) — bouton « Modifier » dans les Cards Articles et
 * Dossiers d'une Boucle, pour l'auteur, les co-auteurs, le proprietaire et les
 * animateurs ; jamais pour un simple membre ; cout borne (pas de requete par ligne).
 */
class TASK1884LoopCardsModifyButtonTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $proprietaire;

    private User $animateur;

    private User $auteur;

    private User $coAuteur;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        [$this->proprietaire, $this->animateur, $this->auteur, $this->coAuteur, $this->membre] = User::factory()->count(5)->create(['organization_id' => $this->org->id])->all();
        app()->instance('current_organization', $this->org);

        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->proprietaire, 'Redaction')->fresh();
        $this->loop->forceFill(['type' => 'writing'])->save();
        $loops->addMember($this->loop, $this->animateur, 'facilitator');
        foreach ([$this->auteur, $this->coAuteur, $this->membre] as $m) {
            $loops->addMember($this->loop, $m, 'member');
        }
        LoopCard::firstOrCreate(['loop_id' => $this->loop->id, 'card_key' => 'core.article'], ['organization_id' => $this->org->id, 'enabled' => true]);
        $this->dossier = Dossier::firstOrCreate(['loop_id' => $this->loop->id], [
            'organization_id' => $this->org->id, 'owner_id' => $this->proprietaire->id, 'name' => 'Dossier', 'visibility' => 'loop',
        ]);
    }

    private function article(string $titre, string $statut): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->org->id, 'user_id' => $this->auteur->id, 'title' => $titre,
            'slug' => Str::slug($titre).'-'.Str::random(5), 'content' => 'x', 'status' => $statut, 'audience' => 'loop',
            'published_at' => $statut === 'published' ? now() : null,
        ]);
        DossierBlogPost::create(['organization_id' => $this->org->id, 'dossier_id' => $this->dossier->id, 'blog_post_id' => $post->id, 'added_by' => $this->auteur->id, 'position' => 0]);
        $post->coAuthors()->attach($this->coAuteur->id, ['role' => 'coauthor', 'added_by' => $this->auteur->id]);

        return $post->fresh();
    }

    private function edition(BlogPost $post): string
    {
        return route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $post->slug]);
    }

    public function test_modifier_visible_pour_auteur_co_auteur_proprietaire_animateur_jamais_pour_un_membre(): void
    {
        $publie = $this->article('Article publie', 'published');
        $brouillon = $this->article('Brouillon en cours', 'draft');

        foreach ([$this->proprietaire, $this->animateur, $this->coAuteur] as $qui) {
            Livewire::actingAs($qui)->test(LoopArticleCard::class, ['loop' => $this->loop->fresh()])
                ->assertSeeHtml('href="'.$this->edition($publie).'"')
                ->assertSeeHtml('href="'.$this->edition($brouillon).'"');
            Livewire::actingAs($qui)->test(LoopDossiersCard::class, ['loop' => $this->loop->fresh()])
                ->assertSeeHtml('data-dossier-card-modify')
                ->assertSeeHtml('href="'.$this->edition($publie).'"');
        }

        // L'auteur : son publie (le brouillon passe par « Reprendre », deja la).
        Livewire::actingAs($this->auteur)->test(LoopArticleCard::class, ['loop' => $this->loop->fresh()])
            ->assertSeeHtml('href="'.$this->edition($publie).'"');

        foreach ([LoopArticleCard::class, LoopDossiersCard::class] as $card) {
            Livewire::actingAs($this->membre)->test($card, ['loop' => $this->loop->fresh()])
                ->assertDontSeeHtml($this->edition($publie))
                ->assertDontSeeHtml($this->edition($brouillon));
        }
    }

    public function test_boucle_archivee_aucun_bouton_modifier(): void
    {
        $publie = $this->article('Article publie', 'published');
        $this->loop->forceFill(['status' => 'archived'])->save();

        Livewire::actingAs($this->proprietaire)->test(LoopArticleCard::class, ['loop' => $this->loop->fresh()])
            ->assertDontSeeHtml($this->edition($publie));
    }

    public function test_le_cout_ne_croit_pas_avec_le_nombre_d_articles(): void
    {
        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->coAuteur)->test(LoopDossiersCard::class, ['loop' => $this->loop->fresh()]);
            Livewire::actingAs($this->coAuteur)->test(LoopArticleCard::class, ['loop' => $this->loop->fresh()]);

            return count(DB::getQueryLog());
        };

        $this->article('Un', 'published');
        $avant = $compter();
        foreach (range(2, 6) as $i) {
            $this->article('Article '.$i, $i % 2 ? 'draft' : 'published');
        }

        $this->assertSame($avant, $compter());
    }
}
