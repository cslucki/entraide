<?php

namespace Tests\Feature;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1697 — la fiche profil gagne deux cards : les Boucles, et les derniers
 * commentaires de blog. Et « Articles publies » devient « Blog ».
 *
 * ## Les pieges tenus ici
 *
 * 1. **`loop_members.organization_id` est nullable** — 3 lignes sur 24 y sont
 *    NULL en base. Filtrer le tenant dessus perdrait ces appartenances EN
 *    SILENCE. L'autorite est `loops.organization_id`, comme a l'annuaire.
 * 2. **Un commentaire laisse sur un article non publie menerait a un lien
 *    mort.** L'article d'accueil est donc filtre publie ET de l'Organization.
 * 3. **La limite de 5 doit porter sur les lignes RETENUES**, pas sur un lot
 *    filtre apres coup — sinon un membre tres bavard sur des brouillons
 *    afficherait une card vide.
 */
class TASK1697ProfileLoopsAndCommentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1697p',
            'is_active' => true,
            'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            // PAS de `slug` : poser `users.slug` ne rend PAS la fiche
            // joignable. `UserSlugService::resoudre()` interroge la table
            // `user_slugs`, qui est l'autorite ; sans inscription la route
            // rend 404. On arrive donc par l'identifiant, comme 30 autres
            // routes du produit.
        ]);
    }

    // ── Le libelle ──────────────────────────────────────────────────────────

    public function test_la_section_des_articles_s_appelle_Blog(): void
    {
        $this->article('published');

        $html = $this->fiche();

        $this->assertStringContainsString('>'.__('profile.blog_section').'<', $html);
        $this->assertSame('Blog', __('profile.blog_section'));
        $this->assertStringNotContainsString('Articles publiés', $html);
    }

    // ── La card des Boucles ─────────────────────────────────────────────────

    public function test_les_boucles_apparaissent_avec_un_lien_scope(): void
    {
        $boucle = $this->boucle('Boucle Alpha');
        $this->appartenance($boucle, 'member');

        $html = $this->fiche();

        $this->assertStringContainsString('data-profile-loops', $html);
        $this->assertStringContainsString('data-profile-loop="'.$boucle->id.'"', $html);
        $this->assertStringContainsString(
            e(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $boucle->slug,
            ])),
            $html
        );
    }

    public function test_les_trois_roles_sont_listes(): void
    {
        $boucles = [];
        foreach (['owner', 'facilitator', 'member'] as $role) {
            $b = $this->boucle('Boucle '.$role);
            $this->appartenance($b, $role);
            $boucles[] = $b;
        }

        $html = $this->fiche();

        foreach ($boucles as $b) {
            $this->assertStringContainsString('data-profile-loop="'.$b->id.'"', $html);
        }
    }

    public function test_sans_boucle_la_card_disparait(): void
    {
        $html = $this->fiche();

        $this->assertStringNotContainsString('data-profile-loops', $html);
    }

    public function test_une_appartenance_inactive_n_est_pas_listee(): void
    {
        $boucle = $this->boucle('Boucle quittee');
        $this->appartenance($boucle, 'member', 'left');

        $this->assertStringNotContainsString('data-profile-loop="'.$boucle->id.'"', $this->fiche());
    }

    public function test_une_boucle_archivee_n_est_pas_listee(): void
    {
        $boucle = $this->boucle('Boucle archivee', 'archived');
        $this->appartenance($boucle, 'owner');

        $this->assertStringNotContainsString('data-profile-loop="'.$boucle->id.'"', $this->fiche());
    }

    public function test_une_boucle_d_une_autre_organization_n_est_pas_listee(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697q', 'is_active' => true]);
        $boucle = Loop::factory()->create([
            'organization_id' => $autreOrg->id,
            'name' => 'Boucle etrangere',
            'slug' => 'boucle-etrangere-'.Str::random(5),
            'status' => 'active',
        ]);
        $this->appartenance($boucle, 'member');

        $html = $this->fiche();

        $this->assertStringNotContainsString('data-profile-loop="'.$boucle->id.'"', $html);
        $this->assertStringNotContainsString(e('Boucle etrangere'), $html);
    }

    /** Le piege : la colonne nullable de la ligne d'appartenance. */
    public function test_une_appartenance_sans_organization_id_est_quand_meme_listee(): void
    {
        $boucle = $this->boucle('Boucle sans org sur la ligne');

        LoopMember::query()->create([
            'loop_id' => $boucle->id,
            'user_id' => $this->membre->id,
            'role' => 'member',
            'status' => 'active',
            'organization_id' => null,
        ]);

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $boucle->id,
            'organization_id' => null,
        ]);

        $this->assertStringContainsString('data-profile-loop="'.$boucle->id.'"', $this->fiche());
    }

    // ── La card des commentaires ────────────────────────────────────────────

    public function test_les_commentaires_apparaissent_avec_le_lien_vers_l_article(): void
    {
        $article = $this->article('published', 'Article commente');
        $commentaire = $this->commentaire($article, 'Un commentaire de mesure.');

        $html = $this->fiche();

        $this->assertStringContainsString('data-profile-comments', $html);
        $this->assertStringContainsString('data-profile-comment="'.$commentaire->id.'"', $html);
        $this->assertStringContainsString(e('Un commentaire de mesure.'), $html);
        $this->assertStringContainsString(
            e(route('organization.blog.show', [
                'organization' => $this->organization->slug,
                'post' => $article->slug,
            ])),
            $html
        );
    }

    public function test_sans_commentaire_la_card_disparait(): void
    {
        $this->assertStringNotContainsString('data-profile-comments', $this->fiche());
    }

    /** Un commentaire sur un BROUILLON menerait a un lien mort. */
    public function test_un_commentaire_sur_un_article_non_publie_n_est_pas_listee(): void
    {
        $brouillon = $this->article('draft', 'Brouillon');
        $commentaire = $this->commentaire($brouillon, 'Commentaire sur brouillon.');

        $html = $this->fiche();

        $this->assertStringNotContainsString('data-profile-comment="'.$commentaire->id.'"', $html);
        $this->assertStringNotContainsString(e('Commentaire sur brouillon.'), $html);
    }

    public function test_un_commentaire_sur_un_article_d_une_autre_organization_n_est_pas_listee(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697r', 'is_active' => true]);
        $etranger = BlogPost::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $autreOrg->id,
            'title' => 'Article etranger',
            'slug' => 'article-etranger-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $commentaire = $this->commentaire($etranger, 'Commentaire ailleurs.');

        $html = $this->fiche();

        $this->assertStringNotContainsString('data-profile-comment="'.$commentaire->id.'"', $html);
        $this->assertStringNotContainsString(e('Commentaire ailleurs.'), $html);
    }

    public function test_un_commentaire_non_approuve_n_est_pas_listee(): void
    {
        $article = $this->article('published');
        $commentaire = $this->commentaire($article, 'Commentaire en attente.', false);

        $this->assertStringNotContainsString('data-profile-comment="'.$commentaire->id.'"', $this->fiche());
    }

    /**
     * La limite porte sur les lignes RETENUES : six commentaires publiables
     * donnent cinq lignes, et non « cinq lues puis filtrees ».
     */
    public function test_la_limite_porte_sur_les_commentaires_retenus(): void
    {
        $article = $this->article('published');
        $brouillon = $this->article('draft');

        // trois commentaires sur un brouillon, plus anciens : ils ne doivent pas
        // consommer la limite
        foreach (range(1, 3) as $i) {
            $this->commentaire($brouillon, 'Brouillon '.$i);
        }
        foreach (range(1, 6) as $i) {
            $this->commentaire($article, 'Visible '.$i);
        }

        $html = $this->fiche();

        $trouves = preg_match_all('/data-profile-comment="/', $html);
        $this->assertSame(5, $trouves, 'La card doit porter exactement 5 commentaires.');
        $this->assertStringNotContainsString(e('Brouillon 1'), $html);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function boucle(string $nom, string $statut = 'active'): Loop
    {
        return Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => $nom,
            'slug' => Str::slug($nom).'-'.Str::random(5),
            'status' => $statut,
        ]);
    }

    private function appartenance(Loop $boucle, string $role, string $statut = 'active'): LoopMember
    {
        return LoopMember::query()->create([
            'loop_id' => $boucle->id,
            'user_id' => $this->membre->id,
            'role' => $role,
            'status' => $statut,
            'organization_id' => $boucle->organization_id,
        ]);
    }

    private function article(string $statut, string $titre = 'Article de mesure'): BlogPost
    {
        return BlogPost::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'title' => $titre,
            'slug' => Str::slug($titre).'-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => $statut,
            'published_at' => $statut === 'published' ? now()->subDay() : null,
        ]);
    }

    private function commentaire(BlogPost $article, string $contenu, bool $approuve = true): BlogComment
    {
        return BlogComment::query()->create([
            'blog_post_id' => $article->id,
            'user_id' => $this->membre->id,
            'organization_id' => $article->organization_id,
            'content' => $contenu,
            'is_approved' => $approuve,
        ]);
    }

    private function fiche(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->membre->profileRouteKey(),
            ]))
            ->assertOk()
            ->getContent();
    }
}
