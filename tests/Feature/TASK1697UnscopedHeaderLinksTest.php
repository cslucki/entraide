<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1697 — les liens qui sortaient du locataire sur les pages SANS prefixe.
 *
 * Question de Cyril : « sur /verify-email, si on est en train de valider un
 * utilisateur OFSH, le lien du header pointe vers / et non vers /org/ofsh ».
 *
 * ## Ce que la mesure a etabli, une requete par test
 *
 * | URL | statut | Organization « courante » |
 * |---|---|---|
 * | `/dashboard` | 200 | **ofsh** — correct |
 * | `/favorites` | **302** vers `/org/ofsh/favorites` | deja canonise |
 * | `/profile/edit` | 200 | **`main`** — le vrai defaut |
 * | `/verify-email` | 200 | **NULL** |
 *
 * Trois cas sains ou rattrapes, **un seul reellement casse**.
 *
 * ## La cause du lien de l'en-tete
 *
 * `AppServiceProvider` prend `brandOrganizationName` sur l'Organization de la
 * PERSONNE CONNECTEE quand aucune n'est courante, tandis que
 * `organizationScopedUrl()` exige un prefixe `/org/...` dans l'URL — doctrine
 * assumee de T1605, « le declencheur est l'URL ». Le nom disait donc OFSH et
 * le lien menait a la racine.
 *
 * ## Ce qui n'a PAS ete touche, et pourquoi
 *
 * - **`organizationScopedUrl()`** : huit vues en dependent, dont des pages
 *   PUBLIQUES (vitrines, mycelium, mentions legales) ou basculer sur
 *   l'Organization du visiteur serait faux.
 * - **La route `/verify-email`** : son URL signee `verify-email/{id}/{hash}`
 *   est deja partie par courriel, on ne la re-scope pas retroactivement.
 * - **`/dashboard`** : il resout DEJA sur la bonne Organization, et il est le
 *   seul membre de `$authenticatedPersonalRoutes` — la redirection canonique
 *   l'exclut DELIBEREMENT. C'est une decision produit : le tableau de bord est
 *   une surface personnelle. La changer demande l'arbitrage de Cyril.
 * - **`ResolveUrlOrganization::resolveOrganization()`** : son propre docblock
 *   mesure que resoudre depuis l'utilisateur y ferait rougir **8 tests** de
 *   T1288/1289/1291. « Ce n'est pas un correctif, c'est une redefinition. »
 */
class TASK1697UnscopedHeaderLinksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ofsh;

    private function membre(bool $verifie = true): User
    {
        Organization::factory()->create(['slug' => 'main', 'is_active' => true, 'is_default' => true]);
        $this->ofsh = Organization::factory()->create(['slug' => 'ofsh', 'is_active' => true]);

        return User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => $verifie ? now() : null,
        ]);
    }

    // ── L'en-tete de /verify-email ──────────────────────────────────────────

    /**
     * ## Pourquoi ces gardes visent la BALISE, et non la page
     *
     * Mesure par sabotage : asserter la presence de
     * `route('organization.home', 'ofsh')` dans le HTML restait **VERT** sans
     * le correctif. Cette URL — `http://localhost/org/ofsh` — est un PREFIXE
     * de tout autre lien scope de la page. Une garde ecrite ainsi passait donc
     * par coincidence.
     *
     * Chaque garde extrait desormais la balise porteuse par son marqueur.
     */
    private function balise(string $html, string $marqueur): string
    {
        $this->assertSame(1, preg_match('/<a[^>]*'.preg_quote($marqueur, '/').'[^>]*>/', $html, $trouvee),
            "Aucune balise ne porte [{$marqueur}].");

        return $trouvee[0];
    }

    public function test_le_lien_de_l_entete_mene_a_l_organization_du_membre(): void
    {
        $membre = $this->membre(verifie: false);

        $html = $this->actingAs($membre)->get('/verify-email')->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('organization.home', ['organization' => 'ofsh'])).'"',
            $this->balise($html, 'data-guest-brand'),
            "L'en-tete ne mene pas a l'Organization du membre."
        );
    }

    /** Le nom affiche et le lien doivent designer la MEME Organization. */
    public function test_le_nom_et_le_lien_designent_la_meme_organization(): void
    {
        $membre = $this->membre(verifie: false);

        $html = $this->actingAs($membre)->get('/verify-email')->assertOk()->getContent();

        $this->assertStringContainsString(e($this->ofsh->name), $html, 'Le nom affiche n\'est pas celui d\'OFSH.');
        $this->assertStringContainsString(
            e(route('organization.home', ['organization' => 'ofsh'])).'"',
            $this->balise($html, 'data-guest-brand')
        );
    }

    /** Un visiteur anonyme ne change pas de comportement. */
    public function test_un_visiteur_anonyme_garde_le_lien_global(): void
    {
        Organization::factory()->create(['slug' => 'main', 'is_active' => true, 'is_default' => true]);

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('/org/ofsh', $this->balise($html, 'data-guest-brand'));
    }

    // ── Les liens du menu utilisateur ───────────────────────────────────────

    public function test_le_lien_profil_du_menu_reste_dans_le_locataire(): void
    {
        $membre = $this->membre();

        $html = $this->actingAs($membre)->get('/profile/edit')->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('organization.profile.edit', ['organization' => 'ofsh'])).'"',
            $this->balise($html, 'data-user-menu-profile')
        );
    }

    public function test_le_lien_favoris_du_menu_reste_dans_le_locataire(): void
    {
        $membre = $this->membre();

        $html = $this->actingAs($membre)->get('/profile/edit')->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('organization.favorites.index', ['organization' => 'ofsh'])).'"',
            $this->balise($html, 'data-user-menu-favorites')
        );
    }

    /**
     * Le tableau de bord reste NON scope, et c'est voulu.
     *
     * Il est le seul membre de `$authenticatedPersonalRoutes`, et la
     * redirection canonique l'exclut deliberement : c'est une surface
     * PERSONNELLE. Cette garde protege cette decision d'un scope ajoute par
     * reflexe — qu'il faudra retirer le jour ou Cyril tranchera l'inverse.
     */
    public function test_le_tableau_de_bord_reste_une_surface_personnelle(): void
    {
        $membre = $this->membre();

        $html = $this->actingAs($membre)->get('/profile/edit')->assertOk()->getContent();

        $this->assertStringContainsString(
            e(route('dashboard')).'"',
            $this->balise($html, 'data-user-menu-dashboard')
        );
    }

    /** Le tableau de bord resout DEJA la bonne Organization : rien a corriger. */
    public function test_le_tableau_de_bord_resout_deja_la_bonne_organization(): void
    {
        $membre = $this->membre();

        $this->actingAs($membre)->get('/dashboard')->assertOk();

        $this->assertSame('ofsh', currentOrganization()?->slug);
    }

    /** Et `/favorites` non prefixe est deja rattrape par la redirection canonique. */
    public function test_favorites_non_prefixe_est_deja_canonise(): void
    {
        $membre = $this->membre();

        $this->actingAs($membre)
            ->get('/favorites')
            ->assertRedirect(route('organization.favorites.index', ['organization' => 'ofsh']));
    }
}
