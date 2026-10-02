<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackEntity;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1658 (demande de Cyril, hors du scope gele) — « Se connecter sous »
 * depuis l'onglet « personnes » du Preview.
 *
 * ## Ce que cette surface fait, et ce qu'elle n'est pas
 *
 * Elle n'introduit AUCUN mecanisme d'emprunt d'identite : elle poste vers
 * `admin.outils.scenarios.personas.enter`, la route du Persona Access de T1654,
 * avec le meme champ `persona_id` et donc la meme garde. L'ecran de selection
 * reste la porte principale ; ceci en est un raccourci, la ou l'on consulte la
 * liste des personas.
 *
 * ## Et surtout : aucun mot de passe
 *
 * Il n'existe aucun mot de passe de persona a afficher. Le Loader ecrit
 * `Hash::make(bin2hex(random_bytes(16)))` : le clair est tire a l'interieur de
 * l'appel, jamais affecte, jamais conserve. Le Persona Access n'en a pas besoin
 * — c'est une bascule de session gardee, pas une connexion par identifiant.
 *
 * ## L'appariement, et pourquoi pas par email
 *
 * L'onglet liste ce que le DOCUMENT declare ; le geste a besoin du compte
 * REELLEMENT charge. Le pont est le registre de tracabilite
 * (`manifest_user` -> `entity_id`). Apparier par email serait faux : le Load le
 * transforme en `…@<slug>.…` (T1654), donc l'email du document ne se retrouve
 * jamais tel quel en base.
 */
class TASK1658PreviewLoginAsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
        ]);
    }

    public function test_l_onglet_personnes_d_une_version_CHARGEE_offre_le_geste(): void
    {
        $version = $this->versionChargee();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => 'personnes']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('admin.scenario_manager.preview_login_as')), $html);
        $this->assertStringContainsString(e(__('admin.scenario_manager.preview_actions')), $html);

        // Le geste poste vers la route EXISTANTE du Persona Access, pas ailleurs.
        $this->assertStringContainsString(
            route('admin.outils.scenarios.personas.enter', $version),
            $html
        );

        // Et il porte l'identifiant d'un compte REELLEMENT charge.
        $idsCharges = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $version->scenario_pack_load_id)
            ->where('entity_type', 'manifest_user')
            ->pluck('entity_id');

        $this->assertGreaterThan(0, $idsCharges->count(), 'Aucun persona tracke.');

        $offerts = 0;
        foreach ($idsCharges as $id) {
            if (str_contains($html, 'data-persona-enter="'.$id.'"')) {
                $offerts++;
            }
        }
        $this->assertGreaterThan(0, $offerts, 'Aucun persona charge n est offert a l emprunt.');
    }

    public function test_le_geste_poste_reellement_et_bascule_l_identite(): void
    {
        $version = $this->versionChargee();

        $persona = User::query()
            ->whereIn('id', ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $version->scenario_pack_load_id)
                ->where('entity_type', 'manifest_user')
                ->pluck('entity_id'))
            ->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), [
                'persona_id' => $persona->id,
            ])
            ->assertRedirect('/');

        // C'est bien la persona qui agit desormais — aucune saisie de mot de
        // passe n'a eu lieu.
        $this->assertSame($persona->id, auth()->id());
    }

    public function test_une_version_NON_CHARGEE_n_offre_AUCUN_geste(): void
    {
        $version = $this->versionValide();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => 'personnes']))
            ->assertOk()
            ->getContent();

        // Les personas y sont DECLAREES mais n'existent pas encore : proposer
        // le geste serait promettre une porte qui n'a rien derriere.
        $this->assertStringNotContainsString(e(__('admin.scenario_manager.preview_login_as')), $html);
        $this->assertStringNotContainsString('data-persona-enter=', $html);
    }

    public function test_aucun_mot_de_passe_n_est_affiche_nulle_part_dans_l_onglet(): void
    {
        $version = $this->versionChargee();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => 'personnes']))
            ->assertOk()
            ->getContent();

        // Ni colonne, ni valeur, ni hachage. `CREDENTIAL_EXPOSURE = NEVER`
        // (T1654 §25) : cet ecran ne doit jamais devenir une surface de
        // divulgation, meme pour des comptes fictifs.
        $this->assertStringNotContainsString('password', strtolower($html));
        $this->assertStringNotContainsString('$2y$', $html);

        // Et aucun hachage de la base ne s'y trouve.
        foreach (User::query()->where('organization_id', $this->sandboxIdDe($version))->pluck('password') as $hachage) {
            if (is_string($hachage) && $hachage !== '') {
                $this->assertStringNotContainsString($hachage, $html);
            }
        }
    }

    // =====================================================================
    // Outils
    // =====================================================================

    private function versionValide(): ScenarioManifestVersion
    {
        $json = (string) file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
        $resultat = app(ScenarioManifestValidator::class)->validate($json);
        $this->assertTrue($resultat->isValid());

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'amt-formation-ia',
            'name' => 'AMT — Formation IA',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        return $version->fresh();
    }

    private function versionChargee(): ScenarioManifestVersion
    {
        $version = $this->versionValide();
        $cycle = app(ScenarioLifecycleService::class);
        $cycle->approve($version, $this->superAdmin);
        $cycle->load($version->fresh());

        return $version->fresh();
    }

    private function sandboxIdDe(ScenarioManifestVersion $version): string
    {
        return (string) \App\Models\ScenarioPackLoad::query()
            ->findOrFail($version->scenario_pack_load_id)->organization_id;
    }
}
