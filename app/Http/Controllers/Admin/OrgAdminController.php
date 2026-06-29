<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\BugReport;
use App\Models\Category;
use App\Models\LoginLog;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\SystemEmailTemplate;
use App\Models\Theme;
use App\Models\Transaction;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\LoopService;
use App\Services\TranslationOverrideService;
use App\Services\TranslationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrgAdminController extends Controller
{
    public function dashboard(Organization $organization): View
    {
        $orgId = $organization->id;
        $stats = [
            'users' => User::where('organization_id', $orgId)->count(),
            'loops' => Loop::where('organization_id', $orgId)->count(),
            'services' => Service::where('organization_id', $orgId)->where('status', 'active')->count(),
            'requests' => ServiceRequest::where('organization_id', $orgId)->count(),
        ];
        $recentUsers = User::where('organization_id', $orgId)->latest()->limit(5)->get();

        return view('admin.org.dashboard', [
            'organization' => $organization,
            'stats' => $stats,
            'recentUsers' => $recentUsers,
        ]);
    }

    public function services(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Service::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('status', 'active')->whereNull('deleted_at'),
                'paused' => $query->where('status', 'paused')->whereNull('deleted_at'),
                'deleted' => $query->onlyTrashed(),
                default => null,
            };
        }

        $services = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.services', [
            'organization' => $organization,
            'services' => $services,
        ]);
    }

    public function requests(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = ServiceRequest::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        $requests = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.requests', [
            'organization' => $organization,
            'requests' => $requests,
        ]);
    }

    public function transactions(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Transaction::where('organization_id', $orgId)->with(['buyer', 'seller', 'service']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('buyer', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'))
                    ->orWhereHas('seller', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $transactions = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.transactions', [
            'organization' => $organization,
            'transactions' => $transactions,
        ]);
    }

    public function closeRequest(Organization $organization, string $serviceRequest): RedirectResponse
    {
        $serviceRequest = ServiceRequest::where('organization_id', $organization->id)->findOrFail($serviceRequest);
        $serviceRequest->update(['status' => 'closed']);

        return back()->with('success', 'Demande clôturée.');
    }

    public function loops(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Loop::where('organization_id', $orgId)->with(['creator', 'activeMembers.user']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('status', 'active'),
                'archived' => $query->where('status', 'archived'),
                default => null,
            };
        }

        $loops = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.loops', [
            'organization' => $organization,
            'loops' => $loops,
        ]);
    }

    public function messages(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Message::where('organization_id', $orgId)->with(['sender', 'transaction']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('body', 'like', '%'.$request->search.'%')
                    ->orWhereHas('sender', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $messages = $query->latest('created_at')->paginate(25)->withQueryString();

        return view('admin.org.messages', [
            'organization' => $organization,
            'messages' => $messages,
        ]);
    }

    public function blog(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = BlogPost::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'draft' => $query->where('status', 'draft'),
                'published' => $query->where('status', 'published'),
                default => null,
            };
        }

        $posts = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.blog', [
            'organization' => $organization,
            'posts' => $posts,
        ]);
    }

    public function toggleLoopActive(Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $loop->update([
            'status' => $loop->isActive() ? 'archived' : 'active',
        ]);

        $action = $loop->isActive() ? 'reactivated' : 'archived';

        return back()->with('success', __("navigation.org_admin_loop_{$action}"));
    }

    public function addLoopMember(Request $request, Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $data = $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::findOrFail($data['user_id']);
        abort_if($user->organization_id !== $organization->id, 422, __('loops.not_member'));

        try {
            app(LoopService::class)->addMemberByUserId($loop, $data['user_id']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('loops.member_added'));
    }

    public function removeLoopMember(Organization $organization, Loop $loop, LoopMember $member): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);
        abort_if($member->loop_id !== $loop->id, 404);

        app(LoopService::class)->removeMember($member);

        return back()->with('success', __('loops.member_removed'));
    }

    public function publishBlogPost(Organization $organization, BlogPost $blogPost): RedirectResponse
    {
        abort_if($blogPost->organization_id !== $organization->id, 404);

        $blogPost->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return back()->with('success', __('navigation.org_admin_post_published'));
    }

    public function users(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = User::where('organization_id', $orgId);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'banned' => $query->whereNotNull('banned_at'),
                'active' => $query->whereNull('banned_at'),
                default => null,
            };
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'name' => $query->orderBy('name', $direction),
            'email' => $query->orderBy('email', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            'points_balance' => $query->orderBy('points_balance', $direction),
            'is_admin' => $query->orderBy('is_admin', $direction),
            'status' => $query->orderByRaw('banned_at IS NULL '.($direction === 'asc' ? 'ASC' : 'DESC').', banned_at '.$direction),
            default => $query->latest(),
        };

        $users = $query->paginate(25)->withQueryString();

        return view('admin.org.users', [
            'organization' => $organization,
            'users' => $users,
        ]);
    }

    public function toggleUserBan(Organization $organization, User $user): RedirectResponse
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $user->update([
            'banned_at' => $user->banned_at ? null : now(),
        ]);

        $action = $user->banned_at ? 'banned' : 'unbanned';

        return back()->with('success', __("navigation.org_admin_user_{$action}"));
    }

    // ── User deletion dry-run (org-admin) ─────────────────────────────────────

    public function deletePreview(Organization $organization, User $user): View
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $counts = $this->countUserRelations($organization, $user);

        $sameOrgUsers = User::where('organization_id', $organization->id)
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        return view('admin.org.users.delete-preview', compact('organization', 'user', 'counts', 'sameOrgUsers'));
    }

    public function deleteUser(Request $request, Organization $organization, User $user): View
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'confirmation' => 'required|string',
            'transfer_to' => 'nullable|uuid|exists:users,id',
        ]);

        if ($data['confirmation'] !== $user->name) {
            return $this->deletePreview($organization, $user);
        }

        $counts = $this->countUserRelations($organization, $user);

        if (! empty($data['transfer_to'])) {
            $transferTo = User::find($data['transfer_to']);
            if ($transferTo && $transferTo->organization_id === $organization->id) {
                $counts['transfer'] = $this->estimateTransferCounts($user, $data['transfer_to']);
            }
        }

        $counts['preview_only'] = true;

        $sameOrgUsers = User::where('organization_id', $organization->id)
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        return view('admin.org.users.delete-preview', compact('organization', 'user', 'counts', 'sameOrgUsers'));
    }

    private function countUserRelations(Organization $organization, User $user): array
    {
        $own = [
            'services' => Service::where('organization_id', $organization->id)
                ->where('user_id', $user->id)->count(),
            'service_requests' => ServiceRequest::where('organization_id', $organization->id)
                ->where('user_id', $user->id)->count(),
            'transactions_as_buyer' => Transaction::where('organization_id', $organization->id)
                ->where('buyer_id', $user->id)->count(),
            'transactions_as_seller' => Transaction::where('organization_id', $organization->id)
                ->where('seller_id', $user->id)->count(),
        ];

        $part = [
            'loop_memberships' => $user->loopMemberships()->whereHas('loop', fn ($q) => $q->where('organization_id', $organization->id))->count(),
            'loops_created' => Loop::where('organization_id', $organization->id)
                ->where('created_by', $user->id)->count(),
        ];

        $audit = [
            'login_logs' => LoginLog::where('organization_id', $organization->id)
                ->where('user_id', $user->id)->count(),
        ];

        return compact('own', 'part', 'audit');
    }

    private function estimateTransferCounts(User $user, string $transferToId): array
    {
        $transferTo = User::find($transferToId);
        if (! $transferTo || $transferTo->organization_id !== $user->organization_id) {
            return [];
        }

        return [
            'services' => Service::where('user_id', $user->id)->count(),
            'service_requests' => ServiceRequest::where('user_id', $user->id)->count(),
        ];
    }

    public function categories(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Category::where('organization_id', $orgId)
            ->with(['skills'])
            ->withCount(['services', 'serviceRequests', 'skills']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name_b2c', 'like', '%'.$request->search.'%')
                    ->orWhere('name_b2b', 'like', '%'.$request->search.'%');
            });
        }

        $categories = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.categories', [
            'organization' => $organization,
            'categories' => $categories,
        ]);
    }

    public function createCategory(Organization $organization): View
    {
        return view('admin.org.categories-form', [
            'organization' => $organization,
            'category' => null,
        ]);
    }

    public function storeCategory(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'name_b2c' => 'required|string|max:100',
            'name_b2b' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $data['organization_id'] = $organization->id;
        $category = Category::create($data);

        if ($request->has('skills')) {
            $skillNames = array_filter($request->input('skills', []), fn ($name) => ! empty(trim($name)));
            foreach ($skillNames as $skillName) {
                $category->skills()->create([
                    'name' => $skillName,
                    'slug' => Str::slug($skillName),
                    'organization_id' => $organization->id,
                ]);
            }
        }

        return redirect()->route('organization.admin.categories', [
            'organization' => $organization->slug,
        ])->with('success', 'Catégorie créée.');
    }

    public function editCategory(Organization $organization, Category $category): View
    {
        abort_if($category->organization_id !== $organization->id, 404);

        $category->load('skills');

        return view('admin.org.categories-form', [
            'organization' => $organization,
            'category' => $category,
        ]);
    }

    public function updateCategory(Request $request, Organization $organization, Category $category): RedirectResponse
    {
        abort_if($category->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'name_b2c' => 'required|string|max:100',
            'name_b2b' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $category->update($data);

        if ($request->has('skills')) {
            $skillNames = array_filter($request->input('skills', []), fn ($name) => ! empty(trim($name)));
            $existingSkills = $category->skills->keyBy('name');
            foreach ($skillNames as $skillName) {
                if (! $existingSkills->has($skillName)) {
                    $category->skills()->create([
                        'name' => $skillName,
                        'slug' => Str::slug($skillName),
                        'organization_id' => $organization->id,
                    ]);
                }
            }
            $skillsToDelete = $category->skills->whereNotIn('name', $skillNames);
            $skillsToDelete->each->delete();
        }

        return redirect()->route('organization.admin.categories', [
            'organization' => $organization->slug,
        ])->with('success', 'Catégorie mise à jour.');
    }

    public function destroyCategory(Organization $organization, Category $category): RedirectResponse
    {
        abort_if($category->organization_id !== $organization->id, 404);

        if ($category->services()->count() > 0 || $category->serviceRequests()->count() > 0) {
            return back()->with('error', 'Impossible de supprimer une catégorie utilisée par des services ou demandes.');
        }

        $category->skills()->delete();
        $category->delete();

        return redirect()->route('organization.admin.categories', [
            'organization' => $organization->slug,
        ])->with('success', 'Catégorie supprimée.');
    }

    public function storeCategorySkill(Request $request, Organization $organization, Category $category): RedirectResponse
    {
        abort_if($category->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        Skill::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'organization_id' => $organization->id,
        ]);

        return back()->with('success', 'Compétence ajoutée.');
    }

    public function destroyCategorySkill(Organization $organization, Skill $skill): RedirectResponse
    {
        abort_if($skill->organization_id !== $organization->id, 404);

        $skill->delete();

        return back()->with('success', 'Compétence supprimée.');
    }

    public function reports(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = BugReport::where('organization_id', $orgId)->with('reporter');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('reason', 'like', '%'.$request->search.'%');
        }

        $bugReports = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.reports', [
            'organization' => $organization,
            'bugReports' => $bugReports,
        ]);
    }

    public function resolveBugReport(Organization $organization, BugReport $bugReport): RedirectResponse
    {
        abort_if($bugReport->organization_id !== $organization->id, 404);

        $bugReport->update([
            'status' => 'resolved',
            'fixed_at' => now(),
        ]);

        return back()->with('success', __('navigation.org_admin_report_resolved'));
    }

    public function invitations(Organization $organization): View
    {
        $orgId = $organization->id;
        $referrals = Referral::where('organization_id', $orgId)
            ->with(['referrer', 'referredUser'])
            ->latest()
            ->paginate(25);

        return view('admin.org.invitations', [
            'organization' => $organization,
            'referrals' => $referrals,
        ]);
    }

    public function translations(
        Request $request,
        Organization $organization,
        TranslationOverrideService $overrideService,
    ): View {
        $translationService = app(TranslationService::class);
        $entries = $translationService->all();
        $groups = collect($translationService->getGroups());

        $orgId = $organization->id;
        $activeGroup = $request->input('group', '_all');
        $activeStatus = $request->input('status', '_all');
        $search = $request->input('search', '');

        $overrides = TranslationOverride::query()
            ->forOrganization($orgId)
            ->with(['createdBy'])
            ->latest()
            ->get()
            ->keyBy(fn (TranslationOverride $o) => "{$o->group}.{$o->key}:{$o->locale}");

        $entries = $entries->filter(function ($entry) use ($activeGroup, $activeStatus, $search, $overrides): bool {
            if ($activeGroup !== '_all' && $entry['group'] !== $activeGroup) {
                return false;
            }
            if ($activeStatus === 'OVERRIDDEN') {
                $hasOverride = isset($overrides["{$entry['group']}.{$entry['key']}:fr"])
                    || isset($overrides["{$entry['group']}.{$entry['key']}:en"]);
                if (! $hasOverride) {
                    return false;
                }
            } elseif ($activeStatus !== '_all' && $entry['status'] !== $activeStatus) {
                return false;
            }
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $fr = is_array($entry['fr'] ?? null) ? '' : (string) ($entry['fr'] ?? '');
                $en = is_array($entry['en'] ?? null) ? '' : (string) ($entry['en'] ?? '');
                if (! str_contains(mb_strtolower($entry['group']), $needle)
                    && ! str_contains(mb_strtolower($entry['key']), $needle)
                    && ! str_contains(mb_strtolower($fr), $needle)
                    && ! str_contains(mb_strtolower($en), $needle)) {
                    return false;
                }
            }

            return true;
        })->values();

        $overriddenCount = $entries->filter(fn ($e) => isset($overrides["{$e['group']}.{$e['key']}:fr"])
            || isset($overrides["{$e['group']}.{$e['key']}:en"])
        )->count();

        $remainingCount = $entries->count() - $overriddenCount;

        $stats = [
            'total' => $entries->count(),
            'ok' => $entries->where('status', 'OK')->count(),
            'missing_fr' => $entries->where('status', 'MISSING_FR')->count(),
            'missing_en' => $entries->where('status', 'MISSING_EN')->count(),
            'overridden' => $overriddenCount,
            'remaining' => $remainingCount,
        ];

        return view('admin.org.translations', [
            'organization' => $organization,
            'groups' => $groups,
            'entries' => $entries,
            'overrides' => $overrides,
            'activeGroup' => $activeGroup,
            'activeStatus' => $activeStatus,
            'search' => $search,
            'stats' => $stats,
        ]);
    }

    public function storeOverride(
        Request $request,
        Organization $organization,
        TranslationOverrideService $overrideService,
    ): RedirectResponse {
        $validated = $request->validate([
            'locale' => 'required|in:fr,en',
            'group' => 'required|string|max:100',
            'key' => 'required|string|max:100',
            'value' => 'required|string|max:1000',
        ]);

        $overrideService->set(
            group: $validated['group'],
            key: $validated['key'],
            locale: $validated['locale'],
            value: $validated['value'],
            organization: $organization,
            userId: auth()->id(),
        );

        return redirect()->route('organization.admin.translations', [
            'organization' => $organization->slug,
        ])->with('success', __('navigation.org_admin_translation_created'));
    }

    public function deactivateOverride(
        Organization $organization,
        TranslationOverride $translationOverride,
        TranslationOverrideService $overrideService,
    ): RedirectResponse {
        abort_if($translationOverride->organization_id !== $organization->id, 404);

        $overrideService->deactivate(
            group: $translationOverride->group,
            key: $translationOverride->key,
            locale: $translationOverride->locale,
            organization: $organization,
        );

        return back()->with('success', __('navigation.org_admin_translation_deactivated'));
    }

    public function resetOverride(
        Request $request,
        Organization $organization,
        TranslationOverrideService $overrideService,
    ): RedirectResponse {
        $validated = $request->validate([
            'group' => 'required|string|max:100',
            'key' => 'required|string|max:100',
        ]);

        $orgId = $organization->id;
        $count = TranslationOverride::query()
            ->where('organization_id', $orgId)
            ->where('group', $validated['group'])
            ->where('key', $validated['key'])
            ->where('is_active', true)
            ->count();

        if ($count === 0) {
            return back()->with('error', __('navigation.org_admin_translation_no_active'));
        }

        foreach (['fr', 'en'] as $locale) {
            $overrideService->deactivate(
                group: $validated['group'],
                key: $validated['key'],
                locale: $locale,
                organization: $organization,
            );
        }

        return back()->with('success', __('navigation.org_admin_translation_reset_done'));
    }

    public function identity(Organization $organization): View
    {
        return view('admin.org.identity', [
            'organization' => $organization,
        ]);
    }

    public function updateIdentity(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_logo' => 'nullable|boolean',
        ]);

        if ($request->boolean('remove_logo') && $organization->logo_path) {
            $this->deleteLogoFile($organization->logo_path);
            $organization->update(['logo_path' => null]);

            return redirect()->route('organization.admin.identity', [
                'organization' => $organization->slug,
            ])->with('success', __('admin.organization_logo_removed'));
        }

        if ($request->hasFile('logo')) {
            if ($organization->logo_path) {
                $this->deleteLogoFile($organization->logo_path);
            }

            $filename = Str::random(32).'.'.$request->file('logo')->extension();
            $path = $request->file('logo')->storeAs(
                'organization-logos/'.$organization->id,
                $filename,
                'public',
            );

            $organization->update(['logo_path' => $path]);

            return redirect()->route('organization.admin.identity', [
                'organization' => $organization->slug,
            ])->with('success', __('admin.organization_logo_updated'));
        }

        return redirect()->route('organization.admin.identity', [
            'organization' => $organization->slug,
        ])->with('info', __('admin.organization_logo_no_change'));
    }

    private function deleteLogoFile(string $path): void
    {
        if (str_starts_with($path, 'organization-logos/') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function aiSupervision(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_ai_supervision'));
    }

    public function memberAiProfiles(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_member_ai_profiles'));
    }

    public function aiInteractions(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_ai_interactions'));
    }

    // ── Design / Homepage ───────────────────────────────────────────────────────

    public function homepage(Organization $organization): View
    {
        return view('admin.org.homepage', compact('organization'));
    }

    public function updateHomepage(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'homepage_template' => ['nullable', 'string', Rule::in(['default', 'bouclepro_hero_v2', 'artscilab_hero'])],
            'subheadline' => ['nullable', 'string', 'max:500'],
            'card_create_label' => ['nullable', 'string', 'max:100'],
            'card_meet_label' => ['nullable', 'string', 'max:100'],
            'card_help_label' => ['nullable', 'string', 'max:100'],
            'card_offer_label' => ['nullable', 'string', 'max:100'],
            'ai_note' => ['nullable', 'string', 'max:255'],
            'primary_cta_label' => ['nullable', 'string', 'max:100'],
            'primary_cta_url' => ['nullable', 'string', 'max:500'],
            'secondary_cta_label' => ['nullable', 'string', 'max:100'],
            'secondary_cta_url' => ['nullable', 'string', 'max:500'],
            'headline_solid' => ['nullable', 'string', 'max:100'],
            'headline_outline' => ['nullable', 'string', 'max:200'],
            'card_1_label' => ['nullable', 'string', 'max:100'],
            'card_2_label' => ['nullable', 'string', 'max:100'],
            'card_3_label' => ['nullable', 'string', 'max:100'],
            'card_4_label' => ['nullable', 'string', 'max:100'],
        ]);

        foreach (['primary_cta_url', 'secondary_cta_url'] as $urlField) {
            if (! empty($validated[$urlField]) && ! $this->isSafeHomepageUrl($validated[$urlField])) {
                return back()->withErrors([$urlField => 'URL invalide. Utilisez une URL interne relative ou une URL HTTPS.'])->withInput();
            }
        }

        $template = $validated['homepage_template'] ?? null;

        $settings = [];
        foreach (['subheadline', 'card_create_label', 'card_meet_label', 'card_help_label', 'card_offer_label', 'ai_note', 'primary_cta_label', 'primary_cta_url', 'secondary_cta_label', 'secondary_cta_url', 'headline_solid', 'headline_outline', 'card_1_label', 'card_2_label', 'card_3_label', 'card_4_label'] as $field) {
            if (filled($validated[$field] ?? null)) {
                $settings[$field] = $validated[$field];
            }
        }

        $organization->update([
            'homepage_template' => $template,
            'homepage_settings' => ! empty($settings) ? $settings : null,
        ]);

        return redirect()->route('organization.admin.homepage', $organization)
            ->with('success', 'Page d\'accueil mise à jour.');
    }

    private function isSafeHomepageUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https';
    }

    // ── Design / Themes ─────────────────────────────────────────────────────────

    public function themes(Request $request, Organization $organization): View
    {
        $mainOrgId = Organization::orderBy('created_at')->value('id');
        $themes = Theme::with('organization')
            ->whereIn('organization_id', [$organization->id, $mainOrgId])
            ->orderBy('is_default', 'desc')
            ->orderBy('label')
            ->get();

        $currentTheme = null;
        if ($request->filled('theme')) {
            $currentTheme = $themes->firstWhere('key', $request->theme);
        }
        if (! $currentTheme) {
            $currentTheme = $themes->firstWhere('is_default', true) ?? $themes->first();
        }

        $themeKeys = $themes->pluck('key')->values()->all();
        $currentIndex = array_search($currentTheme->key, $themeKeys);
        $prevTheme = $currentIndex > 0 ? $themes[$currentIndex - 1] : null;
        $nextTheme = $currentIndex < count($themeKeys) - 1 ? $themes[$currentIndex + 1] : null;

        return view('admin.org.themes.index', compact('organization', 'themes', 'currentTheme', 'prevTheme', 'nextTheme'));
    }

    public function themesCreate(Organization $organization): View
    {
        return view('admin.org.themes.create', compact('organization'));
    }

    public function themesStore(Request $request, Organization $organization): RedirectResponse
    {
        if ($request->has('tokens') && is_string($request->tokens)) {
            $request->merge(['tokens' => json_decode($request->tokens, true) ?? []]);
        }
        if ($request->has('dark_tokens') && is_string($request->dark_tokens)) {
            $request->merge(['dark_tokens' => json_decode($request->dark_tokens, true) ?? []]);
        }

        $data = $request->validate([
            'key' => 'required|string|max:50|unique:themes,key',
            'label' => 'required|string|max:100',
            'description' => 'nullable|string',
            'tokens' => ['required', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'dark_tokens' => ['nullable', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token sombre « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
        ]);

        $data['organization_id'] = $organization->id;
        $data['is_default'] = false;
        $data['dark_tokens'] = $data['dark_tokens'] ?? [];

        $theme = Theme::create($data);
        Theme::regenerateCache();

        return redirect()->route('organization.admin.themes', [$organization, 'theme' => $theme->key])
            ->with('success', 'Thème « '.$theme->label.' » créé.');
    }

    public function themesEdit(Organization $organization, Theme $theme): View
    {
        abort_if($theme->organization_id !== $organization->id, 403, 'Vous ne pouvez modifier que vos propres thèmes.');

        return view('admin.org.themes.edit', compact('organization', 'theme'));
    }

    public function themesUpdate(Request $request, Organization $organization, Theme $theme): RedirectResponse
    {
        abort_if($theme->organization_id !== $organization->id, 403, 'Vous ne pouvez modifier que vos propres thèmes.');

        if ($request->has('tokens') && is_string($request->tokens)) {
            $request->merge(['tokens' => json_decode($request->tokens, true) ?? []]);
        }
        if ($request->has('dark_tokens') && is_string($request->dark_tokens)) {
            $request->merge(['dark_tokens' => json_decode($request->dark_tokens, true) ?? []]);
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', Rule::unique('themes', 'key')->ignore($theme->id)],
            'label' => 'required|string|max:100',
            'description' => 'nullable|string',
            'tokens' => ['required', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'dark_tokens' => ['nullable', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token sombre « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
        ]);
        $data['dark_tokens'] = $data['dark_tokens'] ?? [];

        $theme->update($data);
        Theme::regenerateCache();

        return redirect()->route('organization.admin.themes', [$organization, 'theme' => $theme->key])
            ->with('success', 'Thème « '.$theme->label.' » mis à jour.');
    }

    public function themesDestroy(Organization $organization, Theme $theme): RedirectResponse
    {
        abort_if($theme->organization_id !== $organization->id, 403, 'Vous ne pouvez supprimer que vos propres thèmes.');

        if ($theme->is_default) {
            return back()->with('error', 'Impossible de supprimer le thème par défaut.');
        }

        Organization::where('theme_id', $theme->id)->update(['theme_id' => null]);

        $theme->delete();
        Theme::regenerateCache();

        return redirect()->route('organization.admin.themes', [$organization])
            ->with('success', 'Thème « '.$theme->label.' » supprimé.');
    }

    public function themesAssign(Organization $organization, Theme $theme): RedirectResponse
    {
        $mainOrgId = Organization::orderBy('created_at')->value('id');
        abort_if(
            $theme->organization_id !== $organization->id && $theme->organization_id !== $mainOrgId,
            403,
            'Ce thème ne peut pas être sélectionné pour cette organisation.'
        );

        $organization->update(['theme_id' => $theme->id]);

        return redirect()->route('organization.admin.themes', [$organization, 'theme' => $theme->key])
            ->with('success', 'Thème « '.$theme->label.' » appliqué.');
    }

    // ── Login history ─────────────────────────────────────────────────────────

    public function loginHistory(Request $request, Organization $organization): View
    {
        $query = LoginLog::where('organization_id', $organization->id)
            ->with('user');

        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'user' => $query->orderBy(
                User::select('name')->whereColumn('id', 'login_logs.user_id')->limit(1),
                $direction
            ),
            'ip_address' => $query->orderBy('ip_address', $direction),
            default => $query->latest('created_at'),
        };

        $loginLogs = $query->paginate(25)->withQueryString();

        return view('admin.org.login-history.index', [
            'organization' => $organization,
            'loginLogs' => $loginLogs,
        ]);
    }

    public function loginHistoryUser(Request $request, Organization $organization, User $user): View
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $logs = LoginLog::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.org.login-history.user', [
            'organization' => $organization,
            'user' => $user,
            'logs' => $logs,
        ]);
    }

    public function systemEmailTemplates(Request $request, Organization $organization): View
    {
        $query = SystemEmailTemplate::with('organization')
            ->where('organization_id', $organization->id)
            ->orderBy('locale')
            ->orderBy('name');

        $locale = $request->input('locale', $organization->locale);

        if ($locale) {
            $query->where('locale', $locale);
        }

        $templates = $query->get();

        return view('admin.org.system-email-templates', [
            'organization' => $organization,
            'templates' => $templates,
            'currentLocale' => $locale,
        ]);
    }

    public function editSystemEmailTemplate(Organization $organization, SystemEmailTemplate $systemEmailTemplate): View
    {
        abort_if($systemEmailTemplate->organization_id !== $organization->id, 404);

        return view('admin.org.system-email-template-edit', [
            'organization' => $organization,
            'systemEmailTemplate' => $systemEmailTemplate,
        ]);
    }

    public function updateSystemEmailTemplate(Request $request, Organization $organization, SystemEmailTemplate $systemEmailTemplate): RedirectResponse
    {
        abort_if($systemEmailTemplate->organization_id !== $organization->id, 404);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'enabled' => 'boolean',
        ]);

        $validated['enabled'] = $request->boolean('enabled');

        $systemEmailTemplate->update($validated);

        return redirect()->route('organization.admin.system-email-templates', $organization)
            ->with('success', __('admin.emailer_updated'));
    }

    private function comingSoon(Organization $organization, string $sectionName): View
    {
        return view('admin.org.coming-soon', [
            'organization' => $organization,
            'sectionName' => $sectionName,
        ]);
    }
}
