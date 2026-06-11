<?php

namespace App\Services\Ai\Scenarios;

use App\Services\Ai\Contracts\AiScenarioDefinition;

class ClarifyHelpRequestScenario implements AiScenarioDefinition
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Tu es un assistant d'aide à la formulation pour les administrateurs d'une plateforme collaborative française.

Tu reçois un message brut d'un membre qui cherche de l'aide. Ta mission est de transformer ce message en une demande claire, structurée et actionable pour l'administrateur.

Règles générales :
- Réponse exclusivement en français.
- Aucune donnée personnelle inventée.
- Reste factuel, ne juge pas la personne.
- Ne propose pas d'action légale ou médicale.
- Si le contenu est ambigu ou trop court, dis-le explicitement et propose des questions de clarification.

Instructions de sortie :
1. Réformule la demande en 2-3 phrases maximum.
2. Classifie le type d'aide demandé.
3. Suggère une catégorie de la plateforme (slug libre, pas forcément dans une liste fermée).
4. Suggère un nom de loop pertinent ou laisse vide si non déterminable.
5. Propose 0 à 3 questions de clarification pour aider le membre à préciser sa demande.
6. Rédige une version publiable (3-5 phrases) que l'administrateur peut poster ou envoyer.
7. Évalue ta confiance sur la qualité de la clarification.
8. Indique si une relecture humaine est nécessaire.
PROMPT;

    public function id(): string
    {
        return 'clarify_help_request';
    }

    public function name(): string
    {
        return 'Clarification de demande d\'aide';
    }

    public function description(): ?string
    {
        return 'Analyse une demande d\'aide vague et produit une version clarifiée avec classification et questions pour le membre.';
    }

    public function providerHint(): string
    {
        return '';
    }

    public function systemPrompt(): string
    {
        return self::SYSTEM_PROMPT;
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'title',
                'clarified_request',
                'help_type',
                'suggested_category',
                'suggested_loop',
                'questions_for_user',
                'publishable_draft',
                'confidence',
                'needs_human_review',
            ],
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Titre court et descriptif de la demande (max 80 caractères).',
                ],
                'clarified_request' => [
                    'type' => 'string',
                    'description' => 'Version reformulée claire de la demande en 2-3 phrases maximum.',
                ],
                'help_type' => [
                    'type' => 'string',
                    'enum' => ['service_offer', 'collaboration', 'information', 'support', 'other'],
                    'description' => 'Type d\'aide demandé par le membre.',
                ],
                'suggested_category' => [
                    'type' => 'string',
                    'description' => 'Slug de catégorie suggérée sur la plateforme (libre, pas d\'enum).',
                ],
                'suggested_loop' => [
                    'type' => 'string',
                    'description' => 'Nom de loop suggéré ou chaîne vide si non déterminable.',
                ],
                'questions_for_user' => [
                    'type' => 'array',
                    'description' => 'Questions de clarification à poser au membre (0-3 maximum).',
                    'items' => [
                        'type' => 'string',
                    ],
                    'maxItems' => 3,
                ],
                'publishable_draft' => [
                    'type' => 'string',
                    'description' => 'Version publiable reformulée en 3-5 phrases que l\'administrateur peut poster.',
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0.0,
                    'maximum' => 1.0,
                    'description' => 'Niveau de confiance entre 0.0 et 1.0 sur la qualité de la clarification.',
                ],
                'needs_human_review' => [
                    'type' => 'boolean',
                    'description' => 'true si le message est trop ambigu ou vague pour être clarifié automatiquement.',
                ],
            ],
        ];
    }
}
