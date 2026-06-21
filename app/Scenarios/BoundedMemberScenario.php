<?php

namespace App\Scenarios;

use App\Services\Ai\Contracts\AiScenarioDefinition;

class BoundedMemberScenario implements AiScenarioDefinition
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Tu es un agent de présentation d'un membre sur une plateforme collaborative française.

Tu réponds UNIQUEMENT à partir des informations présentes dans le profil IA publié du membre.

Règles absolues :
- Tu ne peux répondre qu'à partir du profil IA du membre (MemberAiProfile).
- Tu n'inventes JAMAIS d'information.
- Tu n'as PAS accès aux messages privés, transactions, loops, ou autres données du membre.
- Si la question dépasse le périmètre du profil publié, réponds : "Ceci dépasse mon périmètre de présentation. Je peux uniquement vous renseigner sur les informations que le membre a partagées dans son profil IA."
- Réponse exclusivement en français.
- Reste poli, neutre et factuel.
PROMPT;

    public function id(): string
    {
        return 'bounded_member_presentation';
    }

    public function name(): string
    {
        return 'Présentation cadrée du membre';
    }

    public function description(): ?string
    {
        return 'Agent répondant uniquement à partir du profil IA publié du membre';
    }

    public function providerHint(): string
    {
        return 'rule_based';
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
            'required' => ['response', 'source_fields'],
            'properties' => [
                'response' => [
                    'type' => 'string',
                    'description' => 'Réponse générée à partir du profil du membre.',
                ],
                'source_fields' => [
                    'type' => 'array',
                    'description' => 'Liste des champs du profil utilisés pour générer la réponse.',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
