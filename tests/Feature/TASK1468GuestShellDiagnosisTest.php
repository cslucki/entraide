<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;
use App\Models\User;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestShellDiagnosis;
use App\Support\GuestShell\GuestShellState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1468 (CDC 21h-23h §3.2, UX-6) — « Mal configure » devient un diagnostic
 * actionnable.
 *
 * ## Le probleme, precisement
 *
 * `MISCONFIGURED` recouvre TROIS situations sans rapport : Organization
 * inactive, Organization non publique, plafond plateforme non pose. Trois
 * causes, trois gestes, un seul mot pour les trois. C'est pour cela que le
 * libelle derive desormais de la RAISON et non du statut.
 *
 * ## Ce que ce test garantit
 *
 *  - la table couvre TOUS les codes que la politique peut produire — le test
 *    lit la liste dans le code source de `GuestShellPolicyService`, pas dans
 *    une copie recopiee a la main, pour qu'un code ajoute demain sans entree
 *    de diagnostic fasse rougir ce fichier ;
 *  - chaque entree porte un libelle, une cause et un geste, dans les DEUX
 *    langues ;
 *  - un code inconnu ne devient jamais « Pret » ;
 *  - aucun libelle ne laisse fuir une cle.
 */
class TASK1468GuestShellDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // A. La table est EXHAUSTIVE — et le prouve contre la source
    // =====================================================================

    /**
     * La liste de reference n'est pas recopiee : elle est extraite du code de
     * `GuestShellPolicyService::state()`, la seule autorite qui produit ces
     * codes. Une TASK qui ajoute un code sans entree de diagnostic casse ici,
     * et nulle part ailleurs — c'est tout l'interet.
     */
    public function test_every_reason_code_the_policy_can_emit_has_a_diagnosis(): void
    {
        $source = file_get_contents(app_path('Services/GuestShell/GuestShellPolicyService.php'));
        $this->assertIsString($source);

        preg_match_all("/\\\$reasons\\[\\] = '([a-z_]+)'|GuestShellState::[A-Z_]+, \\['([a-z_]+)'\\]|\\['([a-z_]+)'\\], \\\$policy/", $source, $m);
        $emitted = array_values(array_unique(array_filter(array_merge($m[1], $m[2], $m[3]))));

        // Le ternaire de la derniere branche n'est pas capture par la regex :
        // les deux codes qu'il produit sont ajoutes explicitement, et le test
        // verifie qu'ils sont bien dans le fichier source.
        foreach (['guest_monthly_budget_reached', 'process_budget_reached'] as $ternary) {
            $this->assertStringContainsString("'".$ternary."'", $source, 'la branche ternaire produit toujours '.$ternary);
            $emitted[] = $ternary;
        }
        $emitted = array_values(array_unique($emitted));

        $this->assertGreaterThanOrEqual(11, count($emitted), 'la regex a bien trouve les codes de la politique');

        foreach ($emitted as $reason) {
            $this->assertArrayHasKey(
                $reason,
                GuestShellDiagnosis::REASON_MAP,
                "le code [{$reason}] est produit par la politique mais n'a pas de diagnostic",
            );
        }
    }

    /** Chaque entree porte un libelle, une cause et un geste — dans les deux langues. */
    public function test_every_diagnosis_entry_says_what_and_what_to_do_in_both_languages(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (GuestShellDiagnosis::REASON_MAP as $reason => [$key, $tone]) {
                $state = $this->stateWith(GuestShellState::MISCONFIGURED, [$reason]);
                $diag = GuestShellDiagnosis::for($state);

                $this->assertSame($key, $diag['key'], $locale.'/'.$reason);
                $this->assertSame($tone, $diag['tone'], $locale.'/'.$reason);
                $this->assertNotSame('', trim($diag['label']), $locale.'/'.$reason.' : libelle');
                $this->assertStringNotContainsString('guest_shell_diag_', $diag['label'], $locale.'/'.$reason.' : cle non traduite');

                // « Desactive » est un choix, pas une panne : ni cause ni geste.
                if ($key === 'disabled') {
                    $this->assertNull($diag['cause']);
                    $this->assertNull($diag['action']);

                    continue;
                }

                $this->assertNotNull($diag['cause'], $locale.'/'.$reason.' : cause');
                $this->assertNotNull($diag['action'], $locale.'/'.$reason.' : geste');
                $this->assertStringNotContainsString('guest_shell_diag_', (string) $diag['cause']);
                $this->assertStringNotContainsString('guest_shell_diag_', (string) $diag['action']);
            }
        }

        app()->setLocale('fr');
    }

    // =====================================================================
    // B. Les cas qui comptent
    // =====================================================================

    /** ACTIVE : « Pret », rien a expliquer, rien a faire. */
    public function test_an_active_shell_is_simply_ready(): void
    {
        $diag = GuestShellDiagnosis::for($this->stateWith(GuestShellState::ACTIVE, []));

        $this->assertSame('ready', $diag['key']);
        $this->assertSame(GuestShellDiagnosis::TONE_READY, $diag['tone']);
        $this->assertNull($diag['cause']);
        $this->assertNull($diag['action']);
    }

    /**
     * L'etat REEL de `main` ce soir : le plafond plateforme n'est pas pose.
     * C'est le cas qui a motive toute la tranche — « Mal configure » n'en
     * disait rien.
     */
    public function test_the_platform_ceiling_case_names_its_cause_and_its_action(): void
    {
        $diag = GuestShellDiagnosis::for($this->stateWith(GuestShellState::MISCONFIGURED, ['platform_ceiling_unset']));

        $this->assertSame('platform_ceiling_unset', $diag['key']);
        $this->assertSame(GuestShellDiagnosis::TONE_ACTION, $diag['tone']);
        $this->assertStringNotContainsString('Mal configuré', $diag['label']);
        // Zone SuperAdmin : nommer la variable d'environnement AIDE a agir.
        $this->assertStringContainsString('AI_GUEST_SHELL_PLATFORM_CEILING_USD', (string) $diag['action']);
    }

    /**
     * Le meme statut, trois causes : la demonstration que le libelle ne peut
     * pas venir du statut.
     */
    public function test_one_status_three_causes_three_different_diagnoses(): void
    {
        $keys = [];
        foreach (['organization_inactive', 'organization_not_public', 'platform_ceiling_unset'] as $reason) {
            $keys[] = GuestShellDiagnosis::for($this->stateWith(GuestShellState::MISCONFIGURED, [$reason]))['key'];
        }

        $this->assertSame($keys, array_unique($keys), 'trois causes MISCONFIGURED, trois diagnostics distincts');
    }

    /** Un code inconnu n'est jamais « Pret » : il demande une action et se nomme. */
    public function test_an_unknown_reason_never_reads_as_healthy(): void
    {
        $diag = GuestShellDiagnosis::for($this->stateWith(GuestShellState::MISCONFIGURED, ['une_raison_de_demain']));

        $this->assertSame(GuestShellDiagnosis::UNKNOWN_KEY, $diag['key']);
        $this->assertSame(GuestShellDiagnosis::TONE_ACTION, $diag['tone']);
        $this->assertSame('une_raison_de_demain', $diag['technical']);
        $this->assertNotSame(GuestShellDiagnosis::TONE_READY, $diag['tone']);

        // Idem pour un etat bloquant sans aucune raison.
        $empty = GuestShellDiagnosis::for($this->stateWith(GuestShellState::MISCONFIGURED, []));
        $this->assertSame(GuestShellDiagnosis::UNKNOWN_KEY, $empty['key']);
        $this->assertNull($empty['technical']);
    }

    /** Aucun libelle ne transporte de secret. */
    public function test_no_diagnosis_leaks_a_credential(): void
    {
        $secret = 'sk-task1468-ne-doit-jamais-sortir';
        $organization = $this->organization(['is_active' => true, 'is_public' => true]);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => $secret,
        ]);
        OrganizationGuestShellPolicy::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            ['enabled' => true],
        );

        $diag = GuestShellDiagnosis::for(app(GuestShellPolicyService::class)->state($organization->fresh()));

        $this->assertStringNotContainsString($secret, implode(' ', array_map('strval', array_filter($diag, 'is_string'))));
    }

    // =====================================================================
    // C. L'ecran SuperAdmin le montre vraiment
    // =====================================================================

    public function test_the_ai_config_screen_shows_the_action_instead_of_the_old_label(): void
    {
        $organization = $this->organization(['is_active' => true, 'is_public' => true, 'name' => 'Org Diag']);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1468',
        ]);
        OrganizationGuestShellPolicy::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            ['enabled' => true],
        );

        // Aucun plafond plateforme : l'etat REEL du banc ce soir.
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => null]);

        // La langue de la PAGE vient de la personne (`preferred_locale`), pas de
        // `app()->setLocale()` : on la fixe, puis on construit l'attendu dans la
        // meme langue.
        $superAdmin = User::factory()->complete()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        // TASK-1500 : la configuration Shell Welcome a sa page — la mesure suit.
        $html = $this->actingAs($superAdmin)->get(route('admin.shell-welcome-config'))->assertOk()->getContent();

        app()->setLocale('fr');
        preg_match_all('/data-guest-shell-diag="([a-z_]+)"/', $html, $found);
        $this->assertContains('platform_ceiling_unset', $found[1], 'diags rendus : '.implode(',', $found[1]));
        $this->assertStringContainsString(e(__('admin.guest_shell_diag_platform_ceiling_unset_action')), $html);
        $this->assertStringContainsString('data-guest-shell-diag-action', $html);

        // Et le mot qui n'aidait personne a disparu de cet ecran.
        $this->assertStringNotContainsString('Mal configuré', $html);
        $this->assertStringNotContainsString('Mal configur', $html);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @param list<string> $reasons */
    private function stateWith(string $status, array $reasons): GuestShellState
    {
        $organization = $this->organization();

        return new GuestShellState(
            $status,
            $reasons,
            OrganizationGuestShellPolicy::query()->updateOrCreate(
                ['organization_id' => $organization->id],
                ['enabled' => true],
            ),
            null,
            ['messages' => 0, 'cost_usd' => 0.0, 'cost_unknown' => 0],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function organization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes + [
            'is_active' => true,
            'slug' => 'org-diag-'.uniqid(),
        ]);
    }
}
