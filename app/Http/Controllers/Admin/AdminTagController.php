<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminTagController extends Controller
{
    public function index(Request $request): View
    {
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');

        $allowedSorts = ['name', 'slug', 'blog_posts_count', 'services_count'];
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'name';
        }
        $direction = in_array($direction, ['asc', 'desc']) ? $direction : 'asc';

        $query = Tag::with('organization')->withCount([
            'blogPosts',
            'services' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
        ]);

        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        if ($selectedOrganizationId !== 'all') {
            $query->where('organization_id', $selectedOrganizationId);
        }

        $query->where(function ($q) {
            $q->whereHas('blogPosts')
                ->orWhereHas('services', fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class));
        });

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $tags = $query->orderBy($sort, $direction)->paginate(30)->withQueryString();

        return view('admin.tags.index', compact('tags', 'organizations', 'selectedOrganizationId', 'sort', 'direction'));
    }

    public function edit(Tag $tag): View
    {
        $tag->loadCount([
            'blogPosts',
            'services' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
        ]);

        $organizations = $this->adminOrganizations();

        return view('admin.tags.edit', compact('tag', 'organizations'));
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);

        $slug = ! empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);

        $collision = Tag::where(function ($q) use ($tag) {
            $q->where('organization_id', $tag->organization_id)
                ->orWhereNull('organization_id');
        })
            ->where('id', '!=', $tag->id)
            ->where(function ($q) use ($data, $slug) {
                $q->where('slug', $slug)
                    ->orWhere('name', $data['name']);
            })
            ->exists();

        if ($collision) {
            return back()
                ->with('error', 'Un tag avec ce nom ou ce slug existe déjà pour cette organisation.')
                ->withInput();
        }

        $tag->update([
            'name' => $data['name'],
            'slug' => $slug,
        ]);

        return redirect()->route('admin.tags')->with('success', 'Tag mis à jour.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->loadCount([
            'blogPosts',
            'services' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class),
        ]);

        $totalUsages = $tag->blog_posts_count + $tag->services_count;

        if ($totalUsages > 0) {
            return back()->with('error', "Impossible de supprimer ce tag : il est utilisé par {$tag->blog_posts_count} article(s) et {$tag->services_count} service(s).");
        }

        $tag->delete();

        return redirect()->route('admin.tags')->with('success', 'Tag supprimé.');
    }

    private function adminOrganizations(): Collection
    {
        return Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);
    }

    private function selectedAdminOrganizationId(Request $request): string
    {
        if ($request->input('organization_id') === 'all') {
            return 'all';
        }

        if ($request->filled('organization_id')) {
            return (string) $request->input('organization_id');
        }

        return 'all';
    }
}
