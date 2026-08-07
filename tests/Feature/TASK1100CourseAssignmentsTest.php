<?php

namespace Tests\Feature;

use App\Livewire\LoopAssignmentsCard;
use App\Models\CourseAssignment;
use App\Models\CourseSubmission as S;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\CourseAssignmentService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les Travaux a rendre.
 *
 * Cette classe couvre les trois invariants transverses arretes apres la revue
 * de la Progression, en plus des regles metier :
 *
 * 1. **Le cout ne croit pas avec la classe** — un budget de requetes.
 * 2. **Un acquis ne regresse jamais** — retirer ou modifier un Travail ne
 *    detruit ni ne referme ce qui est deja fait.
 * 3. **Une capacite = un backend **et** un chemin** — pour chaque geste
 *    important : refus d'un acteur non autorise, succes d'un acteur autorise,
 *    et **presence reelle du geste dans l'interface**.
 */
class TASK1100CourseAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $formateur;

    private User $stagiaire;

    private Loop $loop;

    private LoopService $loops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->formateur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->stagiaire = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loops = new LoopService;
        $this->loop = $this->trainingLoop();
        $this->loops->addMember($this->loop, $this->stagiaire, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function trainingLoop(): Loop
    {
        $loop = $this->loops->createLoop($this->formateur, 'Formation');
        $loop->forceFill(['type' => 'training'])->save();
        LoopCard::where('loop_id', $loop->id)->delete();
        app(LoopTypeRegistry::class)->applyPreset($loop->fresh());

        return $loop->fresh();
    }

    private function service(): CourseAssignmentService
    {
        return app(CourseAssignmentService::class);
    }

    private function travail(string $titre = 'Un Travail', ?string $due = null): CourseAssignment
    {
        return $this->service()->create(
            $this->loop, $this->formateur, $titre, 'Consignes.',
            $due ? \Illuminate\Support\Carbon::parse($due) : null,
        );
    }

    private function card(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->formateur)
            ->test(LoopAssignmentsCard::class, ['loop' => $this->loop]);
    }

    // ── Le preset minimal est complet ───────────────────────────────────────

    public function test_the_training_preset_now_has_its_three_cards(): void
    {
        $cles = app(LoopTypeRegistry::class)->activeCardsFor($this->loop);

        // La matrice canonique : Support de cours, Progression, et Travaux
        // **ou** QCM au troisieme emplacement.
        $this->assertContains('training.course_material', $cles);
        $this->assertContains('training.progression', $cles);
        $this->assertContains('training.assignments', $cles);
        // Le cadre permanent est la partout.
        $this->assertContains('core.manifesto', $cles);
        $this->assertContains('core.members', $cles);
    }

    public function test_assignments_depend_on_the_course_and_the_progression(): void
    {
        // Un Travail ferme une etape d'un parcours : il n'a pas de sens sans lui.
        $requis = app(LoopCardRegistry::class)->get('training.assignments')['requires'];

        $this->assertContains('training.course_material', $requis);
        $this->assertContains('training.progression', $requis);
    }

    public function test_the_quiz_card_is_still_not_promised(): void
    {
        // Le troisieme emplacement accepte Travaux **ou** QCM. Cette tache livre
        // le premier ; le second n'est declare nulle part.
        $this->assertFalse(app(LoopCardRegistry::class)->exists('training.quiz'));
    }

    public function test_no_second_file_system_is_created(): void
    {
        // Une remise qui porte un fichier **pointe** vers un `DossierFile` : le
        // quota, la somme de controle et la deduplication sont deja la.
        $colonnes = \Illuminate\Support\Facades\Schema::getColumnListing('course_submissions');

        $this->assertContains('dossier_file_id', $colonnes);
        foreach (['path', 'disk', 'checksum_sha256', 'size_bytes', 'mime_type'] as $interdite) {
            $this->assertNotContains($interdite, $colonnes, "course_submissions ne doit pas porter {$interdite}");
        }
    }

    // ── Invariant 1 : le cout ne croit pas avec la classe ───────────────────

    public function test_the_trainer_matrix_has_a_query_budget(): void
    {
        // 8 stagiaires x 6 Travaux = 48 cellules. Sans chargement groupe, chaque
        // cellule coutait une requete, et le cout suivait membres x contenus.
        $stagiaires = collect(range(1, 7))->map(function () {
            $u = User::factory()->create(['organization_id' => $this->org->id]);
            $this->loops->addMember($this->loop, $u, 'member');

            return $u;
        })->push($this->stagiaire);

        foreach (range(1, 6) as $i) {
            $t = $this->travail("Travail {$i}");
            $this->service()->submit($t, $stagiaires[0], 'Ma reponse.');
        }

        $service = app(CourseAssignmentService::class);

        DB::enableQueryLog();
        $matrice = $service->matrixFor($this->loop, $stagiaires);
        // On force l'evaluation complete, sinon on mesurerait une collection
        // paresseuse plutot que le rendu.
        $matrice->each(fn (array $l) => $l['cells']->each(fn (array $c) => $c['status']));
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Un plafond, pas un nombre exact : ce qui compte est que le cout ne
        // suive pas membres x contenus.
        $this->assertLessThan(20, $requetes, "matrixFor a fait {$requetes} requetes pour 48 cellules");
        $this->assertCount(8, $matrice);
    }

    public function test_the_student_overview_has_a_query_budget(): void
    {
        foreach (range(1, 8) as $i) {
            $this->travail("Travail {$i}");
        }

        $service = app(CourseAssignmentService::class);

        DB::enableQueryLog();
        $service->overviewFor($this->loop, $this->stagiaire)->each(fn (array $e) => $e['status']);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(12, $requetes, "overviewFor a fait {$requetes} requetes");
    }

    // ── Invariant 2 : un acquis ne regresse jamais ──────────────────────────

    public function test_removing_a_submitted_assignment_archives_it(): void
    {
        $t = $this->travail();
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');

        $this->service()->delete($t);

        // Effacer la ligne effacerait ce que quelqu'un a rendu.
        $this->assertDatabaseHas('course_assignments', ['id' => $t->id]);
        $this->assertNotNull($t->fresh()->archived_at);
        $this->assertDatabaseHas('course_submissions', [
            'course_assignment_id' => $t->id,
            'user_id' => $this->stagiaire->id,
        ]);
    }

    public function test_an_archived_assignment_leaves_the_active_path(): void
    {
        $garde = $this->travail('A garder');
        $retire = $this->travail('A retirer');
        $this->service()->submit($retire, $this->stagiaire, 'Ma reponse.');

        $this->service()->delete($retire);

        // Il ne compte plus dans ce qui reste a faire, et surtout il ne bloque
        // rien : c'est la regression que la Progression avait produite.
        $actifs = $this->service()->activeAssignments($this->loop)->pluck('id')->all();
        $this->assertSame([$garde->id], $actifs);
    }

    public function test_an_untouched_assignment_is_really_deleted(): void
    {
        $t = $this->travail();

        $this->service()->delete($t);

        // Avant toute activite, une suppression simple reste possible.
        $this->assertDatabaseMissing('course_assignments', ['id' => $t->id]);
    }

    public function test_editing_a_brief_never_resets_a_submission(): void
    {
        $t = $this->travail();
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');
        $this->service()->validateSubmission($t, $this->stagiaire, $this->formateur);

        $this->service()->update($t, 'Intitule corrige', 'Consignes corrigees.');

        // Corriger une faute ne renvoie pas vingt personnes a la case depart.
        $this->assertSame(S::STATUS_VALIDATED, $this->service()->statusFor($t->fresh(), $this->stagiaire));
        $this->assertSame('Ma reponse.', S::where('course_assignment_id', $t->id)->value('body'));
    }

    public function test_deleting_a_sequence_never_destroys_submissions(): void
    {
        $materiel = app(\App\Services\Loops\CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $sequence = $materiel->addSequence($module, $this->formateur, 'S1');

        $t = $this->service()->create($this->loop, $this->formateur, 'Rattache', null, null, $sequence->id);
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');

        $sequence->delete();

        // `nullOnDelete` : le Travail survit sans son ancrage, la remise aussi.
        $this->assertDatabaseHas('course_assignments', ['id' => $t->id]);
        $this->assertNull($t->fresh()->course_sequence_id);
        $this->assertDatabaseHas('course_submissions', ['course_assignment_id' => $t->id]);
    }

    // ── Invariant 3 : refus, succes, et presence dans l'interface ───────────

    public function test_a_trainee_is_refused_every_trainer_gesture(): void
    {
        $t = $this->travail();
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');

        foreach ([
            ['saveAssignment', []],
            ['startEditing', [$t->id]],
            ['deleteAssignment', [$t->id]],
            ['toggleReview', [$t->id, $this->stagiaire->id]],
            ['validateSubmission', [$t->id, $this->stagiaire->id]],
            ['requestRedo', [$t->id, $this->stagiaire->id]],
        ] as [$methode, $arguments]) {
            $this->card($this->stagiaire)->call($methode, ...$arguments)->assertForbidden();
        }

        $this->assertSame(S::STATUS_SUBMITTED, $this->service()->statusFor($t->fresh(), $this->stagiaire));
    }

    public function test_a_trainer_succeeds_at_every_gesture(): void
    {
        // B — le succes d'un acteur autorise, pas seulement le refus des autres.
        $this->card()->set('title', 'Le premier Travail')->set('brief', 'Consignes.')->call('saveAssignment');
        $t = CourseAssignment::where('loop_id', $this->loop->id)->firstOrFail();
        $this->assertSame('Le premier Travail', $t->title);

        $this->card()->call('startEditing', $t->id)->set('title', 'Corrige')->call('saveAssignment');
        $this->assertSame('Corrige', $t->fresh()->title);

        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');

        $this->card()->set('face', 'everyone')
            ->call('toggleReview', $t->id, $this->stagiaire->id)
            ->set('feedback', 'Bien vu.')
            ->call('validateSubmission', $t->id, $this->stagiaire->id);

        $remise = S::where('course_assignment_id', $t->id)->firstOrFail();
        $this->assertSame(S::STATUS_VALIDATED, $remise->status);
        $this->assertSame('Bien vu.', $remise->feedback);
        $this->assertSame($this->formateur->id, $remise->reviewed_by);

        $this->card()->set('face', 'everyone')
            ->call('toggleReview', $t->id, $this->stagiaire->id)
            ->call('requestRedo', $t->id, $this->stagiaire->id);
        $this->assertSame(S::STATUS_REDO, $remise->fresh()->status);

        $this->card()->call('deleteAssignment', $t->id);
        $this->assertNotNull($t->fresh()->archived_at);
    }

    public function test_every_gesture_is_present_in_the_interface(): void
    {
        // C — la presence reelle du geste a l'ecran. Une capacite dont seul le
        // refus est teste n'est pas livree : c'est exactement ce qui avait
        // manque a la Progression.
        $t = $this->travail('Un Travail visible');
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');

        // Cote Animateur : creer, modifier, et les deux gestes de relecture.
        $this->card()
            ->assertSee(e(__('loops.cards.assignments.add')), false)
            ->assertSee(e(__('loops.cards.assignments.edit', ['name' => 'Un Travail visible'])), false);

        $this->card()->set('face', 'everyone')
            ->call('toggleReview', $t->id, $this->stagiaire->id)
            ->assertSee('Ma reponse.')
            ->assertSee(e(__('loops.cards.assignments.validate')), false)
            ->assertSee(e(__('loops.cards.assignments.request_redo')), false)
            ->assertSee(e(__('loops.cards.assignments.feedback_label')), false);

        // Cote stagiaire : remettre et enregistrer un brouillon.
        $autre = $this->travail('A rendre');
        $this->card($this->stagiaire)
            ->call('openAssignment', $autre->id)
            ->assertSee(e(__('loops.cards.assignments.submit')), false)
            ->assertSee(e(__('loops.cards.assignments.save_draft')), false);
    }

    public function test_a_trainee_can_actually_submit_from_the_card(): void
    {
        $t = $this->travail();

        $this->card($this->stagiaire)
            ->call('openAssignment', $t->id)
            ->set('body', 'Voici mon travail.')
            ->call('submit', $t->id);

        $remise = S::where('course_assignment_id', $t->id)->firstOrFail();
        $this->assertSame(S::STATUS_SUBMITTED, $remise->status);
        $this->assertSame('Voici mon travail.', $remise->body);
        $this->assertNotNull($remise->submitted_at);
    }

    public function test_a_non_member_reads_nothing(): void
    {
        $this->travail('Travail confidentiel');
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        $this->card($etranger)
            ->assertDontSee('Travail confidentiel')
            ->assertSee(e(__('loops.cards.assignments.no_access')), false);
    }

    // ── Regles metier ───────────────────────────────────────────────────────

    public function test_a_draft_never_reaches_the_trainer_queue(): void
    {
        $t = $this->travail();

        $this->service()->saveDraft($t, $this->stagiaire, 'Pas fini.');

        // Ecrire n'est pas rendre.
        $this->assertSame(S::STATUS_DRAFT, $this->service()->statusFor($t, $this->stagiaire));
        $this->assertSame(0, $this->service()->matrixFor($this->loop, collect([$this->stagiaire]))[0]['awaiting']);
    }

    public function test_an_empty_submission_is_refused(): void
    {
        $t = $this->travail();

        $this->expectException(ValidationException::class);
        // Elle occuperait la file du formateur sans rien lui donner a lire.
        $this->service()->submit($t, $this->stagiaire, null);
    }

    public function test_a_trainee_cannot_undo_a_validation(): void
    {
        $t = $this->travail();
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');
        $this->service()->validateSubmission($t, $this->stagiaire, $this->formateur);

        $this->service()->submit($t, $this->stagiaire, 'Je change tout.');
        $this->service()->saveDraft($t, $this->stagiaire, 'Et encore.');

        // Defaire la decision du formateur n'est pas au stagiaire.
        $this->assertSame(S::STATUS_VALIDATED, $this->service()->statusFor($t->fresh(), $this->stagiaire));
        $this->assertSame('Ma reponse.', S::where('course_assignment_id', $t->id)->value('body'));
    }

    public function test_an_archived_assignment_accepts_no_submission(): void
    {
        $t = $this->travail();
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');
        $this->service()->delete($t);

        $this->expectException(ValidationException::class);
        $this->service()->submit($t->fresh(), $this->stagiaire, 'Trop tard.');
    }

    public function test_a_due_date_informs_but_closes_nothing(): void
    {
        $t = $this->travail('En retard', '2020-01-01 08:00');

        $this->assertTrue($t->isOverdue());

        // Rien n'en decoule automatiquement : pas de fermeture, pas de penalite.
        // Un retard qui ferme tout seul appellerait un mecanisme de derogation
        // que personne n'a demande.
        $remise = $this->service()->submit($t, $this->stagiaire, 'En retard mais rendu.');
        $this->assertSame(S::STATUS_SUBMITTED, $remise->status);
    }

    public function test_only_a_human_reviews(): void
    {
        $t = $this->travail();
        $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');
        $this->service()->validateSubmission($t, $this->stagiaire, $this->formateur, 'Tres bien.');

        $remise = S::where('course_assignment_id', $t->id)->firstOrFail();

        // Un avis sans auteur ne serait pas un avis.
        $this->assertSame($this->formateur->id, $remise->reviewed_by);
        $this->assertNotNull($remise->reviewed_at);
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_an_assignment_of_another_loop_is_never_reachable(): void
    {
        $autre = $this->trainingLoop();
        $voisin = $this->service()->create($autre, $this->formateur, 'Chez le voisin');

        $this->card()->call('deleteAssignment', $voisin->id)->assertNotFound();

        $this->assertDatabaseHas('course_assignments', ['id' => $voisin->id]);
        $this->assertNull($voisin->fresh()->archived_at);
    }

    public function test_a_trainer_never_reviews_someone_outside_the_loop(): void
    {
        $t = $this->travail();
        $dehors = User::factory()->create(['organization_id' => $this->org->id]);

        $this->card()->call('validateSubmission', $t->id, $dehors->id)->assertNotFound();

        $this->assertDatabaseCount('course_submissions', 0);
    }

    public function test_every_row_carries_its_organization(): void
    {
        $t = $this->travail();
        $remise = $this->service()->submit($t, $this->stagiaire, 'Ma reponse.');

        $this->assertSame($this->org->id, $t->organization_id);
        $this->assertSame($this->org->id, $remise->organization_id);
    }
}
