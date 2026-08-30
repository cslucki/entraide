<?php

namespace Tests\Feature;

use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le type Formation est offert.
 *
 * La condition posee dans `config/loop_types.php` depuis l'origine — livrer
 * plutot que promettre — est remplie : le type embarque les trois Cards que la
 * matrice canonique lui prevoit.
 *
 * Ces tests verifient que l'ouverture **fonctionne**, et pas seulement qu'un
 * booleen a change : une Formation creee par le parcours normal doit composer
 * son preset complet.
 */
class TASK1101TrainingAvailableTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function registry(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    public function test_the_training_type_is_now_offered(): void
    {
        $this->assertTrue($this->registry()->isAvailable('training'));
        $this->assertContains('training', $this->registry()->availableKeys());
    }

    public function test_a_type_without_its_cards_stays_withheld(): void
    {
        // L'ouverture de la Formation n'ouvre pas les autres : `peer_support`
        // reste retenu, faute de Cards de pair-aidance. La regle n'a pas
        // change, seul son verdict sur un type a change.
        $this->assertFalse($this->registry()->isAvailable('peer_support'));
    }

    public function test_a_training_loop_can_be_created_through_the_normal_path(): void
    {
        // `createLoop()` refusait un type indisponible : les Boucles Formation
        // des taches precedentes devaient forcer leur type apres coup. Plus
        // maintenant.
        $loop = (new LoopService)->createLoop($this->auteur, 'Ma Formation', type: 'training');

        $this->assertSame('training', $loop->fresh()->type);
    }

    public function test_a_new_training_loop_composes_its_three_cards(): void
    {
        $loop = (new LoopService)->createLoop($this->auteur, 'Ma Formation', type: 'training');

        $cles = $this->registry()->activeCardsFor($loop->fresh());

        // La matrice canonique : le cadre permanent, plus les trois Cards
        // pedagogiques. Ouvrir un type dont le preset ne se composerait pas
        // serait pire que de le laisser ferme. Depuis TASK-1332, le Resume IA
        // rejoint ce socle (le Manifeste n'y est plus impose par defaut).
        $this->assertContains('core.ai_summary', $cles);
        $this->assertContains('core.members', $cles);
        $this->assertContains('training.course_material', $cles);
        $this->assertContains('training.progression', $cles);
        $this->assertContains('training.assignments', $cles);

        // Et les lignes sont bien ecrites, pas seulement deduites du preset.
        $this->assertSame(
            count($this->registry()->cardsFor('training')),
            LoopCard::where('loop_id', $loop->id)->where('enabled', true)->count(),
        );
    }

    public function test_the_quiz_is_not_part_of_the_preset(): void
    {
        // Le troisieme emplacement accepte Travaux **ou** QCM. Le preset livre
        // les Travaux ; le QCM existe depuis TASK-1102 et s'active localement.
        // L'ouverture du type n'impose donc aucun des deux.
        $this->assertNotContains('training.quiz', $this->registry()->cardsFor('training'));
        $this->assertTrue(app(LoopCardRegistry::class)->exists('training.quiz'));
    }

    public function test_an_existing_training_loop_gains_the_missing_cards(): void
    {
        // Les Boucles Formation creees avant ces livraisons portent des lignes
        // `loop_cards` explicites, et `activeCardsFor()` les lit sans jamais
        // retomber sur le preset : elles n'auraient donc **jamais** vu les
        // nouvelles Cards. `applyPreset()` est additif — il ajoute ce qui
        // manque sans retirer ce qui reste.
        $loop = (new LoopService)->createLoop($this->auteur, 'Formation ancienne', type: 'training');
        LoopCard::where('loop_id', $loop->id)
            ->whereIn('card_key', ['training.progression', 'training.assignments'])
            ->delete();

        $avant = $this->registry()->activeCardsFor($loop->fresh());
        $this->assertNotContains('training.assignments', $avant);

        $ajoutees = $this->registry()->applyPreset($loop->fresh());

        $this->assertEqualsCanonicalizing(
            ['training.progression', 'training.assignments'],
            $ajoutees,
        );
        $this->assertContains('training.assignments', $this->registry()->activeCardsFor($loop->fresh()));
        // Ce qui etait deja la n'a pas bouge.
        $this->assertContains('core.ai_summary', $this->registry()->activeCardsFor($loop->fresh()));
    }
}
