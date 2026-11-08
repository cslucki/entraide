<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1788, correctif 10 — la gestion des commentaires du Flux.
 *
 * Deux demandes de Cyril sur `/admin/flux` : voir tous les commentaires et
 * pouvoir les voir / modifier / supprimer, et un bouton « Voir les
 * commentaires » ouvrant une page qui ne montre QUE les commentaires, classes
 * par date et par utilisateur.
 *
 * ## ⚠️ Ce que ce fichier surveille avant tout
 *
 * `/admin` est une surface PLATEFORME : `AdminMiddleware` exige `is_admin`,
 * jamais un administrateur d'Organization. C'est ce qui autorise a lire
 * plusieurs tenants ici — **et seulement ici**. Deux choses doivent donc tenir
 * ensemble, et chacune a son test :
 *
 * 1. le SuperAdmin voit bien les trois tenants (le scope global est retire) ;
 * 2. personne d'autre n'entre, pas meme l'administrateur d'une Organization.
 *
 * Le scope est retire **par son nom** : `withoutGlobalScopes()` emporterait
 * aussi `SoftDeletingScope`.
 */
class TASK1788Correctif10AdminCommentairesTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1788-10', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1788-10', 'is_active' => true, 'locale' => 'fr']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
    }

    private function annonce(Organization $org, ?User $auteur = null): FeedPost
    {
        $auteur ??= User::factory()->create(['organization_id' => $org->id]);

        return FeedPost::create([
            'organization_id' => $org->id,
            'user_id' => $auteur->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'title' => 'Annonce de '.$org->slug,
            'content' => 'Contenu.',
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function commentaire(FeedPost $annonce, User $auteur, string $contenu, ?\DateTimeInterface $quand = null): FeedPostComment
    {
        $ligne = FeedPostComment::create([
            'feed_post_id' => $annonce->id,
            'organization_id' => $annonce->organization_id,
            'user_id' => $auteur->id,
            'content' => $contenu,
            'is_approved' => true,
        ]);

        if ($quand !== null) {
            $ligne->forceFill(['created_at' => $quand])->save();
        }

        return $ligne->fresh();
    }

    private function page(array $parametres = []): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.feed.comments', $parametres))
            ->assertOk()
            ->getContent();
    }

    // ── La porte ─────────────────────────────────────────────────────────────

    /**
     * ⚠️ La garde d'abord : une page qui lit TOUS les tenants doit prouver
     * qu'elle n'ouvre a personne d'autre.
     */
    public function test_la_page_est_fermee_a_qui_n_est_pas_super_admin(): void
    {
        $adminOrg = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);

        $this->actingAs($adminOrg)
            ->get(route('admin.feed.comments'))
            ->assertStatus(403);

        // ⚠️ 403 et non une redirection, meme pour un anonyme :
        // `AdminMiddleware` fait `abort(403)` sans distinguer le cas. C'est
        // mesure, pas suppose — ma premiere version attendait une redirection.
        $this->get(route('admin.feed.comments'))->assertStatus(403);
    }

    // ── Voir ─────────────────────────────────────────────────────────────────

    public function test_le_super_admin_voit_les_commentaires_de_tous_les_tenants(): void
    {
        $a = $this->annonce($this->orgA);
        $b = $this->annonce($this->orgB);

        $this->commentaire($a, User::factory()->create(['organization_id' => $this->orgA->id]), 'COMMENTAIRE DE A');
        $this->commentaire($b, User::factory()->create(['organization_id' => $this->orgB->id]), 'COMMENTAIRE DE B');

        $html = $this->page();

        $this->assertStringContainsString('COMMENTAIRE DE A', $html, 'Le commentaire du premier tenant manque.');
        $this->assertStringContainsString('COMMENTAIRE DE B', $html,
            'Le commentaire du second tenant manque : le scope global n\'a pas ete retire.');
    }

    /** Le filtre d'Organization borne la lecture a un tenant. */
    public function test_le_filtre_d_organisation_borne_la_lecture(): void
    {
        $a = $this->annonce($this->orgA);
        $b = $this->annonce($this->orgB);

        $this->commentaire($a, User::factory()->create(['organization_id' => $this->orgA->id]), 'COMMENTAIRE DE A');
        $this->commentaire($b, User::factory()->create(['organization_id' => $this->orgB->id]), 'COMMENTAIRE DE B');

        $html = $this->page(['organization_id' => $this->orgA->id]);

        $this->assertStringContainsString('COMMENTAIRE DE A', $html, 'Temoin absent : le tenant choisi ne rend rien.');
        $this->assertStringNotContainsString('COMMENTAIRE DE B', $html, 'Le filtre d\'Organization ne borne pas la lecture.');
    }

    /** Classes PAR UTILISATEUR : le filtre d'auteur. */
    public function test_le_filtre_par_auteur_ne_rend_que_ses_commentaires(): void
    {
        $annonce = $this->annonce($this->orgA);

        $ottoline = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ottoline', 'name' => 'Kaminski']);
        $brunhilde = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Brunhilde', 'name' => 'Bartholomew']);

        $this->commentaire($annonce, $ottoline, 'ECRIT PAR OTTOLINE');
        $this->commentaire($annonce, $brunhilde, 'ECRIT PAR BRUNHILDE');

        $html = $this->page(['user_id' => $ottoline->id]);

        $this->assertStringContainsString('ECRIT PAR OTTOLINE', $html, 'Temoin absent : l\'auteur choisi ne rend rien.');
        $this->assertStringNotContainsString('ECRIT PAR BRUNHILDE', $html, 'Le filtre par auteur ne borne pas la lecture.');
    }

    /** Classes PAR DATE : l'ordre se renverse. */
    public function test_l_ordre_par_date_se_renverse(): void
    {
        $annonce = $this->annonce($this->orgA);
        $auteur = User::factory()->create(['organization_id' => $this->orgA->id]);

        $this->commentaire($annonce, $auteur, 'LE PLUS ANCIEN', now()->subDays(3));
        $this->commentaire($annonce, $auteur, 'LE PLUS RECENT', now());

        $recentDAbord = $this->page();
        $this->assertLessThan(
            strpos($recentDAbord, 'LE PLUS ANCIEN'),
            strpos($recentDAbord, 'LE PLUS RECENT'),
            'Par defaut, la page doit montrer le plus recent en premier.'
        );

        $ancienDAbord = $this->page(['ordre' => 'asc']);
        $this->assertLessThan(
            strpos($ancienDAbord, 'LE PLUS RECENT'),
            strpos($ancienDAbord, 'LE PLUS ANCIEN'),
            'L\'ordre croissant ne renverse pas la liste.'
        );
    }

    // ── Modifier ─────────────────────────────────────────────────────────────

    public function test_le_super_admin_modifie_le_texte_d_un_commentaire(): void
    {
        $annonce = $this->annonce($this->orgB);
        $ligne = $this->commentaire($annonce, User::factory()->create(['organization_id' => $this->orgB->id]), 'Texte d origine.');

        $this->actingAs($this->superAdmin)
            ->put(route('admin.feed.comment.update', $ligne->id), ['content' => 'Texte corrige.'])
            ->assertRedirect();

        $this->assertSame('Texte corrige.', $ligne->fresh()->content,
            'La modification n\'a pas ete enregistree — ou le scope global a masque la ligne d\'un autre tenant.');
    }

    /** Un commentaire vide n'est pas une correction : c'est une suppression a moitie faite. */
    public function test_un_commentaire_ne_peut_pas_etre_vide(): void
    {
        $annonce = $this->annonce($this->orgA);
        $ligne = $this->commentaire($annonce, User::factory()->create(['organization_id' => $this->orgA->id]), 'Texte d origine.');

        $this->actingAs($this->superAdmin)
            ->put(route('admin.feed.comment.update', $ligne->id), ['content' => ''])
            ->assertSessionHasErrors('content');

        $this->assertSame('Texte d origine.', $ligne->fresh()->content, 'Le texte a ete vide malgre le refus.');
    }

    // ── Supprimer, et sa distinction d'avec « masquer » ──────────────────────

    public function test_le_super_admin_supprime_un_commentaire(): void
    {
        $annonce = $this->annonce($this->orgB);
        $ligne = $this->commentaire($annonce, User::factory()->create(['organization_id' => $this->orgB->id]), 'A supprimer.');

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.feed.comment.destroy', $ligne->id))
            ->assertRedirect();

        // ⚠️ `assertDatabaseMissing` et non `assertSoftDeleted` : le bouton est
        // rouge et l'ecran dit « definitivement ». `FeedPostComment` emploie
        // `SoftDeletes` — un `delete()` aurait seulement masque la ligne, soit
        // un second « masquer » deguise en suppression. C'est ce test qui l'a
        // revele.
        $this->assertDatabaseMissing('feed_post_comments', ['id' => $ligne->id]);
    }

    /**
     * ⚠️ « Masquer » n'est PAS « supprimer ».
     *
     * Le premier bascule `is_approved` et se defait ; le second detruit. Les
     * confondre ferait perdre un contenu la ou on voulait seulement le retirer
     * de la vue.
     */
    public function test_masquer_conserve_la_ligne_la_ou_supprimer_la_detruit(): void
    {
        $annonce = $this->annonce($this->orgA);
        $ligne = $this->commentaire($annonce, User::factory()->create(['organization_id' => $this->orgA->id]), 'A masquer.');

        $this->actingAs($this->superAdmin)
            ->put(route('admin.feed.comment.toggle', $ligne->id))
            ->assertRedirect();

        $this->assertDatabaseHas('feed_post_comments', ['id' => $ligne->id]);
        $this->assertFalse((bool) $ligne->fresh()->is_approved, 'Le commentaire n\'a pas ete masque.');
    }

    // ── Le chemin depuis la page des annonces ────────────────────────────────

    public function test_la_page_des_annonces_mene_aux_commentaires(): void
    {
        $annonce = $this->annonce($this->orgA);
        $this->commentaire($annonce, User::factory()->create(['organization_id' => $this->orgA->id]), 'Un commentaire.');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.feed'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-marqueur="lien-commentaires"', $html,
            'Le bouton « Voir les commentaires » manque sur la page des annonces.');
        $this->assertStringContainsString('data-marqueur="lien-commentaires-annonce"', $html,
            'Le compte de commentaires d\'une annonce ne mene nulle part.');
    }

    /**
     * ⚠️ LE defaut signale en recette.
     *
     * Cliquer « 3 commentaires » sur une ligne ouvrait la page filtree sur la
     * seule ORGANIZATION : on y trouvait tous les commentaires du tenant. Un
     * compteur qui annonce trois doit mener a ces trois-la.
     */
    public function test_le_compteur_d_une_annonce_mene_aux_commentaires_de_cette_annonce(): void
    {
        $auteur = User::factory()->create(['organization_id' => $this->orgA->id]);

        $celleCi = $this->annonce($this->orgA);
        $uneAutre = $this->annonce($this->orgA);

        $this->commentaire($celleCi, $auteur, 'SUR CETTE ANNONCE');
        $this->commentaire($uneAutre, $auteur, 'SUR UNE AUTRE ANNONCE');

        // Le lien de la ligne porte bien l'identifiant de SON annonce.
        $index = $this->actingAs($this->superAdmin)->get(route('admin.feed'))->assertOk()->getContent();
        $this->assertStringContainsString(
            e(route('admin.feed.comments', ['organization_id' => $this->orgA->id, 'feed_post_id' => $celleCi->id])),
            $index,
            'Le compteur ne mene pas aux commentaires de SON annonce.'
        );

        // Et la page bornee ne rend que ceux-la.
        $html = $this->page(['feed_post_id' => $celleCi->id]);

        $this->assertStringContainsString('SUR CETTE ANNONCE', $html, 'Temoin absent : l\'annonce choisie ne rend rien.');
        $this->assertStringNotContainsString('SUR UNE AUTRE ANNONCE', $html,
            'La page bornee a une annonce rend les commentaires des autres.');
    }

    /** Quand la page est bornee a une annonce, elle le DIT, et on peut en sortir. */
    public function test_la_borne_sur_une_annonce_est_annoncee_et_se_retire(): void
    {
        $annonce = $this->annonce($this->orgA);
        $this->commentaire($annonce, User::factory()->create(['organization_id' => $this->orgA->id]), 'Un commentaire.');

        $html = $this->page(['feed_post_id' => $annonce->id]);

        $this->assertStringContainsString('data-marqueur="borne-annonce"', $html,
            'La page ne dit pas qu\'elle est bornee a une annonce.');
        $this->assertStringContainsString('data-marqueur="retirer-borne-annonce"', $html,
            'Rien ne permet de sortir de la borne.');
        $this->assertStringContainsString('name="feed_post_id"', $html,
            'La borne ne voyage pas avec les autres filtres : changer d\'auteur la ferait sauter.');
    }

    /**
     * ⚠️ La colonne « Annonce » rendait « — » sur toutes les lignes.
     *
     * Un EVENEMENT automatique n'a ni titre ni contenu : son libelle est
     * CALCULE. Se contenter de `title` puis `content` ne pouvait donner qu'un
     * tiret.
     */
    public function test_la_colonne_annonce_nomme_aussi_un_evenement_automatique(): void
    {
        $auteur = User::factory()->create(['organization_id' => $this->orgA->id]);

        $evenement = FeedPost::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $auteur->id,
            'type' => \App\Support\Feed\FeedEventCatalogue::clefs()[0],
            'title' => null,
            'content' => null,
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->commentaire($evenement, $auteur, 'Un commentaire sur un evenement.');

        $html = $this->page();

        $this->assertStringContainsString('Un commentaire sur un evenement.', $html,
            'Temoin absent : le commentaire n\'est pas rendu.');

        $libelle = \App\Support\Feed\FeedEventRenderer::libelle($evenement->fresh());
        $this->assertNotSame('', trim(strip_tags($libelle)),
            'Precondition : l\'evenement doit avoir un libelle calcule, sinon le test ne prouve rien.');
    }

    /** Le bouton emporte le filtre d'Organization courant, et lui seul. */
    public function test_le_bouton_emporte_le_filtre_d_organisation_mais_pas_celui_de_type(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.feed', ['organization_id' => $this->orgA->id, 'type' => 'annonces']))
            ->assertOk()
            ->getContent();

        $attendu = route('admin.feed.comments', ['organization_id' => $this->orgA->id]);

        $this->assertStringContainsString(e($attendu), $html, 'Le bouton ne reporte pas le filtre d\'Organization.');

        // ⚠️ Ma premiere version cherchait `e($attendu.'&amp;type=annonces')` :
        // `e()` rechappait le `&` deja echappe, le motif ne pouvait JAMAIS
        // apparaitre, et le sabotage « le bouton emporte aussi le type » est
        // reste vert. On mesure desormais le parametre lui-meme.
        $positionBouton = strpos($html, 'data-marqueur="lien-commentaires"');
        $this->assertNotFalse($positionBouton, 'Temoin absent : le bouton n\'est pas rendu.');

        $lien = substr($html, strrpos(substr($html, 0, $positionBouton), '<a '), 400);
        $this->assertStringNotContainsString('type=annonces', $lien,
            'Le bouton emporte un filtre de type que la page des commentaires ignore.');
    }
}
