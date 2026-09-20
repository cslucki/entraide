import cytoscape from 'cytoscape';

/**
 * TASK-1608 — le moteur de la carte interactive.
 *
 * ## Ce fichier ne decide AUCUNE permission
 *
 * Il affiche ce que Laravel lui a donne, et rien d'autre. Une Boucle absente du
 * payload n'existe pas de son point de vue : il n'y a aucun filtrage de
 * securite ici, parce qu'il n'y a rien a filtrer.
 *
 * ## Il ne persiste rien (§21)
 *
 * V0 = EXPLORATEUR, pas editeur. Les noeuds ne sont pas deplacables, aucune
 * position n'est sauvegardee, aucune arete n'est reconnectable.
 *
 * ## Lisibilite : la correction demandee par MASTER apres recette
 *
 * Premiere version : des noeuds de 26 a 54 px, des labels de 11 a 13 px, et un
 * `fit()` au chargement. Le `fit()` etait la vraie cause — il ramene TOUT le
 * graphe dans le cadre, donc plus il y a de noeuds, plus le texte devient
 * petit. Grossir les noeuds sans toucher au `fit()` n'aurait rien change :
 * l'echelle aurait simplement compense.
 *
 * Corrige sur les deux fronts :
 * - des tailles de lecture (ROOT 96 px / 18 px, INTENTION 74 px / 15 px, les
 *   Boucles en vraies cartes) ;
 * - au chargement, AUCUN `fit()` : une echelle de lecture posee explicitement
 *   et un simple recentrage. `fit()` ne sert plus qu'a « Vue d'ensemble », et
 *   reste borne par `ZOOM_MIN_LISIBLE` partout ailleurs.
 *
 * ## Le fond appartient a l'application
 *
 * Cytoscape ne peint aucune couleur de fond : le canvas est transparent et
 * laisse voir `--bp-page`, pose par le layout. Le graphe fait partie de la
 * page, il n'y est pas embarque.
 */

/** En dessous, les labels cessent d'etre lisibles sans zoomer a la main. */
const ZOOM_MIN_LISIBLE = 0.62;

/** L'echelle de lecture au chargement. */
const ZOOM_INITIAL = 1;

const racine = document.querySelector('[data-flowchart-canvas]');
const source = document.querySelector('[data-flowchart-graph]');

if (racine && source) {
    demarrer(racine, JSON.parse(source.textContent));
}

/** Un jeton de theme, avec un repli explicite : un `var()` vide serait invisible. */
function jeton(nom, repli) {
    const valeur = getComputedStyle(document.documentElement).getPropertyValue(nom).trim();

    return valeur || repli;
}

function demarrer(racine, graph) {
    const couleurs = {
        page: jeton('--bp-page', '#0f172a'),
        surface: jeton('--bp-surface', '#1e293b'),
        primary: jeton('--bp-primary', '#1B1FCC'),
        primaryDeep: jeton('--bp-primary-deep', '#141799'),
        accent: jeton('--bp-accent', '#7c3aed'),
        validation: jeton('--bp-validation', '#16a34a'),
        info: jeton('--bp-info', '#0284c7'),
        text: jeton('--bp-text', '#e2e8f0'),
        muted: jeton('--bp-muted', '#94a3b8'),
        border: jeton('--bp-border', '#334155'),
    };

    // La couleur porte le SENS, pas la decoration : une porte d'entree, une
    // etape du mecanisme, un resultat, une Boucle reelle.
    const parGenre = {
        root: couleurs.primaryDeep,
        intent: couleurs.primary,
        step: couleurs.info,
        outcome: couleurs.validation,
        explore: couleurs.accent,
        loop: couleurs.surface,
    };

    const etroit = () => racine.clientWidth < 720;

    const cy = cytoscape({
        container: racine,
        elements: { nodes: graph.nodes, edges: graph.edges },
        // §21 : la carte s'explore, elle ne s'edite pas.
        autoungrabify: true,
        boxSelectionEnabled: false,
        minZoom: 0.25,
        maxZoom: 2.5,
        // Le rendu initial est pose par `disposerPremierNiveau()`, jamais par
        // un ajustement automatique qui ecraserait l'echelle de lecture.
        style: [
            {
                selector: 'node',
                style: {
                    'background-color': (n) => parGenre[n.data('kind')] || couleurs.muted,
                    label: 'data(label)',
                    color: couleurs.text,
                    'font-size': 13,
                    'font-weight': 600,
                    'text-valign': 'bottom',
                    'text-margin-y': 8,
                    'text-wrap': 'wrap',
                    'text-max-width': 180,
                    // Le contour de texte detache le label du fond de page,
                    // quel que soit le theme et le mode clair/sombre.
                    'text-outline-width': 3,
                    'text-outline-color': couleurs.page,
                    width: 52,
                    height: 52,
                    'border-width': 0,
                    'transition-property': 'background-color, opacity, border-width',
                    'transition-duration': 220,
                },
            },
            {
                selector: 'node[kind = "root"]',
                style: { width: 96, height: 96, 'font-size': 18, 'text-max-width': 240, 'text-margin-y': 12 },
            },
            {
                selector: 'node[kind = "intent"]',
                style: { width: 74, height: 74, 'font-size': 15, 'text-max-width': 200, 'text-margin-y': 10 },
            },
            {
                selector: 'node[kind = "explore"]',
                style: { width: 74, height: 74, 'font-size': 15, 'text-max-width': 200, 'text-margin-y': 10, shape: 'round-diamond' },
            },
            { selector: 'node[kind = "step"]', style: { width: 54, height: 54, 'font-size': 13 } },
            { selector: 'node[kind = "outcome"]', style: { width: 60, height: 60, 'font-size': 13, shape: 'round-hexagon' } },
            // Une Boucle est une vraie petite carte, pas un point avec du
            // texte accroche : le nom vit DANS le noeud.
            {
                selector: 'node[kind = "loop"]',
                style: {
                    shape: 'round-rectangle',
                    width: 'label',
                    height: 'label',
                    padding: 14,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'text-margin-y': 0,
                    'text-max-width': 190,
                    'font-size': 13,
                    'text-outline-width': 0,
                    color: couleurs.text,
                    'border-width': 2,
                    'border-color': couleurs.accent,
                },
            },
            {
                selector: 'edge',
                style: {
                    width: 2,
                    'line-color': couleurs.border,
                    'target-arrow-color': couleurs.border,
                    'target-arrow-shape': 'triangle',
                    'arrow-scale': 1,
                    'curve-style': 'bezier',
                    opacity: 0.6,
                    'transition-property': 'line-color, opacity, width',
                    'transition-duration': 220,
                },
            },
            // Le chemin actif : mis en evidence, le reste attenue (§9).
            { selector: 'node.bp-actif', style: { 'border-width': 4, 'border-color': couleurs.primary } },
            { selector: 'edge.bp-actif', style: { 'line-color': couleurs.primary, 'target-arrow-color': couleurs.primary, width: 3, opacity: 1 } },
            { selector: '.bp-attenue', style: { opacity: 0.16 } },
            { selector: '.bp-replie', style: { display: 'none' } },
        ],
    });

    // =====================================================================
    // Revelation progressive — §3 et §G : simple au depart, detail a la demande
    // =====================================================================

    const root = cy.getElementById('root');

    /** Ce qui est visible au chargement : le point d'entree et ses portes. */
    const premierNiveau = cy.nodes().filter((n) => {
        const genre = n.data('kind');

        return genre === 'root' || genre === 'intent' || genre === 'explore';
    });

    let deplie = null;

    /** Les aretes dont les DEUX extremites sont dans l'ensemble donne. */
    const aretesEntre = (noeuds) => noeuds.edgesWith(noeuds);

    /**
     * Tout ce qui descend d'un noeud, sans jamais remonter.
     *
     * `successors()` suit les aretes sortantes : la chaine du mecanisme puis
     * les resultats pour une intention, les Boucles pour le noeud
     * d'exploration.
     */
    const branche = (noeud) => noeud.union(noeud.successors());

    function replierTout() {
        cy.elements().removeClass('bp-actif bp-attenue');
        cy.elements().addClass('bp-replie');
        premierNiveau.removeClass('bp-replie');
        aretesEntre(premierNiveau).removeClass('bp-replie');
        deplie = null;
        fermerPanneau();
    }

    /**
     * L'etat initial : la question au centre, ses portes autour (§G).
     *
     * `concentric` place la racine au milieu et repartit le reste sur un
     * anneau — la forme en etoile que MASTER a dessinee. Aucun `fit()` : on
     * pose une echelle de lecture, puis on recentre.
     */
    function disposerPremierNiveau(animer = true) {
        const visibles = premierNiveau;

        visibles
            .layout({
                name: 'concentric',
                concentric: (n) => (n.data('kind') === 'root' ? 10 : 1),
                levelWidth: () => 1,
                minNodeSpacing: etroit() ? 70 : 120,
                padding: 40,
                animate: animer,
                animationDuration: 380,
                fit: false,
                stop: () => {
                    cy.zoom({ level: echelleDeLecture(), renderedPosition: centreDuCadre() });
                    cy.center(visibles);
                },
            })
            .run();
    }

    const centreDuCadre = () => ({ x: racine.clientWidth / 2, y: racine.clientHeight / 2 });

    /**
     * Une echelle qui reste lisible, meme sur un cadre etroit.
     *
     * Sur mobile on descend un peu pour que l'etoile tienne, mais jamais en
     * dessous du plancher de lisibilite.
     */
    function echelleDeLecture() {
        return etroit() ? Math.max(ZOOM_MIN_LISIBLE, 0.78) : ZOOM_INITIAL;
    }

    /** Un `fit` qui refuse de rapetisser le texte en dessous du lisible. */
    function ajusterSansEcraser(elements, padding = 60) {
        cy.animate({ fit: { eles: elements, padding } }, { duration: 380 });

        // `animate` pose le zoom a la fin ; on borne apres coup plutot que de
        // calculer un ajustement a la main, qui redirait ce que Cytoscape sait
        // deja faire.
        setTimeout(() => {
            if (cy.zoom() < ZOOM_MIN_LISIBLE) {
                cy.zoom({ level: ZOOM_MIN_LISIBLE, renderedPosition: centreDuCadre() });
                cy.center(elements);
            }
        }, 400);
    }

    function deplier(noeud) {
        const actif = branche(noeud);
        const ensemble = actif.union(premierNiveau);

        cy.elements().addClass('bp-replie');
        ensemble.removeClass('bp-replie');
        aretesEntre(ensemble).removeClass('bp-replie');

        cy.elements().removeClass('bp-actif').addClass('bp-attenue');
        actif.union(aretesEntre(actif)).removeClass('bp-attenue').addClass('bp-actif');

        deplie = noeud;

        ensemble
            .layout({
                name: 'breadthfirst',
                directed: true,
                roots: root,
                padding: 40,
                spacingFactor: etroit() ? 1 : 1.35,
                // §14 : vertical sur mobile, horizontal quand la place existe.
                transform: etroit() ? undefined : (n, pos) => ({ x: pos.y, y: pos.x }),
                animate: true,
                animationDuration: 420,
                fit: false,
                stop: () => ajusterSansEcraser(actif, 70),
            })
            .run();
    }

    // =====================================================================
    // Panneau de detail (§10) — le graphe reste simple, la fiche vit a cote
    // =====================================================================

    const panneau = document.querySelector('[data-flowchart-panel]');
    const champ = (nom) => document.querySelector(`[data-flowchart-panel-${nom}]`);

    function fermerPanneau() {
        if (panneau) {
            panneau.hidden = true;
        }
    }

    function ouvrirPanneau(noeud) {
        if (!panneau) {
            return;
        }

        const d = noeud.data();

        champ('kind').textContent = d.access_label || '';
        champ('title').textContent = d.label || '';
        champ('body').textContent = d.hint || d.tagline || '';
        champ('meta').textContent = d.kind === 'loop' && d.members ? `${d.members}` : '';

        const cta = champ('cta');
        if (d.kind === 'loop' && d.url) {
            cta.href = d.url;
            cta.textContent = d.cta_label || '';
            cta.hidden = false;
        } else {
            cta.hidden = true;
            cta.removeAttribute('href');
        }

        panneau.hidden = false;
    }

    // =====================================================================
    // Gestes
    // =====================================================================

    cy.on('tap', 'node', (evenement) => {
        const noeud = evenement.target;
        const genre = noeud.data('kind');

        if (genre === 'intent' || genre === 'explore') {
            // Re-cliquer la branche ouverte la referme : le geste est reversible.
            if (deplie && deplie.id() === noeud.id()) {
                replierTout();
                disposerPremierNiveau();

                return;
            }

            deplier(noeud);
        }

        ouvrirPanneau(noeud);
    });

    cy.on('tap', (evenement) => {
        if (evenement.target === cy) {
            fermerPanneau();
        }
    });

    champ('close')?.addEventListener('click', fermerPanneau);

    // « Vue d'ensemble » : le SEUL endroit ou l'on accepte de tout montrer,
    // donc le seul ou un `fit` complet a un sens.
    document.querySelector('[data-flowchart-overview]')?.addEventListener('click', () => {
        cy.elements().removeClass('bp-replie bp-attenue bp-actif');
        deplie = null;

        cy.elements()
            .layout({
                name: 'breadthfirst',
                directed: true,
                roots: root,
                padding: 40,
                spacingFactor: etroit() ? 1 : 1.3,
                transform: etroit() ? undefined : (n, pos) => ({ x: pos.y, y: pos.x }),
                animate: true,
                animationDuration: 420,
                fit: true,
            })
            .run();

        fermerPanneau();
    });

    document.querySelector('[data-flowchart-back]')?.addEventListener('click', () => {
        replierTout();
        disposerPremierNiveau();
    });

    document.querySelector('[data-flowchart-reset]')?.addEventListener('click', () => {
        replierTout();
        disposerPremierNiveau(false);
    });

    // La carte se recompose quand la place change (rotation, redimensionnement).
    let minuterie;
    window.addEventListener('resize', () => {
        clearTimeout(minuterie);
        minuterie = setTimeout(() => {
            cy.resize();

            if (deplie) {
                deplier(deplie);
            } else {
                disposerPremierNiveau(false);
            }
        }, 180);
    });

    // Poignee de RECETTE, posee sur l'element plutot que sur `window` : elle
    // ne pollue aucun espace de noms global. Elle sert a mesurer l'etat reel
    // du graphe depuis Playwright (noeuds visibles, echelle, positions
    // rendues) — une capture d'ecran ne prouve pas qu'une branche s'est
    // depliee. Aucune logique produit n'en depend.
    racine.__bpFlowchart = cy;

    // Entree en scene : le premier niveau, et lui seul.
    replierTout();
    disposerPremierNiveau(false);
}
