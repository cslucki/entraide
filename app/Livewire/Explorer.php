<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceRequest;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Explorer extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'services';

    #[Url]
    public string $search = '';

    #[Url]
    public array $selectedCategories = [];

    #[Url]
    public string $deliveryMode = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategories(): void
    {
        $this->resetPage();
    }

    public function updatedDeliveryMode(): void
    {
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function toggleCategory(string $id): void
    {
        if (in_array($id, $this->selectedCategories)) {
            $this->selectedCategories = array_values(array_filter($this->selectedCategories, fn($c) => $c !== $id));
        } else {
            $this->selectedCategories[] = $id;
        }
        $this->resetPage();
    }

    public function render()
    {
        $categories = Category::all();

        if ($this->tab === 'services') {
            $query = Service::with(['user', 'category', 'skills', 'tags'])
                ->where('status', 'active');

            if ($this->search) {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', $search)
                      ->orWhere('description', 'like', $search)
                      ->orWhereHas('tags', fn($t) => $t->where('name', 'like', $search));
                });
            }

            if (!empty($this->selectedCategories)) {
                $query->whereIn('category_id', $this->selectedCategories);
            }

            if ($this->deliveryMode) {
                $query->where(function ($q) {
                    $q->where('delivery_mode', $this->deliveryMode)
                      ->orWhere('delivery_mode', 'both');
                });
            }

            $items = $query->latest()->paginate(15);
        } else {
            $query = ServiceRequest::with(['user', 'category'])
                ->where('status', 'open');

            if ($this->search) {
                $search = '%' . $this->search . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', $search)
                      ->orWhere('description', 'like', $search);
                });
            }

            if (!empty($this->selectedCategories)) {
                $query->whereIn('category_id', $this->selectedCategories);
            }

            if ($this->deliveryMode) {
                $query->where(function ($q) {
                    $q->where('delivery_mode', $this->deliveryMode)
                      ->orWhere('delivery_mode', 'both');
                });
            }

            $items = $query->latest()->paginate(15);
        }

        return view('livewire.explorer', compact('categories', 'items'));
    }
}
