<?php

namespace Database\Factories;

use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberAiProfileFactory extends Factory
{
    protected $model = MemberAiProfile::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'status' => MemberAiProfile::STATUS_DRAFT,
            'locale' => 'fr',
            'member_profile_summary' => fake()->sentence(),
            'service_scope' => fake()->sentence(4),
            'experience_context' => fake()->paragraph(),
            'preferred_contact_action' => fake()->randomElement(['poser_question_loop', 'envoyer_demande_echange', 'envoyer_message']),
            'tone' => fake()->randomElement(['sobre', 'chaleureux', 'direct', 'pedagogique']),
            'target_audience' => fake()->randomElements(['entrepreneurs', 'independants', 'associations', 'tpe_pme'], 2),
            'problems_helped' => fake()->randomElements(['clarifier_offre', 'creer_identite_visuelle', 'corriger_texte', 'preparer_entretien'], 2),
            'skills' => fake()->words(3),
            'help_types' => fake()->randomElements(['avis_rapide', 'repondre_question', 'relire_document', 'partager_methode'], 2),
            'boundaries' => fake()->randomElements(['pas_urgence', 'pas_travail_gratuit', 'pas_hors_domaine'], 2),
            'good_request_examples' => [fake()->sentence()],
            'bad_request_examples' => [fake()->sentence()],
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'validated_at' => now(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MemberAiProfile::STATUS_DRAFT,
            'validated_at' => null,
        ]);
    }
}
