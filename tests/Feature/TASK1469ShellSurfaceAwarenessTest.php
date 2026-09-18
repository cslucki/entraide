<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1469 (CDC 21h-23h §2.4, UX-3) — le Shell dit OU l'on se trouve.
 *
 * ## Ce que remplace cette tranche
 *
 * Le sous-titre disait « Disponible partout sur BouclePro ». Generique, et
 * depuis TASK-1466 carrement faux : le Shell n'est justement PAS partout, il
 * n'est plus sur une Boucle.
 *
 * ## Les quatre couches restent separees
 *
 * Le CDC est explicite, et ce test le tient :
 *
 *   PageContext     -> ou suis-je ?              (ce fichier)
 *   UsageReference  -> comment utiliser ce lieu ?
 *   Runtime         -> que puis-je y faire ?
 *   Constitution    -> comment l'IA se comporte ?
 *
 * D'ou l'assertion la moins evidente et la plus importante de ce fichier :
 * aucun libelle de surface ne promet une fonction. « Vous etes sur l'agenda »
 * est une position, pas une capacite. Le jour ou quelqu'un ecrira « Vous etes
 * sur l'agenda, vous pouvez creer un evenement », il aura fusionne deux
 * couches — et ce test rougira.
 *
 * ## Et la surface n'accorde rien
 *
 * Comme tout `AiShellPageContext`, elle DECRIT ce que la page montre deja. Un
 * nom de route inconnu retombe sur `unknown`, dont le libelle ne dit rien de
 * faux plutot que d'inventer un lieu.
 */
class TASK1469ShellSurfaceAwarenessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-ux3',
            'name' => 'Org UX3',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1469',
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. La resolution : nom de route exact, jamais l'URL
    // =====================================================================

    public function test_every_declared_route_resolves_to_its_surface(): void
    {
        foreach (AiShellPageContext::SURFACE_ROUTES as $surface => $routes) {
            foreach ($routes as $routeName) {
                $this->assertSame(
                    $surface,
                    AiShellPageContext::surfaceFor($routeName),
                    $routeName.' devrait resoudre en '.$surface,
                );
            }
        }
    }

    public function test_an_unknown_route_never_invents_a_place(): void
    {
        foreach (['', 'une.route.inexistante', 'loops.show', 'organization.loops.show'] as $routeName) {
            $this->assertSame(AiShellPageContext::SURFACE_UNKNOWN, AiShellPageContext::surfaceFor($routeName), $routeName);
        }
    }

    /**
     * Toutes les routes declarees existent REELLEMENT. Une table qui nomme une
     * route disparue resoudrait toujours « correctement » en test tout en
     * n'etant jamais atteinte en production.
     */
    public function test_every_declared_route_actually_exists(): void
    {
        foreach (AiShellPageContext::SURFACE_ROUTES as $surface => $routes) {
            foreach ($routes as $routeName) {
                $this->assertTrue(Route::has($routeName), "la route [{$routeName}] declaree pour [{$surface}] n'existe pas");
            }
        }
    }

    // =====================================================================
    // B. Les libelles : une position, jamais une capacite
    // =====================================================================

    public function test_every_surface_has_a_short_label_in_both_languages(): void
    {
        $surfaces = [...array_keys(AiShellPageContext::SURFACE_ROUTES), AiShellPageContext::SURFACE_UNKNOWN];

        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($surfaces as $surface) {
                $label = __('ai.shell_surface_'.$surface);

                $this->assertNotSame('ai.shell_surface_'.$surface, $label, $locale.'/'.$surface.' : cle non traduite');
                $this->assertLessThanOrEqual(60, mb_strlen($label), $locale.'/'.$surface.' : une phrase courte');
            }
        }

        app()->setLocale('fr');
    }

    /**
     * La separation des couches, mesuree. Un libelle de POSITION ne promet
     * aucune fonction : ni verbe d'action adresse a la personne, ni « vous
     * pouvez ». Le jour ou quelqu'un fusionnera PageContext et UsageReference,
     * il passera par ici.
     */
    public function test_no_surface_label_promises_a_capability(): void
    {
        $surfaces = [...array_keys(AiShellPageContext::SURFACE_ROUTES), AiShellPageContext::SURFACE_UNKNOWN];

        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($surfaces as $surface) {
                $label = __('ai.shell_surface_'.$surface);

                $this->assertDoesNotMatchRegularExpression(
                    '/vous pouvez|you can|cr[ée]er|create|ajouter|add|publier|publish|inviter|invite/i',
                    $label,
                    $locale.'/'.$surface.' : la surface dit OU, jamais QUOI FAIRE (c\'est UsageReference et le runtime)',
                );
            }
        }

        app()->setLocale('fr');
    }

    // =====================================================================
    // C. A l'ecran
    // =====================================================================

    public function test_the_shell_and_the_fab_name_the_same_place(): void
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier UX3',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $cases = [
            // TASK-1473 : le tableau de bord est `dashboard`, pas
            // `organization_home` — cette clé désigne l'accueil public, et
            // `UsageReference` la porte déjà dans ce sens.
            'dashboard' => route('organization.dashboard', ['organization' => $this->organization->slug]),
            'dossier' => route('organization.dossiers.show', ['organization' => $this->organization->slug, 'dossier' => $dossier->id]),
        ];

        foreach ($cases as $surface => $url) {
            $html = $this->actingAs($this->member)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-ai-shell-surface="'.$surface.'"', $html, $surface);
            $this->assertStringContainsString(e(__('ai.shell_surface_'.$surface)), $html, $surface);
        }
    }

    /** Le sous-titre generique a disparu de l'ecran. */
    public function test_the_generic_subtitle_is_gone(): void
    {
        $html = $this->actingAs($this->member)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(e(__('ai.fab_subtitle_other')), $html);
        $this->assertStringContainsString(e(__('ai.shell_surface_dashboard')), $html);
    }
}
