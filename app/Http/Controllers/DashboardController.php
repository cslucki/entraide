<?php

namespace App\Http\Controllers;

use App\Models\FeedPost;
use App\Models\MemberAiProfile;
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

        $aiProfile = MemberAiProfile::forUser($user)
            ->forOrganization($organization)
            ->first();

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
        $hasFavorite = $user->favorites()->where('organization_id', $organization->id)->exists();
        $hasSentReferral = $sentReferralsCount > 0;

        $onboardingRoute = function (string $name, array $parameters = []) use ($organization): string {
            $orgRoute = 'organization.'.$name;
            if ($organization && Route::has($orgRoute)) {
                return route($orgRoute, ['organization' => $organization->slug] + $parameters);
            }

            return route($name, $parameters);
        };

        $stepsKeys = ['presentation', 'request', 'service', 'ai_profile', 'leads', 'invite'];
        $stepsDone = [$hasPresentation, $hasServiceRequest, $hasService, $hasPublishedAiProfile, $hasFavorite, $hasSentReferral];
        $stepsCtaUrls = [
            $hasPresentation ? $onboardingRoute('profile.show', ['user' => $user]) : $onboardingRoute('profile.edit'),
            $onboardingRoute('requests.create'),
            $onboardingRoute('services.create'),
            $onboardingRoute('agent-ia.wizard'),
            $hasFavorite ? $onboardingRoute('favorites.index') : $onboardingRoute('explorer'),
            url()->current().'#invitations',
        ];

        $onboardingSteps = array_map(function ($key, $done, $ctaUrl) {
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
        ));
    }
}
