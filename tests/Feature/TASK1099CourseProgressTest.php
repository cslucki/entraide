<?php

namespace Tests\Feature;

use App\Models\CourseModule;
use App\Models\CourseSequence;
use App\Models\CourseSequenceProgress as P;
use App\Models\CourseSetting;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\CourseMaterialService;
use App\Services\Loops\CourseProgressService;
use App\Services\LoopService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La progression individuelle.
 *
 * Toute la tache tient dans une phrase : **la structure est commune, l'etat est
 * individuel**. Ces tests verifient les deux moities, et surtout ce qui ne doit
 * pas exister — un etat de Module stocke, une inscription parallele, une regle
 * d'ouverture que l'IA pourrait prononcer.
 */
class TASK1099CourseProgressTest extends TestCase
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
        \App\Models\LoopCard::where('loop_id', $loop->id)->delete();
        app(LoopTypeRegistry::class)->applyPreset($loop->fresh());

        return $loop->fresh();
    }

    private function progress(): CourseProgressService
    {
        return app(CourseProgressService::class);
    }

    private function material(): CourseMaterialService
    {
        return app(CourseMaterialService::class);
    }

    private function module(string $titre): CourseModule
    {
        return $this->material()->createModule($this->loop, $this->formateur, $titre);
    }

    private function sequence(CourseModule $module, string $titre, bool $validation = false): CourseSequence
    {
        $s = $this->material()->addSequence($module, $this->formateur, $titre);

        if ($validation) {
            $s->forceFill(['requires_validation' => true])->save();
        }

        return $s->fresh();
    }

    private function etat(CourseSequence $s, ?User $qui = null): string
    {
        return $this->progress()->statusFor($qui ?? $this->stagiaire, $s->fresh());
    }

    // ── Ce qui ne doit pas exister ──────────────────────────────────────────

    public function test_no_module_state_is_ever_stored(): void
    {
        // L'etat d'un Module se **calcule** depuis ses Sequences. Le stocker
        // obligerait a le tenir a jour a chaque mouvement, et deux verites
        // finiraient par diverger au pire moment.
        $colonnes = \Illuminate\Support\Facades\Schema::getColumnListing('course_modules');

        foreach (['status', 'state', 'completed_at', 'progress'] as $interdite) {
            $this->assertNotContains($interdite, $colonnes);
        }
    }

    public function test_membership_remains_the_only_enrolment(): void
    {
        foreach (['training_enrollments', 'course_enrollments', 'course_students'] as $table) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable($table));
        }

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->stagiaire->id,
        ]);
    }

    public function test_available_and_unavailable_are_never_written(): void
    {
        $m = $this->module('M1');
        $s = $this->sequence($m, 'S1');

        // La toute premiere etape est ouverte — mais rien n'est ecrit tant que
        // personne n'a rien fait. Ce sont des conclusions, pas des faits.
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($s));
        $this->assertDatabaseCount('course_sequence_progress', 0);

        $this->assertNotContains(P::STATUS_AVAILABLE, P::STORED_STATUSES);
        $this->assertNotContains(P::STATUS_UNAVAILABLE, P::STORED_STATUSES);
    }

    // ── Regle 1 : apres l'etape precedente (le defaut) ──────────────────────

    public function test_the_path_is_sequential_by_default(): void
    {
        $this->assertSame(CourseSetting::PATH_SEQUENTIAL, $this->progress()->pathMode($this->loop));

        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');
        $b = $this->sequence($m, 'B');

        // La premiere est ouverte — sinon personne ne pourrait commencer.
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($a));
        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($b));

        $this->progress()->markCompleted($this->stagiaire, $a);

        $this->assertSame(P::STATUS_COMPLETED, $this->etat($a));
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($b));
    }

    public function test_a_module_opens_only_when_the_previous_one_is_finished(): void
    {
        $m1 = $this->module('M1');
        $a = $this->sequence($m1, 'A');
        $b = $this->sequence($m1, 'B');

        $m2 = $this->module('M2');
        $c = $this->sequence($m2, 'C');

        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($c));

        $this->progress()->markCompleted($this->stagiaire, $a);
        // M1 n'est pas fini : B reste.
        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($c));

        $this->progress()->markCompleted($this->stagiaire, $b);
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($c));
    }

    public function test_an_empty_module_does_not_open_the_next_one(): void
    {
        // Un Module vide n'est pas « termine » : il est vide, ce qui n'est pas
        // la meme chose.
        $this->module('M1 vide');
        $m2 = $this->module('M2');
        $c = $this->sequence($m2, 'C');

        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($c));
    }

    public function test_a_locked_sequence_cannot_be_started(): void
    {
        $m = $this->module('M1');
        $this->sequence($m, 'A');
        $b = $this->sequence($m, 'B');

        $this->expectException(ValidationException::class);
        $this->progress()->start($this->stagiaire, $b);
    }

    // ── Regle 2 : le parcours libre ─────────────────────────────────────────

    public function test_the_free_path_opens_everything_at_once(): void
    {
        $m = $this->module('M1');
        $this->sequence($m, 'A');
        $b = $this->sequence($m, 'B');
        $m2 = $this->module('M2');
        $c = $this->sequence($m2, 'C');

        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($b));

        $this->progress()->setPathMode($this->loop, CourseSetting::PATH_FREE);

        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($b));
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($c));
    }

    public function test_an_unknown_path_mode_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->progress()->setPathMode($this->loop, 'au-hasard');
    }

    // ── Regle 4 : le deblocage manuel prime sur tout ────────────────────────

    public function test_a_manual_unlock_overrides_every_automatic_rule(): void
    {
        $m1 = $this->module('M1');
        $this->sequence($m1, 'A');
        $m2 = $this->module('M2');
        $c = $this->sequence($m2, 'C');

        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($c));

        $this->progress()->unlock($this->formateur, $this->stagiaire, $c);

        // Ouverte, malgre le sequentiel et malgre M1 inacheve.
        $this->assertTrue($this->progress()->isAvailable($this->stagiaire, $c->fresh()));
    }

    public function test_unlocking_opens_without_declaring_the_work_done(): void
    {
        $m = $this->module('M1');
        $this->sequence($m, 'A');
        $b = $this->sequence($m, 'B');

        $this->progress()->unlock($this->formateur, $this->stagiaire, $b);

        // Debloquer et valider sont deux gestes differents : la personne peut
        // travailler, elle n'a pas fini pour autant.
        $this->assertNotSame(P::STATUS_COMPLETED, $this->etat($b));
        $this->assertNotSame(P::STATUS_VALIDATED, $this->etat($b));
    }

    public function test_a_manual_unlock_only_concerns_the_person_it_was_given_to(): void
    {
        $autre = User::factory()->create(['organization_id' => $this->org->id]);
        $this->loops->addMember($this->loop, $autre, 'member');

        $m1 = $this->module('M1');
        $this->sequence($m1, 'A');
        $m2 = $this->module('M2');
        $c = $this->sequence($m2, 'C');

        $this->progress()->unlock($this->formateur, $this->stagiaire, $c);

        $this->assertTrue($this->progress()->isAvailable($this->stagiaire, $c->fresh()));
        $this->assertFalse($this->progress()->isAvailable($autre, $c->fresh()));
    }

    // ── completed contre validated ──────────────────────────────────────────

    public function test_completed_is_declarative_and_ends_a_sequence_without_review(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'Une lecture');

        $this->progress()->markCompleted($this->stagiaire, $a);

        // Personne ne va corriger une lecture.
        $this->assertSame(P::STATUS_COMPLETED, $this->etat($a));
    }

    public function test_a_sequence_awaiting_a_review_is_submitted_not_completed(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'Un travail', validation: true);
        $b = $this->sequence($m, 'La suite');

        $this->progress()->markCompleted($this->stagiaire, $a);

        // Se declarer termine ne suffit pas a l'etre.
        $this->assertSame(P::STATUS_SUBMITTED, $this->etat($a));
        // Et la suite reste fermee : `submitted` n'est pas fini.
        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($b));

        $this->progress()->validate($this->formateur, $this->stagiaire, $a);

        $this->assertSame(P::STATUS_VALIDATED, $this->etat($a));
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($b));
    }

    public function test_only_a_human_validates(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'Un travail', validation: true);

        $this->progress()->markCompleted($this->stagiaire, $a);
        $this->progress()->validate($this->formateur, $this->stagiaire, $a);

        $ligne = \App\Models\CourseSequenceProgress::where('course_sequence_id', $a->id)->firstOrFail();

        // Le nom de qui a valide est ecrit : une validation sans auteur ne
        // serait pas une validation.
        $this->assertSame($this->formateur->id, $ligne->validated_by);
        $this->assertNotNull($ligne->validated_at);
    }

    public function test_a_redo_reopens_the_sequence_and_closes_the_next(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'Un travail', validation: true);
        $b = $this->sequence($m, 'La suite');

        $this->progress()->markCompleted($this->stagiaire, $a);
        $this->progress()->validate($this->formateur, $this->stagiaire, $a);
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($b));

        $this->progress()->requestRedo($this->formateur, $this->stagiaire, $a);

        $this->assertSame(P::STATUS_REDO, $this->etat($a));
        $this->assertSame(P::STATUS_UNAVAILABLE, $this->etat($b));
    }

    // ── Ouvrir n'est pas faire ──────────────────────────────────────────────

    public function test_opening_a_sequence_never_finishes_it(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');

        $this->progress()->start($this->stagiaire, $a);

        // La progression n'est jamais deduite du dernier acces.
        $this->assertSame(P::STATUS_IN_PROGRESS, $this->etat($a));
    }

    public function test_rereading_a_finished_sequence_does_not_reopen_it(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');

        $this->progress()->markCompleted($this->stagiaire, $a);
        $this->progress()->start($this->stagiaire, $a);

        $this->assertSame(P::STATUS_COMPLETED, $this->etat($a));
    }

    // ── Les cas qui font mal ────────────────────────────────────────────────

    public function test_reordering_the_course_never_reopens_anything(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');
        $b = $this->sequence($m, 'B');

        $this->progress()->markCompleted($this->stagiaire, $a);
        $this->progress()->markCompleted($this->stagiaire, $b);

        // Les etats suivent leur Sequence, pas leur position.
        $this->material()->reorderSequences($m, [$b->id, $a->id]);

        $this->assertSame(P::STATUS_COMPLETED, $this->etat($a));
        $this->assertSame(P::STATUS_COMPLETED, $this->etat($b));
    }

    public function test_editing_a_module_never_resets_progress(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');

        $this->progress()->markCompleted($this->stagiaire, $a);
        $this->material()->updateModule($m, 'Titre corrige');

        // Corriger une faute de frappe ne doit pas reinitialiser vingt
        // personnes.
        $this->assertSame(P::STATUS_COMPLETED, $this->etat($a));
    }

    public function test_a_followed_sequence_is_archived_not_deleted(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');
        $b = $this->sequence($m, 'B');

        $this->progress()->markCompleted($this->stagiaire, $a);
        $this->material()->deleteSequence($a);

        // Effacer la ligne effacerait ce que des gens ont fait.
        $this->assertDatabaseHas('course_sequences', ['id' => $a->id]);
        $this->assertNotNull($a->fresh()->archived_at);
        $this->assertDatabaseHas('course_sequence_progress', ['course_sequence_id' => $a->id]);

        // Archivee, elle ne bloque plus personne : B devient la premiere.
        $this->assertSame(P::STATUS_AVAILABLE, $this->etat($b));
    }

    public function test_an_untouched_sequence_is_really_deleted(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');

        $this->material()->deleteSequence($a);

        // Rien a preserver : elle part.
        $this->assertDatabaseMissing('course_sequences', ['id' => $a->id]);
    }

    public function test_progress_survives_leaving_the_loop(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');

        $this->progress()->markCompleted($this->stagiaire, $a);
        $this->loops->removeMember(
            \App\Models\LoopMember::where('loop_id', $this->loop->id)
                ->where('user_id', $this->stagiaire->id)
                ->firstOrFail()
        );

        // Meme doctrine que les votes de Sondage et les reponses d'Evenement :
        // la progression decrit ce qui s'est passe.
        $this->assertDatabaseHas('course_sequence_progress', [
            'course_sequence_id' => $a->id,
            'user_id' => $this->stagiaire->id,
            'status' => P::STATUS_COMPLETED,
        ]);
    }

    // ── Les deux vues ───────────────────────────────────────────────────────

    public function test_the_student_overview_numbers_sequences_without_writing(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');
        $this->sequence($m, 'B');

        $this->progress()->markCompleted($this->stagiaire, $a);

        $vue = $this->progress()->overviewFor($this->stagiaire, $this->loop);

        $this->assertCount(1, $vue);
        $this->assertSame(P::STATUS_IN_PROGRESS, $vue[0]['status']);
        $this->assertSame(['01', '02'], $vue[0]['sequences']->pluck('number')->all());
        $this->assertSame('A', $a->fresh()->title);
    }

    public function test_the_trainer_matrix_counts_what_awaits_a_human_gesture(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'Un travail', validation: true);

        $this->progress()->markCompleted($this->stagiaire, $a);

        $matrice = $this->progress()->matrixFor($this->loop, collect([$this->stagiaire]));

        $this->assertSame(1, $matrice[0]['awaiting']);
        $this->assertSame($this->stagiaire->id, $matrice[0]['user']->id);

        $this->progress()->validate($this->formateur, $this->stagiaire, $a);

        $this->assertSame(0, $this->progress()->matrixFor($this->loop, collect([$this->stagiaire]))[0]['awaiting']);
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_every_progress_row_carries_its_organization(): void
    {
        $m = $this->module('M1');
        $a = $this->sequence($m, 'A');

        $ligne = $this->progress()->markCompleted($this->stagiaire, $a);

        $this->assertSame($this->org->id, $ligne->organization_id);
    }
}
