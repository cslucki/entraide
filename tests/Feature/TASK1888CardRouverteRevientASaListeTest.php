<?php

namespace Tests\Feature;

use App\Livewire\Concerns\RevientASaListe;
use App\Livewire\LoopAssignmentsCard;
use App\Livewire\LoopCourseMaterialCard;
use App\Livewire\LoopDecisionsCard;
use App\Livewire\LoopEventsCard;
use App\Livewire\LoopJournalCard;
use App\Livewire\LoopMarketplaceCard;
use App\Livewire\LoopPollsCard;
use App\Livewire\LoopQuizCard;
use App\Livewire\LoopRoadmapCard;
use App\Livewire\LoopWatchCard;
use Livewire\Attributes\On;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1888 — une Card rouverte depuis le MENU revient a sa liste.
 *
 * Les Cards sont montees une fois et seulement masquees (`x-show`) : un
 * formulaire laisse ouvert le restait a la reouverture. Chaque Card qui porte
 * un formulaire ou un detail ecoute `loop-card-opened-from-menu` pour SA cle.
 *
 * Les dix Cards sont mesurees sur leur ETAT, composant par composant : la
 * Veille l'est en plus de bout en bout, rendu compris, dans
 * TASK1879VeillePluginTest.
 */
class TASK1888CardRouverteRevientASaListeTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: array<string, mixed>}>
     */
    public static function cards(): array
    {
        return [
            'veille' => [LoopWatchCard::class, 'plugin.veille', ['showForm' => true, 'editingId' => 'x', 'subject' => 'brouillon', 'showCategories' => true, 'confirmingDeleteId' => 'x']],
            'roadmap' => [LoopRoadmapCard::class, 'core.roadmap', ['editingId' => 'x', 'detailId' => 'x', 'editingMessageId' => 'x']],
            'sondages' => [LoopPollsCard::class, 'core.polls', ['showForm' => true, 'editingId' => 'x', 'confirmingDeleteId' => 'x']],
            'decisions' => [LoopDecisionsCard::class, 'core.decisions', ['showForm' => true, 'editingId' => 'x', 'showPicker' => true]],
            'journal' => [LoopJournalCard::class, 'core.journal', ['showForm' => true, 'editingId' => 'x']],
            'evenements' => [LoopEventsCard::class, 'core.events', ['showForm' => true, 'editingId' => 'x', 'selectedEventId' => 'x', 'confirmingDeleteId' => 'x']],
            'devoirs' => [LoopAssignmentsCard::class, 'training.assignments', ['showForm' => true, 'editingId' => 'x', 'openAssignmentId' => 'x']],
            'quiz' => [LoopQuizCard::class, 'training.quiz', ['showForm' => true, 'editingId' => 'x']],
            'supports' => [LoopCourseMaterialCard::class, 'training.course_material', ['showModuleForm' => true, 'openSequenceFormFor' => 'x', 'editingModuleId' => 'x']],
            'marketplace' => [LoopMarketplaceCard::class, 'core.marketplace', ['editingId' => 'x']],
        ];
    }

    /** @param array<string, mixed> $ouvert */
    #[DataProvider('cards')]
    public function test_rouverte_depuis_le_menu_la_card_ferme_formulaire_et_detail(string $classe, string $cle, array $ouvert): void
    {
        $card = $this->ouverte($classe, $ouvert);

        $card->reouvertureDepuisLeMenu($cle);

        $vierge = new $classe;
        foreach (array_keys($ouvert) as $propriete) {
            $this->assertSame($vierge->{$propriete}, $card->{$propriete}, "{$cle} : `{$propriete}` reste ouvert.");
        }
    }

    /** @param array<string, mixed> $ouvert */
    #[DataProvider('cards')]
    public function test_l_ouverture_d_une_autre_card_ne_touche_a_rien(string $classe, string $cle, array $ouvert): void
    {
        $card = $this->ouverte($classe, $ouvert);

        $card->reouvertureDepuisLeMenu('core.dossiers');

        foreach ($ouvert as $propriete => $valeur) {
            $this->assertSame($valeur, $card->{$propriete});
        }
    }

    /**
     * L'ecoute est bien branchee : sans `#[On]`, Livewire n'appellerait rien.
     *
     * @param  array<string, mixed>  $ouvert
     */
    #[DataProvider('cards')]
    public function test_la_card_ecoute_l_evenement_du_menu(string $classe, string $cle, array $ouvert): void
    {
        $this->assertContains(RevientASaListe::class, class_uses($classe));

        $attributs = (new \ReflectionMethod($classe, 'reouvertureDepuisLeMenu'))->getAttributes(On::class);
        $this->assertCount(1, $attributs);
        $this->assertSame('loop-card-opened-from-menu', $attributs[0]->newInstance()->event);
    }

    /** @param array<string, mixed> $ouvert */
    private function ouverte(string $classe, array $ouvert): object
    {
        $card = new $classe;
        foreach ($ouvert as $propriete => $valeur) {
            $card->{$propriete} = $valeur;
        }

        return $card;
    }
}
