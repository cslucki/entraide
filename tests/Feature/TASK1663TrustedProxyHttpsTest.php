<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * TASK-1663 — HTTPS derriere un reverse proxy de confiance.
 *
 * Laravel ne croit `X-Forwarded-Proto` que si la requete vient d'un proxy
 * declare de confiance. Sans cela, une requete HTTPS terminee par un proxy est
 * vue comme `http` : les URL absolues sortent en `http` sur une page servie en
 * `https`, et le navigateur bloque le contenu actif. Mesure avant correctif sur
 * la page `/org/main` d'un banc expose par un tunnel : **6 references `http://`**
 * (1 feuille de style, 4 actions de formulaire, 1 image).
 *
 * Le piege de cette correction n'est pas celui qu'on croit. `TrustProxies` porte
 * une AUTO-DETECTION :
 *
 *     $trustedIps = $this->proxies() ?: config('trustedproxy.proxies');
 *     if (is_null($trustedIps) && (laravel_cloud() || …on-forge… || …on-vapor…)) {
 *         $trustedIps = '*';
 *     }
 *
 * La production, sur Laravel Cloud, passe DEJA par ce chemin : c'est pourquoi
 * elle rend ses URL en `https` sans aucune configuration. Renseigner `$proxies`
 * explicitement partout **ecraserait** cette auto-detection et pourrait degrader
 * la PROD. D'ou la forme retenue : une clef de configuration laissee a `null`
 * par defaut, renseignee seulement la ou l'auto-detection ne s'applique pas.
 *
 * Le test G est celui qui protege la PROD d'une configuration trop zelee.
 */
class TASK1663TrustedProxyHttpsTest extends TestCase
{
    /** @var array{0: array|string|null, 1: int} */
    private array $etatInitial;

    protected function setUp(): void
    {
        parent::setUp();

        // `setTrustedProxies()` est un etat STATIQUE de Symfony : sans
        // restauration, un test contaminerait tous les suivants.
        $this->etatInitial = [SymfonyRequest::getTrustedProxies(), SymfonyRequest::getTrustedHeaderSet()];
    }

    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedProxies($this->etatInitial[0] ?? [], $this->etatInitial[1]);
        TrustProxies::flushState();

        parent::tearDown();
    }

    /** Fait passer une requete par le VRAI middleware, comme en production. */
    private function parLeMiddleware(array $server, ?string $url = null): Request
    {
        $requete = Request::create($url ?? 'http://bouclepro.test/org/main', 'GET', [], [], [], $server);

        (new TrustProxies())->handle($requete, fn (Request $r) => $r);

        return $requete;
    }

    private const PROXY_LOCAL = ['REMOTE_ADDR' => '127.0.0.1'];

    // ── A. Requete HTTP directe, sans proxy ────────────────────────────────

    public function test_a_direct_http_request_without_any_proxy_stays_http(): void
    {
        config(['trustedproxy.proxies' => null]);

        $r = $this->parLeMiddleware(self::PROXY_LOCAL, 'http://127.0.0.1:8097/org/main');

        $this->assertSame('http', $r->getScheme());
        $this->assertFalse($r->isSecure());
        $this->assertSame('http://127.0.0.1:8097', $r->getSchemeAndHttpHost());
    }

    // ── B. Derriere un proxy de confiance, avec l'en-tete ──────────────────

    public function test_b_a_trusted_proxy_announcing_https_makes_the_request_secure(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $r = $this->parLeMiddleware(self::PROXY_LOCAL + [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '37.167.126.41',
            'HTTP_HOST' => 'tunnel.example.test',
        ], 'http://tunnel.example.test/org/main');

        $this->assertSame('https', $r->getScheme());
        $this->assertTrue($r->isSecure());
        $this->assertSame('https://tunnel.example.test', $r->getSchemeAndHttpHost());
    }

    // ── C. Proxy de confiance, mais AUCUN en-tete de schema ────────────────

    public function test_c_a_trusted_proxy_without_the_proto_header_does_not_invent_https(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $r = $this->parLeMiddleware(self::PROXY_LOCAL, 'http://127.0.0.1:8097/org/main');

        $this->assertSame('http', $r->getScheme(), 'faire confiance a un proxy ne doit pas suffire a fabriquer du HTTPS');
        $this->assertFalse($r->isSecure());
    }

    // ── D. En-tete present, proxy NON de confiance ─────────────────────────

    public function test_d_the_proto_header_is_ignored_when_no_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => null]);

        $r = $this->parLeMiddleware(self::PROXY_LOCAL + [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'tunnel.example.test',
        ], 'http://tunnel.example.test/org/main');

        $this->assertSame('http', $r->getScheme(), 'un en-tete client ne doit rien prouver sans proxy de confiance');
        $this->assertFalse($r->isSecure());
        $this->assertSame([], SymfonyRequest::getTrustedProxies(), 'aucun proxy ne doit avoir ete declare');
    }

    // ── E + F. Ce que la clef de configuration produit ─────────────────────

    public function test_e_a_null_configuration_trusts_no_proxy_at_all(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->parLeMiddleware(self::PROXY_LOCAL);

        $this->assertSame([], SymfonyRequest::getTrustedProxies());
    }

    public function test_f_the_star_value_trusts_only_the_calling_ip(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->parLeMiddleware(['REMOTE_ADDR' => '10.1.2.3']);

        $this->assertSame(['10.1.2.3'], SymfonyRequest::getTrustedProxies(),
            '`*` ne fait pas confiance a tout le monde : il fait confiance a l\'IP APPELANTE, et a elle seule');
    }

    // ── G. La garde PROD ───────────────────────────────────────────────────

    /**
     * Sans variable d'environnement, la clef doit valoir `null` — et c'est
     * exactement ce qui laisse l'auto-detection Laravel Cloud operer. Une valeur
     * explicite, meme bien intentionnee, la court-circuiterait.
     */
    public function test_g_the_shipped_configuration_defaults_to_null_so_laravel_cloud_detection_survives(): void
    {
        $this->assertNull(env('TRUSTED_PROXIES'), 'aucun environnement de test ne doit definir cette variable');

        $fichier = require base_path('config/trustedproxy.php');
        $this->assertArrayHasKey('proxies', $fichier);
        $this->assertNull($fichier['proxies'], 'le defaut livre doit rester null');
    }

    public function test_g_bis_on_laravel_cloud_the_proxy_is_trusted_without_any_configuration(): void
    {
        config(['trustedproxy.proxies' => null]);

        $avant = $_ENV['LARAVEL_CLOUD'] ?? null;
        $_ENV['LARAVEL_CLOUD'] = '1';

        try {
            $r = $this->parLeMiddleware(self::PROXY_LOCAL + [
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_HOST' => 'bouclepro.com',
            ], 'http://bouclepro.com/org/main');

            $this->assertTrue($r->isSecure(), 'c\'est ce chemin, et lui seul, qui tient la PROD aujourd\'hui');
            $this->assertSame('https://bouclepro.com', $r->getSchemeAndHttpHost());
        } finally {
            if ($avant === null) {
                unset($_ENV['LARAVEL_CLOUD']);
            } else {
                $_ENV['LARAVEL_CLOUD'] = $avant;
            }
        }
    }
}
