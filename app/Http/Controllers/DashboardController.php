<?php

namespace App\Http\Controllers;

use App\Models\MemberAiProfile;
use App\Models\Transaction;
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
            if (request()->routeIs('organization.*')) {
                return route('organization.requests.create', ['organization' => $organization->slug]);
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

        $hasPresentation = filled($user->bio);
        $hasServiceRequest = $user->serviceRequests()->where('organization_id', $organization->id)->exists();
        $hasService = $user->services()->where('organization_id', $organization->id)->exists();
        $hasPublishedAiProfile = $aiProfile?->status === MemberAiProfile::STATUS_PUBLISHED;
        $hasFavorite = $user->favorites()->where('organization_id', $organization->id)->exists();
        $hasSentReferral = $sentReferralsCount > 0;

        $onboardingRoute = function (string $name, array $parameters = []) use ($organization): string {
            if (request()->routeIs('organization.*')) {
                return route('organization.'.$name, ['organization' => $organization->slug] + $parameters);
            }

            return route($name, $parameters);
        };

        $onboardingSteps = [
            [
                'key' => 'presentation',
                'title' => 'Créer ma présentation',
                'description' => 'Présentez vos besoins, vos compétences et votre contexte.',
                'status' => $hasPresentation ? 'done' : 'todo',
                'status_label' => $hasPresentation ? 'Terminé' : 'À faire',
                'cta_label' => $hasPresentation ? 'Voir mon profil' : 'Compléter mon profil',
                'cta_url' => $hasPresentation ? $onboardingRoute('profile.show', ['user' => $user]) : $onboardingRoute('profile.edit'),
            ],
            [
                'key' => 'request',
                'title' => 'Demander de l’aide',
                'description' => 'Publiez une demande claire pour recevoir un coup de main.',
                'status' => $hasServiceRequest ? 'done' : 'todo',
                'status_label' => $hasServiceRequest ? 'Terminé' : 'À faire',
                'cta_label' => $hasServiceRequest ? 'Voir mes demandes' : 'Créer une demande',
                'cta_url' => $onboardingRoute('requests.create'),
            ],
            [
                'key' => 'service',
                'title' => 'Proposer mon aide',
                'description' => 'Indiquez ce que vous pouvez offrir à la communauté.',
                'status' => $hasService ? 'done' : 'todo',
                'status_label' => $hasService ? 'Terminé' : 'À faire',
                'cta_label' => $hasService ? 'Voir mes propositions' : 'Créer une proposition',
                'cta_url' => $onboardingRoute('services.create'),
            ],
            [
                'key' => 'ai-profile',
                'title' => 'Créer mon agent IA',
                'description' => 'Aidez l’IA à mieux orienter les échanges vers votre profil.',
                'status' => $hasPublishedAiProfile ? 'done' : 'todo',
                'status_label' => $hasPublishedAiProfile ? 'Terminé' : 'À faire',
                'cta_label' => $hasPublishedAiProfile ? 'Voir mon agent' : 'Configurer mon agent',
                'cta_url' => $onboardingRoute('agent-ia.wizard'),
            ],
            [
                'key' => 'leads',
                'title' => 'Découvrir Mes pistes',
                'description' => 'Explorez les profils et gardez les services utiles en favoris.',
                'status' => $hasFavorite ? 'done' : 'todo',
                'status_label' => $hasFavorite ? 'Terminé' : 'À faire',
                'cta_label' => $hasFavorite ? 'Voir mes favoris' : 'Explorer les pistes',
                'cta_url' => $hasFavorite ? $onboardingRoute('favorites.index') : $onboardingRoute('explorer'),
            ],
            [
                'key' => 'invite',
                'title' => 'Inviter une personne',
                'description' => 'Faites entrer une personne de confiance dans la boucle.',
                'status' => $hasSentReferral ? 'done' : 'todo',
                'status_label' => $hasSentReferral ? 'Terminé' : 'À faire',
                'cta_label' => $hasSentReferral ? 'Voir mes invitations' : 'Inviter quelqu’un',
                'cta_url' => url()->current().'#invitations',
            ],
        ];

        return view('dashboard', compact(
            'user', 'earned', 'spent', 'completedCount',
            'myServices', 'myRequests', 'myProposals', 'activeExchanges', 'recentMessages',
            'referralCode', 'referralLink', 'sentReferralsCount', 'activatedReferralsCount', 'referralPointsEarned',
            'aiProfile', 'onboardingSteps', 'requestCreateUrl',
        ));
    }
}
