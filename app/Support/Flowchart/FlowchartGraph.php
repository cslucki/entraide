<?php

namespace App\Support\Flowchart;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopTypeRegistry;
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
 * ## ENTREES DIFFERENTES -> MOTEUR COMMUN (arbitrage MASTER, §2 correctifs)
 *
 * Les quatre intentions ne revelaient PAS des branches distinctes : elles
 * entraient toutes directement dans le meme tronc, et cliquer l'une ou l'autre
 * montrait exactement les memes noeuds. Le clic ne tenait pas sa promesse.
 *
 * Chaque intention porte desormais sa propre PREMIERE ETAPE (`kind = entry`),
 * qui dit ce que la personne fait concretement. La convergence vers le tronc
 * commun n'arrive qu'ensuite — parce que le moteur BouclePro, lui, EST commun,
 * et le dupliquer quatre fois mentirait sur le produit.
 *
 * ## Deux lectures du meme graphe
 *
 * Les aretes portent une `view` :
 *
 * - `detail` : l'exploration focalisee, pas a pas, entrees comprises ;
 * - `overview` : la vue synthetique, qui court-circuite les entrees et agrege
 *   « IA + ressources + documents » et « synthese + decision » en deux noeuds
 *   (`kind = aggregate`) pour que BouclePro se comprenne en cinq secondes ;
 * - `both` : les tronçons communs aux deux lectures.
 *
 * Les noeuds agreges existent donc dans le payload mais ne sont affiches qu'en
 * vue d'ensemble. Aucune arete n'est orpheline dans l'une ou l'autre lecture.
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
     * Le moteur BouclePro, dans l'ordre. Une chaine PARTAGEE par les quatre
     * intentions : c'est ce que le produit fait reellement, et le dire quatre
     * fois n'en ferait pas quatre moteurs.
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

    /**
     * Quel champ de la homepage porte le libelle de quelle intention.
     *
     * ## Ce mapping est verifie DANS LE CODE, pas deduit des noms
     *
     * Les noms de champs sont trompeurs, et s'y fier aurait inverse deux
     * intentions sur quatre. Mesure dans
     * `resources/views/admin/organizations/homepage.blade.php` :
     *
     * | ecran admin | champ reel |
     * |---|---|
     * | Card 1 — « J'explore une piste » | `card_create_label` |
     * | Card 2 — « Je cree du lien »     | `card_meet_label` |
     * | Card 3 — « J'ai besoin d'aide »  | `card_help_label` |
     * | Card 4 — « Je peux aider »       | `card_offer_label` |
     *
     * `card_create_label` porte donc « J'explore », et `card_meet_label`
     * porte « Je cree du lien » : l'inverse de ce que leurs noms suggerent.
     */
    private const INTENT_HOMEPAGE_FIELD = [
        'need_help' => 'card_help_label',
        'offer_help' => 'card_offer_label',
        'explore_idea' => 'card_create_label',
        'connect' => 'card_meet_label',
    ];

    /** Le noeud qui porte les Boucles reelles — §11 : aucune intention n'y est reliee. */
    public const NODE_LOOPS = 'loops';

    /**
     * Les DEBOUCHES concrets, un par intention d'entraide.
     *
     * Une intention ne doit pas seulement mener a un geste d'expression : elle
     * doit aussi donner acces au reel de la plateforme. « J'ai besoin d'aide »
     * ouvre donc sur les Propositions, « Je peux aider » sur les Demandes.
     *
     * Les NOEUDS sont publics — ils disent ou mene BouclePro. Les DONNEES,
     * elles, sont reservees aux membres : voir {@see FlowchartExchanges}.
     */
    public const OUTLETS = [
        'need_help' => 'outlet:proposals',
        'offer_help' => 'outlet:requests',
    ];

    /** « IA + ressources + documents », replie pour la vue d'ensemble. */
    public const NODE_ENGINE = 'agg:engine';

    /** « Synthese + decision », idem. */
    public const NODE_DECIDE = 'agg:decide';

    /** Les etapes que `agg:engine` remplace en vue d'ensemble. */
    private const ENGINE_COVERS = ['ai', 'resources', 'dossiers'];

    /** Celles que `agg:decide` remplace. */
    private const DECIDE_COVERS = ['synthesis', 'decision'];

    /** Un libelle ecrit par un membre est cite, jamais laisse libre (meme borne que T1359/T1364). */
    private const MAX_LABEL_CHARS = 90;

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

        // --- Les quatre portes, et leur entree propre -------------------
        foreach (self::INTENTS as $intent) {
            $porte = 'intent:'.$intent;
            $entree = 'entry:'.$intent;

            $nodes[] = $this->node($porte, 'intent', $this->intentLabel($organization, $intent), [
                'hint' => __('flowchart.intent_'.$intent.'_hint'),
            ]);
            $nodes[] = $this->node($entree, 'entry', __('flowchart.entry_'.$intent), [
                'hint' => __('flowchart.entry_'.$intent.'_hint'),
            ]);

            $edges[] = $this->edge(self::NODE_ROOT, $porte, 'both');
            // Exploration : l'intention passe par SON entree.
            $edges[] = $this->edge($porte, $entree, 'detail');
            $edges[] = $this->edge($entree, 'step:clarify', 'detail');
            // Synthese : les quatre portes tombent directement dans le tronc.
            $edges[] = $this->edge($porte, 'step:clarify', 'overview');

            // Le debouche concret, quand cette intention en a un.
            if (isset(self::OUTLETS[$intent])) {
                $debouche = self::OUTLETS[$intent];

                $nodes[] = $this->node($debouche, 'outlet', __('flowchart.outlet_'.$intent), [
                    'hint' => __('flowchart.outlet_'.$intent.'_hint'),
                    'outlet' => $intent === 'need_help' ? 'proposals' : 'requests',
                ]);

                $edges[] = $this->edge($porte, $debouche, 'detail');
            }
        }

        // --- Le tronc commun -------------------------------------------
        foreach (self::STEPS as $step) {
            $nodes[] = $this->node('step:'.$step, 'step', __('flowchart.step_'.$step), [
                'hint' => __('flowchart.step_'.$step.'_hint'),
            ]);
        }

        $precedent = null;

        foreach (self::STEPS as $step) {
            if ($precedent !== null) {
                // `both` tant que les deux lectures suivent le meme tronçon ;
                // `detail` des que la vue d'ensemble replie le segment.
                $repliee = $this->estRepliee($precedent) || $this->estRepliee($step);
                $edges[] = $this->edge('step:'.$precedent, 'step:'.$step, $repliee ? 'detail' : 'both');
            }

            $precedent = $step;
        }

        foreach (self::OUTCOMES as $outcome) {
            $nodes[] = $this->node('outcome:'.$outcome, 'outcome', __('flowchart.outcome_'.$outcome));
            $edges[] = $this->edge('step:decision', 'outcome:'.$outcome, 'detail');
            $edges[] = $this->edge(self::NODE_DECIDE, 'outcome:'.$outcome, 'overview');
        }

        // --- Les deux replis de la vue d'ensemble ----------------------
        $nodes[] = $this->node(self::NODE_ENGINE, 'aggregate', __('flowchart.aggregate_engine'), [
            'hint' => __('flowchart.aggregate_engine_hint'),
        ]);
        $nodes[] = $this->node(self::NODE_DECIDE, 'aggregate', __('flowchart.aggregate_decide'), [
            'hint' => __('flowchart.aggregate_decide_hint'),
        ]);

        $edges[] = $this->edge('step:exchange', self::NODE_ENGINE, 'overview');
        $edges[] = $this->edge(self::NODE_ENGINE, self::NODE_DECIDE, 'overview');

        // --- Les Boucles reelles ---------------------------------------
        // §11 : AUCUN moteur de recommandation. Elles ne pendent a aucune
        // intention — rien dans les donnees ne relierait honnetement « j'ai
        // besoin d'aide » a une Boucle plutot qu'a une autre.
        $nodes[] = $this->node(self::NODE_LOOPS, 'explore', __('flowchart.explore_loops_node'));
        $edges[] = $this->edge(self::NODE_ROOT, self::NODE_LOOPS, 'both');

        $boucles = $this->loopNodes($organization, $user);

        foreach ($boucles as $boucle) {
            $nodes[] = $boucle;
            // `detail` seulement : la vue d'ensemble ne deploie pas les
            // Boucles (§5), elle en annonce le nombre.
            $edges[] = $this->edge(self::NODE_LOOPS, $boucle['data']['id'], 'detail');
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
     * Le libelle d'une porte, tel que CETTE Organization l'a choisi.
     *
     * ## La meme source que la homepage, pas une seconde configuration
     *
     * `hero-v2.blade.php:5` resout ainsi :
     * `filled($settings[$cle]) ? e($settings[$cle]) : org_trans($defaut)`.
     * On reprend la premiere moitie telle quelle.
     *
     * Le repli, lui, vise `flowchart.intent_*` et non `hero.card_*` : c'est
     * l'arbitrage MASTER (§4), et cela evite d'importer ici les `<br>` que les
     * libelles d'accueil portent pour leur mise en page. Passer par
     * `org_trans()` garde la couche d'overrides de traduction par Organization
     * ET par locale, qui est le seul canal multilingue des deux.
     *
     * ## Limite EXISTANTE, pas creee ici
     *
     * Une valeur saisie dans `homepage_settings` est une chaine UNIQUE : elle
     * s'affiche a l'identique en francais et en anglais. Le flowchart
     * reproduit ce comportement plutot que d'inventer un modele de donnees
     * multilingue pour les contenus admin (§5).
     *
     * ## Le texte ne pilote jamais la structure
     *
     * L'identifiant du noeud reste `intent:<cle>` quoi qu'il arrive. La
     * personnalisation est editoriale : elle change ce qui se lit, jamais ce
     * qui se passe au clic.
     */
    private function intentLabel(Organization $organization, string $intent): string
    {
        $champ = self::INTENT_HOMEPAGE_FIELD[$intent] ?? null;
        $personnalise = $champ ? ($organization->homepage_settings[$champ] ?? null) : null;

        if (filled($personnalise)) {
            // Saisi par un admin et rendu sur un canvas : on retire le
            // balisage plutot que de l'echapper. `e()` conviendrait a du HTML,
            // pas a un libelle de noeud, ou « &amp; » s'afficherait tel quel.
            $propre = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], ' ', (string) $personnalise))));

            if ($propre !== '') {
                return $this->borne($propre, self::MAX_LABEL_CHARS);
            }
        }

        return org_trans('flowchart.intent_'.$intent, $organization);
    }

    /** Cette etape du tronc est-elle repliee dans un agregat de la vue d'ensemble ? */
    private function estRepliee(string $step): bool
    {
        return in_array($step, self::ENGINE_COVERS, true) || in_array($step, self::DECIDE_COVERS, true);
    }

    /**
     * Les Boucles a poser sur la carte, pour cette personne, dans ce tenant.
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

        $nom = $this->borne($loop->name, self::MAX_LABEL_CHARS);
        $statut = __('flowchart.access_'.$access);

        // Le TYPE vient du `LoopTypeRegistry`, seule autorite du catalogue —
        // types de plateforme ET types crees par l'Organization. Le recopier
        // dans le JavaScript aurait fige une liste que le produit fait vivre.
        $registre = app(LoopTypeRegistry::class);
        $typeLibelle = $registre->label($loop->type, $loop->organization);

        // §6 des correctifs : le STATUT vit dans le noeud, pas seulement dans
        // le panneau. Deux lignes, parce qu'une Boucle sans son mode d'entree
        // n'est pas une possibilite lisible — c'est juste un nom.
        return $this->node('loop:'.$loop->id, 'loop', $nom."\n".$typeLibelle.' · '.$statut, [
            'loop_id' => (string) $loop->id,
            'name' => $nom,
            'access' => $access,
            'access_label' => $statut,
            'type_label' => $typeLibelle,
            'type_description' => $registre->description($loop->type, $loop->organization),
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
    private function edge(string $source, string $target, string $view): array
    {
        return ['data' => [
            'id' => 'e:'.$source.'>'.$target,
            'source' => $source,
            'target' => $target,
            'view' => $view,
        ]];
    }
}
