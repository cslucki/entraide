<?php

namespace App\Services\Loops;

use App\Models\CourseAssignment;
use App\Models\CourseSubmission;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les Travaux a rendre : ce qu'on demande, et ce que chacun remet.
 *
 * Meme partage que la Progression — la structure est commune, l'etat est
 * individuel — et les memes trois regles apprises en chemin :
 *
 * 1. **Le cout ne doit pas exploser avec la taille de la classe.** `warmUp()`
 *    charge toutes les remises en une requete ; rien ici n'interroge la base
 *    dans une boucle.
 * 2. **Un acquis ne regresse jamais.** Retirer un Travail deja rendu l'archive
 *    au lieu de le supprimer, et l'archivage ne referme rien.
 * 3. **Une capacite n'existe que si quelqu'un peut l'atteindre.** Chaque geste
 *    de ce service a un chemin dans la Card, et un test qui le prouve.
 *
 * **Aucun second systeme de fichiers ni d'editeur** : une remise qui porte un
 * fichier pointe vers un `DossierFile` existant.
 */
class CourseAssignmentService
{
    /** @var array<string, CourseSubmission|null> */
    private array $cache = [];

    /**
     * Charger d'un coup les remises qui vont servir.
     *
     * @param  Collection<int, User>  $users
     */
    public function warmUp(Loop $loop, Collection $users): void
    {
        $assignmentIds = CourseAssignment::where('loop_id', $loop->id)->pluck('id');

        if ($assignmentIds->isEmpty() || $users->isEmpty()) {
            return;
        }

        // Les cles absentes valent `null` : une remise inexistante est une
        // reponse, et la redemander a chaque cellule est ce qui coute.
        foreach ($assignmentIds as $assignmentId) {
            foreach ($users as $user) {
                $this->cache[$assignmentId.':'.$user->id] = null;
            }
        }

        CourseSubmission::whereIn('course_assignment_id', $assignmentIds)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->each(function (CourseSubmission $row) {
                $this->cache[$row->course_assignment_id.':'.$row->user_id] = $row;
            });
    }

    private function forget(): void
    {
        $this->cache = [];
    }

    // ── Ce que le formateur demande ─────────────────────────────────────────

    public function create(
        Loop $loop,
        User $actor,
        string $title,
        ?string $brief = null,
        ?\DateTimeInterface $dueAt = null,
        ?string $sequenceId = null,
    ): CourseAssignment {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        return DB::transaction(function () use ($loop, $actor, $title, $brief, $dueAt, $sequenceId) {
            $locked = Loop::whereKey($loop->id)->lockForUpdate()->firstOrFail();

            return CourseAssignment::create([
                'organization_id' => $locked->organization_id,
                'loop_id' => $locked->id,
                'course_sequence_id' => $sequenceId,
                'title' => $title,
                'brief' => filled($brief) ? $brief : null,
                'due_at' => $dueAt,
                'position' => ((int) CourseAssignment::where('loop_id', $locked->id)->max('position')) + 1,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function update(CourseAssignment $assignment, string $title, ?string $brief = null, ?\DateTimeInterface $dueAt = null): CourseAssignment
    {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        // **Modifier un enonce ne touche a aucune remise.** Corriger une faute
        // ne doit pas renvoyer vingt personnes a la case depart ; si le
        // changement est substantiel, le formateur demande explicitement une
        // reprise.
        $assignment->update([
            'title' => $title,
            'brief' => filled($brief) ? $brief : null,
            'due_at' => $dueAt,
        ]);

        return $assignment->fresh();
    }

    /**
     * Retirer un Travail.
     *
     * **Il s'archive des qu'une remise existe.** Effacer la ligne effacerait ce
     * que des gens ont rendu. Et un Travail archive **sort du parcours actif** :
     * il ne bloque plus rien et ne compte plus dans ce qui reste a faire.
     *
     * Sans remise, rien a preserver : il part.
     */
    public function delete(CourseAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $this->forget();

            if (CourseSubmission::where('course_assignment_id', $assignment->id)->exists()) {
                $assignment->forceFill(['archived_at' => now()])->save();

                return;
            }

            $assignment->delete();

            $this->renumber($assignment->loop_id);
        });
    }

    // ── Ce que le stagiaire remet ───────────────────────────────────────────

    /**
     * Enregistrer un brouillon, sans le remettre.
     *
     * Ecrire n'est pas rendre : tant que la personne n'a pas remis, rien
     * n'arrive dans la file du formateur.
     */
    public function saveDraft(CourseAssignment $assignment, User $student, ?string $body, ?DossierFile $file = null): CourseSubmission
    {
        $this->assertOpen($assignment);

        if ($file) {
            $this->assertSameOrganization($file, $assignment);
        }

        return DB::transaction(function () use ($assignment, $student, $body, $file) {
            $existante = $this->submissionOf($assignment, $student);

            // Une remise validee ne redevient pas un brouillon parce qu'on
            // reouvre le formulaire. Defaire la decision du formateur n'est pas
            // au stagiaire.
            if ($existante && $existante->status === CourseSubmission::STATUS_VALIDATED) {
                return $existante;
            }

            return $this->write($assignment, $student, [
                'body' => $body,
                'dossier_file_id' => $file?->id ?? $existante?->dossier_file_id,
                'status' => CourseSubmission::STATUS_DRAFT,
            ]);
        });
    }

    /** Remettre. C'est ce geste, et lui seul, qui met le Travail dans la file. */
    public function submit(CourseAssignment $assignment, User $student, ?string $body = null, ?DossierFile $file = null): CourseSubmission
    {
        $this->assertOpen($assignment);

        if ($file) {
            $this->assertSameOrganization($file, $assignment);
        }

        return DB::transaction(function () use ($assignment, $student, $body, $file) {
            $existante = $this->submissionOf($assignment, $student);

            if ($existante && $existante->status === CourseSubmission::STATUS_VALIDATED) {
                return $existante;
            }

            $corps = $body ?? $existante?->body;
            $fichier = $file?->id ?? $existante?->dossier_file_id;

            // Une remise vide n'est pas une remise : elle occuperait la file du
            // formateur sans rien lui donner a lire.
            if (blank($corps) && $fichier === null) {
                throw ValidationException::withMessages([
                    'body' => __('loops.cards.assignments.submission_empty'),
                ]);
            }

            return $this->write($assignment, $student, [
                'body' => $corps,
                'dossier_file_id' => $fichier,
                'status' => CourseSubmission::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);
        });
    }

    // ── Ce que le formateur prononce ────────────────────────────────────────

    public function validateSubmission(CourseAssignment $assignment, User $student, User $trainer, ?string $feedback = null): CourseSubmission
    {
        return DB::transaction(fn () => $this->write($assignment, $student, [
            'status' => CourseSubmission::STATUS_VALIDATED,
            'feedback' => $feedback,
            'reviewed_at' => now(),
            'reviewed_by' => $trainer->id,
        ]));
    }

    public function requestRedo(CourseAssignment $assignment, User $student, User $trainer, ?string $feedback = null): CourseSubmission
    {
        return DB::transaction(fn () => $this->write($assignment, $student, [
            'status' => CourseSubmission::STATUS_REDO,
            'feedback' => $feedback,
            'reviewed_at' => now(),
            'reviewed_by' => $trainer->id,
        ]));
    }

    // ── Lire ────────────────────────────────────────────────────────────────

    /** @return Collection<int, CourseAssignment> */
    public function activeAssignments(Loop $loop): Collection
    {
        // Les archives sortent du parcours actif : ils gardent les remises mais
        // ne comptent plus dans ce qui reste a faire.
        return CourseAssignment::where('loop_id', $loop->id)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    public function statusFor(CourseAssignment $assignment, User $student): string
    {
        return $this->submissionOf($assignment, $student)?->status
            ?? CourseSubmission::STATUS_DRAFT;
    }

    /**
     * Ce que cette personne a a rendre, et ou elle en est.
     *
     * @return Collection<int, array{assignment: CourseAssignment, submission: ?CourseSubmission, status: string, overdue: bool}>
     */
    public function overviewFor(Loop $loop, User $student): Collection
    {
        $this->warmUp($loop, collect([$student]));

        return $this->activeAssignments($loop)->map(fn (CourseAssignment $a) => [
            'assignment' => $a,
            'submission' => $this->submissionOf($a, $student),
            'status' => $this->statusFor($a, $student),
            'overdue' => $a->isOverdue(),
        ]);
    }

    /**
     * La matrice du formateur : une ligne par membre, une colonne par Travail.
     *
     * @param  Collection<int, User>  $students
     * @return Collection<int, array{user: User, cells: Collection<int, array{assignment: CourseAssignment, submission: ?CourseSubmission, status: string}>, awaiting: int}>
     */
    public function matrixFor(Loop $loop, Collection $students): Collection
    {
        $this->warmUp($loop, $students);
        $assignments = $this->activeAssignments($loop);

        return $students->map(function (User $student) use ($assignments) {
            $cellules = $assignments->map(fn (CourseAssignment $a) => [
                'assignment' => $a,
                'submission' => $this->submissionOf($a, $student),
                'status' => $this->statusFor($a, $student),
            ]);

            return [
                'user' => $student,
                'cells' => $cellules,
                // Ce qui attend un geste du formateur, et lui seul.
                'awaiting' => $cellules->filter(
                    fn (array $c) => $c['status'] === CourseSubmission::STATUS_SUBMITTED
                )->count(),
            ];
        });
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    public function submissionOf(CourseAssignment $assignment, User $student): ?CourseSubmission
    {
        $cle = $assignment->id.':'.$student->id;

        if (array_key_exists($cle, $this->cache)) {
            return $this->cache[$cle];
        }

        return $this->cache[$cle] = CourseSubmission::where('course_assignment_id', $assignment->id)
            ->where('user_id', $student->id)
            ->first();
    }

    /** Un Travail archive n'accepte plus de remise. */
    private function assertOpen(CourseAssignment $assignment): void
    {
        if ($assignment->archived_at !== null) {
            throw ValidationException::withMessages([
                'assignment' => __('loops.cards.assignments.archived'),
            ]);
        }
    }

    private function assertSameOrganization(DossierFile $file, CourseAssignment $assignment): void
    {
        if ($file->organization_id !== $assignment->organization_id) {
            throw ValidationException::withMessages([
                'dossier_file_id' => __('dossiers.content_not_in_dossier'),
            ]);
        }
    }

    /** @param  array<string, mixed>  $attributs */
    private function write(CourseAssignment $assignment, User $student, array $attributs): CourseSubmission
    {
        $this->forget();

        $cle = ['course_assignment_id' => $assignment->id, 'user_id' => $student->id];
        $valeurs = array_merge(['organization_id' => $assignment->organization_id], $attributs);

        try {
            return CourseSubmission::updateOrCreate($cle, $valeurs);
        } catch (UniqueConstraintViolationException) {
            // Deux premieres ecritures simultanees passent toutes les deux : le
            // verrou ne verrouille rien tant que la ligne n'existe pas. L'index
            // unique tranche, et le perdant met a jour au lieu de recevoir 500.
            return tap(
                CourseSubmission::where($cle)->firstOrFail(),
                fn (CourseSubmission $ligne) => $ligne->update($valeurs)
            );
        }
    }

    private function renumber(string $loopId): void
    {
        CourseAssignment::where('loop_id', $loopId)
            ->orderBy('position')
            ->get()
            ->each(fn (CourseAssignment $row, int $rang) => $row->forceFill(['position' => $rang])->save());
    }
}
