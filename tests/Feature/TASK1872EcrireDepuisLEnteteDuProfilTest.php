<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1872, correctif 09 — « Ecrire un message prive » rejoint la ligne du nom.
 *
 * Cyril, 21/11 : « sur le profil utilisateur, sur desktop, le bouton pour
 * ecrire en prive est beaucoup trop loin du nom de la personne. Sur
 * smartphone, c'est ok. Mais est-ce qu'on n'a pas interet a mettre une icone
 * dans la card qui contient le nom de l'utilisateur ? »
 *
 * ## Ce que la mesure a montre
 *
 * L'asymetrie n'etait pas une impression. Le profil rend DEUX appels a
 * l'action : l'un dans un bloc `md:hidden` en haut de la colonne principale —
 * le telephone, ou tout va bien — et l'autre dans l'`<aside>`, rendu APRES la
 * liste des articles de blog. Sur une fiche a cinq articles, le seul chemin du
 * bureau tombe a plusieurs centaines de pixels sous le nom.
 *
 * ## Ce que ces tests gardent
 *
 * La presence, mais surtout les trois refus : jamais sur sa propre fiche, un
 * invite part se connecter, et l'adresse reste dans l'Organization. Plus deux
 * garanties d'ergonomie qu'un oeil ne verifie pas a chaque recette : le
 * libelle accessible est porte meme quand l'icone est seule, et le bloc du
 * telephone n'a pas bouge.
 */
class TASK1872EcrireDepuisLEnteteDuProfilTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    private User $cible;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true, 'is_public' => true, 'slug' => 'org-1872-profil',
        ]);

        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'email_verified_at' => now(),
        ]);
        $this->cible = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'email_verified_at' => now(),
            'first_name' => 'Camille', 'name' => 'Lucki',
        ]);
    }

    private function fiche(?User $lecteur, ?User $sujet = null): string
    {
        $sujet ??= $this->cible;

        $requete = $lecteur ? $this->actingAs($lecteur) : $this;

        return $requete
            ->get(route('organization.profile.show', [
                'organization' => $this->org->slug,
                'user' => $sujet->fresh()->slug ?? $sujet->id,
            ]))
            ->assertOk()
            ->getContent();
    }

    /** La balise du bouton de l'en-tete, isolee de tout le reste de la page. */
    private function boutonEnLigne(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<a[^>]*data-profile-write-inline[^>]*>/', $html, $trouve),
            'Le bouton « Ecrire » de la ligne du nom n\'a pas ete trouve.'
        );

        return $trouve[0];
    }

    /* ─────────── IL EST LA, ET AU BON ENDROIT ─────────── */

    public function test_le_bouton_ecrire_est_sur_la_ligne_du_nom(): void
    {
        $html = $this->fiche($this->visiteur);

        $bouton = $this->boutonEnLigne($html);

        $this->assertStringContainsString(
            route('organization.messages.with', [
                'organization' => $this->org->slug,
                'user' => $this->cible->fresh()->profileRouteKey(),
            ]),
            html_entity_decode($bouton),
            'Le bouton ne mene pas a la conversation privee, dans l\'Organization.'
        );
    }

    /**
     * Il est DANS l'en-tete, pas ailleurs dans la page.
     *
     * C'est toute la demande : la proximite. Sans cette borne, le test
     * passerait aussi si le bouton restait au fond de l'`<aside>`.
     */
    public function test_le_bouton_est_bien_a_l_interieur_de_l_entete(): void
    {
        $html = $this->fiche($this->visiteur);

        $debutEntete = strpos($html, 'data-profile-entete');
        $bouton = strpos($html, 'data-profile-write-inline');
        // La fin de l'en-tete : le premier bloc qui le suit dans la page.
        $finEntete = strpos($html, 'md:grid md:grid-cols-3');

        $this->assertNotFalse($debutEntete);
        $this->assertNotFalse($bouton);
        $this->assertNotFalse($finEntete, 'La grille a deux colonnes est introuvable.');

        $this->assertGreaterThan($debutEntete, $bouton);
        $this->assertLessThan($finEntete, $bouton,
            'Le bouton est hors de l\'en-tete : la distance au nom n\'est pas corrigee.');
    }

    /**
     * Le libelle accessible est porte MEME quand l'icone est seule.
     *
     * En dessous de `lg` le texte est masque pour ne pas faire tronquer le
     * nom. Sans `aria-label`, le bouton serait alors muet pour un lecteur
     * d'ecran — une icone sans nom n'est pas un bouton, c'est un dessin.
     */
    public function test_l_icone_seule_garde_un_nom_accessible(): void
    {
        $bouton = $this->boutonEnLigne($this->fiche($this->visiteur));

        $attendu = e(__('messages.member_preview_write'));

        $this->assertStringContainsString('aria-label="'.$attendu.'"', $bouton);
        $this->assertStringContainsString('title="'.$attendu.'"', $bouton);
    }

    /**
     * Le vocabulaire est celui qui existe deja.
     *
     * La meme action s'appelle « Ecrire un message prive » sur la carte
     * membre. Un troisieme mot pour le meme geste aurait ajoute du
     * vocabulaire a apprendre, pas de la clarte.
     */
    public function test_le_libelle_est_celui_de_la_carte_membre(): void
    {
        $this->assertSame(
            __('messages.member_preview_write'),
            trans('messages.member_preview_write'),
        );

        $this->assertStringContainsString(
            e(__('messages.member_preview_write')),
            $this->boutonEnLigne($this->fiche($this->visiteur)),
        );
    }

    /* ─────────── LES TROIS REFUS ─────────── */

    /** On ne s'ecrit pas a soi-meme. */
    public function test_le_bouton_est_absent_sur_sa_propre_fiche(): void
    {
        $html = $this->fiche($this->cible, $this->cible);

        $this->assertStringNotContainsString('data-profile-write-inline', $html);

        // TEMOIN : c'est bien la fiche attendue, sinon l'absence ne prouve rien.
        $this->assertStringContainsString('data-profile-entete', $html);
    }

    /**
     * Un invite n'atteint meme pas cette page.
     *
     * ⚠️ Ma premiere version lisait le bouton sur une fiche visitee en invite
     * et rougissait sur un 302. MESURE : la fiche redirige vers
     * `/org/{slug}/login` AVANT d'etre rendue. Le repli
     * `auth()->check() ? … : route('login')` que porte ce bouton — et que
     * portent deja les deux appels a l'action voisins — est donc du code
     * INATTEIGNABLE sur cet ecran.
     *
     * Il est conserve quand meme : le retirer ferait differer ce bouton de
     * ses deux voisins pour aucun gain, et la garde coute une expression
     * ternaire. Mais le test, lui, mesure ce qui se passe VRAIMENT, plutot
     * que d'affirmer une branche que personne n'emprunte.
     */
    public function test_un_invite_est_renvoye_vers_la_connexion_avant_la_fiche(): void
    {
        $this->get(route('organization.profile.show', [
            'organization' => $this->org->slug,
            'user' => $this->cible->fresh()->profileRouteKey(),
        ]))->assertRedirect(route('organization.login', ['organization' => $this->org->slug]));
    }

    /* ─────────── LE TELEPHONE N'A PAS BOUGE ─────────── */

    /**
     * Cyril : « sur smartphone, c'est ok ». Le bloc existant reste donc
     * intact, et le bouton neuf ne parait qu'a partir de `sm`.
     */
    public function test_le_bloc_du_telephone_est_inchange_et_le_bouton_neuf_ne_s_y_affiche_pas(): void
    {
        $html = $this->fiche($this->visiteur);

        $this->assertStringContainsString('space-y-3 md:hidden', $html,
            'Le bloc d\'appels a l\'action du telephone a disparu.');

        $this->assertStringContainsString('hidden sm:inline-flex', $this->boutonEnLigne($html),
            'Le bouton neuf s\'affiche aussi sur telephone, ou un bloc le fait deja.');
    }
}
