<?php

namespace App\Support\Flowchart;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * TASK-1608 — le modele de graphe servi a Cytoscape.
 *
 * ## Cette classe ne decide AUCUNE permission
 *
 * Elle assemble des noeuds et des aretes. Quelles Boucles y entrent est
 * decide par {@see FlowchartLoops}, la projection dediee au payload ; ce que
 * l'on peut y FAIRE reste decide par `LoopPolicy`, au moment du clic, sur la
 * fiche de la Boucle. Rien n'est recopie ici.
 *
 * `App\Support\Loops\VisibleLoops` n'est **pas** consommee, et n'est pas
 * modifiee : elle reste l'autorite du catalogue interne, qui repond a une
 * autre question. Voir le docblock de {@see FlowchartLoops} pour la raison.
 *
 * ## Ce qui entre sur la carte (arbitrage MASTER corrige du 2026-09-20)
 *
 * Socle public, servi a tout le monde : `status = active`,
 * `visibility = public`, `access_mode ∈ {open, request}`.
 * Ajout pour qui appartient a l'Organization visitee : ses Boucles actives
 * dont il est membre ACTIF, meme privees, meme sur invitation.
 *
 * Un invite recoit donc des Boucles — les publiques et praticables — et
 * aucune autre.
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

    public function __construct(private readonly FlowchartLoops $loops) {}

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
        return $this->loops->for($organization, $user)
            ->map(fn (Loop $loop): array => $this->loopNode($loop, $user))
            ->values()
            ->all();
    }

    /** @return array{data: array<string, mixed>} */
    private function loopNode(Loop $loop, ?User $user): array
    {
        $estMembre = $this->loops->isMember($loop, $user);
        $access = $this->loops->accessLabelFor($loop, $user);

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
