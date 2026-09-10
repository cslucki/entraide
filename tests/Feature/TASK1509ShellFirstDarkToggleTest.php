<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestShellDisplayMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1509 — sur le Shell first SANS rail, la bascule clair/sombre n'etait
 * cablee a RIEN.
 *
 * Alpine n'initialise que les arbres qui partent d'une racine `x-data`.
 * `shell-first.blade.php` n'en portait aucune : le
 * `@click="$store.darkMode.toggle()"` de la bascule n'etait jamais cable, et
 * — c'est ce qui l'a rendu invisible — sans la moindre erreur console.
 *
 * En mode « avec rail », le rail (`<aside x-data>`) apportait sa propre racine
 * ET sa propre bascule : le defaut restait cache. En mode SANS rail, cette
 * bascule est la SEULE, et elle ne fonctionnait a aucune largeur.
 *
 * Ce test mesure la seule chose qu'un test HTTP peut prouver ici : la racine
 * existe dans le document, dans les DEUX modes, et la bascule est dedans. Le
 * comportement (classe `dark` qui bascule, choix persiste) est mesure au
 * navigateur — fiche de captures.
 */
class TASK1509ShellFirstDarkToggleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string} le document, et la barre isolee */
    private function render(string $mode): array
    {
        // Meme mise en place que TASK-1500 : le Shell ne s'affiche que si l'etat
        // est ACTIF (politique activee, credential presente, plafonds poses).
        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
        ]);

        $organization = Organization::factory()->create([
            'slug' => 'org-t1509', 'is_active' => true, 'is_public' => true,
            'is_default' => true, 'name' => 'Organisation T1509', 'locale' => 'fr',
        ]);

        app(GuestShellPolicyService::class)->update($organization, [
            'enabled' => true,
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

        $organization = $organization->fresh();

        $html = $this->get(route('organization.home', $organization))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<header[^>]*class="[^"]*bpsf-bar[^"]*"[^>]*>.*?<\/header>/s', $html, $m), "barre introuvable en mode {$mode}");

        return [$html, $m[0]];
    }

    private function assertToggleIsRooted(string $mode): void
    {
        [$html, $bar] = $this->render($mode);

        // La racine, sur la barre elle-meme.
        $this->assertSame(1, preg_match('/<header[^>]*class="[^"]*bpsf-bar[^"]*"[^>]*\sx-data(?![\w-])/', $html), "aucune racine Alpine sur la barre en mode {$mode}");

        // Et la bascule est bien DEDANS : une racine ailleurs ne la cablerait pas.
        $this->assertStringContainsString('class="bpsf-theme"', $bar, "la bascule doit vivre dans la barre en mode {$mode}");
        $this->assertStringContainsString('$store.darkMode.toggle()', $bar);
    }

    public function test_the_toggle_sits_inside_an_alpine_root_without_the_rail(): void
    {
        $this->assertToggleIsRooted(GuestShellDisplayMode::SHELL_FIRST);
    }

    public function test_the_toggle_sits_inside_an_alpine_root_with_the_rail(): void
    {
        $this->assertToggleIsRooted(GuestShellDisplayMode::SHELL_FIRST_RAIL);
    }

    /**
     * Sans rail, la bascule de la barre est la SEULE de la page : si elle
     * n'etait pas cablee, il n'y aurait aucun repli.
     */
    public function test_without_the_rail_the_bar_toggle_is_the_only_one(): void
    {
        [$html] = $this->render(GuestShellDisplayMode::SHELL_FIRST);

        $this->assertStringNotContainsString('<aside', $html, 'le mode sans rail ne doit pas rendre le rail');
        $this->assertSame(1, substr_count($html, '$store.darkMode.toggle()'), 'une seule bascule, donc aucun repli si elle n est pas cablee');
    }

    public function test_with_the_rail_both_toggles_exist_and_both_are_rooted(): void
    {
        [$html] = $this->render(GuestShellDisplayMode::SHELL_FIRST_RAIL);

        $this->assertStringContainsString('<aside x-data', $html, 'le rail porte sa propre racine');
        $this->assertSame(2, substr_count($html, '$store.darkMode.toggle()'), 'celle de la barre (masquee en CSS au-dessus de 768) et celle du rail');
    }
}
