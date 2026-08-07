<?php

namespace App\Livewire;

use App\Models\CourseQuiz;
use App\Models\CourseQuizQuestion;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\Loops\CourseQuizService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * La Card QCM : **une seule Card, deux visages**.
 *
 * « Mes QCM » pour le stagiaire, la matrice des resultats pour l'Animateur.
 *
 * Quatre regles, chacune venue d'un defaut deja paye ailleurs dans cette
 * serie :
 *
 * 1. **Passer un QCM est une ecriture** — `quiz.attempt`, sans `read`. Une
 *    Boucle archivee n'en recoit plus.
 * 2. **Un score vient d'une tentative reelle** : aucun chemin ne le pose a la
 *    main, et rien ici ne peut fabriquer un acquis.
 * 3. **Debloquer n'est pas reussir** : le deblocage manuel reste le geste de la
 *    Card Progression, distinct et nomme.
 * 4. **Chaque geste a un chemin**, et un test qui le prouve — pas seulement son
 *    refus.
 *
 * **L'IA n'apparait nulle part.** Aucun service d'IA n'est importe.
 */
class LoopQuizCard extends Component
{
    public const FACE_MINE = 'mine';

    public const FACE_EVERYONE = 'everyone';

    public Loop $loop;

    public string $face = self::FACE_MINE;

    public bool $showForm = false;

    public string $title = '';

    public string $intro = '';

    public string $passScore = '70';

    public string $maxAttempts = '';

    public bool $blocking = false;

    public string $revealMode = CourseQuiz::REVEAL_AFTER_LAST;

    /** Le QCM ouvert pour redaction de question, cote Animateur. */
    public ?string $questionFormFor = null;

    public string $questionText = '';

    public string $questionKind = CourseQuizQuestion::KIND_SINGLE;

    /** @var array<int, array{text: string, correct: bool}> */
    public array $optionRows = [
        ['text' => '', 'correct' => true],
        ['text' => '', 'correct' => false],
    ];

    /** Le QCM en cours de passage, cote stagiaire. */
    public ?string $takingId = null;

    /** @var array<string, array<int, string>> */
    public array $answers = [];

    public string $flash = '';

    public string $problem = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    // ── Droits ──────────────────────────────────────────────────────────────

    public function canView(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'quiz.view');
    }

    /** Passer un QCM est une ecriture : une archive n'en recoit plus. */
    public function canAttempt(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'quiz.attempt');
    }

    public function canManage(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'quiz.manage');
    }

    // ── Gestes de l'Animateur ───────────────────────────────────────────────

    public function saveQuiz(): void
    {
        $this->authorizeManage();

        try {
            $this->service()->create(
                $this->loop,
                auth()->user(),
                $this->title,
                $this->intro,
                (int) ($this->passScore === '' ? 70 : $this->passScore),
                $this->maxAttempts === '' ? null : max(1, (int) $this->maxAttempts),
                $this->blocking,
                $this->revealMode,
            );
        } catch (ValidationException $e) {
            $this->addError(array_key_first($e->errors()), $e->getMessage());

            return;
        }

        $this->reset(['title', 'intro', 'passScore', 'maxAttempts', 'blocking', 'revealMode', 'showForm']);
        $this->problem = '';
        $this->flash = __('loops.cards.quiz.saved');
    }

    public function openQuestionForm(string $quizId): void
    {
        $this->authorizeManage();

        $quiz = $this->resolveQuiz($quizId);

        $this->questionFormFor = $this->questionFormFor === $quiz->id ? null : $quiz->id;
        $this->reset(['questionText', 'questionKind', 'optionRows']);
    }

    public function addOptionRow(): void
    {
        $this->authorizeManage();

        $this->optionRows[] = ['text' => '', 'correct' => false];
    }

    public function saveQuestion(string $quizId): void
    {
        $this->authorizeManage();

        $options = collect($this->optionRows)
            ->filter(fn (array $o) => filled($o['text'] ?? null))
            ->map(fn (array $o) => ['text' => $o['text'], 'correct' => (bool) ($o['correct'] ?? false)])
            ->values()
            ->all();

        try {
            $this->service()->addQuestion(
                $this->resolveQuiz($quizId),
                $this->questionText,
                $this->questionKind,
                $options,
            );
        } catch (ValidationException $e) {
            $this->addError('questionText', $e->getMessage());

            return;
        }

        $this->reset(['questionText', 'questionKind', 'optionRows', 'questionFormFor']);
        $this->flash = __('loops.cards.quiz.saved');
    }

    public function deleteQuiz(string $quizId): void
    {
        $this->authorizeManage();

        $this->service()->delete($this->resolveQuiz($quizId));

        $this->flash = __('loops.cards.quiz.removed');
    }

    public function release(string $quizId): void
    {
        $this->authorizeManage();

        $this->service()->release($this->resolveQuiz($quizId));

        $this->flash = __('loops.cards.quiz.released');
    }

    // ── Gestes du stagiaire ─────────────────────────────────────────────────

    public function startQuiz(string $quizId): void
    {
        $this->authorizeAttempt();

        $quiz = $this->resolveQuiz($quizId);

        $this->takingId = $this->takingId === $quiz->id ? null : $quiz->id;
        $this->answers = [];
        $this->problem = '';
    }

    /** Cocher une reponse. Le choix unique remplace, le multiple ajoute. */
    public function toggleAnswer(string $questionId, string $optionId, bool $single): void
    {
        $this->authorizeAttempt();

        if ($single) {
            $this->answers[$questionId] = [$optionId];

            return;
        }

        $deja = $this->answers[$questionId] ?? [];
        $this->answers[$questionId] = in_array($optionId, $deja, true)
            ? array_values(array_diff($deja, [$optionId]))
            : [...$deja, $optionId];
    }

    public function submitAttempt(string $quizId): void
    {
        $this->authorizeAttempt();

        try {
            $tentative = $this->service()->attempt($this->resolveQuiz($quizId), auth()->user(), $this->answers);
        } catch (ValidationException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->reset(['takingId', 'answers']);
        $this->problem = '';
        $this->flash = __('loops.cards.quiz.score', ['score' => $tentative->score]);
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function authorizeAttempt(): void
    {
        abort_unless($this->canAttempt(), 403);
    }

    /** Un QCM de **cette** Boucle, ou 404. */
    private function resolveQuiz(string $quizId): CourseQuiz
    {
        $quiz = CourseQuiz::where('loop_id', $this->loop->id)->whereKey($quizId)->first();

        abort_unless((bool) $quiz, 404);

        return $quiz;
    }

    /** @return Collection<int, User> */
    private function students(): Collection
    {
        return LoopMember::where('loop_id', $this->loop->id)
            ->where('status', 'active')
            ->with('user:id,first_name,name,email,organization_id,banned_at')
            ->get()
            ->pluck('user')
            ->filter(fn (?User $u) => $u && $u->banned_at === null)
            ->values();
    }

    private function service(): CourseQuizService
    {
        return app(CourseQuizService::class);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    public function render()
    {
        $canView = $this->canView();
        $canManage = $canView && $this->canManage();
        $face = $canManage ? $this->face : self::FACE_MINE;

        return view('livewire.loop-quiz-card', [
            'canView' => $canView,
            'canManage' => $canManage,
            'canAttempt' => $canView && $this->canAttempt(),
            'face' => $face,
            'overview' => $canView && $face === self::FACE_MINE
                ? $this->service()->overviewFor($this->loop, auth()->user())
                : collect(),
            'matrix' => $canManage && $face === self::FACE_EVERYONE
                ? $this->service()->matrixFor($this->loop, $this->students())
                : collect(),
        ]);
    }
}
