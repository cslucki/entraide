<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;
use App\Models\User;
use App\Support\GuestShell\GuestShellDiagnosis;
use App\Support\GuestShell\GuestShellState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1470 — les TROIS surfaces admin disent la meme verite.
 *
 * ## Ce qui divergeait
 *
 * TASK-1468 avait branche `/admin/ai-config` sur `GuestShellDiagnosis`. Les
 * deux autres cockpits — `/admin/shell-welcome` (plateforme) et
 * `/admin/org/ai-consumption` (une Organization) — lisaient encore
 * `admin.guest_shell_state_*` + `admin.guest_shell_reason_*` : ils donnaient
 * une cause, jamais un geste, et affichaient toujours « Mal configure ».
 *
 * Un meme etat, trois vocabulaires. C'est exactement ce que la doctrine
 * « jamais de seconde autorite » interdit — ici sur la couche de presentation.
 *
 * ## Une table, deux portes
 *
 * `/admin/shell-welcome` ne recoit pas d'objet `GuestShellState` :
 * `GuestShellUsageService` agrege un statut et une liste de raisons. Plutot
 * que de recopier la table ou de faire remonter l'objet dans un service
 * d'agregation, `GuestShellDiagnosis::fromStatusAndReasons()` accepte le
 * couple brut, et `for()` y delegue. UNE table, UN repli, UNE definition de
 * « Pret ».
 *
 * ## Le nom propre
 *
 * `guest_shell_reason_platform_ceiling_unset` disait « tant que Cyril ne l'a
 * pas fixe ». Un prenom dans une chaine d'interface. Les 16 cles de cette
 * famille etant devenues orphelines, elles ont ete retirees plutot que
 * reecrites : une chaine morte qui dit quelque chose de faux est pire qu'une
 * chaine absente — elle attend d'etre reaffichee.
 */
class TASK1470AdminDiagnosisConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $superAdmin;

    private User $orgAdminA;

    protected function setUp(): void
    {
        parent::setUp();

        // A : plafond plateforme absent — l'etat reel du banc.
        $this->organizationA = $this->organizationWithShell('org-diag-a', 'Org Diag A', enabled: true);
        // B : Shell eteint par choix.
        $this->organizationB = $this->organizationWithShell('org-diag-b', 'Org Diag B', enabled: false);

        $this->superAdmin = User::factory()->complete()->create([
            'organization_id' => $this->organizationA->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        // L'admin d'une Organization, au sens de `OrgAdminMiddleware` :
        // `organization.admin_id`. Volontairement PAS `is_admin` — un
        // SuperAdmin passerait partout et le test de cloisonnement ne
        // prouverait rien.
        $this->orgAdminA = User::factory()->complete()->create([
            'organization_id' => $this->organizationA->id,
            'preferred_locale' => 'fr',
        ]);
        $this->organizationA->forceFill(['admin_id' => $this->orgAdminA->id])->saveQuietly();
        $this->assertFalse((bool) $this->orgAdminA->is_admin, 'pre-requis : l\'admin d\'Organization n\'est pas SuperAdmin');

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => null]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Une table, deux portes — et le meme verdict
    // =====================================================================

    /**
     * `for()` et `fromStatusAndReasons()` ne peuvent pas diverger : le premier
     * delegue au second. Ce test l'exerce sur TOUS les codes plutot que de le
     * croire sur parole.
     */
    public function test_both_entry_points_agree_on_every_reason_code(): void
    {
        $cases = array_keys(GuestShellDiagnosis::REASON_MAP);
        $cases[] = 'une_raison_de_demain';

        foreach ($cases as $reason) {
            $state = $this->stateWith(GuestShellState::MISCONFIGURED, [$reason]);

            $this->assertSame(
                GuestShellDiagnosis::for($state),
                GuestShellDiagnosis::fromStatusAndReasons(GuestShellState::MISCONFIGURED, [$reason]),
                'les deux portes divergent sur ['.$reason.']',
            );
        }

        $this->assertSame(
            GuestShellDiagnosis::for($this->stateWith(GuestShellState::ACTIVE, [])),
            GuestShellDiagnosis::fromStatusAndReasons(GuestShellState::ACTIVE, []),
        );
    }

    // =====================================================================
    // B. Les trois surfaces, le meme diagnostic
    // =====================================================================

    /**
     * Le cas qui a motive la tranche : `platform_ceiling_unset`. Les trois
     * ecrans doivent nommer la MEME cle de diagnostic et proposer le MEME
     * geste — pas trois formulations d'une meme panne.
     */
    public function test_the_three_admin_surfaces_show_the_same_diagnosis_for_the_same_state(): void
    {
        $expected = GuestShellDiagnosis::fromStatusAndReasons(GuestShellState::MISCONFIGURED, ['platform_ceiling_unset']);

        $htmls = [
            'ai-config' => $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent(),
            'shell-welcome' => $this->actingAs($this->superAdmin)->get(route('admin.guest-shell'))->assertOk()->getContent(),
            'org-consumption' => $this->actingAs($this->orgAdminA)
                ->get(route('organization.admin.ai-consumption', ['organization' => $this->organizationA->slug]))
                ->assertOk()->getContent(),
        ];

        foreach ($htmls as $surface => $html) {
            $this->assertStringContainsString('data-guest-shell-diag="platform_ceiling_unset"', $html, $surface);
            $this->assertStringContainsString(e((string) $expected['label']), $html, $surface.' : libelle');
            $this->assertStringContainsString(e((string) $expected['cause']), $html, $surface.' : cause');
            $this->assertStringContainsString(e((string) $expected['action']), $html, $surface.' : geste');
        }
    }

    /** Un Shell eteint par choix se lit pareil partout : un etat, pas une panne. */
    public function test_a_disabled_shell_reads_the_same_on_both_superadmin_cockpits(): void
    {
        $expected = GuestShellDiagnosis::fromStatusAndReasons(GuestShellState::DISABLED, ['disabled']);

        foreach (['admin.ai-config', 'admin.guest-shell'] as $route) {
            $html = $this->actingAs($this->superAdmin)->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('data-guest-shell-diag="disabled"', $html, $route);
            $this->assertStringContainsString(e((string) $expected['label']), $html, $route);
        }
    }

    /** Une cle manquante se lit pareil partout. */
    public function test_a_missing_credential_reads_the_same_on_both_superadmin_cockpits(): void
    {
        // Par le MODELE : `api_key` est chiffree par un cast. Un update en
        // query builder ecrirait du texte clair, et la lecture suivante
        // echouerait a le dechiffrer (500) — un faux rouge qui n'aurait rien
        // dit du diagnostic.
        $setting = OrganizationAiSetting::query()->firstWhere('organization_id', $this->organizationA->id);
        $setting->api_key = '';
        $setting->save();
        $expected = GuestShellDiagnosis::fromStatusAndReasons(GuestShellState::NO_CREDENTIAL, ['api_key_missing']);

        foreach (['admin.ai-config', 'admin.guest-shell'] as $route) {
            $html = $this->actingAs($this->superAdmin)->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('data-guest-shell-diag="api_key_missing"', $html, $route);
            $this->assertStringContainsString(e((string) $expected['action']), $html, $route);
        }
    }

    // =====================================================================
    // C. Le nom propre, et les secrets
    // =====================================================================

    /**
     * Aucune des trois surfaces ne nomme une personne. La mesure porte sur le
     * HTML RENDU : les commentaires Blade en contiennent legitimement, et ils
     * ne sortent pas.
     */
    public function test_no_admin_surface_names_a_person_or_leaks_a_secret(): void
    {
        $secret = 'sk-task1470-ne-doit-jamais-sortir';
        $setting = OrganizationAiSetting::query()->firstWhere('organization_id', $this->organizationA->id);
        $setting->api_key = $secret;
        $setting->save();

        $htmls = [
            'ai-config' => $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent(),
            'shell-welcome' => $this->actingAs($this->superAdmin)->get(route('admin.guest-shell'))->assertOk()->getContent(),
            'org-consumption' => $this->actingAs($this->orgAdminA)
                ->get(route('organization.admin.ai-consumption', ['organization' => $this->organizationA->slug]))
                ->assertOk()->getContent(),
        ];

        foreach ($htmls as $surface => $html) {
            $this->assertStringNotContainsString('Cyril', $html, $surface.' : aucun nom propre dans une chaine rendue');
            $this->assertStringNotContainsString($secret, $html, $surface.' : aucun secret');
        }
    }

    /** Et la chaine fautive n'existe plus du tout dans les fichiers de langue. */
    public function test_the_orphaned_reason_strings_are_gone_from_both_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $keys = array_keys(require lang_path($locale.'/admin.php'));

            $orphans = array_filter(
                $keys,
                fn (string $k) => str_starts_with($k, 'guest_shell_state_') || str_starts_with($k, 'guest_shell_reason_'),
            );

            $this->assertSame([], array_values($orphans), $locale.' : les cles orphelines subsistent');
        }
    }

    // =====================================================================
    // D. Tenant : A ne lit jamais le diagnostic de B
    // =====================================================================

    public function test_an_organization_admin_never_sees_another_organizations_diagnosis(): void
    {
        // B est eteinte ; A est en plafond plateforme absent. Deux diagnostics
        // differents, pour que la fuite soit visible si elle existe.
        $bDiag = GuestShellDiagnosis::fromStatusAndReasons(GuestShellState::DISABLED, ['disabled']);

        $html = $this->actingAs($this->orgAdminA)
            ->get(route('organization.admin.ai-consumption', ['organization' => $this->organizationA->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-guest-shell-diag="platform_ceiling_unset"', $html);
        $this->assertStringNotContainsString('data-guest-shell-diag="disabled"', $html, 'le diagnostic de B ne doit pas apparaitre');
        $this->assertStringNotContainsString($this->organizationB->name, $html, 'le nom de B ne doit pas apparaitre');
        $this->assertSame('disabled', $bDiag['key'], 'pre-requis du test : B a bien un diagnostic DIFFERENT');

        // Et l'ecran d'une autre Organization lui est refuse.
        $this->actingAs($this->orgAdminA)
            ->get(route('organization.admin.ai-consumption', ['organization' => $this->organizationB->slug]))
            ->assertForbidden();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function organizationWithShell(string $slug, string $name, bool $enabled): Organization
    {
        $organization = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => $slug,
            'name' => $name,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1470-'.$slug,
        ]);

        OrganizationGuestShellPolicy::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            ['enabled' => $enabled],
        );

        return $organization;
    }

    /** @param list<string> $reasons */
    private function stateWith(string $status, array $reasons): GuestShellState
    {
        return new GuestShellState(
            $status,
            $reasons,
            OrganizationGuestShellPolicy::query()->firstWhere('organization_id', $this->organizationA->id),
            null,
            ['messages' => 0, 'cost_usd' => 0.0, 'cost_unknown' => 0],
        );
    }
}
