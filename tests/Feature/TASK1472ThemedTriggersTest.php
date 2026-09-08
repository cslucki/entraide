<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1472 (CDC 23h-01h §5, UX-2) — les declencheurs « BouclePro IA » portent
 * la couleur « Action principale » du theme de l'Organization, et ne
 * promettent plus une question impossible.
 *
 * ## Deux choses, une raison commune : dire la verite
 *
 * 1. **La couleur.** Le FAB membre etait un degrade `violet-600 → indigo-600`
 *    en dur ; le declencheur Guest, un `#111827` en dur. Aucun des deux
 *    n'appartenait visuellement a l'Organization.
 * 2. **Le libelle.** TASK-1467 avait rendu le PANNEAU honnete en etat degrade,
 *    mais le BOUTON promettait encore « Une question ? ». On ouvrait pour
 *    poser une question, on apprenait ensuite que l'assistant ne repond pas.
 *
 * ## Pourquoi un `<style>` local plutot que des classes Tailwind
 *
 * Une classe `bg-[var(--bp-primary)]` absente du build serait un no-op
 * SILENCIEUX — le rendu resterait transparent et rien ne le signalerait. Ce
 * piege a deja mordu ce depot. Les regles vivent donc dans une feuille locale,
 * qui ne depend d'aucune compilation.
 *
 * ## Le repli n'est pas decoratif
 *
 * `var(--bp-primary, <repli>)` : si le token manquait, un bouton TRANSPARENT
 * serait pire qu'un bouton d'une autre couleur. Le CDC l'exige explicitement,
 * et un test le mesure.
 *
 * ## Ce que ce test ne peut pas faire
 *
 * PHPUnit ne calcule pas de CSS. Il verifie donc que les declarations LISENT
 * le token et portent un repli ; la valeur calculee (`rgb(11, 77, 255)` sur le
 * theme zen) est mesuree au navigateur et consignee dans la fiche TASK.
 */
class TASK1472ThemedTriggersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Plus aucune couleur d'autorite en dur sur les declencheurs
    // =====================================================================

    public function test_no_hardcoded_brand_colour_remains_on_the_triggers(): void
    {
        $fab = (string) file_get_contents(resource_path('views/components/ai-fab.blade.php'));

        $this->assertStringNotContainsString('from-violet-600', $fab, 'le degrade viole/indigo n\'est plus l\'autorite du declencheur');
        $this->assertStringNotContainsString('to-indigo-600', $fab);

        $overlay = (string) file_get_contents(resource_path('views/organization/partials/guest-shell-overlay.blade.php'));

        // `#111827` reste legitime comme REPLI et comme couleur de texte ; ce
        // qui ne doit plus exister est le fond du declencheur en dur.
        $this->assertStringNotContainsString('.bpgs-toggle{display:flex;align-items:center;gap:10px;border:0;cursor:pointer;background:#111827', $overlay);
    }

    /** Chaque declencheur lit le token ET porte un repli : jamais de bouton transparent. */
    public function test_every_trigger_reads_the_theme_token_with_a_safe_fallback(): void
    {
        $sources = [
            'ai-fab' => (string) file_get_contents(resource_path('views/components/ai-fab.blade.php')),
            'guest-shell-overlay' => (string) file_get_contents(resource_path('views/organization/partials/guest-shell-overlay.blade.php')),
        ];

        foreach ($sources as $label => $source) {
            // TOUTES les lectures du token, pas au moins une : une seule
            // declaration privee de repli suffit a rendre un bouton
            // transparent. Mesure faite — l'assertion « au moins une » laissait
            // passer le sabotage, parce que les autres declarations du meme
            // fichier la satisfaisaient a sa place.
            $this->assertGreaterThan(0, preg_match_all('/var\(\s*--bp-primary(-deep)?\b/i', $source), $label.' : le fichier lit bien le token');

            preg_match_all('/var\(\s*--bp-primary(?:-deep)?\s*([^)]*)\)/i', $source, $reads, PREG_SET_ORDER);

            foreach ($reads as $read) {
                $this->assertMatchesRegularExpression(
                    '/^,\s*#[0-9a-f]{3,8}\s*$/i',
                    $read[1],
                    $label.' : lecture du token SANS repli sur — '.$read[0],
                );
            }
        }
    }

    // =====================================================================
    // B. A l'ecran : le declencheur est rendu, avec le token de SON theme
    // =====================================================================

    public function test_the_member_trigger_is_rendered_and_the_page_carries_its_token(): void
    {
        $organization = $this->organization('org-ux2-member');
        $member = User::factory()->complete()->create(['organization_id' => $organization->id]);

        $html = $this->actingAs($member)
            ->get(route('organization.dashboard', ['organization' => $organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bp-ai-trigger', $html, 'le declencheur membre porte la classe thematisee');
        $this->assertStringContainsString('--bp-primary:', $html, 'et la page emet reellement le token qu\'elle lit');
    }

    public function test_the_guest_trigger_is_rendered_and_the_landing_carries_its_token(): void
    {
        $organization = $this->organizationWithGuestShell('org-ux2-guest');

        $html = $this->get(route('organization.home', ['organization' => $organization->slug]))->assertOk()->getContent();

        $this->assertStringContainsString('bpgs-toggle', $html);
        $this->assertStringContainsString('background:var(--bp-primary,', $html);
        $this->assertStringContainsString('--bp-primary:', $html, 'la landing emet le token depuis TASK-1471');
    }

    // =====================================================================
    // C. Le declencheur ne promet plus une question impossible
    // =====================================================================

    /**
     * Le P2 laisse ouvert par TASK-1467. En etat degrade, le panneau disait la
     * verite et le bouton la contredisait.
     */
    public function test_the_guest_trigger_stops_promising_a_question_when_the_shell_cannot_answer(): void
    {
        $organization = $this->organizationWithGuestShell('org-ux2-degraded');

        // Aucun plafond plateforme : l'etat degrade REEL du banc.
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => null]);

        $html = $this->get(route('organization.home', ['organization' => $organization->slug]))->assertOk()->getContent();

        $this->assertStringContainsString('data-guest-shell-state="degraded"', $html);
        $this->assertStringContainsString('data-guest-shell-toggle-state="degraded"', $html);

        // Le bouton, mesure pour lui-meme : il ne pose plus de question.
        $this->assertSame(1, preg_match('/<button[^>]*data-guest-shell-toggle[^>]*>(.*?)<\/button>/s', $html, $button));
        $this->assertStringNotContainsString(e(__('guest_shell.ui.open', ['name' => $organization->name])), $button[1],
            'un bouton qui invite a poser une question alors qu\'aucune reponse n\'est possible');
        $this->assertStringContainsString(e(__('guest_shell.ui.open_unavailable')), $button[1]);
    }

    /** Quand le Shell repond vraiment, l'invitation reste. */
    public function test_the_invitation_stays_when_the_shell_is_live(): void
    {
        $organization = $this->organizationWithGuestShell('org-ux2-live');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 10.0]);

        $html = $this->get(route('organization.home', ['organization' => $organization->slug]))->assertOk()->getContent();

        $this->assertStringContainsString('data-guest-shell-toggle-state="live"', $html);
        $this->assertSame(1, preg_match('/<button[^>]*data-guest-shell-toggle[^>]*>(.*?)<\/button>/s', $html, $button));
        $this->assertStringContainsString(e(__('guest_shell.ui.open', ['name' => $organization->name])), $button[1]);
    }

    /** Le libelle indisponible existe dans les deux langues et ne pose pas de question. */
    public function test_the_unavailable_label_exists_in_both_languages_and_asks_nothing(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            $label = __('guest_shell.ui.open_unavailable');

            $this->assertNotSame('guest_shell.ui.open_unavailable', $label, $locale);
            $this->assertStringNotContainsString('?', $label, $locale.' : un bouton indisponible ne pose pas de question');
        }

        app()->setLocale('fr');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function organization(string $slug): Organization
    {
        $themeKey = array_key_first(bp_themes()['themes']);
        $theme = Theme::query()->firstOrCreate(['key' => $themeKey], ['label' => strtoupper($themeKey)]);

        return Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => $slug,
            'homepage_template' => 'bouclepro_hero_v2',
            'theme_id' => $theme->id,
        ]);
    }

    private function organizationWithGuestShell(string $slug): Organization
    {
        $organization = $this->organization($slug);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1472',
        ]);

        OrganizationGuestShellPolicy::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            ['enabled' => true, 'display_mode' => 'overlay'],
        );

        return $organization;
    }
}
