<?php

namespace App\Support\Ai;

use App\Ai\ProviderResolver;
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

    /**
     * TASK-1466 (CDC 21h-23h §2.3) — les routes ou la Boucle porte DEJA son
     * IA : le ChatLoop. Ce sont exactement les deux noms que `actionsFor()`
     * reconnait plus bas comme « page Boucle » — la meme liste, une seule
     * fois, pour que la surface qui propose les actions et celle qui decide
     * du montage ne puissent jamais diverger.
     */
    // TASK-1476 ajoute `organization.loops.catch-up` : « Rattrape-moi depuis… »
    // est une surface de Boucle, atteinte DEPUIS la Boucle, dont l'IA native est
    // a un clic. Y monter le Shell global rouvrirait la seconde porte que
    // TASK-1466 a fermee — sur une surface que PageContext ne sait meme pas
    // nommer. La liste reste EXACTE : on l'etend en nommant une route, jamais
    // par un `str_contains` sur « loops ».
    public const LOOP_SURFACE_ROUTES = ['loops.show', 'organization.loops.show', 'organization.loops.catch-up'];

    /**
     * Les surfaces de DECOUVERTE — ni FAB, ni Shell.
     *
     * TASK-1609. « A propos », « Mycelium » et « Logigramme » expliquent le
     * produit a quelqu'un qui ne le connait pas encore. Arbitrage de Cyril :
     * les EPURER. Une pastille « BouclePro IA » posee par-dessus une page dont
     * l'objet est justement d'expliquer ce qu'est BouclePro ajoute du bruit la
     * ou l'on cherche de la clarte — et le Mycelium PARLE de la gouvernance de
     * cette IA : l'y convoquer en meme temps brouille le propos.
     *
     * La garde vit ici, et non dans les gabarits, parce que `forRequest()` est
     * l'autorite unique : elle alimente le FAB **et** `shouldMountShell()`, qui
     * en depend. Un seul geste eteint donc les deux, et aucune vue ne peut les
     * faire diverger.
     */
    public const DISCOVERY_SURFACE_ROUTES = [
        'organization.about',
        'organization.mycelium',
        'organization.flowchart',
        'mycelium',
    ];

    /**
     * La MESSAGERIE — ni FAB, ni Shell.
     *
     * TASK-1693 bug AT, arbitrage de Cyril. On y ECRIT a quelqu'un : une
     * pastille d'assistant posee par-dessus une conversation privee s'y
     * invite. Meme raisonnement que les surfaces de decouverte (TASK-1609) et
     * que le ChatLoop (TASK-1466), ou la Boucle porte deja son IA.
     *
     * CET ARBITRAGE ANNULE LE BUG AR, qui avait remis l'assistant sur une
     * conversation d'ordinateur. Il est donc retire des DEUX tailles d'ecran,
     * et la consigne `hideOnMobile` du FAB devient sans objet ici — elle reste
     * en place, elle sert toujours si une autre surface en a besoin.
     *
     * La liste nomme des ROUTES, jamais un `str_contains` sur « messages » :
     * `loops.messages.store` et `admin.messages.*` en portent le mot sans etre
     * la Messagerie.
     *
     * @var list<string>
     */
    public const MESSAGING_SURFACE_ROUTES = [
        'messages.index',
        'messages.show',
        'messages.with',
        'organization.messages.index',
        'organization.messages.show',
        'organization.messages.with',
    ];

    /** Cle memo : id utilisateur -> contexte (une lecture par requete). */
    private array $memo = [];

    public function __construct(
        private readonly AiEconomicGuard $guard,
        private readonly LoopCardRegistry $cards,
        private readonly DossierSemanticSearchGate $dossierSearchGate,
        private readonly LoopLifecycleService $lifecycle,
        private readonly AiShellPageContext $pageContext,
        private readonly ProviderResolver $providers,
    ) {}

    /**
     * Le contexte injecte dans le composant, ou null quand le FAB ne se rend
     * pas (kill-switch plateforme, utilisateur sans Organization, ou IA non
     * disponible pour son Organization — TASK-1685).
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

        // TASK-1609 — surfaces de decouverte : aucun contexte, donc aucun FAB
        // et, par ricochet, aucun Shell (`shouldMountShell()` lit ce retour).
        // Place AVANT le memo : on ne veut meme pas construire le contexte.
        if ($this->isDiscoverySurface($request) || $this->isMessagingSurface($request)) {
            return null;
        }

        return $this->memo[$user->id] ??= $this->build($request, $user);
    }

    /**
     * Le FAB doit-il etre RENDU sur cette page ?
     *
     * TASK-1466 (CDC 21h-23h §2.3) — sur une Boucle, l'IA est DEJA la : le
     * ChatLoop, « Demander a l'IA », les Dossiers, la demande d'aide. Un FAB
     * flottant y ouvrirait une SECONDE porte vers les MEMES actions. Deux
     * portes pour une seule piece, c'est un doublon, pas une commodite.
     *
     * La distinction est deliberee : le CONTEXTE d'une Boucle reste calcule
     * (`forRequest()` — credit, gardes, actions), parce que le Shell et
     * `AiSelfKnowledge` s'en servent ailleurs. Ce qui s'arrete ici est le
     * RENDU d'une seconde surface, pas la connaissance de la page.
     */
    public function shouldRenderFab(Request $request, ?User $user): bool
    {
        return ! $this->isLoopSurface($request) && $this->forRequest($request, $user) !== null;
    }

    /**
     * La page en cours est-elle un ChatLoop ?
     *
     * Le nom de route seul, jamais l'URL : `loops.show` et
     * `organization.loops.show` sont les deux formes (non prefixee et
     * prefixee par l'Organization) de la MEME page.
     */
    public function isLoopSurface(Request $request): bool
    {
        return in_array((string) ($request->route()?->getName() ?? ''), self::LOOP_SURFACE_ROUTES, true);
    }

    /** TASK-1609 — « A propos », « Mycelium », « Logigramme » : pages epurees. */
    public function isDiscoverySurface(Request $request): bool
    {
        return in_array((string) ($request->route()?->getName() ?? ''), self::DISCOVERY_SURFACE_ROUTES, true);
    }

    /** La page en cours est-elle la Messagerie ? (TASK-1693 bug AT) */
    public function isMessagingSurface(Request $request): bool
    {
        return in_array((string) ($request->route()?->getName() ?? ''), self::MESSAGING_SURFACE_ROUTES, true);
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

        // TASK-1685 — l'IA est-elle disponible pour CETTE Organization ?
        //
        // LE DEFAUT CORRIGE. Mesure de recette : sur une Organization dont le
        // SuperAdmin avait DECOCHE « Activer les fonctions IA », `/membres`
        // rendait 200 avec le Shell « BouclePro IA » monte. Le reglage etait
        // fidelement persiste (`organization_ai_settings.is_enabled = false`) et
        // la garde serveur refusait bien — `ProviderResolver` leve une
        // DomainException AVANT tout appel, credential complet ou non. Ce
        // fichier, lui, ne lisait la configuration du tenant NULLE PART : la
        // racine etait une ABSENCE, pas une condition fausse.
        //
        // Le produit proposait donc un geste que le serveur allait refuser.
        // Aucune capacite ne fuyait ; c'est une poignee posee sur une porte
        // muree, et une poignee qui ne mene nulle part est un defaut a part
        // entiere.
        //
        // POURQUOI ICI. `forRequest()` est l'autorite UNIQUE : elle alimente le
        // FAB (`shouldRenderFab()`) **et** le montage du Shell
        // (`shouldMountShell()`). Un seul geste eteint donc les deux, et aucune
        // vue ne peut les faire diverger — la meme raison qui a fait vivre la
        // garde des surfaces de decouverte ici plutot que dans les gabarits.
        //
        // POURQUOI `isAvailableFor()` ET PAS `is_enabled`. Le drapeau seul
        // laisserait visible le Shell d'une Organization activee mais sans
        // credential : le premier geste echouerait, soit le meme defaut deplace
        // d'un cran. `isAvailableFor()` repond « `resolve()` aboutirait-il ? »
        // en lisant la MEME liste de conditions que la garde qui refuse — pas
        // une copie. Aucun second drapeau, aucune migration : `is_enabled` reste
        // le mecanisme canonique pose par TASK-1212.
        //
        // CE QUE CETTE GARDE NE FAIT PAS. Elle ne lit aucune sante de
        // fournisseur : une panne, un 429 ou un timeout ne masquent rien (voir
        // `ProviderResolver::isAvailableFor()`). Elle ne touche aucune surface
        // non-IA : les Dossiers, le depot de fichiers et la navigation vivent
        // hors de ce contexte et restent INCHANGES. Et elle ne lit pas le credit
        // utilisateur, qui a son autorite propre et refuse en se nommant.
        if (! $this->providers->isAvailableFor((string) $organization->id)) {
            return null;
        }

        // TASK-1693 bug AV — l'assistant eteint pour cette Organization ne
        // rend NI panneau NI bouton.
        //
        // MON DEFAUT AU BUG AU. J'avais compose le drapeau dans
        // `shell_enabled`, qui ne gouverne que le MONTAGE du panneau : le
        // bouton flottant continuait de paraitre et basculait simplement en
        // mode « panel ». Recette de Cyril : sur `/org/ofsh/explorer`, case
        // decochee, la pastille « BouclePro IA » etait toujours la.
        //
        // Et mon test ne l'a pas vu parce qu'il mesurait l'ABSENCE DU PANNEAU
        // (`data-ai-shell`), jamais celle du bouton. Deux objets, une seule
        // mesure.
        //
        // La garde vit donc ICI, a cote de celle de TASK-1685 : `forRequest()`
        // rend `null`, et `shouldRenderFab()` comme `shouldMountShell()`
        // tombent ensemble.
        if (! ($organization->ai_shell_enabled ?? true)) {
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
            // L'interrupteur PLATEFORME, et lui seul : il choisit entre le
            // Shell et l'ancien panneau, il ne retire pas le bouton.
            // Le reglage par ORGANIZATION (bug AU) agit plus haut, en
            // rendant `null` — il retire les DEUX.
            'shell_enabled' => (bool) config('ai.shell.enabled', true),
        ];
    }

    /**
     * Le Shell doit-il etre MONTE sur cette page ?
     *
     * TASK-1315 — la question n'est pas cosmetique, et la reponse ne peut pas
     * vivre dans le composant : monter un composant Livewire suffit a inscrire
     * dans la page son instantane, dont `memo.path` — c'est-a-dire l'URL
     * COURANTE. Sur une page de refus (`/dossiers/{id}` sans le droit de voir
     * ce Dossier), cette URL porte l'identifiant refuse. Le composant peut bien
     * ne rien nommer — TASK-1145 exige que la page de refus ne divulgue RIEN de
     * l'objet, pas meme son identifiant, et Livewire l'y remettrait.
     *
     * D'ou la regle, qui vaut aussi comme regle produit : **le Shell n'est pas
     * offert sur une page dont l'objet a ete refuse a l'utilisateur.** Le
     * contexte le sait deja (`page_context.refused`) ; il suffit de ne pas
     * monter.
     *
     * TASK-1466 : seconde regle produit, meme mecanique et meme frontiere —
     * **le Shell global ne se monte pas sur un ChatLoop**. La Boucle possede
     * son IA native ; le Shell global y serait la meme piece par une autre
     * porte. Comme pour le FAB, c'est le RENDU qui s'arrete : le contexte de
     * la page continue d'exister pour ceux qui le lisent ailleurs.
     */
    public function shouldMountShell(Request $request, ?User $user): bool
    {
        if ($this->isLoopSurface($request)) {
            return false;
        }

        $fab = $this->forRequest($request, $user);

        if ($fab === null || ! ($fab['shell_enabled'] ?? false)) {
            return false;
        }

        return ! ($fab['page_context']['refused'] ?? false);
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
        if (in_array($routeName, self::LOOP_SURFACE_ROUTES, true)) {
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
    /**
     * TASK-1363 : rendue publique pour la MEME raison que `loopActions()` en
     * T1315 — le Shell doit proposer l'action du Dossier calculee par le MEME
     * code que le FAB, sous les MEMES gardes. Sa logique n'a pas bouge : gate
     * de recherche semantique activee pour l'Organization du Dossier, ET
     * `$user->cannot('view', $dossier)`. Un second calcul, meme fidele au
     * depart, aurait fini par diverger.
     */
    public function dossierActions(Dossier $dossier, User $user): array
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
