<?php

namespace App\Services\Loops;

use App\Models\BlogPost;
use App\Models\CourseModule;
use App\Models\CourseSequence;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les regles du Support de cours, en un seul endroit.
 *
 * Ecrit sur le modele de `DossierSeriesService`, et pour la meme raison : les
 * invariants d'un contenu ordonne se redisent mal. Le composant Livewire garde
 * l'autorisation et l'affichage ; ce service tient les transactions, les
 * verrous et le cloisonnement.
 *
 * Il ne cree **aucun** second systeme documentaire. Une Sequence qui porte un
 * Article ou un fichier porte une **reference** : rien n'est recopie, donc rien
 * ne diverge de l'original quand celui-ci change.
 */
class CourseMaterialService
{
    // ── Modules ─────────────────────────────────────────────────────────────

    public function createModule(Loop $loop, User $actor, string $title, ?string $summary = null): CourseModule
    {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        return DB::transaction(function () use ($loop, $actor, $title, $summary) {
            // Le verrou serialise les creations dans CETTE Boucle : sans lui,
            // deux ajouts simultanes lisaient le meme maximum de position.
            $locked = Loop::whereKey($loop->id)->lockForUpdate()->firstOrFail();

            return CourseModule::create([
                'organization_id' => $locked->organization_id,
                'loop_id' => $locked->id,
                'title' => $title,
                'summary' => filled($summary) ? trim($summary) : null,
                'position' => ((int) CourseModule::where('loop_id', $locked->id)->max('position')) + 1,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function updateModule(CourseModule $module, string $title, ?string $summary = null): CourseModule
    {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        $module->update([
            'title' => $title,
            'summary' => filled($summary) ? trim($summary) : null,
        ]);

        return $module->fresh();
    }

    /**
     * Supprimer un Module et ses Sequences.
     *
     * **Aucun Article ni fichier n'est supprime.** Une Sequence qui pointait
     * vers un Article disparait ; l'Article reste dans son Dossier. Un Support
     * de cours est une mise en ordre, pas un coffre.
     */
    public function deleteModule(CourseModule $module): void
    {
        DB::transaction(function () use ($module) {
            $loopId = $module->loop_id;

            CourseSequence::where('course_module_id', $module->id)->delete();
            $module->delete();

            $this->renumberModules($loopId);
        });
    }

    // ── Sequences ───────────────────────────────────────────────────────────

    /**
     * Ajouter une Sequence a un Module.
     *
     * Un contenu **au plus** : un Article, un fichier, ou rien — auquel cas la
     * Sequence porte son propre texte. Les deux references ensemble sont
     * refusees plutot que tranchees : deviner ce qu'on voulait rattacher, c'est
     * rattacher la mauvaise chose une fois sur deux.
     */
    public function addSequence(
        CourseModule $module,
        User $actor,
        string $title,
        ?string $body = null,
        BlogPost|DossierFile|null $reference = null,
    ): CourseSequence {
        $title = trim($title);

        if ($title === '' && $reference === null) {
            throw ValidationException::withMessages([
                'title' => __('validation.required', ['attribute' => 'title']),
            ]);
        }

        if ($reference !== null) {
            $this->assertSameOrganization($reference, $module);
        }

        return DB::transaction(function () use ($module, $actor, $title, $body, $reference) {
            $locked = CourseModule::whereKey($module->id)->lockForUpdate()->firstOrFail();

            return CourseSequence::create([
                'organization_id' => $locked->organization_id,
                'course_module_id' => $locked->id,
                'title' => $title,
                'body' => $reference === null && filled($body) ? $body : null,
                'blog_post_id' => $reference instanceof BlogPost ? $reference->id : null,
                'dossier_file_id' => $reference instanceof DossierFile ? $reference->id : null,
                'position' => ((int) CourseSequence::where('course_module_id', $locked->id)->max('position')) + 1,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function deleteSequence(CourseSequence $sequence): void
    {
        DB::transaction(function () use ($sequence) {
            $moduleId = $sequence->course_module_id;

            // Le contenu reference n'est jamais touche : seule la Sequence
            // disparait.
            $sequence->delete();

            $this->renumberSequences($moduleId);
        });
    }

    // ── Classement ──────────────────────────────────────────────────────────

    /**
     * Reordonner les Modules d'une Boucle.
     *
     * Un classement bati sur une liste devenue obsolete est refuse **en
     * entier** : appliquer la moitie d'un ordre est pire que le refuser.
     *
     * @param  array<int, string>  $moduleIds
     */
    public function reorderModules(Loop $loop, array $moduleIds): void
    {
        DB::transaction(function () use ($loop, $moduleIds) {
            $rows = CourseModule::where('loop_id', $loop->id)
                ->whereIn('id', $moduleIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($rows->count() !== count(array_unique($moduleIds))
                || $rows->count() !== CourseModule::where('loop_id', $loop->id)->count()) {
                throw ValidationException::withMessages([
                    'modules' => __('dossiers.reorder_invalid'),
                ]);
            }

            foreach (array_values($moduleIds) as $rang => $id) {
                $rows[$id]->forceFill(['position' => $rang])->save();
            }
        });
    }

    /**
     * Reordonner les Sequences d'un Module.
     *
     * @param  array<int, string>  $sequenceIds
     */
    public function reorderSequences(CourseModule $module, array $sequenceIds): void
    {
        DB::transaction(function () use ($module, $sequenceIds) {
            $locked = CourseModule::whereKey($module->id)->lockForUpdate()->firstOrFail();

            $rows = CourseSequence::where('course_module_id', $locked->id)
                ->whereIn('id', $sequenceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($rows->count() !== count(array_unique($sequenceIds))
                || $rows->count() !== CourseSequence::where('course_module_id', $locked->id)->count()) {
                throw ValidationException::withMessages([
                    'sequences' => __('dossiers.reorder_invalid'),
                ]);
            }

            foreach (array_values($sequenceIds) as $rang => $id) {
                $rows[$id]->forceFill(['position' => $rang])->save();
            }
        });
    }

    // ── Invariants ──────────────────────────────────────────────────────────

    /**
     * Le contenu reference appartient-il a la meme Organization ?
     *
     * C'est le seul cloisonnement que ce service ait a faire lui-meme : un
     * identifiant forge peut designer un Article ou un fichier d'une autre
     * Organization entierement.
     */
    private function assertSameOrganization(BlogPost|DossierFile $reference, CourseModule $module): void
    {
        if ($reference->organization_id !== $module->organization_id) {
            throw ValidationException::withMessages([
                'reference' => __('dossiers.content_not_in_dossier'),
            ]);
        }
    }

    /** Des rangs contigus, de 0 a n-1. */
    private function renumberModules(string $loopId): void
    {
        CourseModule::where('loop_id', $loopId)
            ->orderBy('position')
            ->get()
            ->each(fn (CourseModule $row, int $rang) => $row->forceFill(['position' => $rang])->save());
    }

    private function renumberSequences(string $moduleId): void
    {
        CourseSequence::where('course_module_id', $moduleId)
            ->orderBy('position')
            ->get()
            ->each(fn (CourseSequence $row, int $rang) => $row->forceFill(['position' => $rang])->save());
    }
}
