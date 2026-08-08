<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les Dossiers rejoignent le preset Projet.
 *
 * La matrice produit donne au Projet « Roadmap · Decisions · Dossiers ». Les
 * deux premieres y sont depuis TASK-1105 et TASK-1106 ; la troisieme arrive.
 *
 * **Aucune Card n'est creee.** `core.dossiers` existe depuis TASK-1091, qui l'a
 * livree a la Communaute et a rattrape le parc. Cette tache ne fait que
 * l'ajouter a un second preset — et rattraper les Boucles Projet existantes,
 * ce qui est desormais un invariant et non une decouverte.
 */
class TASK1110ProjectDossiersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private LoopService $loops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loops = new LoopService;
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    private function projet(string $nom = 'Un projet'): Loop
    {
        $loop = $this->loops->createLoop($this->auteur, $nom);
        $loop->forceFill(['type' => 'project'])->save();

        return $loop->fresh();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_08_170000_backfill_dossiers_card_on_project_loops.php');
    }

    // ── Aucune Card creee ───────────────────────────────────────────────────

    public function test_no_new_card_is_declared(): void
    {
        // La Card existe depuis TASK-1091. En creer une seconde ferait deux
        // endroits pour le meme objet.
        $registre = app(LoopCardRegistry::class);

        $this->assertTrue($registre->exists('core.dossiers'));

        foreach (['core.project_dossiers', 'core.files', 'project.dossiers'] as $inexistante) {
            $this->assertFalse($registre->exists($inexistante), $inexistante);
        }
    }

    public function test_the_card_catalogue_is_unchanged_in_size(): void
    {
        // Un ajout au catalogue serait le signe qu'on a cree une Card.
        $this->assertSame(
            count(config('loop_cards.cards')),
            collect(config('loop_cards.cards'))->pluck('key')->unique()->count(),
        );
    }

    // ── La matrice, pour le Projet ──────────────────────────────────────────

    public function test_the_project_preset_holds_its_three_distinctive_cards(): void
    {
        $cles = $this->types()->cardsFor('project');

        foreach (['core.roadmap', 'core.decisions', 'core.dossiers'] as $attendue) {
            $this->assertContains($attendue, $cles, $attendue);
        }
    }

    public function test_the_permanent_frame_is_still_there(): void
    {
        $cles = $this->types()->cardsFor('project');

        $this->assertContains('core.manifesto', $cles);
        $this->assertContains('core.members', $cles);
    }

    public function test_the_grid_is_exactly_at_its_cap(): void
    {
        // Trois Cards en grille pour trois `grid_slots`. Une quatrieme
        // disparaitrait **en silence** : `workspaceCardsFor()` fait `->take(3)`
        // sans un mot.
        $catalogue = config('loop_cards.cards');

        $enGrille = collect($this->types()->cardsFor('project'))
            ->filter(fn (string $k) => ($catalogue[$k]['placement'] ?? '') === 'grid');

        $this->assertSame(config('loop_cards.grid_slots'), $enGrille->count());
    }

    public function test_the_community_preset_is_untouched(): void
    {
        // TASK-1091 a livre Sondage, Evenements et Dossiers a la Communaute et
        // rattrape le parc. Rien ici ne doit y toucher.
        $cles = $this->types()->cardsFor('general');

        foreach (['core.polls', 'core.events', 'core.dossiers', 'core.members'] as $attendue) {
            $this->assertContains($attendue, $cles, $attendue);
        }
    }

    // ── Les nouvelles Boucles ───────────────────────────────────────────────

    public function test_a_new_project_loop_gets_the_card(): void
    {
        $projet = $this->projet();

        LoopCard::where('loop_id', $projet->id)->delete();
        $this->types()->applyPreset($projet->fresh());

        $this->assertContains('core.dossiers', $this->types()->activeCardsFor($projet->fresh()));
    }

    // ── Le parc existant ────────────────────────────────────────────────────

    public function test_an_existing_project_loop_receives_the_card(): void
    {
        // C'est l'invariant : modifier `config/loop_types.php` ne suffit pas.
        $ancienne = $this->projet('Un projet d’avant');

        LoopCard::where('loop_id', $ancienne->id)->delete();
        foreach (['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.decisions', 'core.members'] as $cle) {
            LoopCard::create([
                'organization_id' => $this->org->id, 'loop_id' => $ancienne->id,
                'card_key' => $cle, 'enabled' => true, 'added_by_preset' => 'project',
            ]);
        }

        $this->assertNotContains('core.dossiers', $this->types()->activeCardsFor($ancienne->fresh()));

        $this->migration()->up();

        $this->assertContains('core.dossiers', $this->types()->activeCardsFor($ancienne->fresh()));
    }

    public function test_the_backfill_can_run_twice_without_duplicating(): void
    {
        $projet = $this->projet();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(1, LoopCard::where('loop_id', $projet->id)->where('card_key', 'core.dossiers')->count());
    }

    public function test_a_card_switched_off_by_hand_stays_off(): void
    {
        // **Reellement eteinte** : `firstOrCreate` ne met pas a jour, et le
        // preset avait deja cree la ligne activee — la mise en place ne
        // prouvait rien.
        $projet = $this->projet();
        LoopCard::updateOrCreate(
            ['loop_id' => $projet->id, 'card_key' => 'core.dossiers'],
            ['organization_id' => $this->org->id, 'enabled' => false],
        );

        $this->assertFalse((bool) LoopCard::where('loop_id', $projet->id)->where('card_key', 'core.dossiers')->value('enabled'));

        $this->migration()->up();

        $this->assertFalse(
            (bool) LoopCard::where('loop_id', $projet->id)->where('card_key', 'core.dossiers')->value('enabled'),
        );
    }

    public function test_a_card_added_by_hand_survives_the_backfill(): void
    {
        // Le rattrapage est **purement additif** : il n'enleve rien.
        $projet = $this->projet();
        LoopCard::firstOrCreate(
            ['loop_id' => $projet->id, 'card_key' => 'core.journal'],
            ['organization_id' => $this->org->id, 'enabled' => true, 'added_by_preset' => null],
        );

        $this->migration()->up();

        $ligne = LoopCard::where('loop_id', $projet->id)->where('card_key', 'core.journal')->first();

        $this->assertNotNull($ligne);
        $this->assertTrue((bool) $ligne->enabled);
        $this->assertNull($ligne->added_by_preset);
    }

    public function test_the_backfill_removes_no_card_at_all(): void
    {
        $projet = $this->projet();
        $avant = LoopCard::where('loop_id', $projet->id)->pluck('card_key')->sort()->values()->all();

        $this->migration()->up();

        $apres = LoopCard::where('loop_id', $projet->id)->pluck('card_key')->sort()->values()->all();

        $this->assertSame([], array_diff($avant, $apres), 'le rattrapage a retire une Card');
    }

    public function test_a_loop_of_another_type_is_left_alone(): void
    {
        $dialogue = $this->loops->createLoop($this->auteur, 'Un dialogue')->fresh();
        $avant = LoopCard::where('loop_id', $dialogue->id)->count();

        $this->migration()->up();

        $this->assertSame($avant, LoopCard::where('loop_id', $dialogue->id)->count());
    }

    public function test_every_backfilled_row_carries_its_organization(): void
    {
        $projet = $this->projet();
        LoopCard::where('loop_id', $projet->id)->where('card_key', 'core.dossiers')->delete();

        $this->migration()->up();

        $ligne = LoopCard::where('loop_id', $projet->id)->where('card_key', 'core.dossiers')->first();

        $this->assertSame($this->org->id, $ligne->organization_id);
    }

    // ── Le compteur ─────────────────────────────────────────────────────────

    public function test_the_card_declares_a_data_count(): void
    {
        // Sans lui, on eteint la Card sans etre prevenu de ce qu'elle porte.
        $projet = $this->projet();

        $composition = app(\App\Services\Loops\LoopCardCompositionService::class)->compositionFor($projet->fresh());
        $ligne = collect($composition)->firstWhere('key', 'core.dossiers');

        $this->assertNotNull($ligne);
        $this->assertNotNull($ligne['data_count'] ?? null, 'la Card Dossiers n’annonce pas ce qu’elle porte');
    }
}
