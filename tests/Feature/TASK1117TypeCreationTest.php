<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\LoopTypeSettingsService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creer un type de Boucle, et le voir partout ou un type se voit.
 *
 * Premiere partie : **une dette laissee par TASK-1116**. Le selecteur de type —
 * l'endroit le plus visible ou un type apparait, present dans les trois
 * formulaires de creation — lisait `__($definition['label_key'])` en direct,
 * sans passer par `label()`. Une Organization qui renommait « Formation » en
 * « Parcours de formation » voyait donc toujours « Formation » au moment de
 * creer une Boucle. Le meme ecran annonçait le socle **Plateforme** au lieu du
 * sien.
 *
 * Ce n'est pas un detail de cette tache : un type cree n'a **aucune**
 * `label_key`, donc le selecteur doit de toute façon passer par le registre.
 * Les deux defauts se corrigent d'un seul geste.
 */
class TASK1117TypeCreationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function reglages(): LoopTypeSettingsService
    {
        return app(LoopTypeSettingsService::class);
    }

    private function creation(): \App\Services\Loops\LoopTypeCreationService
    {
        return app(\App\Services\Loops\LoopTypeCreationService::class);
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    // ── Un type cree existe, et sa cle porte son origine ────────────────────

    public function test_a_created_type_joins_the_catalogue(): void
    {
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->assertTrue($this->types()->exists($type->key));
        $this->assertSame('Parcours', $this->types()->label($type->key));
        $this->assertSame($type->key, $this->types()->resolve($type->key));
    }

    public function test_an_organization_key_carries_its_prefix(): void
    {
        $type = $this->creation()->create($this->org, 'Parcours');

        $this->assertStringStartsWith('org_', $type->key);
        $this->assertStringContainsString('__', $type->key);
        $this->assertStringEndsWith('parcours', $type->key);
    }

    public function test_a_platform_type_has_no_prefix(): void
    {
        $type = $this->creation()->create(null, 'Atelier');

        $this->assertSame('atelier', $type->key);
    }

    public function test_the_key_never_collides_with_a_configured_one(): void
    {
        // Une Organization qui creerait « Formation » masquerait le type du
        // fichier pour elle seule — un ecart qu'aucun ecran ne saurait
        // expliquer.
        $type = $this->creation()->create($this->org, 'Formation');

        $this->assertNotSame('training', $type->key);
        $this->assertNotSame('general', $type->key);
        $this->assertNotContains($type->key, array_keys(config('loop_types.types')));
    }

    public function test_two_types_of_the_same_name_get_distinct_keys(): void
    {
        $un = $this->creation()->create($this->org, 'Parcours');
        $deux = $this->creation()->create($this->org, 'Parcours');

        $this->assertNotSame($un->key, $deux->key);
        $this->assertTrue($this->types()->exists($un->key));
        $this->assertTrue($this->types()->exists($deux->key));
    }

    public function test_two_organizations_may_use_the_same_word_without_meeting(): void
    {
        // Ce que le prefixe protege vraiment : deux locataires appellent leur
        // type « Parcours », chacun garde le sien, et aucun ne voit celui de
        // l'autre. Sans prefixe, le second recevrait une cle rafistolee
        // (`parcours_2`) qui ne dit plus a qui elle appartient.
        $voisine = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $chezMoi = $this->creation()->create($this->org, 'Parcours', null, 'training');
        $chezElle = $this->creation()->create($voisine, 'Parcours', null, 'training');

        $this->assertNotSame($chezMoi->key, $chezElle->key);

        // Chacune ne voit que le sien, et les deux portent bien le meme mot.
        $this->assertArrayHasKey($chezMoi->key, $this->types()->all($this->org));
        $this->assertArrayNotHasKey($chezElle->key, $this->types()->all($this->org));
        $this->assertArrayHasKey($chezElle->key, $this->types()->all($voisine));
        $this->assertArrayNotHasKey($chezMoi->key, $this->types()->all($voisine));

        $this->assertSame('Parcours', $this->types()->label($chezMoi->key));
        $this->assertSame('Parcours', $this->types()->label($chezElle->key));

        // Et la cle dit de qui elle vient, sans avoir a interroger la table.
        $this->assertStringContainsString(substr(str_replace('-', '', $this->org->id), 0, 6), $chezMoi->key);
        $this->assertStringContainsString(substr(str_replace('-', '', $voisine->id), 0, 6), $chezElle->key);
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_a_type_created_by_an_organization_is_invisible_elsewhere(): void
    {
        $voisine = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $type = $this->creation()->create($this->org, 'Parcours');

        $this->assertArrayHasKey($type->key, $this->types()->all($this->org));
        $this->assertArrayNotHasKey($type->key, $this->types()->all($voisine));
        $this->assertArrayNotHasKey($type->key, $this->types()->all());
    }

    public function test_a_type_created_by_an_organization_is_not_offered_elsewhere(): void
    {
        $voisine = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->assertArrayHasKey($type->key, $this->types()->available($this->org));
        $this->assertArrayNotHasKey($type->key, $this->types()->available($voisine));
    }

    public function test_a_platform_type_is_offered_to_every_organization(): void
    {
        $voisine = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $type = $this->creation()->create(null, 'Atelier', null, 'training');

        $this->assertArrayHasKey($type->key, $this->types()->available($this->org));
        $this->assertArrayHasKey($type->key, $this->types()->available($voisine));
    }

    public function test_a_loop_carrying_a_created_type_is_never_read_as_the_default(): void
    {
        // **L'invariant le plus couteux a rater.** Si `exists()` ne connaissait
        // le type que dans le contexte de son Organization, toute lecture hors
        // contexte — une commande, un job, un ecran transverse — le ferait
        // retomber sur le type par defaut : la Boucle s'afficherait comme une
        // Communaute sans que personne ne l'ait decide.
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        app()->forgetInstance('current_organization');

        $this->assertTrue($this->types()->exists($type->key));
        $this->assertSame($type->key, $this->types()->resolve($type->key));
        $this->assertSame('Parcours', $this->types()->label($type->key));
        $this->assertNotSame($this->types()->default(), $this->types()->resolve($type->key));
    }

    // ── « Partir de » copie, ne suit pas ────────────────────────────────────

    public function test_starting_from_a_type_copies_its_composition(): void
    {
        $modele = $this->types()->cardsFor('training', $this->org);

        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->assertSame($modele, $this->types()->cardsFor($type->key, $this->org));
        $this->assertNotSame([], $modele, 'le modele choisi ne compose rien : le test ne prouverait rien');
    }

    public function test_the_copy_does_not_follow_the_model_afterwards(): void
    {
        // Suivre ferait bouger un type tout seul quand un autre change.
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');
        $avant = $this->types()->cardsFor($type->key, $this->org);

        $this->reglages()->save('training', ['core.manifesto'], true, $this->org);

        $this->assertSame($avant, $this->types()->cardsFor($type->key, $this->org));
    }

    public function test_starting_from_nothing_leaves_the_type_closed(): void
    {
        // Un type qui ne compose rien donnerait un espace de travail vide : il
        // existe, mais ne s'offre pas encore.
        $type = $this->creation()->create($this->org, 'Brouillon');

        $this->assertFalse($type->available);
        $this->assertArrayNotHasKey($type->key, $this->types()->available($this->org));
        $this->assertTrue($this->types()->exists($type->key));
    }

    public function test_an_unknown_model_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->creation()->create($this->org, 'Parcours', null, 'type-qui-nexiste-pas');
    }

    public function test_an_empty_label_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->creation()->create($this->org, '   ');
    }

    // ── Retirer un type ─────────────────────────────────────────────────────

    public function test_a_type_nobody_carries_can_be_removed(): void
    {
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->creation()->delete($type);

        $this->assertFalse($this->types()->exists($type->key));
    }

    public function test_a_type_a_loop_carries_is_never_removed(): void
    {
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $loop = (new \App\Services\LoopService)->createLoop($this->membre, 'Une Boucle')->fresh();
        $loop->forceFill(['type' => $type->key])->save();

        try {
            $this->creation()->delete($type);
            $this->fail('un type porte par une Boucle a ete supprime');
        } catch (\InvalidArgumentException) {
            // C'est le refus attendu.
        }

        $this->assertTrue($this->types()->exists($type->key));
        $this->assertSame($type->key, $loop->fresh()->type);
    }

    public function test_deleting_an_organization_takes_its_types_away(): void
    {
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->org->forceDelete();

        $this->assertDatabaseMissing('custom_loop_types', ['id' => $type->id]);
    }

    // ── Le renommage de TASK-1116 s'applique aussi aux types crees ──────────

    public function test_a_created_type_can_be_renamed_without_touching_its_key(): void
    {
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->reglages()->rename($type->key, 'Parcours certifiant', null, $this->org);

        $this->assertSame('Parcours certifiant', $this->types()->label($type->key, $this->org));
        $this->assertSame($type->key, $type->fresh()->key);
        $this->assertTrue($this->types()->exists($type->key));
    }

    // ── La dette de TASK-1116 : le selecteur ignorait les surcharges ────────

    public function test_the_type_picker_shows_the_word_of_the_organization(): void
    {
        $this->reglages()->rename('training', 'Parcours de formation', null, $this->org);

        $this->actingAs($this->membre)
            ->get(route('organization.loops.create', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee('Parcours de formation');
    }

    public function test_the_type_picker_stops_showing_the_inherited_word(): void
    {
        // La preuve que le mot affiche vient bien de la surcharge : l'ancien ne
        // doit plus apparaitre du tout.
        $this->reglages()->rename('training', 'Parcours de formation', null, $this->org);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.loops.create', ['organization' => $this->org->slug]))
            ->getContent();

        $this->assertStringNotContainsString('>Formation<', $html);
    }

    public function test_the_type_picker_announces_the_preset_of_the_organization(): void
    {
        // L'ecran annonce « ce que ce type construit ». S'il lit le socle
        // Plateforme pendant que la creation applique celui du locataire, il
        // annonce une composition que la Boucle n'aura pas.
        //
        // **Mesure par comptage, pas par presence.** Le selecteur affiche tous
        // les types disponibles, et « Journal » figure deja dans le socle d'un
        // autre type : un `assertSee` serait satisfait par la pastille du
        // voisin et passerait sans rien prouver.
        $mot = app(\App\Support\Loops\LoopCardRegistry::class)->label('core.journal');

        $page = fn (): string => $this->actingAs($this->membre)
            ->get(route('organization.loops.create', ['organization' => $this->org->slug]))
            ->getContent();

        $avant = substr_count($page(), $mot);

        $this->reglages()->save('training', ['core.manifesto', 'core.journal'], true, $this->org);

        $this->assertSame(
            $avant + 1,
            substr_count($page(), $mot),
            'le selecteur annonce le socle Plateforme et non celui de l’Organization',
        );
    }

    // ── Par l'ecran ─────────────────────────────────────────────────────────

    public function test_only_the_super_admin_creates_a_type(): void
    {
        $this->actingAs($this->membre)
            ->post(route('admin.loop-types.store'), ['label' => 'Detourne'])
            ->assertForbidden();

        $this->assertSame(0, \App\Models\CustomLoopType::query()->count());
    }

    public function test_the_screen_creates_a_type_in_the_displayed_scope(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $this->actingAs($admin)
            ->post(route('admin.loop-types.store'), [
                'label' => 'Parcours',
                'based_on' => 'training',
                'scope' => $this->org->id,
            ])
            ->assertRedirect();

        $type = \App\Models\CustomLoopType::query()->firstOrFail();

        $this->assertSame($this->org->id, $type->organization_id);
        $this->assertSame('Parcours', $type->label);
        $this->assertStringStartsWith('org_', $type->key);
    }

    public function test_a_created_type_appears_at_once_on_the_screen_that_made_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $this->actingAs($admin)->post(route('admin.loop-types.store'), [
            'label' => 'Parcours', 'based_on' => 'training', 'scope' => $this->org->id,
        ]);

        $vue = $this->actingAs($admin)
            ->get(route('admin.loop-types', ['scope' => $this->org->id]))
            ->viewData('types');

        $cle = \App\Models\CustomLoopType::query()->value('key');

        $this->assertArrayHasKey($cle, $vue, 'le type cree n’apparait pas la ou il a ete cree');
        $this->assertSame('Parcours', $vue[$cle]['label']);
    }

    public function test_a_created_type_does_not_leak_into_the_platform_screen(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $this->actingAs($admin)->post(route('admin.loop-types.store'), [
            'label' => 'Parcours', 'based_on' => 'training', 'scope' => $this->org->id,
        ]);

        $cle = \App\Models\CustomLoopType::query()->value('key');

        $this->assertArrayNotHasKey(
            $cle,
            $this->actingAs($admin)->get(route('admin.loop-types'))->viewData('types'),
        );
    }

    public function test_the_screen_refuses_to_remove_a_type_a_loop_carries(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $loop = (new \App\Services\LoopService)->createLoop($this->membre, 'Une Boucle')->fresh();
        $loop->forceFill(['type' => $type->key])->save();

        $this->actingAs($admin)
            ->delete(route('admin.loop-types.destroy', $type), ['scope' => $this->org->id])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseHas('custom_loop_types', ['id' => $type->id]);
    }

    public function test_the_screen_removes_a_type_nobody_carries(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->actingAs($admin)
            ->delete(route('admin.loop-types.destroy', $type), ['scope' => $this->org->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('custom_loop_types', ['id' => $type->id]);
    }

    public function test_the_platform_screen_renders_a_created_type(): void
    {
        // Un type cree n'a **pas** de cle de traduction. L'ecran lisait
        // `__($definition['label_key'])` en direct pour l'heritage : sur un type
        // cree, cette ligne echouait. Ce test rend la page, donc il attrape la
        // 500 comme il attraperait un mot manquant.
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $type = $this->creation()->create(null, 'Atelier', 'Un format court.', 'training');

        $vue = $this->actingAs($admin)
            ->get(route('admin.loop-types'))
            ->assertOk()
            ->viewData('types');

        $this->assertArrayHasKey($type->key, $vue);
        $this->assertSame('Atelier', $vue[$type->key]['label']);
        $this->assertSame('Atelier', $vue[$type->key]['inherited_label']);
    }

    public function test_a_created_type_is_offered_in_the_creation_form(): void
    {
        // Le critere d'arret du mandat : un type cree doit etre immediatement
        // utilisable, pas seulement enregistre.
        $type = $this->creation()->create($this->org, 'Parcours', null, 'training');

        $this->actingAs($this->membre)
            ->get(route('organization.loops.create', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee('Parcours');
    }
}
