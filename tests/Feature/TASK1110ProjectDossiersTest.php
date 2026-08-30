<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
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
 * ajoutee au preset Communaute. Cette tache ne fait que l'ajouter a un second
 * preset, et rattraper les Boucles Projet existantes — un invariant desormais,
 * et non une decouverte.
 *
 * **Le rattrapage ne prend la place de personne.** Le preset Projet porte
 * exactement trois Cards de grille pour trois `grid_slots` : ecrire la
 * quatrieme sur une Boucle qui en portait deja une ajoutee a la main aurait
 * sorti cette derniere de l'ecran, sans un mot. Une Boucle deja au plafond est
 * donc laissee telle quelle.
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

    /**
     * Une Boucle Projet **telle qu'elle etait avant cette tache**.
     *
     * `createLoop()` applique le preset `general`, qui porte deja les
     * Dossiers : une Boucle ainsi fabriquee puis reetiquetee `project` n'a
     * jamais eu besoin du rattrapage, et les tests ne l'exerçaient pas. La
     * composition est donc posee a la main, exactement comme le parc reel.
     *
     * @param  list<string>  $enPlus  Cards ajoutees a la main sur cette Boucle
     */
    private function projet(string $nom = 'Un projet', array $enPlus = []): Loop
    {
        $loop = $this->loops->createLoop($this->auteur, $nom);
        $loop->forceFill(['type' => 'project'])->save();

        LoopCard::where('loop_id', $loop->id)->delete();

        foreach (['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.decisions', 'core.members'] as $cle) {
            LoopCard::create([
                'organization_id' => $this->org->id, 'loop_id' => $loop->id,
                'card_key' => $cle, 'enabled' => true, 'added_by_preset' => 'project',
            ]);
        }

        foreach ($enPlus as $cle) {
            LoopCard::create([
                'organization_id' => $this->org->id, 'loop_id' => $loop->id,
                'card_key' => $cle, 'enabled' => true, 'added_by_preset' => null,
            ]);
        }

        return $loop->fresh();
    }

    /** Ce que la grille montre reellement — plafond compris. */
    private function grille(Loop $loop): array
    {
        return app(LoopCardRegistry::class)
            ->workspaceCardsFor($loop->fresh(), $this->auteur)
            ->pluck('key')->all();
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

    public function test_the_card_catalogue_gained_nothing(): void
    {
        // **La premiere version de ce test etait une tautologie** : elle
        // comparait `count()` a `pluck('key')->unique()->count()` sur un
        // tableau **indexe par la cle** — les deux sont egaux quelle que soit
        // la taille. Ajouter une Card au catalogue la laissait passer.
        //
        // La liste est donc figee a la main, comme les presets.
        $this->assertSame([
            'core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.polls',
            'core.events', 'core.dossiers', 'training.course_material',
            'training.progression', 'training.assignments', 'training.quiz',
            'core.journal', 'core.decisions', 'core.marketplace', 'core.article',
            'core.members',
        ], array_keys(config('loop_cards.cards')));
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
        // TASK-1332 : le Manifeste a quitte le socle par defaut (il reste
        // au catalogue en placement `frame`, toujours activable) ; seul
        // Membres, la Card requise, y est garanti.
        $cles = $this->types()->cardsFor('project');

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
        // **Un temoin qui n'a pas deja la Card.** Le precedent etait une Boucle
        // `general`, dont le preset porte les Dossiers depuis TASK-1091 : le
        // test passait meme sans le filtre sur le type.
        $formation = $this->loops->createLoop($this->auteur, 'Une formation');
        $formation->forceFill(['type' => 'training'])->save();

        LoopCard::where('loop_id', $formation->id)->delete();
        LoopCard::create([
            'organization_id' => $this->org->id, 'loop_id' => $formation->id,
            'card_key' => 'core.members', 'enabled' => true, 'added_by_preset' => 'training',
        ]);

        $this->migration()->up();

        $this->assertNull(
            LoopCard::where('loop_id', $formation->id)->where('card_key', 'core.dossiers')->first(),
            'le rattrapage a touche une Boucle qui n’est pas un Projet',
        );
    }

    public function test_every_backfilled_row_carries_the_organization_of_its_loop(): void
    {
        // **Une seconde Organization, et le contexte courant pointe ailleurs.**
        // Sans cela, `HasOrganizationId` remplissait la colonne tout seul et le
        // test passait meme si le rattrapage ne la posait pas.
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $ailleurs = User::factory()->create(['organization_id' => $autreOrg->id]);

        app()->instance('current_organization', $autreOrg);
        $chezEux = (new LoopService)->createLoop($ailleurs, 'Leur projet');
        $chezEux->forceFill(['type' => 'project'])->save();
        LoopCard::where('loop_id', $chezEux->id)->delete();
        LoopCard::create([
            'organization_id' => $autreOrg->id, 'loop_id' => $chezEux->id,
            'card_key' => 'core.members', 'enabled' => true, 'added_by_preset' => 'project',
        ]);

        // Le contexte courant reste sur **notre** Organization pendant le
        // rattrapage : la colonne doit venir de la Boucle, pas de la requete.
        app()->instance('current_organization', $this->org);

        $this->migration()->up();

        $ligne = LoopCard::where('loop_id', $chezEux->id)->where('card_key', 'core.dossiers')->first();

        $this->assertNotNull($ligne);
        $this->assertSame($autreOrg->id, $ligne->organization_id);
    }

    // ── Le rattrapage ne prend la place de personne ─────────────────────────

    public function test_a_card_added_by_hand_never_leaves_the_grid(): void
    {
        // **Le defaut trouve en revue.** Le preset porte exactement trois Cards
        // de grille pour trois emplacements : ecrire la quatrieme aurait sorti
        // de l'ecran celle qu'un humain avait ajoutee — et le Journal n'a
        // aucune route a lui, sa Card est sa seule surface. La donnee aurait
        // survecu, l'acces non.
        $projet = $this->projet('Avec un Journal', ['core.journal']);

        $avant = $this->grille($projet);
        $this->assertContains('core.journal', $avant);

        $this->migration()->up();

        $this->assertContains('core.journal', $this->grille($projet), 'le Journal a ete chasse de la grille');
    }

    public function test_a_loop_already_at_the_cap_is_left_untouched(): void
    {
        $projet = $this->projet('Deja au plafond', ['core.polls']);

        $this->migration()->up();

        $this->assertNull(
            LoopCard::where('loop_id', $projet->id)->where('card_key', 'core.dossiers')->first(),
            'la Card a ete ajoutee alors que la grille etait pleine',
        );
    }

    public function test_the_backfill_never_hides_an_active_tool(): void
    {
        // Ce test plafonnait l'affichage a `grid_slots` : c'etait la regle
        // d'avant TASK-1124, ou le workspace coupait sa barre a trois et la 4e
        // Card active devenait introuvable. Ce qui compte desormais, c'est que
        // le backfill n'escamote **aucun** outil actif : au plus trois sont
        // mis en avant, les autres restent accessibles.
        $composition = app(LoopCardCompositionService::class);

        foreach ([[], ['core.journal'], ['core.polls', 'core.events']] as $i => $enPlus) {
            $projet = $this->projet("Projet {$i}", $enPlus);

            $this->migration()->up();

            $actifs = app(LoopCardRegistry::class)->activeGridKeysFor($projet->fresh());
            $principaux = $composition->primaryKeysFor($projet->fresh());
            $secondaires = $composition->secondaryKeysFor($projet->fresh());

            $this->assertLessThanOrEqual(
                LoopCardCompositionService::MAX_PRIMARY,
                count($principaux),
                "Projet {$i}",
            );

            // Tout ce qui est actif est rendu, mis en avant ou non.
            $this->assertSame(
                collect($actifs)->sort()->values()->all(),
                collect($principaux)->merge($secondaires)->sort()->values()->all(),
                "Projet {$i}",
            );

            $this->assertSame(
                collect($actifs)->sort()->values()->all(),
                collect($this->grille($projet))->sort()->values()->all(),
                "Projet {$i}",
            );
        }
    }

    public function test_an_archived_project_loop_keeps_what_it_shows(): void
    {
        // Sur une Boucle archivee la recomposition est refusee : une eviction y
        // serait irreversible sans desarchiver.
        $projet = $this->projet('Archivee', ['core.journal']);
        $projet->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->migration()->up();

        $this->assertContains('core.journal', $this->grille($projet->fresh()));
    }

    public function test_a_loop_with_room_still_receives_the_card(): void
    {
        // Le rattrapage ne doit pas devenir timide au point de ne rien faire.
        $projet = $this->projet('De la place');

        $this->migration()->up();

        $this->assertContains('core.dossiers', $this->grille($projet));
    }

    // ── Le compteur ─────────────────────────────────────────────────────────

    public function test_the_card_declares_a_data_count(): void
    {
        // Sans lui, on eteint la Card sans etre prevenu de ce qu'elle porte.
        $projet = $this->projet();

        $composition = app(LoopCardCompositionService::class)->compositionFor($projet->fresh());
        $ligne = collect($composition)->firstWhere('key', 'core.dossiers');

        $this->assertNotNull($ligne);
        $this->assertNotNull($ligne['data_count'] ?? null, 'la Card Dossiers n’annonce pas ce qu’elle porte');
    }
}
