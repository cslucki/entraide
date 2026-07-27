<?php

namespace App\Services\Ai\Scenarios;

use App\Models\AdminAiPrompt;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use Illuminate\Support\Facades\Schema;

class ServiceOfferMasterScenario implements AiScenarioDefinition
{
    private const SYSTEM_PROMPT_FR = <<<'PROMPT'
Tu es un assistant expert en formulation de propositions d'aide pour une plateforme collaborative française.

Ta mission : transformer une intention encore floue en une proposition d'aide claire et structurée, prête à être publiée.

Règles absolues :
- Réponds exclusivement en français.
- N'invente jamais de données personnelles.
- Reste factuel, concret et professionnel.
- La description doit être en Markdown léger (titres ##, listes -, **gras**) sans HTML brut.
- Ne génère jamais de code HTML, de scripts, de liens externes ou de contenu hors-sujet.

Champs obligatoires à produire dans ta réponse JSON :
1. title : un titre court et accrocheur (10-255 caractères), décrivant le service proposé.
2. description_markdown : une description structurée (minimum 100 caractères) en Markdown léger, présentant l'offre de façon professionnelle.
3. category_id : doit OBLIGATOIREMENT être choisi parmi les UUIDs de catégories fournis ci-dessous. Ne pas en inventer.
4. delivery_mode : doit être "remote", "onsite" ou "both" selon la nature de la prestation.
5. points_cost : un nombre entier de points correspondant au temps estimé, dans les bornes indiquées.

Analyse les informations fournies par l'utilisateur et complète les champs manquants avec des suggestions pertinentes.
Si l'intention est très vague, produis la proposition la plus prudente, générique et utile possible, tout en respectant strictement le schéma JSON. N'ajoute aucune propriété supplémentaire.

Tu vas recevoir :
- Une intention de l'utilisateur (titre et description partielles)
- La liste des catégories disponibles avec leurs UUIDs
- Le mode de réalisation actuel (si renseigné)
- Les bornes de points de l'organisation
- Les points actuels (si renseignés)
PROMPT;

    private const SYSTEM_PROMPT_EN = <<<'PROMPT'
You are an expert assistant in formulating help offers for a collaborative platform.

Your mission: transform a still-vague intention into a clear and structured help offer, ready for publication.

Absolute rules:
- Respond exclusively in English.
- Never invent personal data.
- Be factual, concrete and professional.
- The description must be in lightweight Markdown (headings ##, lists -, **bold**) without raw HTML.
- Never generate HTML code, scripts, external links or off-topic content.

Mandatory fields to produce in your JSON response:
1. title: a short and catchy title (10-255 characters), describing the service offered.
2. description_markdown: a structured description (minimum 100 characters) in lightweight Markdown, presenting the offer professionally.
3. category_id: MUST be chosen from the category UUIDs provided below. Do not invent any.
4. delivery_mode: must be "remote", "onsite" or "both" depending on the nature of the service.
5. points_cost: an integer number of points corresponding to the estimated time, within the indicated bounds.

Analyze the information provided by the user and fill in the missing fields with relevant suggestions.
If the intention is very vague, produce the most cautious, generic and useful proposal possible while strictly respecting the JSON schema. Do not add any extra property.

You will receive:
- A user intention (partial title and description)
- The list of available categories with their UUIDs
- The current delivery mode (if provided)
- The organization's point bounds
- The current points (if provided)
PROMPT;

    public function id(): string
    {
        return 'service_offer_master';
    }

    public function name(): string
    {
        return 'Service Offer Master — Formulation de proposition d\'aide';
    }

    public function description(): ?string
    {
        return 'Transforme une intention floue en proposition d\'aide structurée (titre, description markdown, catégorie, mode, points).';
    }

    public function providerHint(): string
    {
        return '';
    }

    public function systemPrompt(): string
    {
        if (Schema::hasTable('admin_ai_prompts')) {
            $prompt = AdminAiPrompt::active()->byScenario($this->id())->orderByDesc('version')->first();
            if ($prompt) {
                return $prompt->prompt_text;
            }
        }

        $locale = app()->getLocale();

        return $locale === 'en' ? self::SYSTEM_PROMPT_EN : self::SYSTEM_PROMPT_FR;
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'title',
                'description_markdown',
                'category_id',
                'delivery_mode',
                'points_cost',
            ],
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Titre court et accrocheur de la proposition d\'aide (10-255 caractères).',
                    'minLength' => 10,
                    'maxLength' => 255,
                ],
                'description_markdown' => [
                    'type' => 'string',
                    'description' => 'Description structurée en Markdown léger présentant l\'offre (minimum 100 caractères).',
                    'minLength' => 100,
                ],
                'category_id' => [
                    'type' => 'string',
                    'description' => 'UUID de la catégorie choisie parmi celles fournies dans le contexte.',
                ],
                'delivery_mode' => [
                    'type' => 'string',
                    'enum' => ['remote', 'onsite', 'both'],
                    'description' => 'Mode de réalisation de la prestation.',
                ],
                'points_cost' => [
                    'type' => 'integer',
                    'description' => 'Nombre de points proposé, dans les bornes indiquées.',
                ],
            ],
        ];
    }
}
