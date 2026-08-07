<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le vocabulaire de la Roadmap, par preset.
 *
 * **Engagements et Suivi de coaching ne sont pas des Cards.** La spec produit
 * le dit sans ambiguite : « une seule Card technique. Les variantes entre
 * presets sont des presets de vocabulaire et de colonnes, pas des Cards
 * distinctes ».
 *
 * En creer une aurait fait un second systeme pour le meme objet, et une Card
 * hors matrice.
 *
 * Ces tests verifient les deux moities : le vocabulaire **change** selon le
 * type, et les **statuts en base** ne changent pas.
 */
class TASK1105RoadmapVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private LoopService $loops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loops = new LoopService;
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    private function boucle(string $type): Loop
    {
        $loop = $this->loops->createLoop($this->auteur, 'Une Boucle');
        $loop->forceFill(['type' => $type])->save();

        LoopCard::firstOrCreate(
            ['loop_id' => $loop->id, 'card_key' => 'core.roadmap'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );

        return $loop->fresh();
    }

    // ── Aucune Card nouvelle ────────────────────────────────────────────────

    public function test_no_separate_card_is_invented(): void
    {
        $registre = app(LoopCardRegistry::class);

        // La spec l'interdit, et la matrice canonique ne les contient pas.
        foreach (['core.commitments', 'core.engagements', 'core.coaching_followup', 'coaching.followup'] as $inexistante) {
            $this->assertFalse($registre->exists($inexistante), "{$inexistante} ne doit pas exister");
        }

        $this->assertTrue($registre->exists('core.roadmap'));
    }

    public function test_no_second_table_is_created(): void
    {
        foreach (['loop_commitments', 'loop_engagements', 'coaching_followups'] as $interdite) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable($interdite));
        }
    }

    // ── Le vocabulaire change ───────────────────────────────────────────────

    public function test_each_preset_gives_the_roadmap_its_own_name(): void
    {
        $this->assertSame('Roadmap', $this->types()->roadmapLabel('project'));
        $this->assertSame('Engagements', $this->types()->roadmapLabel('peer_support'));
        $this->assertSame('Suivi de coaching', $this->types()->roadmapLabel('coaching'));
    }

    public function test_a_type_without_a_declared_name_keeps_the_default(): void
    {
        // Un type qui ne declare rien retombe sur « Roadmap » : ajouter un
        // preset ne doit pas obliger a ecrire un vocabulaire.
        $this->assertSame('Roadmap', $this->types()->roadmapLabel('general'));
        $this->assertSame('Roadmap', $this->types()->roadmapLabel(null));
    }

    public function test_each_preset_names_its_three_columns(): void
    {
        $this->assertSame(
            ['todo' => 'À faire', 'in_progress' => 'En cours', 'done' => 'Fait'],
            $this->types()->roadmapColumnLabels('project'),
        );

        $this->assertSame(
            ['todo' => 'Pris', 'in_progress' => 'En cours', 'done' => 'Tenu'],
            $this->types()->roadmapColumnLabels('peer_support'),
        );

        $this->assertSame(
            ['todo' => 'À travailler', 'in_progress' => 'En cours', 'done' => 'Acquis'],
            $this->types()->roadmapColumnLabels('coaching'),
        );
    }

    public function test_the_three_columns_are_always_present_and_in_order(): void
    {
        foreach (['general', 'project', 'coaching', 'peer_support', 'training'] as $type) {
            $this->assertSame(
                [LoopRoadmapItem::STATUS_TODO, LoopRoadmapItem::STATUS_IN_PROGRESS, LoopRoadmapItem::STATUS_DONE],
                array_keys($this->types()->roadmapColumnLabels($type)),
                $type,
            );
        }
    }

    // ── Les statuts, eux, ne changent pas ───────────────────────────────────

    public function test_the_stored_statuses_never_change_with_the_vocabulary(): void
    {
        // C'est le point : renommer en base aurait fait **deux verites** sur le
        // meme etat, et un item deplace d'un preset a l'autre serait devenu
        // illisible.
        $engagements = $this->boucle('peer_support');

        $item = LoopRoadmapItem::create([
            'organization_id' => $this->org->id,
            'loop_id' => $engagements->id,
            'title' => 'Un engagement',
            'status' => LoopRoadmapItem::STATUS_DONE,
            'created_by' => $this->auteur->id,
        ]);

        $this->assertDatabaseHas('loop_roadmap_items', [
            'id' => $item->id,
            'status' => 'done',
        ]);

        // Le meme item, dans une Boucle Coaching, garde son statut et change de
        // mot.
        $item->forceFill(['loop_id' => $this->boucle('coaching')->id])->save();

        $this->assertSame('done', $item->fresh()->status);
        $this->assertSame('Acquis', $this->types()->roadmapColumnLabels('coaching')['done']);
    }

    // ── Ce que l'ecran montre ───────────────────────────────────────────────

    public function test_the_card_title_follows_the_preset(): void
    {
        $registre = app(LoopCardRegistry::class);

        $this->assertSame('Engagements', $registre->labelFor($this->boucle('peer_support'), 'core.roadmap'));
        $this->assertSame('Suivi de coaching', $registre->labelFor($this->boucle('coaching'), 'core.roadmap'));
        $this->assertSame('Roadmap', $registre->labelFor($this->boucle('project'), 'core.roadmap'));
    }

    public function test_another_card_keeps_its_name_whatever_the_preset(): void
    {
        // Seule la Roadmap a des variantes : les autres Cards gardent leur nom.
        $registre = app(LoopCardRegistry::class);

        $this->assertSame(
            $registre->labelFor($this->boucle('project'), 'core.members'),
            $registre->labelFor($this->boucle('coaching'), 'core.members'),
        );
    }

    public function test_the_columns_are_rendered_with_the_preset_words(): void
    {
        $engagements = $this->boucle('peer_support');

        Livewire::actingAs($this->auteur)
            ->test(\App\Livewire\LoopRoadmapCard::class, ['loop' => $engagements])
            ->assertSee('Pris')
            ->assertSee('Tenu')
            ->assertDontSee('À faire');
    }

    public function test_a_project_still_reads_its_own_words(): void
    {
        $projet = $this->boucle('project');

        Livewire::actingAs($this->auteur)
            ->test(\App\Livewire\LoopRoadmapCard::class, ['loop' => $projet])
            ->assertSee('À faire')
            ->assertDontSee('Tenu');
    }

    // ── Les presets qui l'attendaient ───────────────────────────────────────

    public function test_peer_support_now_holds_its_three_cards(): void
    {
        // La matrice donne a la Pair-aidance : Engagements, Journal, Sondage.
        // Les trois sont la — Engagements etant la Roadmap sous son nom.
        $cles = $this->types()->cardsFor('peer_support');

        $this->assertContains('core.roadmap', $cles);
        $this->assertContains('core.journal', $cles);
        $this->assertContains('core.polls', $cles);
    }

    public function test_coaching_holds_two_of_its_three(): void
    {
        // Coaching : Engagements, Suivi de coaching, Journal. Or les deux
        // premiers sont **la meme Card** sous deux noms — une Boucle n'en porte
        // donc qu'une. Le Suivi de coaching reste a inventer comme objet
        // distinct, ou a reconnaitre comme le meme.
        $cles = $this->types()->cardsFor('coaching');

        $this->assertContains('core.roadmap', $cles);
        $this->assertContains('core.journal', $cles);
    }

    // ── Aucune condition sur le type dans le code ───────────────────────────

    public function test_no_business_code_branches_on_the_loop_type(): void
    {
        // Le vocabulaire est **declare**, pas decide dans une vue. C'est la
        // regle qui tient depuis TASK-1079.
        foreach ([
            app_path('Support/Loops/LoopCardRegistry.php'),
            app_path('Livewire/LoopRoadmapCard.php'),
            resource_path('views/livewire/loop-roadmap-card.blade.php'),
        ] as $fichier) {
            $source = file_get_contents($fichier);

            foreach (["\$loop->type ===", "\$loop->type =="] as $condition) {
                $this->assertStringNotContainsString($condition, $source, basename($fichier));
            }
        }
    }
}
