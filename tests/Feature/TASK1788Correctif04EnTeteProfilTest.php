<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1788, correctif 04 — l'en-tete du profil public, allege.
 *
 * Cyril : « la c'est totalement surcharge ! ». L'en-tete melait l'IDENTITE —
 * qui est cette personne, ou elle est, comment la joindre — et le PALMARES —
 * note, compteurs, points, bilan, remerciements, badges. Le second noyait le
 * premier.
 *
 * ## Ce que ces tests mesurent, et pourquoi c'est une POSITION
 *
 * « Les badges ne sont plus dans l'en-tete » ne se mesure pas par une absence :
 * ils sont toujours dans la page, un peu plus bas. Il faut donc mesurer ou.
 *
 * `data-profile-entete` ouvre l'en-tete et `data-profile-apercu` ouvre le bloc
 * d'accueil de la colonne de droite. Entre les deux se trouve tout ce que la
 * page montre avant l'apercu. Un marqueur deplace ne doit plus apparaitre dans
 * cet intervalle — et doit apparaitre apres.
 */
class TASK1788Correctif04EnTeteProfilTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    /**
     * Les marqueurs qui ont quitte l'en-tete.
     *
     * ⚠️ `data-entraide-temps` n'en fait PAS partie, et c'est une lecon de ce
     * correctif : il est pose sur toutes les durees du produit, y compris sur
     * les cartes d'annonce de cette meme page. Le chercher « avant l'apercu »
     * le trouvait dans une carte de service et faisait rougir un test juste.
     * `data-profile-temps`, lui, n'appartient qu'au bandeau.
     *
     * `data-entraide-bilan>` est clos par son chevron : sans lui, le motif
     * attraperait aussi `data-entraide-bilan-personnes`, qui le prefixe.
     */
    private const DEPLACES = [
        'data-profile-temps',
        'data-entraide-bilan>',
        'data-entraide-bilan-personnes',
        // `data-badge` a disparu du lot : TASK-1788 correctif 05 a supprime le
        // systeme de badges membre. Il n'y a plus rien a deplacer.
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-04', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
            // PRECONDITION : sans ce drapeau, `structureIsShown()` rend `false`
            // et le bloc de structure n'est jamais rendu — le test « la
            // structure reste dans l'en-tete » mesurerait alors une absence qui
            // n'a rien a voir avec ce correctif.
            'structure_enabled' => true,
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'structure' => 'AMT',
            'slogan' => 'Je resous des problemes, pas des tickets.',
            // `public_location` est un ACCESSEUR (`city` + pays), pas une
            // colonne : le poser directement ferait echouer l'insertion.
            'city' => 'Marseille',
            'country_code' => 'FR',
            'website' => 'https://mon-site.test',
            'points_balance' => 62,
        ]);
    }

    /** Un profil qui a quelque chose a montrer : un echange, un merci, un badge. */
    private function garnir(): void
    {
        $beneficiaire = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        // Une entraide passe par une VRAIE annonce : c'est son existence qui
        // la distingue d'une discussion directe, et le bilan la compte ainsi
        // (lecon ecrite dans les tests de T1755).
        $annonce = Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => 45,
        ]);

        $echange = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'seller_id' => $this->membre->id,
            'buyer_id' => $beneficiaire->id,
            'service_id' => $annonce->id,
            'status' => 'completed',
            'points_proposed' => 45,
            'points_agreed' => 45,
        ]);

        Review::factory()->create([
            'transaction_id' => $echange->id,
            'reviewer_id' => $beneficiaire->id,
            'reviewed_id' => $this->membre->id,
            'rating' => 5,
            'comment' => 'Un grand merci pour ce coup de main.',
        ]);

    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();
    }

    /** Ce qui precede l'apercu : l'en-tete et la colonne de gauche. */
    private function avantLApercu(string $html): string
    {
        $debut = strpos($html, 'data-profile-entete');
        $fin = strpos($html, 'data-profile-apercu');

        $this->assertNotFalse($debut, 'Temoin absent : l\'en-tete n\'est pas rendu.');
        $this->assertNotFalse($fin, 'Temoin absent : l\'apercu chiffre n\'est pas rendu.');
        $this->assertGreaterThan($debut, $fin, 'L\'apercu precede l\'en-tete : la page est a l\'envers.');

        return substr($html, $debut, $fin - $debut);
    }

    // ── Ce qui a quitte l'en-tete ────────────────────────────────────────────

    public function test_les_compteurs_le_bilan_et_les_badges_ont_quitte_l_en_tete(): void
    {
        $this->garnir();
        $html = $this->page();
        $avant = $this->avantLApercu($html);

        foreach (self::DEPLACES as $marqueur) {
            $this->assertStringNotContainsString($marqueur, $avant,
                "`{$marqueur}` figure encore avant l'apercu : il n'a pas quitte l'en-tete.");
            $this->assertStringContainsString($marqueur, $html,
                "`{$marqueur}` a disparu de la page : il devait etre DEPLACE, pas supprime.");
        }
    }

    // ── Ce qui reste dans l'en-tete ──────────────────────────────────────────

    public function test_l_identite_reste_dans_l_en_tete(): void
    {
        $this->garnir();
        $avant = $this->avantLApercu($this->page());

        $this->assertStringContainsString(e($this->membre->fullName), $avant, 'Le nom a quitte l\'en-tete.');
        $this->assertStringContainsString('data-profile-structure', $avant, 'La structure a quitte l\'en-tete.');
        $this->assertStringContainsString('data-profile-slogan', $avant, 'Le slogan a quitte l\'en-tete.');
        $this->assertStringContainsString('data-profile-localisation', $avant, 'La localisation a quitte l\'en-tete.');
        $this->assertStringContainsString('mon-site.test', $avant, 'Les liens ont quitte l\'en-tete.');
    }

    /**
     * La localisation etait melee aux compteurs dans la meme rangee.
     *
     * Elle dit ou se trouve la personne, pas ce qu'elle a accompli : elle reste,
     * et elle a desormais sa propre rangee. Un seul exemplaire.
     */
    public function test_la_localisation_n_est_rendue_qu_une_fois_et_dans_l_en_tete(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-profile-localisation'),
            'La localisation est rendue plus d\'une fois.');
        $this->assertStringContainsString('Marseille', $this->avantLApercu($this->garnirPuisPage()),
            'La localisation n\'est plus dans l\'en-tete.');
    }

    private function garnirPuisPage(): string
    {
        $this->garnir();

        return $this->page();
    }

    // ── UN seul exemplaire, pas deux ─────────────────────────────────────────

    /**
     * ⚠️ Le piege que ce correctif devait eviter.
     *
     * Rendre le bloc deux fois — un `md:hidden` en bas de page, un
     * `hidden md:block` dans la colonne — aurait donne le bon resultat a
     * l'ecran et deux copies a maintenir, avec les memes marqueurs en double
     * dans le document. La grille `md:grid` suffit : sous `md` elle ne
     * s'applique pas, les colonnes s'empilent, et l'apercu tombe en fin
     * d'ecran tout seul.
     */
    public function test_l_apercu_n_est_rendu_qu_une_seule_fois(): void
    {
        $this->garnir();
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-profile-apercu'), 'L\'apercu est rendu en double.');
        $this->assertSame(1, substr_count($html, 'data-entraide-bilan>'), 'Le bilan d\'entraide est rendu en double.');
    }

    /**
     * ⚠️ Ce test existe parce qu'un SABOTAGE est reste vert.
     *
     * Remettre `hidden md:block` sur la colonne de droite ne faisait rougir
     * aucun test : l'apercu disparaissait du smartphone — exactement ce que
     * Cyril demandait d'eviter (« sur la version smartphone, les elements
     * superflus seront a la fin de l'ecran ») — et les cinq tests restaient
     * verts, puisque le HTML, lui, contenait toujours tout.
     *
     * Ce qu'il tient : la colonne de droite n'est plus masquee sous `md`, et
     * les appels a l'action, eux, le restent (sinon le smartphone les
     * afficherait deux fois — il a deja son propre bloc).
     *
     * Ce qu'il ne tient pas : le rendu a une largeur donnee. Aucun test PHP ne
     * le mesure ; c'est la recette qui le voit.
     */
    public function test_la_colonne_de_droite_n_est_plus_reservee_au_bureau(): void
    {
        $this->garnir();
        $html = $this->page();

        $this->assertStringContainsString('<aside class="md:col-span-1 space-y-4">', $html,
            'La colonne de droite est redevenue reservee au bureau : l\'apercu disparaitrait du smartphone.');
        $this->assertStringNotContainsString('<aside class="hidden md:block', $html,
            'La colonne de droite porte encore `hidden md:block`.');

        // Et les appels a l'action restent, eux, reserves au bureau.
        $this->assertStringContainsString('<div class="hidden md:block space-y-4">', $html,
            'Les appels a l\'action ne sont plus reserves au bureau : le smartphone les afficherait deux fois.');
    }

    // ── Le Blog, a droite et AVANT l'apercu ──────────────────────────────────

    /**
     * Demande de Cyril : « on va mettre la card Blog avant celle des stats,
     * donc sur la droite ».
     *
     * Deux choses a tenir, et la seconde se serait cassee en silence :
     *
     * 1. la carte est dans la colonne de droite, et AVANT l'apercu chiffre ;
     * 2. `id="articles"` survit au deplacement — c'est la cible de l'ancre
     *    posee par l'annuaire (T1697 correctif 15). Un lien d'ancre ne se
     *    plaint jamais quand sa cible disparait : il ouvre la page et ne
     *    defile pas.
     */
    public function test_le_blog_est_dans_la_colonne_de_droite_avant_l_apercu(): void
    {
        $this->garnir();
        $this->publierUnArticle();

        $html = $this->page();

        // ⚠️ `strpos($html, '<aside')` ne convient PAS : la mise en page de
        // l'application porte deja un `<aside>` (la navigation laterale), et
        // c'est LUI que `strpos` trouvait en premier. La fermeture lue etait
        // donc la sienne, et le test rougissait sur une carte pourtant bien
        // placee. On vise la colonne du profil par sa classe exacte.
        $ouvertureAside = strpos($html, '<aside class="md:col-span-1 space-y-4">');
        $this->assertNotFalse($ouvertureAside, 'Temoin absent : la colonne de droite du profil n\'est pas rendue.');

        $fermetureAside = strpos($html, '</aside>', $ouvertureAside);
        $articles = strpos($html, '<section id="articles">');
        $apercu = strpos($html, 'data-profile-apercu');

        $this->assertNotFalse($articles, 'Temoin absent : la carte Blog n\'est pas rendue.');
        $this->assertNotFalse($apercu, 'Temoin absent : l\'apercu chiffre n\'est pas rendu.');

        $this->assertGreaterThan($ouvertureAside, $articles, 'La carte Blog est restee dans la colonne de gauche.');
        $this->assertLessThan($fermetureAside, $articles, 'La carte Blog est sortie de la colonne de droite.');
        $this->assertLessThan($apercu, $articles, 'La carte Blog passe APRES l\'apercu chiffre.');
    }

    /** Une colonne, pas deux : la barre laterale fait un tiers de la page. */
    public function test_la_carte_blog_ne_se_coupe_plus_en_deux_colonnes(): void
    {
        $this->garnir();
        $this->publierUnArticle();

        $html = $this->page();

        // ⚠️ L'assertion doit porter sur la SECTION, pas sur la page : les
        // annonces et les demandes, dans la colonne de gauche, emploient la
        // meme classe de grille et sont parfaitement a leur place.
        $debut = strpos($html, '<section id="articles">');
        $this->assertNotFalse($debut, 'Temoin absent : la carte Blog n\'est pas rendue.');

        $section = substr($html, $debut, strpos($html, '</section>', $debut) - $debut);

        $this->assertStringContainsString('grid gap-3', $section, 'Temoin absent : la grille de la carte Blog n\'est pas rendue.');
        $this->assertStringNotContainsString('sm:grid-cols-2', $section,
            'La carte Blog garde une grille a deux colonnes, trop serree dans la barre laterale.');
    }

    /** Pas de fabrique pour `BlogPost` dans ce depot : creation directe. */
    private function publierUnArticle(): void
    {
        $titre = 'Un article de mesure pour le correctif 04';

        BlogPost::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'title' => $titre,
            'slug' => Str::slug($titre).'-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    // ── Rien a montrer : rien ne s'affiche ───────────────────────────────────

    /**
     * Un arrivant n'a ni note, ni echange, ni badge, ni bilan.
     *
     * Lui rendre une carte qui annonce « 0 echange » serait un reproche, pas un
     * apercu — exactement l'arbitrage deja pris pour le bilan en T1755.
     */
    public function test_l_apercu_ne_s_affiche_pas_quand_il_n_y_a_rien_a_montrer(): void
    {
        $html = $this->page();

        // TEMOIN : la page est bien rendue, et c'est bien un profil.
        $this->assertStringContainsString('data-profile-entete', $html, 'Temoin absent : l\'en-tete n\'est pas rendu.');
        $this->assertStringContainsString('data-profile-slogan', $html, 'Temoin absent : ce n\'est pas la fiche attendue.');

        $this->assertStringNotContainsString('data-profile-apercu', $html,
            'Une carte d\'apercu vide est rendue a un arrivant qui n\'a rien a montrer.');
    }
}
