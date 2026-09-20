<?php

namespace App\Support\Flowchart;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\VisibleLoops;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * TASK-1608 — le modele de graphe servi a Cytoscape.
 *
 * ## Cette classe ne decide AUCUNE permission
 *
 * `App\Support\Loops\VisibleLoops` (TASK-1364) reste l'autorite UNIQUE des
 * Boucles qu'une personne peut voir, et `LoopPolicy` reste la seule autorite
 * de ce qu'elle peut y faire. Rien n'est recopie ici. Ce fichier consomme
 * l'autorite, puis applique une **projection de presentation** qui ne peut que
 * RETRANCHER — jamais ajouter une Boucle que l'autorite n'a pas rendue.
 *
 * ## La projection, et la decision produit qui la motive
 *
 * Arbitrage MASTER du 2026-09-20 :
 *
 * | access_mode | is_member | flowchart |
 * |---|---|---|
 * | `open`       | —     | visible |
 * | `request`    | —     | visible (y compris demande en attente) |
 * | `invitation` | true  | visible — cette Boucle fait partie de son espace |
 * | `invitation` | false | ABSENTE |
 *
 * Le catalogue `/org/{org}/loops` nomme, lui, toute Boucle active du tenant :
 * « privee » ne veut pas dire « cachee » (TASK-1075). Le flowchart est une
 * carte des POSSIBILITES REELLES : une Boucle qu'on ne peut ni rejoindre ni
 * demander n'en est pas une. C'est une soustraction assumee, et la seule.
 *
 * ## Le piege que `$aRetrancher()` evite, et qui a ete mesure
 *
 * Le predicat lit `Loop::access_mode`, **jamais** `accessStateFor()`.
 *
 * `VisibleLoops::accessStateFor()` rend `ACCESS_INVITATION` PAR DEFAUT, des
 * que ni `join` ni `requestToJoin` n'autorisent. Or `LoopPolicy::join` refuse
 * aussi quand la personne est **deja membre actif**
 * ({@see \App\Policies\LoopPolicy::join()}). Une Boucle `open` dont on est
 * membre rend donc l'etat `invitation`.
 *
 * Filtrer sur cet etat aurait masque TOUTES les Boucles de la personne —
 * l'exact contraire de la decision. L'etat reste l'autorite de l'ETIQUETTE
 * (il interroge les Policies) ; `access_mode` est l'autorite du RETRAIT.
 *
 * ## Un invite ne recoit aucune Boucle
 *
 * `VisibleLoops::query()` exige un `User`, et aucune surface du produit ne
 * nomme une Boucle a un visiteur anonyme. Aucune primitive invite n'est
 * inventee ici : sans utilisateur du tenant visite, la branche est vide et le
 * graphe se limite a sa partie structurelle.
 *
 * ## Les couleurs ne sont pas ici
 *
 * Le payload ne porte aucune couleur. Cytoscape lit les jetons `--bp-*` poses
 * par `<x-theme-tokens>` au moment du rendu : la charte suit l'Organization
 * sans qu'un seul slug soit ecrit en dur.
 */
final class FlowchartGraph
{
    /** Le point d'entree du graphe. */
    public const NODE_ROOT = 'root';

    /** Les quatre portes du premier niveau. */
    public const INTENTS = ['need_help', 'offer_help', 'explore_idea', 'connect'];

    /**
     * Le mecanisme BouclePro, dans l'ordre. Une chaine PARTAGEE par les quatre
     * intentions : c'est ce que le produit fait reellement, et le dire quatre
     * fois n'en ferait pas quatre mecanismes.
     */
    public const STEPS = [
        'clarify',
        'match',
        'exchange',
        'ai',
        'resources',
        'dossiers',
        'synthesis',
        'decision',
    ];

    /** Ce a quoi la chaine aboutit (§5 du mandat). */
    public const OUTCOMES = ['entraide', 'relation', 'learning', 'coordination', 'action', 'memory'];

    /** Le noeud qui porte les Boucles reelles — §11 : aucune intention n'y est reliee. */
    public const NODE_LOOPS = 'loops';

    /** Un libelle ecrit par un membre est cite, jamais laisse libre (meme borne que T1359/T1364). */
    private const MAX_LABEL_CHARS = 120;

    /** Une accroche de noeud reste une accroche : le detail va au panneau lateral. */
    private const MAX_TAGLINE_CHARS = 140;

    public function __construct(private readonly VisibleLoops $visibleLoops) {}

    /**
     * @return array{
     *     organization: array<string, mixed>,
     *     nodes: list<array{data: array<string, mixed>}>,
     *     edges: list<array{data: array<string, mixed>}>,
     *     has_loops: bool,
     *     is_guest: bool
     * }
     */
    public function build(Organization $organization, ?User $user): array
    {
        $nodes = [];
        $edges = [];

        $nodes[] = $this->node(self::NODE_ROOT, 'root', __('flowchart.root'));

        foreach (self::INTENTS as $intent) {
            $id = 'intent:'.$intent;
            $nodes[] = $this->node($id, 'intent', __('flowchart.intent_'.$intent), [
                'hint' => __('flowchart.intent_'.$intent.'_hint'),
            ]);
            $edges[] = $this->edge(self::NODE_ROOT, $id);
        }

        // La chaine du §5, posee une fois. Chaque intention y entre par sa
        // premiere etape : l'intention est clarifiee avant tout le reste.
        $precedent = null;

        foreach (self::STEPS as $step) {
            $id = 'step:'.$step;
            $nodes[] = $this->node($id, 'step', __('flowchart.step_'.$step), [
                'hint' => __('flowchart.step_'.$step.'_hint'),
            ]);

            if ($precedent === null) {
                foreach (self::INTENTS as $intent) {
                    $edges[] = $this->edge('intent:'.$intent, $id);
                }
            } else {
                $edges[] = $this->edge($precedent, $id);
            }

            $precedent = $id;
        }

        foreach (self::OUTCOMES as $outcome) {
            $id = 'outcome:'.$outcome;
            $nodes[] = $this->node($id, 'outcome', __('flowchart.outcome_'.$outcome));
            $edges[] = $this->edge((string) $precedent, $id);
        }

        // §11 — AUCUN moteur de recommandation. Les Boucles ne pendent a
        // aucune intention : rien dans les donnees ne relierait honnetement
        // « j'ai besoin d'aide » a une Boucle plutot qu'a une autre.
        $nodes[] = $this->node(self::NODE_LOOPS, 'explore', __('flowchart.explore_loops'));
        $edges[] = $this->edge(self::NODE_ROOT, self::NODE_LOOPS);

        $boucles = $this->loopNodes($organization, $user);

        foreach ($boucles as $boucle) {
            $nodes[] = $boucle;
            $edges[] = $this->edge(self::NODE_LOOPS, $boucle['data']['id']);
        }

        return [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'theme_key' => bp_organization_theme_key($organization),
                'locale' => app()->getLocale(),
                'loop_mode' => $organization->loop_mode,
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'has_loops' => $boucles !== [],
            'is_guest' => $user === null,
        ];
    }

    /**
     * Les Boucles reellement praticables, pour CETTE personne, dans CE tenant.
     *
     * @return list<array{data: array<string, mixed>}>
     */
    private function loopNodes(Organization $organization, ?User $user): array
    {
        // Un invite, ou quelqu'un d'une autre Organization : aucune Boucle.
        // `VisibleLoops` bornerait deja la requete au tenant visite, mais un
        // non-membre n'a de toute facon aucune surface qui les lui nomme — la
        // page publique n'en ouvre pas une.
        if ($user === null || $user->organization_id !== $organization->id) {
            return [];
        }

        // `groupedFor()` plutot que `query()` : il porte DEJA la restriction
        // `loop_mode = mono` (pas de catalogue, donc `other` vide). La
        // contourner enrichirait la demo en revelant ce qu'aucune surface ne
        // montre.
        $grouped = $this->visibleLoops->groupedFor($organization, $user);

        $aRetrancher = static fn (Loop $loop): bool => $loop->access_mode === Loop::ACCESS_INVITATION
            && ! (bool) $loop->getAttribute('is_member');

        return $grouped['member']
            ->merge($grouped['other'])
            ->reject($aRetrancher)
            ->map(fn (Loop $loop): array => $this->loopNode($loop, $user))
            ->values()
            ->all();
    }

    /** @return array{data: array<string, mixed>} */
    private function loopNode(Loop $loop, User $user): array
    {
        $estMembre = (bool) $loop->getAttribute('is_member');

        // `member` court-circuite : pour un membre, `accessStateFor()` rend
        // `invitation` (voir le docblock de classe), ce qui serait faux a
        // afficher. Hors appartenance, l'etat vient des Policies.
        $access = $estMembre ? 'member' : $this->visibleLoops->accessStateFor($loop, $user);

        return $this->node('loop:'.$loop->id, 'loop', $this->borne($loop->name, self::MAX_LABEL_CHARS), [
            'loop_id' => (string) $loop->id,
            'access' => $access,
            'access_label' => __('flowchart.access_'.$access),
            'cta_label' => __($estMembre ? 'flowchart.cta_open' : 'flowchart.cta_view'),
            'url' => $this->loopUrl($loop),
            'tagline' => $this->borne($loop->tagline ?: $loop->description, self::MAX_TAGLINE_CHARS),
            'members' => (int) ($loop->getAttribute('active_members_count') ?? 0),
        ]);
    }

    /**
     * Le CTA mene a la fiche de la Boucle, JAMAIS a une ecriture.
     *
     * Rejoindre et demander a rejoindre sont des POST (`organization.loops.join`,
     * `organization.loops.join-requests.store`). `organization.loops.show` porte
     * deja ces gestes, avec les Policies reevaluees au moment du clic : le
     * flowchart n'en rejoue aucun.
     */
    private function loopUrl(Loop $loop): string
    {
        $slug = $loop->organization?->slug;

        if ($slug && Route::has('organization.loops.show')) {
            return route('organization.loops.show', ['organization' => $slug, 'loop' => $loop->id]);
        }

        return $loop->workspaceUrl();
    }

    private function borne(?string $valeur, int $max): string
    {
        return Str::limit(trim((string) $valeur), $max, '…');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{data: array<string, mixed>}
     */
    private function node(string $id, string $kind, string $label, array $extra = []): array
    {
        return ['data' => ['id' => $id, 'kind' => $kind, 'label' => $label] + $extra];
    }

    /** @return array{data: array<string, mixed>} */
    private function edge(string $source, string $target): array
    {
        return ['data' => ['id' => 'e:'.$source.'>'.$target, 'source' => $source, 'target' => $target]];
    }
}
