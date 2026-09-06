<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1371 — une seule version active par scenario, et c'est un CHEMIN
 * D'ECRITURE qu'on repare, pas une contrainte qu'on ajoute.
 *
 * ## Le defaut, constate pendant T1370
 *
 * `clarify_help_request` portait v3 ET v1 actives en base de dev. Le service
 * reste correct — il retient la version active la plus haute — mais l'ecran
 * d'administration affichait deux versions « actives » sans dire laquelle
 * s'appliquait. La migration v3 l'avait pourtant ecrit d'elle-meme : « laisser
 * deux lignes actives rendrait l'ecran d'administration mensonger ».
 *
 * ## Deux portes, pas une
 *
 * `update()` activait sans toucher aux soeurs. Et `store()` ne validait meme pas
 * `is_active` — or la colonne vaut `->default(true)`, donc toute version creee
 * par l'interface naissait ACTIVE, silencieusement. La seconde porte est la plus
 * discrete, et probablement l'origine de l'etat observe.
 */
class TASK1371ActiveVersionUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private const SCENARIO = 'task1371_scenario';

    private const AUTRE = 'task1371_autre_scenario';

    /** 1. Activer une version desactive les soeurs du MEME scenario. */
    public function test_activating_a_version_deactivates_its_siblings(): void
    {
        $v1 = $this->prompt(1, true);
        $v3 = $this->prompt(3, true);
        $v4 = $this->prompt(4, false);

        $v4->activate();

        $this->assertTrue($v4->fresh()->is_active);
        $this->assertFalse($v1->fresh()->is_active);
        $this->assertFalse($v3->fresh()->is_active);
        $this->assertSame(1, $this->actives(self::SCENARIO));
    }

    /** 2. Un AUTRE scenario n'est jamais touche. */
    public function test_another_scenario_is_strictly_untouched(): void
    {
        $autre = AdminAiPrompt::create([
            'scenario_id' => self::AUTRE,
            'name' => 'Autre scenario v1',
            'prompt_text' => 'AUTRE',
            'version' => 1,
            'is_active' => true,
        ]);

        $this->prompt(1, true);
        $this->prompt(2, false)->activate();

        $this->assertTrue($autre->fresh()->is_active, 'Un scenario voisin ne doit rien perdre.');
        $this->assertSame(1, $this->actives(self::AUTRE));
    }

    /** 3. Rejouee, l'activation ne change rien : elle est idempotente. */
    public function test_activation_is_idempotent(): void
    {
        $this->prompt(1, true);
        $v2 = $this->prompt(2, false);

        $v2->activate();
        $apres = $v2->fresh()->updated_at;

        $v2->fresh()->activate();

        $this->assertTrue($v2->fresh()->is_active);
        $this->assertSame(1, $this->actives(self::SCENARIO));
        $this->assertEquals($apres, $v2->fresh()->updated_at, 'Une activation deja acquise ne doit rien reecrire.');
    }

    /**
     * 4. La version CHOISIE est bien celle que le runtime resout.
     *
     * Le service prend la version ACTIVE la plus haute. Activer une version
     * BASSE doit donc reellement la faire gouverner — sinon « choisir » ne
     * voudrait rien dire.
     */
    public function test_the_chosen_version_is_the_one_resolved_at_runtime(): void
    {
        $this->prompt(9, true);
        $v2 = $this->prompt(2, false);

        $v2->activate();

        $resolue = AdminAiPrompt::query()
            ->where('scenario_id', self::SCENARIO)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $this->assertSame(2, (int) $resolue->version, 'La v9 reste en base mais ne gouverne plus.');
    }

    // =====================================================================
    // Les deux portes d'ecriture de l'ecran d'administration
    // =====================================================================

    /** 5. L'ecran d'edition : cocher « actif » laisse UNE seule version active. */
    public function test_the_admin_update_screen_leaves_a_single_active_version(): void
    {
        $v1 = $this->prompt(1, true);
        $v2 = $this->prompt(2, false);

        $this->actingAs($this->platformAdmin())
            ->put(route('admin.ai-prompts.update', $v2), [
                'name' => 'v2 activee',
                'prompt_text' => 'TEXTE',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($v2->fresh()->is_active);
        $this->assertFalse($v1->fresh()->is_active);
        $this->assertSame(1, $this->actives(self::SCENARIO));
    }

    /**
     * 6. L'ecran de creation aussi — et c'etait la porte la plus discrete.
     *
     * `store()` ne valide pas `is_active`, mais la colonne vaut `default(true)` :
     * la nouvelle version naissait active SANS desactiver les autres.
     */
    public function test_the_admin_create_screen_also_leaves_a_single_active_version(): void
    {
        $v1 = $this->prompt(1, true);

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.ai-prompts.store'), [
                'scenario_id' => 'clarify_help_request',
                'name' => 'Nouvelle version',
                'prompt_text' => 'TEXTE',
            ])
            ->assertRedirect();

        $this->assertSame(1, $this->actives('clarify_help_request'));
        $this->assertTrue($v1->fresh()->is_active, 'Un autre scenario reste intact.');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function prompt(int $version, bool $active): AdminAiPrompt
    {
        return AdminAiPrompt::create([
            'scenario_id' => self::SCENARIO,
            'name' => 'Scenario v'.$version,
            'prompt_text' => 'TEXTE v'.$version,
            'version' => $version,
            'is_active' => $active,
        ]);
    }

    private function actives(string $scenario): int
    {
        return AdminAiPrompt::query()
            ->where('scenario_id', $scenario)
            ->where('is_active', true)
            ->count();
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
