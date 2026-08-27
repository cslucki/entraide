<?php

namespace App\Services\Ai;

use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnLock;
use DomainException;
use Illuminate\Support\Str;

/**
 * TASK-1315 — UN tour de conversation du Shell « BouclePro IA ».
 *
 * ## Ce service n'est PAS un moteur de conversation
 *
 * Il n'appelle aucun provider, ne compose aucun prompt, ne resout aucun modele,
 * n'ecrit pas le ledger et ne declare aucune capability. Il DELEGUE a une
 * autorite qui existe deja et qui est deja en production :
 * `ClarifyUserHelpRequestService::clarifyForOrganization()` — le meme chemin
 * que `RequestController::formulate()`.
 *
 * Ce que cette delegation apporte, sans qu'on le reconstruise :
 *  - capability `CLARIFY_HELP_REQUEST`, scope `ORGANIZATION` deja autorise ;
 *  - contexte borne par `ContextBuilder`, doctrine d'Organization (T1227) ;
 *  - provider / modele / credential resolus par l'Organization (T1212) ;
 *  - `AiEconomicGuard::authorize()` AVANT tout appel, et le ledger
 *    `ai_provider_invocations` apres (T1220) ;
 *  - `suggestedLoop` REVALIDE contre les Boucles reellement offertes au
 *    contexte (T1210) — le Shell ne peut donc pas proposer une Boucle que
 *    l'utilisateur n'aurait pas ;
 *  - repli deterministe, sans appel, quand la clarification est desactivee, le
 *    tenant non configure, ou le verdict economique negatif.
 *
 * ## Ce qu'il ajoute, et rien d'autre
 *
 * La persistance du tour dans le fil du Shell, le verrou de course, et
 * l'idempotence par declencheur. Il ne publie RIEN : aucune Demande, aucun
 * message de Boucle, aucun Article. La validation humaine reste devant toute
 * publication durable.
 */
final class AiShellResponder
{
    /** Le tour a produit une reponse de l'IA. */
    public const STATUS_ANSWERED = 'answered';

    /** Le garde de securite de la clarification a refuse la demande. */
    public const STATUS_BLOCKED = 'blocked';

    /** Aucune IA n'a repondu (desactivee, tenant non configure, refus economique). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly ClarifyUserHelpRequestService $clarifier,
        private readonly AiShellThread $thread,
    ) {}

    /**
     * @param  array<string, mixed>  $pageContext  contexte de page DEJA resolu et
     *                                             deja autorise — il est trace,
     *                                             jamais utilise comme un droit.
     * @param  string|null  $conversationId  identifiant deja affiche par la
     *                                       surface, utilise SI le fil est vide :
     *                                       le premier tour ne doit pas changer
     *                                       de conversation sous les yeux de
     *                                       l'utilisateur.
     * @return array{trigger: AiShellMessage, answer: AiShellMessage}
     */
    public function respond(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        ?string $conversationId = null,
    ): array
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new DomainException('An empty prompt never reaches a provider.');
        }

        $prompt = Str::limit($prompt, (int) config('ai.shell.max_input_chars', 2000), '');

        return AiShellTurnLock::run($organization, $user, function () use ($organization, $user, $prompt, $pageContext, $conversationId) {
            // Le message humain est ecrit AVANT l'appel : meme si la generation
            // echoue, l'utilisateur retrouve ce qu'il a demande dans son fil.
            $trigger = $this->thread->appendUser($organization, $user, $prompt, [
                'page_context' => $this->traceable($pageContext),
            ], $conversationId);

            // Idempotence (T1311) : un tour deja repondu ne se rejoue pas. La
            // contrainte UNIQUE sur `reply_to_id` fait foi en base ; cette
            // lecture evite simplement l'appel provider avant de s'y heurter.
            $existing = $this->thread->answerFor($trigger);

            if ($existing instanceof AiShellMessage) {
                return ['trigger' => $trigger, 'answer' => $existing];
            }

            [$content, $metadata] = $this->generate($organization, $user, $prompt, $pageContext);

            $answer = $this->thread->appendAssistant($organization, $user, $content, $trigger, $metadata);

            return ['trigger' => $trigger, 'answer' => $answer];
        });
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function generate(Organization $organization, User $user, string $prompt, array $pageContext): array
    {
        try {
            $result = $this->clarifier->clarifyForOrganization($organization, $user, $this->situated($prompt, $pageContext));
        } catch (DomainException $exception) {
            report($exception);

            return [__('ai.shell_answer_unavailable'), [
                'status' => self::STATUS_UNAVAILABLE,
                'page_context' => $this->traceable($pageContext),
            ]];
        }

        if ($result->isBlocked()) {
            return [
                (string) ($result->fallback['reason'] ?? __('ai.shell_answer_blocked')),
                [
                    'status' => self::STATUS_BLOCKED,
                    'producer' => $result->producer,
                    'page_context' => $this->traceable($pageContext),
                ],
            ];
        }

        // Meme position que `RequestController::formulate()` : un repli
        // deterministe n'est pas une reponse de l'IA et ne se presente jamais
        // comme telle.
        if ($result->producer === 'deterministic_fallback') {
            return [__('ai.shell_answer_unavailable'), [
                'status' => self::STATUS_UNAVAILABLE,
                'producer' => $result->producer,
                'page_context' => $this->traceable($pageContext),
            ]];
        }

        $content = trim((string) ($result->need !== '' ? $result->need : $result->title));

        if ($content === '') {
            $content = __('ai.shell_answer_unavailable');
        }

        return [$content, [
            'status' => self::STATUS_ANSWERED,
            'producer' => $result->producer,
            'scenario' => $result->scenario,
            'confidence' => $result->confidence,
            'title' => $result->title,
            'context' => $result->context,
            'expected_help_type' => $result->expectedHelpType,
            'message_draft' => $result->messageDraft,
            // On ne garde que l'IDENTIFIANT : le libelle et l'URL sont
            // re-resolus a l'affichage, sous la garde de la page. Un fil relu
            // demain ne peut donc pas exposer une Boucle qu'on a quittee.
            'suggested_loop_id' => is_array($result->suggestedLoop) ? ($result->suggestedLoop['id'] ?? null) : null,
            'suggested_category' => $result->suggestedCategory,
            'page_context' => $this->traceable($pageContext),
        ]];
    }

    /**
     * La question, situee. Le contexte de page est un INDICE d'intention donne
     * au modele — jamais un droit : les seules donnees qui entrent dans le
     * prompt sont celles que `ContextBuilder` accepte de composer pour cet
     * utilisateur, et le nom d'un objet que l'utilisateur a deja sous les yeux.
     *
     * @param  array<string, mixed>  $pageContext
     */
    private function situated(string $prompt, array $pageContext): string
    {
        $object = $pageContext['object'] ?? null;

        if (! is_array($object) || ! isset($object['label'])) {
            return $prompt;
        }

        $where = match ($object['type'] ?? '') {
            'loop' => __('ai.shell_prompt_where_loop', ['name' => $object['label']]),
            'dossier' => __('ai.shell_prompt_where_dossier', ['name' => $object['label']]),
            'article' => __('ai.shell_prompt_where_article', ['name' => $object['label']]),
            default => null,
        };

        return $where === null ? $prompt : $where."\n".$prompt;
    }

    /**
     * Ce qu'on trace du contexte : de quoi relire un tour, jamais de quoi
     * reconstituer un droit.
     *
     * @param  array<string, mixed>  $pageContext
     * @return array<string, mixed>
     */
    private function traceable(array $pageContext): array
    {
        $object = $pageContext['object'] ?? null;

        return [
            'route' => (string) ($pageContext['route'] ?? ''),
            'kind' => (string) ($pageContext['kind'] ?? 'other'),
            'object_type' => is_array($object) ? ($object['type'] ?? null) : null,
            'object_id' => is_array($object) ? ($object['id'] ?? null) : null,
        ];
    }
}
