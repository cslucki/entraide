<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Services\LoopTypeSettingsService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'ecran des types passe de « Plateforme » a « Plateforme -> Organization ».
 *
 * Il lisait la traduction du fichier de configuration et n'ecrivait qu'au niveau
 * global : c'etait le seul endroit du produit a ne pas voir les surcharges qu'il
 * servait a poser.
 *
 * La portee arrive **par un parametre de requete**, donc elle est resolue contre
 * la table avant de servir d'`organization_id` d'ecriture. C'est la regle du
 * mandat, et le test qui la garde est ici.
 */
class TASK1116TypeAdminScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $simple;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->simple = User::factory()->create(['is_admin' => false, 'organization_id' => $this->orgA->id]);

        app()->instance('current_organization', $this->orgA);
    }

    private function reglages(): LoopTypeSettingsService
    {
        return app(LoopTypeSettingsService::class);
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    /** Le socle en vigueur, pour poster un formulaire qui ne change que le mot. */
    private function socle(string $type, ?Organization $org = null): array
    {
        return $this->reglages()->cardsFor($type, $org);
    }

    // ── Acces ───────────────────────────────────────────────────────────────

    public function test_only_the_super_admin_reaches_the_screen(): void
    {
        $this->actingAs($this->simple)->get(route('admin.loop-types'))->assertForbidden();
        $this->actingAs($this->superAdmin)->get(route('admin.loop-types'))->assertOk();
    }

    public function test_a_simple_member_cannot_rename_a_type(): void
    {
        $this->actingAs($this->simple)
            ->put(route('admin.loop-types.update', 'training'), [
                'label' => 'Detourne',
                'cards' => $this->socle('training'),
                'available' => 1,
                'scope' => $this->orgA->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, LoopTypeSetting::query()->count());
    }

    // ── La portee est resolue, jamais recue ─────────────────────────────────

    public function test_a_forged_scope_is_a_404_and_writes_nothing(): void
    {
        // Un identifiant qui n'existe pas ne doit pas devenir un
        // `organization_id` d'ecriture.
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'training'), [
                'label' => 'Forge',
                'cards' => $this->socle('training'),
                'available' => 1,
                'scope' => '00000000-0000-0000-0000-000000000000',
            ])
            ->assertNotFound();

        $this->assertSame(0, LoopTypeSetting::query()->count());
    }

    public function test_a_scope_that_is_not_even_an_identifier_is_refused(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => 'pas-un-identifiant']))
            ->assertNotFound();
    }

    public function test_a_deleted_organization_is_not_a_scope(): void
    {
        $id = $this->orgB->id;
        $this->orgB->delete();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $id]))
            ->assertNotFound();
    }

    public function test_no_scope_means_the_platform(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'training'), [
                'label' => 'Parcours de formation',
                'cards' => $this->socle('training'),
                'available' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('loop_type_settings', [
            'organization_id' => null,
            'loop_type' => 'training',
            'label' => 'Parcours de formation',
        ]);
    }

    // ── Renommer par l'ecran ────────────────────────────────────────────────

    public function test_the_super_admin_renames_a_type_for_one_organization(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'training'), [
                'label' => 'Nos parcours',
                'cards' => $this->socle('training', $this->orgA),
                'available' => 1,
                'scope' => $this->orgA->id,
            ])
            ->assertRedirect();

        $this->assertSame('Nos parcours', $this->types()->label('training', $this->orgA));
        $this->assertNotSame('Nos parcours', $this->types()->label('training', $this->orgB));
        $this->assertNotSame('Nos parcours', $this->types()->label('training'));
    }

    public function test_renaming_by_the_screen_never_moves_the_key(): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'training'), [
                'label' => 'Nos parcours',
                'cards' => $this->socle('training', $this->orgA),
                'available' => 1,
                'scope' => $this->orgA->id,
            ]);

        $this->assertDatabaseHas('loop_type_settings', [
            'organization_id' => $this->orgA->id,
            'loop_type' => 'training',
        ]);
        $this->assertSame(0, LoopTypeSetting::query()->where('loop_type', 'Nos parcours')->count());
    }

    public function test_the_screen_shows_the_word_in_force_for_the_scope(): void
    {
        // L'ecran lisait la traduction du fichier : il etait le seul a ne pas
        // voir les surcharges qu'il sert a poser.
        //
        // **Sur la donnee de la vue, pas sur le HTML.** Le mot apparait aussi
        // dans l'attribut `value` du champ de saisie : un `assertSee` passerait
        // meme si le titre, lui, affichait encore la traduction du fichier.
        $this->reglages()->rename('training', 'Nos parcours', null, $this->orgA);

        $chezA = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->viewData('types');

        $this->assertSame('Nos parcours', $chezA['training']['label']);

        $chezB = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgB->id]))
            ->viewData('types');

        $this->assertNotSame('Nos parcours', $chezB['training']['label']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgB->id]))
            ->assertDontSee('Nos parcours');
    }

    public function test_the_field_shows_what_this_level_decided_not_what_it_inherits(): void
    {
        // Afficher l'heritage dans le champ le ferait recopier dans la surcharge
        // au premier enregistrement, et ce type cesserait de suivre la
        // Plateforme.
        $this->reglages()->rename('training', 'Formation continue', null);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->getContent();

        $this->assertStringNotContainsString('value="Formation continue"', $html);
        $this->assertStringContainsString('placeholder="Formation continue"', $html);
    }

    public function test_the_key_is_always_shown_next_to_the_word(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->assertSee('<code>training</code>', false);
    }

    // ── Revenir aux reglages Plateforme ─────────────────────────────────────

    public function test_the_reset_only_undoes_the_scope_on_screen(): void
    {
        $this->reglages()->rename('training', 'Chez A', null, $this->orgA);
        $this->reglages()->rename('training', 'Chez B', null, $this->orgB);
        $this->reglages()->rename('training', 'Sur la Plateforme', null);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loop-types.reset', 'training'), ['scope' => $this->orgA->id])
            ->assertRedirect();

        $this->assertSame('Sur la Plateforme', $this->types()->label('training', $this->orgA));
        $this->assertSame('Chez B', $this->types()->label('training', $this->orgB));
        $this->assertSame('Sur la Plateforme', $this->types()->label('training'));
    }

    public function test_the_reset_never_touches_an_existing_loop(): void
    {
        $auteur = User::factory()->create(['organization_id' => $this->orgA->id]);
        $loop = (new LoopService)->createLoop($auteur, 'Une Boucle')->fresh();
        $loop->forceFill(['type' => 'training'])->save();

        $this->reglages()->save('training', ['core.manifesto'], true, $this->orgA);
        $avant = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loop-types.reset', 'training'), ['scope' => $this->orgA->id]);

        $this->assertSame($avant, LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all());
    }

    // ── Ce que l'ecran annonce est ce qu'il fait ────────────────────────────

    public function test_the_announced_impact_is_counted_inside_the_scope(): void
    {
        // La mesure portait sur tout le parc pendant que l'ecriture, elle,
        // appliquait le socle de l'Organization **de chaque Boucle**. Regler une
        // Organization annonçait donc un chiffre pris chez les autres.
        $auteurA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $auteurB = User::factory()->create(['organization_id' => $this->orgB->id]);

        app()->instance('current_organization', $this->orgA);
        $chezA = (new LoopService)->createLoop($auteurA, 'Chez A')->fresh();
        $chezA->forceFill(['type' => 'training'])->save();

        app()->instance('current_organization', $this->orgB);
        $chezB = (new LoopService)->createLoop($auteurB, 'Chez B')->fresh();
        $chezB->forceFill(['type' => 'training'])->save();

        LoopCard::where('loop_id', $chezB->id)->delete();

        $impact = app(\App\Services\Loops\LoopPresetSyncService::class)
            ->previewForCards('training', $this->socle('training'), $this->orgA);

        $this->assertSame(1, $impact['loops'], 'l’impact a ete compte hors de la portee reglee');
    }

    public function test_saving_one_organization_leaves_the_other_parks_loops_alone(): void
    {
        $auteurB = User::factory()->create(['organization_id' => $this->orgB->id]);

        app()->instance('current_organization', $this->orgB);
        $chezB = (new LoopService)->createLoop($auteurB, 'Chez B')->fresh();
        $chezB->forceFill(['type' => 'training'])->save();
        LoopCard::where('loop_id', $chezB->id)->delete();

        app()->instance('current_organization', $this->orgA);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-types.update', 'training'), [
                'label' => 'Nos parcours',
                'cards' => ['core.manifesto', 'core.members', 'core.journal'],
                'available' => 1,
                'scope' => $this->orgA->id,
            ]);

        $this->assertSame(
            0,
            LoopCard::where('loop_id', $chezB->id)->count(),
            'le reglage d’une Organization a ecrit dans les Boucles d’une autre',
        );
    }

    // ── Le mot ne coute pas une requete par Boucle ──────────────────────────

    public function test_reading_the_label_never_costs_a_query_per_loop(): void
    {
        // La carte de catalogue lit desormais le libelle **de l'Organization de
        // la Boucle** : sans chargement anticipe, c'est une requete par ligne.
        //
        // **La mesure porte sur la table `organizations`, pas sur la page.** Le
        // catalogue traine par ailleurs un `exists` sur `loop_members` par
        // carte, anterieur a cette tache et documente a part : un compte global
        // grossirait avec le parc pour une raison qui n'est pas celle-ci, et ce
        // test accuserait le mauvais coupable — ou, pire, serait desarme en
        // relachant son seuil.
        $mesure = function (int $combien): int {
            $org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
            $membre = User::factory()->create(['organization_id' => $org->id]);
            app()->instance('current_organization', $org);

            // Deux au minimum : avec une seule Boucle, le catalogue redirige
            // vers elle et ne rend jamais la liste qu'on mesure.
            for ($i = 0; $i < $combien; $i++) {
                (new LoopService)->createLoop($membre, "Boucle {$i}");
            }

            $surOrganizations = 0;

            \Illuminate\Support\Facades\DB::listen(function ($q) use (&$surOrganizations) {
                if (str_contains($q->sql, 'from "organizations"') || str_contains($q->sql, 'from `organizations`')) {
                    $surOrganizations++;
                }
            });

            $this->actingAs($membre)
                ->get(route('organization.loops.index', ['organization' => $org->slug]))
                ->assertOk();

            return $surOrganizations;
        };

        $petit = $mesure(2);
        $grand = $mesure(7);

        $this->assertSame(
            $petit,
            $grand,
            "le libelle interroge `organizations` par Boucle : {$petit} pour deux Boucles, {$grand} pour sept",
        );
    }

    public function test_the_loop_count_shown_is_the_one_of_the_scope(): void
    {
        $auteurB = User::factory()->create(['organization_id' => $this->orgB->id]);

        app()->instance('current_organization', $this->orgB);
        $chezB = (new LoopService)->createLoop($auteurB, 'Chez B')->fresh();
        $chezB->forceFill(['type' => 'training'])->save();

        app()->instance('current_organization', $this->orgA);

        $vue = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->viewData('types');

        $this->assertSame(0, $vue['training']['loops'], 'le compte affiche deborde de la portee');

        $vueB = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgB->id]))
            ->viewData('types');

        $this->assertSame(1, $vueB['training']['loops']);
    }
}
