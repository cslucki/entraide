<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1698 — le mobilier de navigation reste dans le locataire.
 *
 * ## L'audit a d'abord REDUIT le perimetre, pas l'inverse
 *
 * J'avais annonce a Cyril (T1697 correctif 19) : « `route('profile.edit')` sans
 * garde dans QUATRE fichiers partages, plus ~53 appels a trier ». **Surestime.**
 *
 * Mesure : des 81 appels non prefixes des quatre fichiers, **30** sont des
 * replis de ternaire deja gardes, et **34** vivent dans
 * `layouts/navigation.blade.php` — qui **n'est rendu par AUCUNE page**.
 * `layouts/app.blade.php` monte `<x-mobile-topbar>` et `<x-app-side-nav>`, pas
 * `layouts.navigation` ; seuls des TESTS le lisent, comme un fichier texte.
 *
 * Preuve : un marqueur temporaire pose en tete de ce fichier n'apparait ni sur
 * `/dashboard`, ni sur `/profile/edit`, ni sur `/login`.
 *
 * Deux des « quatre fichiers » que j'avais nommes etaient donc du **code mort**.
 *
 * ## Le perimetre reel : QUATRE liens
 *
 * Et le detail qui prouve l'oubli : dans `mobile-topbar`, les lignes 295, 296 et
 * 299 passent par `$routeUrl('x', 'organization.x')` tandis que 287, 297 et 300
 * appelaient `route()` NU. **Le helper etait la, applique a trois freres et pas
 * a ces trois-la.**
 *
 * ## Ce qui reste NON scope, et c'est voulu
 *
 * | lien | raison |
 * |---|---|
 * | `dashboard` | surface PERSONNELLE — seul membre de `$authenticatedPersonalRoutes`, exclu DELIBEREMENT de la redirection canonique |
 * | `admin.dashboard` | admin de PLATEFORME (`is_admin`) ; le pointer vers `organization.admin.dashboard` enverrait sur le cockpit d'une Organization — un changement de sens, pas un correctif |
 * | `help`, `search`, `locale.switch` | **aucune route scopee n'existe** ; rediriger fabriquerait un 404 |
 * | `logout` | detruit la session ; la destination d'apres est dans le controleur, pas dans le lien |
 */
class TASK1698NavigationFurnitureTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ofsh;

    private function membre(): User
    {
        Organization::factory()->create(['slug' => 'main', 'is_active' => true, 'is_default' => true]);
        $this->ofsh = Organization::factory()->create(['slug' => 'ofsh', 'is_active' => true]);

        return User::factory()->complete()->create([
            'organization_id' => $this->ofsh->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Les gardes visent la BALISE PORTEUSE et non la page : une URL de tenant
     * est le PREFIXE d'une autre, et une assertion sur la page passe par
     * coincidence. Trois faux verts l'ont montre en T1697.
     */
    private function balise(string $html, string $marqueur): string
    {
        $this->assertSame(1, preg_match('/<a[^>]*'.preg_quote($marqueur, '/').'[^>]*>/', $html, $trouvee),
            "Aucune balise ne porte [{$marqueur}].");

        return $trouvee[0];
    }

    private function page(User $membre): string
    {
        // Une page SANS prefixe d'Organization : c'est la que le defaut vit.
        return $this->actingAs($membre)->get('/profile/edit')->assertOk()->getContent();
    }

    public function test_le_lien_du_profil_de_la_topbar_reste_dans_le_locataire(): void
    {
        $html = $this->page($this->membre());

        $this->assertStringContainsString(
            'href="'.route('organization.profile.edit', ['organization' => 'ofsh']).'"',
            $this->balise($html, 'data-topbar-settings')
        );
    }

    public function test_le_lien_des_favoris_de_la_topbar_reste_dans_le_locataire(): void
    {
        $html = $this->page($this->membre());

        $this->assertStringContainsString(
            'href="'.route('organization.favorites.index', ['organization' => 'ofsh']).'"',
            $this->balise($html, 'data-topbar-favorites')
        );
    }

    public function test_le_lien_agent_ia_de_la_topbar_reste_dans_le_locataire(): void
    {
        $html = $this->page($this->membre());

        $this->assertStringContainsString(
            'href="'.route('organization.agent-ia.wizard', ['organization' => 'ofsh']).'"',
            $this->balise($html, 'data-topbar-ai-wizard')
        );
    }

    public function test_le_lien_agent_ia_du_rail_reste_dans_le_locataire(): void
    {
        $html = $this->page($this->membre());

        $this->assertStringContainsString(
            'href="'.route('organization.agent-ia.wizard', ['organization' => 'ofsh']).'"',
            $this->balise($html, 'data-side-nav-ai-wizard')
        );
    }

    /** Depuis une page DEJA prefixee, c'est l'URL qui decide — pas l'utilisateur. */
    public function test_depuis_une_autre_organization_l_url_decide(): void
    {
        $membre = $this->membre();
        $autre = Organization::factory()->create(['slug' => 'autre-org', 'is_active' => true]);
        $membre->organization->forceFill([])->save();

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', ['organization' => $this->ofsh->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('organization.profile.edit', ['organization' => 'ofsh']).'"',
            $this->balise($html, 'data-topbar-settings')
        );
        $this->assertStringNotContainsString($autre->slug, $this->balise($html, 'data-topbar-settings'));
    }

    /**
     * LA plus grande part du correctif.
     *
     * Ces trois liens passaient **DEJA** par `$routeUrl('x', 'organization.x')`
     * — ils avaient l'air corrects. Mais le helper ne borne que si
     * `$organizationRouteParam` est renseigne, et il ne l'etait QUE par le
     * parametre d'URL. Sur une page sans prefixe, les **20** liens de cette
     * barre rendaient donc l'URL globale **en silence**.
     *
     * Le helper n'etait pas mal employe : son ENTREE etait vide. C'est la
     * raison pour laquelle convertir trois liens de plus ne changeait rien
     * avant qu'on pose le repli.
     */
    public function test_les_liens_qui_utilisaient_deja_le_helper_sont_bornes_aussi(): void
    {
        $html = $this->page($this->membre());

        foreach ([
            'points.index' => 'organization.points.index',
            'invitations.index' => 'organization.invitations.index',
            'blog.my-posts' => 'organization.blog.my-posts',
        ] as $global => $scopee) {
            $this->assertStringContainsString(
                'href="'.route($scopee, ['organization' => 'ofsh']).'"',
                $html,
                "[{$global}] n'est pas borne : le repli de \$organizationRouteParam ne s'applique pas."
            );
            $this->assertStringNotContainsString(
                'href="'.route($global).'"',
                $html,
                "[{$global}] rend encore l'URL globale."
            );
        }
    }

    /**
     * Le repli prend l'Organization DU MEMBRE, pas celle par defaut.
     *
     * C'est tout l'enjeu de la famille de defauts corrigee ici : sans prefixe
     * d'URL, le produit retombait sur `main`. Un repli qui designerait encore
     * le defaut ne corrigerait rien — il deplacerait le bug.
     *
     * ## Pourquoi il n'y a PAS de garde « visiteur anonyme » ici
     *
     * Elle serait creuse, et je l'ai d'abord ecrite ainsi : j'assertais
     * l'absence d'un prefixe sur `/login` — page servie par `x-guest-layout`,
     * qui **ne rend pas cette barre**. Mesure : dans un environnement de test
     * nu, aucune page publique ne la rend (302 sur `/explorer` et `/membres`,
     * 404 sur `/blog` faute de donnees). Le sabotage du repli y restait donc
     * vert.
     *
     * Le cas anonyme est couvert autrement, et suffisamment : le repli exige
     * `auth()->check()`, et **cette** garde-ci rougit des que le repli designe
     * autre chose que l'Organization du membre.
     */
    public function test_le_repli_designe_le_membre_et_non_l_organization_par_defaut(): void
    {
        $html = $this->page($this->membre());

        $balise = $this->balise($html, 'data-topbar-settings');

        $this->assertStringContainsString(
            'href="'.route('organization.profile.edit', ['organization' => 'ofsh']).'"',
            $balise
        );
        $this->assertStringNotContainsString('/org/main/', $balise,
            "Le repli designe l'Organization PAR DEFAUT : le defaut est deplace, pas corrige.");
    }

    /**
     * `layouts/navigation.blade.php` n'est rendu par AUCUNE page.
     *
     * Cette garde fige la mesure qui a reduit le perimetre de cette TASK. Si
     * quelqu'un remet ce gabarit en service, elle rougira — et il faudra alors
     * auditer ses 34 appels non prefixes, pas les ignorer.
     */
    public function test_le_gabarit_de_navigation_est_du_code_mort(): void
    {
        $inclusions = [];

        foreach (['resources/views', 'app'] as $racine) {
            $sortie = [];
            exec('grep -rlE "layouts\\.navigation|layouts/navigation" '.base_path($racine).' 2>/dev/null', $sortie);
            $inclusions = array_merge($inclusions, $sortie);
        }

        // `errors/404.blade.php` ne fait que le CITER dans un commentaire.
        $inclusions = array_values(array_filter(
            $inclusions,
            fn ($f) => ! str_contains($f, 'errors/404.blade.php')
        ));

        $this->assertSame([], $inclusions,
            "`layouts/navigation.blade.php` est de nouveau reference : auditer ses appels route() non prefixes.");
    }
}
