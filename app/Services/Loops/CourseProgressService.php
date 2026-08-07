<?php

namespace App\Services\Loops;

use App\Models\CourseModule;
use App\Models\CourseSequence;
use App\Models\CourseSequenceProgress;
use App\Models\CourseSetting;
use App\Models\Loop;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La progression individuelle : la structure est commune, l'etat est individuel.
 *
 * Ce service ne stocke que **ce qu'une personne a fait**. Tout le reste — une
 * etape est-elle ouverte, un Module est-il termine — se **calcule**. Stocker
 * une conclusion obligerait a la tenir a jour a chaque mouvement, et deux
 * verites finiraient par diverger au pire moment : celui ou quelqu'un croit
 * avoir fini.
 *
 * **Quatre regles d'ouverture, pas un moteur generique.** Un moteur coute cher,
 * se teste mal, et personne n'en utilise le dixieme.
 *
 * 1. apres l'etape precedente — le defaut ;
 * 2. disponible d'emblee — le parcours libre, choisi explicitement ;
 * 3. apres reussite d'un QCM — **pas encore livrable**, la Card QCM n'existe pas ;
 * 4. deblocage manuel du formateur — **qui prime toujours**.
 *
 * Quand plusieurs conditions s'appliquent, il faut **toutes** les remplir. Le
 * « et » plutot que le « ou » : l'erreur qu'il produit — une etape fermee a tort
 * — se corrige d'un clic, l'inverse ne se rattrape pas.
 *
 * **L'IA ne debloque jamais.** Aucun appel a un service d'IA n'existe ici, et
 * la decision d'ouvrir une etape appartient au formateur, sans exception.
 */
class CourseProgressService
{
    // ── Le reglage de parcours ──────────────────────────────────────────────

    /** Une Boucle sans reglage est **sequentielle** : le defaut sans rien ecrire. */
    public function pathMode(Loop $loop): string
    {
        return CourseSetting::where('loop_id', $loop->id)->value('path_mode')
            ?? CourseSetting::PATH_SEQUENTIAL;
    }

    public function setPathMode(Loop $loop, string $mode): CourseSetting
    {
        if (! in_array($mode, [CourseSetting::PATH_SEQUENTIAL, CourseSetting::PATH_FREE], true)) {
            throw ValidationException::withMessages([
                'path_mode' => __('loops.cards.progression.path_mode_invalid'),
            ]);
        }

        return CourseSetting::updateOrCreate(
            ['loop_id' => $loop->id],
            ['organization_id' => $loop->organization_id, 'path_mode' => $mode],
        );
    }

    // ── Lire un etat ────────────────────────────────────────────────────────

    /**
     * L'etat d'une personne sur une Sequence.
     *
     * Ce qui est en base l'emporte quand la personne a fait quelque chose :
     * avoir commence, avoir termine, ce sont des faits, et aucune regle
     * d'ouverture ne les efface. Sinon l'etat se deduit de l'ouverture.
     */
    public function statusFor(User $user, CourseSequence $sequence): string
    {
        $progress = $this->progressOf($user, $sequence);

        if ($progress && in_array($progress->status, CourseSequenceProgress::STORED_STATUSES, true)) {
            return $progress->status;
        }

        return $this->isAvailable($user, $sequence)
            ? CourseSequenceProgress::STATUS_AVAILABLE
            : CourseSequenceProgress::STATUS_UNAVAILABLE;
    }

    /**
     * Cette Sequence est-elle ouverte a cette personne ?
     *
     * L'ordre des verifications **est** la regle de priorite.
     */
    public function isAvailable(User $user, CourseSequence $sequence): bool
    {
        // Regle 4 — le deblocage manuel ecrase tout. En premier, donc, et sans
        // condition : un formateur qui ouvre une etape sait ce qu'il fait.
        if ($this->progressOf($user, $sequence)?->isManuallyUnlocked()) {
            return true;
        }

        $module = $sequence->module;

        if (! $module) {
            return false;
        }

        // Regle 2 — parcours libre : tout est ouvert d'emblee.
        if ($this->pathMode($module->loop) === CourseSetting::PATH_FREE) {
            return true;
        }

        // Regle 1 — apres l'etape precedente. « Precedente » se lit dans l'ordre
        // du parcours : les Sequences de ce Module, puis, si c'est la premiere,
        // la fin du Module d'avant.
        return $this->previousIsFinished($user, $sequence, $module);
    }

    /**
     * L'etat d'un Module, **calcule** depuis ses Sequences. Jamais stocke.
     *
     * Un Module est termine quand toutes ses Sequences vivantes le sont ; ouvert
     * des qu'une seule l'est ; entame des que quelqu'un y a touche. Un Module
     * sans Sequence n'est pas « termine » — il est vide, ce qui n'est pas la
     * meme chose et ne doit pas ouvrir le suivant.
     */
    public function moduleStatusFor(User $user, CourseModule $module): string
    {
        $sequences = $this->liveSequences($module);

        if ($sequences->isEmpty()) {
            return CourseSequenceProgress::STATUS_UNAVAILABLE;
        }

        $etats = $sequences->map(fn (CourseSequence $s) => $this->statusFor($user, $s));

        if ($etats->every(fn (string $e) => in_array($e, [
            CourseSequenceProgress::STATUS_COMPLETED,
            CourseSequenceProgress::STATUS_VALIDATED,
        ], true))) {
            return CourseSequenceProgress::STATUS_COMPLETED;
        }

        // « En cours » couvre aussi le Module dont **une partie** est terminee.
        // Avoir fini une Sequence est une activite : un Module a moitie fait
        // n'est pas « a faire ».
        if ($etats->contains(fn (string $e) => in_array($e, [
            CourseSequenceProgress::STATUS_IN_PROGRESS,
            CourseSequenceProgress::STATUS_SUBMITTED,
            CourseSequenceProgress::STATUS_REDO,
            CourseSequenceProgress::STATUS_COMPLETED,
            CourseSequenceProgress::STATUS_VALIDATED,
        ], true))) {
            return CourseSequenceProgress::STATUS_IN_PROGRESS;
        }

        return $etats->contains(CourseSequenceProgress::STATUS_AVAILABLE)
            ? CourseSequenceProgress::STATUS_AVAILABLE
            : CourseSequenceProgress::STATUS_UNAVAILABLE;
    }

    // ── Ecrire un etat ──────────────────────────────────────────────────────

    /**
     * Ouvrir une Sequence. **Ouvrir n'est pas faire** : cela passe `in_progress`
     * et rien de plus. La progression n'est jamais deduite du dernier acces.
     */
    public function start(User $user, CourseSequence $sequence): CourseSequenceProgress
    {
        $this->assertAvailable($user, $sequence);

        return DB::transaction(function () use ($user, $sequence) {
            $progress = $this->lockedProgress($user, $sequence);

            // Une Sequence deja terminee ne redevient pas « en cours » parce
            // qu'on la relit.
            if ($progress && $progress->status !== CourseSequenceProgress::STATUS_REDO
                && $progress->status !== CourseSequenceProgress::STATUS_AVAILABLE) {
                return $progress;
            }

            return $this->write($user, $sequence, CourseSequenceProgress::STATUS_IN_PROGRESS, [
                'started_at' => $progress?->started_at ?? now(),
            ]);
        });
    }

    /**
     * « J'ai termine » — declaratif, par la personne elle-meme.
     *
     * Une Sequence qui exige une validation passe `submitted` : elle attend un
     * regard, et se declarer termine ne suffit pas a l'etre.
     */
    public function markCompleted(User $user, CourseSequence $sequence): CourseSequenceProgress
    {
        $this->assertAvailable($user, $sequence);

        return DB::transaction(function () use ($user, $sequence) {
            $attendUnRegard = (bool) $sequence->requires_validation;

            return $this->write(
                $user,
                $sequence,
                $attendUnRegard
                    ? CourseSequenceProgress::STATUS_SUBMITTED
                    : CourseSequenceProgress::STATUS_COMPLETED,
                $attendUnRegard ? [] : ['completed_at' => now()],
            );
        });
    }

    /** Le formateur approuve. C'est le seul chemin vers `validated`. */
    public function validate(User $trainer, User $student, CourseSequence $sequence): CourseSequenceProgress
    {
        return DB::transaction(fn () => $this->write($student, $sequence, CourseSequenceProgress::STATUS_VALIDATED, [
            'completed_at' => $this->progressOf($student, $sequence)?->completed_at ?? now(),
            'validated_at' => now(),
            'validated_by' => $trainer->id,
        ]));
    }

    /** Le formateur demande une reprise. */
    public function requestRedo(User $trainer, User $student, CourseSequence $sequence): CourseSequenceProgress
    {
        return DB::transaction(fn () => $this->write($student, $sequence, CourseSequenceProgress::STATUS_REDO, [
            'validated_at' => null,
            'validated_by' => null,
        ]));
    }

    /**
     * Ouvrir une etape a la main. La regle qui prime sur toutes les autres.
     *
     * Elle **n'avance pas** la progression : elle ouvre. La personne reste a
     * l'etat ou elle etait — souvent aucun — et peut maintenant travailler.
     * Debloquer et valider sont deux gestes differents.
     */
    public function unlock(User $trainer, User $student, CourseSequence $sequence): CourseSequenceProgress
    {
        return DB::transaction(function () use ($trainer, $student, $sequence) {
            $progress = $this->lockedProgress($student, $sequence);

            return $this->write(
                $student,
                $sequence,
                $progress?->status ?? CourseSequenceProgress::STATUS_IN_PROGRESS,
                ['unlocked_at' => now(), 'unlocked_by' => $trainer->id],
            );
        });
    }

    // ── Vues ────────────────────────────────────────────────────────────────

    /**
     * Ou en est cette personne, Module par Module.
     *
     * @return Collection<int, array{module: CourseModule, status: string, sequences: Collection<int, array{sequence: CourseSequence, status: string, number: string}>}>
     */
    public function overviewFor(User $user, Loop $loop): Collection
    {
        return $this->modulesOf($loop)->map(fn (CourseModule $module) => [
            'module' => $module,
            'status' => $this->moduleStatusFor($user, $module),
            'sequences' => $this->liveSequences($module)->values()->map(
                fn (CourseSequence $sequence, int $index) => [
                    'sequence' => $sequence,
                    'status' => $this->statusFor($user, $sequence),
                    'number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                ]
            ),
        ]);
    }

    /**
     * La matrice du formateur : une ligne par membre, une colonne par Module.
     *
     * @param  Collection<int, User>  $students
     * @return Collection<int, array{user: User, modules: Collection<int, array{module: CourseModule, status: string}>, awaiting: int}>
     */
    public function matrixFor(Loop $loop, Collection $students): Collection
    {
        $modules = $this->modulesOf($loop);

        return $students->map(function (User $student) use ($modules) {
            $lignes = $modules->map(fn (CourseModule $module) => [
                'module' => $module,
                'status' => $this->moduleStatusFor($student, $module),
            ]);

            return [
                'user' => $student,
                'modules' => $lignes,
                // Ce qui attend **un geste du formateur**, et lui seul : c'est
                // la colonne qu'on regarde en premier.
                'awaiting' => $modules->sum(fn (CourseModule $module) => $this->liveSequences($module)
                    ->filter(fn (CourseSequence $s) => $this->statusFor($student, $s) === CourseSequenceProgress::STATUS_SUBMITTED)
                    ->count()),
            ];
        });
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** @return Collection<int, CourseModule> */
    private function modulesOf(Loop $loop): Collection
    {
        return CourseModule::where('loop_id', $loop->id)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Les Sequences qui comptent : les archivees n'en font plus partie.
     *
     * Une Sequence archivee garde les progressions qui la concernent — elles
     * decrivent ce qui s'est passe — mais elle ne bloque plus personne.
     *
     * @return Collection<int, CourseSequence>
     */
    private function liveSequences(CourseModule $module): Collection
    {
        return $module->sequences()->whereNull('archived_at')->get();
    }

    private function progressOf(User $user, CourseSequence $sequence): ?CourseSequenceProgress
    {
        return CourseSequenceProgress::where('course_sequence_id', $sequence->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function lockedProgress(User $user, CourseSequence $sequence): ?CourseSequenceProgress
    {
        return CourseSequenceProgress::where('course_sequence_id', $sequence->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * L'etape qui precede est-elle terminee ?
     *
     * « Precedente » se lit dans l'ordre du parcours : la Sequence d'avant dans
     * ce Module, ou — pour la premiere Sequence — la fin du Module precedent.
     * La toute premiere etape d'une Formation est toujours ouverte : sans cela
     * personne ne pourrait jamais commencer.
     */
    private function previousIsFinished(User $user, CourseSequence $sequence, CourseModule $module): bool
    {
        $sequences = $this->liveSequences($module)->values();
        $rang = $sequences->search(fn (CourseSequence $s) => $s->id === $sequence->id);

        if ($rang === false) {
            // Archivee : elle n'ouvre rien et ne se travaille plus.
            return false;
        }

        if ($rang > 0) {
            return $this->isFinishedFor($user, $sequences[$rang - 1]);
        }

        $precedent = CourseModule::where('loop_id', $module->loop_id)
            ->where(fn ($q) => $q->where('position', '<', $module->position)
                ->orWhere(fn ($q2) => $q2->where('position', $module->position)
                    ->where('created_at', '<', $module->created_at)))
            ->orderByDesc('position')
            ->orderByDesc('created_at')
            ->first();

        if (! $precedent) {
            return true;
        }

        return $this->moduleStatusFor($user, $precedent) === CourseSequenceProgress::STATUS_COMPLETED;
    }

    private function isFinishedFor(User $user, CourseSequence $sequence): bool
    {
        return (bool) $this->progressOf($user, $sequence)?->isFinished();
    }

    private function assertAvailable(User $user, CourseSequence $sequence): void
    {
        if (! $this->isAvailable($user, $sequence)) {
            throw ValidationException::withMessages([
                'sequence' => __('loops.cards.progression.sequence_locked'),
            ]);
        }
    }

    /** @param  array<string, mixed>  $attributs */
    private function write(User $user, CourseSequence $sequence, string $status, array $attributs): CourseSequenceProgress
    {
        return CourseSequenceProgress::updateOrCreate(
            ['course_sequence_id' => $sequence->id, 'user_id' => $user->id],
            array_merge([
                'organization_id' => $sequence->organization_id,
                'status' => $status,
            ], $attributs),
        );
    }
}
