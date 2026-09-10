<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Admin\PlatformDashboardMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1507 — le tableau de bord SuperAdmin, TOUTES Organizations confondues.
 *
 * Ce qui se mesure ici et nulle part ailleurs : que rien ne soit filtre par un
 * tenant. Deux Organizations sont peuplees dans CHAQUE fixture, et chaque
 * assertion porte sur la SOMME. Un service qui, par accident, ne verrait qu'un
 * tenant — le defaut naturel de ce code, `BelongsToOrganizationScope` etant
 * fail-closed hors requete HTTP — rendrait la moitie des chiffres, et ces
 * tests le diraient.
 *
 * Les fenetres (jour / 7 j / 30 j) sont posees sur une horloge FIGEE, avec une
 * connexion a 7,5 jours : la borne « 7 jours » ne peut pas etre elargie a 8
 * sans rougir.
 */
class TASK1507PlatformDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 14:00:00'));
        $this->orgA = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-a-1507', 'name' => 'Alpha 1507']);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-b-1507', 'name' => 'Beta 1507']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function metrics(): array
    {
        return app(PlatformDashboardMetrics::class)->get();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
    }

    private function login(User $user, Organization $organization, string $when): void
    {
        DB::table('login_logs')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'user_id' => $user->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'created_at' => CarbonImmutable::parse($when),
        ]);
    }

    private function interaction(?Organization $organization, string $when, ?float $cost, bool $unknown = false): AiInteraction
    {
        // `ai_interactions.user_id` et `feature` sont NOT NULL ;
        // `organization_id` est nullable — c'est ce qui rend les traces
        // « non rattachables » possibles.
        $user = User::factory()->create(['organization_id' => $organization?->id ?? $this->orgA->id]);

        $interaction = AiInteraction::create([
            'organization_id' => $organization?->id,
            'user_id' => $user->id,
            'feature' => 'blog_generate',
            'model' => 'test/model',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => $cost,
            'cost_unknown' => $unknown,
        ]);

        $interaction->forceFill(['created_at' => CarbonImmutable::parse($when)])->saveQuietly();

        return $interaction;
    }

    public function test_logins_are_counted_across_every_organization_and_respect_their_windows(): void
    {
        $a = User::factory()->create(['organization_id' => $this->orgA->id]);
        $b = User::factory()->create(['organization_id' => $this->orgB->id]);

        // Aujourd'hui : un dans chaque Organization.
        $this->login($a, $this->orgA, '2026-09-15 08:00:00');
        $this->login($b, $this->orgB, '2026-09-15 09:00:00');
        // Dans la fenetre 7 jours mais pas aujourd'hui.
        $this->login($a, $this->orgA, '2026-09-12 10:00:00');
        // A 7,5 jours : DEHORS. C'est la borne — elargir a 8 jours ferait
        // passer « week » a 4 et rougir ce test.
        $this->login($b, $this->orgB, '2026-09-08 02:00:00');
        // Dans les 30 jours seulement.
        $this->login($a, $this->orgA, '2026-09-01 10:00:00');
        $this->login($b, $this->orgB, '2026-08-25 10:00:00');
        // Hors fenetre.
        $this->login($b, $this->orgB, '2026-07-01 10:00:00');

        $m = $this->metrics();

        $this->assertSame(['today' => 2, 'week' => 3, 'month' => 6], $m['logins']);

        // Le classement melange les deux Organizations, et dit laquelle.
        $names = $m['top_logins']->map(fn ($r) => $r['organization']?->name)->all();
        $this->assertContains('Alpha 1507', $names);
        $this->assertContains('Beta 1507', $names);
        // Le classement porte sur 30 jours : la connexion du 1er juillet de $b
        // en est dehors. Les deux comptes sont donc a 3, et le departage se
        // fait sur la DERNIERE connexion — celle de $b est plus recente.
        $this->assertSame(3, $m['top_logins']->firstWhere('user.id', $a->id)['logins']);
        $this->assertSame(3, $m['top_logins']->firstWhere('user.id', $b->id)['logins']);
        $this->assertSame([$b->id, $a->id], $m['top_logins']->pluck('user.id')->all());
    }

    public function test_ai_spend_and_interactions_sum_every_organization(): void
    {
        $this->interaction($this->orgA, '2026-09-03 10:00:00', 0.50);
        $this->interaction($this->orgB, '2026-09-04 10:00:00', 0.25);
        $this->interaction($this->orgB, '2026-09-05 10:00:00', null, unknown: true);
        // Le mois precedent : hors fenetre.
        $this->interaction($this->orgA, '2026-08-20 10:00:00', 9.99);

        $m = $this->metrics();

        $this->assertEqualsWithDelta(0.75, $m['ai']['spend_usd'], 0.0001, 'la depense doit sommer les deux Organizations');
        $this->assertSame(1, $m['ai']['spend_unknown']);
        $this->assertSame(3, $m['ai']['interactions'], 'les interactions du mois, toutes Organizations confondues');
    }

    public function test_spend_is_null_when_nothing_was_measured_and_never_zero(): void
    {
        $m = $this->metrics();

        $this->assertNull($m['ai']['spend_usd'], 'aucune mesure n est NULL, jamais 0,00 $');
        $this->assertSame(0, $m['ai']['interactions']);
    }

    /**
     * Les traces sans Organization (`organization_id` NULL) ont coute : elles
     * comptent dans le total plateforme, mais ne peuvent etre attribuees a
     * aucun tenant — et le classement ne doit surtout pas les y ranger.
     */
    public function test_unattributable_spend_counts_in_the_total_but_never_in_the_ranking(): void
    {
        $this->interaction($this->orgA, '2026-09-03 10:00:00', 1.00);

        $this->interaction(null, '2026-09-04 10:00:00', 0.40);

        $m = $this->metrics();

        $this->assertEqualsWithDelta(1.40, $m['ai']['spend_usd'], 0.0001, 'le total compte la trace orpheline');
        $this->assertEqualsWithDelta(0.40, $m['ai']['spend_unattributed_usd'], 0.0001);
        // `?->` volontaire : si une ligne non rattachable entrait dans le
        // classement, on veut une assertion qui NOMME le defaut, pas une
        // exception sur un `null`.
        $this->assertSame(['Alpha 1507'], $m['top_organizations']->map(fn ($r) => $r['organization']?->name)->all());
        $this->assertCount(1, $m['top_organizations']);
    }

    public function test_the_top_spenders_are_ordered_and_carry_their_interaction_count(): void
    {
        $this->interaction($this->orgA, '2026-09-03 10:00:00', 0.10);
        $this->interaction($this->orgB, '2026-09-03 11:00:00', 2.00);
        $this->interaction($this->orgB, '2026-09-04 11:00:00', 1.00);

        $m = $this->metrics();

        $this->assertSame(['Beta 1507', 'Alpha 1507'], $m['top_organizations']->map(fn ($r) => $r['organization']->name)->all());
        $this->assertSame(2, $m['top_organizations']->first()['interactions']);
    }

    public function test_counts_and_attention_span_the_whole_platform(): void
    {
        User::factory()->count(2)->create(['organization_id' => $this->orgA->id]);
        User::factory()->create(['organization_id' => $this->orgB->id, 'banned_at' => CarbonImmutable::now()]);
        // Aucune fixture d'interaction ici : les comptes sont exactement ces trois.
        $inactive = Organization::factory()->create(['is_active' => false, 'slug' => 'org-c-1507']);

        $m = $this->metrics();

        $this->assertSame(3, $m['counts']['organizations']);
        $this->assertSame(2, $m['counts']['organizations_active']);
        $this->assertSame(3, $m['counts']['users'], 'les comptes des deux Organizations');
        $this->assertSame(1, $m['counts']['banned']);
        $this->assertSame(1, $m['attention']['banned_users']);
        $this->assertSame(1, $m['attention']['inactive_organizations']);
        $this->assertSame(2, $m['attention']['total']);
        $this->assertTrue($inactive->exists);
        $this->assertArrayHasKey('transactions_pending', $m['counts'], '« 0 finalise » seul cacherait les echanges en attente');
    }

    public function test_the_page_renders_every_tile_and_links_them(): void
    {
        $this->interaction($this->orgA, '2026-09-03 10:00:00', 0.50);
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        foreach (['organizations', 'users', 'services', 'transactions', 'ai_spend', 'ai_interactions', 'shell_visitors', 'logins'] as $tile) {
            $this->assertStringContainsString('data-platform-tile="'.$tile.'"', $html, "tuile {$tile} absente");
        }

        $this->assertStringContainsString('data-platform-top-logins', $html);
        $this->assertStringContainsString('data-platform-top-organizations', $html);
        $this->assertStringContainsString('href="'.route('admin.stats.login-history').'"', $html);
    }

    public function test_the_page_shows_a_dash_when_no_spend_was_measured(): void
    {
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-platform-tile="ai_spend".*?<p[^>]*>(.*?)<\/p>/s', $html, $m));
        $this->assertSame('—', trim($m[1]), 'aucune mesure se rend « — », jamais 0,00 $');
    }

    /**
     * `layouts/admin` n'emet AUCUN jeton de theme (mesure en TASK-1506) : une
     * couleur ecrite `var(--bp-primary)` y serait un no-op silencieux.
     */
    public function test_the_page_never_relies_on_theme_tokens_the_admin_layout_does_not_emit(): void
    {
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<main\b.*?<\/main>/s', $html, $m), 'contenu principal introuvable');
        $this->assertStringNotContainsString('var(--bp-', $m[0]);
    }

    /**
     * La suite tourne en anglais : un fichier de langue francais casse resterait
     * invisible (TASK-1506 l'a montre — 12 tests verts, page 500 en francais).
     */
    public function test_both_locales_carry_every_label_of_this_screen(): void
    {
        $fr = require lang_path('fr/dashboard.php');
        $en = require lang_path('en/dashboard.php');

        $keys = array_values(array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'platform_')));
        $this->assertNotEmpty($keys);

        // Les libelles francais VISIBLES portent leurs accents : « Depense »,
        // « Echanges », « A traiter » sont passes une fois en production
        // interne sans eux, et cela se voit.
        foreach (['platform_stat_ai_spend' => 'Dépense', 'platform_stat_transactions' => 'Échanges', 'platform_attention' => 'À traiter', 'platform_pending_reports' => 'récents'] as $key => $needle) {
            $this->assertStringContainsString($needle, (string) $fr[$key], "accent manquant dans {$key}");
        }

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $fr, "cle {$key} absente du francais");
            $this->assertNotSame('', trim((string) $fr[$key]), "cle {$key} vide en francais");
        }

        app()->setLocale('fr');
        $this->actingAs($this->superAdmin())->get(route('admin.dashboard'))->assertOk()
            ->assertSee(__('dashboard.platform_top_logins'));
    }
}
