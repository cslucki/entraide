<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DossierController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->currentOrganizationOrFail();
        $this->authorize('viewAny', Dossier::class);

        $userId = $request->user()->id;

        $ownedDossiers = Dossier::query()
            ->where('organization_id', $organization->id)
            ->where('owner_id', $userId)
            ->withCount('dossierMembers')
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        $sharedDossiers = Dossier::query()
            ->where('organization_id', $organization->id)
            ->where('owner_id', '!=', $userId)
            ->whereHas('dossierMembers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->with(['owner:id,first_name,name,email', 'dossierMembers' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->latest('updated_at')
            ->get();

        return view('dossiers.index', [
            'dossiers' => $ownedDossiers,
            'sharedDossiers' => $sharedDossiers,
        ]);
    }

    public function create(): View
    {
        $this->currentOrganizationOrFail();
        $this->authorize('create', Dossier::class);

        return view('dossiers.create');
    }

    public function show(Request $request): View
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('view', $dossier);

        $userId = $request->user()->id;
        $isOwner = $dossier->owner_id === $userId;
        $userRole = $isOwner ? 'owner' : ($dossier->memberRoleFor($userId) ?? 'none');
        $canManageArticles = $isOwner || $userRole === 'editor';

        $dossier->load([
            'dossierBlogPosts.blogPost.user:id,first_name,name,email,organization_id',
            'dossierMembers.user:id,first_name,name,email',
        ]);

        $series = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->with(['rootBlogPost:id,organization_id,user_id,title,slug,status,updated_at', 'items.blogPost:id,organization_id,user_id,title,slug,status,updated_at'])
            ->first();

        $eligibleArticles = collect();
        if ($canManageArticles) {
            $eligibleArticles = BlogPost::query()
                ->with('user:id,first_name,name,email,organization_id')
                ->where('organization_id', $organization->id)
                ->where('user_id', $userId)
                ->whereDoesntHave('dossierEntry')
                ->latest('updated_at')
                ->get(['id', 'organization_id', 'user_id', 'title', 'slug', 'status', 'updated_at']);
        }

        $seriesEligibleArticles = collect();
        if ($canManageArticles && $series) {
            $seriesEligibleArticles = $dossier->articles()
                ->whereNotIn('blog_posts.id', array_merge(
                    [$series->root_blog_post_id],
                    $series->items->pluck('blog_post_id')->toArray()
                ))
                ->get(['blog_posts.id', 'blog_posts.organization_id', 'user_id', 'title', 'slug', 'status', 'blog_posts.updated_at']);
        } elseif ($canManageArticles && ! $series) {
            $seriesEligibleArticles = $dossier->articles()
                ->get(['blog_posts.id', 'blog_posts.organization_id', 'user_id', 'title', 'slug', 'status', 'blog_posts.updated_at']);
        }

        return view('dossiers.show', [
            'dossier' => $dossier,
            'eligibleArticles' => $eligibleArticles,
            'series' => $series,
            'seriesEligibleArticles' => $seriesEligibleArticles,
            'userRole' => $userRole,
            'canManageArticles' => $canManageArticles,
            'canManageMembers' => $isOwner,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganizationOrFail();
        $this->authorize('create', Dossier::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['prohibited'],
            'visibility' => ['nullable', Rule::in([Dossier::VISIBILITY_PRIVATE])],
        ]);

        Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $request->user()->id,
            'name' => $data['name'],
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        return redirect()
            ->route('organization.dossiers.index', ['organization' => $organization])
            ->with('success', __('dossiers.created'));
    }

    public function edit(Request $request): View
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        return view('dossiers.edit', [
            'dossier' => $dossier,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['prohibited'],
            'visibility' => ['nullable', Rule::in([Dossier::VISIBILITY_PRIVATE])],
        ]);

        $dossier->update([
            'name' => $data['name'],
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        return redirect()
            ->route('organization.dossiers.index', ['organization' => $organization])
            ->with('success', __('dossiers.updated'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('delete', $dossier);

        DB::transaction(function () use ($dossier) {
            $seriesIds = $dossier->articleSeries()->pluck('id');
            ArticleSeriesItem::whereIn('article_series_id', $seriesIds)->delete();
            ArticleSeries::whereIn('id', $seriesIds)->delete();
            $dossier->files()->update(['dossier_id' => null]);
            $dossier->dossierMembers()->delete();
            $dossier->dossierBlogPosts()->delete();
            $dossier->delete();
        });

        return redirect()
            ->route('organization.dossiers.index', ['organization' => $organization])
            ->with('success', __('dossiers.deleted'));
    }

    private function currentOrganizationOrFail()
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        return $organization;
    }

    private function resolveDossier(mixed $dossier): Dossier
    {
        if ($dossier instanceof Dossier) {
            return $dossier;
        }

        return Dossier::query()->whereKey($dossier)->firstOrFail();
    }

    private function ensureDossierBelongsToCurrentOrganization(Dossier $dossier): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($dossier->organization_id !== $organization->id) {
            abort(404);
        }
    }
}
