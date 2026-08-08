<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\Loops\PresetException;
use App\Services\LoopService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un type de Boucle retire des choix ne s'assigne pas — **sur aucun chemin**.
 *
 * La regle existait, mais dans **un seul controleur**. Les trois autres
 * chemins ne l'avaient pas : un POST forge, ou simplement l'ecran d'admin
 * d'Organization, posait un type que le produit n'offre pas.
 *
 * Elle vit desormais au registre, et chaque chemin la lit au lieu de la redire :
 * c'est la duplication qui avait laisse les trous.
 *
 * **La nuance qui compte** : garder le type que la Boucle porte deja n'est pas
 * une assignation. L'interdire empecherait d'enregistrer le nom ou la
 * description d'une Boucle dont le type a ete ferme entre-temps.
 */
class TASK1108UnavailableLoopTypesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $orgAdmin;

    private User $proprietaire;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        // L'admin d'Organization est designe par `organizations.admin_id`, et
        // non par un drapeau sur l'utilisateur.
        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $this->proprietaire = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loop = (new LoopService)->createLoop($this->proprietaire, 'Ma Boucle')->fresh();
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    /** Un type reellement retire des choix, lu dans la configuration. */
    private function indisponible(): string
    {
        foreach (array_keys(config('loop_types.types')) as $cle) {
            if (! $this->types()->isAvailable($cle)) {
                return $cle;
            }
        }

        $this->fail('aucun type indisponible : le test ne prouverait rien');
    }

    // ── La regle, au registre ───────────────────────────────────────────────

    public function test_networking_is_currently_unavailable(): void
    {
        // Si ce type venait a s'ouvrir, ce test le dirait — et les suivants
        // devraient choisir un autre exemple plutot que de passer par accident.
        $this->assertFalse($this->types()->isAvailable('networking'));
    }

    public function test_an_available_type_is_assignable(): void
    {
        $this->assertTrue($this->types()->isAssignableTo('project', 'general'));
    }

    public function test_an_unavailable_type_is_not_assignable(): void
    {
        $this->assertFalse($this->types()->isAssignableTo($this->indisponible(), 'general'));
    }

    public function test_keeping_the_type_one_already_has_is_not_an_assignment(): void
    {
        // Sinon un formulaire d'edition refuserait d'enregistrer le nom d'une
        // Boucle dont le type a ete ferme entre-temps.
        $ferme = $this->indisponible();

        $this->assertTrue($this->types()->isAssignableTo($ferme, $ferme));
    }

    public function test_an_unknown_type_is_never_assignable(): void
    {
        $this->assertFalse($this->types()->isAssignableTo('nexiste-pas', 'general'));
        $this->assertFalse($this->types()->isAssignableTo(null, 'general'));
    }

    // ── Le service qui applique ─────────────────────────────────────────────

    public function test_the_service_refuses_an_unavailable_type(): void
    {
        // C'est le point unique par lequel tous les appelants passent.
        $this->expectException(PresetException::class);

        app(LoopPresetConfigurator::class)->applyPreset(
            $this->superAdmin, $this->loop, $this->indisponible(),
        );
    }

    public function test_the_service_leaves_the_type_untouched_after_a_refusal(): void
    {
        $avant = $this->loop->type;

        try {
            app(LoopPresetConfigurator::class)->applyPreset(
                $this->superAdmin, $this->loop, $this->indisponible(),
            );
        } catch (PresetException) {
            // Attendu.
        }

        $this->assertSame($avant, $this->loop->fresh()->type);
    }

    public function test_the_service_still_accepts_an_available_type(): void
    {
        app(LoopPresetConfigurator::class)->applyPreset($this->superAdmin, $this->loop, 'project');

        $this->assertSame('project', $this->loop->fresh()->type);
    }

    // ── Le chemin Admin d'Organization ──────────────────────────────────────

    public function test_the_organization_admin_cannot_apply_an_unavailable_type(): void
    {
        // C'est ce chemin qui manquait la garde : l'ecran d'admin
        // d'Organization ne verifiait que l'existence du type.
        $reponse = $this->actingAs($this->orgAdmin)->put(
            route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
            ['name' => 'Renommee', 'type' => $this->indisponible()],
        );

        $reponse->assertSessionHas('error');
        $this->assertNotSame($this->indisponible(), $this->loop->fresh()->type);
    }

    public function test_nothing_at_all_is_written_when_the_type_is_refused(): void
    {
        // Le nom ne doit pas passer non plus : un refus partiel laisserait
        // croire que le geste a reussi.
        $this->actingAs($this->orgAdmin)->put(
            route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
            ['name' => 'Renommee en douce', 'type' => $this->indisponible()],
        );

        $this->assertNotSame('Renommee en douce', $this->loop->fresh()->name);
    }

    public function test_the_organization_admin_may_still_apply_an_available_type(): void
    {
        $this->actingAs($this->orgAdmin)->put(
            route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
            ['name' => 'Ma Boucle', 'type' => 'project'],
        )->assertSessionHasNoErrors();

        $this->assertSame('project', $this->loop->fresh()->type);
    }

    public function test_the_organization_admin_may_keep_a_closed_type_the_loop_already_has(): void
    {
        $ferme = $this->indisponible();
        $this->loop->forceFill(['type' => $ferme])->save();

        $this->actingAs($this->orgAdmin)->put(
            route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
            ['name' => 'Nouveau nom', 'type' => $ferme],
        );

        $this->assertSame('Nouveau nom', $this->loop->fresh()->name);
        $this->assertSame($ferme, $this->loop->fresh()->type);
    }

    public function test_a_loop_of_another_organization_is_a_404(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->actingAs($this->orgAdmin)->put(
            route('organization.admin.loops.update', ['organization' => $autreOrg->slug, 'loop' => $this->loop->id]),
            ['name' => 'Chez eux', 'type' => 'project'],
        )->assertNotFound();

        $this->assertNotSame('Chez eux', $this->loop->fresh()->name);
    }

    public function test_an_unknown_identifier_is_refused(): void
    {
        $this->actingAs($this->orgAdmin)->put(
            route('organization.admin.loops.update', [
                'organization' => $this->org->slug,
                'loop' => '00000000-0000-0000-0000-000000000000',
            ]),
            ['name' => 'X', 'type' => 'project'],
        )->assertNotFound();
    }

    // ── Le chemin SuperAdmin ────────────────────────────────────────────────

    public function test_the_super_admin_path_refuses_it_too(): void
    {
        $this->actingAs($this->superAdmin)->post(
            route('admin.loops.preset.apply', ['loop' => $this->loop->id]),
            ['type' => $this->indisponible()],
        )->assertSessionHas('error');

        $this->assertNotSame($this->indisponible(), $this->loop->fresh()->type);
    }

    public function test_the_owner_has_no_path_to_change_the_type_at_all(): void
    {
        // Le formulaire du proprietaire n'accepte pas `type` : le poser n'a
        // aucun effet, plutot que d'etre refuse.
        $avant = $this->loop->type;

        $this->actingAs($this->proprietaire)->put(
            route('loops.update', ['loop' => $this->loop->id]),
            ['name' => 'Ma Boucle', 'type' => $this->indisponible()],
        );

        $this->assertSame($avant, $this->loop->fresh()->type);
    }

    // ── Aucune condition sur le type dans le metier ─────────────────────────

    public function test_no_path_compares_the_type_by_hand(): void
    {
        // La regle se lit au registre. Une comparaison ecrite a la main est ce
        // qui avait laisse trois chemins sans garde.
        foreach ([
            app_path('Http/Controllers/Admin/OrgAdminController.php'),
            app_path('Http/Controllers/Admin/AdminLoopController.php'),
            app_path('Services/Loops/LoopPresetConfigurator.php'),
        ] as $fichier) {
            $source = file_get_contents($fichier);

            $this->assertStringNotContainsString(
                "isAvailable(\$type) && ",
                $source,
                basename($fichier).' redit la regle au lieu de la lire',
            );
        }
    }
}
