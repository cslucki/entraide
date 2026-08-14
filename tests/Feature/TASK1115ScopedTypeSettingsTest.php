<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopTypeSettingsService;
use App\Services\LoopService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les reglages de types deviennent scopables : Plateforme, puis Organization.
 *
 * **Aucun second systeme.** `loop_type_settings` existait deja, mais sans
 * portee : `loop_type` y etait unique, donc un reglage etait forcement global.
 * La chaine **Plateforme -> Organization -> Boucle** existait deja pour les
 * permissions — `organizations.loop_permissions` et `LoopPermissionResolver`
 * la tiennent — et c'est sa grammaire qui est reprise ici : **`null` signifie
 * le niveau au-dessus**.
 *
 * L'invariant majeur du mandat est teste a part : modifier un preset ne doit
 * **jamais** reecrire la composition effective d'une Boucle existante.
 */
class TASK1115ScopedTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $auteurA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->auteurA = User::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function reglages(): LoopTypeSettingsService
    {
        return app(LoopTypeSettingsService::class);
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    // ── La grammaire : null veut dire le niveau au-dessus ───────────────────

    public function test_without_an_organization_only_the_platform_level_is_read(): void
    {
        // Un appelant sans contexte tenant ne doit pas heriter d'une
        // Organization au hasard.
        $plateformeAvant = $this->reglages()->cardsFor('project');

        $this->reglages()->save('project', ['core.manifesto'], true, $this->orgA);

        // Le niveau Plateforme n'a pas bouge…
        $this->assertSame($plateformeAvant, $this->reglages()->cardsFor('project'));

        // …et l'Organization, elle, voit son propre reglage.
        $this->assertSame(['core.manifesto'], $this->reglages()->cardsFor('project', $this->orgA));
    }

    public function test_an_organization_without_an_override_inherits_the_platform(): void
    {
        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true);

        $this->assertSame(
            $this->reglages()->cardsFor('project'),
            $this->reglages()->cardsFor('project', $this->orgB),
        );
    }

    public function test_an_organization_override_wins_over_the_platform(): void
    {
        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true);
        $this->reglages()->save('project', ['core.manifesto', 'core.members', 'core.journal'], true, $this->orgA);

        $this->assertContains('core.journal', $this->reglages()->cardsFor('project', $this->orgA));
        $this->assertNotContains('core.journal', $this->reglages()->cardsFor('project'));
    }

    public function test_availability_is_scoped_too(): void
    {
        // Un type ferme sur la Plateforme peut etre ouvert pour une seule
        // Organization — c'est tout l'objet de la portee.
        $this->assertFalse($this->types()->isAvailable('networking'));

        $this->reglages()->save('networking', $this->types()->cardsFor('networking'), true, $this->orgA);

        $this->assertTrue($this->types()->isAvailable('networking', $this->orgA));
        $this->assertFalse($this->types()->isAvailable('networking', $this->orgB));
        $this->assertFalse($this->types()->isAvailable('networking'));
    }

    // ── Rien n'est stocke pour rien ─────────────────────────────────────────

    public function test_an_override_that_repeats_the_level_above_is_not_stored(): void
    {
        // **La reference n'est pas la meme selon la portee.** Comparer un
        // reglage d'Organization a la configuration du fichier ecrirait un
        // override inutile — et ce type cesserait alors de suivre les
        // changements de la Plateforme.
        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true);
        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true, $this->orgA);

        $this->assertFalse($this->reglages()->hasOrganizationOverride('project', $this->orgA));
    }

    public function test_an_organization_keeps_following_the_platform_after_such_a_save(): void
    {
        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true);
        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true, $this->orgA);

        // La Plateforme bouge : l'Organization suit, puisqu'elle n'a rien
        // stocke.
        $this->reglages()->save('project', ['core.manifesto', 'core.members', 'core.polls'], true);

        $this->assertContains('core.polls', $this->reglages()->cardsFor('project', $this->orgA));
    }

    public function test_only_one_platform_setting_can_exist_per_type(): void
    {
        // Plusieurs NULL ne s'egalent pas dans un index unique ordinaire : sans
        // l'index partiel, deux reglages Plateforme du meme type coexisteraient
        // et le service en lirait un au hasard.
        $this->reglages()->save('project', ['core.manifesto'], true);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LoopTypeSetting::create([
            'organization_id' => null,
            'loop_type' => 'project',
            'cards' => ['core.members'],
        ]);
    }

    // ── Le reset ne deborde pas ─────────────────────────────────────────────

    public function test_resetting_an_organization_leaves_the_platform_alone(): void
    {
        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true);
        $this->reglages()->save('project', ['core.manifesto', 'core.members', 'core.journal'], true, $this->orgA);

        $this->reglages()->reset('project', $this->orgA);

        $this->assertFalse($this->reglages()->hasOrganizationOverride('project', $this->orgA));
        $this->assertContains('core.members', $this->reglages()->cardsFor('project'));
        $this->assertSame(
            $this->reglages()->cardsFor('project'),
            $this->reglages()->cardsFor('project', $this->orgA),
        );
    }

    public function test_resetting_an_organization_never_touches_its_neighbour(): void
    {
        $this->reglages()->save('project', ['core.manifesto', 'core.journal'], true, $this->orgA);
        $this->reglages()->save('project', ['core.manifesto', 'core.polls'], true, $this->orgB);

        $this->reglages()->reset('project', $this->orgA);

        $this->assertTrue($this->reglages()->hasOrganizationOverride('project', $this->orgB));
        $this->assertContains('core.polls', $this->reglages()->cardsFor('project', $this->orgB));
    }

    public function test_resetting_the_platform_leaves_organization_overrides_alone(): void
    {
        $this->reglages()->save('project', ['core.manifesto'], true);
        $this->reglages()->save('project', ['core.manifesto', 'core.journal'], true, $this->orgA);

        $this->reglages()->reset('project');

        $this->assertTrue($this->reglages()->hasOrganizationOverride('project', $this->orgA));
        $this->assertContains('core.journal', $this->reglages()->cardsFor('project', $this->orgA));
    }

    // ── L'invariant majeur : une Boucle existante n'est jamais reecrite ─────

    public function test_changing_a_preset_never_rewrites_an_existing_loop(): void
    {
        app()->instance('current_organization', $this->orgA);

        $loop = (new LoopService)->createLoop($this->auteurA, 'Une Boucle')->fresh();
        $loop->forceFill(['type' => 'project'])->save();

        LoopCard::where('loop_id', $loop->id)->delete();
        foreach (['core.manifesto', 'core.members', 'core.roadmap'] as $cle) {
            LoopCard::create([
                'organization_id' => $this->orgA->id, 'loop_id' => $loop->id,
                'card_key' => $cle, 'enabled' => true, 'added_by_preset' => 'project',
            ]);
        }

        $avant = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all();

        // Le preset de l'Organization change du tout au tout.
        $this->reglages()->save('project', ['core.manifesto'], true, $this->orgA);

        $apres = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all();

        $this->assertSame($avant, $apres, 'le changement de preset a reecrit une Boucle existante');
    }

    public function test_a_locally_disabled_card_stays_disabled(): void
    {
        app()->instance('current_organization', $this->orgA);

        $loop = (new LoopService)->createLoop($this->auteurA, 'Une Boucle')->fresh();
        $loop->forceFill(['type' => 'project'])->save();

        LoopCard::updateOrCreate(
            ['loop_id' => $loop->id, 'card_key' => 'core.roadmap'],
            ['organization_id' => $this->orgA->id, 'enabled' => false],
        );

        $this->reglages()->save('project', $this->types()->cardsFor('project'), true, $this->orgA);
        $this->types()->applyPreset($loop->fresh());

        $this->assertFalse(
            (bool) LoopCard::where('loop_id', $loop->id)->where('card_key', 'core.roadmap')->value('enabled'),
            'une Card eteinte a la main a ete rallumee',
        );
    }

    public function test_a_locally_added_card_survives(): void
    {
        app()->instance('current_organization', $this->orgA);

        $loop = (new LoopService)->createLoop($this->auteurA, 'Une Boucle')->fresh();
        $loop->forceFill(['type' => 'project'])->save();

        LoopCard::firstOrCreate(
            ['loop_id' => $loop->id, 'card_key' => 'core.journal'],
            ['organization_id' => $this->orgA->id, 'enabled' => true, 'added_by_preset' => null],
        );

        $this->reglages()->save('project', ['core.manifesto', 'core.members'], true, $this->orgA);
        $this->types()->applyPreset($loop->fresh());

        $ligne = LoopCard::where('loop_id', $loop->id)->where('card_key', 'core.journal')->first();

        $this->assertNotNull($ligne, 'une Card ajoutee a la main a disparu');
        $this->assertTrue((bool) $ligne->enabled);
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_a_loop_reads_the_override_of_its_own_organization(): void
    {
        // L'Organization vient de la **Boucle**, jamais de la requete : la
        // regle posee par TASK-1103.
        app()->instance('current_organization', $this->orgA);

        $auteurB = User::factory()->create(['organization_id' => $this->orgB->id]);
        app()->instance('current_organization', $this->orgB);
        $loopB = (new LoopService)->createLoop($auteurB, 'Chez B')->fresh();
        $loopB->forceFill(['type' => 'project'])->save();

        $this->reglages()->save('project', ['core.manifesto', 'core.journal'], true, $this->orgB);

        // Le contexte courant pointe sur A pendant qu'on lit une Boucle de B.
        app()->instance('current_organization', $this->orgA);

        LoopCard::where('loop_id', $loopB->id)->delete();
        $this->types()->applyPreset($loopB->fresh());

        $this->assertContains('core.journal', $this->types()->activeCardsFor($loopB->fresh()));
    }

    public function test_an_override_of_one_organization_is_invisible_to_the_other(): void
    {
        $this->reglages()->save('project', ['core.manifesto', 'core.journal'], true, $this->orgA);

        $this->assertFalse($this->reglages()->hasOrganizationOverride('project', $this->orgB));
        $this->assertNotContains('core.journal', $this->reglages()->cardsFor('project', $this->orgB));
    }

    public function test_deleting_an_organization_takes_its_settings_away(): void
    {
        $this->reglages()->save('project', ['core.manifesto', 'core.journal'], true, $this->orgA);

        $id = $this->orgA->id;
        $this->orgA->forceDelete();

        $this->assertDatabaseMissing('loop_type_settings', ['organization_id' => $id]);
    }
}
