<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TASK-1505 — la barre laterale de l'admin d'Organisation suit le theme
 * choisi, et reste TOUJOURS sombre avec un texte blanc, en mode clair comme
 * en mode sombre.
 *
 * Trois contrats, trois tests : l'emetteur de jetons fournit `--bp-sidebar-*`
 * depuis la palette SOMBRE de chaque theme, hors de `.dark` ; le layout ne
 * porte plus une seule couleur figee dans l'aside ; la feuille de style peint
 * l'aside avec ces jetons. Le rendu (luminance mesuree sur 6 themes x 2
 * modes) est dans la fiche de captures.
 */
class TASK1505OrgAdminSidebarThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_tokens_come_from_the_dark_palette_of_every_theme_and_ignore_the_colour_mode(): void
    {
        $css = Blade::render('<x-theme-tokens />');
        $themes = bp_themes()['themes'];

        foreach ($themes as $key => $theme) {
            // Le bloc CLAIR du theme — celui qui s'applique sans `.dark`.
            $this->assertSame(1, preg_match('/(?<!\.dark)\[data-bp-theme="'.preg_quote($key, '/').'"\]\s*\{([^}]*)\}/', $css, $m), "bloc [data-bp-theme={$key}] absent");
            $block = $m[1];

            $this->assertStringContainsString('--bp-sidebar-bg: '.$theme['dark']['panel'].';', $block, "{$key} : fond de barre = panel SOMBRE");
            $this->assertStringContainsString('--bp-sidebar-accent: '.$theme['dark']['primary'].';', $block, "{$key} : accent = primaire SOMBRE");
            $this->assertStringContainsString('--bp-sidebar-border: '.$theme['dark']['border'].';', $block, "{$key} : bordure SOMBRE");
            $this->assertStringContainsString('--bp-sidebar-text: #FFFFFF;', $block, "{$key} : texte blanc");
            $this->assertStringNotContainsString('--bp-sidebar-bg: '.$theme['tokens']['panel'].';', $block, "{$key} : le panel CLAIR ne doit jamais servir de fond de barre");
        }

        // `:root` porte le theme par defaut, meme regle.
        $default = $themes[bp_themes()['default']];
        $this->assertSame(1, preg_match('/:root\s*\{([^}]*)\}/', $css, $m));
        $this->assertStringContainsString('--bp-sidebar-bg: '.$default['dark']['panel'].';', $m[1]);

        // Les jetons ne sont PAS redefinis sous `.dark` : ils ne dependent pas du mode.
        preg_match_all('/\.dark[^{]*\{([^}]*)\}/', $css, $dark);
        $this->assertNotEmpty($dark[1]);
        foreach ($dark[1] as $block) {
            $this->assertStringNotContainsString('--bp-sidebar-', $block, 'un jeton de barre laterale sous .dark changerait avec le mode');
        }
    }

    public function test_the_org_admin_sidebar_carries_no_fixed_colour(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-t1505']);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $org->update(['admin_id' => $admin->id]);

        $html = $this->actingAs($admin)
            ->get(route('organization.admin.dashboard', ['organization' => $org->slug]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/<aside\b.*?<\/aside>/s', $html, $m), 'aside introuvable');
        $aside = $m[0];

        $this->assertMatchesRegularExpression('/<aside\b[^>]*class="[^"]*\bbp-org-sidebar\b/', $aside);
        $this->assertStringContainsString('bp-sb-active', $aside, 'l entree active (tableau de bord) porte la classe de jeton');
        $this->assertStringContainsString('bp-sb-link', $aside);
        $this->assertStringContainsString('bp-sb-group', $aside);

        foreach (['bg-gray-', 'text-gray-', 'border-gray-', 'hover:bg-gray-', 'text-indigo-', 'var(--bp-primary)'] as $fixed) {
            $this->assertStringNotContainsString($fixed, $aside, "couleur figee « {$fixed} » encore presente dans la barre laterale");
        }
    }

    public function test_the_stylesheet_paints_the_sidebar_with_the_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertSame(1, preg_match('/\.bp-org-sidebar\s*\{([^}]*)\}/', $css, $m), 'bloc .bp-org-sidebar absent');
        $this->assertStringContainsString('var(--bp-sidebar-bg', $m[1]);
        $this->assertStringContainsString('var(--bp-sidebar-text', $m[1]);

        // L'entree active garde le texte blanc de la barre : l'accent la marque
        // par une barre laterale, il ne devient jamais un FOND de texte (blanc
        // sur l'accent de `sable` : 1,92:1, mesure).
        $this->assertSame(1, preg_match('/\.bp-org-sidebar \.bp-sb-active\s*\{([^}]*)\}/', $css, $m));
        $this->assertStringContainsString('var(--bp-sidebar-text', $m[1]);
        $this->assertStringContainsString('inset 3px 0 0 var(--bp-sidebar-accent', $m[1]);
        $this->assertStringNotContainsString('background-color: var(--bp-sidebar-accent', $m[1]);

        $this->assertSame(1, preg_match('/\.bp-org-sidebar \.bp-sb-link:hover\s*\{([^}]*)\}/', $css, $m));
        $this->assertStringContainsString('var(--bp-sidebar-soft', $m[1]);
    }
}
