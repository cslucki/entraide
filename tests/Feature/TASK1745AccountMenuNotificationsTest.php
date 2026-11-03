<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1745 (demande Cyril, 03/11) — « Notifications » au menu avatar des
 * trois homepages.
 *
 * ## Les TROIS, et c'est tout l'objet du test
 *
 * `hero-v2` (Main), `artscilab-hero` (LaunchPals) et `ofsh-hero` (OFSH) sont
 * trois pages complètes, avec chacune sa feuille de style. Elles partagent
 * le partiel `organization/partials/account-menu` depuis T1720, précisément
 * pour qu'une entrée ajoutée ne le soit pas trois fois — ni oubliée sur
 * l'une des trois.
 *
 * Une entrée ajoutée au partiel devrait donc apparaître partout. « Devrait »
 * ne suffit pas : un gabarit qui cesserait d'inclure le partiel le ferait
 * silencieusement, et c'est exactement la régression que T1720 existe pour
 * empêcher. Les trois sont donc visitées.
 *
 * ## Le lien doit rester dans SON tenant
 *
 * La route est org-scopée quand elle existe sous cette forme. Arriver sur le
 * Centre d'une autre Organization que celle de la page serait une bascule
 * silencieuse — le genre de défaut que le chantier A1→A7 a passé une semaine
 * à fermer.
 */
class TASK1745AccountMenuNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** Les trois gabarits de homepage autonomes, et leur slug de test. */
    private const GABARITS = [
        'bouclepro_hero_v2' => 't1745-main',
        'artscilab_hero' => 't1745-launchpals',
        'ofsh_hero' => 't1745-ofsh',
    ];

    public function test_les_TROIS_homepages_portent_l_entree_notifications(): void
    {
        foreach (self::GABARITS as $gabarit => $slug) {
            $html = $this->accueil($gabarit, $slug);

            $this->assertStringContainsString(
                __('navigation.notifications'),
                $html,
                "[{$gabarit}] l'entree Notifications doit figurer au menu avatar",
            );
        }
    }

    public function test_le_lien_reste_dans_le_tenant_de_la_page(): void
    {
        foreach (self::GABARITS as $gabarit => $slug) {
            $html = $this->accueil($gabarit, $slug);

            $attendu = route('organization.notifications.index', ['organization' => $slug]);

            $this->assertStringContainsString(
                'href="'.$attendu.'"',
                $html,
                "[{$gabarit}] le lien doit viser le Centre de CETTE Organization",
            );
        }
    }

    public function test_les_entrees_voisines_sont_INTACTES(): void
    {
        // Ajouter une porte ne doit pas en fermer une autre. L'inventaire est
        // ecrit a la main : il rougirait si une entree disparaissait, ce
        // qu'un simple « le menu contient Notifications » ne verrait pas.
        $html = $this->accueil('bouclepro_hero_v2', 't1745-main');

        foreach ([
            __('navigation.dashboard'),
            __('navigation.notifications'),
            __('navigation.settings'),
            __('navigation.loops'),
            __('footer.bug'),
            __('navigation.logout'),
        ] as $entree) {
            $this->assertStringContainsString($entree, $html, "entree absente : {$entree}");
        }
    }

    public function test_un_visiteur_ANONYME_ne_voit_pas_l_entree(): void
    {
        // Le menu entier appartient aux personnes connectees : le Centre de
        // notifications de personne n'existe pas.
        $organization = $this->organization('bouclepro_hero_v2', 't1745-anon');

        $this->get('/org/'.$organization->slug)
            ->assertOk()
            ->assertDontSee('data-marqueur="menu-compte-accueil"', false);
    }

    private function organization(string $gabarit, string $slug): Organization
    {
        return Organization::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'is_public' => true,
            'homepage_template' => $gabarit,
        ]);
    }

    private function accueil(string $gabarit, string $slug): string
    {
        $organization = $this->organization($gabarit, $slug);

        $membre = User::factory()->create([
            'organization_id' => $organization->id,
            'email_verified_at' => now(),
            'approved_at' => now(),
        ]);

        return $this->actingAs($membre)
            ->get('/org/'.$organization->slug)
            ->assertOk()
            ->getContent();
    }
}
