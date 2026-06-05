<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminCategoryController extends Controller
{
    public function index(): View
    {
        $orgId = auth()->user()->organization_id;

        $categories = Category::where('organization_id', $orgId)
            ->withCount(['services', 'skills', 'serviceRequests'])
            ->with('skills')
            ->orderBy('name_b2c')
            ->get();

        return view('admin.categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('admin.categories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name_b2c'   => 'required|string|max:100',
            'name_b2b'   => 'required|string|max:100',
            'color'      => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
            'service_1'  => 'nullable|string|max:255',
            'service_2'  => 'nullable|string|max:255',
            'service_3'  => 'nullable|string|max:255',
            'service_4'  => 'nullable|string|max:255',
            'service_5'  => 'nullable|string|max:255',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $data['organization_id'] = auth()->user()->organization_id;
        Category::create($data);

        return redirect()->route('admin.categories')->with('success', 'Catégorie créée.');
    }

    public function edit(Category $category): View
    {
        $this->ensureOrgScope($category);
        $category->load('skills');

        return view('admin.categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->ensureOrgScope($category);
        $data = $request->validate([
            'name_b2c'   => 'required|string|max:100',
            'name_b2b'   => 'required|string|max:100',
            'color'      => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
            'service_1'  => 'nullable|string|max:255',
            'service_2'  => 'nullable|string|max:255',
            'service_3'  => 'nullable|string|max:255',
            'service_4'  => 'nullable|string|max:255',
            'service_5'  => 'nullable|string|max:255',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $category->update($data);

        return redirect()->route('admin.categories')->with('success', 'Catégorie mise à jour.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->ensureOrgScope($category);
        if (
            $category->services()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0
            || $category->serviceRequests()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0
        ) {
            return back()->with('error', 'Impossible de supprimer une catégorie utilisée par des services ou demandes.');
        }

        $category->skills()->delete();
        $category->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }

    public function storeSkill(Request $request, Category $category): RedirectResponse
    {
        $this->ensureOrgScope($category);
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        Skill::create([
            'category_id'     => $category->id,
            'name'            => $data['name'],
            'slug'            => Str::slug($data['name']),
            'organization_id' => $category->organization_id,
        ]);

        return back()->with('success', 'Compétence ajoutée.');
    }

    public function destroySkill(Skill $skill): RedirectResponse
    {
        if ($skill->category && $skill->category->organization_id !== auth()->user()->organization_id) {
            abort(404);
        }

        $skill->delete();

        return back()->with('success', 'Compétence supprimée.');
    }

    private function ensureOrgScope(Category $category): void
    {
        if ($category->organization_id !== auth()->user()->organization_id) {
            abort(404);
        }
    }
}
