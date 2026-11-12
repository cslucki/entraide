<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Devices\DeviceKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1812, correctif 10 — des compteurs par type d'appareil sur
 * `/admin/stats/login-history`.
 *
 * Demande de Cyril : « faire des compteurs pour voir les stats par type de
 * navigateur (nbre et %), desktop, smartphone android, smartphone iOS,
 * tablette, etc. »
 *
 * Le filtre d'appareil (TASK-1694) ne distinguait que quatre categories —
 * Phone/Tablet/Desktop/Other — sans jamais separer Android et iOS, parce que
 * le filtre n'en avait pas besoin. `DeviceKind::statKey()` affine cette seule
 * categorie pour les statistiques, sans toucher au filtre existant.
 */
class TASK1812Correctif10DeviceStatsLoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** Memes chaines REELLES que TASK1694LoginDeviceTest, deja vetees. */
    private const AGENTS = [
        'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
        'phone_android' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36',
        'phone_ios' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        'tablet' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        'other' => 'curl/7.81.0',
    ];

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1812-10', 'is_active' => true]);

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

    // ── La classification elle-meme ─────────────────────────────────────────

    public function test_statkey_distingue_android_et_ios_la_ou_le_filtre_ne_le_fait_pas(): void
    {
        $this->assertSame('phone_android', DeviceKind::statKey(self::AGENTS['phone_android']));
        $this->assertSame('phone_ios', DeviceKind::statKey(self::AGENTS['phone_ios']));
        $this->assertSame('desktop', DeviceKind::statKey(self::AGENTS['desktop']));
        $this->assertSame('tablet', DeviceKind::statKey(self::AGENTS['tablet']));
        $this->assertSame('other', DeviceKind::statKey(self::AGENTS['other']));

        // Le filtre, lui, continue de ranger Android et iOS dans LA MEME
        // categorie : ce correctif n'y touche pas.
        $this->assertSame(
            DeviceKind::Phone,
            DeviceKind::fromUserAgent(self::AGENTS['phone_android'])
        );
        $this->assertSame(
            DeviceKind::Phone,
            DeviceKind::fromUserAgent(self::AGENTS['phone_ios'])
        );
    }

    public function test_un_telephone_ni_android_ni_ios_tombe_dans_autre(): void
    {
        $this->assertSame('other', DeviceKind::statKey('Mozilla/5.0 (Windows Phone 10.0; Mobile)'));
    }

    // ── L'ecran ──────────────────────────────────────────────────────────────

    public function test_les_compteurs_comptent_et_pourcentent_correctement(): void
    {
        $this->journaliser(self::AGENTS['desktop']);
        $this->journaliser(self::AGENTS['desktop']);
        $this->journaliser(self::AGENTS['desktop']);
        $this->journaliser(self::AGENTS['phone_android']);
        $this->journaliser(self::AGENTS['phone_ios']);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.stats.login-history'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-login-device-stat="desktop"', $html);
        $this->assertStringContainsString('data-login-device-stat="phone_android"', $html);
        $this->assertStringContainsString('data-login-device-stat="phone_ios"', $html);

        // 3 sur 5 -> 60,0 %, affiche avec une virgule (locale FR).
        $this->assertStringContainsString('60,0', $html);
        // 1 sur 5 -> 20,0 % (deux fois, Android et iOS).
        $this->assertStringContainsString('20,0', $html);
    }

    /** 🔴 Les compteurs ne doivent JAMAIS se figer a 100 % sur la categorie choisie. */
    public function test_les_compteurs_ignorent_le_filtre_d_appareil_lui_meme(): void
    {
        $this->journaliser(self::AGENTS['desktop']);
        $this->journaliser(self::AGENTS['desktop']);
        $this->journaliser(self::AGENTS['phone_android']);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.stats.login-history', ['device' => 'phone']))
            ->assertOk()
            ->getContent();

        // La LISTE, elle, est bien filtree a un seul telephone...
        $this->assertStringContainsString(__('admin.device_phone'), $html);

        // ...mais les compteurs continuent de montrer les TROIS connexions :
        // 2 ordinateurs (66,7 %) et 1 telephone Android (33,3 %), jamais
        // "1 sur 1 -> 100 %".
        $this->assertStringContainsString('66,7', $html);
        $this->assertStringContainsString('33,3', $html);
        $this->assertStringNotContainsString('100,0', $html);
    }

    public function test_un_journal_vide_n_affiche_aucune_section_de_compteurs(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.stats.login-history'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('admin.device_stats_title'), $html);
    }

    /** Les compteurs respectent la recherche/Organisation deja en place. */
    public function test_les_compteurs_respectent_le_filtre_d_organization(): void
    {
        $autreOrganization = Organization::factory()->create(['is_active' => true]);
        $autreUtilisateur = User::factory()->create(['organization_id' => $autreOrganization->id]);

        $this->journaliser(self::AGENTS['desktop']);

        LoginLog::query()->create([
            'organization_id' => $autreOrganization->id,
            'user_id' => $autreUtilisateur->id,
            'ip_address' => '127.0.0.2',
            'user_agent' => self::AGENTS['phone_android'],
        ]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.stats.login-history', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-login-device-stat="desktop"', $html);
        // Un seul login, pour cette Organization : 100 %.
        $this->assertStringContainsString('100,0', $html);
    }
}
