<?php

namespace App\Support\Ai;

use App\Models\AiConfig;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Services\Loops\LoopLifecycleService;
use App\Support\Loops\LoopCardRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1231 — contexte du FAB « BouclePro IA » : ce que le bouton flottant
 * PROPOSE sur la page courante, et le credit IA de l'utilisateur.
 *
 * Calcule cote serveur, injecte dans le composant Blade `<x-ai-fab />` du
 * layout membre (`layouts.app`), jamais devine en lisant le DOM. Le FAB
 * n'appelle JAMAIS un provider et ne compose aucun prompt : chaque action
 * ouvre une surface qui existe deja (evenement window) ou suit un lien —
 * les endpoints qu'elle atteint passent deja par `CapabilityRegistry` et
 * `AiEconomicGuard`. « Demander a l'IA » (loop_ask, ChatLoopAiService::ask,
 * migre dans le systeme nerveux canonique par TASK-1233) est expose depuis
 * TASK-1237 — au meme titre que les autres actions, avec les memes gardes
 * que le bouton historique de loop-chat.blade.php.
 *
 * Regle : le FAB ne propose jamais une capability sans page qui la porte,
 * et il reprend EXACTEMENT les gardes des boutons existants de la page
 * (loop-chat, header-actions, Drive) — aucune seconde autorite.
 *
 * Credit : `AiEconomicGuard::userCreditStatus()` uniquement, l'autorite qui
 * bloque ; UNE lecture par requete HTTP (binding `scoped`, memo interne),
 * jamais de cache applicatif. Il ne montre ni budget Organization ni cout.
 */
final class AiFabContext
{
    public const ACTION_LOOP_ASK = 'loop_ask';

    public const ACTION_LOOP_KNOWLEDGE = 'loop_knowledge';

    public const ACTION_LOOP_SUMMARY = 'loop_summary';

    public const ACTION_HELP_REQUEST = 'help_request';

    public const ACTION_DOSSIER_SEARCH = 'dossier_search';

    /** Cle memo : id utilisateur -> contexte (une lecture par requete). */
    private array $memo = [];

    public function __construct(
        private readonly AiEconomicGuard $guard,
        private readonly LoopCardRegistry $cards,
        private readonly DossierSemanticSearchGate $dossierSearchGate,
        private readonly LoopLifecycleService $lifecycle,
        private readonly AiShellPageContext $pageContext,
    ) {}

    /**
     * Le contexte injecte dans le composant, ou null quand le FAB ne se rend
     * pas (kill-switch, utilisateur sans Organization).
     *
     * @return array{
     *     credit: array<string, mixed>,
     *     credit_label: string,
     *     credit_tone: string,
     *     refusal_message: ?string,
     *     offers_url: ?string,
     *     usage_url: string,
     *     actions: list<array<string, mixed>>,
     *     page: string,
     *     page_context: array<string, mixed>,
     *     shell_enabled: bool
     * }|null
     */
    public function forRequest(Request $request, ?User $user): ?array
    {
        if ($user === null || ! config('ai.fab.enabled', true)) {
            return null;
        }

        return $this->memo[$user->id] ??= $this->build($request, $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build(Request $request, User $user): ?array
    {
        // Meme regle que « Mes usages IA » (UserAiUsageController) : sur la
        // route non prefixee, l'Organization courante est celle par defaut de
        // la plateforme, pas la sienne — le credit doit rester le sien.
        $organization = $user->organization ?? currentOrganization();

        if (! $organization instanceof Organization) {
            return null;
        }

        $credit = $this->guard->userCreditStatus($organization, $user);
        $routeName = (string) ($request->route()?->getName() ?? '');

        [$page, $actions] = $this->actionsFor($request, $routeName, $user);

        $refusal = null;
        if ($credit->isExhausted()) {
            $refusal = trans_choice('ai.credit_refusal_user_exhausted', (int) $credit->quota(), [
                'used' => $credit->used,
                'quota' => (int) $credit->quota(),
                'date' => $credit->renewsAt->format('d/m/Y'),
            ]);
        }

        return [
            'credit' => $credit->toArray(),
            'credit_label' => $this->creditLabel($credit),
            'credit_tone' => $credit->isExhausted() ? 'exhausted' : ($credit->isAlerting() ? 'alert' : 'ok'),
            'refusal_message' => $refusal,
            // « Voir les offres » : seulement au plafond ET si la plateforme le propose.
            'offers_url' => $credit->isExhausted() && $credit->policy->offerSubscription ? aiOffersUrl($organization) : null,
            'usage_url' => $this->usageUrl($request),
            'actions' => $actions,
            'page' => $page,
            // TASK-1315 : OU se trouve l'utilisateur, decrit par la seule
            // autorite qui rejoue les gardes des pages. Cette entree ne donne
            // aucun droit : elle nomme ce que la page montre deja.
            'page_context' => $this->pageContext->forRequest($request, $user, $organization),
            'shell_enabled' => (bool) config('ai.shell.enabled', true),
        ];
    }

    private function creditLabel(AiUserCreditStatus $credit): string
    {
        if ($credit->isUnlimited()) {
            return __('ai.fab_credit_included');
        }

        return trans_choice('ai.credit_remaining', (int) $credit->remaining());
    }

    /**
     * Les actions de la page courante — calculees avec les MEMES gardes que
     * les boutons qu'elles declenchent.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function actionsFor(Request $request, string $routeName, User $user): array
    {
        if (in_array($routeName, ['loops.show', 'organization.loops.show'], true)) {
            $loop = $request->route('loop');

            if ($loop instanceof Loop) {
                return ['loop', $this->loopActions($loop, $user)];
            }
        }

        if (in_array($routeName, ['dossiers.show', 'organization.dossiers.show'], true)) {
            $dossier = $request->route('dossier');
            $dossier = $dossier instanceof Dossier ? $dossier : Dossier::query()->find($dossier);

            if ($dossier instanceof Dossier) {
                return ['dossier', $this->dossierActions($dossier, $user)];
            }
        }

        return ['other', []];
    }

    /**
     * Page Boucle : les gardes sont celles de loop-chat (« Demander a l'IA »,
     * « Consulter les Dossiers », « Qui peut m'aider ») et de header-actions
     * (Resume) — un membre actif, une Boucle ouverte, ChatLoop actif.
     *
     * TASK-1315 : rendue publique pour que le Shell propose la MEME action que
     * le FAB, calculee par le MEME code. Un second calcul, meme fidele au
     * depart, aurait fini par diverger — c'est exactement ce que la regle
     * « jamais de seconde autorite » interdit.
     *
     * @return list<array<string, mixed>>
     */
    public function loopActions(Loop $loop, User $user): array
    {
        $isMember = LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        $canContribute = $isMember && ! $user->isDeactivated() && $this->lifecycle->isWritable($loop);

        if (! $canContribute || ! config('ai.chatloop.enabled', true)) {
            return [];
        }

        // « Demander a l'IA » (loop_ask, TASK-1233 : capability canonique) :
        // meme garde que ci-dessus, exactement celle du bouton historique
        // (LoopChat::canContribute()). Le FAB ne fait qu'ouvrir le MEME
        // formulaire (evenement `bp-open-ask-ai` ecoute dans loop-chat.blade.php,
        // qui poste vers la route `loops.ai` deja canonique) — aucune capability,
        // aucun credit, aucun controle nouveau.
        $actions = [[
            'key' => self::ACTION_LOOP_ASK,
            'label' => __('ai.fab_action_loop_ask'),
            'hint' => __('ai.fab_action_loop_ask_hint'),
            'kind' => 'event',
            'event' => 'bp-open-ask-ai',
            'detail' => null,
        ], [
            'key' => self::ACTION_LOOP_KNOWLEDGE,
            'label' => __('ai.fab_action_loop_knowledge'),
            'hint' => __('ai.fab_action_loop_knowledge_hint'),
            'kind' => 'event',
            'event' => 'bp-open-knowledge',
            'detail' => null,
        ]];

        // Le Resume : seulement si la Card est reellement placee dans cette
        // Boucle pour cet utilisateur (meme source que header-actions).
        $summaryPlaced = $this->cards->chatActionCardsFor($loop, $user)->contains('key', 'core.ai_summary');
        if ($summaryPlaced) {
            $actions[] = [
                'key' => self::ACTION_LOOP_SUMMARY,
                'label' => __('ai.fab_action_loop_summary'),
                'hint' => __('ai.fab_action_loop_summary_hint'),
                'kind' => 'event',
                'event' => 'bp-open-loop-card',
                'detail' => ['card' => 'core.ai_summary'],
            ];
        }

        if ((bool) AiConfig::get('clarification_enabled', false)) {
            $actions[] = [
                'key' => self::ACTION_HELP_REQUEST,
                'label' => __('ai.fab_action_help_request'),
                'hint' => __('ai.fab_action_help_request_hint'),
                'kind' => 'event',
                'event' => 'bp-open-help-request',
                'detail' => null,
            ];
        }

        return $actions;
    }

    /**
     * Page Dossier : la recherche semantique n'est proposee que si le pilote
     * est ouvert a cette Organization (meme gate que la vue Documents) et si
     * l'utilisateur peut voir ce Dossier.
     *
     * @return list<array<string, mixed>>
     */
    private function dossierActions(Dossier $dossier, User $user): array
    {
        if (! $this->dossierSearchGate->isEnabledFor((string) $dossier->organization_id) || $user->cannot('view', $dossier)) {
            return [];
        }

        return [[
            'key' => self::ACTION_DOSSIER_SEARCH,
            'label' => __('ai.fab_action_dossier_search'),
            'hint' => __('ai.fab_action_dossier_search_hint'),
            'kind' => 'event',
            'event' => 'bp-open-dossier-search',
            'detail' => null,
        ]];
    }

    private function usageUrl(Request $request): string
    {
        $slug = $request->route('organization');
        if ($slug instanceof Organization) {
            $slug = $slug->slug;
        }

        if ($slug && Route::has('organization.profile.ai-usage')) {
            return route('organization.profile.ai-usage', ['organization' => $slug]);
        }

        return route('profile.ai-usage');
    }
}
