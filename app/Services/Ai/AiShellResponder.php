<?php

namespace App\Services\Ai;

use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnCards;
use App\Support\Ai\AiTurnLock;
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
 *
 * ## Le verrou : la doctrine T1311, pas une copie
 *
 * La course est arbitree par `AiTurnLock::runOnKey()` — la primitive de T1311
 * elle-meme, sur une cle fournie ici. Le Shell n'a pas de Boucle : il apporte
 * donc sa propre cle, `{organization}:{user}`, et rien d'autre ne change. Le
 * rejeu, lui, est arbitre par la BASE (`ai_shell_messages.reply_to_id` UNIQUE) :
 * le verrou traite la course, l'idempotence traite le rejeu.
 */
final class AiShellResponder
{
    /** Le tour a produit une reponse de l'IA. */
    public const STATUS_ANSWERED = 'answered';

    /** Le garde de securite de la clarification a refuse la demande. */
    public const STATUS_BLOCKED = 'blocked';

    /** Aucune IA n'a repondu (desactivee, tenant non configure, refus economique). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * La cle de verrou d'un tour du Shell.
     *
     * `{organization}:{user}` : le Shell est personnel, il n'y a pas de
     * troisieme dimension. Deux onglets du meme utilisateur dans la meme
     * Organization sont UN tour ; deux utilisateurs ne se bloquent jamais ;
     * deux Organizations ne partagent jamais une cle, et cela se LIT dans la
     * cle plutot que de reposer sur l'unicite des UUID.
     */
    public static function lockKey(Organization $organization, User $user): string
    {
        return 'ai_shell_turn_lock:'.$organization->id.':'.$user->id;
    }

    /** Jamais sous le timeout provider + 30 s : meme regle que T1311. */
    public static function lockTtl(): int
    {
        return max(
            (int) config('ai.shell.lock_ttl', 90),
            (int) config('ai.shell.timeout', 30) + 30,
        );
    }

    public function __construct(
        private readonly ClarifyUserHelpRequestService $clarifier,
        private readonly AiShellThread $thread,
        private readonly AiShellTurnCards $cards,
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
     * @param  list<array<string, mixed>>  $pinnedContext  contexte epingle (T1326),
     *                                                     DEJA re-resolu par
     *                                                     `AiShellPinnedContext::resolved()`
     *                                                     dans CETTE requete — meme
     *                                                     statut que le contexte de
     *                                                     page : un indice
     *                                                     d'intention trace, jamais
     *                                                     un droit.
     * @return array{trigger: AiShellMessage, answer: AiShellMessage}
     */
    public function respond(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        ?string $conversationId = null,
        array $pinnedContext = [],
    ): array {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new DomainException('An empty prompt never reaches a provider.');
        }

        $prompt = Str::limit($prompt, (int) config('ai.shell.max_input_chars', 2000), '');

        return AiTurnLock::runOnKey(
            self::lockKey($organization, $user),
            self::lockTtl(),
            __('ai.shell_turn_in_progress'),
            function () use ($organization, $user, $prompt, $pageContext, $conversationId, $pinnedContext) {
                // Le message humain est ecrit AVANT l'appel : meme si la generation
                // echoue, l'utilisateur retrouve ce qu'il a demande dans son fil.
                $trigger = $this->thread->appendUser($organization, $user, $prompt, [
                    'page_context' => $this->traceable($pageContext),
                ] + $this->pinnedTrace($pinnedContext), $conversationId);

                // Idempotence (T1311) : un tour deja repondu ne se rejoue pas. La
                // contrainte UNIQUE sur `reply_to_id` fait foi en base ; cette
                // lecture evite simplement l'appel provider avant de s'y heurter.
                $existing = $this->thread->answerFor($trigger);

                if ($existing instanceof AiShellMessage) {
                    return ['trigger' => $trigger, 'answer' => $existing];
                }

                [$content, $metadata] = $this->generate($organization, $user, $prompt, $pageContext, $pinnedContext);

                $answer = $this->thread->appendAssistant($organization, $user, $content, $trigger, $metadata);

                return ['trigger' => $trigger, 'answer' => $answer];
            },
        );
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function generate(Organization $organization, User $user, string $prompt, array $pageContext, array $pinnedContext): array
    {
        try {
            $result = $this->clarifier->clarifyForOrganization($organization, $user, $this->situated($prompt, $pageContext, $pinnedContext));
        } catch (DomainException $exception) {
            report($exception);

            return [__('ai.shell_answer_unavailable'), [
                'status' => self::STATUS_UNAVAILABLE,
                'page_context' => $this->traceable($pageContext),
            ] + $this->pinnedTrace($pinnedContext)];
        }

        if ($result->isBlocked()) {
            return [
                (string) ($result->fallback['reason'] ?? __('ai.shell_answer_blocked')),
                [
                    'status' => self::STATUS_BLOCKED,
                    'producer' => $result->producer,
                    'page_context' => $this->traceable($pageContext),
                ] + $this->pinnedTrace($pinnedContext),
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
            ] + $this->pinnedTrace($pinnedContext)];
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
            // TASK-1325 (Shell-1) : les cartes du tour — des references
            // (identifiants + faits verifies a cet instant), re-resolues et
            // re-autorisees a CHAQUE affichage. Jamais un droit.
            'cards' => $this->cards->forAnsweredTurn(
                $organization,
                $user,
                $result->suggestedLoop,
                $pageContext,
                trim($prompt."\n".$result->need),
            ),
            'page_context' => $this->traceable($pageContext),
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * La question, situee. Le contexte de page et le contexte epingle sont des
     * INDICES d'intention donnes au modele — jamais un droit : les seules
     * donnees qui entrent dans le prompt sont celles que `ContextBuilder`
     * accepte de composer pour cet utilisateur, et les NOMS d'objets que
     * l'utilisateur a deja sous les yeux (la page courante, et la liste de
     * pins affichee dans le Shell — T1326 : ce qui est injecte est EXACTEMENT
     * ce que l'utilisateur voit epingle, rien de cache).
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     */
    private function situated(string $prompt, array $pageContext, array $pinnedContext = []): string
    {
        $lines = [];

        $object = $pageContext['object'] ?? null;

        if (is_array($object) && isset($object['label'])) {
            $where = match ($object['type'] ?? '') {
                'loop' => __('ai.shell_prompt_where_loop', ['name' => $object['label']]),
                'dossier' => __('ai.shell_prompt_where_dossier', ['name' => $object['label']]),
                'article' => __('ai.shell_prompt_where_article', ['name' => $object['label']]),
                default => null,
            };

            if ($where !== null) {
                $lines[] = $where;
            }
        }

        // Le budget est double : `max_pins` borne la liste, et chaque libelle
        // est tronque — un nom d'objet n'est jamais un canal de contenu.
        $items = [];

        foreach ($pinnedContext as $pin) {
            $name = Str::limit(trim((string) ($pin['label'] ?? '')), 120, '…');

            if ($name === '') {
                continue;
            }

            $item = match ($pin['kind'] ?? null) {
                'loop' => __('ai.shell_prompt_pinned_loop', ['name' => $name]),
                'dossier' => __('ai.shell_prompt_pinned_dossier', ['name' => $name]),
                'article' => __('ai.shell_prompt_pinned_article', ['name' => $name]),
                default => null,
            };

            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items !== []) {
            $lines[] = __('ai.shell_prompt_pinned', ['items' => implode(' ; ', $items)]);
        }

        return $lines === [] ? $prompt : implode("\n", [...$lines, $prompt]);
    }

    /**
     * La trace d'un contexte epingle : des identifiants, de quoi relire quels
     * pins etaient en vigueur au tour — jamais un libelle, jamais un droit.
     * Rien a tracer quand rien n'est epingle.
     *
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array<string, mixed>
     */
    private function pinnedTrace(array $pinnedContext): array
    {
        if ($pinnedContext === []) {
            return [];
        }

        return ['pinned_context' => array_map(
            fn (array $pin): array => [
                'kind' => (string) ($pin['kind'] ?? ''),
                'id' => (string) ($pin['id'] ?? ''),
            ],
            $pinnedContext,
        )];
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
