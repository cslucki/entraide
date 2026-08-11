<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopPoll;
use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Outils **actifs** et outils **principaux** sont deux choses distinctes.
 *
 * Avant TASK-1124, « maximum 3 » voulait dire trois outils actifs : le
 * configurateur refusait le quatrieme, et le workspace coupait sa barre a
 * trois — la 4e Card etait active en base, ses donnees vivantes, et
 * introuvable dans la Boucle. Desormais une Boucle a N outils actifs, dont
 * au plus trois mis en avant.
 *
 * La bascule derive -> explicite est le point delicat : une Boucle qui n'a
 * jamais choisi garde ses trois premiers actifs comme principaux (mode
 * derive) ; des qu'un rang est pose, ce sont les rangs qui font foi.
 */
class TASK1124PrimaryToolsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        // Organization non par defaut, comme l'exige le mandat.
        $this->orgA = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi', 'is_default' => false,
        ]);
        $this->orgB = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi', 'is_default' => false,
        ]);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);

        app()->instance('current_organization', $this->orgA);

        $this->boucle = (new LoopService)->createLoop(
            User::factory()->create(['organization_id' => $this->orgA->id]),
            'Boucle Outils QA',
        )->fresh();
    }

    private function composition(): LoopCardCompositionService
    {
        return app(LoopCardCompositionService::class);
    }

    private function registry(): LoopCardRegistry
    {
        return app(LoopCardRegistry::class);
    }

    private function configurator(): LoopPresetConfigurator
    {
        return app(LoopPresetConfigurator::class);
    }

    /** Active des outils de grille jusqu'a en avoir au moins $cible. */
    private function activerJusqua(int $cible): array
    {
        foreach ($this->registry()->gridKeys() as $cle) {
            $actifs = $this->registry()->activeGridKeysFor($this->boucle->fresh());

            if (count($actifs) >= $cible) {
                break;
            }

            $blocages = $this->registry()->blockersFor($cle, $actifs);

            if (in_array($cle, $actifs, true) || $blocages['missing'] !== [] || $blocages['conflicting'] !== []) {
                continue;
            }

            $this->composition()->enable($this->boucle->fresh(), $cle);
        }

        return $this->registry()->activeGridKeysFor($this->boucle->fresh());
    }

    // ── Actif != principal ──────────────────────────────────────────────────

    public function test_a_loop_can_have_more_active_tools_than_primary_ones(): void
    {
        $actifs = $this->activerJusqua(5);

        $this->assertGreaterThanOrEqual(5, count($actifs), 'Le catalogue doit permettre 5 outils actifs.');

        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());
        $secondaires = $this->composition()->secondaryKeysFor($this->boucle->fresh());

        $this->assertCount(LoopCardCompositionService::MAX_PRIMARY, $principaux);
        $this->assertGreaterThanOrEqual(2, count($secondaires));

        // Les deux groupes recouvrent exactement les actifs : rien ne tombe
        // entre les deux, rien n'est compte deux fois.
        $this->assertSame(
            collect($actifs)->sort()->values()->all(),
            collect($principaux)->merge($secondaires)->sort()->values()->all(),
        );
    }

    public function test_activating_a_fourth_tool_is_never_refused_for_being_a_fourth(): void
    {
        $this->activerJusqua(3);
        $avant = $this->registry()->activeGridKeysFor($this->boucle->fresh());

        $suivant = collect($this->registry()->gridKeys())
            ->reject(fn ($k) => in_array($k, $avant, true))
            ->first(fn ($k) => $this->registry()->blockersFor($k, $avant)['missing'] === []
                && $this->registry()->blockersFor($k, $avant)['conflicting'] === []);

        $this->assertNotNull($suivant);

        $this->configurator()->enable($this->superAdmin, $this->boucle->fresh(), $suivant);

        $apres = $this->registry()->activeGridKeysFor($this->boucle->fresh());

        $this->assertContains($suivant, $apres);
        // Aucun outil desactive pour lui faire de la place.
        foreach ($avant as $cle) {
            $this->assertContains($cle, $apres);
        }
    }

    // ── Le mode derive, et sa bascule vers l'explicite ──────────────────────

    public function test_a_loop_that_never_chose_derives_its_primary_tools(): void
    {
        $actifs = $this->activerJusqua(5);

        // Aucun rang en base : mode derive.
        $this->assertSame(0, LoopCard::where('loop_id', $this->boucle->id)->whereNotNull('primary_rank')->count());

        $this->assertSame(
            array_slice($actifs, 0, LoopCardCompositionService::MAX_PRIMARY),
            $this->composition()->primaryKeysFor($this->boucle->fresh()),
        );
    }

    public function test_the_first_explicit_choice_materialises_the_derived_state(): void
    {
        $this->activerJusqua(5);

        $derives = $this->composition()->primaryKeysFor($this->boucle->fresh());
        $aPromouvoir = $this->composition()->secondaryKeysFor($this->boucle->fresh())[0];

        // Retrograder l'un des derives, puis promouvoir un secondaire : la
        // Boucle bascule en mode explicite sans perdre les deux autres. Sans
        // la materialisation, poser un seul rang aurait fait disparaitre les
        // principaux derives — le piege signale par l'arbitrage.
        $this->composition()->demote($this->boucle->fresh(), $derives[2]);

        $apresRetrait = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->assertSame([$derives[0], $derives[1]], $apresRetrait);
        $this->assertGreaterThan(0, LoopCard::where('loop_id', $this->boucle->id)->whereNotNull('primary_rank')->count());

        $this->composition()->promote($this->boucle->fresh(), $aPromouvoir);

        $final = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->assertSame([$derives[0], $derives[1], $aPromouvoir], $final);
        // Et le retrograde reste **actif**, dans les autres outils.
        $this->assertContains($derives[2], $this->composition()->secondaryKeysFor($this->boucle->fresh()));
    }

    public function test_promoting_when_three_are_featured_refuses_loudly(): void
    {
        $this->activerJusqua(5);

        $secondaire = $this->composition()->secondaryKeysFor($this->boucle->fresh())[0];
        $principauxAvant = $this->composition()->primaryKeysFor($this->boucle->fresh());

        try {
            $this->composition()->promote($this->boucle->fresh(), $secondaire);
            $this->fail('Promouvoir un quatrieme outil principal doit etre refuse.');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // Jamais de remplacement silencieux.
        $this->assertSame($principauxAvant, $this->composition()->primaryKeysFor($this->boucle->fresh()));
    }

    public function test_the_last_featured_tool_cannot_be_removed(): void
    {
        $this->activerJusqua(5);

        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->composition()->demote($this->boucle->fresh(), $principaux[2]);
        $this->composition()->demote($this->boucle->fresh(), $principaux[1]);

        // Le dernier ne peut pas partir : une liste vide ferait retomber la
        // Boucle en mode derive, qui le remettrait aussitot — un geste sans
        // effet, pire qu'un refus.
        $this->expectException(\RuntimeException::class);
        $this->composition()->demote($this->boucle->fresh(), $principaux[0]);
    }

    // ── Non-destruction (invariant TASK-1123, etendu) ───────────────────────

    public function test_featuring_and_unfeaturing_never_touches_data_or_activation(): void
    {
        $this->activerJusqua(5);
        $this->composition()->enable($this->boucle->fresh(), 'core.polls');

        $poll = LoopPoll::create([
            'organization_id' => $this->orgA->id,
            'loop_id' => $this->boucle->id,
            'created_by' => $this->superAdmin->id,
            'question' => 'Question QA 1124 ?',
            'selection_type' => LoopPoll::TYPE_SINGLE,
            'status' => 'open',
        ]);

        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());

        // Principal -> secondaire : toujours actif, donnee intacte.
        $this->composition()->demote($this->boucle->fresh(), $principaux[0]);

        $this->assertContains($principaux[0], $this->registry()->activeGridKeysFor($this->boucle->fresh()));
        $this->assertTrue(LoopCard::where('loop_id', $this->boucle->id)->where('card_key', $principaux[0])->value('enabled'));
        $this->assertDatabaseHas('loop_polls', ['id' => $poll->id]);

        // Secondaire -> principal : meme donnee.
        $this->composition()->promote($this->boucle->fresh(), $principaux[0]);

        $this->assertContains($principaux[0], $this->composition()->primaryKeysFor($this->boucle->fresh()));
        $this->assertSame($poll->id, LoopPoll::where('loop_id', $this->boucle->id)->value('id'));
    }

    public function test_disabling_a_featured_tool_keeps_its_data_and_frees_the_slot(): void
    {
        $this->activerJusqua(5);
        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->composition()->disable($this->boucle->fresh(), $principaux[0]);

        // Il quitte les principaux sans qu'on ait eu a nettoyer son rang…
        $this->assertNotContains($principaux[0], $this->composition()->primaryKeysFor($this->boucle->fresh()));
        // …et sa ligne survit, prete pour la reactivation (TASK-1123).
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $this->boucle->id, 'card_key' => $principaux[0], 'enabled' => false,
        ]);

        $this->composition()->enable($this->boucle->fresh(), $principaux[0]);
        $this->assertContains($principaux[0], $this->registry()->activeGridKeysFor($this->boucle->fresh()));
    }

    // ── Isolation ───────────────────────────────────────────────────────────

    public function test_featuring_in_one_loop_changes_nothing_else(): void
    {
        $this->activerJusqua(5);

        $temoin = (new LoopService)->createLoop(
            User::factory()->create(['organization_id' => $this->orgA->id]),
            'Boucle Temoin',
        )->fresh();

        $typeAvant = $this->boucle->type;
        $reglagesAvant = LoopTypeSetting::query()->count();
        $temoinAvant = $this->composition()->primaryKeysFor($temoin);

        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());
        $this->composition()->demote($this->boucle->fresh(), $principaux[2]);

        $this->assertSame($typeAvant, $this->boucle->fresh()->type);
        $this->assertSame($reglagesAvant, LoopTypeSetting::query()->count());
        $this->assertSame($temoinAvant, $this->composition()->primaryKeysFor($temoin->fresh()));
        // Le catalogue n'a pas bouge non plus.
        $this->assertSame(0, LoopCard::where('loop_id', $temoin->id)->whereNotNull('primary_rank')->count());
    }

    public function test_an_archived_loop_refuses_featuring_gestures(): void
    {
        $this->activerJusqua(5);
        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->boucle->forceFill(['status' => 'archived'])->save();

        $this->actingAs($this->superAdmin)->post(route('admin.loops.compose', $this->boucle), [
            'action' => 'demote', 'card_key' => $principaux[0],
        ])->assertSessionHas('error');

        $this->assertSame($principaux, $this->composition()->primaryKeysFor($this->boucle->fresh()));
    }

    public function test_a_neighbour_organization_loop_is_never_reachable(): void
    {
        app()->instance('current_organization', $this->orgB);
        $boucleB = (new LoopService)->createLoop(
            User::factory()->create(['organization_id' => $this->orgB->id]),
            'Boucle B Candidat',
        )->fresh();
        app()->instance('current_organization', $this->orgA);

        $adminOrgA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->orgA->forceFill(['admin_id' => $adminOrgA->id])->save();

        $this->actingAs($adminOrgA)->post(route('organization.admin.loops.compose', [
            'organization' => $this->orgA->slug, 'loop' => $boucleB->id,
        ]), ['action' => 'demote', 'card_key' => 'core.polls'])->assertNotFound();
    }

    // ── Les deux gestes par la route admin ──────────────────────────────────

    public function test_the_admin_route_features_and_unfeatures(): void
    {
        $this->activerJusqua(5);
        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());
        $secondaire = $this->composition()->secondaryKeysFor($this->boucle->fresh())[0];

        $this->actingAs($this->superAdmin)->post(route('admin.loops.compose', $this->boucle), [
            'action' => 'demote', 'card_key' => $principaux[2],
        ])->assertSessionHas('success');

        $this->actingAs($this->superAdmin)->post(route('admin.loops.compose', $this->boucle), [
            'action' => 'promote', 'card_key' => $secondaire,
        ])->assertSessionHas('success');

        $final = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->assertContains($secondaire, $final);
        $this->assertNotContains($principaux[2], $final);
        // Le retrograde reste actif : retirer des principaux n'eteint rien.
        $this->assertContains($principaux[2], $this->registry()->activeGridKeysFor($this->boucle->fresh()));
    }

    public function test_the_configurator_screen_speaks_of_tools_not_slots(): void
    {
        $this->withSession(['locale' => 'fr']);
        $this->activerJusqua(5);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $this->boucle))
            ->assertOk()
            ->assertSee(__('loops.tools_primary_title'))
            ->assertSee(__('loops.tools_secondary_title'))
            ->assertSee(__('loops.tools_promote'))
            // Le vieux vocabulaire a disparu de cet ecran.
            ->assertDontSee('Cards distinctives');
    }

    public function test_the_workspace_hides_none_of_the_active_tools(): void
    {
        $this->withSession(['locale' => 'fr']);
        $actifs = $this->activerJusqua(5);

        $membre = User::factory()->create(['organization_id' => $this->orgA->id]);
        (new LoopService)->addMemberByUserId($this->boucle, $membre->id);

        $reponse = $this->actingAs($membre)
            ->get(route('organization.loops.show', [
                'organization' => $this->orgA->slug, 'loop' => $this->boucle->id,
            ]))
            ->assertOk();

        // **TASK-1128** : la barre montre cinq outils directement. Avec cinq
        // actifs, il n'y a plus rien a faire deborder — donc plus de groupe
        // « Autres outils ». Ce test gardait la presence du groupe ; il garde
        // desormais ce qu'il protegeait vraiment : **aucun outil actif n'est
        // masque**. C'est mieux servi qu'avant, pas moins.
        $reponse->assertDontSee(__('loops.tools_others_title'));

        $registry = $this->registry();
        $rendus = $registry->primaryWorkspaceCardsFor($this->boucle->fresh(), $membre)
            ->merge($registry->secondaryWorkspaceCardsFor($this->boucle->fresh(), $membre))
            ->pluck('key')->sort()->values()->all();

        $this->assertSame(collect($actifs)->sort()->values()->all(), $rendus);

        // Et ils sont tous atteignables a l'ecran, sans deplier quoi que ce soit.
        foreach ($rendus as $cle) {
            $reponse->assertSee($registry->labelFor($this->boucle->fresh(), $cle));
        }
    }
}
