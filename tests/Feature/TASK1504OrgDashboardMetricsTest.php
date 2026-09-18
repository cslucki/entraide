<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\LoginLog;
use App\Models\LoopInvitation;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Admin\OrganizationDashboardMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1504 — le tableau de bord d'une Organization dit quoi faire, et chaque
 * chiffre a une fenetre precise. Fixtures DATEES : une connexion d'il y a 8
 * jours ne compte pas dans « 7 jours », une d'il y a 40 jours pas dans
 * « 30 jours » ; une demande fermee n'est pas « a traiter » ; une invitation
 * expiree non plus. C'est exactement ce qu'un sabotage de borne ferait rougir.
 */
class TASK1504OrgDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 14:00:00'));
        $this->org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-t1504']);
        $this->orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $this->orgAdmin->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function login(User $user, string $when): void
    {
        DB::table('login_logs')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->org->id, 'user_id' => $user->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'created_at' => CarbonImmutable::parse($when),
        ]);
    }

    public function test_logins_respect_their_windows_and_the_top_list_is_ordered(): void
    {
        $a = User::factory()->create(['organization_id' => $this->org->id]);
        $b = User::factory()->create(['organization_id' => $this->org->id]);
        $this->login($a, '2026-09-15 09:00:00');   // aujourd'hui
        $this->login($a, '2026-09-12 09:00:00');   // 3 jours
        $this->login($a, '2026-09-08 02:00:00');   // 7 jours et demi : hors semaine, dans le mois — une borne a 8 jours le reprendrait
        $this->login($a, '2026-09-07 09:00:00');   // 8 jours : hors semaine, dans le mois
        $this->login($b, '2026-09-14 09:00:00');   // hier
        $this->login($b, '2026-08-01 09:00:00');   // 45 jours : hors mois
        $this->login($this->orgAdmin, '2026-09-15 08:00:00');

        $m = app(OrganizationDashboardMetrics::class)->for($this->org->fresh());

        $this->assertSame(['today' => 2, 'week' => 4, 'month' => 6], $m['logins']);
        // b et l'admin ont une connexion chacun sur 30 jours : l'admin passe devant
        // parce que sa derniere connexion est la plus recente (15/09 08h vs 14/09).
        $this->assertSame([$a->id, $this->orgAdmin->id, $b->id], $m['top_logins']->pluck('user.id')->all(), 'classement par nombre, puis par derniere connexion');
        $this->assertSame(4, $m['top_logins'][0]['logins']);
    }

    public function test_attention_counts_only_what_still_needs_a_gesture(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        ServiceRequest::factory()->create(['organization_id' => $this->org->id, 'user_id' => $member->id, 'status' => 'open']);
        ServiceRequest::factory()->create(['organization_id' => $this->org->id, 'user_id' => $member->id, 'status' => 'closed']);
        $loop = \App\Models\Loop::factory()->create(['organization_id' => $this->org->id, 'created_by' => $member->id]);
        foreach ([['pending', '2026-09-30'], ['pending', '2026-09-01'], ['accepted', '2026-09-30']] as [$status, $expires]) {
            LoopInvitation::create([
                'organization_id' => $this->org->id, 'loop_id' => $loop->id, 'sender_id' => $member->id,
                'recipient_email' => Str::random(6).'@example.test', 'token' => Str::random(32), 'invitation_type' => 'email',
                'status' => $status, 'expires_at' => CarbonImmutable::parse($expires),
            ]);
        }

        $m = app(OrganizationDashboardMetrics::class)->for($this->org->fresh());

        $this->assertSame(1, $m['attention']['open_requests'], 'une demande fermee n\'est pas a traiter');
        $this->assertSame(1, $m['attention']['pending_invitations'], 'une invitation expiree ou acceptee n\'attend rien');
        $this->assertSame(0, $m['attention']['pending_reports']);
        $this->assertSame(2, $m['attention']['total']);
    }

    public function test_ai_spend_and_interactions_are_bounded_to_the_current_month(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        // La depense de l'organisation = celle de la page Consommation IA : `ai_interactions.cost_usd`
        // quand `cost_unknown = false`. Septembre : 0,25 + 0,50 ; aout : 9,99 (hors fenetre) ; une
        // interaction de septembre au cout inconnu compte dans les interactions, pas dans la depense.
        foreach ([['2026-09-02 10:00:00', 0.25, false], ['2026-09-10 10:00:00', 0.50, false], ['2026-09-12 10:00:00', 0.0, true], ['2026-08-20 10:00:00', 9.99, false]] as [$when, $cost, $unknown]) {
            $ia = AiInteraction::create([
                'user_id' => $member->id, 'organization_id' => $this->org->id, 'feature' => 'blog_generate',
                'model' => 'test/model', 'prompt' => 'p', 'response' => 'r', 'input_tokens' => 1, 'output_tokens' => 1,
                'cost_usd' => $cost, 'cost_unknown' => $unknown,
            ]);
            $ia->forceFill(['created_at' => CarbonImmutable::parse($when)])->saveQuietly();
        }

        $m = app(OrganizationDashboardMetrics::class)->for($this->org->fresh());

        $this->assertEqualsWithDelta(0.75, $m['ai']['spend_usd'], 0.0001, 'la depense d\'aout ne compte pas dans septembre');
        $this->assertSame(1, $m['ai']['spend_unknown']);
        $this->assertSame(3, $m['ai']['interactions'], 'trois interactions en septembre, la quatrieme est d\'aout');
    }

    public function test_no_measurement_is_not_zero(): void
    {
        $m = app(OrganizationDashboardMetrics::class)->for($this->org->fresh());
        $this->assertNull($m['ai']['spend_usd'], '« aucune mesure » traverse en NULL, jamais en 0,00');

        $html = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.dashboard', ['organization' => $this->org->slug]))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-dashboard-tile="ai_spend"[^>]*>\s*<p[^>]*>—</', $html, 'sans mesure, la tuile affiche un tiret, pas 0,00');
    }

    public function test_the_page_links_every_tile_and_hides_the_attention_strip_when_empty(): void
    {
        $html = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.dashboard', ['organization' => $this->org->slug]))
            ->assertOk()->getContent();

        foreach (['users', 'loops', 'services', 'requests', 'ai_spend', 'ai_interactions', 'shell_visitors', 'logins'] as $key) {
            $this->assertMatchesRegularExpression('/<a href="[^"]+"[^>]*data-dashboard-tile="'.$key.'"/', $html, "la tuile $key n'est pas un lien");
        }
        $this->assertStringContainsString('data-dashboard-attention-none', $html, 'rien a traiter : le bandeau cede la place a un etat vide');
        $this->assertStringNotContainsString('data-dashboard-attention-total', $html);
        $this->assertStringContainsString('data-dashboard-top-logins', $html);
        $this->assertStringContainsString('data-dashboard-activity', $html);
        $this->assertStringContainsString('data-dashboard-actions', $html);
    }

    public function test_the_attention_strip_shows_only_non_zero_items_with_their_links(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        ServiceRequest::factory()->create(['organization_id' => $this->org->id, 'user_id' => $member->id, 'status' => 'open']);

        $html = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.dashboard', ['organization' => $this->org->slug]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-dashboard-attention-total="1"', $html);
        $this->assertMatchesRegularExpression('/data-dashboard-attention-item="open_requests" data-count="1"/', $html);
        $this->assertStringNotContainsString('data-dashboard-attention-item="pending_invitations"', $html, 'un compteur a zero n\'est pas affiche');
        $this->assertStringContainsString('status=open', $html, 'la tuile envoie vers les demandes OUVERTES');
    }
}
