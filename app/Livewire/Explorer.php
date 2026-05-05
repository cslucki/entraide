<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Tag;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Explorer extends Component
{
    use WithPagination;

    public ?string $communityId = null;

    #[Url]
    public string $tab = 'requests';

    #[Url]
    public string $search = '';

    #[Url]
    public array $selectedCategories = [];

    #[Url]
    public string $deliveryMode = '';

    #[Url]
    public string $sortBy = 'latest'; // latest | points_asc | points_desc | rating

    #[Url]
    public string $tagFilter = '';

    #[Url]
    public int $minRating = 0;

    #[Url]
    public array $selectedSkills = [];

    public int $perPage = 15;

    public function updatedSearch(): void          { $this->resetPage(); }
    public function updatedSelectedCategories(): void { $this->resetPage(); }
    public function updatedDeliveryMode(): void    { $this->resetPage(); }
    public function updatedSortBy(): void          { $this->resetPage(); }
    public function updatedTagFilter(): void       { $this->resetPage(); }
    public function updatedMinRating(): void       { $this->resetPage(); }
    public function updatedSelectedSkills(): void  { $this->resetPage(); }

    public function mount(): void
    {
        try {
            $community = app('current_community');
            $this->communityId = $community?->id;
        } catch (\Exception) {
            $this->communityId = null;
        }
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
        $this->tagFilter = '';
        $this->selectedSkills = [];
        $this->resetPage();
    }

    public function toggleCategory(string $id): void
    {
        if (in_array($id, $this->selectedCategories)) {
            $this->selectedCategories = array_values(array_filter($this->selectedCategories, fn($c) => $c !== $id));
        } else {
            $this->selectedCategories[] = $id;
        }
        $this->selectedSkills = [];
        $this->resetPage();
    }

    public function toggleSkill(string $id): void
    {
        if (in_array($id, $this->selectedSkills)) {
            $this->selectedSkills = array_values(array_filter($this->selectedSkills, fn($s) => $s !== $id));
        } else {
            $this->selectedSkills[] = $id;
        }
        $this->resetPage();
    }

    public function filterByTag(string $slug): void
    {
        $this->tagFilter = $this->tagFilter === $slug ? '' : $slug;
        $this->tab = 'services';
        $this->resetPage();
    }

    public function loadMore(): void
    {
        $this->perPage += 15;
    }

    public function render()
    {
        $categories = Category::with('skills')->get();

        if ($this->tab === 'services') {
            $query = Service::withoutGlobalScopes()
                ->with(['user', 'category', 'skills', 'tags'])
                ->where('status', 'active')
                ->where('community_id', $this->communityId);

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
                    $q->where('delivery_mode', $this->deliveryMode)->orWhere('delivery_mode', 'both');
                });
            }

            if ($this->tagFilter) {
                $query->whereHas('tags', fn($t) => $t->where('slug', $this->tagFilter));
            }

            if (!empty($this->selectedSkills)) {
                $query->whereHas('skills', fn($q) => $q->whereIn('skills.id', $this->selectedSkills));
            }

            if ($this->minRating > 0) {
                $query->whereHas('user', fn($u) => $u->where('rating', '>=', $this->minRating));
            }

            $query = match ($this->sortBy) {
                'points_asc'  => $query->orderBy('points_cost'),
                'points_desc' => $query->orderByDesc('points_cost'),
                'rating'      => $query->join('users', 'users.id', '=', 'services.user_id')
                                       ->orderByDesc('users.rating')
                                       ->select('services.*'),
                default       => $query->latest(),
            };

            $items = $query->paginate($this->perPage);
            $hasMore = $items->hasMorePages();

            // Charger les favoris en une seule requête
            $favoritedIds = auth()->check()
                ? Favorite::where('user_id', auth()->id())
                    ->whereIn('service_id', $items->pluck('id'))
                    ->pluck('service_id')
                    ->flip()
                : collect();
        } else {
            $query = ServiceRequest::withoutGlobalScopes()
                ->with(['user', 'category'])
                ->where('status', 'open')
                ->where('community_id', $this->communityId);

            if ($this->search) {
                $search = '%' . $this->search . '%';
                $query->where(fn($q) => $q->where('title', 'like', $search)->orWhere('description', 'like', $search));
            }

            if (!empty($this->selectedCategories)) {
                $query->whereIn('category_id', $this->selectedCategories);
            }

            if ($this->deliveryMode) {
                $query->where(fn($q) => $q->where('delivery_mode', $this->deliveryMode)->orWhere('delivery_mode', 'both'));
            }

            $query = match ($this->sortBy) {
                'points_asc'  => $query->orderBy('budget_min'),
                'points_desc' => $query->orderByDesc('budget_min'),
                default       => $query->latest(),
            };

            $items = $query->paginate($this->perPage);
            $hasMore = $items->hasMorePages();
            $favoritedIds = collect();
        }

        return view('livewire.explorer', compact('categories', 'items', 'hasMore', 'favoritedIds'));
    }
}
