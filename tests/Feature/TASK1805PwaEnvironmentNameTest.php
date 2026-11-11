<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * TASK-1805 — le nom PWA servi par `GET /site.webmanifest` depend de
 * l'ENVIRONNEMENT (`config('pwa.app_name_override')` via `PwaAppName`),
 * jamais du `Host` de la requete.
 *
 * Le test le plus important de ce fichier est
 * `test_a_spoofed_tunnel_host_has_no_effect_on_the_name` : il prouve qu'une
 * requete qui PRETEND venir d'un sous-domaine `trycloudflare.com` (ou de
 * `bouclepro.com`) ne change RIEN au nom rendu — exactement ce que la
 * consigne demandait de ne jamais faire reposer sur le hostname.
 */
class TASK1805PwaEnvironmentNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['pwa.app_name_override' => null]);
    }

    public function test_manifest_is_served_with_the_correct_content_type(): void
    {
        $this->get('/site.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
    }

    public function test_name_and_short_name_are_identical_and_match_the_environment(): void
    {
        $manifest = $this->get('/site.webmanifest')->json();

        // L'environnement reel de la suite de tests est `testing`, jamais
        // `local` : le nom attendu est donc celui de PRODUCTION.
        $this->assertSame('BouclePro', $manifest['name']);
        $this->assertSame($manifest['name'], $manifest['short_name']);
    }

    public function test_an_explicit_override_is_reflected_in_the_served_manifest(): void
    {
        config(['pwa.app_name_override' => 'BouclePro STAGING']);

        $manifest = $this->get('/site.webmanifest')->json();

        $this->assertSame('BouclePro STAGING', $manifest['name']);
        $this->assertSame('BouclePro STAGING', $manifest['short_name']);
    }

    /**
     * LE test de cette TASK. Un `Host` fabrique — qu'il imite un tunnel
     * temporaire ou le domaine de production — ne doit avoir AUCUN effet :
     * la decision vient du serveur (`app()->environment()`), jamais d'une
     * donnee que le client controle.
     */
    public function test_a_spoofed_tunnel_host_has_no_effect_on_the_name(): void
    {
        $reference = $this->get('/site.webmanifest')->json();

        foreach ([
            'abc123def.trycloudflare.com',
            'bouclepro.com',
            'anything-attacker-controlled.example.test',
        ] as $host) {
            $manifest = $this
                ->withHeaders(['Host' => $host])
                ->get('/site.webmanifest')
                ->json();

            $this->assertSame(
                $reference['name'],
                $manifest['name'],
                "Le Host [{$host}] a change le nom rendu — la decision ne doit JAMAIS dependre du hostname."
            );
        }
    }

    /** Tout le reste du manifest (icones, raccourcis, couleur...) reste intact. */
    public function test_every_other_manifest_field_is_unchanged(): void
    {
        $manifest = $this->get('/site.webmanifest')->json();

        $this->assertSame('/', $manifest['id']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame(config('pwa.theme_color'), $manifest['theme_color']);
        $this->assertCount(4, $manifest['icons']);
        $this->assertCount(2, $manifest['shortcuts']);
    }

    /** En production (ou tout environnement non `local`), les icones restent les PROD. */
    public function test_production_icons_point_to_the_standard_files(): void
    {
        $manifest = $this->get('/site.webmanifest')->json();

        foreach ($manifest['icons'] as $icone) {
            $this->assertStringEndsNotWith('-dev.png', $icone['src']);
            $this->assertFileExists(public_path(ltrim($icone['src'], '/')));
        }
    }

    /**
     * TASK-1805 correctif (demande Cyril) — icone MARRON en local, manifest
     * ET meta Apple, toutes deux gouvernees par `PwaAppName::isLocal()`.
     */
    public function test_local_environment_serves_the_brown_icon_set(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $manifest = $this->get('/site.webmanifest')->json();

        $this->assertSame('BoucleDev', $manifest['name']);
        $this->assertSame('BoucleDev', $manifest['short_name']);

        $this->assertNotEmpty($manifest['icons']);
        foreach ($manifest['icons'] as $icone) {
            $this->assertStringEndsWith(
                '-dev.png',
                $icone['src'],
                "L'icone [{$icone['src']}] n'est pas la variante locale (marron) attendue."
            );
            $this->assertFileExists(
                public_path(ltrim($icone['src'], '/')),
                "L'icone locale [{$icone['src']}] est declaree mais absente du disque."
            );
        }

        $html = Blade::render('<x-pwa-head />');
        $this->assertStringContainsString('content="BoucleDev"', $html);
        $this->assertStringContainsString('href="/apple-touch-icon-dev.png"', $html);
        $this->assertFileExists(public_path('apple-touch-icon-dev.png'));
    }
}
