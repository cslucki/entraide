<?php

namespace App\Livewire;

use App\Models\CourseModule;
use App\Models\CourseSequence;
use App\Models\Loop;
use App\Services\Loops\CourseMaterialService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * La Card Support de cours d'une Boucle Formation.
 *
 * Elle porte des **Modules**, et dans chacun des **Sequences**. Ni les uns ni
 * les autres ne sont des Cards : ce sont les contenus de celle-ci, comme un
 * Article est le contenu d'un Dossier. Ils ne sont declares dans aucun
 * catalogue et n'ont aucune permission a eux — ce qu'on a le droit d'en faire
 * depend entierement de cette Card.
 *
 * Aucune regle n'est tenue ici : `CourseMaterialService` a les transactions,
 * les verrous et le cloisonnement. Ce composant garde l'autorisation, l'etat
 * des fenetres et l'affichage.
 *
 * Les numeros — `01`, `02`, … — sont **calcules** a partir du rang, jamais
 * ecrits dans un titre. Un numero recopie devient faux au premier deplacement.
 */
class LoopCourseMaterialCard extends Component
{
    public Loop $loop;

    /** L'etat des fenetres vit **cote serveur** : fermer une fenetre doit se
     *  voir dans la meme reponse que le rendu qui suit. */
    public bool $showModuleForm = false;

    public ?string $openSequenceFormFor = null;

    public string $moduleTitle = '';

    public string $moduleSummary = '';

    public string $sequenceTitle = '';

    public string $sequenceBody = '';

    public string $flash = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    /** @return Collection<int, CourseModule> */
    public function modules(): Collection
    {
        return CourseModule::where('loop_id', $this->loop->id)
            ->with(['sequences.blogPost:id,title,slug', 'sequences.dossierFile:id,display_name,original_name'])
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    public function canManage(): bool
    {
        return app(LoopPermissionResolver::class)
            ->can(auth()->user(), $this->loop, 'course_material.manage');
    }

    // ── Ecriture ────────────────────────────────────────────────────────────

    public function createModule(): void
    {
        $this->authorizeManage();

        try {
            app(CourseMaterialService::class)
                ->createModule($this->loop, auth()->user(), $this->moduleTitle, $this->moduleSummary);
        } catch (ValidationException $e) {
            $this->addError('moduleTitle', $e->getMessage());

            return;
        }

        $this->reset(['moduleTitle', 'moduleSummary', 'showModuleForm']);
        $this->flash = __('loops.cards.course_material.saved');
    }

    public function addSequence(string $moduleId): void
    {
        $this->authorizeManage();

        $module = $this->resolveModule($moduleId);

        try {
            app(CourseMaterialService::class)
                ->addSequence($module, auth()->user(), $this->sequenceTitle, $this->sequenceBody);
        } catch (ValidationException $e) {
            $this->addError('sequenceTitle', $e->getMessage());

            return;
        }

        $this->reset(['sequenceTitle', 'sequenceBody', 'openSequenceFormFor']);
        $this->flash = __('loops.cards.course_material.saved');
    }

    public function deleteModule(string $moduleId): void
    {
        $this->authorizeManage();

        app(CourseMaterialService::class)->deleteModule($this->resolveModule($moduleId));

        $this->flash = __('loops.cards.course_material.module_deleted');
    }

    public function deleteSequence(string $sequenceId): void
    {
        $this->authorizeManage();

        app(CourseMaterialService::class)->deleteSequence($this->resolveSequence($sequenceId));

        $this->flash = __('loops.cards.course_material.sequence_deleted');
    }

    /**
     * Deplacer une Sequence d'un rang, sans glissement.
     *
     * C'est le chemin de premiere classe, pas un repli : il est atteignable au
     * clavier, et le seul praticable sur un ecran tactile etroit.
     */
    public function moveSequence(string $sequenceId, int $delta): void
    {
        $this->authorizeManage();

        $sequence = $this->resolveSequence($sequenceId);
        $module = $this->resolveModule($sequence->course_module_id);

        $ids = $this->shifted($module->sequences()->pluck('id')->all(), $sequence->id, $delta);

        if ($ids === null) {
            return;
        }

        app(CourseMaterialService::class)->reorderSequences($module, $ids);
    }

    public function moveModule(string $moduleId, int $delta): void
    {
        $this->authorizeManage();

        $ids = $this->shifted(
            CourseModule::where('loop_id', $this->loop->id)
                ->orderBy('position')->orderBy('created_at')->pluck('id')->all(),
            $moduleId,
            $delta,
        );

        if ($ids === null) {
            return;
        }

        app(CourseMaterialService::class)->reorderModules($this->loop, $ids);
    }

    /**
     * La liste, avec un element deplace de `$delta` rangs. `null` si le
     * deplacement sort des bornes ou si l'element n'y est pas.
     *
     * Ecrite en clair plutot qu'avec deux `array_splice` imbriques : le premier
     * jet passait le retour de l'un en argument de l'autre, sur le meme tableau
     * pris par reference, et ne deplacait rien. Un classement qui echoue en
     * silence est pire qu'un classement refuse.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>|null
     */
    private function shifted(array $ids, string $id, int $delta): ?array
    {
        $from = array_search($id, $ids, true);

        if ($from === false) {
            return null;
        }

        $to = $from + $delta;

        if ($to < 0 || $to >= count($ids)) {
            return null;
        }

        $deplace = $ids[$from];
        unset($ids[$from]);
        $ids = array_values($ids);
        array_splice($ids, $to, 0, [$deplace]);

        return $ids;
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    /**
     * Un Module de **cette** Boucle, ou 404.
     *
     * La contrainte sur `loop_id` est le cloisonnement : un identifiant forge
     * ne traverse ni la Boucle ni l'Organization, quel que soit le droit de la
     * personne dans la sienne.
     */
    private function resolveModule(string $moduleId): CourseModule
    {
        $module = CourseModule::where('loop_id', $this->loop->id)
            ->whereKey($moduleId)
            ->first();

        // `abort(404)` plutot que `firstOrFail()` : le refus doit etre un
        // **statut**, lisible comme tel partout — y compris depuis un test de
        // composant, ou une exception de modele ne se lit pas comme une reponse.
        abort_unless((bool) $module, 404);

        return $module;
    }

    private function resolveSequence(string $sequenceId): CourseSequence
    {
        $sequence = CourseSequence::whereKey($sequenceId)
            ->whereHas('module', fn ($q) => $q->where('loop_id', $this->loop->id))
            ->first();

        abort_unless((bool) $sequence, 404);

        return $sequence;
    }

    public function render()
    {
        return view('livewire.loop-course-material-card', [
            'modules' => $this->modules(),
            'canManage' => $this->canManage(),
        ]);
    }
}
