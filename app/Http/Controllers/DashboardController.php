<?php

namespace App\Http\Controllers;

use App\Models\MemberAiProfile;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Support\Onboarding\MemberOnboardingSteps;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(MemberOnboardingSteps $onboarding): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $user = auth()->user();

        $myServices = $user->services()->where('status', 'active')->with('category')->latest()->get();
        // TASK-1693 bug U — « Mes demandes d'aide » taisait les demandes PRISES
        // EN CHARGE.
        //
        // Des qu'une personne propose son aide, la demande passe de `open` a
        // `in_progress` (`TransactionController:106`). La card ne listant que
        // `open`, elle DISPARAISSAIT de la vue de son auteur a la seconde ou
        // elle devenait vivante — c'est-a-dire au moment le plus interessant.
        //
        // Le titre dit « mes demandes », pas « mes demandes que personne n'a
        // encore vues ». On liste donc les deux etats, et c'est la PASTILLE qui
        // dit lequel. `closed` reste dehors : son auteur l'a ferme
        // deliberement, exactement comme les echanges termines restent hors de
        // la card « Échanges ».
        //
        // A noter : la page « Voir tout » (`dashboard.requests`) n'a JAMAIS
        // filtre sur le statut. L'information n'etait donc pas perdue, elle
        // etait absente de l'ecran d'accueil — la card mentait par omission.
        $myRequests = $user->serviceRequests()
            ->whereIn('status', ['open', 'in_progress'])
            ->with('category')
            ->latest()
            ->get();

        // TASK-1693 bug T — UNE seule card « Échanges », l'UNION des deux.
        //
        // Le tableau de bord portait deux cards qui se CHEVAUCHAIENT :
        //
        //   « Mes échanges en cours »  buyer_id = moi, statuts pending+accepted+buyer_done
        //   « Échanges en cours »      moi des deux cotes, statuts accepted+buyer_done
        //
        // Un echange dont j'etais acheteur et qui etait `accepted` figurait
        // donc DANS LES DEUX, meme titre, meme lien (constate en recette :
        // « Titre Titre Titre · 20 pts » affiche deux fois). Et comme la
        // premiere se masquait quand elle etait vide, le tableau de bord
        // changeait de FORME selon la personne : deux cards pour qui achete,
        // une seule pour qui vend.
        //
        // Supprimer l'une des deux aurait perdu quelque chose dans les deux
        // sens — la premiere seule montrait les propositions `pending`, la
        // seconde seule ce dont je suis vendeur. L'union ne perd rien, et
        // c'est deja exactement le perimetre de « Messages récents »
        // ci-dessous : la coherence est donc retablie, pas inventee.
        //
        // L'etat de chaque ligne — En attente / Accepté / Terminé (acheteur) —
        // est porte par la PASTILLE, a cote de chaque echange, au lieu d'etre
        // porte par le titre de deux cards qu'il fallait savoir distinguer.
        $exchanges = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->whereIn('status', ['pending', 'accepted', 'buyer_done'])
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

        // TASK-1361 : la definition des etapes vit desormais dans un support
        // partage, pour que le Shell reponde « je commence par quoi ? » a
        // partir de la MEME verite que cette page — et pas d'une copie.
        // Les libelles, les CTA et l'affichage restent ici : le support ne
        // porte que des cles et des booleens.
        $stepsDoneByKey = $onboarding->doneFor($user, $organization, $aiProfile);

        $hasPresentation = $stepsDoneByKey[MemberOnboardingSteps::STEP_PRESENTATION];
        $hasServiceRequest = $stepsDoneByKey[MemberOnboardingSteps::STEP_REQUEST];
        $hasService = $stepsDoneByKey[MemberOnboardingSteps::STEP_SERVICE];
        $hasPublishedAiProfile = $stepsDoneByKey[MemberOnboardingSteps::STEP_AI_PROFILE];

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
            $hasPresentation ? $onboardingRoute('profile.show', ['user' => $user->profileRouteKey()]) : $onboardingRoute('profile.edit'),
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
            'user',
            'myServices', 'myRequests', 'exchanges', 'recentMessages',
            'referralCode', 'referralLink', 'sentReferralsCount', 'activatedReferralsCount', 'referralPointsEarned',
            'aiProfile', 'onboardingSteps', 'requestCreateUrl',
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
