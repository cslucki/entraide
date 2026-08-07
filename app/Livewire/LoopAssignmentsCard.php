<?php

namespace App\Livewire;

use App\Models\CourseAssignment;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\Loops\CourseAssignmentService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * La Card Travaux a rendre : **une seule Card, deux visages**.
 *
 * « Mes Travaux » pour le stagiaire, la matrice pour l'Animateur — meme forme
 * que la Progression, et pour la meme raison : deux Cards obligeraient a
 * choisir laquelle montrer selon le role, ce que le socle ne sait pas faire.
 *
 * Trois regles apprises sur les Cards precedentes sont tenues ici des la
 * conception :
 *
 * 1. **Le cout ne croit pas avec la classe** : le service charge les remises en
 *    une requete, rien n'interroge la base dans une boucle de rendu.
 * 2. **Un acquis ne regresse jamais** : retirer un Travail rendu l'archive.
 * 3. **Chaque geste a un chemin** : les six actions publiques sont rendues dans
 *    la vue, et un test le verifie — pas seulement leur refus.
 */
class LoopAssignmentsCard extends Component
{
    public const FACE_MINE = 'mine';

    public const FACE_EVERYONE = 'everyone';

    public Loop $loop;

    public string $face = self::FACE_MINE;

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $title = '';

    public string $brief = '';

    public string $dueAt = '';

    /** Le Travail ouvert cote stagiaire, et le brouillon en cours. */
    public ?string $openAssignmentId = null;

    public string $body = '';

    /** La cellule depliee cote Animateur. */
    public ?string $reviewAssignmentId = null;

    public ?string $reviewUserId = null;

    public string $feedback = '';

    public string $flash = '';

    /** Une erreur n'est pas un succes : elle a sa propre banniere. */
    public string $problem = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    // ── Droits ──────────────────────────────────────────────────────────────

    public function canView(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'assignments.view');
    }

    public function canManage(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'assignments.manage');
    }

    /**
     * Le droit de **remettre**, distinct du droit de voir.
     *
     * Remettre est une ecriture : le resolveur la refuse sur une Boucle
     * archivee, ce qu'une permission de lecture ne faisait pas.
     */
    public function canSubmit(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'assignments.submit');
    }

    // ── Gestes de l'Animateur ───────────────────────────────────────────────

    public function saveAssignment(): void
    {
        $this->authorizeManage();

        try {
            if ($this->editingId) {
                $this->service()->update(
                    $this->resolveAssignment($this->editingId),
                    $this->title,
                    $this->brief,
                    $this->parsedDueAt(),
                );
            } else {
                $this->service()->create(
                    $this->loop,
                    auth()->user(),
                    $this->title,
                    $this->brief,
                    $this->parsedDueAt(),
                );
            }
        } catch (ValidationException $e) {
            $this->addError(array_key_first($e->errors()) === 'dueAt' ? 'dueAt' : 'title', $e->getMessage());

            return;
        }

        $this->reset(['title', 'brief', 'dueAt', 'showForm', 'editingId']);
        $this->problem = '';
        $this->flash = __('loops.cards.assignments.saved');
    }

    public function startEditing(string $assignmentId): void
    {
        $this->authorizeManage();

        $a = $this->resolveAssignment($assignmentId);

        $this->flash = '';
        $this->problem = '';

        $this->editingId = $a->id;
        $this->title = $a->title;
        $this->brief = (string) $a->brief;
        $this->dueAt = $a->due_at?->format('Y-m-d\TH:i') ?? '';
        $this->showForm = true;
    }

    public function deleteAssignment(string $assignmentId): void
    {
        $this->authorizeManage();

        $this->service()->delete($this->resolveAssignment($assignmentId));

        $this->flash = __('loops.cards.assignments.removed');
    }

    public function toggleReview(string $assignmentId, string $userId): void
    {
        $this->authorizeManage();

        $meme = $this->reviewAssignmentId === $assignmentId && $this->reviewUserId === $userId;

        $this->reviewAssignmentId = $meme ? null : $assignmentId;
        $this->reviewUserId = $meme ? null : $userId;
        $this->feedback = $meme ? '' : (string) $this->service()->submissionOf(
            $this->resolveAssignment($assignmentId),
            $this->resolveMemberUser($userId),
        )?->feedback;
    }

    public function validateSubmission(string $assignmentId, string $userId): void
    {
        $this->authorizeManage();

        $this->service()->validateSubmission(
            $this->resolveAssignment($assignmentId),
            $this->resolveMemberUser($userId),
            auth()->user(),
            $this->feedback ?: null,
        );

        $this->reset(['reviewAssignmentId', 'reviewUserId', 'feedback']);
        $this->flash = __('loops.cards.assignments.status_validated');
    }

    public function requestRedo(string $assignmentId, string $userId): void
    {
        $this->authorizeManage();

        $this->service()->requestRedo(
            $this->resolveAssignment($assignmentId),
            $this->resolveMemberUser($userId),
            auth()->user(),
            $this->feedback ?: null,
        );

        $this->reset(['reviewAssignmentId', 'reviewUserId', 'feedback']);
        $this->flash = __('loops.cards.assignments.status_redo');
    }

    // ── Gestes du stagiaire ─────────────────────────────────────────────────

    public function openAssignment(string $assignmentId): void
    {
        $this->authorizeView();

        $a = $this->resolveAssignment($assignmentId);
        $meme = $this->openAssignmentId === $a->id;

        $this->openAssignmentId = $meme ? null : $a->id;
        $this->body = $meme ? '' : (string) $this->service()->submissionOf($a, auth()->user())?->body;
    }

    public function saveDraft(string $assignmentId): void
    {
        $this->authorizeSubmit();

        try {
            $this->service()->saveDraft($this->resolveAssignment($assignmentId), auth()->user(), $this->body ?: null);
        } catch (ValidationException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->problem = '';
        $this->flash = __('loops.cards.assignments.draft_saved');
    }

    public function submit(string $assignmentId): void
    {
        $this->authorizeSubmit();

        try {
            $this->service()->submit($this->resolveAssignment($assignmentId), auth()->user(), $this->body ?: null);
        } catch (ValidationException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->problem = '';
        $this->openAssignmentId = null;
        $this->flash = __('loops.cards.assignments.submission_sent');
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    private function authorizeView(): void
    {
        abort_unless($this->canView(), 403);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function authorizeSubmit(): void
    {
        abort_unless($this->canSubmit(), 403);
    }

    /** Un Travail de **cette** Boucle, ou 404. */
    private function resolveAssignment(string $assignmentId): CourseAssignment
    {
        $a = CourseAssignment::where('loop_id', $this->loop->id)->whereKey($assignmentId)->first();

        abort_unless((bool) $a, 404);

        return $a;
    }

    /** Un membre **actif** de cette Boucle, ou 404. */
    private function resolveMemberUser(string $userId): User
    {
        $membre = LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        // Un compte banni sort de la matrice — il doit donc sortir aussi des
        // gestes. Sans ce controle, le formateur pouvait ecrire sur quelqu'un
        // qu'il ne voit plus : une ecriture sans lecture.
        abort_unless($membre && $membre->user && $membre->user->banned_at === null, 404);

        return $membre->user;
    }

    private function parsedDueAt(): ?\DateTimeInterface
    {
        if (blank($this->dueAt)) {
            return null;
        }

        try {
            $date = \Illuminate\Support\Carbon::parse($this->dueAt);
        } catch (\Throwable) {
            // Avaler l'exception faisait disparaitre la date saisie sans un
            // mot : une faute de frappe coutait l'echeance en silence.
            throw ValidationException::withMessages([
                'dueAt' => __('loops.cards.assignments.due_invalid'),
            ]);
        }

        // Carbon accepte « 0000-00-00 » et rend une date negative, affichee
        // « 30/11/-001 » et perpetuellement en retard.
        if ($date->year < 2000 || $date->year > 2200) {
            throw ValidationException::withMessages([
                'dueAt' => __('loops.cards.assignments.due_invalid'),
            ]);
        }

        return $date;
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

    private function service(): CourseAssignmentService
    {
        return app(CourseAssignmentService::class);
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

        return view('livewire.loop-assignments-card', [
            'canView' => $canView,
            'canManage' => $canManage,
            'canSubmit' => $canView && $this->canSubmit(),
            'archived' => $canManage ? $this->service()->archivedAssignments($this->loop) : collect(),
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
