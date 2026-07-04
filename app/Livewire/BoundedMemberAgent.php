<?php

namespace App\Livewire;

use App\Models\AdminAiInteraction;
use App\Models\MemberAiProfile;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]

class BoundedMemberAgent extends Component
{
    public User $targetUser;

    public ?MemberAiProfile $profile = null;

    public string $question = '';

    public string $response = '';

    public ?string $error = null;

    public function mount(User $user): void
    {
        $this->targetUser = $user;

        $organization = currentOrganization()
            ?? $user?->organization
            ?? DefaultOrganizationResolver::resolve();

        if (! $organization) {
            abort(404);
        }

        $this->profile = MemberAiProfile::where('user_id', $user->id)
            ->where('status', MemberAiProfile::STATUS_PUBLISHED)
            ->first();

        if (! $this->profile) {
            $this->error = "Ce membre n'a pas encore publié son profil IA.";
        }
    }

    public function askQuestion(): void
    {
        $this->response = '';
        $this->error = null;

        if (! $this->profile) {
            $this->error = "Ce membre n'a pas encore publié son profil IA.";

            return;
        }

        if (trim($this->question) === '') {
            $this->error = 'Veuillez poser une question.';

            return;
        }

        $question = $this->normalize($this->question);

        $result = $this->matchQuestion($question);

        $response = $result['response'];
        $matchedFields = $result['fields'];

        $this->response = $response;

        $this->logInteraction($response, $matchedFields);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(
            ['é', 'è', 'ê', 'ë', 'à', 'â', 'ä', 'ù', 'û', 'ü', 'ô', 'ö', 'î', 'ï', 'ç'],
            ['e', 'e', 'e', 'e', 'a', 'a', 'a', 'u', 'u', 'u', 'o', 'o', 'i', 'i', 'c'],
            $text
        );

        return $text;
    }

    private function matchQuestion(string $question): array
    {
        $profile = $this->profile;

        $keywordMap = [
            'competence' => ['skills', 'experience_context'],
            'savoir' => ['skills', 'experience_context'],
            'skill' => ['skills', 'experience_context'],
            'aide' => ['help_types', 'service_scope'],
            'help' => ['help_types', 'service_scope'],
            'service' => ['help_types', 'service_scope'],
            'propose' => ['help_types', 'service_scope'],
            'limite' => ['boundaries'],
            'boundary' => ['boundaries'],
            'urgence' => ['boundaries'],
            'gratuit' => ['boundaries'],
            'contact' => ['preferred_contact_action'],
            'joindre' => ['preferred_contact_action'],
            'telephone' => ['preferred_contact_action'],
            'email' => ['preferred_contact_action'],
            'ton' => ['tone'],
            'tone' => ['tone'],
            'style' => ['tone'],
            'audience' => ['target_audience', 'problems_helped', 'member_profile_summary'],
            'client' => ['target_audience', 'problems_helped', 'member_profile_summary'],
            'cible' => ['target_audience', 'problems_helped', 'member_profile_summary'],
            'public' => ['target_audience', 'problems_helped', 'member_profile_summary'],
        ];

        $matchedFields = [];

        foreach ($keywordMap as $keyword => $fields) {
            if (str_contains($question, $keyword)) {
                $matchedFields = array_merge($matchedFields, $fields);
            }
        }

        $matchedFields = array_unique($matchedFields);

        if (empty($matchedFields)) {
            $response = 'Ceci dépasse mon périmètre de présentation. Je peux uniquement vous renseigner sur les informations que le membre a partagées dans son profil IA.';

            return ['response' => $response, 'fields' => []];
        }

        $parts = [];

        $fieldLabels = [
            'skills' => __('member_ai_profile.field_skills'),
            'experience_context' => __('member_ai_profile.field_experience'),
            'help_types' => __('member_ai_profile.field_help_types'),
            'service_scope' => __('member_ai_profile.field_service_scope'),
            'boundaries' => __('member_ai_profile.field_boundaries'),
            'preferred_contact_action' => __('member_ai_profile.field_preferred_contact'),
            'tone' => __('member_ai_profile.field_tone'),
            'target_audience' => __('member_ai_profile.field_target_audience'),
            'problems_helped' => __('member_ai_profile.field_problems_helped'),
            'member_profile_summary' => __('member_ai_profile.field_summary'),
        ];

        foreach ($matchedFields as $field) {
            $value = $profile->{$field} ?? null;
            if ($value === null || (is_array($value) && empty($value)) || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $label = $fieldLabels[$field] ?? $field;

            $lookup = function (string $langKey, string|array $v): string {
                $options = __("member_ai_profile.{$langKey}");
                if (is_array($v)) {
                    return implode(', ', array_map(fn ($k) => $options[$k] ?? $k, $v));
                }

                return $options[$v] ?? $v;
            };

            if (is_array($value)) {
                $formatted = match ($field) {
                    'help_types' => $lookup('help_type_options', $value),
                    'boundaries' => $lookup('boundary_options', $value),
                    'target_audience' => $lookup('target_audience_options', $value),
                    'preferred_contact_action' => $lookup('contact_options', $value),
                    default => implode(', ', $value),
                };
                if ($field === 'preferred_contact_action') {
                    $parts[] = "**{$label} :** {$formatted}";

                    continue;
                }
                $parts[] = "**{$label} :** {$formatted}";
            } elseif ($field === 'tone') {
                $formatted = $lookup('tones', $value);
                $parts[] = "**{$label} :** {$formatted}";
            } elseif ($field === 'preferred_contact_action') {
                $formatted = $lookup('contact_options', $value);
                $parts[] = "**{$label} :** {$formatted}";
            } else {
                $parts[] = "**{$label} :** {$value}";
            }
        }

        if (empty($parts)) {
            $response = "Je n'ai pas trouvé d'information correspondant à votre question dans le profil publié de ce membre.";

            return ['response' => $response, 'fields' => $matchedFields];
        }

        $response = implode("\n\n", $parts);

        return ['response' => $response, 'fields' => $matchedFields];
    }

    private function logInteraction(string $response, array $matchedFields): void
    {
        $organization = currentOrganization()
            ?? $this->targetUser?->organization
            ?? DefaultOrganizationResolver::resolve();

        AdminAiInteraction::create([
            'organization_id' => $organization?->id,
            'user_id' => auth()->id(),
            'scenario_id' => 'bounded_member_presentation',
            'provider' => 'rule_based',
            'status' => 'success',
            'input_excerpt' => Str::limit($this->question, 200),
            'input_length' => strlen($this->question),
            'result_summary' => Str::limit($response, 500),
            'result_payload' => [
                'member_profile_id' => $this->profile?->id,
                'member_user_id' => $this->targetUser->id,
                'matched_fields' => $matchedFields,
            ],
            'metadata' => ['scenario' => 'bounded_member_presentation'],
        ]);
    }

    public function render()
    {
        return view('livewire.bounded-member-agent');
    }
}
