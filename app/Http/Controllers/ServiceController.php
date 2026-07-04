<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateServiceThumbnail;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\Skill;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Intervention\Image\Laravel\Facades\Image;

class ServiceController extends Controller
{
    public function show(Service $service): View|RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $service->organization_id !== $organization->id) {
            abort(404);
        }

        // Seul le propriétaire peut voir un service pausé
        if ($service->status !== 'active' && auth()->id() !== $service->user_id) {
            abort(404);
        }

        $service->load(['user', 'category', 'skills.category', 'tags', 'images']);
        $isFavorited = auth()->check() && auth()->user()->hasFavorited($service->id);
        $isPaused = $service->status === 'paused';

        $ogTitle = $service->title;
        $ogDescription = Str::limit(strip_tags($service->description), 160);
        $ogImage = $service->images->first()
            ? $service->images->first()->url
            : null;
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $service->title,
            'description' => Str::limit(strip_tags($service->description), 160),
            'provider' => [
                '@type' => 'Person',
                'name' => $service->user->name,
                'url' => route('profile.show', $service->user),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return view('services.show', compact('service', 'isFavorited', 'isPaused', 'ogTitle', 'ogDescription', 'ogImage', 'jsonLd'));
    }

    public function orgShow(string $org, Service $service): View|RedirectResponse
    {
        return $this->show($service);
    }

    public function create(): View
    {
        $organization = currentOrganization();
        $categories = Category::where('organization_id', $organization?->id)->with('skills', 'pointGuidelines')->get();
        $skills = Skill::where('organization_id', $organization?->id)->with('category')->get()->groupBy('category_id');

        return view('services.create', compact('categories', 'skills'));
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $data = $request->validate([
            'title' => 'required|string|min:10|max:255',
            'description' => 'required|string|min:100',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost' => 'required|integer|min:40|max:100',
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|max:2048',
        ], [], __('marketplace.validation_attributes'));

        $service = Service::create([
            'user_id' => auth()->id(),
            'organization_id' => $organization->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost' => $data['points_cost'],
            'status' => 'active',
        ]);

        if (! empty($data['skills'])) {
            $service->skills()->syncWithPivotValues($data['skills'], ['organization_id' => $service->organization_id]);
        }

        if (! empty($data['tags'])) {
            $tagNames = array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5);
            $tagIds = [];
            foreach ($tagNames as $name) {
                $slug = Str::slug($name);
                if (! $slug) {
                    continue;
                }
                $tag = Tag::firstOrCreate(['slug' => $slug, 'organization_id' => $service->organization_id], ['name' => $name, 'slug' => $slug]);
                $tagIds[] = $tag->id;
            }
            $service->tags()->syncWithPivotValues($tagIds, ['organization_id' => $service->organization_id]);
        }

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                $filename = time().'_'.$index.'_'.$file->getClientOriginalName();
                $img = Image::decode($file);
                $img->scaleDown(1200, 800);

                Storage::disk('public')->put('services/'.$filename, (string) $img->encode());

                $serviceImage = $service->images()->create([
                    'path' => 'services/'.$filename,
                    'order' => $index,
                    'organization_id' => $service->organization_id,
                ]);

                GenerateServiceThumbnail::dispatch($serviceImage);
            }
        }

        $redirectRoute = $organization && Route::has('organization.dashboard.services')
            ? route('organization.dashboard.services', ['organization' => $organization->slug])
            : route('dashboard.services');

        return redirect($redirectRoute)->with('success', __('services.notification.created'));
    }

    public function edit(Service $service): View
    {
        $organization = currentOrganization();
        if (! $organization || $service->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $service);
        $categories = Category::where('organization_id', $organization->id)->with('skills', 'pointGuidelines')->get();
        $skills = Skill::where('organization_id', $organization->id)->with('category')->get()->groupBy('category_id');
        $service->load(['skills', 'tags', 'category']);

        return view('services.edit', compact('service', 'categories', 'skills', 'organization'));
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $service->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $service);

        $data = $request->validate([
            'title' => 'required|string|min:10|max:255',
            'description' => 'required|string|min:100',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost' => 'required|integer|min:40|max:100',
            'status' => 'required|in:active,paused',
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|max:2048',
            'delete_images' => 'nullable|array',
            'delete_images.*' => 'uuid|exists:service_images,id',
        ], [], __('marketplace.validation_attributes'));

        $service->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost' => $data['points_cost'],
            'status' => $data['status'],
        ]);

        $service->skills()->syncWithPivotValues($data['skills'] ?? [], ['organization_id' => $service->organization_id]);

        if (isset($data['tags'])) {
            $tagNames = array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5);
            $tagIds = [];
            foreach ($tagNames as $name) {
                $slug = Str::slug($name);
                if (! $slug) {
                    continue;
                }
                $tag = Tag::firstOrCreate(['slug' => $slug, 'organization_id' => $service->organization_id], ['name' => $name, 'slug' => $slug]);
                $tagIds[] = $tag->id;
            }
            $service->tags()->syncWithPivotValues($tagIds, ['organization_id' => $service->organization_id]);
        }

        if (! empty($data['delete_images'])) {
            $imagesToDelete = ServiceImage::whereIn('id', $data['delete_images'])->where('service_id', $service->id)->get();
            foreach ($imagesToDelete as $img) {
                Storage::disk('public')->delete($img->path);
                $img->delete();
            }
        }

        if ($request->hasFile('images')) {
            $currentCount = $service->images()->count();
            foreach ($request->file('images') as $index => $file) {
                if ($currentCount + $index >= 5) {
                    break;
                }

                $filename = time().'_'.$index.'_'.$file->getClientOriginalName();
                $img = Image::decode($file);
                $img->scaleDown(1200, 800);

                Storage::disk('public')->put('services/'.$filename, (string) $img->encode());

                $serviceImage = $service->images()->create([
                    'path' => 'services/'.$filename,
                    'order' => $currentCount + $index,
                    'organization_id' => $service->organization_id,
                ]);

                GenerateServiceThumbnail::dispatch($serviceImage);
            }
        }

        return redirect()->route('dashboard')->with('success', __('services.notification.updated'));
    }

    public function destroy(Service $service): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $service->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('delete', $service);

        if ($service->hasActiveTransaction()) {
            return redirect()->route('dashboard')->with('error', __('services.notification.delete_blocked'));
        }

        $service->update(['status' => 'deleted']);
        $service->delete();

        return redirect()->route('dashboard')->with('success', __('services.notification.deleted'));
    }
}
