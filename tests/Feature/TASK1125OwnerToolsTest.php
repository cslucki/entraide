<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopPoll;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Personnaliser ma Boucle » — l'ecran du proprietaire.
 *
 * Le modele vient de TASK-1124 et n'est pas rediscute : cet ecran le rend en
 * langage humain. Ce qui se teste ici est donc l'acces (qui voit l'entree,
 * qui passe la porte), la traduction des prerequis, et le fait qu'aucun geste
 * ne detruit de donnee.
 */
class TASK1125OwnerToolsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgAutorisee;

    private Organization $orgVerrouillee;

    private Organization $orgVoisine;

    private User $proprietaire;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        // Organization non par defaut, et qui autorise le proprietaire.
        $this->orgAutorisee = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi', 'is_default' => false,
            'loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED,
        ]);
        // Verrouillee : c'est le defaut du produit.
        $this->orgVerrouillee = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi', 'is_default' => false,
            'loop_composition_policy' => Organization::COMPOSITION_LOCKED,
        ]);
        $this->orgVoisine = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi', 'is_default' => false,
            'loop_composition_policy' => Organization::COMPOSITION_OWNER_ALLOWED,
        ]);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->orgAutorisee->id]);

        app()->instance('current_organization', $this->orgAutorisee);

        $this->boucle = (new LoopService)->createLoop($this->proprietaire, 'Boucle Outils Proprietaire')->fresh();
    }

    private function composition(): LoopCardCompositionService
    {
        return app(LoopCardCompositionService::class);
    }

    private function ecran(?User $acteur = null, ?Loop $loop = null, ?Organization $org = null): \Illuminate\Testing\TestResponse
    {
        $loop ??= $this->boucle;
        $org ??= $this->orgAutorisee;

        return $this->actingAs($acteur ?? $this->proprietaire)->get(route('organization.loops.tools', [
            'organization' => $org->slug, 'loop' => $loop->id,
        ]));
    }

    private function geste(string $action, string $outil, ?User $acteur = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($acteur ?? $this->proprietaire)->post(route('organization.loops.tools.update', [
            'organization' => $this->orgAutorisee->slug, 'loop' => $this->boucle->id,
        ]), ['action' => $action, 'tool' => $outil]);
    }

    // ── Qui entre, qui n'entre pas ──────────────────────────────────────────

    public function test_an_authorised_owner_reaches_the_screen(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->ecran()
            ->assertOk()
            ->assertSee(__('loops.owner_tools_title'))
            ->assertSee(__('loops.owner_tools_mine_title')) // TASK-1127 : sections fusionnees en « Mes outils »
            ->assertSee(__('loops.owner_tools_add_title'));
    }

    public function test_a_locked_organization_closes_the_door(): void
    {
        app()->instance('current_organization', $this->orgVerrouillee);
        $proprietaireB = User::factory()->create(['organization_id' => $this->orgVerrouillee->id]);
        $boucleB = (new LoopService)->createLoop($proprietaireB, 'Boucle Verrouillee')->fresh();

        // La porte est celle de l'Organization, pas celle de la Boucle.
        $this->actingAs($proprietaireB)->get(route('organization.loops.tools', [
            'organization' => $this->orgVerrouillee->slug, 'loop' => $boucleB->id,
        ]))->assertForbidden();
    }

    public function test_a_simple_member_never_reaches_it(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->orgAutorisee->id]);
        (new LoopService)->addMemberByUserId($this->boucle, $membre->id);

        $this->ecran($membre)->assertForbidden();
    }

    public function test_a_neighbour_organization_owner_is_a_candidate_and_is_refused(): void
    {
        app()->instance('current_organization', $this->orgVoisine);
        $proprietaireVoisin = User::factory()->create(['organization_id' => $this->orgVoisine->id]);
        // Reellement candidat : proprietaire, dans une Organization qui autorise.
        (new LoopService)->createLoop($proprietaireVoisin, 'Boucle Voisine')->fresh();
        app()->instance('current_organization', $this->orgAutorisee);

        $this->ecran($proprietaireVoisin)->assertNotFound();

        $this->actingAs($proprietaireVoisin)->post(route('organization.loops.tools.update', [
            'organization' => $this->orgAutorisee->slug, 'loop' => $this->boucle->id,
        ]), ['action' => 'disable', 'tool' => 'core.polls'])->assertNotFound();
    }

    public function test_an_archived_loop_is_read_only(): void
    {
        $this->boucle->forceFill(['status' => 'archived'])->save();

        $this->ecran()->assertForbidden();

        // Et la requete forgee est refusee : le service leve, le formulaire
        // renvoie l'explication — et rien n'a bouge.
        $this->geste('disable', 'core.polls')->assertSessionHas('error');

        $this->assertContains('core.polls', app(LoopCardRegistry::class)->activeGridKeysFor($this->boucle->fresh()));
    }

    // ── Le point d'entree suit la capacite ──────────────────────────────────

    public function test_the_workspace_offers_the_entry_only_when_allowed(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->actingAs($this->proprietaire)
            ->get(route('organization.loops.show', ['organization' => $this->orgAutorisee->slug, 'loop' => $this->boucle->id]))
            ->assertOk()
            ->assertSee(__('loops.owner_tools_action'));

        // Boucle archivee : plus d'entree.
        $this->boucle->forceFill(['status' => 'archived'])->save();

        $this->actingAs($this->proprietaire)
            ->get(route('organization.loops.show', ['organization' => $this->orgAutorisee->slug, 'loop' => $this->boucle->id]))
            ->assertOk()
            ->assertDontSee(__('loops.owner_tools_action'));
    }

    public function test_a_member_does_not_see_the_entry(): void
    {
        $this->withSession(['locale' => 'fr']);

        $membre = User::factory()->create(['organization_id' => $this->orgAutorisee->id]);
        (new LoopService)->addMemberByUserId($this->boucle, $membre->id);

        $this->actingAs($membre)
            ->get(route('organization.loops.show', ['organization' => $this->orgAutorisee->slug, 'loop' => $this->boucle->id]))
            ->assertOk()
            ->assertDontSee(__('loops.owner_tools_action'));
    }

    // ── Le langage : des outils, pas des cles ───────────────────────────────

    public function test_the_screen_speaks_human(): void
    {
        $this->withSession(['locale' => 'fr']);

        $reponse = $this->ecran()->assertOk();

        // Un nom et une phrase, pris au catalogue existant.
        $reponse->assertSee(__('loops.cards.polls.label'));
        $reponse->assertSee(__('loops.cards.polls.description'));

        // Et jamais le vocabulaire du moteur **a l'ecran**. Les cles voyagent
        // dans les `value` des formulaires — elles doivent bien identifier
        // l'outil au serveur — mais rien de tout cela ne doit s'afficher :
        // on retire les attributs avant de regarder.
        $visible = preg_replace('/(value|name|action|href|class|id)="[^"]*"/', '', $reponse->getContent());

        foreach (['primary_rank', 'grid_slots', 'core.polls', 'documentary', 'pedagogy', 'rhythm', 'slot'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $visible, "Le mot « {$interdit} » ne doit pas s'afficher.");
        }
    }

    public function test_a_prerequisite_is_explained_in_words(): void
    {
        $this->withSession(['locale' => 'fr']);

        // `training.progression` exige `training.course_material` : le
        // catalogue le dit en cles, l'ecran doit le dire en toutes lettres.
        $manquant = app(LoopCardRegistry::class)->requirementsOf('training.progression');

        $this->assertNotEmpty($manquant, 'Ce test suppose un outil a prerequis.');

        $reponse = $this->ecran()->assertOk();

        $reponse->assertSee(trans_choice('loops.owner_tools_requires', 1, [
            'tools' => __('loops.cards.'.str_replace('training.', '', $manquant[0]).'.label'),
        ]));
    }

    // ── Les gestes, et la non-destruction ───────────────────────────────────

    public function test_turning_a_tool_off_keeps_its_data_and_turning_it_back_on_finds_them(): void
    {
        $poll = LoopPoll::create([
            'organization_id' => $this->orgAutorisee->id,
            'loop_id' => $this->boucle->id,
            'created_by' => $this->proprietaire->id,
            'question' => 'Question QA 1125 ?',
            'selection_type' => LoopPoll::TYPE_SINGLE,
            'status' => 'open',
        ]);

        $this->geste('disable', 'core.polls')->assertSessionHas('success');

        $this->assertDatabaseHas('loop_polls', ['id' => $poll->id]);
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $this->boucle->id, 'card_key' => 'core.polls', 'enabled' => false,
        ]);

        $this->geste('enable', 'core.polls')->assertSessionHas('success');

        $this->assertContains('core.polls', app(LoopCardRegistry::class)->activeGridKeysFor($this->boucle->fresh()));
        $this->assertSame($poll->id, LoopPoll::where('loop_id', $this->boucle->id)->value('id'));
    }

    public function test_featuring_and_unfeaturing_never_turns_a_tool_off(): void
    {
        $principaux = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->geste('demote', $principaux[0])->assertSessionHas('success');

        $this->assertTrue(LoopCard::where('loop_id', $this->boucle->id)->where('card_key', $principaux[0])->value('enabled'));
        $this->assertContains($principaux[0], $this->composition()->secondaryKeysFor($this->boucle->fresh()));

        $this->geste('promote', $principaux[0])->assertSessionHas('success');

        $this->assertContains($principaux[0], $this->composition()->primaryKeysFor($this->boucle->fresh()));
    }

    public function test_featuring_a_fourth_tool_explains_instead_of_replacing(): void
    {
        // Le preset `general` donne exactement trois outils de grille : le
        // quatrieme, on l'ajoute — c'est justement le geste que TASK-1124 a
        // rendu possible, et la condition de ce test.
        $registry = app(LoopCardRegistry::class);
        $actifs = $registry->activeGridKeysFor($this->boucle->fresh());

        $aAjouter = collect($registry->gridKeys())
            ->reject(fn ($k) => in_array($k, $actifs, true))
            ->first(fn ($k) => $registry->blockersFor($k, $actifs)['missing'] === []
                && $registry->blockersFor($k, $actifs)['conflicting'] === []);

        $this->assertNotNull($aAjouter);

        $this->geste('enable', $aAjouter)->assertSessionHas('success');

        // Quatre outils actifs, trois mis en avant : il y a donc au moins un
        // secondaire. **Lequel** depend du mode : tant que la Boucle n'a rien
        // choisi, les principaux sont les trois premiers dans l'ordre du
        // catalogue — ajouter un outil qui y passe devant peut donc changer
        // la vitrine, sans jamais rien desactiver.
        $secondaires = $this->composition()->secondaryKeysFor($this->boucle->fresh());

        $this->assertNotEmpty($secondaires);
        $this->assertCount(4, app(LoopCardRegistry::class)->activeGridKeysFor($this->boucle->fresh()));

        $candidat = $secondaires[0];
        $avant = $this->composition()->primaryKeysFor($this->boucle->fresh());

        $this->geste('promote', $candidat)->assertSessionHas('error');

        // Jamais de remplacement silencieux — et l'outil reste actif.
        $this->assertSame($avant, $this->composition()->primaryKeysFor($this->boucle->fresh()));
        $this->assertContains($candidat, app(LoopCardRegistry::class)->activeGridKeysFor($this->boucle->fresh()));
    }

    public function test_a_required_tool_cannot_be_turned_off(): void
    {
        $requis = collect(app(LoopCardRegistry::class)->manageableKeys())
            ->first(fn ($k) => app(LoopCardRegistry::class)->isRequired($k));

        if ($requis === null) {
            $this->markTestSkipped('Aucun outil requis au catalogue.');
        }

        $this->geste('disable', $requis)->assertSessionHas('error');
    }

    public function test_an_unknown_tool_is_refused(): void
    {
        $this->geste('enable', 'outil.invente')->assertSessionHas('error');
    }
}
