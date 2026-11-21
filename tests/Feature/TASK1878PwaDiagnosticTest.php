<?php

namespace Tests\Feature;

use App\Http\Controllers\PwaDiagnosticController;
use App\Models\Organization;
use App\Models\User;
use App\Support\Pwa\PwaAppName;
use App\Support\Sharing\ShareIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1878 — console « Diagnostic PWA ».
 *
 * Ce que ce fichier defend, par ordre d'importance :
 *
 * 1. la verification du partage n'a AUCUN effet metier : un partage reel en
 *    attente en session survit intact, et l'absence de partage n'en cree pas ;
 * 2. rien de prive ne sort : ni clef VAPID privee, ni URL analysee, ni Boucle ;
 * 3. la console reutilise le bloc Notifications de T1877 et ses routes ;
 * 4. on y arrive depuis la page appareil, pas depuis le menu.
 */
class TASK1878PwaDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        // T1822 — sans ces reglages, le bloc Push est indisponible sur le banc.
        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.subject', 'mailto:contact@exemple.test');
        config()->set('webpush.vapid.public_key', 'BPubliqueDeTestPubliqueDeTestPubliqueDeTestPubliqueDeTest');
        config()->set('webpush.vapid.private_key', 'SECRET-PRIVE-NE-DOIT-JAMAIS-SORTIR');

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->alice = User::factory()->create(['organization_id' => $this->org->id]);
    }

    // ── La page ──────────────────────────────────────────────────────────────

    public function test_la_console_porte_les_quatre_sections_le_rapport_et_les_limites(): void
    {
        $html = $this->actingAs($this->alice)->get(route('pwa.diagnostic'))->assertOk()->getContent();

        foreach (['installation', 'service_worker', 'notifications', 'sharing'] as $section) {
            $this->assertStringContainsString('data-diag-section="'.$section.'"', $html);
        }

        // Les deux indicateurs approuves, et la ligne que le web ne peut pas mesurer.
        $this->assertStringContainsString('data-diag-row="share_standalone"', $html);
        $this->assertStringContainsString('data-diag-row="share_declared"', $html);
        $this->assertStringContainsString('data-diag-row="share_native"', $html);

        // Mesures automatiques et verifications manuelles, separees.
        $this->assertStringContainsString('data-diag-manual', $html);
        $this->assertStringContainsString('data-diag-row="m_share_menu"', $html);

        $this->assertStringContainsString('data-pwa-report-copy', $html);
        $this->assertStringContainsString(e(__('pwa.diagnostic.limits')), $html);
        $this->assertStringContainsString('data-verification-url="'.route('pwa.diagnostic.partage').'"', $html);
        $this->assertStringContainsString('data-expected-name="'.e(PwaAppName::current()).'"', $html);
        $this->assertStringContainsString('data-expected-sw="'.PwaDiagnosticController::versionDuServiceWorkerServi().'"', $html);
        $this->assertStringContainsString('data-app-version="'.trim(file_get_contents(base_path('VERSION'))).'"', $html);
    }

    public function test_la_section_notifications_reprend_le_bloc_de_T1877_avec_le_diagnostic_deplie(): void
    {
        $html = $this->actingAs($this->alice)->get(route('pwa.diagnostic'))->assertOk()->getContent();

        $this->assertStringContainsString('data-push-device', $html);
        $this->assertStringContainsString('data-push-test-url="'.route('notifications.device.test').'"', $html);
        $this->assertMatchesRegularExpression('/<details data-push-diag-panel[^>]*\sopen(?=[\s>])/', $html);
        $this->assertStringContainsString('data-push-diag-local', $html);
        $this->assertStringContainsString('data-push-diag-simuler', $html);
    }

    public function test_la_console_existe_en_forme_Organization(): void
    {
        $params = ['organization' => $this->org->slug];

        $html = $this->actingAs($this->alice)->get(route('organization.pwa.diagnostic', $params))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-verification-url="'.route('organization.pwa.diagnostic.partage', $params).'"', $html);
        $this->assertStringContainsString(route('organization.notifications.device.test', $params), $html);
        $this->assertStringContainsString(route('organization.notifications.device.edit', $params), $html);

        $this->actingAs($this->alice)->getJson(route('organization.pwa.diagnostic.partage', $params))->assertOk();
    }

    public function test_un_invite_ne_voit_ni_la_console_ni_la_verification(): void
    {
        $this->get(route('pwa.diagnostic'))->assertRedirect(route('login'));
        $this->getJson(route('pwa.diagnostic.partage'))->assertUnauthorized();
    }

    public function test_aucun_secret_dans_la_console(): void
    {
        $html = $this->actingAs($this->alice)->get(route('pwa.diagnostic'))->assertOk()->getContent();

        $this->assertStringNotContainsString('SECRET-PRIVE-NE-DOIT-JAMAIS-SORTIR', $html);
    }

    // ── La verification du partage ──────────────────────────────────────────

    public function test_la_verification_confirme_la_declaration_le_parcours_et_l_analyse(): void
    {
        $this->actingAs($this->alice)->getJson(route('pwa.diagnostic.partage'))
            ->assertOk()
            ->assertExactJson([
                'manifeste_serveur' => ['action' => true, 'method' => true, 'params' => true],
                'route_reception' => true,
                'route_choix' => true,
                'analyse_synthetique' => true,
                'intention_en_attente' => false,
            ]);
    }

    public function test_un_partage_reel_en_attente_survit_intact_a_la_verification(): void
    {
        $intention = [
            'url' => 'https://exemple.test/article-prive',
            'titre' => 'Un article',
            'recueA' => now()->timestamp,
            'jeton' => str_repeat('a', 32),
        ];

        $reponse = $this->actingAs($this->alice)
            ->withSession([ShareIntent::CLE_SESSION => $intention])
            ->getJson(route('pwa.diagnostic.partage'))
            ->assertOk()
            ->assertJsonPath('intention_en_attente', true);

        $reponse->assertSessionHas(ShareIntent::CLE_SESSION, $intention);

        // L'URL d'un membre ne sort jamais, ni celle d'exemple analysee.
        $this->assertStringNotContainsString('article-prive', $reponse->getContent());
        $this->assertStringNotContainsString('example.com', $reponse->getContent());
    }

    public function test_sans_partage_en_attente_la_verification_n_en_cree_aucun(): void
    {
        $this->actingAs($this->alice)->getJson(route('pwa.diagnostic.partage'))
            ->assertOk()
            ->assertSessionMissing(ShareIntent::CLE_SESSION);
    }

    public function test_la_version_du_service_worker_servi_est_lue_dans_sw_js(): void
    {
        preg_match("/const SW_VERSION = '([^']+)'/", file_get_contents(public_path('sw.js')), $m);

        $this->assertNotEmpty($m[1] ?? null);
        $this->assertSame($m[1], PwaDiagnosticController::versionDuServiceWorkerServi());
    }

    // ── L'acces ──────────────────────────────────────────────────────────────

    public function test_on_y_arrive_depuis_la_page_appareil_et_pas_depuis_les_preferences(): void
    {
        $this->actingAs($this->alice);

        $appareil = $this->get(route('notifications.device.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('data-pwa-diagnostic-link', $appareil);
        $this->assertStringContainsString('href="'.route('pwa.diagnostic').'"', $appareil);

        // Aucune entree de menu : la page des preferences (layout complet,
        // navigation comprise) n'y mene pas.
        $preferences = $this->get(route('notifications.preferences.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('pwa.diagnostic'), $preferences);
    }
}
