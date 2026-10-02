<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\GuestShell\GuestPageContextResolver;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1662 — un CTA de page invite ne doit dependre ni de `APP_URL` ni du host
 * courant.
 *
 * Le defaut corrige ici etait VIVANT EN PRODUCTION : `route()` batit ses URL
 * absolues depuis la racine de la requete, tandis que la garde les comparait au
 * seul prefixe `config('app.url')`. Des que l'application etait servie sur un
 * autre host legitime — le domaine vanity Laravel Cloud, toujours actif — la
 * comparaison echouait et le constructeur du DTO jetait :
 *
 *   https://bouclepro.com/org/main                      -> 200
 *   https://entraide-main-1xztoq.laravel.cloud/org/main  -> 500
 *
 * La garde n'etait pas en tort : son CRITERE l'etait. Le correctif rend le CTA
 * relatif et enseigne a la garde la forme relative, SANS rien accepter de plus
 * cote hotes.
 */
class TASK1662GuestCtaInternalUrlTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://bouclepro.test']);

        $this->org = Organization::factory()->create([
            'slug' => 'org-1662', 'name' => 'Guilde 1662',
            'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true, 'max_messages' => 5]);
    }

    // ── A + B. Les deux formes internes acceptees ──────────────────────────

    public function test_a_relative_internal_path_is_accepted(): void
    {
        $this->assertTrue(GuestPageContext::isInternalUrl('/org/org-1662/register'));
        $this->assertTrue(GuestPageContext::isInternalUrl('/'));
    }

    public function test_an_absolute_url_under_app_url_stays_accepted(): void
    {
        $this->assertTrue(GuestPageContext::isInternalUrl('https://bouclepro.test/org/org-1662/register'));
        $this->assertTrue(GuestPageContext::isInternalUrl('https://bouclepro.test'));
    }

    // ── C + D. Ce que la garde doit TOUJOURS refuser ───────────────────────

    /**
     * Chaque entree est une tentative de faire passer un hote etranger pour
     * interne. `//evil` et `/\evil` sont les deux pieges classiques : la
     * premiere ressemble a un chemin mais designe un AUTRE HOTE en heritant du
     * schema courant ; la seconde exploite le fait que les navigateurs
     * normalisent la contre-oblique en oblique.
     */
    public static function urlsExternes(): array
    {
        return [
            'autre hote en https' => ['https://evil.example/org/org-1662/register'],
            'autre hote en http' => ['http://evil.example/org/org-1662/register'],
            'sans schema' => ['//evil.example/org/org-1662/register'],
            'sans schema, hote nu' => ['//evil.example'],
            'contre-oblique' => ['/\\evil.example/x'],
            'contre-oblique doublee' => ['/\\\\evil.example/x'],
            'schema exotique' => ['javascript:alert(1)'],
            'donnees embarquees' => ['data:text/html,<script>1</script>'],
            'prefixe trompeur' => ['https://bouclepro.test.evil.example/x'],
            'host vanity, en absolu' => ['https://entraide-main-1xztoq.laravel.cloud/org/org-1662/register'],
        ];
    }

    /**
     * La chaine vide est refusee elle aussi, mais PLUS TOT : la validation de
     * forme du DTO (« exactement un label et une url ») la rejette avant que la
     * question de l'internalite ne se pose. Elle a donc son propre test, sinon
     * l'assertion sur le message serait fausse.
     */
    public function test_an_empty_url_is_refused_by_the_shape_check_first(): void
    {
        $this->assertFalse(GuestPageContext::isInternalUrl(''));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A guest page CTA carries exactly a label and an url.');
        new GuestPageContext(
            organizationId: (string) $this->org->id,
            kind: GuestPageContext::KIND_ORGANIZATION_HOME,
            publicId: null,
            publicLabel: 'Guilde 1662',
            publicCta: ['label' => 'S\'inscrire', 'url' => ''],
            routeName: 'organization.home',
        );
    }

    #[DataProvider('urlsExternes')]
    public function test_an_external_or_ambiguous_url_is_always_refused(string $url): void
    {
        $this->assertFalse(GuestPageContext::isInternalUrl($url), "doit rester refuse : {$url}");

        // Et le DTO refuse de se construire avec lui.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A guest page CTA must point inside the platform.');
        new GuestPageContext(
            organizationId: (string) $this->org->id,
            kind: GuestPageContext::KIND_ORGANIZATION_HOME,
            publicId: null,
            publicLabel: 'Guilde 1662',
            publicCta: ['label' => 'S\'inscrire', 'url' => $url],
            routeName: 'organization.home',
        );
    }

    // ── E. Le scenario de PRODUCTION : un second host legitime ─────────────

    public function test_the_cta_is_relative_so_a_second_legitimate_host_no_longer_throws(): void
    {
        $chemin = '/org/org-1662';

        $page = app(GuestPageContextResolver::class)->fromRoute(
            $this->org,
            Route::getRoutes()->match(Request::create($chemin, 'GET'))
        );

        $this->assertNotNull($page);
        $this->assertNotNull($page->publicCta);
        $this->assertSame('/org/org-1662/register', $page->publicCta['url'], 'le CTA est un chemin, pas une URL absolue');
        $this->assertStringStartsWith('/', $page->publicCta['url']);
        $this->assertStringStartsNotWith('//', $page->publicCta['url']);

        // Le meme resolver, sur un host qui N'EST PAS `APP_URL` : c'est ce cas qui
        // rendait 500 en PROD sur le domaine vanity.
        $requete = Request::create('https://entraide-main-1xztoq.laravel.cloud'.$chemin, 'GET');
        $requete->setRouteResolver(fn () => Route::getRoutes()->match(Request::create($chemin, 'GET')));

        $surAutreHost = app(GuestPageContextResolver::class)->fromRequest($this->org, $requete);

        $this->assertNotNull($surAutreHost, 'aucune exception sur un second host legitime');
        $this->assertSame('/org/org-1662/register', $surAutreHost->publicCta['url']);
        $this->assertTrue(GuestPageContext::isInternalUrl($surAutreHost->publicCta['url']));
    }

    public function test_the_public_organization_page_answers_on_a_host_other_than_app_url(): void
    {
        $this->get('https://entraide-main-1xztoq.laravel.cloud/org/org-1662')->assertOk();
        $this->get('/org/org-1662')->assertOk();
    }

    // ── F. Sabotage : la garde est-elle ce qui refuse vraiment ? ───────────

    /**
     * Un test de refus ne prouve rien s'il reste vert quand la garde disparait.
     * Celui-ci reproduit le CRITERE HISTORIQUE — le seul prefixe `APP_URL` — et
     * verifie qu'il echouait precisement la ou le correctif reussit. Si un jour
     * la garde acceptait un hote arbitraire, la premiere assertion rougirait.
     */
    public function test_the_historical_criterion_refused_what_the_fix_accepts(): void
    {
        $critereHistorique = function (string $url): bool {
            $base = rtrim((string) config('app.url'), '/');

            return $base !== '' && ($url === $base || str_starts_with($url, $base.'/'));
        };

        // Le chemin relatif : refuse AVANT, accepte APRES. C'est tout le correctif.
        $this->assertFalse($critereHistorique('/org/org-1662/register'), 'l\'ancien critere refusait le chemin relatif');
        $this->assertTrue(GuestPageContext::isInternalUrl('/org/org-1662/register'));

        // L'hote etranger : refuse AVANT comme APRES. La protection n'a pas bouge.
        foreach (['https://evil.example/x', '//evil.example/x'] as $hostile) {
            $this->assertFalse($critereHistorique($hostile));
            $this->assertFalse(GuestPageContext::isInternalUrl($hostile), 'le correctif ne doit RIEN relacher cote hotes');
        }
    }
}
