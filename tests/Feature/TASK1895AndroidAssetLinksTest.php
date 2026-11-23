<?php

namespace Tests\Feature;

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1895 — `/.well-known/assetlinks.json` pour l'application Android TWA.
 *
 * Les empreintes ci-dessous sont des FIXTURES synthetiques (un octet repete),
 * jamais celles d'un vrai certificat.
 */
class TASK1895AndroidAssetLinksTest extends TestCase
{
    private const URL = '/.well-known/assetlinks.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'android.twa.package' => 'com.bouclepro.app',
            'android.twa.sha256_cert_fingerprints' => null,
        ]);
    }

    private static function fixture(string $octet): string
    {
        return implode(':', array_fill(0, 32, $octet));
    }

    public function test_without_fingerprints_the_route_serves_an_empty_json_list(): void
    {
        $response = $this->get(self::URL);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame('[]', $response->getContent());
    }

    public function test_configured_fingerprints_produce_the_exact_digital_asset_links_statement(): void
    {
        config(['android.twa.sha256_cert_fingerprints' => self::fixture('AA').','.self::fixture('BB')]);

        $this->get(self::URL)
            ->assertOk()
            ->assertExactJson([[
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => 'com.bouclepro.app',
                    'sha256_cert_fingerprints' => [self::fixture('AA'), self::fixture('BB')],
                ],
            ]]);
    }

    public function test_fingerprints_are_normalized_deduplicated_and_invalid_values_are_dropped(): void
    {
        config(['android.twa.sha256_cert_fingerprints' => ' '.strtolower(self::fixture('CD')).' , '
            .self::fixture('CD').', pas-une-empreinte, '.self::fixture('ZZ').', AA:BB']);

        $json = $this->get(self::URL)->assertOk()->json();

        $this->assertSame([self::fixture('CD')], $json[0]['target']['sha256_cert_fingerprints']);
    }

    public function test_only_invalid_fingerprints_serve_an_empty_list(): void
    {
        config(['android.twa.sha256_cert_fingerprints' => 'AA:BB, n-importe-quoi']);

        $this->assertSame('[]', $this->get(self::URL)->assertOk()->getContent());
    }

    public function test_a_malformed_package_name_serves_an_empty_list(): void
    {
        config([
            'android.twa.package' => 'pas un package',
            'android.twa.sha256_cert_fingerprints' => self::fixture('AA'),
        ]);

        $this->assertSame('[]', $this->get(self::URL)->assertOk()->getContent());
    }

    public function test_the_route_is_outside_the_web_group_no_session_no_cookie_no_redirect(): void
    {
        $route = Route::getRoutes()->getByName('android.assetlinks');

        $this->assertNotContains(StartSession::class, app('router')->gatherRouteMiddleware($route));

        $response = $this->get(self::URL);

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
    }
}
