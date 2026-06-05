<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $organizations = $this->organizations();
        $organization = $this->selectedOrganization($request, $organizations);

        $categories = Category::where('organization_id', $organization?->id)
            ->withCount(['services', 'skills', 'serviceRequests'])
            ->with(['organization', 'skills'])
            ->orderBy('name_b2c')
            ->get();

        return view('admin.categories.index', compact('categories', 'organization', 'organizations'));
    }

    public function create(Request $request): View
    {
        $organizations = $this->organizations();
        $organization = $this->selectedOrganization($request, $organizations);

        return view('admin.categories.create', compact('organization', 'organizations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name_b2c'   => 'required|string|max:100',
            'name_b2b'   => 'required|string|max:100',
            'color'      => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
            'organization_id' => 'required|exists:organizations,id',
            'skills'    => 'array',
            'skills.*'  => 'nullable|string|max:100',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $category = Category::create($data);

        if ($request->has('skills')) {
            $skillNames = array_filter($request->input('skills', []), fn($name) => !empty(trim($name)));

            foreach ($skillNames as $skillName) {
                $category->skills()->create([
                    'name' => $skillName,
                    'slug' => Str::slug($skillName),
                    'organization_id' => $category->organization_id,
                ]);
            }
        }

        return redirect()->route('admin.categories', ['organization_id' => $data['organization_id']])->with('success', 'Catégorie créée.');
    }

    public function edit(Category $category): View
    {
        $organizations = $this->organizations();
        $category->load(['organization', 'skills']);

        return view('admin.categories.edit', compact('category', 'organizations'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name_b2c'   => 'required|string|max:100',
            'name_b2b'   => 'required|string|max:100',
            'color'      => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
            'skills'    => 'array',
            'skills.*'  => 'nullable|string|max:100',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $category->update($data);

        if ($request->has('skills')) {
            $skillNames = array_filter($request->input('skills', []), fn($name) => !empty(trim($name)));

            $existingSkills = $category->skills->keyBy('name');
            $newSkills = array_values($skillNames);

            foreach ($newSkills as $skillName) {
                if (!$existingSkills->has($skillName)) {
                    $category->skills()->create([
                        'name' => $skillName,
                        'slug' => Str::slug($skillName),
                        'organization_id' => $category->organization_id,
                    ]);
                }
            }

            $skillsToDelete = $category->skills->whereNotIn('name', $newSkills);
            $skillsToDelete->each->delete();
        }

        return redirect()->route('admin.categories', ['organization_id' => $category->organization_id])->with('success', 'Catégorie mise à jour.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if (
            $category->services()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0
            || $category->serviceRequests()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0
        ) {
            return back()->with('error', 'Impossible de supprimer une catégorie utilisée par des services ou demandes.');
        }

        $category->skills()->delete();
        $category->delete();

        return redirect()->route('admin.categories', ['organization_id' => $category->organization_id])->with('success', 'Catégorie supprimée.');
    }

    public function storeSkill(Request $request, Category $category): RedirectResponse
    {
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
        $organizationId = $skill->organization_id ?: $skill->category?->organization_id;

        $skill->delete();

        return redirect()->route('admin.categories', ['organization_id' => $organizationId])->with('success', 'Compétence supprimée.');
    }

    private function organizations()
    {
        return Organization::orderByDesc('is_default')->orderBy('name')->get();
    }

    private function selectedOrganization(Request $request, $organizations): ?Organization
    {
        $requestedId = $request->query('organization_id');

        if ($requestedId && $organizations->contains('id', $requestedId)) {
            return $organizations->firstWhere('id', $requestedId);
        }

        $userOrgId = auth()->user()->organization_id;

        return $organizations->firstWhere('id', $userOrgId) ?: $organizations->first();
    }
}
