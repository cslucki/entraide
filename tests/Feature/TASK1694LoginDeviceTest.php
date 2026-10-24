<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Devices\DeviceKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AK — smartphone ou ordinateur, dans le journal de connexions.
 *
 * **Demande de Cyril** : « savoir qui se connecte avec un smartphone, qui se
 * connecte avec son ordinateur. Donc ajouter une colonne, et un menu de
 * filtre. »
 *
 * ## Rien à capturer
 *
 * `login_logs.user_agent` est stocké depuis toujours — 404 lignes au 24/10,
 * **aucune vide**. La catégorie est donc dérivée à la lecture, sans migration,
 * et s'applique **rétroactivement** à tout l'historique.
 *
 * ## Quatre catégories, parce que les données en disent quatre
 *
 * Relevé du 24/10 : 326 ordinateurs, 68 téléphones, et **10 connexions qui ne
 * sont ni l'un ni l'autre** — 6 `curl`, 4 `Symfony`. Les ranger dans
 * « ordinateur » serait faux, et c'est exactement le genre de ligne qu'on veut
 * pouvoir isoler dans un journal de connexions.
 */
class TASK1694LoginDeviceTest extends TestCase
{
    use RefreshDatabase;

    /** Des chaînes RÉELLES, relevées dans la base le 24/10. */
    private const AGENTS = [
        'desktop_windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
        'desktop_linux' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
        'phone_android' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36',
        'phone_samsung' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/143.0.0.0 Mobile Safari/537.36',
        'phone_iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        'tablet_ipad' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        'tablet_android' => 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
        'other_curl' => 'curl/7.81.0',
        'other_symfony' => 'Symfony',
    ];

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1694ak', 'is_active' => true]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        LoginLog::query()->delete();
    }

    private function journaliser(string $userAgent): LoginLog
    {
        return LoginLog::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->superAdmin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => $userAgent,
        ]);
    }

    private function html(array $query = []): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.stats.login-history', $query))
            ->assertOk()
            ->getContent();
    }

    /**
     * La classification, sur des chaînes RÉELLES.
     *
     * Et deux pièges que la mesure a corrigés en cours de route :
     *
     * - `SamsungBrowser` **contient** `Mobile Safari` — je l'avais cru tablette
     *   en lisant une sortie tronquée à 105 caractères. C'est un téléphone ;
     * - une chaîne **vide** rend « autre », pas « ordinateur » : ne pas savoir
     *   n'est pas savoir, et un journal qui confond les deux ment sur ses
     *   propres lacunes.
     */
    public function test_real_user_agents_are_classified(): void
    {
        $attendu = [
            'desktop_windows' => DeviceKind::Desktop,
            'desktop_linux' => DeviceKind::Desktop,
            'phone_android' => DeviceKind::Phone,
            'phone_samsung' => DeviceKind::Phone,
            'phone_iphone' => DeviceKind::Phone,
            'tablet_ipad' => DeviceKind::Tablet,
            'tablet_android' => DeviceKind::Tablet,
            'other_curl' => DeviceKind::Other,
            'other_symfony' => DeviceKind::Other,
        ];

        foreach ($attendu as $clef => $categorie) {
            $this->assertSame(
                $categorie,
                DeviceKind::fromUserAgent(self::AGENTS[$clef]),
                "mauvaise categorie pour « {$clef} »",
            );
        }

        $this->assertSame(DeviceKind::Other, DeviceKind::fromUserAgent(''));
        $this->assertSame(DeviceKind::Other, DeviceKind::fromUserAgent(null));
    }

    /**
     * Un outil qui annonce « Windows » reste un OUTIL.
     *
     * Les signatures d'outil sont examinées en premier, et c'est la raison :
     * un robot peut très bien porter « Windows NT » dans sa chaîne, et serait
     * alors compté comme un ordinateur.
     */
    public function test_a_tool_claiming_windows_is_still_a_tool(): void
    {
        $this->assertSame(
            DeviceKind::Other,
            DeviceKind::fromUserAgent('Mozilla/5.0 (Windows NT 10.0) GoogleBot/2.1'),
        );
    }

    /** LE geste demandé, moitié 1 : la colonne. */
    public function test_the_column_shows_the_device(): void
    {
        $this->journaliser(self::AGENTS['desktop_windows']);
        $this->journaliser(self::AGENTS['phone_iphone']);

        $html = $this->html();

        $this->assertStringContainsString('data-login-device="desktop"', $html);
        $this->assertStringContainsString('data-login-device="phone"', $html);
    }

    /** LE geste demandé, moitié 2 : le menu de filtre. */
    public function test_the_filter_menu_is_offered(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-login-device-filter', $html);

        foreach (DeviceKind::cases() as $categorie) {
            $this->assertStringContainsString('value="'.$categorie->value.'"', $html);
        }
    }

    /**
     * LE test qui compte : le filtre rend EXACTEMENT ce que la colonne annonce.
     *
     * C'est la garantie que l'approche visait. Traduire la classification en
     * `LIKE` aurait donné deux règles pour une seule idée — et c'est le filtre
     * qui aurait eu tort, **en silence**. Ici, les deux exécutent la même
     * fonction.
     *
     * Le test le vérifie catégorie par catégorie, sur les neuf chaînes réelles :
     * chaque ligne rendue doit porter la catégorie demandée, et **aucune autre**
     * ne doit manquer.
     */
    public function test_the_filter_agrees_with_the_column_for_every_category(): void
    {
        $attendus = [];

        foreach (self::AGENTS as $agent) {
            $this->journaliser($agent);
            $attendus[DeviceKind::fromUserAgent($agent)->value][] = $agent;
        }

        foreach (DeviceKind::cases() as $categorie) {
            $html = $this->html(['device' => $categorie->value]);

            $compte = substr_count($html, 'data-login-device="');

            $this->assertSame(
                count($attendus[$categorie->value] ?? []),
                $compte,
                "le filtre « {$categorie->value} » ne rend pas le meme nombre de lignes que la colonne n'en classe",
            );

            foreach (DeviceKind::cases() as $autre) {
                if ($autre === $categorie) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    'data-login-device="'.$autre->value.'"',
                    $html,
                    "le filtre « {$categorie->value} » laisse passer du « {$autre->value} »",
                );
            }
        }
    }

    /** Une ligne sans agent appartient à « autre », et n'est perdue par aucun filtre. */
    public function test_a_row_without_user_agent_belongs_to_other(): void
    {
        LoginLog::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->superAdmin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
        ]);

        $this->assertStringContainsString(
            'data-login-device="other"',
            $this->html(['device' => 'other']),
        );
    }

    /**
     * Une valeur de filtre inconnue est IGNORÉE, pas refusée.
     *
     * Un journal qu'on consulte ne doit pas rendre une erreur parce qu'un
     * paramètre d'URL a été mal recopié.
     */
    public function test_an_unknown_device_value_is_ignored(): void
    {
        $this->journaliser(self::AGENTS['desktop_windows']);

        $this->assertStringContainsString(
            'data-login-device="desktop"',
            $this->html(['device' => 'montre-connectee']),
        );
    }

    /** Et le filtre se combine avec les autres. */
    public function test_the_device_filter_combines_with_the_search(): void
    {
        $autre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'ZZZ_INTROUVABLE',
        ]);

        $this->journaliser(self::AGENTS['phone_iphone']);

        LoginLog::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $autre->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => self::AGENTS['phone_android'],
        ]);

        $html = $this->html(['device' => 'phone', 'search' => 'ZZZ_INTROUVABLE']);

        $this->assertSame(1, substr_count($html, 'data-login-device="'));
    }
}
