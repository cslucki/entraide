<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent de la capability `clarify_help_request` (TASK-1210 / IA P3).
 *
 * Transforme une intention floue en demande exploitable, et propose une Boucle
 * parmi celles que le serveur lui a fournies.
 *
 * ## Sortie structuree native
 *
 * `HasStructuredOutput` est le mecanisme du SDK v0.7.2 : le schema est transmis
 * au provider, et la reponse revient dans `StructuredAgentResponse->structured`,
 * deja decodee. Aucun parser maison, aucun framework JSON — le SDK fait ce
 * travail, et son fake sait generer une reponse conforme au schema.
 *
 * ## `suggested_loop_id` n'engage a rien
 *
 * Le schema demande un identifiant, mais le modele peut en inventer un : c'est
 * une chaine comme une autre pour lui. La valeur n'a donc aucune autorite ici.
 * `ClarifyUserHelpRequestService` la confronte a la liste reellement fournie au
 * contexte, et la ramene a `null` si elle n'y figure pas. L'agent propose, le
 * serveur tranche.
 */
class HelpRequestClarifierAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly string $composedInstructions,
        private readonly ?int $maxTokens = null,
        private readonly ?float $temperature = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->composedInstructions;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Titre court et descriptif de la demande, 80 caracteres maximum.')
                ->required(),

            'clarified_request' => $schema->string()
                ->description('La demande reformulee clairement, en 2 a 3 phrases, a la premiere personne.')
                ->required(),

            'help_type' => $schema->string()
                ->enum(['service_offer', 'collaboration', 'information', 'support', 'other'])
                ->description("Nature de l'aide attendue.")
                ->required(),

            'suggested_loop_id' => $schema->string()
                ->description(
                    'Identifiant EXACT, recopie depuis la liste des Boucles autorisees fournie en contexte, '
                    .'de la Boucle la plus pertinente. Chaine vide si aucune ne convient : '
                    ."ne jamais inventer d'identifiant, ne jamais choisir une Boucle absente de la liste."
                )
                ->required(),

            'suggestion_reason' => $schema->string()
                ->description(
                    'Une phrase courte expliquant pourquoi cette Boucle convient. '
                    .'Chaine vide si aucune Boucle n\'est suggeree.'
                )
                ->required(),

            'questions_for_user' => $schema->array()
                ->items($schema->string())
                ->description('0 a 3 questions de clarification si la demande reste ambigue.')
                ->required(),

            'confidence' => $schema->number()
                ->description('Confiance dans la clarification, entre 0.0 et 1.0.')
                ->required(),

            'needs_human_review' => $schema->boolean()
                ->description('true si la demande est trop vague pour etre clarifiee automatiquement.')
                ->required(),
        ];
    }
}
