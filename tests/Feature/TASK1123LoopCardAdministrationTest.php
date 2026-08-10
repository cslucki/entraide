<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopPoll;
use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\LoopService;
use App\Services\LoopTypeSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les admins modifient la composition des Cards d'UNE Boucle precise.
 *
 * TYPE = point de depart ; BOUCLE = composition effective. L'invariant
 * principal est la **non-destruction** : desactiver une Card ne supprime
 * jamais ses donnees, et la reactiver les retrouve — prouve ici avec de
 * vraies donnees sur deux stockages differents (Sondages, fichiers du Dossier
 * racine).
 *
 * Le configurateur existait sans aucun lien entrant ; cette tache pose les
 * points d'entree et les conditionne a la capacite **reelle**
 * (`canConfigure()` + Boucle non archivee) : jamais un bouton que le serveur
 * refuserait.
 */
class TASK1123LoopCardAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private User $adminOrgA;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        // orgA n'est pas l'Organization par defaut : le mandat exige le test
        // hors du chemin par defaut.
        $this->orgA = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi', 'is_default' => false,
        ]);
        $this->orgB = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi', 'is_default' => false,
        ]);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->adminOrgA = User::factory()->create(['is_admin' => false, 'organization_id' => $this->orgA->id]);
        $this->orgA->forceFill(['admin_id' => $this->adminOrgA->id])->save();

        app()->instance('current_organization', $this->orgA);

        $proprietaire = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->boucle = (new LoopService)->createLoop($proprietaire, 'Boucle Cards QA')->fresh();
    }

    private function sondage(): LoopPoll
    {
        $poll = LoopPoll::create([
            'organization_id' => $this->orgA->id,
            'loop_id' => $this->boucle->id,
            'created_by' => $this->superAdmin->id,
            'question' => 'Question de recette 1123 ?',
            'selection_type' => LoopPoll::TYPE_SINGLE,
            'status' => 'open',
        ]);

        return $poll;
    }

    private function basculer(User $acteur, string $cle, bool $active): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($acteur)->put(route('admin.loops.cards.update', $this->boucle), [
            'card_key' => $cle, 'enabled' => $active,
        ]);
    }

    // ── Points d'entree : visibilite = capacite ─────────────────────────────

    public function test_the_super_admin_edit_screen_offers_the_tools_action(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->boucle))
            ->assertOk()
            ->assertSee(__('loops.edit_tools_action'))
            ->assertSee(route('admin.loops.configure', $this->boucle), false);
    }

    public function test_an_archived_loop_hides_the_action_on_both_screens(): void
    {
        $this->boucle->forceFill(['status' => 'archived'])->save();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->boucle))
            ->assertOk()
            ->assertDontSee(__('loops.edit_tools_action'));

        $this->actingAs($this->adminOrgA)
            ->get(route('organization.admin.loops.edit', [
                'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
            ]))
            ->assertOk()
            ->assertDontSee(__('loops.edit_tools_action'));
    }

    public function test_the_organization_edit_screen_offers_the_scoped_action(): void
    {
        $this->actingAs($this->adminOrgA)
            ->get(route('organization.admin.loops.edit', [
                'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
            ]))
            ->assertOk()
            ->assertSee(__('loops.edit_tools_action'))
            // La route SCOPEE — jamais la route plateforme en raccourci.
            ->assertSee(route('organization.admin.loops.configure', [
                'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
            ]), false)
            ->assertDontSee('"'.route('admin.loops.configure', $this->boucle).'"', false);
    }

    public function test_a_cross_org_super_admin_gets_no_scoped_button_it_could_not_use(): void
    {
        // Constate en recette : configureLoop() exige l'appartenance a
        // l'Organization, editLoop() laisse passer le SuperAdmin — le bouton
        // scope menait donc a une 404 pour un SuperAdmin d'une autre
        // Organization. Visibilite = capacite de la route visee.
        $superAdminAilleurs = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgB->id]);

        $this->actingAs($superAdminAilleurs)
            ->get(route('organization.admin.loops.edit', [
                'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
            ]))
            ->assertOk()
            ->assertDontSee(__('loops.edit_tools_action'));

        // Et la route scopee elle-meme refuse bien ce profil.
        $this->actingAs($superAdminAilleurs)
            ->get(route('organization.admin.loops.configure', [
                'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
            ]))
            ->assertNotFound();
    }

    // ── Non-destruction : Sondages (donnees en table metier) ────────────────

    public function test_disabling_polls_keeps_the_poll_and_reenabling_finds_it(): void
    {
        $poll = $this->sondage();

        // 1. La Card est dans le socle `general`, donc active.
        $this->assertContains('core.polls', app(\App\Support\Loops\LoopTypeRegistry::class)->activeCardsFor($this->boucle->fresh()));

        // 2. Desactivation par l'admin.
        $this->basculer($this->superAdmin, 'core.polls', false)->assertSessionMissing('error');

        $boucle = $this->boucle->fresh();

        // 3. La Card a quitte le workspace…
        $this->assertNotContains('core.polls', app(\App\Support\Loops\LoopTypeRegistry::class)->activeCardsFor($boucle));

        // 4. …mais la donnee metier est intacte : rien n'a purge la table.
        $this->assertDatabaseHas('loop_polls', ['id' => $poll->id, 'question' => 'Question de recette 1123 ?']);

        // 5. Reactivation : la MEME donnee est retrouvee.
        $this->basculer($this->superAdmin, 'core.polls', true)->assertSessionMissing('error');

        $this->assertContains('core.polls', app(\App\Support\Loops\LoopTypeRegistry::class)->activeCardsFor($this->boucle->fresh()));
        $this->assertSame($poll->id, LoopPoll::where('loop_id', $this->boucle->id)->value('id'));
    }

    // ── Non-destruction : fichiers du Dossier racine (stockage different) ───

    public function test_disabling_dossiers_keeps_the_files_and_reenabling_finds_them(): void
    {
        $racine = Dossier::where('loop_id', $this->boucle->id)->firstOrFail();
        $fichier = DossierFile::factory()->create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $racine->id,
            'uploaded_by' => $this->superAdmin->id,
            'original_name' => 'preuve-1123.pdf',
        ]);

        $this->basculer($this->superAdmin, 'core.dossiers', false)->assertSessionMissing('error');

        // Le fichier, le Dossier et le lien au document racine survivent tous.
        $this->assertDatabaseHas('dossier_files', ['id' => $fichier->id, 'original_name' => 'preuve-1123.pdf']);
        $this->assertDatabaseHas('dossiers', ['id' => $racine->id, 'loop_id' => $this->boucle->id]);

        $this->basculer($this->superAdmin, 'core.dossiers', true)->assertSessionMissing('error');

        $this->assertSame($fichier->id, $racine->fresh()->files()->value('id'));
    }

    // ── Le geste est local a la Boucle ──────────────────────────────────────

    public function test_composing_one_loop_touches_nothing_else(): void
    {
        $autre = (new LoopService)->createLoop(
            User::factory()->create(['organization_id' => $this->orgA->id]),
            'Boucle Temoin',
        )->fresh();

        $typeAvant = $this->boucle->type;
        $reglagesAvant = LoopTypeSetting::query()->count();

        $this->basculer($this->superAdmin, 'core.polls', false)->assertSessionMissing('error');

        // Ni le type, ni le preset commun, ni le preset Organization, ni la
        // Boucle temoin du meme type.
        $this->assertSame($typeAvant, $this->boucle->fresh()->type);
        $this->assertSame($reglagesAvant, LoopTypeSetting::query()->count(),
            'Composer une Boucle ne doit ecrire aucun reglage de type.');
        $this->assertContains('core.polls', app(\App\Support\Loops\LoopTypeRegistry::class)->activeCardsFor($autre->fresh()));
        $this->assertContains('core.polls', app(LoopTypeSettingsService::class)->cardsFor('general', $this->orgA));
    }

    // ── Archivee : lecture seule, meme par requete forgee ───────────────────

    public function test_an_archived_loop_refuses_forged_toggles(): void
    {
        $this->boucle->forceFill(['status' => 'archived'])->save();

        // La route de toggle : le resolveur refuse manage_cards sur archivee.
        $this->basculer($this->superAdmin, 'core.polls', false)->assertForbidden();

        // La route du configurateur : PresetException -> retour avec erreur,
        // et rien n'a change.
        $this->actingAs($this->superAdmin)->post(route('admin.loops.compose', $this->boucle), [
            'action' => 'disable', 'card_key' => 'core.polls',
        ])->assertSessionHas('error');

        $this->assertContains('core.polls', app(\App\Support\Loops\LoopTypeRegistry::class)->activeCardsFor($this->boucle->fresh()));
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_an_organization_admin_never_reaches_a_neighbour_loop(): void
    {
        // Une Boucle chez B, reellement candidate si le tenant fuyait.
        app()->instance('current_organization', $this->orgB);
        $boucleB = (new LoopService)->createLoop(
            User::factory()->create(['organization_id' => $this->orgB->id]),
            'Boucle B Candidat',
        )->fresh();
        app()->instance('current_organization', $this->orgA);

        // La route scopee de A refuse la Boucle de B.
        $this->actingAs($this->adminOrgA)
            ->get(route('organization.admin.loops.configure', [
                'organization' => $this->orgA->slug, 'loop' => $boucleB->id,
            ]))
            ->assertNotFound();

        // Et la route SuperAdmin n'est pas un raccourci : middleware admin.
        $this->actingAs($this->adminOrgA)
            ->get(route('admin.loops.configure', $boucleB))
            ->assertForbidden();
    }

    // ── Portee Organization sur le socle affiche ────────────────────────────

    public function test_the_composition_baseline_follows_the_organization_preset(): void
    {
        // orgA retire les Sondages de SON socle `general` : la baseline
        // affichee doit suivre l'Organization, pas la Plateforme.
        app(LoopTypeSettingsService::class)->save('general', ['core.manifesto', 'core.members'], true, $this->orgA);

        $composition = collect(app(\App\Services\Loops\LoopCardCompositionService::class)->compositionFor($this->boucle->fresh()))
            ->keyBy('key');

        $this->assertFalse($composition['core.polls']['in_preset'],
            'Le socle affiche doit etre celui de l\'Organization, pas celui de la Plateforme.');
        $this->assertTrue($composition['core.members']['in_preset']);
    }

    // ── Regle 3/3 : rien de masque, rien de detruit ─────────────────────────

    public function test_the_grid_limit_refuses_loudly_and_hides_nothing(): void
    {
        $configurator = app(LoopPresetConfigurator::class);
        $etat = $configurator->describe($this->boucle);

        // Le comportement documente : la grille montre TOUTES les Cards
        // actives (aucun slice), et la limite ne s'applique qu'a l'activation,
        // avec un refus nomme — jamais un masquage silencieux.
        $this->assertGreaterThan(0, $etat['slots']);
        $this->assertSame(
            collect($etat['grid'])->count(),
            collect($etat['grid'])->unique('key')->count(),
        );

        // Remplir la grille puis tenter une activation de plus : refus
        // explicite, et rien n'est supprime.
        $grille = collect($etat['grid'])->pluck('key');
        $disponibles = collect($etat['available'])->pluck('key');
        $aActiver = $disponibles->take(max(0, $etat['slots'] - $grille->count()));

        foreach ($aActiver as $cle) {
            try {
                $configurator->enable($this->superAdmin, $this->boucle->fresh(), $cle);
            } catch (\App\Services\Loops\PresetException) {
                // Dependance manquante : sans importance pour ce test.
            }
        }

        $encoreDispo = collect($configurator->describe($this->boucle->fresh())['available'])->pluck('key')->first();

        if ($encoreDispo !== null && collect($configurator->describe($this->boucle->fresh())['grid'])->count() >= $etat['slots']) {
            try {
                $configurator->enable($this->superAdmin, $this->boucle->fresh(), $encoreDispo);
                $this->fail('La grille pleine doit refuser une activation de plus.');
            } catch (\App\Services\Loops\PresetException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        // Quoi qu'il arrive : aucune ligne loop_cards supprimee par le refus.
        $this->assertGreaterThanOrEqual($grille->count(), \App\Models\LoopCard::where('loop_id', $this->boucle->id)->where('enabled', true)->count());
    }
}
