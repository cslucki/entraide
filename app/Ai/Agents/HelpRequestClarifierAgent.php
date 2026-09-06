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
            // TASK-1350 : le premier verdict, AVANT toute redaction. Le modele
            // decide d'abord si un autre membre a quelque chose a apporter ;
            // il ne redige un brouillon que si c'est le cas. Ce booleen n'a
            // d'AUTORITE que sous un prompt `clarify_help_request` en version
            // 3 ou plus : sous v1/v2, le service l'ignore integralement
            // (fail-open vers le comportement historique).
            'interaction_fit' => $schema->boolean()
                ->description(
                    'true si un autre membre pourrait utilement contribuer a cet enonce : '
                    ."demande d'aide, offre de service ou de competence, information, soutien, "
                    .'collaboration. En cas de doute, repondre true et poser des questions dans '
                    .'questions_for_user. false UNIQUEMENT quand l\'enonce est clairement hors '
                    .'Interaction : remerciement, salutation, bavardage, question sur la plateforme '
                    .'elle-meme, ou propos sans destinataire humain possible.'
                )
                ->required(),

            // TASK-1350 (direct_reply V1) — la parole de BouclePro IA quand
            // l'enonce n'appelle PAS d'Interaction collective.
            //
            // C'est le champ qui manquait : sans lui, le Shell n'avait que deux
            // sorties, un brouillon de demande ou un message canonique fige. Il
            // ne repondait donc jamais. Ce champ ne sert QUE lorsque
            // `interaction_fit` est false ; ailleurs il reste vide, et le
            // pipeline demande/offre est inchange.
            'direct_reply' => $schema->string()
                ->description(
                    'Reponse conversationnelle courte au MESSAGE ACTUEL du membre, 1 a 4 phrases. '
                    .'A remplir UNIQUEMENT quand interaction_fit vaut false ; chaine vide sinon. '
                    .'Tu peux repondre simplement, guider, demander de reformuler, dire que tu ne sais pas, '
                    ."expliquer une limite, ou rappeler que l'humain valide avant toute publication. "
                    ."N'invente JAMAIS une donnee temps reel (meteo, actualite, cours), un outil dont tu ne "
                    ."disposes pas, une information sur BouclePro qui ne t'a pas ete fournie, une permission, "
                    ."un droit, ou une source documentaire. Tu ne publies rien et n'agis jamais. "
                    .'Reponds au message actuel, jamais a un tour precedent du transcript.'
                )
                ->required(),

            'title' => $schema->string()
                ->description('Titre court et descriptif de la demande, 80 caracteres maximum. Chaine vide quand interaction_fit vaut false.')
                ->required(),

            'clarified_request' => $schema->string()
                ->description('La demande reformulee clairement, en 2 a 3 phrases, a la premiere personne.')
                ->required(),

            'help_type' => $schema->string()
                ->enum(['service_offer', 'collaboration', 'information', 'support', 'other'])
                ->description("Nature de l'aide attendue.")
                ->required(),

            'suggested_category_id' => $schema->string()
                ->description(
                    'Identifiant EXACT, recopie depuis la liste des categories autorisees fournie en contexte. '
                    .'Chaine vide si aucune categorie ne correspond clairement. Ne jamais inventer un identifiant.'
                )
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
