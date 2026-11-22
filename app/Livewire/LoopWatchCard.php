<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopWatchItem;
use App\Services\Loops\LoopWatchService;
use App\Services\Loops\WatchException;
use Livewire\Component;

/**
 * TASK-1879 — la Card Veille.
 *
 * Une liste (plus recentes d'abord, filtrable par categorie), un formulaire
 * (date, categorie, information, impact, source) et, pour qui mene la Boucle,
 * la gestion des categories. Aucune regle ne vit ici : tout passe par
 * LoopWatchService, qui reverifie plugin, permission, adhesion et appartenance
 * a la Boucle a CHAQUE geste. Cacher un bouton n'accorde ni ne retire rien.
 */
class LoopWatchCard extends Component
{
    public Loop $loop;

    // ── Liste ───────────────────────────────────────────────────────────────

    /** Filtre de categorie : '' = toutes. */
    public string $filterCategory = '';

    // ── Formulaire de veille ────────────────────────────────────────────────

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $observedOn = '';

    public string $categoryId = '';

    public string $information = '';

    public string $impact = '';

    public string $sourceUrl = '';

    public ?string $confirmingDeleteId = null;

    // ── Categories ──────────────────────────────────────────────────────────

    public bool $showCategories = false;

    public string $newCategoryName = '';

    public ?string $renamingCategoryId = null;

    public string $renameCategoryName = '';

    // ── Retours ─────────────────────────────────────────────────────────────

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    private function service(): LoopWatchService
    {
        return app(LoopWatchService::class);
    }

    private function user()
    {
        return auth()->user();
    }

    // ── Veilles ─────────────────────────────────────────────────────────────

    public function openCreateForm(): void
    {
        $this->resetMessages();

        if (! $this->user() || ! $this->service()->canCreate($this->user(), $this->loop)) {
            $this->errorMessage = __('veille.error_not_allowed');

            return;
        }

        if ($this->service()->categoriesFor($this->loop)->isEmpty()) {
            $this->errorMessage = __('veille.error_no_category_yet');
            $this->showCategories = $this->service()->canManageCategories($this->user(), $this->loop);

            return;
        }

        $this->resetForm();
        $this->observedOn = now()->format('Y-m-d');
        $this->showForm = true;
    }

    public function openEditForm(string $itemId): void
    {
        $this->resetMessages();

        $item = $this->service()->findItem($this->loop, $itemId);

        if (! $item || ! $this->user() || ! $this->service()->canManageItem($this->user(), $item, $this->loop)) {
            $this->errorMessage = __('veille.error_not_allowed');

            return;
        }

        $this->editingId = $item->id;
        $this->observedOn = $item->observed_on->format('Y-m-d');
        $this->categoryId = (string) ($item->category_id ?? '');
        $this->information = $item->information;
        $this->impact = $item->impact;
        $this->sourceUrl = $item->source_url;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->resetMessages();
    }

    public function save(): void
    {
        $this->resetMessages();

        if (! $this->user()) {
            $this->errorMessage = __('veille.error_not_allowed');

            return;
        }

        $donnees = [
            'observed_on' => $this->observedOn,
            'category_id' => $this->categoryId,
            'information' => $this->information,
            'impact' => $this->impact,
            'source_url' => $this->sourceUrl,
        ];

        try {
            if ($this->editingId) {
                $this->service()->update($this->user(), $this->loop, $this->editingId, $donnees);
                $this->successMessage = __('veille.updated');
            } else {
                $this->service()->create($this->user(), $this->loop, $donnees);
                $this->successMessage = __('veille.created');
                // Le fil voisin a une annonce neuve a montrer.
                $this->dispatch('loop-activity-published', loopId: $this->loop->id);
            }
        } catch (WatchException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->resetForm();
    }

    public function confirmDelete(string $itemId): void
    {
        $this->resetMessages();
        $this->confirmingDeleteId = $itemId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(string $itemId): void
    {
        $this->resetMessages();
        $this->confirmingDeleteId = null;

        try {
            $this->service()->delete($this->user(), $this->loop, $itemId);
            $this->successMessage = __('veille.deleted');
        } catch (WatchException $e) {
            $this->errorMessage = $e->getMessage();
        }

        if ($this->editingId === $itemId) {
            $this->resetForm();
        }
    }

    // ── Categories ──────────────────────────────────────────────────────────

    public function toggleCategories(): void
    {
        $this->resetMessages();
        $this->showCategories = ! $this->showCategories;
    }

    public function createCategory(): void
    {
        $this->resetMessages();

        try {
            $this->service()->createCategory($this->user(), $this->loop, $this->newCategoryName);
            $this->newCategoryName = '';
            $this->successMessage = __('veille.category_created');
        } catch (WatchException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function startRename(string $categoryId): void
    {
        $this->resetMessages();

        $categorie = $this->service()->findCategory($this->loop, $categoryId);

        if (! $categorie || $categorie->isArchived()) {
            $this->errorMessage = __('veille.error_category_not_found');

            return;
        }

        $this->renamingCategoryId = $categorie->id;
        $this->renameCategoryName = $categorie->name;
    }

    public function cancelRename(): void
    {
        $this->renamingCategoryId = null;
        $this->renameCategoryName = '';
    }

    public function renameCategory(): void
    {
        $this->resetMessages();

        try {
            $this->service()->renameCategory($this->user(), $this->loop, (string) $this->renamingCategoryId, $this->renameCategoryName);
            $this->cancelRename();
            $this->successMessage = __('veille.category_renamed');
        } catch (WatchException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function moveCategory(string $categoryId, string $direction): void
    {
        $this->resetMessages();

        try {
            $this->service()->moveCategory($this->user(), $this->loop, $categoryId, $direction);
        } catch (WatchException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function archiveCategory(string $categoryId): void
    {
        $this->resetMessages();

        try {
            $this->service()->archiveCategory($this->user(), $this->loop, $categoryId);
            $this->successMessage = __('veille.category_archived');

            if ($this->filterCategory === $categoryId) {
                $this->filterCategory = '';
            }
        } catch (WatchException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    // ── Rendu ───────────────────────────────────────────────────────────────

    public function render()
    {
        $user = $this->user();
        $service = $this->service();

        if (! $user || ! $service->canView($user, $this->loop)) {
            return view('livewire.loop-watch-card', [
                'allowed' => false,
                'items' => collect(),
                'categories' => collect(),
                'allCategories' => collect(),
                'canCreate' => false,
                'canManageCategories' => false,
                'needsCategory' => false,
                'readOnly' => true,
            ]);
        }

        $canCreate = $service->canCreate($user, $this->loop);
        $canManageCategories = $service->canManageCategories($user, $this->loop);

        $items = $service->itemsFor($this->loop, $this->filterCategory !== '' ? $this->filterCategory : null)
            ->map(fn (LoopWatchItem $item) => [
                'item' => $item,
                'can_manage' => $service->canManageItem($user, $item, $this->loop),
            ]);

        $categories = $service->categoriesFor($this->loop);

        return view('livewire.loop-watch-card', [
            'allowed' => true,
            'items' => $items,
            'categories' => $categories,
            // Categorie obligatoire : sans categorie active, pas de publication.
            'needsCategory' => $categories->isEmpty(),
            'allCategories' => $canManageCategories ? $service->categoriesFor($this->loop, true) : collect(),
            'canCreate' => $canCreate,
            'canManageCategories' => $canManageCategories,
            // Boucle archivee, ou membre sans droit d'ecrire : lecture seule.
            'readOnly' => ! $canCreate && ! $canManageCategories,
        ]);
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->observedOn = '';
        $this->categoryId = '';
        $this->information = '';
        $this->impact = '';
        $this->sourceUrl = '';
    }

    private function resetMessages(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;
    }
}
