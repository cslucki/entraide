<?php

namespace App\Services\Loops;

use App\Models\CourseQuiz;
use App\Models\CourseQuizAttempt;
use App\Models\CourseQuizOption;
use App\Models\CourseQuizQuestion;
use App\Models\CourseSequenceProgress;
use App\Models\Loop;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les QCM : ce qui se corrige tout seul.
 *
 * Quatre regles portent tout, et chacune vient d'un defaut deja paye ailleurs :
 *
 * 1. **Un score vient d'une tentative reelle.** Il est calcule par
 *    `grade()` au moment de la soumission ; aucun chemin ne permet de le poser
 *    a la main. Une tentative inexistante ne peut donc pas produire un acquis —
 *    c'est le defaut qui, sur les Travaux, enfermait la personne.
 * 2. **Debloquer n'est pas reussir.** Les deux ouvrent une etape, mais ils ne
 *    disent pas la meme chose. `unlocked_at` porte le coup de pouce,
 *    `passed_quiz_id` porte le resultat, et rien ne les confond.
 * 3. **L'IA ne cree aucune transition.** Aucun service d'IA n'est importe ici,
 *    et la correction est arithmetique.
 * 4. **Le cout ne croit pas avec la classe.** Les tentatives se chargent en une
 *    requete ; rien n'interroge la base dans une boucle.
 */
class CourseQuizService
{
    /** @var array<string, Collection<int, CourseQuizAttempt>> */
    private array $cache = [];

    /**
     * Charger d'un coup les tentatives qui vont servir.
     *
     * @param  Collection<int, User>  $users
     */
    public function warmUp(Loop $loop, Collection $users): void
    {
        $quizIds = CourseQuiz::where('loop_id', $loop->id)->pluck('id');

        if ($quizIds->isEmpty() || $users->isEmpty()) {
            return;
        }

        foreach ($quizIds as $quizId) {
            foreach ($users as $user) {
                $this->cache[$quizId.':'.$user->id] = collect();
            }
        }

        CourseQuizAttempt::whereIn('course_quiz_id', $quizIds)
            ->whereIn('user_id', $users->pluck('id'))
            ->orderBy('attempt_number')
            ->get()
            ->groupBy(fn (CourseQuizAttempt $a) => $a->course_quiz_id.':'.$a->user_id)
            ->each(function (Collection $groupe, string $cle) {
                $this->cache[$cle] = $groupe->values();
            });
    }

    private function forget(): void
    {
        $this->cache = [];
    }

    // ── Ce que le formateur ecrit ───────────────────────────────────────────

    public function create(
        Loop $loop,
        User $actor,
        string $title,
        ?string $intro = null,
        int $passScore = 70,
        ?int $maxAttempts = null,
        bool $blocking = false,
        string $revealMode = CourseQuiz::REVEAL_AFTER_LAST,
        ?string $sequenceId = null,
    ): CourseQuiz {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        $this->assertSettings($title, $passScore, $revealMode, $maxAttempts);

        return DB::transaction(function () use ($loop, $actor, $title, $intro, $passScore, $maxAttempts, $blocking, $revealMode, $sequenceId) {
            $locked = Loop::whereKey($loop->id)->lockForUpdate()->firstOrFail();

            return CourseQuiz::create([
                'organization_id' => $locked->organization_id,
                'loop_id' => $locked->id,
                'course_sequence_id' => $sequenceId,
                'title' => $title,
                'intro' => filled($intro) ? $intro : null,
                'pass_score' => $passScore,
                'max_attempts' => $maxAttempts,
                'blocking' => $blocking,
                'reveal_mode' => $revealMode,
                'position' => ((int) CourseQuiz::where('loop_id', $locked->id)->max('position')) + 1,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Ajouter une question et ses options.
     *
     * @param  array<int, array{text: string, correct?: bool}>  $options
     */
    public function addQuestion(CourseQuiz $quiz, string $text, string $kind, array $options): CourseQuizQuestion
    {
        $text = trim($text);

        if ($text === '') {
            throw ValidationException::withMessages([
                'text' => __('validation.required', ['attribute' => 'text']),
            ]);
        }

        if (! in_array($kind, CourseQuizQuestion::KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => __('loops.cards.quiz.kind_invalid'),
            ]);
        }

        if (count($options) < 2) {
            throw ValidationException::withMessages([
                'options' => __('loops.cards.quiz.needs_two_options'),
            ]);
        }

        $bonnes = collect($options)->filter(fn (array $o) => (bool) ($o['correct'] ?? false))->count();

        // Sans bonne reponse, la question ne peut **jamais** etre juste : le
        // QCM devient impassable, et rien ne le dit.
        if ($bonnes < 1) {
            throw ValidationException::withMessages([
                'options' => __('loops.cards.quiz.needs_one_correct'),
            ]);
        }

        // Une question a choix unique n'en garde qu'une a la correction : en
        // declarer deux la rendrait impossible a reussir, silencieusement.
        if ($kind !== CourseQuizQuestion::KIND_MULTIPLE && $bonnes > 1) {
            throw ValidationException::withMessages([
                'options' => __('loops.cards.quiz.single_needs_one_correct'),
            ]);
        }

        return DB::transaction(function () use ($quiz, $text, $kind, $options) {
            $locked = CourseQuiz::whereKey($quiz->id)->lockForUpdate()->firstOrFail();

            $question = CourseQuizQuestion::create([
                'organization_id' => $locked->organization_id,
                'course_quiz_id' => $locked->id,
                'text' => $text,
                'kind' => $kind,
                'position' => ((int) CourseQuizQuestion::where('course_quiz_id', $locked->id)->max('position')) + 1,
            ]);

            foreach (array_values($options) as $rang => $option) {
                CourseQuizOption::create([
                    'organization_id' => $locked->organization_id,
                    'course_quiz_question_id' => $question->id,
                    'text' => (string) ($option['text'] ?? ''),
                    'is_correct' => (bool) ($option['correct'] ?? false),
                    'position' => $rang,
                ]);
            }

            return $question->fresh();
        });
    }

    /**
     * Retirer un QCM.
     *
     * **Il s'archive des qu'une tentative existe** — effacer la ligne
     * effacerait ce que des gens ont passe — et **sort du parcours actif** : il
     * ne bloque plus rien. Sans tentative, il part.
     */
    public function delete(CourseQuiz $quiz): void
    {
        DB::transaction(function () use ($quiz) {
            $this->forget();

            $locked = CourseQuiz::whereKey($quiz->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if (CourseQuizAttempt::where('course_quiz_id', $locked->id)->exists()) {
                $locked->forceFill(['archived_at' => now()])->save();

                return;
            }

            $locked->delete();
        });
    }

    /**
     * Modifier les reglages d'un QCM.
     *
     * Sans ce chemin, un seuil mal saisi ou un mode d'affichage mal choisi
     * etaient **definitifs** : le QCM devenait une impasse que rien ne
     * rattrapait. Les tentatives deja passees ne sont pas touchees.
     */
    public function update(
        CourseQuiz $quiz,
        string $title,
        ?string $intro = null,
        ?int $passScore = null,
        ?int $maxAttempts = null,
        ?bool $blocking = null,
        ?string $revealMode = null,
    ): CourseQuiz {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        $this->assertSettings($title, $passScore ?? $quiz->pass_score, $revealMode ?? $quiz->reveal_mode, $maxAttempts);

        $quiz->update([
            'title' => $title,
            'intro' => filled($intro) ? $intro : null,
            'pass_score' => $passScore ?? $quiz->pass_score,
            'max_attempts' => $maxAttempts,
            'blocking' => $blocking ?? $quiz->blocking,
            'reveal_mode' => $revealMode ?? $quiz->reveal_mode,
        ]);

        return $quiz->fresh();
    }

    /**
     * Les bornes des reglages.
     *
     * Elles ne sont pas cosmetiques : `title` est un `varchar(255)` et
     * `max_attempts` un `smallint` en PostgreSQL. SQLite les accepte sans
     * broncher — la suite de tests ne pouvait donc pas voir ces deux 500.
     */
    private function assertSettings(string $title, int $passScore, string $revealMode, ?int $maxAttempts): void
    {
        if (mb_strlen($title) > 255) {
            throw ValidationException::withMessages([
                'title' => __('loops.cards.quiz.title_too_long'),
            ]);
        }

        // Un seuil a zero validerait un envoi vide : le score est toujours
        // superieur ou egal a zero, et « reussi » perdrait son sens.
        if ($passScore < 1 || $passScore > 100) {
            throw ValidationException::withMessages([
                'pass_score' => __('loops.cards.quiz.pass_score_invalid'),
            ]);
        }

        if ($maxAttempts !== null && ($maxAttempts < 1 || $maxAttempts > 999)) {
            throw ValidationException::withMessages([
                'max_attempts' => __('loops.cards.quiz.max_attempts_invalid'),
            ]);
        }

        if (! in_array($revealMode, CourseQuiz::REVEAL_MODES, true)) {
            throw ValidationException::withMessages([
                'reveal_mode' => __('loops.cards.quiz.reveal_invalid'),
            ]);
        }
    }

    /** Ouvrir le detail des bonnes reponses, en mode `after_release`. */
    public function release(CourseQuiz $quiz): CourseQuiz
    {
        $quiz->forceFill(['released_at' => now()])->save();

        return $quiz->fresh();
    }

    // ── Ce que le stagiaire passe ───────────────────────────────────────────

    /**
     * Soumettre une tentative. **Le seul endroit ou un score est produit.**
     *
     * @param  array<string, array<int, string>>  $answers  questionId => [optionIds]
     */
    public function attempt(CourseQuiz $quiz, User $student, array $answers): CourseQuizAttempt
    {
        if ($quiz->archived_at !== null) {
            throw ValidationException::withMessages([
                'quiz' => __('loops.cards.quiz.archived'),
            ]);
        }

        if ($quiz->questions->isEmpty()) {
            throw ValidationException::withMessages([
                'quiz' => __('loops.cards.quiz.no_question'),
            ]);
        }

        // Le cloisonnement ne se delegue pas a l'appelant. La Card le tient
        // deja, mais un service qui suppose son unique appelant devient faux au
        // deuxieme.
        if ($student->organization_id !== $quiz->organization_id) {
            throw ValidationException::withMessages([
                'quiz' => __('dossiers.content_not_in_dossier'),
            ]);
        }

        return DB::transaction(function () use ($quiz, $student, $answers) {
            $this->forget();

            $passees = CourseQuizAttempt::where('course_quiz_id', $quiz->id)
                ->where('user_id', $student->id)
                ->lockForUpdate()
                ->get();

            if ($quiz->max_attempts !== null && $passees->count() >= $quiz->max_attempts) {
                throw ValidationException::withMessages([
                    'quiz' => __('loops.cards.quiz.no_attempt_left'),
                ]);
            }

            [$score, $normalisees] = $this->grade($quiz, $answers);
            $reussi = $score >= $quiz->pass_score;

            // `max + 1` et non `count + 1` : un trou dans la numerotation — une
            // ligne supprimee — faisait reutiliser un rang deja pris et violait
            // l'index unique, avec un 500 a la cle.
            $rang = ((int) CourseQuizAttempt::where('course_quiz_id', $quiz->id)
                ->where('user_id', $student->id)
                ->max('attempt_number')) + 1;

            $tentative = CourseQuizAttempt::create([
                'organization_id' => $quiz->organization_id,
                'course_quiz_id' => $quiz->id,
                'user_id' => $student->id,
                'attempt_number' => $rang,
                'score' => $score,
                'passed' => $reussi,
                'answers' => $normalisees,
                'submitted_at' => now(),
            ]);

            // **La reussite ouvre l'etape ; le deblocage manuel reste distinct.**
            // `passed_quiz_id` est renseigne par la correction, jamais par une
            // main : un coup de pouce ne doit pas ressembler a un resultat.
            if ($reussi && $quiz->blocking && $quiz->course_sequence_id) {
                $this->markSequencePassed($quiz, $student);
            }

            return $tentative;
        });
    }

    /**
     * La correction, arithmetique et sans appel exterieur.
     *
     * Une question est juste quand l'ensemble coche est **exactement** celui
     * des bonnes reponses. Pas de point partiel : il faudrait un bareme, et un
     * bareme est un chantier que personne n'a demande.
     *
     * @param  array<string, array<int, string>>  $answers
     * @return array{0: int, 1: array<string, array<int, string>>}
     */
    private function grade(CourseQuiz $quiz, array $answers): array
    {
        $questions = $quiz->questions()->with('options')->get();
        $justes = 0;
        $normalisees = [];

        foreach ($questions as $question) {
            $cochees = collect($answers[$question->id] ?? [])
                ->filter(fn ($id) => is_string($id))
                ->unique()
                ->values();

            // Une question a choix unique n'accepte qu'une coche : en accepter
            // plusieurs laisserait tout cocher pour ne jamais se tromper.
            if ($question->expectsSingleAnswer()) {
                $cochees = $cochees->take(1)->values();
            }

            $attendues = $question->options->where('is_correct', true)->pluck('id')->sort()->values();
            $possibles = $question->options->pluck('id');

            // Une option d'une autre question ne compte pas : un identifiant
            // forge ne doit pas fabriquer une bonne reponse.
            $retenues = $cochees->filter(fn (string $id) => $possibles->contains($id))->sort()->values();

            $normalisees[$question->id] = $retenues->all();

            if ($attendues->isNotEmpty() && $retenues->all() === $attendues->all()) {
                $justes++;
            }
        }

        $score = $questions->isEmpty() ? 0 : (int) round($justes * 100 / $questions->count());

        return [$score, $normalisees];
    }

    private function markSequencePassed(CourseQuiz $quiz, User $student): void
    {
        CourseSequenceProgress::updateOrCreate(
            ['course_sequence_id' => $quiz->course_sequence_id, 'user_id' => $student->id],
            [
                'organization_id' => $quiz->organization_id,
                'status' => CourseSequenceProgress::STATUS_COMPLETED,
                'completed_at' => now(),
                'passed_quiz_id' => $quiz->id,
            ],
        );
    }

    // ── Lire ────────────────────────────────────────────────────────────────

    /** @return Collection<int, CourseQuiz> */
    public function activeQuizzes(Loop $loop): Collection
    {
        // Les questions et leurs options sont chargees **avec** : la vue les
        // parcourt toutes, et sans cela elle payait une requete par QCM puis
        // une par question. Le service etait plat ; c'est le rendu qui coutait.
        return CourseQuiz::where('loop_id', $loop->id)
            ->whereNull('archived_at')
            ->with('questions.options')
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, CourseQuizAttempt> */
    public function attemptsOf(CourseQuiz $quiz, User $student): Collection
    {
        $cle = $quiz->id.':'.$student->id;

        if (array_key_exists($cle, $this->cache)) {
            return $this->cache[$cle];
        }

        return $this->cache[$cle] = CourseQuizAttempt::where('course_quiz_id', $quiz->id)
            ->where('user_id', $student->id)
            ->orderBy('attempt_number')
            ->get();
    }

    public function bestAttempt(CourseQuiz $quiz, User $student): ?CourseQuizAttempt
    {
        return $this->attemptsOf($quiz, $student)->sortByDesc('score')->first();
    }

    public function attemptsLeft(CourseQuiz $quiz, User $student): ?int
    {
        if ($quiz->max_attempts === null) {
            return null;
        }

        return max(0, $quiz->max_attempts - $this->attemptsOf($quiz, $student)->count());
    }

    /**
     * Le detail des bonnes reponses est-il visible pour cette personne ?
     *
     * **Le score, lui, est toujours immediat** : c'est ce que tout le monde
     * attend. Le detail immediat rendrait les tentatives multiples sans objet,
     * et circulerait d'un stagiaire a l'autre.
     */
    public function revealsAnswersTo(CourseQuiz $quiz, User $student): bool
    {
        if ($this->attemptsOf($quiz, $student)->isEmpty()) {
            return false;
        }

        return match ($quiz->reveal_mode) {
            CourseQuiz::REVEAL_IMMEDIATE => true,
            CourseQuiz::REVEAL_AFTER_RELEASE => $quiz->released_at !== null,
            // Sans limite de tentatives, il n'y a **pas** de derniere : la
            // condition n'arrivait jamais, et le corrige restait invisible pour
            // toujours. Le formateur reprend alors la main — c'est le seul
            // moment ou « apres la derniere » a encore un sens.
            default => $quiz->max_attempts === null
                ? $quiz->released_at !== null
                : $this->attemptsLeft($quiz, $student) === 0,
        };
    }

    /**
     * @return Collection<int, array{quiz: CourseQuiz, best: ?CourseQuizAttempt, left: ?int, reveals: bool}>
     */
    public function overviewFor(Loop $loop, User $student): Collection
    {
        $this->warmUp($loop, collect([$student]));

        return $this->activeQuizzes($loop)->map(fn (CourseQuiz $q) => [
            'quiz' => $q,
            'best' => $this->bestAttempt($q, $student),
            'left' => $this->attemptsLeft($q, $student),
            'reveals' => $this->revealsAnswersTo($q, $student),
        ]);
    }

    /**
     * @param  Collection<int, User>  $students
     * @return Collection<int, array{user: User, cells: Collection<int, array{quiz: CourseQuiz, best: ?CourseQuizAttempt, tries: int}>}>
     */
    public function matrixFor(Loop $loop, Collection $students): Collection
    {
        $this->warmUp($loop, $students);
        $quizzes = $this->activeQuizzes($loop);

        return $students->map(fn (User $student) => [
            'user' => $student,
            'cells' => $quizzes->map(fn (CourseQuiz $q) => [
                'quiz' => $q,
                'best' => $this->bestAttempt($q, $student),
                'tries' => $this->attemptsOf($q, $student)->count(),
            ]),
        ]);
    }
}
