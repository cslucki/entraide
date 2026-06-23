<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\BugReport;
use App\Models\Category;
use App\Models\Loop;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\TranslationOverrideService;
use App\Services\TranslationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $query = Loop::where('organization_id', $orgId)->with(['creator']);

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

        $users = $query->latest()->paginate(25)->withQueryString();

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

    public function categories(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Category::where('organization_id', $orgId);

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

    private function comingSoon(Organization $organization, string $sectionName): View
    {
        return view('admin.org.coming-soon', [
            'organization' => $organization,
            'sectionName' => $sectionName,
        ]);
    }
}
