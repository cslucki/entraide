<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\GuestShell\GuestPageContextResolver;
use App\Services\GuestShell\GuestShellDisplayModeResolver;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestShellDisplayMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1500 — le rail gauche devient un CHOIX d'affichage du Shell Welcome.
 *
 * Decision Cyril (10/09/2026 04h37) : dans /admin/ai-config, deux facons
 * d'etre « Shell first » — sans rail, ou avec le rail gauche pour que la page
 * ressemble a une Boucle. Puis (05h) : ce rail est LE MEME composant que
 * l'application, et il montre toutes ses entrees a un visiteur ; celui qui
 * clique et doit se connecter revient ensuite sur la page voulue.
 *
 * Ce que ces tests mesurent, et pourquoi chacun peut rougir :
 * - la liste des modes est EXHAUSTIVE (un mode ne peut pas entrer sans passer ici) ;
 * - le rail se monte en `shell_first_rail` et PAS en `shell_first` ;
 * - c'est le composant partage (`<aside … fixed inset-y-0 left-0`), pas une copie ;
 * - ses huit entrees sont rendues a un visiteur, sans le bouton « Cooperer » ;
 * - le CTA « Creer un compte » disparait des deux modes shell-first et reste en overlay ;
 * - les jetons de theme et Alpine sont bien montes sur la page autonome ;
 * - le select d'administration offre les trois modes, en francais et en anglais ;
 * - le nouveau mode est un « reason » valide pour `GuestShellDisplay` (sinon exception).
 */
class TASK1500GuestShellRailOptionTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $mode, bool $enabled = true): Organization
    {
        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
        ]);

        $organization = Organization::factory()->create([
            'is_public' => true,
            'is_active' => true,
            'is_default' => true,
            'slug' => 't1500-org',
            'name' => 'Organisation T1500',
            'locale' => 'fr',
        ]);

        app(GuestShellPolicyService::class)->update($organization, [
            'enabled' => $enabled,
            'max_messages' => 5,
            'display_mode' => $mode,
        ]);

        OrganizationAiSetting::create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-not-a-real-key',
            'is_enabled' => true,
        ]);

        return $organization->fresh();
    }

    private function home(Organization $organization): string
    {
        return $this->get(route('organization.home', $organization))->assertOk()->getContent();
    }

    // ── 1. Le contrat des modes ────────────────────────────────────────────

    public function test_the_mode_list_is_exhaustive_and_the_helpers_agree_on_what_is_shell_first(): void
    {
        $this->assertSame(['overlay', 'shell_first', 'shell_first_rail'], GuestShellDisplayMode::MODES);
        $this->assertSame(['shell_first', 'shell_first_rail'], GuestShellDisplayMode::SHELL_FIRST_MODES);

        $this->assertTrue(GuestShellDisplayMode::isValid('shell_first_rail'));
        $this->assertTrue(GuestShellDisplayMode::isShellFirst('shell_first'));
        $this->assertTrue(GuestShellDisplayMode::isShellFirst('shell_first_rail'));
        $this->assertFalse(GuestShellDisplayMode::isShellFirst('overlay'));
        $this->assertFalse(GuestShellDisplayMode::isShellFirst(null));

        $this->assertTrue(GuestShellDisplayMode::showsRail('shell_first_rail'));
        $this->assertFalse(GuestShellDisplayMode::showsRail('shell_first'));
        $this->assertFalse(GuestShellDisplayMode::showsRail('overlay'));

        // Le defaut ne bouge pas : rien ne devient public par lui-meme.
        $this->assertSame(GuestShellDisplayMode::OVERLAY, GuestShellDisplayMode::DEFAULT);
    }

    public function test_the_policy_stores_the_rail_mode_and_the_resolver_reports_it_as_a_valid_reason(): void
    {
        $organization = $this->organization(GuestShellDisplayMode::SHELL_FIRST_RAIL);

        $this->assertSame('shell_first_rail', app(GuestShellPolicyService::class)->policyFor($organization)->display_mode);

        // `GuestShellDisplay` refuse un « reason » inconnu par exception : si le
        // mode n'avait pas ete ajoute a REASONS, cette resolution exploserait.
        $page = app(GuestPageContextResolver::class)->fromRoute(
            $organization,
            Route::getRoutes()->match(Request::create('/org/'.$organization->slug, 'GET')),
        );
        $display = app(GuestShellDisplayModeResolver::class)->resolve($organization, $page);

        $this->assertSame('shell_first_rail', $display->mode);
        $this->assertSame('shell_first_rail', $display->reason);
    }

    // ── 2. Le rendu des deux variantes shell-first ────────────────────────

    public function test_shell_first_rail_mounts_the_shared_rail_and_offsets_the_page(): void
    {
        $html = $this->home($this->organization(GuestShellDisplayMode::SHELL_FIRST_RAIL));

        $this->assertStringContainsString('bpsf-page', $html, 'la vue Shell First n\'est pas rendue');
        // On mesure le RENDU : la feuille de style nomme toujours `.bpsf-page--rail`,
        // seul le <body> dit si la classe est POSEE.
        $this->assertMatchesRegularExpression('/<body class="bpsf-page\s+bpsf-page--rail/', $html, 'la page ne se decale pas pour le rail');
        $this->assertSame(1, substr_count($html, 'id="bp-guest-shell"'));
        $this->assertStringContainsString('class="bpgs-first"', $html);

        // LE composant partage — sa signature structurelle, pas une copie.
        $this->assertMatchesRegularExpression('/<aside[^>]*fixed inset-y-0 left-0[^>]*>/', $html, 'le rail partage n\'est pas monte');
        $this->assertSame(1, preg_match_all('/<aside[^>]*fixed inset-y-0 left-0/', $html), 'un seul rail');

        // Un visiteur n'a pas le bouton « Cooperer » : la liste, elle, est complete.
        $aside = $this->aside($html);
        $this->assertStringNotContainsString('aria-expanded', $aside, 'le bouton Cooperer est reserve aux connectes');
        foreach (['navigation.feed', 'navigation.loops', 'navigation.agenda', 'navigation.exchanges', 'navigation.messaging', 'navigation.directory', 'navigation.blog', 'navigation.my_dossiers'] as $key) {
            $this->assertStringContainsString('title="'.e(__($key)).'"', $aside, "entree {$key} absente du rail visiteur");
        }
        $this->assertStringContainsString('aria-label="'.e(__('navigation.language_switcher')).'"', $aside, 'la langue n\'est pas dans le rail');
        $this->assertStringContainsString('$store.darkMode.toggle()', $aside, 'la bascule clair/sombre n\'est pas dans le rail');

        // Ce que le rail requiert pour VIVRE sur un document autonome.
        $this->assertStringContainsString('--bp-surface:', $html, 'les jetons de theme ne sont pas emis');
        $this->assertStringContainsString('data-bp-theme="', $html, 'la cle de theme n\'est pas posee sur <html>');
        $this->assertStringContainsString('window.bpThemes = ', $html, 'le cycleur de theme ne connait pas les themes');
        $this->assertMatchesRegularExpression('/livewire/i', $html, 'Alpine (via Livewire) n\'est pas monte : les bascules cliqueraient dans le vide');

        // Ce qui disparait en shell-first : le CTA, la mention de bas de page.
        // Idem pour le CTA : le JS du partial cite `[data-guest-shell-cta]` dans un
        // querySelector ; seule une balise <a> le RENDRAIT (TASK-1442 mesure pareil).
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*data-guest-shell-cta/', $html, 'le CTA « Creer un compte » doublonne « Connexion »');
        $this->assertStringContainsString('data-footer-mycelium', $html, 'le pied de page BouclePro n\'est pas monte');
        $this->assertStringNotContainsString('<h1', $html, 'la landing est rendue derriere le Shell');
    }

    public function test_shell_first_without_rail_mounts_no_rail_and_no_cta(): void
    {
        $html = $this->home($this->organization(GuestShellDisplayMode::SHELL_FIRST));

        $this->assertStringContainsString('bpsf-page', $html);
        $this->assertMatchesRegularExpression('/<body class="bpsf-page\s*">/', $html, 'le mode sans rail decale la page');
        $this->assertDoesNotMatchRegularExpression('/<aside[^>]*fixed inset-y-0 left-0/', $html, 'un rail est monte sans avoir ete choisi');
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*data-guest-shell-cta/', $html);
        $this->assertStringContainsString('data-footer-mycelium', $html);
    }

    public function test_overlay_keeps_its_cta_and_never_gets_the_rail(): void
    {
        $html = $this->home($this->organization(GuestShellDisplayMode::OVERLAY));

        $this->assertStringNotContainsString('bpsf-page', $html);
        $this->assertStringContainsString('data-guest-shell-mode="overlay"', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*data-guest-shell-cta/', $html, 'en overlay le CTA reste : la page publique n\'a pas la barre « Connexion »');
    }

    // ── 3. L'administration ───────────────────────────────────────────────

    public function test_the_admin_select_offers_the_three_modes_in_both_languages(): void
    {
        $this->organization(GuestShellDisplayMode::SHELL_FIRST_RAIL);
        $superAdmin = User::factory()->complete()->create(['is_admin' => true]);

        foreach (['fr', 'en'] as $locale) {
            // TASK-1500 : le select vit sur la page dediee de configuration.
            $html = $this->actingAs($superAdmin)->withSession(['locale' => $locale])->get(route('admin.shell-welcome-config'))->assertOk()->getContent();

            foreach (GuestShellDisplayMode::MODES as $mode) {
                $label = trans('admin.guest_shell_display_mode_'.$mode, [], $locale);
                $this->assertNotSame('admin.guest_shell_display_mode_'.$mode, $label, "[$locale] libelle manquant pour {$mode}");
                $this->assertStringContainsString('<option value="'.$mode.'"', $html, "[$locale] option {$mode} absente du select");
                $this->assertStringContainsString(e($label), $html, "[$locale] libelle de {$mode} non rendu");
            }

            $this->assertStringContainsString('data-guest-shell-display-mode="shell_first_rail"', $html, "[$locale] le mode choisi n'est pas reflete");
        }
    }

    public function test_the_admin_sidebar_files_workshops_under_exchanges_and_the_measuring_screens_under_stats(): void
    {
        $superAdmin = User::factory()->complete()->create(['is_admin' => true]);
        $page = $this->actingAs($superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        // On mesure la SIDEBAR, pas la page : le corps peut lier legitimement
        // vers ces ecrans (la section Shell Welcome lie le cockpit, par exemple).
        preg_match('/<aside.*?<main/s', $page, $m);
        $this->assertNotEmpty($m, 'sidebar introuvable');
        $html = $m[0];

        $pos = fn (string $needle): int => (int) strpos($html, $needle);

        // Echanges se termine par Tags puis Ateliers ; IA commence par « Organizations & IA ».
        $this->assertGreaterThan(0, $pos(route('admin.workshops')));
        $this->assertGreaterThan($pos(route('admin.tags')), $pos(route('admin.workshops')), 'Ateliers doit suivre Tags dans Echanges');
        $this->assertLessThan($pos(route('admin.ai-organizations')), $pos(route('admin.workshops')), 'Ateliers ne doit plus etre dans IA');

        // TASK-1500 : la page de configuration Shell Welcome est dans « IA », juste apres les reglages plateforme.
        $this->assertGreaterThan($pos(route('admin.ai-config')), $pos(route('admin.shell-welcome-config')), 'Config. Shell Welcome doit suivre Reglages IA plateforme');
        $this->assertGreaterThan($pos(route('admin.ai-organizations')), $pos(route('admin.shell-welcome-config')), 'Config. Shell Welcome doit etre dans IA');

        $this->assertStringContainsString('Stats, Logs et compta', $html, 'la section Stats n\'est pas renommee');
        $stats = $pos('Stats, Logs et compta');
        foreach (['admin.ia-usage-by-user', 'admin.ia-usage', 'admin.ai-benchmark', 'admin.ai-interactions', 'admin.guest-shell'] as $name) {
            $this->assertSame(1, substr_count($html, 'href="'.route($name).'"'), "{$name} doit apparaitre une seule fois");
            $this->assertGreaterThan($stats, $pos('href="'.route($name).'"'), "{$name} doit etre sous Stats");
            $this->assertLessThan($pos(route('admin.ai-organizations')), $pos('href="'.route($name).'"'), "{$name} ne doit plus etre dans IA");
        }
    }

    private function aside(string $html): string
    {
        preg_match('/<aside[^>]*fixed inset-y-0 left-0.*?<\/aside>/s', $html, $m);
        $this->assertNotEmpty($m, 'aside introuvable');

        return $m[0];
    }
}
