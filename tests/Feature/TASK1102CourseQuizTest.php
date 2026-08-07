<?php

namespace Tests\Feature;

use App\Livewire\LoopQuizCard;
use App\Models\CourseQuiz;
use App\Models\CourseQuizAttempt;
use App\Models\CourseQuizQuestion;
use App\Models\CourseSequenceProgress;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\CourseMaterialService;
use App\Services\Loops\CourseQuizService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les QCM.
 *
 * Cette classe couvre les quatre invariants arretes au fil de la serie, en plus
 * des regles metier :
 *
 * 1. **Une mutation n'est jamais protegee par une capacite de lecture** — passer
 *    un QCM est une ecriture, et une Boucle archivee la refuse.
 * 2. **Une permission ne suffit pas : la transition doit etre valide** — une
 *    tentative inexistante ne produit aucun acquis, un score vient d'une
 *    tentative reelle, et debloquer reste distinct de reussir.
 * 3. **Le cout SQL ne croit pas avec la classe** — plafond **et** sonde de
 *    croissance.
 * 4. **Une capacite = un backend et un chemin** — refus, succes, et presence a
 *    l'ecran.
 */
class TASK1102CourseQuizTest extends TestCase
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
        $loop = $this->loops->createLoop($this->formateur, 'Formation', type: 'training');
        LoopCard::where('loop_id', $loop->id)->delete();
        app(LoopTypeRegistry::class)->applyPreset($loop->fresh());

        // Le QCM n'est pas dans le preset par defaut : le troisieme emplacement
        // accepte Travaux **ou** QCM, et c'est au formateur de composer.
        LoopCard::create([
            'organization_id' => $this->org->id,
            'loop_id' => $loop->id,
            'card_key' => 'training.quiz',
            'enabled' => true,
        ]);

        return $loop->fresh();
    }

    private function service(): CourseQuizService
    {
        return app(CourseQuizService::class);
    }

    /** Un QCM a deux questions : une juste vaut 50, les deux valent 100. */
    private function quizAvecQuestions(array $reglages = []): CourseQuiz
    {
        $quiz = $this->service()->create(
            $this->loop, $this->formateur,
            $reglages['title'] ?? 'Un QCM',
            null,
            $reglages['pass'] ?? 70,
            $reglages['max'] ?? null,
            $reglages['blocking'] ?? false,
            $reglages['reveal'] ?? CourseQuiz::REVEAL_AFTER_LAST,
            $reglages['sequence'] ?? null,
        );

        $this->service()->addQuestion($quiz, 'Capitale de la France ?', CourseQuizQuestion::KIND_SINGLE, [
            ['text' => 'Paris', 'correct' => true],
            ['text' => 'Lyon'],
        ]);
        $this->service()->addQuestion($quiz, 'Couleurs du drapeau ?', CourseQuizQuestion::KIND_MULTIPLE, [
            ['text' => 'Bleu', 'correct' => true],
            ['text' => 'Blanc', 'correct' => true],
            ['text' => 'Vert'],
        ]);

        return $quiz->fresh();
    }

    /** @return array<string, array<int, string>> */
    private function reponses(CourseQuiz $quiz, bool $justes): array
    {
        $out = [];

        foreach ($quiz->fresh()->questions as $q) {
            $out[$q->id] = $justes
                ? $q->options->where('is_correct', true)->pluck('id')->all()
                : $q->options->where('is_correct', false)->pluck('id')->take(1)->all();
        }

        return $out;
    }

    private function card(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->formateur)->test(LoopQuizCard::class, ['loop' => $this->loop]);
    }

    // ── Un QCM n'est pas un Sondage ─────────────────────────────────────────

    public function test_a_quiz_does_not_share_the_poll_model(): void
    {
        // Un Sondage n'a pas de bonne reponse, pas de score, pas de tentative.
        // Ce qui se reutilise est la **forme**, pas les tables.
        foreach (['course_quizzes', 'course_quiz_questions', 'course_quiz_options', 'course_quiz_attempts'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        // C'est cette colonne qui fait la difference.
        $this->assertContains('is_correct', Schema::getColumnListing('course_quiz_options'));
        $this->assertNotContains('is_correct', Schema::getColumnListing('loop_poll_options'));
    }

    public function test_the_quiz_card_exists_and_is_not_forced_into_the_preset(): void
    {
        $this->assertTrue(app(LoopCardRegistry::class)->exists('training.quiz'));

        // Le troisieme emplacement accepte Travaux **ou** QCM : le preset livre
        // le premier, le second s'active localement.
        $this->assertNotContains('training.quiz', app(LoopTypeRegistry::class)->cardsFor('training'));
    }

    // ── Invariant 1 : une ecriture n'est pas protegee par une lecture ───────

    public function test_an_archived_loop_accepts_no_attempt(): void
    {
        $quiz = $this->quizAvecQuestions();
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card($this->stagiaire)->call('submitAttempt', $quiz->id)->assertForbidden();
        $this->card($this->stagiaire)->call('startQuiz', $quiz->id)->assertForbidden();

        $this->assertDatabaseCount('course_quiz_attempts', 0);
        // Lire reste possible : c'est ce qu'une archive doit permettre.
        $this->assertTrue($this->card($this->stagiaire)->instance()->canView());
        $this->assertFalse($this->card($this->stagiaire)->instance()->canAttempt());
    }

    // ── Invariant 2 : la transition doit etre valide ────────────────────────

    public function test_a_score_always_comes_from_a_real_attempt(): void
    {
        $quiz = $this->quizAvecQuestions();

        // Aucune methode publique ne pose un score : il n'existe que par
        // `attempt()`, qui corrige.
        $this->assertFalse(method_exists($this->service(), 'setScore'));
        $this->assertFalse(method_exists($this->service(), 'markPassed'));

        $t = $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: true));

        $this->assertSame(100, $t->score);
        $this->assertTrue($t->passed);
        $this->assertSame(1, $t->attempt_number);
    }

    public function test_no_attempt_produces_no_acquis(): void
    {
        $materiel = app(CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $sequence = $materiel->addSequence($module, $this->formateur, 'S1');

        $this->quizAvecQuestions(['blocking' => true, 'sequence' => $sequence->id]);

        // Sans tentative, rien n'est ecrit : ni progression, ni acquis.
        $this->assertDatabaseCount('course_quiz_attempts', 0);
        $this->assertDatabaseCount('course_sequence_progress', 0);
    }

    public function test_passing_a_blocking_quiz_opens_the_step_and_says_why(): void
    {
        $materiel = app(CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $sequence = $materiel->addSequence($module, $this->formateur, 'S1');

        $quiz = $this->quizAvecQuestions(['blocking' => true, 'sequence' => $sequence->id]);

        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: true));

        $ligne = CourseSequenceProgress::where('course_sequence_id', $sequence->id)->firstOrFail();

        $this->assertSame(CourseSequenceProgress::STATUS_COMPLETED, $ligne->status);
        // **Debloquer n'est pas reussir** : `passed_quiz_id` porte le resultat,
        // `unlocked_at` porterait le coup de pouce. Ils ne se confondent pas.
        $this->assertSame($quiz->id, $ligne->passed_quiz_id);
        $this->assertNull($ligne->unlocked_at);
    }

    public function test_failing_a_blocking_quiz_opens_nothing(): void
    {
        $materiel = app(CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $sequence = $materiel->addSequence($module, $this->formateur, 'S1');

        $quiz = $this->quizAvecQuestions(['blocking' => true, 'sequence' => $sequence->id]);

        $t = $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        $this->assertFalse($t->passed);
        $this->assertDatabaseCount('course_sequence_progress', 0);
    }

    public function test_an_informative_quiz_never_opens_a_step(): void
    {
        $materiel = app(CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $sequence = $materiel->addSequence($module, $this->formateur, 'S1');

        // Informatif : il situe, il ne conditionne pas.
        $quiz = $this->quizAvecQuestions(['blocking' => false, 'sequence' => $sequence->id]);
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: true));

        $this->assertDatabaseCount('course_sequence_progress', 0);
    }

    public function test_a_manual_unlock_stays_distinct_from_a_pass(): void
    {
        $materiel = app(CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $a = $materiel->addSequence($module, $this->formateur, 'S1');
        $b = $materiel->addSequence($module, $this->formateur, 'S2');

        app(\App\Services\Loops\CourseProgressService::class)->unlock($this->formateur, $this->stagiaire, $b);

        $ligne = CourseSequenceProgress::where('course_sequence_id', $b->id)->firstOrFail();

        // Le coup de pouce ouvre, il ne fabrique pas un resultat.
        $this->assertNotNull($ligne->unlocked_at);
        $this->assertNull($ligne->passed_quiz_id);
    }

    public function test_the_attempt_limit_is_enforced(): void
    {
        $quiz = $this->quizAvecQuestions(['max' => 2]);

        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        $this->expectException(ValidationException::class);
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: true));
    }

    public function test_an_archived_quiz_accepts_no_attempt(): void
    {
        $quiz = $this->quizAvecQuestions();
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));
        $this->service()->delete($quiz);

        $this->assertNotNull($quiz->fresh()->archived_at);

        $this->expectException(ValidationException::class);
        $this->service()->attempt($quiz->fresh(), $this->stagiaire, $this->reponses($quiz, justes: true));
    }

    public function test_a_quiz_without_question_cannot_be_attempted(): void
    {
        $quiz = $this->service()->create($this->loop, $this->formateur, 'Vide');

        $this->expectException(ValidationException::class);
        $this->service()->attempt($quiz, $this->stagiaire, []);
    }

    // ── La correction ───────────────────────────────────────────────────────

    public function test_a_partially_correct_multiple_choice_scores_nothing_for_it(): void
    {
        $quiz = $this->quizAvecQuestions();
        $questions = $quiz->questions;

        // La premiere juste, la seconde a moitie : pas de point partiel — il
        // faudrait un bareme, et un bareme est un chantier que personne n'a
        // demande.
        $reponses = [
            $questions[0]->id => $questions[0]->options->where('is_correct', true)->pluck('id')->all(),
            $questions[1]->id => $questions[1]->options->where('is_correct', true)->pluck('id')->take(1)->all(),
        ];

        $t = $this->service()->attempt($quiz, $this->stagiaire, $reponses);

        $this->assertSame(50, $t->score);
    }

    public function test_a_single_choice_question_keeps_only_one_answer(): void
    {
        $quiz = $this->quizAvecQuestions();
        $question = $quiz->questions[0];

        // Tout cocher pour ne jamais se tromper : la premiere coche seule est
        // retenue, et ici c'est la mauvaise.
        $mauvaise = $question->options->where('is_correct', false)->first();
        $bonne = $question->options->where('is_correct', true)->first();

        $t = $this->service()->attempt($quiz, $this->stagiaire, [
            $question->id => [$mauvaise->id, $bonne->id],
        ]);

        $this->assertSame(0, $t->score);
    }

    public function test_an_option_of_another_question_never_counts(): void
    {
        $quiz = $this->quizAvecQuestions();
        $autre = $this->quizAvecQuestions(['title' => 'Un autre']);

        // Un identifiant forge ne doit pas fabriquer une bonne reponse.
        $t = $this->service()->attempt($quiz, $this->stagiaire, [
            $quiz->questions[0]->id => $autre->fresh()->questions[0]->options->pluck('id')->all(),
        ]);

        $this->assertSame(0, $t->score);
        $this->assertSame([], $t->answers[$quiz->questions[0]->id]);
    }

    // ── Ce que le stagiaire voit ────────────────────────────────────────────

    public function test_the_score_is_always_immediate_but_not_the_detail(): void
    {
        $quiz = $this->quizAvecQuestions(['max' => 2, 'reveal' => CourseQuiz::REVEAL_AFTER_LAST]);

        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        // Le score, oui — c'est ce que tout le monde attend.
        $this->assertNotNull($this->service()->bestAttempt($quiz, $this->stagiaire));
        // Le detail, non : il rendrait la seconde tentative sans objet.
        $this->assertFalse($this->service()->revealsAnswersTo($quiz, $this->stagiaire));

        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        $frais = new CourseQuizService;
        $this->assertTrue($frais->revealsAnswersTo($quiz->fresh(), $this->stagiaire));
    }

    public function test_the_immediate_reveal_shows_the_detail_at_once(): void
    {
        $quiz = $this->quizAvecQuestions(['reveal' => CourseQuiz::REVEAL_IMMEDIATE]);

        $this->assertFalse($this->service()->revealsAnswersTo($quiz, $this->stagiaire));

        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        $this->assertTrue((new CourseQuizService)->revealsAnswersTo($quiz, $this->stagiaire));
    }

    public function test_the_release_reveal_waits_for_the_trainer(): void
    {
        $quiz = $this->quizAvecQuestions(['reveal' => CourseQuiz::REVEAL_AFTER_RELEASE]);
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        $this->assertFalse((new CourseQuizService)->revealsAnswersTo($quiz->fresh(), $this->stagiaire));

        $this->service()->release($quiz);

        $this->assertTrue((new CourseQuizService)->revealsAnswersTo($quiz->fresh(), $this->stagiaire));
    }

    // ── Invariant 3 : le cout ne croit pas avec la classe ───────────────────

    public function test_the_sql_cost_does_not_grow_with_the_class(): void
    {
        $quiz = $this->quizAvecQuestions();
        foreach (range(1, 5) as $i) {
            $this->quizAvecQuestions(['title' => "QCM {$i}"]);
        }

        $gens = collect(range(1, 14))->map(function () {
            $u = User::factory()->create(['organization_id' => $this->org->id]);
            $this->loops->addMember($this->loop, $u, 'member');

            return $u;
        })->push($this->stagiaire);

        $this->service()->attempt($quiz->fresh(), $gens[0], $this->reponses($quiz, justes: true));

        $mesure = function (Collection $population): int {
            $frais = new CourseQuizService;

            DB::flushQueryLog();
            DB::enableQueryLog();
            $frais->matrixFor($this->loop, $population)
                ->each(fn (array $l) => $l['cells']->each(fn (array $c) => $c['tries']));
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $n;
        };

        $petit = $mesure($gens->take(1));   //   6 cellules
        $grand = $mesure($gens);            //  90 cellules — quinze fois plus

        // Le meme cout. Le seuil est en ecart, pas en valeur absolue : c'est ce
        // qui attrape un N+1 par ligne qu'un plafond genereux laisserait passer.
        $this->assertSame($petit, $grand, "le cout suit la population : {$petit} puis {$grand}");
        $this->assertLessThan(6, $grand);
    }

    // ── Invariant 4 : refus, succes, et presence a l'ecran ─────────────────

    public function test_a_trainee_is_refused_every_trainer_gesture(): void
    {
        $quiz = $this->quizAvecQuestions();

        foreach ([
            ['saveQuiz', []],
            ['openQuestionForm', [$quiz->id]],
            ['saveQuestion', [$quiz->id]],
            ['addOptionRow', []],
            ['deleteQuiz', [$quiz->id]],
            ['release', [$quiz->id]],
        ] as [$methode, $arguments]) {
            $this->card($this->stagiaire)->call($methode, ...$arguments)->assertForbidden();
        }

        $this->assertNull($quiz->fresh()->archived_at);
    }

    public function test_a_trainer_succeeds_at_every_gesture(): void
    {
        $this->card()->set('title', 'Mon QCM')->set('passScore', '50')->call('saveQuiz');
        $quiz = CourseQuiz::where('loop_id', $this->loop->id)->firstOrFail();
        $this->assertSame('Mon QCM', $quiz->title);
        $this->assertSame(50, $quiz->pass_score);

        $this->card()
            ->call('openQuestionForm', $quiz->id)
            ->set('questionText', 'Deux et deux ?')
            ->set('optionRows', [['text' => 'Quatre', 'correct' => true], ['text' => 'Cinq', 'correct' => false]])
            ->call('saveQuestion', $quiz->id);

        $this->assertSame(1, $quiz->fresh()->questions()->count());

        $this->card()->call('release', $quiz->id);
        $this->assertNotNull($quiz->fresh()->released_at);

        $this->card()->call('deleteQuiz', $quiz->id);
        $this->assertDatabaseMissing('course_quizzes', ['id' => $quiz->id]);
    }

    public function test_a_trainee_can_actually_take_a_quiz_from_the_card(): void
    {
        $quiz = $this->quizAvecQuestions();
        $question = $quiz->questions[0];
        $bonne = $question->options->where('is_correct', true)->first();

        $this->card($this->stagiaire)
            ->call('startQuiz', $quiz->id)
            ->call('toggleAnswer', $question->id, $bonne->id, true)
            ->call('submitAttempt', $quiz->id);

        $t = CourseQuizAttempt::where('course_quiz_id', $quiz->id)->firstOrFail();
        $this->assertSame($this->stagiaire->id, $t->user_id);
        $this->assertSame(50, $t->score);
    }

    public function test_every_gesture_is_present_in_the_interface(): void
    {
        $quiz = $this->quizAvecQuestions(['reveal' => CourseQuiz::REVEAL_AFTER_RELEASE]);

        $this->card()
            ->assertSee(e(__('loops.cards.quiz.add')), false)
            ->assertSee(e(__('loops.cards.quiz.add_question')), false)
            ->assertSee(e(__('loops.cards.quiz.release')), false);

        $this->card($this->stagiaire)
            ->assertSee(e(__('loops.cards.quiz.start')), false)
            ->call('startQuiz', $quiz->id)
            ->assertSee('Capitale de la France ?')
            ->assertSee(e(__('loops.cards.quiz.submit')), false);
    }

    public function test_a_non_member_reads_nothing(): void
    {
        $this->quizAvecQuestions(['title' => 'QCM confidentiel']);
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        $this->card($etranger)
            ->assertDontSee('QCM confidentiel')
            ->assertSee(e(__('loops.cards.quiz.no_access')), false);
    }


    // ── Ce que la revue hostile a trouve ────────────────────────────────────

    public function test_the_blocking_switch_actually_blocks(): void
    {
        $materiel = app(CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $sequence = $materiel->addSequence($module, $this->formateur, 'S1');

        // Le formulaire propose desormais la Sequence a conditionner. Sans
        // elle, « bloquant » etait une case decorative : le service exige une
        // etape, et rien ne la lui donnait.
        $this->card()
            ->set('title', 'QCM bloquant')
            ->set('passScore', '50')
            ->set('blocking', true)
            ->set('sequenceId', $sequence->id)
            ->call('saveQuiz');

        $quiz = CourseQuiz::where('loop_id', $this->loop->id)->firstOrFail();

        $this->assertTrue($quiz->blocking);
        $this->assertSame($sequence->id, $quiz->course_sequence_id);
    }

    public function test_the_sequence_field_is_offered_when_blocking(): void
    {
        $materiel = app(CourseMaterialService::class);
        $module = $materiel->createModule($this->loop, $this->formateur, 'M1');
        $materiel->addSequence($module, $this->formateur, 'Une etape');

        // C — la presence reelle du geste a l'ecran.
        $this->card()
            ->set('showForm', true)
            ->set('blocking', true)
            ->assertSee(e(__('loops.cards.quiz.sequence_label')), false)
            ->assertSee('Une etape');
    }

    public function test_an_unlimited_quiz_still_reveals_its_answers(): void
    {
        // `after_last_attempt` sans limite de tentatives : il n'y a **pas** de
        // derniere, la condition n'arrivait jamais, et le corrige restait
        // invisible pour toujours — sur le reglage par defaut du formulaire.
        $quiz = $this->quizAvecQuestions(['max' => null, 'reveal' => CourseQuiz::REVEAL_AFTER_LAST]);
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        $this->assertFalse((new CourseQuizService)->revealsAnswersTo($quiz->fresh(), $this->stagiaire));

        // Le formateur reprend la main : c'est le seul moment ou « apres la
        // derniere » a encore un sens.
        $this->service()->release($quiz);

        $this->assertTrue((new CourseQuizService)->revealsAnswersTo($quiz->fresh(), $this->stagiaire));
    }

    public function test_a_quiz_is_not_frozen_after_creation(): void
    {
        $quiz = $this->quizAvecQuestions(['max' => 1, 'reveal' => CourseQuiz::REVEAL_AFTER_LAST]);

        // Sans chemin de modification, un seuil mal saisi etait definitif : le
        // QCM devenait une impasse que rien ne rattrapait.
        $this->card()
            ->call('startEditing', $quiz->id)
            ->set('title', 'Intitule corrige')
            ->set('passScore', '40')
            ->set('maxAttempts', '3')
            ->call('saveQuiz');

        $frais = $quiz->fresh();
        $this->assertSame('Intitule corrige', $frais->title);
        $this->assertSame(40, $frais->pass_score);
        $this->assertSame(3, $frais->max_attempts);
    }

    public function test_a_pass_score_of_zero_is_refused(): void
    {
        // A zero, un envoi **vide** passait : le score est toujours superieur ou
        // egal a zero, et « reussi » perdait son sens.
        $this->expectException(ValidationException::class);
        $this->service()->create($this->loop, $this->formateur, 'Trop permissif', null, 0);
    }

    public function test_a_question_without_a_correct_answer_is_refused(): void
    {
        $quiz = $this->service()->create($this->loop, $this->formateur, 'Un QCM');

        // Sans bonne reponse, la question ne peut jamais etre juste : le QCM
        // devient impassable, et rien ne le dit.
        $this->expectException(ValidationException::class);
        $this->service()->addQuestion($quiz, 'Sans issue ?', CourseQuizQuestion::KIND_SINGLE, [
            ['text' => 'A'], ['text' => 'B'],
        ]);
    }

    public function test_a_single_choice_question_with_two_correct_answers_is_refused(): void
    {
        $quiz = $this->service()->create($this->loop, $this->formateur, 'Un QCM');

        // La correction n'en garde qu'une : en declarer deux la rendrait
        // impossible a reussir, silencieusement.
        $this->expectException(ValidationException::class);
        $this->service()->addQuestion($quiz, 'Deux bonnes ?', CourseQuizQuestion::KIND_SINGLE, [
            ['text' => 'A', 'correct' => true], ['text' => 'B', 'correct' => true],
        ]);
    }

    public function test_the_settings_respect_the_real_column_bounds(): void
    {
        // `title` est un `varchar(255)` et `max_attempts` un `smallint` en
        // PostgreSQL. SQLite les accepte sans broncher : la suite de tests ne
        // pouvait pas voir ces deux 500.
        try {
            $this->service()->create($this->loop, $this->formateur, str_repeat('a', 300));
            $this->fail('un intitule de 300 caracteres doit etre refuse');
        } catch (ValidationException) {
        }

        try {
            $this->service()->create($this->loop, $this->formateur, 'Un QCM', null, 70, 99999);
            $this->fail('99999 tentatives doit etre refuse');
        } catch (ValidationException) {
        }

        $this->assertDatabaseCount('course_quizzes', 0);
    }

    public function test_the_attempt_number_survives_a_gap(): void
    {
        $quiz = $this->quizAvecQuestions();

        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));

        // Un trou dans la numerotation faisait reutiliser un rang deja pris par
        // `count + 1`, et violait l'index unique — 500 a la cle.
        CourseQuizAttempt::where('course_quiz_id', $quiz->id)->where('attempt_number', 1)->delete();

        $t = $this->service()->attempt($quiz->fresh(), $this->stagiaire, $this->reponses($quiz, justes: true));

        $this->assertSame(3, $t->attempt_number);
    }

    public function test_the_service_checks_the_tenant_itself(): void
    {
        $quiz = $this->quizAvecQuestions();

        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        // La Card garde deja, mais un service qui suppose son unique appelant
        // devient faux au deuxieme.
        $this->expectException(ValidationException::class);
        $this->service()->attempt($quiz, $etranger, $this->reponses($quiz, justes: true));
    }

    public function test_the_trainer_reads_the_answers_without_taking_the_quiz(): void
    {
        $this->quizAvecQuestions();

        // Le seul moyen de verifier une question etait de la passer soi-meme,
        // ce qui creait une tentative et faisait apparaitre le formateur dans
        // sa propre matrice.
        $this->card()
            ->assertSee('Capitale de la France ?')
            ->assertSee('Paris');

        $this->assertDatabaseCount('course_quiz_attempts', 0);
    }

    public function test_a_removed_quiz_stays_readable_by_the_trainer(): void
    {
        $quiz = $this->quizAvecQuestions(['title' => 'Retire mais passe']);
        $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: false));
        $this->service()->delete($quiz);

        $this->card()
            ->assertSee(e(__('loops.cards.quiz.archived_title')), false)
            ->assertSee('Retire mais passe');
    }

    public function test_the_take_form_never_shows_without_the_right_to_write(): void
    {
        $quiz = $this->quizAvecQuestions();
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        // `takingId` est une propriete publique : la poser depuis le client
        // affichait le formulaire et le bouton « Valider », qui echouait en 403.
        $this->card($this->stagiaire)
            ->set('takingId', $quiz->id)
            ->assertDontSee(e(__('loops.cards.quiz.submit')), false);
    }

    public function test_the_render_cost_does_not_grow_with_the_content(): void
    {
        // Le service etait plat ; c'est le **rendu** qui payait une requete par
        // QCM puis une par question. Un budget qui ne mesure que le service est
        // aveugle a ce qui coute.
        $mesure = function (int $qcm, int $questions): int {
            $loop = $this->trainingLoop();
            $s = app(CourseQuizService::class);

            foreach (range(1, $qcm) as $i) {
                $q = $s->create($loop, $this->formateur, "Q{$i}", null, 70, null, false, CourseQuiz::REVEAL_IMMEDIATE);
                foreach (range(1, $questions) as $j) {
                    $s->addQuestion($q, "Question {$j}", CourseQuizQuestion::KIND_SINGLE, [
                        ['text' => 'A', 'correct' => true], ['text' => 'B'],
                    ]);
                }
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::actingAs($this->formateur)->test(LoopQuizCard::class, ['loop' => $loop]);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $n;
        };

        $petit = $mesure(2, 3);    //  6 questions
        $grand = $mesure(6, 8);    // 48 questions — huit fois plus

        // Le meme cout, a une requete pres. Avant, la loi etait
        // `14 + somme(1 + nb_questions)` — 70 requetes pour 48 questions.
        $this->assertLessThanOrEqual($petit + 1, $grand, "le rendu suit le contenu : {$petit} puis {$grand}");
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────


    public function test_a_quiz_of_another_loop_is_never_reachable(): void
    {
        $autre = $this->trainingLoop();
        $voisin = $this->service()->create($autre, $this->formateur, 'Chez le voisin');

        $this->card()->call('deleteQuiz', $voisin->id)->assertNotFound();
        $this->card($this->stagiaire)->call('startQuiz', $voisin->id)->assertNotFound();

        $this->assertDatabaseHas('course_quizzes', ['id' => $voisin->id]);
    }

    public function test_every_row_carries_its_organization(): void
    {
        $quiz = $this->quizAvecQuestions();
        $t = $this->service()->attempt($quiz, $this->stagiaire, $this->reponses($quiz, justes: true));

        $this->assertSame($this->org->id, $quiz->organization_id);
        $this->assertSame($this->org->id, $t->organization_id);
        $this->assertSame($this->org->id, $quiz->questions[0]->organization_id);
    }

    public function test_no_ai_service_is_involved(): void
    {
        // L'IA ne cree aucune transition de validation. La verification est
        // textuelle et volontairement grossiere : elle attrape un import.
        $source = file_get_contents(app_path('Services/Loops/CourseQuizService.php'));

        foreach (['Ai\\', 'OpenAi', 'Anthropic', 'Prompt', 'Llm'] as $trace) {
            $this->assertStringNotContainsString($trace, $source);
        }
    }
}
