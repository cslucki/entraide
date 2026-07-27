<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateServiceThumbnail;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\Skill;
use App\Models\Tag;
use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\SupervisionProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View as ViewFacade;
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

        if ($service->user?->banned_at !== null) {
            abort(404);
        }

        $isFavorited = auth()->check() && auth()->user()->hasFavorited($service->id);
        $isPaused = $service->status === 'paused';

        $ogTitle = $service->title;
        $ogDescription = Str::limit(strip_tags(Str::markdown($service->description, ['html_input' => 'strip', 'allow_unsafe_links' => false])), 160);
        $ogImage = $service->images->first()
            ? $service->images->first()->url
            : null;
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            'name' => $service->title,
            'description' => Str::limit(strip_tags(Str::markdown($service->description, ['html_input' => 'strip', 'allow_unsafe_links' => false])), 160),
            'provider' => [
                '@type' => 'Person',
                'name' => $service->user->name,
                'url' => route('profile.show', $service->user),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        ViewFacade::share('ogTitle', $ogTitle);
        ViewFacade::share('ogDescription', $ogDescription);
        ViewFacade::share('ogImage', $ogImage);
        ViewFacade::share('jsonLd', $jsonLd);

        return view('services.show', compact('service', 'isFavorited', 'isPaused', 'ogTitle', 'ogDescription', 'ogImage', 'jsonLd'));
    }

    public function orgShow(string $org, Service $service): View|RedirectResponse
    {
        return $this->show($service);
    }

    public function create(): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $categories = Category::where('organization_id', $organization?->id)->with('skills', 'pointGuidelines')->get();
        $skills = Skill::where('organization_id', $organization?->id)->with('category')->get()->groupBy('category_id');

        return view('services.create', compact('categories', 'skills', 'organization'));
    }

    public function formulate(Request $request): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $categories = Category::where('organization_id', $organization->id)
            ->select('id', 'name_b2c')
            ->get();

        if ($categories->isEmpty()) {
            return response()->json(['error' => __('ai.service_formulation_no_categories')], 422);
        }

        $pointMin = $organization->servicePointsMin();
        $pointMax = $organization->servicePointsMax();

        $currentTitle = $request->input('title', '');
        $currentDescription = $request->input('description', '');
        $currentCategoryId = $request->input('category_id', '');
        $currentDeliveryMode = $request->input('delivery_mode', '');
        $currentPointsCost = $request->input('points_cost', '');

        $categoryList = $categories->map(fn ($c) => "  - {$c->name_b2c} (UUID: {$c->id})")->join("\n");

        $locale = app()->getLocale();
        $langLabel = $locale === 'en' ? 'English' : 'français';

        $promptLines = [
            "Langue de réponse : {$langLabel}.",
            '',
            "--- INTENTION DE L'UTILISATEUR ---",
        ];

        if ($currentTitle !== '' && $currentTitle !== null) {
            $promptLines[] = "Titre saisi : {$currentTitle}";
        }
        if ($currentDescription !== '' && $currentDescription !== null) {
            $promptLines[] = "Description saisie : {$currentDescription}";
        }

        if ($currentTitle === '' && $currentDescription === '') {
            $promptLines[] = '(Aucune intention saisie — propose une suggestion générique basée sur le contexte de la plateforme)';
        }

        $promptLines[] = '';
        $promptLines[] = '--- CATÉGORIES DISPONIBLES (utilise l\'UUID exact) ---';
        $promptLines[] = $categoryList;
        $promptLines[] = '';

        if ($currentCategoryId !== '' && $currentCategoryId !== null) {
            $currentCat = $categories->firstWhere('id', $currentCategoryId);
            $promptLines[] = 'Catégorie actuelle : '.($currentCat ? $currentCat->name_b2c.' (UUID: '.$currentCat->id.')' : $currentCategoryId);
        }

        $promptLines[] = 'Mode de réalisation actuel : '.($currentDeliveryMode !== '' && $currentDeliveryMode !== null ? $currentDeliveryMode : '(non renseigné)');
        $promptLines[] = '';

        $promptLines[] = '--- BORNES DE POINTS ---';
        $promptLines[] = '1 point = 1 minute.';

        if ($pointMin !== null) {
            $promptLines[] = "Minimum : {$pointMin} points.";
        }
        if ($pointMax !== null) {
            $promptLines[] = "Maximum : {$pointMax} points.";
        }
        if ($pointMin === null && $pointMax === null) {
            $promptLines[] = 'Aucune borne spécifique — propose un nombre raisonnable.';
        }

        $promptLines[] = 'Points actuels : '.($currentPointsCost !== '' && $currentPointsCost !== null ? $currentPointsCost : '(non renseigné)');

        $promptContent = implode("\n", $promptLines);

        $providerName = app(SupervisionProviderResolver::class)->defaultProvider();
        if (! $providerName) {
            return response()->json(['error' => __('ai.service_formulation_unavailable')], 503);
        }

        $scenario = app(AiScenarioFactory::class)->resolve('service_offer_master');
        if (! $scenario) {
            return response()->json(['error' => __('ai.service_formulation_unavailable')], 503);
        }

        try {
            $provider = app(SupervisionProviderResolver::class)->resolve($providerName);
            $result = $provider->runScenario($scenario, $promptContent);
        } catch (SupervisionException $e) {
            Log::error('AI formulation failed', [
                'scenario' => 'service_offer_master',
                'error' => $e->getMessage(),
                'organization_id' => $organization->id,
            ]);

            return response()->json(['error' => __('ai.service_formulation_error')], 422);
        } catch (\Throwable $e) {
            Log::error('AI formulation unexpected error', [
                'scenario' => 'service_offer_master',
                'error' => $e->getMessage(),
                'organization_id' => $organization->id,
            ]);

            return response()->json(['error' => __('ai.service_formulation_error')], 500);
        }

        $suggestion = $this->validateAiSuggestion($result, $organization, $categories, $currentTitle, $currentDescription, $currentCategoryId, $currentDeliveryMode, $currentPointsCost, $pointMin, $pointMax);

        return response()->json(['suggestion' => $suggestion]);
    }

    private function validateAiSuggestion(
        array $parsed,
        Organization $organization,
        $categories,
        string $currentTitle,
        string $currentDescription,
        string $currentCategoryId,
        string $currentDeliveryMode,
        string $currentPointsCost,
        ?int $pointMin,
        ?int $pointMax,
    ): array {
        $validCategoryIds = $categories->pluck('id')->toArray();

        $title = $parsed['title'] ?? null;
        if (! is_string($title) || mb_strlen($title) < 10 || mb_strlen($title) > 255) {
            $title = $currentTitle;
        }

        $descriptionMarkdown = $parsed['description_markdown'] ?? null;
        if (! is_string($descriptionMarkdown) || mb_strlen(strip_tags($descriptionMarkdown)) < 100) {
            $descriptionMarkdown = $currentDescription;
        } else {
            $descriptionMarkdown = strip_tags($descriptionMarkdown);
        }

        $categoryId = $parsed['category_id'] ?? null;
        if (! is_string($categoryId) || ! in_array($categoryId, $validCategoryIds, true)) {
            $categoryId = $currentCategoryId;
        }

        $deliveryMode = $parsed['delivery_mode'] ?? null;
        if (! is_string($deliveryMode) || ! in_array($deliveryMode, ['remote', 'onsite', 'both'], true)) {
            $deliveryMode = $currentDeliveryMode;
        }

        $pointsCost = $parsed['points_cost'] ?? null;
        if (! is_int($pointsCost) && ! ctype_digit((string) $pointsCost)) {
            $pointsCost = $currentPointsCost !== '' ? (int) $currentPointsCost : null;
        } else {
            $pointsCost = (int) $pointsCost;
            if (($pointMin !== null && $pointsCost < $pointMin) || ($pointMax !== null && $pointsCost > $pointMax)) {
                $pointsCost = $currentPointsCost !== '' ? (int) $currentPointsCost : null;
            }
            if ($pointsCost <= 0) {
                $pointsCost = $currentPointsCost !== '' ? (int) $currentPointsCost : null;
            }
        }

        return [
            'title' => $title,
            'description_markdown' => $descriptionMarkdown,
            'category_id' => $categoryId,
            'delivery_mode' => $deliveryMode,
            'points_cost' => $pointsCost,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $data = $request->validate($this->validationRules($organization), [], __('marketplace.validation_attributes'));

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
            $tagNames = array_slice(array_filter(array_map(fn ($t) => ltrim(trim($t), '#'), explode(',', $data['tags']))), 0, 10);
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

        $data = $request->validate(array_merge($this->validationRules($organization), [
            'status' => 'required|in:active,paused',
            'delete_images' => 'nullable|array',
            'delete_images.*' => 'uuid|exists:service_images,id',
        ]), [], __('marketplace.validation_attributes'));

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
            $tagNames = array_slice(array_filter(array_map(fn ($t) => ltrim(trim($t), '#'), explode(',', $data['tags']))), 0, 10);
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

    private function validationRules(Organization $organization): array
    {
        return [
            'title' => 'required|string|min:10|max:255',
            'description' => 'required|string|min:100',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost' => array_merge(['required', 'integer'], $this->pointLimitRules($organization)),
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|max:2048',
        ];
    }

    private function pointLimitRules(Organization $organization): array
    {
        return array_values(array_filter([
            $organization->servicePointsMin() !== null ? 'min:'.$organization->servicePointsMin() : null,
            $organization->servicePointsMax() !== null ? 'max:'.$organization->servicePointsMax() : null,
        ]));
    }
}
