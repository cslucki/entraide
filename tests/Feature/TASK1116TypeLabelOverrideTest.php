<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Services\LoopTypeSettingsService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renommer un type change **son libelle**, jamais sa cle.
 *
 * `key = training` reste `training` pour toujours : c'est elle que portent
 * `loops.type`, les presets, les permissions et les donnees deja ecrites. Seul
 * le mot affiche change — et il peut differer d'une Organization a l'autre.
 *
 * C'est l'invariant central de cette tache, et la moitie de ces tests ne
 * verifient que lui.
 */
class TASK1116TypeLabelOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
    }

    private function reglages(): LoopTypeSettingsService
    {
        return app(LoopTypeSettingsService::class);
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    // ── La cle ne bouge jamais ──────────────────────────────────────────────

    public function test_the_stored_row_is_keyed_by_the_key_and_not_by_the_word(): void
    {
        // L'implementation naive — « renommer, c'est ecrire le nouveau mot a la
        // place de l'ancien » — ecrirait le libelle dans `loop_type`. La ligne
        // deviendrait alors introuvable par la cle, et le type perdrait son
        // reglage au premier renommage.
        $this->reglages()->rename('training', 'Parcours de formation', null, $this->orgA);

        $this->assertDatabaseHas('loop_type_settings', [
            'organization_id' => $this->orgA->id,
            'loop_type' => 'training',
            'label' => 'Parcours de formation',
        ]);

        $this->assertSame(
            0,
            LoopTypeSetting::query()->where('loop_type', 'Parcours de formation')->count(),
            'le libelle a ete ecrit dans la colonne de la cle',
        );
    }

    public function test_a_renamed_type_stays_assignable_by_its_key(): void
    {
        // Tout ce qui s'appuie sur la cle doit continuer de repondre : c'est ce
        // que couterait un renommage qui deplacerait la cle.
        $this->reglages()->rename('training', 'Parcours de formation', null);

        $this->assertTrue($this->types()->isAssignableTo('training', 'general'));
        $this->assertNotSame([], $this->types()->cardsFor('training'));
        $this->assertArrayHasKey('training', $this->types()->selectableFor('training'));
    }

    public function test_a_loop_keeps_its_stored_type_after_a_rename(): void
    {
        $auteur = User::factory()->create(['organization_id' => $this->orgA->id]);
        app()->instance('current_organization', $this->orgA);

        $loop = (new LoopService)->createLoop($auteur, 'Une Boucle')->fresh();
        $loop->forceFill(['type' => 'training'])->save();

        $this->reglages()->rename('training', 'Parcours', null, $this->orgA);

        // La colonne porte toujours la cle, pas le mot.
        $this->assertSame('training', $loop->fresh()->type);
        $this->assertDatabaseHas('loops', ['id' => $loop->id, 'type' => 'training']);
    }

    public function test_the_preset_still_answers_after_a_rename(): void
    {
        // Renommer ne doit rien casser de ce qui s'appuie sur la cle.
        $avant = $this->types()->cardsFor('training');

        $this->reglages()->rename('training', 'Parcours', null);

        $this->assertSame($avant, $this->types()->cardsFor('training'));
    }

    // ── Le libelle, lui, change ─────────────────────────────────────────────

    public function test_a_platform_rename_is_seen_everywhere(): void
    {
        $this->reglages()->rename('training', 'Parcours de formation', null);

        $this->assertSame('Parcours de formation', $this->types()->label('training'));
        $this->assertSame('Parcours de formation', $this->types()->label('training', $this->orgA));
        $this->assertSame('Parcours de formation', $this->types()->label('training', $this->orgB));
    }

    public function test_an_organization_rename_is_seen_only_there(): void
    {
        $plateforme = $this->types()->label('training');

        $this->reglages()->rename('training', 'Nos parcours', null, $this->orgA);

        $this->assertSame('Nos parcours', $this->types()->label('training', $this->orgA));
        $this->assertSame($plateforme, $this->types()->label('training', $this->orgB));
        $this->assertSame($plateforme, $this->types()->label('training'));
    }

    public function test_an_organization_rename_wins_over_a_platform_rename(): void
    {
        $this->reglages()->rename('training', 'Parcours de formation', null);
        $this->reglages()->rename('training', 'Nos parcours a nous', null, $this->orgA);

        $this->assertSame('Nos parcours a nous', $this->types()->label('training', $this->orgA));
        $this->assertSame('Parcours de formation', $this->types()->label('training', $this->orgB));
    }

    public function test_without_any_override_the_configured_translation_is_used(): void
    {
        $this->assertSame(__('loops.types.training.label'), $this->types()->label('training'));
    }

    public function test_the_description_follows_the_same_rule(): void
    {
        $this->reglages()->rename('training', null, 'Ce que nous appelons un parcours.', $this->orgA);

        $this->assertSame('Ce que nous appelons un parcours.', $this->types()->description('training', $this->orgA));
        $this->assertSame(__('loops.types.training.description'), $this->types()->description('training', $this->orgB));
    }

    // ── Rien n'est stocke pour rien ─────────────────────────────────────────

    public function test_an_empty_label_stores_nothing(): void
    {
        $this->reglages()->rename('training', '   ', null, $this->orgA);

        $this->assertFalse($this->reglages()->hasOrganizationOverride('training', $this->orgA));
    }

    public function test_a_rename_that_repeats_the_level_above_stores_nothing(): void
    {
        // Sinon ce type cesserait de suivre les renommages de la Plateforme.
        $this->reglages()->rename('training', 'Parcours', null);
        $this->reglages()->rename('training', 'Parcours', null, $this->orgA);

        $this->assertFalse($this->reglages()->hasOrganizationOverride('training', $this->orgA));

        // La preuve : la Plateforme change encore, et l'Organization suit.
        $this->reglages()->rename('training', 'Parcours revises', null);

        $this->assertSame('Parcours revises', $this->types()->label('training', $this->orgA));
    }

    public function test_clearing_a_label_returns_to_the_level_above(): void
    {
        $plateforme = $this->types()->label('training');

        $this->reglages()->rename('training', 'Nos parcours', null, $this->orgA);
        $this->reglages()->rename('training', null, null, $this->orgA);

        $this->assertSame($plateforme, $this->types()->label('training', $this->orgA));
    }

    public function test_clearing_a_label_does_not_drop_the_cards_of_the_same_row(): void
    {
        // La ligne porte aussi la composition : la vider entierement effacerait
        // un reglage que personne n'a demande de retirer.
        $this->reglages()->save('training', ['core.manifesto', 'core.members'], true, $this->orgA);
        $this->reglages()->rename('training', 'Nos parcours', null, $this->orgA);

        $this->reglages()->rename('training', null, null, $this->orgA);

        $this->assertTrue($this->reglages()->hasOrganizationOverride('training', $this->orgA));
        $this->assertSame(['core.manifesto', 'core.members'], $this->reglages()->cardsFor('training', $this->orgA));
    }

    public function test_saving_the_cards_back_to_default_does_not_drop_the_label(): void
    {
        // L'ecran enregistre le libelle et la composition d'un meme geste : les
        // deux ecritures se croisent sur la meme ligne. Ramener la composition
        // au niveau au-dessus ne doit pas emporter le renommage avec elle.
        $this->reglages()->rename('training', 'Nos parcours', null, $this->orgA);
        $this->reglages()->save('training', ['core.manifesto'], true, $this->orgA);

        $this->reglages()->save('training', $this->reglages()->cardsFor('training'), true, $this->orgA);

        $this->assertSame('Nos parcours', $this->types()->label('training', $this->orgA));
    }

    public function test_a_label_is_bounded(): void
    {
        $this->reglages()->rename('training', str_repeat('z', 500), null, $this->orgA);

        $this->assertLessThanOrEqual(80, mb_strlen($this->types()->label('training', $this->orgA)));
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_a_rename_in_one_organization_is_invisible_to_the_other(): void
    {
        $this->reglages()->rename('training', 'Nos parcours', 'Notre description.', $this->orgA);

        $this->assertFalse($this->reglages()->hasOrganizationOverride('training', $this->orgB));
        $this->assertNotSame('Nos parcours', $this->types()->label('training', $this->orgB));
        $this->assertNotSame('Notre description.', $this->types()->description('training', $this->orgB));
    }

    public function test_resetting_an_organization_drops_its_rename_only(): void
    {
        $this->reglages()->rename('training', 'Chez A', null, $this->orgA);
        $this->reglages()->rename('training', 'Chez B', null, $this->orgB);

        $this->reglages()->reset('training', $this->orgA);

        $this->assertSame('Chez B', $this->types()->label('training', $this->orgB));
        $this->assertNotSame('Chez A', $this->types()->label('training', $this->orgA));
    }

    public function test_no_rename_ever_writes_a_new_type_key(): void
    {
        $avant = LoopTypeSetting::query()->pluck('loop_type')->unique()->sort()->values()->all();

        $this->reglages()->rename('training', 'Parcours', null, $this->orgA);
        $this->reglages()->rename('project', 'Chantiers', null, $this->orgB);

        $apres = LoopTypeSetting::query()->pluck('loop_type')->unique()->sort()->values()->all();

        foreach (array_diff($apres, $avant) as $nouvelle) {
            $this->assertTrue(
                $this->types()->exists($nouvelle),
                "« {$nouvelle} » n’est pas une cle du catalogue : un renommage a cree une cle",
            );
        }
    }
}
