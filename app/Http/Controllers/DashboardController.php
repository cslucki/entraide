<?php

namespace App\Http\Controllers;

use App\Models\FeedPost;
use App\Models\MemberAiProfile;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $user = auth()->user();

        $earned = $user->pointLedger()->where('delta', '>', 0)->sum('delta');
        $spent = abs($user->pointLedger()->where('delta', '<', 0)->sum('delta'));
        $completedCount = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'completed')->count();

        $myServices = $user->services()->where('status', 'active')->with('category')->latest()->get();
        $myRequests = $user->serviceRequests()->where('status', 'open')->with('category')->latest()->get();

        $myProposals = Transaction::where('buyer_id', $user->id)
            ->whereIn('status', ['pending', 'accepted', 'buyer_done'])
            ->with(['service', 'serviceRequest', 'seller'])
            ->latest()
            ->get();

        $activeExchanges = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->whereIn('status', ['accepted', 'buyer_done'])
            ->with(['buyer', 'seller', 'service', 'serviceRequest'])
            ->latest()
            ->get();

        $recentMessages = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->whereIn('status', ['pending', 'accepted', 'buyer_done'])
            ->with(['buyer', 'seller', 'service', 'serviceRequest', 'messages' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        $aiProfile = $organization->ai_profiles_enabled
            ? MemberAiProfile::forUser($user)->forOrganization($organization)->first()
            : null;

        $requestCreateUrl = (function () use ($organization): string {
            $orgRoute = 'organization.requests.create';
            if ($organization && Route::has($orgRoute)) {
                return route($orgRoute, ['organization' => $organization->slug]);
            }

            return route('requests.create');
        })();

        $referralCode = $user->referral_code;
        $referralLink = $user->organization?->slug && $user->referral_code
            ? route('organization.register', ['organization' => $user->organization->slug, 'ref' => $user->referral_code])
            : null;
        $sentReferralsCount = $user->sentReferrals()->where('organization_id', $organization->id)->count();
        $activatedReferralsCount = $user->sentReferrals()->where('organization_id', $organization->id)->where('status', 'activated')->count();
        $referralPointsEarned = $user->referralRewards()->sum('points');

        $myFeedPosts = FeedPost::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get();

        $canCreateFeedPost = $user->can('create', FeedPost::class);

        $usesDefaultOrganizationRoute = (bool) $organization->is_default;
        $feedUrl = $usesDefaultOrganizationRoute && Route::has('flux')
            ? route('flux')
            : route('organization.flux', ['organization' => $organization->slug]);
        $feedCreateUrl = $usesDefaultOrganizationRoute && Route::has('flux.create')
            ? route('flux.create')
            : route('organization.flux.create', ['organization' => $organization->slug]);
        $myFeedPostsUrl = $usesDefaultOrganizationRoute && Route::has('flux.my')
            ? route('flux.my')
            : route('organization.flux.my', ['organization' => $organization->slug]);

        $hasPresentation = filled($user->bio);
        $hasServiceRequest = $user->serviceRequests()->where('organization_id', $organization->id)->exists();
        $hasService = $user->services()->where('organization_id', $organization->id)->exists();
        $hasPublishedAiProfile = $aiProfile?->status === MemberAiProfile::STATUS_PUBLISHED;

        $onboardingRoute = function (string $name, array $parameters = []) use ($organization): string {
            $orgRoute = 'organization.'.$name;
            if ($organization && Route::has($orgRoute)) {
                return route($orgRoute, ['organization' => $organization->slug] + $parameters);
            }

            return route($name, $parameters);
        };

        $aiProfileDisabled = ! $organization->ai_profiles_enabled;
        $subscriptionsEnabled = $organization->subscriptions_enabled;

        $stepsKeys = ['presentation', 'request', 'service', 'ai_profile'];
        $stepsDone = [$hasPresentation, $hasServiceRequest, $hasService, $hasPublishedAiProfile];
        $stepsCtaUrls = [
            $hasPresentation ? $onboardingRoute('profile.show', ['user' => $user]) : $onboardingRoute('profile.edit'),
            $hasServiceRequest ? $onboardingRoute('dashboard.requests') : $onboardingRoute('requests.create'),
            $hasService ? $onboardingRoute('dashboard.services') : $onboardingRoute('services.create'),
            $aiProfileDisabled
                ? ($subscriptionsEnabled ? $onboardingRoute('subscriptions') : null)
                : $onboardingRoute('agent-ia.wizard'),
        ];

        $onboardingSteps = array_map(function ($key, $done, $ctaUrl) use ($aiProfileDisabled, $subscriptionsEnabled) {
            if ($key === 'ai_profile' && $aiProfileDisabled) {
                return [
                    'key' => $key,
                    'title' => __("dashboard.steps.{$key}.title"),
                    'description' => __("dashboard.steps.{$key}.description"),
                    'status' => 'disabled',
                    'status_label' => $subscriptionsEnabled
                        ? __('dashboard.ai_profile_disabled_subscription')
                        : __('dashboard.ai_profile_disabled_admin'),
                    'cta_label' => $subscriptionsEnabled
                        ? __('dashboard.see_subscriptions')
                        : __('dashboard.contact_admin'),
                    'cta_url' => $ctaUrl,
                ];
            }

            return [
                'key' => $key,
                'title' => __("dashboard.steps.{$key}.title"),
                'description' => __("dashboard.steps.{$key}.description"),
                'status' => $done ? 'done' : 'todo',
                'status_label' => $done ? __('dashboard.done') : __('dashboard.todo'),
                'cta_label' => $done ? __("dashboard.steps.{$key}.cta_done") : __("dashboard.steps.{$key}.cta_todo"),
                'cta_url' => $ctaUrl,
            ];
        }, $stepsKeys, $stepsDone, $stepsCtaUrls);

        return view('dashboard', compact(
            'user', 'earned', 'spent', 'completedCount',
            'myServices', 'myRequests', 'myProposals', 'activeExchanges', 'recentMessages',
            'referralCode', 'referralLink', 'sentReferralsCount', 'activatedReferralsCount', 'referralPointsEarned',
            'aiProfile', 'onboardingSteps', 'requestCreateUrl',
            'myFeedPosts', 'canCreateFeedPost', 'feedUrl', 'feedCreateUrl', 'myFeedPostsUrl',
            'aiProfileDisabled', 'subscriptionsEnabled',
        ));
    }

    public function requests(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $user = auth()->user();

        $serviceRequests = ServiceRequest::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->with(['category', 'attachments', 'transactions'])
            ->latest()
            ->paginate(20);

        return view('dashboard.requests', compact('serviceRequests', 'organization'));
    }

    public function requestDetail(ServiceRequest $serviceRequest): View
    {
        $organization = currentOrganization();

        if (! $organization || $serviceRequest->organization_id !== $organization->id) {
            abort(404);
        }

        $user = auth()->user();

        if ($serviceRequest->user_id !== $user->id) {
            abort(403);
        }

        $serviceRequest->load(['user', 'category', 'attachments', 'transactions.buyer']);

        $respondents = $serviceRequest->transactions;

        return view('dashboard.request-detail', compact('serviceRequest', 'organization', 'respondents'));
    }

    public function services(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $user = auth()->user();

        $services = Service::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->with(['category', 'images', 'transactions'])
            ->latest()
            ->paginate(20);

        return view('dashboard.services', compact('services', 'organization'));
    }

    public function serviceDetail(Service $service): View
    {
        $organization = currentOrganization();

        if (! $organization || $service->organization_id !== $organization->id) {
            abort(404);
        }

        $user = auth()->user();

        if ($service->user_id !== $user->id) {
            abort(403);
        }

        $service->load(['user', 'category', 'images', 'transactions.buyer']);

        $respondents = $service->transactions;

        return view('dashboard.service-detail', compact('service', 'organization', 'respondents'));
    }
}
