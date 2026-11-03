<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1743 — le menu avatar de Main doit exister sur telephone.
 *
 * ## Le defaut, et ses DEUX causes
 *
 * Sur la homepage de Main (`organization/hero-v2.blade.php`), le menu avatar
 * etait inatteignable au telephone. OFSH et LaunchPals, eux, allaient bien.
 *
 * 1. **La barre entiere disparaissait.** `bouclepro-hero.css` masquait
 *    `.nav-menu` sous 760 px et montrait un burger a la place. L'avatar, qui
 *    vit DANS cette barre, partait avec elle.
 *
 * 2. **Le menu du burger etait INERTE.** Le partiel partage est inclus deux
 *    fois par ce gabarit — une fois dans la barre, une fois dans le panneau du
 *    burger. Son script est emis sous `@once`, donc a la PREMIERE inclusion,
 *    et s'execute en ligne : il appelle `querySelectorAll('.bpam')` a un
 *    instant ou seul le menu du bureau existe. Le declencheur du second menu
 *    ne recevait donc aucun ecouteur, et son panneau ne s'ouvrait jamais.
 *
 * Deux clics etaient necessaires la ou OFSH en demande zero, et le second ne
 * faisait rien.
 *
 * ## La correction reprend celle d'OFSH (T1725), elle n'en invente pas
 *
 * OFSH avait deja tranche le meme probleme : burger retire, la barre du bureau
 * DEVIENT celle du telephone. Le partiel n'etant plus inclus qu'une fois, la
 * seconde cause disparait d'elle-meme — on ne la corrige pas, on la supprime.
 *
 * Rien n'est perdu : `.m-menu` ne portait que « A propos », l'abonnement, le
 * selecteur FR/EN et le menu de compte. Les quatre vivent deja dans
 * `.nav-menu`.
 */
class TASK1743MainMobileAccountMenuTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'main-v2',
            'name' => 'Principale',
            'is_active' => true,
            'is_public' => true,
            'homepage_template' => 'bouclepro_hero_v2',
            'subscriptions_enabled' => true,
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->org->id,
            'email_verified_at' => now(),
            'approved_at' => now(),
        ]);
    }

    private function accueil(): string
    {
        return $this->actingAs($this->membre)
            ->get('/org/'.$this->org->slug)
            ->assertOk()
            ->getContent();
    }

    // ── Cause 1 : la barre ne doit plus se cacher ───────────────────────────

    public function test_la_barre_de_Main_ne_se_cache_plus_derriere_un_burger(): void
    {
        $html = $this->accueil();

        $this->assertStringNotContainsString('class="burger"', $html);
        $this->assertStringNotContainsString('id="m-menu"', $html);
    }

    public function test_la_feuille_de_style_ne_masque_plus_la_barre_sur_telephone(): void
    {
        // La garde vit dans le CSS et nulle part ailleurs : un test sur le HTML
        // seul resterait vert alors que la barre serait invisible a l'ecran.
        $css = file_get_contents(public_path('css/bouclepro-hero.css'));

        $this->assertStringNotContainsString('.bp-hero-v2 .nav-menu{display:none}', $css);
        $this->assertStringNotContainsString('.bp-hero-v2 .burger', $css);
    }

    // ── Cause 2 : un seul montage, donc un menu vivant ──────────────────────

    public function test_le_menu_de_compte_n_est_monte_QU_UNE_FOIS(): void
    {
        // C'est la cause de l'inertie : le script du partiel est emis sous
        // `@once` et s'execute EN LIGNE, donc avant qu'un second menu existe.
        // Un seul montage rend le defaut impossible.
        $html = $this->accueil();

        $this->assertSame(
            1,
            substr_count($html, 'data-marqueur="menu-compte-accueil"'),
            'le menu de compte est monte plusieurs fois : le second serait inerte',
        );
    }

    // ── Les entrees attendues, selon l'etat de la session ───────────────────

    public function test_le_menu_porte_les_entrees_du_bureau(): void
    {
        $html = $this->accueil();

        foreach ([
            __('navigation.dashboard'),
            __('navigation.settings'),
            __('navigation.loops'),
            __('footer.bug'),
            __('navigation.logout'),
        ] as $entree) {
            $this->assertStringContainsString($entree, $html, "entree absente : {$entree}");
        }

        // « A propos » vit dans la barre, a cote du menu — comme sur le bureau,
        // et comme sur OFSH et LaunchPals.
        $this->assertStringContainsString(__('navigation.about'), $html);
    }

    public function test_un_visiteur_ANONYME_voit_la_porte_et_non_le_menu(): void
    {
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-marqueur="menu-compte-accueil"', $html);
        $this->assertStringContainsString(route('organization.login', $this->org), $html);
    }

    // ── Rien de ce que le burger portait n'est perdu ────────────────────────

    public function test_la_barre_porte_TOUT_ce_que_le_burger_portait(): void
    {
        $this->org->update(['multilingual_enabled' => true]);

        $html = $this->accueil();

        $this->assertStringContainsString(__('navigation.about'), $html);
        $this->assertStringContainsString(route('organization.subscriptions', $this->org), $html);
        $this->assertStringContainsString(route('locale.switch', ['locale' => 'en']), $html);
    }

    // ── Et les deux gabarits qui allaient bien n'ont pas bouge ──────────────

    public function test_OFSH_et_LaunchPals_montent_toujours_le_menu_une_seule_fois(): void
    {
        foreach (['ofsh_hero', 'artscilab_hero'] as $gabarit) {
            $this->org->update(['homepage_template' => $gabarit]);

            $html = $this->accueil();

            $this->assertSame(
                1,
                substr_count($html, 'data-marqueur="menu-compte-accueil"'),
                "montage multiple sur le gabarit {$gabarit}",
            );
        }
    }
}
