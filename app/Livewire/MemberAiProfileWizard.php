<?php

namespace App\Livewire;

use App\Models\MemberAiProfile;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Livewire\Component;

class MemberAiProfileWizard extends Component
{
    public ?MemberAiProfile $profile = null;

    public int $step = 1;

    public array $wizardState = [];

    public ?string $member_profile_summary = null;

    public array $target_audience = [];

    public ?string $target_audience_other = null;

    public ?string $problems_helped_raw = null;

    public ?string $service_scope = null;

    public string $skillsInput = '';

    public ?string $experience_context = null;

    public array $help_types = [];

    public array $boundaries = [];

    public ?string $preferred_contact_action = null;

    public ?string $tone = null;

    public array $good_request_examples = [];

    public array $bad_request_examples = [];

    public ?string $goodExampleInput = null;

    public ?string $badExampleInput = null;

    public function mount(): void
    {
        $user = auth()->user();
        $organization = currentOrganization()
            ?? $user?->organization
            ?? DefaultOrganizationResolver::resolve();

        if (! $organization || ! $user) {
            abort(404);
        }

        $this->profile = MemberAiProfile::forUser($user)
            ->forOrganization($organization)
            ->first();

        if ($this->profile) {
            $this->loadProfileData();
        }
    }

    public function loadProfileData(): void
    {
        $this->step = max(1, $this->profile->wizard_state['step'] ?? 1);
        $this->wizardState = $this->profile->wizard_state ?? [];
        $this->member_profile_summary = $this->profile->member_profile_summary;
        $this->service_scope = $this->profile->service_scope;
        $this->experience_context = $this->profile->experience_context;
        $this->skillsInput = is_array($this->profile->skills) ? implode(', ', $this->profile->skills) : '';
        $this->help_types = $this->profile->help_types ?? [];
        $this->boundaries = $this->profile->boundaries ?? [];
        $this->preferred_contact_action = $this->profile->preferred_contact_action;
        $this->tone = $this->profile->tone;
        $this->good_request_examples = $this->profile->good_request_examples ?? [];
        $this->bad_request_examples = $this->profile->bad_request_examples ?? [];

        $audience = $this->profile->target_audience ?? [];
        $knownOptions = config('member_ai_profile.target_audience_options', []);
        $this->target_audience = array_intersect($audience, $knownOptions);
        $custom = array_diff($audience, $knownOptions);
        $this->target_audience_other = ! empty($custom) ? implode(', ', $custom) : null;

        $this->problems_helped_raw = $this->profile->problems_helped
            ? (is_array($this->profile->problems_helped) ? implode("\n", $this->profile->problems_helped) : $this->profile->problems_helped)
            : null;
    }

    public function getProfile(): MemberAiProfile
    {
        if ($this->profile) {
            return $this->profile;
        }

        $user = auth()->user();
        $organization = currentOrganization()
            ?? $user?->organization
            ?? DefaultOrganizationResolver::resolve();

        $this->profile = MemberAiProfile::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => MemberAiProfile::STATUS_DRAFT,
            'locale' => 'fr',
        ]);

        return $this->profile;
    }

    public function goToStep(int $target): void
    {
        if ($target < 1) {
            $target = 1;
        }
        if ($target > 5) {
            $target = 5;
        }
        $this->step = $target;
    }

    public function saveAndContinue(): void
    {
        $rules = $this->stepValidationRules();
        if (! empty($rules)) {
            $this->validate($rules);
        }
        $this->saveStep();
        $this->goToStep($this->step + 1);
    }

    public function previousStep(): void
    {
        $this->goToStep($this->step - 1);
    }

    public function saveStep(): void
    {
        $profile = $this->getProfile();
        $data = match ($this->step) {
            1 => $this->step1Data(),
            2 => $this->step2Data(),
            3 => $this->step3Data(),
            4 => $this->step4Data(),
            default => [],
        };

        if ($this->step === 1) {
            $data['target_audience'] = $this->buildTargetAudience();
            $data['problems_helped'] = $this->buildProblemsHelped();
        }

        $wizardState = $this->wizardState;
        $wizardState['step'] = $this->step;
        $visited = $wizardState['visited'] ?? [];
        if (! in_array($this->step, $visited)) {
            $visited[] = $this->step;
        }
        $wizardState['visited'] = $visited;

        if ($this->hasStepContent($this->step)) {
            $completed = $wizardState['completed'] ?? [];
            if (! in_array($this->step, $completed)) {
                $completed[] = $this->step;
            }
            $wizardState['completed'] = $completed;
        }

        $data['wizard_state'] = $wizardState;
        $data['last_saved_at'] = now();

        $profile->update($data);
        $this->wizardState = $wizardState;
    }

    protected function buildTargetAudience(): array
    {
        $values = $this->target_audience;
        if ($this->target_audience_other) {
            $values[] = trim($this->target_audience_other);
        }

        return array_filter(array_unique($values));
    }

    protected function buildProblemsHelped(): array
    {
        return $this->problems_helped_raw
            ? array_filter(array_map('trim', explode("\n", $this->problems_helped_raw)))
            : [];
    }

    protected function step1Data(): array
    {
        return [
            'member_profile_summary' => $this->member_profile_summary,
        ];
    }

    protected function step2Data(): array
    {
        return [
            'service_scope' => $this->service_scope,
            'skills' => array_slice(
                array_filter(array_map('trim', explode(',', $this->skillsInput))), 0, 10
            ),
            'experience_context' => $this->experience_context,
            'help_types' => $this->help_types,
        ];
    }

    protected function step3Data(): array
    {
        return [
            'boundaries' => $this->boundaries,
            'preferred_contact_action' => $this->preferred_contact_action,
            'tone' => $this->tone,
        ];
    }

    protected function step4Data(): array
    {
        return [
            'good_request_examples' => $this->good_request_examples,
            'bad_request_examples' => $this->bad_request_examples,
        ];
    }

    public function toggleTargetAudience(string $key): void
    {
        if (in_array($key, $this->target_audience)) {
            $this->target_audience = array_values(array_filter($this->target_audience, fn ($v) => $v !== $key));
        } else {
            $this->target_audience[] = $key;
        }
    }

    public function toggleHelpType(string $key): void
    {
        if (in_array($key, $this->help_types)) {
            $this->help_types = array_values(array_filter($this->help_types, fn ($v) => $v !== $key));
        } else {
            $this->help_types[] = $key;
        }
    }

    public function toggleBoundary(string $key): void
    {
        if (in_array($key, $this->boundaries)) {
            $this->boundaries = array_values(array_filter($this->boundaries, fn ($v) => $v !== $key));
        } else {
            $this->boundaries[] = $key;
        }
    }

    public function addGoodExample(): void
    {
        $value = trim($this->goodExampleInput ?? '');
        if ($value === '' || count($this->good_request_examples) >= 3) {
            return;
        }
        $this->good_request_examples[] = $value;
        $this->goodExampleInput = null;
    }

    public function removeGoodExample(int $index): void
    {
        unset($this->good_request_examples[$index]);
        $this->good_request_examples = array_values($this->good_request_examples);
    }

    public function addBadExample(): void
    {
        $value = trim($this->badExampleInput ?? '');
        if ($value === '' || count($this->bad_request_examples) >= 3) {
            return;
        }
        $this->bad_request_examples[] = $value;
        $this->badExampleInput = null;
    }

    public function removeBadExample(int $index): void
    {
        unset($this->bad_request_examples[$index]);
        $this->bad_request_examples = array_values($this->bad_request_examples);
    }

    public function saveDraft(): void
    {
        $profile = $this->getProfile();
        $skills = array_slice(
            array_filter(array_map('trim', explode(',', $this->skillsInput))), 0, 10
        );

        $profile->update([
            'member_profile_summary' => $this->member_profile_summary,
            'target_audience' => $this->buildTargetAudience(),
            'problems_helped' => $this->buildProblemsHelped(),
            'service_scope' => $this->service_scope,
            'skills' => $skills,
            'experience_context' => $this->experience_context,
            'help_types' => $this->help_types,
            'boundaries' => $this->boundaries,
            'preferred_contact_action' => $this->preferred_contact_action,
            'tone' => $this->tone,
            'good_request_examples' => $this->good_request_examples,
            'bad_request_examples' => $this->bad_request_examples,
            'wizard_state' => $this->wizardState,
            'last_saved_at' => now(),
        ]);

        $this->dispatch('profile-saved');
    }

    public function submitForValidation(): void
    {
        $this->validate($this->minimumValidationRules());

        $profile = $this->getProfile();
        $skills = array_slice(
            array_filter(array_map('trim', explode(',', $this->skillsInput))), 0, 10
        );

        $profile->update([
            'member_profile_summary' => $this->member_profile_summary,
            'target_audience' => $this->buildTargetAudience(),
            'problems_helped' => $this->buildProblemsHelped(),
            'service_scope' => $this->service_scope,
            'skills' => $skills,
            'experience_context' => $this->experience_context,
            'help_types' => $this->help_types,
            'boundaries' => $this->boundaries,
            'preferred_contact_action' => $this->preferred_contact_action,
            'tone' => $this->tone,
            'good_request_examples' => $this->good_request_examples,
            'bad_request_examples' => $this->bad_request_examples,
            'status' => MemberAiProfile::STATUS_PENDING_VALIDATION,
            'wizard_state' => $this->wizardState,
            'last_saved_at' => now(),
        ]);

        $this->goToStep(5);
        $this->dispatch('profile-ready-for-review');
    }

    /**
     * TASK-1366 — publier, et REPUBLIER apres un retrait volontaire.
     *
     * Deux choses ont change ici, et aucune n'est cosmetique.
     *
     * 1. `disabled_at` et `withdrawn_at` reviennent a `null`. Le chemin admin
     *    le faisait deja via `applyStatusDates()` ; celui-ci ne le faisait pas.
     *    Le defaut etait LATENT tant qu'aucun membre ne pouvait se retirer — il
     *    devient ATTEIGNABLE avec `unpublish()`. Sans cette remise a zero, un
     *    profil publie porterait une date de desactivation, et l'ecran
     *    d'administration afficherait « Desactive : <date> » sur un profil
     *    actif. Le matching n'en serait pas affecte (le scope filtre sur le
     *    statut) : c'est la piste d'audit qui se corromprait.
     *
     * 2. Un profil desactive par un ADMINISTRATEUR ne peut plus etre republie
     *    ici. C'etait possible AVANT cette TASK — la vue reaffichait le bouton
     *    des que le statut n'etait plus `published`, et cette methode ne
     *    verifiait rien : un membre pouvait donc annuler une sanction. On ne
     *    laisse pas ce comportement au moment precis ou l'on donne a la
     *    personne la main sur son propre consentement.
     */
    public function publish(): void
    {
        $profile = $this->getProfile();

        if ($profile->wasDisabledByAdmin()) {
            $this->addError('publish', __('member_ai_profile.publish_refused_admin_disabled'));

            return;
        }

        $this->validate($this->minimumValidationRules());

        $profile->update([
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'published_at' => now(),
            'validated_at' => now(),
            'disabled_at' => null,
            'withdrawn_at' => null,
            'last_saved_at' => now(),
        ]);

        $this->dispatch('profile-published');
    }

    /**
     * TASK-1366 — retirer SON profil, sans passer par un administrateur.
     *
     * Le formulaire exposait `publish()` et rien pour en sortir : on entrait
     * seul, on ne sortait pas seul. Sur une fonction qui porte du consentement,
     * « j'ai publie, je veux sortir » ne doit pas dependre d'un message a un
     * administrateur.
     *
     * ## Qui peut retirer quoi
     *
     * Ce composant est proprietaire PAR CONSTRUCTION : `mount()` resout le
     * profil par `forUser(auth()->user())->forOrganization(...)`, et **aucune
     * methode publique n'accepte d'identifiant**. Il n'existe donc aucun
     * parametre par lequel viser le profil d'autrui — ce n'est pas une garde
     * qu'on ajoute, c'est une forme qu'on preserve, et un test l'asserte.
     *
     * ## Rien n'est supprime
     *
     * Le profil garde son contenu : c'est un changement d'ETAT. La personne
     * peut republier ensuite, sans ressaisir quoi que ce soit.
     *
     * `STATUS_DISABLED` le sort immediatement de `scopePublished()`, donc de
     * l'ensemble eligible de People-1 et de toute carte deja affichee — les
     * cartes sont re-evaluees au rendu depuis T1360.
     */
    public function unpublish(): void
    {
        $profile = $this->profile;

        // Pas de profil, ou pas publie : il n'y a rien a retirer. On ne cree
        // surtout pas un brouillon pour le desactiver aussitot.
        if (! $profile instanceof MemberAiProfile || $profile->status !== MemberAiProfile::STATUS_PUBLISHED) {
            return;
        }

        $profile->update([
            'status' => MemberAiProfile::STATUS_DISABLED,
            'withdrawn_at' => now(),
            'disabled_at' => now(),
            'last_saved_at' => now(),
        ]);

        $this->dispatch('profile-withdrawn');
    }

    public function hasStepContent(int $stepNumber): bool
    {
        return match ($stepNumber) {
            1 => filled($this->member_profile_summary)
                && ($this->buildTargetAudience() !== [])
                && filled($this->problems_helped_raw),
            2 => filled($this->service_scope)
                && filled($this->skillsInput)
                && filled($this->experience_context)
                && ! empty($this->help_types),
            3 => ! empty($this->boundaries)
                && filled($this->preferred_contact_action)
                && filled($this->tone),
            4 => ! empty($this->good_request_examples),
            default => false,
        };
    }

    public function isAllStepsCompleted(): bool
    {
        return count($this->getCompletedSteps()) >= 4;
    }

    public function getCompletedSteps(): array
    {
        return $this->wizardState['completed'] ?? [];
    }

    public function getVisitedSteps(): array
    {
        return $this->wizardState['visited'] ?? [];
    }

    public function isStepCompleted(int $stepNumber): bool
    {
        return in_array($stepNumber, $this->getCompletedSteps());
    }

    public function getProgressPercent(): int
    {
        return min(100, (int) round((count($this->getCompletedSteps()) / 4) * 100));
    }

    public function stepValidationRules(): array
    {
        return match ($this->step) {
            1 => [
                'member_profile_summary' => 'nullable|string|max:500',
            ],
            2 => [
                'help_types' => 'nullable|array',
            ],
            3 => [
                'preferred_contact_action' => 'nullable|string|max:50',
                'tone' => 'nullable|string|in:'.implode(',', config('member_ai_profile.tones', [])),
            ],
            4 => [
                'good_request_examples' => 'nullable|array|max:3',
                'bad_request_examples' => 'nullable|array|max:3',
            ],
            default => [],
        };
    }

    public function minimumValidationRules(): array
    {
        return [
            'member_profile_summary' => 'required|string|max:500',
            'target_audience' => 'required|array|min:1',
            'problems_helped_raw' => 'required|string|max:1000',
            'service_scope' => 'required|string|max:500',
            'skillsInput' => 'required|string|max:500',
            'experience_context' => 'required|string|max:1000',
            'help_types' => 'required|array|min:1',
            'boundaries' => 'required|array|min:1',
            'preferred_contact_action' => 'required|string|max:50',
            'tone' => 'required|string|in:'.implode(',', config('member_ai_profile.tones', [])),
            'good_request_examples' => 'required|array|min:1',
        ];
    }

    public function render()
    {
        return view('livewire.member-ai-profile-wizard', [
            'tones' => __('member_ai_profile.tones'),
            'targetAudienceOptions' => __('member_ai_profile.target_audience_options'),
            'helpTypeOptions' => __('member_ai_profile.help_type_options'),
            'contactOptions' => __('member_ai_profile.contact_options'),
            'boundaryOptions' => __('member_ai_profile.boundary_options'),
            'steps' => [
                ['number' => 1, 'label' => 'Qui êtes-vous ?', 'subtitle' => 'Présentez-vous en quelques mots'],
                ['number' => 2, 'label' => 'Ce que vous apportez', 'subtitle' => 'Vos compétences et votre offre'],
                ['number' => 3, 'label' => 'Cadre et limites', 'subtitle' => 'Définissez votre périmètre'],
                ['number' => 4, 'label' => 'Exemples', 'subtitle' => 'Illustrez par des cas concrets'],
            ],
            'completedSteps' => $this->getCompletedSteps(),
            'visitedSteps' => $this->getVisitedSteps(),
            'progressPercent' => $this->getProgressPercent(),
        ]);
    }
}
