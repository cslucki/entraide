<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Service;
use App\Models\Skill;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function show(Service $service): View
    {
        $service->load(['user', 'category', 'skills.category', 'tags']);
        return view('services.show', compact('service'));
    }

    public function create(): View
    {
        $categories = Category::with('skills', 'pointGuidelines')->get();
        $skills = Skill::with('category')->get()->groupBy('category_id');
        return view('services.create', compact('categories', 'skills'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost' => 'required|integer|min:1',
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
        ]);

        $service = Service::create([
            'user_id' => auth()->id(),
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost' => $data['points_cost'],
            'status' => 'active',
        ]);

        if (!empty($data['skills'])) {
            $service->skills()->sync($data['skills']);
        }

        if (!empty($data['tags'])) {
            $tagNames = array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5);
            $tagIds = [];
            foreach ($tagNames as $name) {
                $slug = Str::slug($name);
                if (!$slug) continue;
                $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => $name, 'slug' => $slug]);
                $tagIds[] = $tag->id;
            }
            $service->tags()->sync($tagIds);
        }

        return redirect()->route('dashboard')->with('success', 'Service publié avec succès.');
    }

    public function edit(Service $service): View
    {
        $this->authorize('update', $service);
        $categories = Category::with('skills', 'pointGuidelines')->get();
        $skills = Skill::with('category')->get()->groupBy('category_id');
        $service->load(['skills', 'tags', 'category']);
        return view('services.edit', compact('service', 'categories', 'skills'));
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $this->authorize('update', $service);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost' => 'required|integer|min:1',
            'status' => 'required|in:active,paused',
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
        ]);

        $service->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost' => $data['points_cost'],
            'status' => $data['status'],
        ]);

        $service->skills()->sync($data['skills'] ?? []);

        if (isset($data['tags'])) {
            $tagNames = array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5);
            $tagIds = [];
            foreach ($tagNames as $name) {
                $slug = Str::slug($name);
                if (!$slug) continue;
                $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => $name, 'slug' => $slug]);
                $tagIds[] = $tag->id;
            }
            $service->tags()->sync($tagIds);
        }

        return redirect()->route('dashboard')->with('success', 'Service mis à jour.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);

        if ($service->hasActiveTransaction()) {
            return back()->with('error', 'Impossible de supprimer un service avec des transactions en cours.');
        }

        $service->update(['status' => 'deleted']);
        $service->delete();

        return redirect()->route('dashboard')->with('success', 'Service supprimé.');
    }
}
